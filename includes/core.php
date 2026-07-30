<?php
/**
 * SpartanCMS - Core: JSON storage, referential integrity, helpers.
 */

declare(strict_types=1);

if (!defined('CMS_ROOT')) {
    require_once dirname(__DIR__) . '/config.php';
}

/* =========================================================================
 * COMPATIBILITY
 *
 * The CMS targets PHP 8.0+, which several shared hosts still run by default.
 * ====================================================================== */

if (!function_exists('array_is_list')) {
    /** Polyfill of the PHP 8.1 function: true for a 0..n-1 integer-keyed array. */
    function array_is_list(array $array): bool
    {
        $expected = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
    }
}

/* =========================================================================
 * JSON STORE
 * ====================================================================== */

/**
 * Flat-file JSON store.
 *
 * Every "table" is a file in /data holding:
 *   { "auto_increment": int, "rows": [ {...}, ... ] }
 *
 * Writes are atomic (temp file + rename) and guarded by an exclusive lock,
 * so concurrent requests can never leave a truncated file behind.
 */
final class Store
{
    /** @var array<string,array{auto_increment:int,rows:array<int,array<string,mixed>>}> */
    private static array $cache = [];

    private static function path(string $table): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid table name: ' . $table);
        }
        return CMS_DATA_DIR . '/' . $table . '.json';
    }

    /** @return array{auto_increment:int,rows:array<int,array<string,mixed>>} */
    public static function load(string $table): array
    {
        if (isset(self::$cache[$table])) {
            return self::$cache[$table];
        }

        $file = self::path($table);
        $data = ['auto_increment' => 1, 'rows' => []];

        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows'])) {
                    $data = [
                        'auto_increment' => (int)($decoded['auto_increment'] ?? 1),
                        'rows'           => array_values($decoded['rows']),
                    ];
                }
            }
        }

        return self::$cache[$table] = $data;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $table): array
    {
        return self::load($table)['rows'];
    }

    /**
     * Rows filtered by an associative set of equality conditions.
     *
     * @param  array<string,mixed> $where
     * @return array<int,array<string,mixed>>
     */
    public static function where(string $table, array $where): array
    {
        $out = [];
        foreach (self::all($table) as $row) {
            foreach ($where as $k => $v) {
                if (($row[$k] ?? null) != $v) {
                    continue 2;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $table, int $id): ?array
    {
        foreach (self::all($table) as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param  array<string,mixed> $where
     * @return array<string,mixed>|null
     */
    public static function first(string $table, array $where): ?array
    {
        $rows = self::where($table, $where);
        return $rows[0] ?? null;
    }

    /**
     * Insert a row, assigning the next auto increment id.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed> the stored row (with id)
     */
    public static function insert(string $table, array $row): array
    {
        $data = self::load($table);
        $row['id'] = $data['auto_increment'];
        $data['auto_increment']++;
        $row['created_at'] = $row['created_at'] ?? cms_now();
        $row['updated_at'] = cms_now();
        $data['rows'][] = $row;
        self::persist($table, $data);
        return $row;
    }

    /**
     * Update a row by id (shallow merge).
     *
     * @param  array<string,mixed> $values
     */
    public static function update(string $table, int $id, array $values): bool
    {
        $data  = self::load($table);
        $found = false;
        foreach ($data['rows'] as $i => $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                unset($values['id']);
                $values['updated_at'] = cms_now();
                $data['rows'][$i] = array_merge($row, $values);
                $found = true;
                break;
            }
        }
        if ($found) {
            self::persist($table, $data);
        }
        return $found;
    }

    public static function delete(string $table, int $id): bool
    {
        $data  = self::load($table);
        $count = count($data['rows']);
        $data['rows'] = array_values(array_filter(
            $data['rows'],
            static fn(array $r): bool => (int)($r['id'] ?? 0) !== $id
        ));
        if (count($data['rows']) === $count) {
            return false;
        }
        self::persist($table, $data);
        return true;
    }

    /** Delete every row matching the given conditions. Returns rows removed. */
    public static function deleteWhere(string $table, array $where): int
    {
        $data    = self::load($table);
        $removed = 0;
        $kept    = [];
        foreach ($data['rows'] as $row) {
            $match = true;
            foreach ($where as $k => $v) {
                if (($row[$k] ?? null) != $v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $removed++;
            } else {
                $kept[] = $row;
            }
        }
        if ($removed > 0) {
            $data['rows'] = $kept;
            self::persist($table, $data);
        }
        return $removed;
    }

    /** Replace the whole row set, keeping the auto increment counter. */
    public static function replaceAll(string $table, array $rows): void
    {
        $data = self::load($table);
        $data['rows'] = array_values($rows);
        self::persist($table, $data);
    }

    /** Sort rows in place by one key (used for menu ordering). */
    public static function sortBy(string $table, string $key, bool $numeric = true): void
    {
        $data = self::load($table);
        usort($data['rows'], static function (array $a, array $b) use ($key, $numeric): int {
            $x = $a[$key] ?? 0;
            $y = $b[$key] ?? 0;
            return $numeric ? ((int)$x <=> (int)$y) : strcmp((string)$x, (string)$y);
        });
        self::persist($table, $data);
    }

    /** @param array{auto_increment:int,rows:array} $data */
    private static function persist(string $table, array $data): void
    {
        self::$cache[$table] = $data;

        if (!is_dir(CMS_DATA_DIR) && !mkdir(CMS_DATA_DIR, 0775, true) && !is_dir(CMS_DATA_DIR)) {
            throw new RuntimeException('Cannot create data directory.');
        }

        $file = self::path($table);
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new RuntimeException('Cannot encode table ' . $table . ': ' . json_last_error_msg());
        }

        $lock = fopen($file . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open lock file for ' . $table);
        }
        flock($lock, LOCK_EX);

        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException('Cannot write table ' . $table);
        }
        @chmod($tmp, 0664);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException('Cannot commit table ' . $table);
        }

        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** Drop the in-process cache (after external writes). */
    public static function flush(?string $table = null): void
    {
        if ($table === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$table]);
        }
    }
}

/* =========================================================================
 * SETTINGS (single-row table)
 * ====================================================================== */

final class Settings
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$data === null) {
            $rows = Store::all('settings');
            self::$data = $rows[0] ?? [];
        }
        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        $val = $all[$key] ?? null;
        return ($val === null || $val === '') ? $default : $val;
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void
    {
        $rows = Store::all('settings');
        if ($rows === []) {
            Store::insert('settings', $values);
        } else {
            Store::update('settings', (int)$rows[0]['id'], $values);
        }
        self::$data = null;
        Store::flush('settings');
    }
}

/* =========================================================================
 * LANGUAGES
 * ====================================================================== */

final class Languages
{
    /** @return array<int,array<string,mixed>> active languages, ordered */
    public static function active(): array
    {
        $rows = array_filter(Store::all('languages'), static fn($l) => !empty($l['active']));
        usort($rows, static fn($a, $b) => ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0)));
        return array_values($rows);
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        $rows = Store::all('languages');
        usort($rows, static fn($a, $b) => ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0)));
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function byCode(string $code): ?array
    {
        foreach (Store::all('languages') as $l) {
            if (strcasecmp((string)$l['code'], $code) === 0) {
                return $l;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function default(): ?array
    {
        foreach (self::active() as $l) {
            if (!empty($l['is_default'])) {
                return $l;
            }
        }
        return self::active()[0] ?? null;
    }

    public static function defaultCode(): string
    {
        return (string)(self::default()['code'] ?? 'en');
    }

    /** Ensure exactly one default language. */
    public static function setDefault(int $id): void
    {
        $rows = Store::all('languages');
        foreach ($rows as $l) {
            $isDefault = ((int)$l['id'] === $id);
            if ((bool)($l['is_default'] ?? false) !== $isDefault) {
                Store::update('languages', (int)$l['id'], ['is_default' => $isDefault]);
            }
        }
    }
}

/* =========================================================================
 * PAGES
 * ====================================================================== */

final class Pages
{
    /** @return array<int,array<string,mixed>> */
    public static function all(?string $lang = null): array
    {
        $rows = $lang === null ? Store::all('pages') : Store::where('pages', ['lang' => $lang]);
        usort($rows, static function ($a, $b) {
            $c = strcmp((string)($a['lang'] ?? ''), (string)($b['lang'] ?? ''));
            return $c !== 0 ? $c : strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public static function published(?string $lang = null): array
    {
        return array_values(array_filter(
            self::all($lang),
            static fn($p) => ($p['status'] ?? 'published') === 'published'
        ));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Store::find('pages', $id);
    }

    /** @return array<string,mixed>|null */
    public static function bySlug(string $lang, string $slug): ?array
    {
        foreach (Store::all('pages') as $p) {
            if ((string)$p['lang'] === $lang && (string)$p['slug'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    /** Homepage of a language. */
    public static function home(string $lang): ?array
    {
        foreach (Store::all('pages') as $p) {
            if ((string)$p['lang'] === $lang && !empty($p['is_default'])) {
                return $p;
            }
        }
        return null;
    }

    /** Only one default (home) page per language. */
    public static function setDefault(int $id, string $lang): void
    {
        foreach (Store::where('pages', ['lang' => $lang]) as $p) {
            $isDefault = ((int)$p['id'] === $id);
            if ((bool)($p['is_default'] ?? false) !== $isDefault) {
                Store::update('pages', (int)$p['id'], ['is_default' => $isDefault]);
            }
        }
    }

    /**
     * Translations of a page, keyed by language code (excluding the page itself
     * unless $includeSelf is true). Pages are linked by translation_group.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function translations(array $page, bool $includeSelf = true): array
    {
        $group = (string)($page['translation_group'] ?? '');
        $out   = [];
        if ($group === '') {
            return $includeSelf ? [(string)$page['lang'] => $page] : [];
        }
        foreach (Store::all('pages') as $p) {
            if ((string)($p['translation_group'] ?? '') !== $group) {
                continue;
            }
            if (($p['status'] ?? 'published') !== 'published') {
                continue;
            }
            if (!$includeSelf && (int)$p['id'] === (int)$page['id']) {
                continue;
            }
            $out[(string)$p['lang']] = $p;
        }
        return $out;
    }

    /** True when the slug is already used inside the same language. */
    public static function slugExists(string $lang, string $slug, ?int $exceptId = null): bool
    {
        foreach (Store::all('pages') as $p) {
            if ((string)$p['lang'] === $lang
                && (string)$p['slug'] === $slug
                && (int)$p['id'] !== (int)$exceptId) {
                return true;
            }
        }
        return false;
    }

    /** Make a slug unique within its language by appending -2, -3, ... */
    public static function uniqueSlug(string $lang, string $slug, ?int $exceptId = null): string
    {
        $slug = cms_slug($slug);
        if ($slug === '') {
            $slug = 'page';
        }
        $base = $slug;
        $i    = 2;
        while (self::slugExists($lang, $slug, $exceptId)) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}

/* =========================================================================
 * BLOCKS (header / footer / global assets)
 * ====================================================================== */

final class Blocks
{
    /**
     * A block for a position and language, with fallback to the '*' (all
     * languages) variant when no language specific block exists.
     *
     * @return array<string,mixed>|null
     */
    public static function get(string $position, string $lang): ?array
    {
        $fallback = null;
        foreach (Store::all('blocks') as $b) {
            if ((string)$b['position'] !== $position) {
                continue;
            }
            if ((string)$b['lang'] === $lang) {
                return $b;
            }
            if ((string)$b['lang'] === '*') {
                $fallback = $b;
            }
        }
        return $fallback;
    }

    /** Create or update the block identified by position + language. */
    public static function put(string $position, string $lang, array $values): array
    {
        foreach (Store::all('blocks') as $b) {
            if ((string)$b['position'] === $position && (string)$b['lang'] === $lang) {
                Store::update('blocks', (int)$b['id'], $values);
                return Store::find('blocks', (int)$b['id']) ?? [];
            }
        }
        return Store::insert('blocks', array_merge(
            ['position' => $position, 'lang' => $lang, 'html' => '', 'css' => '', 'js' => ''],
            $values
        ));
    }
}

/* =========================================================================
 * TEMPLATE (template.xml)
 * ====================================================================== */

final class Template
{
    /** @var array{positions:array<int,array<string,string>>,layout:array<int,array<string,string>>}|null */
    private static ?array $tpl = null;

    /** @return array{positions:array<int,array<string,string>>,layout:array<int,array<string,string>>} */
    public static function load(): array
    {
        if (self::$tpl !== null) {
            return self::$tpl;
        }

        $default = [
            'positions' => [
                ['key' => 'header', 'label' => 'Header', 'scope' => 'lang'],
                ['key' => 'page',   'label' => 'Page',   'scope' => 'page'],
                ['key' => 'footer', 'label' => 'Footer', 'scope' => 'lang'],
            ],
            'layout' => [
                ['key' => 'header', 'tag' => 'header', 'attrs' => 'role="banner"'],
                ['key' => 'page',   'tag' => 'main',   'attrs' => 'id="main" role="main"'],
                ['key' => 'footer', 'tag' => 'footer', 'attrs' => 'role="contentinfo"'],
            ],
        ];

        $file = CMS_ROOT . '/template.xml';
        if (!is_file($file)) {
            return self::$tpl = $default;
        }

        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_file($file);
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            return self::$tpl = $default;
        }

        $positions = [];
        foreach ($xml->positions->position ?? [] as $p) {
            $positions[] = [
                'key'   => (string)$p['key'],
                'label' => (string)($p['label'] ?: $p['key']),
                'scope' => (string)($p['scope'] ?: 'lang'),
            ];
        }
        $layout = [];
        foreach ($xml->layout->slot ?? [] as $s) {
            $layout[] = [
                'key'   => (string)$s['key'],
                'tag'   => (string)($s['tag'] ?? ''),
                'attrs' => (string)($s['attrs'] ?? ''),
            ];
        }

        return self::$tpl = [
            'positions' => $positions ?: $default['positions'],
            'layout'    => $layout    ?: $default['layout'],
        ];
    }

    /** Editable positions (everything but the page content slot). */
    public static function editablePositions(): array
    {
        return array_values(array_filter(
            self::load()['positions'],
            static fn($p) => ($p['scope'] ?? 'lang') !== 'page'
        ));
    }
}

/* =========================================================================
 * MENU
 * ====================================================================== */

final class Menu
{
    /**
     * Menu items of one menu key and language, as a nested tree.
     *
     * @return array<int,array<string,mixed>> each node has a 'children' key
     */
    public static function tree(string $key, string $lang): array
    {
        $rows = [];
        foreach (Store::all('menu') as $m) {
            if ((string)$m['menu'] === $key && (string)$m['lang'] === $lang && !empty($m['active'])) {
                $rows[] = $m;
            }
        }
        usort($rows, static fn($a, $b) => ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0)));

        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
        }

        $build = static function (int $parent) use (&$build, $byParent): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $node) {
                $node['children'] = $build((int)$node['id']);
                $out[] = $node;
            }
            return $out;
        };

        return $build(0);
    }

    /** Distinct menu keys defined in the data. */
    public static function keys(): array
    {
        $keys = [];
        foreach (Store::all('menu') as $m) {
            $keys[(string)$m['menu']] = true;
        }
        $keys['main'] = true;
        ksort($keys);
        return array_keys($keys);
    }

    /** Resolve the href of a menu item (internal page or external URL). */
    public static function href(array $item): string
    {
        $pageId = (int)($item['page_id'] ?? 0);
        if ($pageId > 0) {
            $page = Pages::find($pageId);
            if ($page !== null) {
                return cms_page_url($page);
            }
        }
        return (string)($item['url'] ?? '#');
    }
}

/* =========================================================================
 * REFERENTIAL INTEGRITY
 * ====================================================================== */

final class Integrity
{
    /**
     * Reasons why a language cannot be deleted.
     *
     * @return array<int,string>
     */
    public static function languageBlockers(string $code): array
    {
        $errors = [];
        $pages  = count(Store::where('pages', ['lang' => $code]));
        if ($pages > 0) {
            $errors[] = sprintf('%d page(s) still use this language.', $pages);
        }
        $menu = count(Store::where('menu', ['lang' => $code]));
        if ($menu > 0) {
            $errors[] = sprintf('%d menu item(s) still use this language.', $menu);
        }
        $blocks = count(Store::where('blocks', ['lang' => $code]));
        if ($blocks > 0) {
            $errors[] = sprintf('%d block(s) still use this language.', $blocks);
        }
        if (count(Languages::active()) <= 1) {
            $errors[] = 'At least one active language must remain.';
        }
        return $errors;
    }

    /**
     * Menu items pointing to a page that is about to be deleted.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function menuItemsForPage(int $pageId): array
    {
        return Store::where('menu', ['page_id' => $pageId]);
    }

    /** Remove dangling references left behind by deletions. */
    public static function purgeOrphans(): int
    {
        $fixed = 0;

        // Menu items whose page no longer exists become inactive custom links.
        foreach (Store::all('menu') as $m) {
            $pid = (int)($m['page_id'] ?? 0);
            if ($pid > 0 && Pages::find($pid) === null) {
                Store::update('menu', (int)$m['id'], ['page_id' => 0, 'active' => false]);
                $fixed++;
            }
        }

        // Menu items whose parent no longer exists are promoted to root.
        $ids = array_column(Store::all('menu'), 'id');
        foreach (Store::all('menu') as $m) {
            $parent = (int)($m['parent_id'] ?? 0);
            if ($parent > 0 && !in_array($parent, $ids, false)) {
                Store::update('menu', (int)$m['id'], ['parent_id' => 0]);
                $fixed++;
            }
        }

        return $fixed;
    }
}

/* =========================================================================
 * HELPERS (also available inside page code)
 * ====================================================================== */

/** Escape for HTML text and attribute context. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Current timestamp in ISO 8601 (used as updated_at and in the sitemap). */
function cms_now(): string
{
    return date('c');
}

/** ASCII, lowercase, hyphenated, SEO friendly slug. */
function cms_slug(string $text): string
{
    $text = trim($text);
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        if ($t !== false) {
            $text = $t;
        }
    } else {
        $text = strtr($text, [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ß'=>'ss','ø'=>'o','å'=>'a','æ'=>'ae',
        ]);
        $text = mb_strtolower($text, 'UTF-8');
    }
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
    return trim($text, '-');
}

/**
 * Absolute site URL, without trailing slash.
 *
 * The value configured in the settings always wins; when it is empty the URL
 * is derived from the request, honouring the X-Forwarded-* headers set by
 * reverse proxies and shared hosting front ends.
 */
function cms_site_url(): string
{
    $url = trim((string)Settings::get('site_url', ''));
    if ($url !== '') {
        return rtrim($url, '/');
    }

    $forwardedProto = strtok((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), ',');
    $scheme = match (true) {
        $forwardedProto !== false && $forwardedProto !== ''      => strtolower(trim($forwardedProto)),
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' => 'https',
        ((int)($_SERVER['SERVER_PORT'] ?? 80)) === 443           => 'https',
        default                                                  => 'http',
    };

    $host = strtok((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''), ',');
    if ($host === false || trim($host) === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    return rtrim($scheme . '://' . trim($host) . CMS_BASE_PATH, '/');
}

/** True when the default language runs without the /xx/ prefix. */
function cms_hide_default_prefix(): bool
{
    return (bool)Settings::get('hide_default_lang_prefix', true);
}

/**
 * Root-relative URL of a page (always starts with the base path).
 *
 * Homepages resolve to "/" (default language) or "/xx/".
 */
function cms_page_url(array|int $page): string
{
    if (is_int($page)) {
        $found = Pages::find($page);
        if ($found === null) {
            return CMS_BASE_PATH . '/';
        }
        $page = $found;
    }

    $lang     = (string)($page['lang'] ?? Languages::defaultCode());
    $isHome   = !empty($page['is_default']);
    $noPrefix = cms_hide_default_prefix() && $lang === Languages::defaultCode();

    if ($isHome) {
        return CMS_BASE_PATH . ($noPrefix ? '/' : '/' . $lang . '/');
    }

    $slug = (string)($page['slug'] ?? '');
    return CMS_BASE_PATH . ($noPrefix ? '/' . $slug : '/' . $lang . '/' . $slug);
}

/** Absolute URL of a page, for canonical, hreflang, sitemap, Open Graph. */
function cms_page_abs_url(array|int $page): string
{
    return cms_site_url() . substr(cms_page_url($page), strlen(CMS_BASE_PATH));
}

/** Absolute or root-relative URL of a media file. */
function cms_media(string $file, bool $absolute = false): string
{
    $file = ltrim($file, '/');
    $path = CMS_BASE_PATH . rtrim(CMS_MEDIA_URL, '/') . '/' . $file;
    return $absolute ? cms_site_url() . substr($path, strlen(CMS_BASE_PATH)) : $path;
}

/** Turn any URL into an absolute one (media names, /paths and full URLs). */
function cms_absolute_url(string $url): string
{
    if ($url === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $url)) {
        return $url;
    }
    if ($url[0] === '/') {
        return cms_site_url() . (CMS_BASE_PATH !== '' && str_starts_with($url, CMS_BASE_PATH)
            ? substr($url, strlen(CMS_BASE_PATH))
            : $url);
    }
    return cms_media($url, true);
}

/** Settings accessor available in page code. */
function cms_setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

/**
 * Render a menu as an accessible nested list.
 *
 * @param array<string,string> $opts ul_class, li_class, a_class, nav_class, aria_label
 */
function cms_menu_html(string $key, ?string $lang = null, array $opts = []): string
{
    $lang    = $lang ?? cms_current_lang();
    $tree    = Menu::tree($key, $lang);
    $current = cms_current_page();
    $currentId = (int)($current['id'] ?? 0);

    $render = static function (array $items, int $depth) use (&$render, $opts, $currentId): string {
        if ($items === []) {
            return '';
        }
        $ulClass = $depth === 0 ? ($opts['ul_class'] ?? '') : ($opts['submenu_class'] ?? $opts['ul_class'] ?? '');
        $html = '<ul' . ($ulClass !== '' ? ' class="' . e($ulClass) . '"' : '') . '>';
        foreach ($items as $item) {
            $href    = Menu::href($item);
            $active  = ((int)($item['page_id'] ?? 0) === $currentId && $currentId > 0);
            $liClass = trim(($opts['li_class'] ?? '') . ($active ? ' is-active' : ''));
            $rel     = trim((string)($item['rel'] ?? ''));
            $target  = trim((string)($item['target'] ?? ''));
            if ($target === '_blank' && !str_contains($rel, 'noopener')) {
                $rel = trim($rel . ' noopener');
            }
            $html .= '<li' . ($liClass !== '' ? ' class="' . e($liClass) . '"' : '') . '>';
            $html .= '<a href="' . e($href) . '"'
                . (($opts['a_class'] ?? '') !== '' ? ' class="' . e($opts['a_class']) . '"' : '')
                . ($target !== '' ? ' target="' . e($target) . '"' : '')
                . ($rel !== '' ? ' rel="' . e($rel) . '"' : '')
                . ($active ? ' aria-current="page"' : '')
                . '>' . e((string)$item['label']) . '</a>';
            $html .= $render($item['children'] ?? [], $depth + 1);
            $html .= '</li>';
        }
        return $html . '</ul>';
    };

    $inner = $render($tree, 0);
    if ($inner === '') {
        return '';
    }

    return '<nav' . (($opts['nav_class'] ?? '') !== '' ? ' class="' . e($opts['nav_class']) . '"' : '')
        . ' aria-label="' . e($opts['aria_label'] ?? ucfirst($key) . ' menu') . '">'
        . $inner . '</nav>';
}

/**
 * Language switcher markup: one link per translation of the current page,
 * falling back to the homepage of the language when no translation exists.
 */
function cms_language_switcher(array $opts = []): string
{
    $languages = Languages::active();
    if (count($languages) < 2) {
        return '';
    }
    $page    = cms_current_page();
    $current = cms_current_lang();
    $trans   = $page !== null ? Pages::translations($page) : [];

    $html = '<nav class="' . e($opts['nav_class'] ?? 'lang-switcher')
        . '" aria-label="' . e($opts['aria_label'] ?? 'Language') . '"><ul>';
    foreach ($languages as $l) {
        $code   = (string)$l['code'];
        $target = $trans[$code] ?? Pages::home($code);
        if ($target === null) {
            continue;
        }
        $isCurrent = ($code === $current);
        $html .= '<li><a href="' . e(cms_page_url($target)) . '" hreflang="' . e((string)($l['hreflang'] ?: $code)) . '" lang="' . e($code) . '"'
            . ($isCurrent ? ' aria-current="true"' : '') . '>'
            . e((string)($l['native_name'] ?: $l['name'])) . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/* -------------------------------------------------------------------------
 * Request context (set by index.php, readable from page code)
 * ---------------------------------------------------------------------- */

/** @var array<string,mixed>|null */
$GLOBALS['cms_context'] = ['page' => null, 'lang' => null];

function cms_set_context(?array $page, string $lang): void
{
    $GLOBALS['cms_context'] = ['page' => $page, 'lang' => $lang];
}

function cms_current_page(): ?array
{
    return $GLOBALS['cms_context']['page'] ?? null;
}

function cms_current_lang(): string
{
    return (string)($GLOBALS['cms_context']['lang'] ?? Languages::defaultCode());
}

/**
 * HTTP status of the response being built.
 *
 * Error responses (404 and friends) must not advertise a canonical URL or
 * hreflang alternates pointing at the error page itself.
 */
function cms_set_status(int $status): void
{
    $GLOBALS['cms_http_status'] = $status;
}

function cms_http_status(): int
{
    return (int)($GLOBALS['cms_http_status'] ?? 200);
}

/* -------------------------------------------------------------------------
 * Sandboxed evaluation of stored HTML+PHP code
 * ---------------------------------------------------------------------- */

/**
 * Execute stored page/block code and return its output.
 *
 * The code is plain PHP+HTML, exactly like a template file. $page, $lang and
 * $settings are always in scope.
 */
function cms_render_code(string $code, array $vars = []): string
{
    if (trim($code) === '') {
        return '';
    }

    $vars = array_merge([
        'page'     => cms_current_page(),
        'lang'     => cms_current_lang(),
        'settings' => Settings::all(),
    ], $vars);

    extract($vars, EXTR_SKIP);

    $level = ob_get_level();
    ob_start();
    try {
        eval('?>' . $code);
    } catch (Throwable $ex) {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        error_log('[SpartanCMS] template error: ' . $ex->getMessage());
        if (CMS_DEBUG) {
            return '<pre style="background:#fee;color:#900;padding:1rem;overflow:auto">'
                . e($ex->getMessage()) . "\n" . e($ex->getTraceAsString()) . '</pre>';
        }
        return '';
    }
    $out = ob_get_clean();
    return $out === false ? '' : $out;
}

/* -------------------------------------------------------------------------
 * Misc
 * ---------------------------------------------------------------------- */

/** Truncate a string on a word boundary (meta descriptions). */
function cms_truncate(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $sp  = mb_strrpos($cut, ' ');
    return rtrim($sp !== false ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-") . '…';
}

/** Collapse HTML whitespace without touching pre/textarea/script/style. */
function cms_minify_html(string $html): string
{
    $chunks = preg_split(
        '#(<(?:pre|textarea|script|style)\b[^>]*>.*?</(?:pre|textarea|script|style)>)#is',
        $html,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if ($chunks === false) {
        return $html;
    }

    $out = '';
    foreach ($chunks as $i => $chunk) {
        if ($i % 2 === 1) {          // protected block, untouched
            $out .= $chunk;
            continue;
        }
        $chunk = preg_replace('/<!--(?!\[if)(?!<!)[^\[>].*?-->/s', '', $chunk) ?? $chunk;
        $chunk = preg_replace('/\s{2,}/', ' ', $chunk) ?? $chunk;
        $chunk = preg_replace('/>\s+</', '><', $chunk) ?? $chunk;
        $out  .= $chunk;
    }
    return trim($out);
}

/** Very small, safe CSS/JS whitespace trimmer used for inlined assets. */
function cms_minify_css(string $css): string
{
    $css = preg_replace('#/\*(?!!).*?\*/#s', '', $css) ?? $css;
    $css = preg_replace('/\s+/', ' ', $css) ?? $css;
    $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $css) ?? $css;
    return trim(str_replace(';}', '}', $css));
}
