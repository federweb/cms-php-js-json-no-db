<?php
/**
 * SpartanCMS - Backend.
 *
 * Single-file administration: settings, languages, menu, pages, template
 * blocks, global CSS/JS, media library and sitemap generation.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/seo.php';

/* =========================================================================
 * SESSION + AUTH
 * ====================================================================== */

session_name(CMS_SESSION_NAME);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'path'     => CMS_BASE_PATH === '' ? '/' : CMS_BASE_PATH,
]);
session_start();

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

/** Idle timeout. */
if (!empty($_SESSION['cms_authenticated'])
    && (time() - (int)($_SESSION['cms_last_seen'] ?? 0)) > CMS_SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['flash'] = [['warn', 'Session expired, please log in again.']];
}

function b_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function b_check_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(b_csrf(), $token)) {
        http_response_code(419);
        exit('Invalid CSRF token. Go back, reload the page and try again.');
    }
}

function b_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

/** @return array<int,array{0:string,1:string}> */
function b_take_flash(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : [];
}

function b_redirect(array $params = []): void
{
    $url = 'backend.php' . ($params !== [] ? '?' . http_build_query($params) : '');
    header('Location: ' . $url, true, 303);
    exit;
}

function b_post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function b_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Raw code fields must keep their formatting: only normalise line endings. */
function b_code(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? str_replace(["\r\n", "\r"], "\n", $v) : '';
}

function b_bool(string $key): bool
{
    return !empty($_POST[$key]);
}

function b_int(string $key, int $default = 0): int
{
    return (int)($_POST[$key] ?? $default);
}

function b_get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

/* ---- Login / logout ------------------------------------------------------ */

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: backend.php', true, 303);
    exit;
}

$loginError = '';

if (($_POST['action'] ?? '') === 'login') {
    $attempts = (int)($_SESSION['login_attempts'] ?? 0);
    $since    = (int)($_SESSION['login_first_attempt'] ?? 0);

    if ($since > 0 && (time() - $since) > CMS_LOGIN_WINDOW) {
        $attempts = 0;
        $_SESSION['login_first_attempt'] = 0;
    }

    if ($attempts >= CMS_LOGIN_MAX_ATTEMPTS) {
        $loginError = 'Too many failed attempts. Try again later.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $stored   = CMS_ADMIN_PASSWORD;
        $ok = str_starts_with($stored, '$')
            ? password_verify($password, $stored)
            : hash_equals($stored, $password);

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['cms_authenticated'] = true;
            $_SESSION['cms_last_seen']     = time();
            unset($_SESSION['login_attempts'], $_SESSION['login_first_attempt']);
            b_redirect();
        }

        $_SESSION['login_attempts'] = $attempts + 1;
        $_SESSION['login_first_attempt'] = $since > 0 ? $since : time();
        $loginError = 'Wrong password.';
        usleep(400000);
    }
}

$authenticated = !empty($_SESSION['cms_authenticated']);
if ($authenticated) {
    $_SESSION['cms_last_seen'] = time();
}

/* =========================================================================
 * ACTIONS (POST)
 * ====================================================================== */

$page = b_get('p', 'dashboard');

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'login') {
    b_check_csrf();
    $action = (string)($_POST['action'] ?? '');

    switch ($action) {

        /* ---- SETTINGS ------------------------------------------------- */
        case 'save_settings': {
            $values = [];
            foreach ([
                'site_name', 'site_url', 'title_suffix', 'default_description', 'author',
                'not_found_slug', 'theme_color', 'favicon', 'apple_touch_icon', 'logo',
                'default_og_image', 'twitter_site', 'google_site_verification',
                'referrer_policy', 'permissions_policy', 'preconnect_origins',
                'skip_link_label', 'schema_organization_type', 'organization_name',
                'organization_email', 'organization_phone', 'organization_street',
                'organization_zip', 'organization_city', 'organization_region',
                'organization_country', 'organization_vat', 'search_url_template',
            ] as $k) {
                $values[$k] = b_str($k);
            }
            foreach (['social_profiles', 'robots_extra', 'head_extra', 'body_extra'] as $k) {
                $values[$k] = b_code($k);
            }
            foreach ([
                'hide_default_lang_prefix', 'allow_indexing', 'append_title_suffix',
                'schema_organization_enabled', 'schema_website_enabled', 'schema_breadcrumbs_enabled',
            ] as $k) {
                $values[$k] = b_bool($k);
            }
            $values['site_url'] = rtrim($values['site_url'], '/');
            Settings::save($values);
            Sitemap::generateRobots();
            b_flash('ok', 'Settings saved. robots.txt regenerated.');
            b_redirect(['p' => 'settings']);
        }

        /* ---- GLOBAL CSS / JS ------------------------------------------- */
        case 'save_assets': {
            $type = b_str('type') === 'js' ? 'js' : 'css';
            Settings::save(['global_' . $type => b_code('code')]);
            b_flash('ok', 'Global ' . strtoupper($type) . ' saved.');
            b_redirect(['p' => 'assets', 'type' => $type]);
        }

        /* ---- LANGUAGES -------------------------------------------------- */
        case 'save_language': {
            $id   = b_int('id');
            $code = strtolower(preg_replace('/[^a-zA-Z\-]/', '', b_str('code')) ?? '');
            if ($code === '') {
                b_flash('err', 'The language code is required.');
                b_redirect(['p' => 'languages']);
            }
            $existing = Languages::byCode($code);
            if ($existing !== null && (int)$existing['id'] !== $id) {
                b_flash('err', 'A language with code "' . $code . '" already exists.');
                b_redirect(['p' => 'languages']);
            }
            $values = [
                'code'        => $code,
                'name'        => b_str('name'),
                'native_name' => b_str('native_name'),
                'locale'      => b_str('locale'),
                'hreflang'    => b_str('hreflang') ?: $code,
                'direction'   => b_str('direction') === 'rtl' ? 'rtl' : 'ltr',
                'active'      => b_bool('active'),
                'sort'        => b_int('sort'),
            ];
            if ($id > 0) {
                $old = Store::find('languages', $id);
                Store::update('languages', $id, $values);
                // Referential integrity: propagate a code change everywhere.
                if ($old !== null && (string)$old['code'] !== $code) {
                    foreach (['pages', 'menu', 'blocks'] as $table) {
                        foreach (Store::where($table, ['lang' => (string)$old['code']]) as $row) {
                            Store::update($table, (int)$row['id'], ['lang' => $code]);
                        }
                    }
                    b_flash('warn', 'Language code changed: pages, menu items and blocks were updated.');
                }
            } else {
                $new = Store::insert('languages', array_merge($values, ['is_default' => false]));
                $id  = (int)$new['id'];
            }
            if (b_bool('is_default')) {
                Languages::setDefault($id);
            }
            Store::flush();
            b_flash('ok', 'Language saved.');
            b_redirect(['p' => 'languages']);
        }

        case 'delete_language': {
            $id   = b_int('id');
            $lang = Store::find('languages', $id);
            if ($lang === null) {
                b_flash('err', 'Language not found.');
                b_redirect(['p' => 'languages']);
            }
            $blockers = Integrity::languageBlockers((string)$lang['code']);
            if ($blockers !== []) {
                b_flash('err', 'Cannot delete "' . $lang['code'] . '": ' . implode(' ', $blockers));
                b_redirect(['p' => 'languages']);
            }
            Store::delete('languages', $id);
            if (!empty($lang['is_default'])) {
                $first = Languages::active()[0] ?? null;
                if ($first !== null) {
                    Languages::setDefault((int)$first['id']);
                }
            }
            b_flash('ok', 'Language deleted.');
            b_redirect(['p' => 'languages']);
        }

        /* ---- PAGES -------------------------------------------------------- */
        case 'save_page': {
            $id   = b_int('id');
            $lang = b_str('lang');
            if (Languages::byCode($lang) === null) {
                b_flash('err', 'Unknown language.');
                b_redirect(['p' => 'pages']);
            }
            $name = b_str('name');
            if ($name === '') {
                b_flash('err', 'The page name is required.');
                b_redirect(['p' => 'pages', 'edit' => $id]);
            }
            $slug = b_str('slug') !== '' ? b_str('slug') : $name;
            $slug = Pages::uniqueSlug($lang, $slug, $id > 0 ? $id : null);

            $values = [
                'name'               => $name,
                'slug'               => $slug,
                'lang'               => $lang,
                'status'             => b_str('status') === 'draft' ? 'draft' : 'published',
                'translation_group'  => cms_slug(b_str('translation_group')),
                'html'               => b_code('html'),
                'css'                => b_code('css'),
                'js'                 => b_code('js'),
                'schema'             => b_code('schema'),
                'schema_page_type'   => b_str('schema_page_type') ?: 'WebPage',
                'meta_title'         => b_str('meta_title'),
                'meta_description'   => b_str('meta_description'),
                'meta_keywords'      => b_str('meta_keywords'),
                'canonical'          => b_str('canonical'),
                'robots_index'       => b_bool('robots_index'),
                'robots_follow'      => b_bool('robots_follow'),
                'robots_extra'       => b_str('robots_extra'),
                'og_title'           => b_str('og_title'),
                'og_description'     => b_str('og_description'),
                'og_image'           => b_str('og_image'),
                'og_type'            => b_str('og_type'),
                'twitter_card'       => b_str('twitter_card'),
                'head_extra'         => b_code('head_extra'),
                'body_class'         => b_str('body_class'),
                'sitemap_include'    => b_bool('sitemap_include'),
                'sitemap_priority'   => b_str('sitemap_priority') ?: '0.5',
                'sitemap_changefreq' => b_str('sitemap_changefreq'),
            ];

            // A JSON-LD block that does not parse is refused before saving.
            if (trim($values['schema']) !== '' && !str_contains($values['schema'], '<?')) {
                json_decode($values['schema']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    b_flash('warn', 'Schema.org JSON is invalid (' . json_last_error_msg()
                        . '). It was saved but will be ignored on the front end.');
                }
            }

            if ($id > 0) {
                Store::update('pages', $id, $values);
            } else {
                $new = Store::insert('pages', array_merge($values, ['is_default' => false]));
                $id  = (int)$new['id'];
            }
            if (b_bool('is_default')) {
                Pages::setDefault($id, $lang);
            }
            Store::flush('pages');
            Sitemap::generate();
            b_flash('ok', 'Page saved and sitemap updated.');
            b_redirect(['p' => 'pages', 'edit' => $id]);
        }

        case 'delete_page': {
            $id  = b_int('id');
            $row = Pages::find($id);
            if ($row === null) {
                b_flash('err', 'Page not found.');
                b_redirect(['p' => 'pages']);
            }
            if (!empty($row['is_default'])) {
                b_flash('err', 'The homepage of a language cannot be deleted. Set another page as homepage first.');
                b_redirect(['p' => 'pages']);
            }
            $refs = Integrity::menuItemsForPage($id);
            Store::delete('pages', $id);
            Integrity::purgeOrphans();
            Sitemap::generate();
            b_flash('ok', 'Page deleted.' . ($refs !== []
                ? ' ' . count($refs) . ' menu item(s) referencing it were deactivated.'
                : ''));
            b_redirect(['p' => 'pages']);
        }

        case 'duplicate_page': {
            $id  = b_int('id');
            $row = Pages::find($id);
            if ($row === null) {
                b_flash('err', 'Page not found.');
                b_redirect(['p' => 'pages']);
            }
            unset($row['id'], $row['created_at'], $row['updated_at']);
            $row['name']       = $row['name'] . ' (copy)';
            $row['slug']       = Pages::uniqueSlug((string)$row['lang'], (string)$row['slug'] . '-copy');
            $row['is_default'] = false;
            $row['status']     = 'draft';
            $new = Store::insert('pages', $row);
            b_flash('ok', 'Page duplicated as draft.');
            b_redirect(['p' => 'pages', 'edit' => (int)$new['id']]);
        }

        /* ---- BLOCKS (header / footer / custom positions) ------------------ */
        case 'save_block': {
            $position = b_str('position');
            $lang     = b_str('lang');
            $valid    = array_column(Template::load()['positions'], 'key');
            if (!in_array($position, $valid, true)) {
                b_flash('err', 'Unknown template position.');
                b_redirect();
            }
            if ($lang !== '*' && Languages::byCode($lang) === null) {
                b_flash('err', 'Unknown language.');
                b_redirect();
            }
            Blocks::put($position, $lang, [
                'html' => b_code('html'),
                'css'  => b_code('css'),
                'js'   => b_code('js'),
            ]);
            Store::flush('blocks');
            b_flash('ok', ucfirst($position) . ' (' . $lang . ') saved.');
            b_redirect(['p' => 'block', 'position' => $position, 'lang' => $lang]);
        }

        /* ---- MENU ---------------------------------------------------------- */
        case 'save_menu_item': {
            $id     = b_int('id');
            $lang   = b_str('lang');
            $pageId = b_int('page_id');
            if (Languages::byCode($lang) === null) {
                b_flash('err', 'Unknown language.');
                b_redirect(['p' => 'menu']);
            }
            if ($pageId > 0) {
                $target = Pages::find($pageId);
                if ($target === null) {
                    b_flash('err', 'The selected page does not exist.');
                    b_redirect(['p' => 'menu']);
                }
                if ((string)$target['lang'] !== $lang) {
                    b_flash('err', 'The selected page belongs to another language.');
                    b_redirect(['p' => 'menu']);
                }
            }
            $parentId = b_int('parent_id');
            if ($parentId > 0) {
                $parent = Store::find('menu', $parentId);
                if ($parent === null || $parentId === $id) {
                    $parentId = 0;
                }
            }
            $values = [
                'menu'      => cms_slug(b_str('menu')) ?: 'main',
                'lang'      => $lang,
                'label'     => b_str('label'),
                'page_id'   => $pageId,
                'url'       => b_str('url'),
                'parent_id' => $parentId,
                'sort'      => b_int('sort'),
                'target'    => b_str('target') === '_blank' ? '_blank' : '',
                'rel'       => b_str('rel'),
                'active'    => b_bool('active'),
            ];
            if ($values['label'] === '') {
                b_flash('err', 'The menu label is required.');
                b_redirect(['p' => 'menu']);
            }
            if ($id > 0) {
                Store::update('menu', $id, $values);
            } else {
                Store::insert('menu', $values);
            }
            Store::flush('menu');
            b_flash('ok', 'Menu item saved.');
            b_redirect(['p' => 'menu', 'menu' => $values['menu'], 'lang' => $lang]);
        }

        case 'delete_menu_item': {
            $id = b_int('id');
            foreach (Store::where('menu', ['parent_id' => $id]) as $child) {
                Store::update('menu', (int)$child['id'], ['parent_id' => 0]);
            }
            Store::delete('menu', $id);
            b_flash('ok', 'Menu item deleted.');
            b_redirect(['p' => 'menu']);
        }

        case 'reorder_menu': {
            foreach ((array)b_post('sort', []) as $itemId => $sort) {
                Store::update('menu', (int)$itemId, ['sort' => (int)$sort]);
            }
            b_flash('ok', 'Order updated.');
            b_redirect(['p' => 'menu', 'menu' => b_str('menu'), 'lang' => b_str('lang')]);
        }

        /* ---- MEDIA ----------------------------------------------------------- */
        case 'upload_media': {
            $files    = $_FILES['files'] ?? null;
            $uploaded = 0;
            $errors   = [];

            if (is_array($files) && isset($files['name']) && is_array($files['name'])) {
                foreach ($files['name'] as $i => $name) {
                    if ((int)$files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) {
                        $errors[] = $name . ': upload error ' . $files['error'][$i];
                        continue;
                    }
                    $size = (int)$files['size'][$i];
                    if ($size > CMS_UPLOAD_MAX_SIZE) {
                        $errors[] = $name . ': file too big.';
                        continue;
                    }
                    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
                    if (!in_array($ext, CMS_UPLOAD_EXTENSIONS, true)) {
                        $errors[] = $name . ': extension .' . $ext . ' is not allowed.';
                        continue;
                    }
                    $base  = cms_slug(pathinfo((string)$name, PATHINFO_FILENAME)) ?: 'file';
                    $final = $base . '.' . $ext;
                    $n     = 2;
                    while (is_file(CMS_MEDIA_DIR . '/' . $final)) {
                        $final = $base . '-' . $n++ . '.' . $ext;
                    }
                    if (!move_uploaded_file((string)$files['tmp_name'][$i], CMS_MEDIA_DIR . '/' . $final)) {
                        $errors[] = $name . ': cannot write to the media folder.';
                        continue;
                    }
                    @chmod(CMS_MEDIA_DIR . '/' . $final, 0644);

                    $info = @getimagesize(CMS_MEDIA_DIR . '/' . $final);
                    Store::insert('media', [
                        'file'          => $final,
                        'original_name' => (string)$name,
                        'mime'          => (string)($files['type'][$i] ?? ''),
                        'size'          => $size,
                        'width'         => $info !== false ? (int)$info[0] : 0,
                        'height'        => $info !== false ? (int)$info[1] : 0,
                        'alt'           => '',
                    ]);
                    $uploaded++;
                }
            }
            if ($uploaded > 0) {
                b_flash('ok', $uploaded . ' file(s) uploaded.');
            }
            foreach ($errors as $err) {
                b_flash('err', $err);
            }
            b_redirect(['p' => 'media']);
        }

        case 'update_media': {
            Store::update('media', b_int('id'), ['alt' => b_str('alt')]);
            b_flash('ok', 'Alt text saved.');
            b_redirect(['p' => 'media']);
        }

        case 'delete_media': {
            $id  = b_int('id');
            $row = Store::find('media', $id);
            if ($row !== null) {
                $path = CMS_MEDIA_DIR . '/' . basename((string)$row['file']);
                if (is_file($path)) {
                    unlink($path);
                }
                Store::delete('media', $id);
                b_flash('ok', 'File deleted.');
            }
            b_redirect(['p' => 'media']);
        }

        /* ---- SITEMAP ----------------------------------------------------------- */
        case 'generate_sitemap': {
            $count = Sitemap::generate();
            Sitemap::generateRobots();
            b_flash('ok', 'sitemap.xml generated with ' . $count . ' URL(s). robots.txt updated.');
            b_redirect(['p' => 'sitemap']);
        }

        case 'purge_orphans': {
            $fixed = Integrity::purgeOrphans();
            b_flash('ok', $fixed === 0 ? 'No dangling reference found.' : $fixed . ' reference(s) repaired.');
            b_redirect(['p' => 'sitemap']);
        }
    }
}

/* =========================================================================
 * VIEW HELPERS
 * ====================================================================== */

function b_link(array $params): string
{
    return 'backend.php' . ($params !== [] ? '?' . e(http_build_query($params)) : '');
}

function b_active(string $key): string
{
    global $page;
    return $page === $key ? ' class="active"' : '';
}

/** A code editor field backed by Ace. */
function b_editor(string $name, string $label, string $value, string $mode, string $hint = '', int $height = 420): void
{
    $id = 'ed_' . $name;
    echo '<div class="field">';
    echo '<label for="' . e($id) . '">' . e($label);
    if ($hint !== '') {
        echo ' <span class="hint">' . $hint . '</span>';
    }
    echo '</label>';
    echo '<div class="ace" data-target="' . e($id) . '" data-mode="' . e($mode) . '" style="height:' . $height . 'px"></div>';
    echo '<textarea id="' . e($id) . '" name="' . e($name) . '" class="code-source" spellcheck="false">'
        . e($value) . '</textarea>';
    echo '</div>';
}

function b_input(string $name, string $label, string $value, string $type = 'text', string $hint = '', array $attrs = []): void
{
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . e($k) . '="' . e((string)$v) . '"';
    }
    echo '<div class="field"><label for="f_' . e($name) . '">' . e($label);
    if ($hint !== '') {
        echo ' <span class="hint">' . $hint . '</span>';
    }
    echo '</label><input type="' . e($type) . '" id="f_' . e($name) . '" name="' . e($name)
        . '" value="' . e($value) . '"' . $extra . '></div>';
}

function b_textarea(string $name, string $label, string $value, int $rows = 3, string $hint = '', array $attrs = []): void
{
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . e($k) . '="' . e((string)$v) . '"';
    }
    echo '<div class="field"><label for="f_' . e($name) . '">' . e($label);
    if ($hint !== '') {
        echo ' <span class="hint">' . $hint . '</span>';
    }
    echo '</label><textarea id="f_' . e($name) . '" name="' . e($name) . '" rows="' . $rows . '"' . $extra . '>'
        . e($value) . '</textarea></div>';
}

function b_checkbox(string $name, string $label, bool $checked, string $hint = ''): void
{
    echo '<div class="field check"><label><input type="checkbox" name="' . e($name) . '" value="1"'
        . ($checked ? ' checked' : '') . '> ' . e($label);
    if ($hint !== '') {
        echo ' <span class="hint">' . $hint . '</span>';
    }
    echo '</label></div>';
}

/** @param array<string,string> $options */
function b_select(string $name, string $label, string $value, array $options, string $hint = ''): void
{
    echo '<div class="field"><label for="f_' . e($name) . '">' . e($label);
    if ($hint !== '') {
        echo ' <span class="hint">' . $hint . '</span>';
    }
    echo '</label><select id="f_' . e($name) . '" name="' . e($name) . '">';
    foreach ($options as $k => $v) {
        echo '<option value="' . e((string)$k) . '"' . ((string)$k === $value ? ' selected' : '') . '>'
            . e($v) . '</option>';
    }
    echo '</select></div>';
}

function b_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return round($n, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

$aceBase = rtrim(dirname(CMS_ACE_URL), '/') . '/';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>SpartanCMS &middot; Backend</title>
<style>
:root{
  --ink:#1b1f26; --muted:#6b7480; --bg:#f2f4f7; --panel:#fff; --line:#dde3ea;
  --accent:#1a56db; --ok:#0a7d3f; --err:#b91c1c; --warn:#a86200; --radius:8px;
}
@media (prefers-color-scheme: dark){
  :root{ --ink:#e6eaf0; --muted:#98a2b3; --bg:#0e1116; --panel:#161a21; --line:#262d38; --accent:#79a3ff; }
}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
     color:var(--ink);background:var(--bg)}
a{color:var(--accent)}
h1{font-size:1.35rem;margin:0 0 1rem}
h2{font-size:1.05rem;margin:2rem 0 .75rem;padding-bottom:.4rem;border-bottom:1px solid var(--line)}
code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.9em;
     background:var(--bg);padding:.1em .35em;border-radius:4px}

/* layout */
.shell{display:grid;grid-template-columns:220px 1fr;min-height:100vh}
aside{background:var(--panel);border-right:1px solid var(--line);padding:1rem 0;position:sticky;top:0;height:100vh;overflow:auto}
aside .logo{font-weight:700;padding:.25rem 1.25rem 1rem;font-size:1.1rem;letter-spacing:-.02em}
aside nav a{display:block;padding:.5rem 1.25rem;color:var(--ink);text-decoration:none;font-size:.94rem;border-left:3px solid transparent}
aside nav a:hover{background:var(--bg)}
aside nav a.active{border-left-color:var(--accent);background:var(--bg);font-weight:600}
aside .group{padding:1rem 1.25rem .25rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
main{padding:1.5rem 2rem 4rem;min-width:0}

/* panels */
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:1.25rem;margin-bottom:1.25rem}
.row{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))}
.field{margin-bottom:.9rem;min-width:0}
.field label{display:block;font-weight:600;font-size:.86rem;margin-bottom:.3rem}
.field.check label{font-weight:400}
.hint{font-weight:400;color:var(--muted);font-size:.8rem}
input[type=text],input[type=password],input[type=url],input[type=email],input[type=number],select,textarea{
  width:100%;padding:.5rem .6rem;border:1px solid var(--line);border-radius:6px;background:var(--bg);
  color:var(--ink);font:inherit}
textarea{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.86rem;resize:vertical}
textarea.code-source{display:none}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:1px}

/* buttons */
.btn{display:inline-block;padding:.5rem 1rem;border:1px solid var(--accent);background:var(--accent);
     color:#fff;border-radius:6px;text-decoration:none;font:inherit;font-weight:600;cursor:pointer}
.btn.sec{background:transparent;color:var(--accent)}
.btn.danger{background:var(--err);border-color:var(--err)}
.btn.sm{padding:.28rem .6rem;font-size:.82rem}
.actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:1rem}

/* tables */
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
tr:hover td{background:var(--bg)}
.tag{display:inline-block;padding:.1rem .45rem;border-radius:20px;font-size:.72rem;font-weight:700;
     background:var(--bg);border:1px solid var(--line)}
.tag.ok{color:var(--ok);border-color:var(--ok)}
.tag.draft{color:var(--warn);border-color:var(--warn)}

/* flash */
.flash{padding:.65rem .9rem;border-radius:var(--radius);margin-bottom:.75rem;font-size:.9rem;border:1px solid}
.flash.ok{color:var(--ok);border-color:var(--ok);background:color-mix(in srgb,var(--ok) 8%,transparent)}
.flash.err{color:var(--err);border-color:var(--err);background:color-mix(in srgb,var(--err) 8%,transparent)}
.flash.warn{color:var(--warn);border-color:var(--warn);background:color-mix(in srgb,var(--warn) 8%,transparent)}

/* tabs */
.tabs{display:flex;gap:.25rem;flex-wrap:wrap;border-bottom:1px solid var(--line);margin-bottom:1rem}
.tabs button{background:none;border:none;border-bottom:2px solid transparent;padding:.55rem .9rem;
             font:inherit;font-weight:600;color:var(--muted);cursor:pointer}
.tabs button.on{color:var(--ink);border-bottom-color:var(--accent)}
.tabpane{display:none}
.tabpane.on{display:block}

/* editor */
.ace{width:100%;border:1px solid var(--line);border-radius:6px;overflow:hidden}
.counter{font-size:.78rem;color:var(--muted);margin-top:.2rem}
.counter.over{color:var(--err);font-weight:700}

/* visual editor */
.visual-editor-wrapper{position:relative}
.visual-editor-toggle{position:absolute;bottom:.75rem;right:.75rem;display:flex;gap:.4rem;z-index:10}
.visual-editor-toggle button{padding:.35rem .65rem;font-size:.8rem;border:1px solid var(--line);background:var(--bg);color:var(--ink);border-radius:4px;cursor:pointer;font-weight:600;transition:all .2s}
.visual-editor-toggle button.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.visual-editor-toggle button:hover{border-color:var(--accent)}
.visual-editor-container{width:100%;border:1px solid var(--line);border-radius:6px;background:var(--panel);min-height:520px;display:none;overflow:auto;position:relative}
.visual-editor-container.active{display:block}
.visual-editor-content{padding:1.5rem;min-height:500px;outline:none;line-height:1.6;word-wrap:break-word;font-size:1rem}
.visual-editor-content table{width:100%;border-collapse:collapse;margin:1rem 0}
.visual-editor-content table,table td,table th{border:1px solid var(--line)}
.visual-editor-content table td,.visual-editor-content table th{padding:.5rem}
.visual-editor-content h1,.visual-editor-content h2,.visual-editor-content h3{margin:1rem 0 .5rem}
.visual-editor-content p{margin:.75rem 0}
.visual-editor-content ul,.visual-editor-content ol{margin:1rem 0;padding-left:2rem}
.visual-editor-toolbar{display:none;padding:.5rem;border-bottom:1px solid var(--line);gap:.3rem;flex-wrap:wrap;background:var(--bg)}
.visual-editor-container.active~.visual-editor-toolbar{display:flex}
.visual-editor-toolbar button{padding:.35rem .5rem;font-size:.75rem;border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:3px;cursor:pointer;font-weight:600;min-width:32px}
.visual-editor-toolbar button:hover,.visual-editor-toolbar button.active{background:var(--accent);color:#fff;border-color:var(--accent)}

/* media grid */
.media-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr))}
.media-item{border:1px solid var(--line);border-radius:var(--radius);padding:.6rem;background:var(--panel);font-size:.8rem}
.media-item .thumb{height:7rem;display:grid;place-items:center;background:var(--bg);border-radius:6px;overflow:hidden;margin-bottom:.5rem}
.media-item img{max-width:100%;max-height:7rem;object-fit:contain}
.media-item .name{word-break:break-all;font-weight:600;margin-bottom:.25rem}
.ext{font-size:1.6rem;font-weight:700;color:var(--muted)}
.login{max-width:22rem;margin:12vh auto;padding:2rem;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius)}
.muted{color:var(--muted)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85em}
@media(max-width:820px){ .shell{grid-template-columns:1fr} aside{position:static;height:auto} main{padding:1rem} }
</style>
</head>
<body>

<?php if (!$authenticated): ?>

<form class="login" method="post" action="backend.php">
    <h1>SpartanCMS</h1>
    <?php if ($loginError !== ''): ?>
        <p class="flash err"><?= e($loginError) ?></p>
    <?php endif; ?>
    <?php foreach (b_take_flash() as [$type, $msg]): ?>
        <p class="flash <?= e($type) ?>"><?= e($msg) ?></p>
    <?php endforeach; ?>
    <input type="hidden" name="action" value="login">
    <div class="field">
        <label for="pw">Password</label>
        <input type="password" id="pw" name="password" autocomplete="current-password" autofocus required>
    </div>
    <button class="btn" type="submit">Sign in</button>
</form>

<?php else:
    $positions = Template::editablePositions();
    $langs     = Languages::all();
?>

<div class="shell">
<aside>
    <div class="logo">SpartanCMS</div>
    <nav>
        <a href="<?= b_link(['p' => 'dashboard']) ?>"<?= b_active('dashboard') ?>>Dashboard</a>
        <a href="<?= b_link(['p' => 'settings']) ?>"<?= b_active('settings') ?>>Settings</a>
        <a href="<?= b_link(['p' => 'languages']) ?>"<?= b_active('languages') ?>>Languages</a>
        <a href="<?= b_link(['p' => 'menu']) ?>"<?= b_active('menu') ?>>Menu</a>
        <a href="<?= b_link(['p' => 'pages']) ?>"<?= b_active('pages') ?>>Pages</a>

        <div class="group">Template</div>
        <?php foreach ($positions as $pos): ?>
            <a href="<?= b_link(['p' => 'block', 'position' => $pos['key']]) ?>"
               <?= ($page === 'block' && b_get('position') === $pos['key']) ? ' class="active"' : '' ?>>
                <?= e($pos['label']) ?>
            </a>
        <?php endforeach; ?>
        <a href="<?= b_link(['p' => 'assets', 'type' => 'css']) ?>"
           <?= ($page === 'assets' && b_get('type', 'css') === 'css') ? ' class="active"' : '' ?>>Global CSS</a>
        <a href="<?= b_link(['p' => 'assets', 'type' => 'js']) ?>"
           <?= ($page === 'assets' && b_get('type') === 'js') ? ' class="active"' : '' ?>>Global JS</a>

        <div class="group">Content</div>
        <a href="<?= b_link(['p' => 'media']) ?>"<?= b_active('media') ?>>Media</a>
        <a href="<?= b_link(['p' => 'sitemap']) ?>"<?= b_active('sitemap') ?>>Sitemap &amp; SEO</a>

        <div class="group">Session</div>
        <a href="<?= e(cms_site_url()) ?>/" target="_blank" rel="noopener">View site &nearr;</a>
        <a href="backend.php?logout=1">Log out</a>
    </nav>
</aside>

<main>
<?php foreach (b_take_flash() as [$type, $msg]): ?>
    <p class="flash <?= e($type) ?>"><?= e($msg) ?></p>
<?php endforeach; ?>

<?php
switch ($page) {

/* =========================================================================
 * DASHBOARD
 * ====================================================================== */
case 'dashboard':
    $pages   = Pages::all();
    $drafts  = array_filter($pages, static fn($p) => ($p['status'] ?? '') === 'draft');
    $noDesc  = array_filter($pages, static fn($p) => trim((string)($p['meta_description'] ?? '')) === '');
    $noTitle = array_filter($pages, static fn($p) => trim((string)($p['meta_title'] ?? '')) === '');
?>
    <h1>Dashboard</h1>
    <div class="row">
        <div class="panel">
            <h2 style="margin-top:0">Content</h2>
            <p><strong><?= count($pages) ?></strong> pages &middot;
               <strong><?= count($drafts) ?></strong> drafts<br>
               <strong><?= count(Languages::active()) ?></strong> active languages &middot;
               <strong><?= count(Store::all('media')) ?></strong> media files</p>
        </div>
        <div class="panel">
            <h2 style="margin-top:0">SEO health</h2>
            <p>
                <?= count($noTitle) ?> page(s) without a custom meta title<br>
                <?= count($noDesc) ?> page(s) without a meta description<br>
                Indexing: <?= Settings::get('allow_indexing', true)
                    ? '<span class="tag ok">enabled</span>'
                    : '<span class="tag draft">disabled</span>' ?>
            </p>
        </div>
        <div class="panel">
            <h2 style="margin-top:0">Sitemap</h2>
            <?php $sm = CMS_ROOT . '/sitemap.xml'; ?>
            <p><?= is_file($sm)
                ? 'Last generated ' . e(date('Y-m-d H:i', (int)filemtime($sm)))
                : 'Not generated yet.' ?></p>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
                <input type="hidden" name="action" value="generate_sitemap">
                <button class="btn sm">Generate now</button>
            </form>
        </div>
    </div>

    <h2>Environment</h2>
    <div class="panel">
        <?php
        $phpOk = PHP_VERSION_ID >= 80000;
        $checks = [
            'PHP version' => [$phpOk, PHP_VERSION . ($phpOk ? '' : ' - PHP 8.0 or newer required')],
            'mbstring'    => [extension_loaded('mbstring'), extension_loaded('mbstring') ? 'loaded' : 'missing - required'],
            'zlib (gzip)' => [extension_loaded('zlib'), extension_loaded('zlib') ? 'loaded' : 'missing - output is sent uncompressed'],
            'intl'        => [extension_loaded('intl'), extension_loaded('intl') ? 'loaded' : 'missing - slugs fall back to a smaller transliteration table'],
            'GD'          => [extension_loaded('gd'), extension_loaded('gd') ? 'loaded' : 'missing - image dimensions still read via getimagesize()'],
            'data/ writable'   => [is_writable(CMS_DATA_DIR), CMS_DATA_DIR],
            'media/ writable'  => [is_writable(CMS_MEDIA_DIR), CMS_MEDIA_DIR],
            'root writable (sitemap.xml, robots.txt)' => [is_writable(CMS_ROOT), CMS_ROOT],
            'Base path' => [true, CMS_BASE_PATH === '' ? '(domain root)' : CMS_BASE_PATH
                . (CMS_BASE_PATH_OVERRIDE === null ? ' - detected automatically' : ' - forced in config.php')],
            'Detected site URL' => [true, cms_site_url()],
        ];
        echo '<table>';
        foreach ($checks as $label => [$ok, $detail]) {
            $required = !in_array($label, ['intl', 'GD', 'zlib (gzip)'], true);
            $tag = $ok
                ? '<span class="tag ok">ok</span>'
                : ($required ? '<span class="tag draft">problem</span>' : '<span class="tag">optional</span>');
            echo '<tr><td>' . e((string)$label) . '</td><td>' . $tag . '</td>'
                . '<td class="mono muted">' . e((string)$detail) . '</td></tr>';
        }
        echo '</table>';
        ?>
    </div>

    <h2>Pages needing attention</h2>
    <div class="panel">
        <table>
            <tr><th>Page</th><th>Lang</th><th>Issue</th><th></th></tr>
            <?php
            $issues = 0;
            foreach ($pages as $p) {
                $why = [];
                if (trim((string)$p['meta_description']) === '') { $why[] = 'no meta description'; }
                if (mb_strlen(Seo::title($p)) > 65) { $why[] = 'title longer than 65 chars'; }
                if (trim((string)$p['html']) === '') { $why[] = 'empty content'; }
                if (!preg_match('/<h1[\s>]/i', (string)$p['html']) && ($p['status'] ?? '') === 'published') {
                    $why[] = 'no <h1> found';
                }
                if ($why === []) { continue; }
                $issues++;
                echo '<tr><td>' . e((string)$p['name']) . '</td><td><span class="tag">' . e((string)$p['lang'])
                    . '</span></td><td class="muted">' . e(implode(', ', $why)) . '</td>'
                    . '<td><a class="btn sec sm" href="' . b_link(['p' => 'pages', 'edit' => (int)$p['id']]) . '">Fix</a></td></tr>';
            }
            if ($issues === 0) {
                echo '<tr><td colspan="4" class="muted">Everything looks good.</td></tr>';
            }
            ?>
        </table>
    </div>
<?php
    break;

/* =========================================================================
 * SETTINGS
 * ====================================================================== */
case 'settings':
    $s = Settings::all();
?>
    <h1>Settings</h1>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
        <input type="hidden" name="action" value="save_settings">

        <div class="panel">
            <h2 style="margin-top:0">Identity</h2>
            <div class="row">
                <?php
                b_input('site_name', 'Site name', (string)($s['site_name'] ?? ''));
                b_input('site_url', 'Site URL', (string)($s['site_url'] ?? ''), 'url',
                    'absolute, no trailing slash - used for canonical, hreflang and sitemap');
                b_input('title_suffix', 'Title suffix', (string)($s['title_suffix'] ?? ''), 'text', 'e.g. | Brand');
                b_input('author', 'Author', (string)($s['author'] ?? ''));
                ?>
            </div>
            <?php
            b_textarea('default_description', 'Default meta description', (string)($s['default_description'] ?? ''), 2,
                'used when a page has none');
            b_checkbox('append_title_suffix', 'Append the title suffix to page names', (bool)($s['append_title_suffix'] ?? true));
            ?>
        </div>

        <div class="panel">
            <h2 style="margin-top:0">URLs &amp; indexing</h2>
            <?php
            b_checkbox('hide_default_lang_prefix', 'Serve the default language without the /xx/ prefix',
                (bool)($s['hide_default_lang_prefix'] ?? true), 'recommended: / instead of /it/');
            b_checkbox('allow_indexing', 'Allow search engines to index this site',
                (bool)($s['allow_indexing'] ?? true), 'off = noindex everywhere + robots.txt Disallow');
            ?>
            <div class="row">
                <?php
                b_input('not_found_slug', '404 page slug', (string)($s['not_found_slug'] ?? '404'));
                b_input('skip_link_label', 'Skip link label', (string)($s['skip_link_label'] ?? ''));
                b_input('referrer_policy', 'Referrer-Policy', (string)($s['referrer_policy'] ?? ''));
                b_input('permissions_policy', 'Permissions-Policy', (string)($s['permissions_policy'] ?? ''));
                ?>
            </div>
            <?php b_textarea('robots_extra', 'Extra robots.txt rules', (string)($s['robots_extra'] ?? ''), 3); ?>
        </div>

        <div class="panel">
            <h2 style="margin-top:0">Social &amp; assets</h2>
            <div class="row">
                <?php
                b_input('logo', 'Logo', (string)($s['logo'] ?? ''), 'text', 'media file name or URL');
                b_input('default_og_image', 'Default social image', (string)($s['default_og_image'] ?? ''), 'text', '1200x630');
                b_input('favicon', 'Favicon', (string)($s['favicon'] ?? ''));
                b_input('apple_touch_icon', 'Apple touch icon', (string)($s['apple_touch_icon'] ?? ''));
                b_input('twitter_site', 'X / Twitter handle', (string)($s['twitter_site'] ?? ''), 'text', '@brand');
                b_input('theme_color', 'Theme color', (string)($s['theme_color'] ?? ''), 'text', '#111111');
                b_input('google_site_verification', 'Google site verification', (string)($s['google_site_verification'] ?? ''));
                b_input('preconnect_origins', 'Extra preconnect origins', (string)($s['preconnect_origins'] ?? ''),
                    'text', 'comma separated');
                ?>
            </div>
            <?php b_textarea('social_profiles', 'Social profiles (sameAs)', (string)($s['social_profiles'] ?? ''), 3,
                'one URL per line'); ?>
        </div>

        <div class="panel">
            <h2 style="margin-top:0">Schema.org</h2>
            <?php
            b_checkbox('schema_website_enabled', 'Emit the WebSite node', (bool)($s['schema_website_enabled'] ?? true));
            b_checkbox('schema_organization_enabled', 'Emit the Organization node', (bool)($s['schema_organization_enabled'] ?? true));
            b_checkbox('schema_breadcrumbs_enabled', 'Emit BreadcrumbList on inner pages', (bool)($s['schema_breadcrumbs_enabled'] ?? true));
            ?>
            <div class="row">
                <?php
                b_select('schema_organization_type', 'Organization type', (string)($s['schema_organization_type'] ?? 'Organization'), [
                    'Organization' => 'Organization',
                    'LocalBusiness' => 'LocalBusiness',
                    'Corporation' => 'Corporation',
                    'NGO' => 'NGO',
                    'EducationalOrganization' => 'EducationalOrganization',
                    'Person' => 'Person',
                ]);
                b_input('organization_name', 'Legal name', (string)($s['organization_name'] ?? ''));
                b_input('organization_email', 'Email', (string)($s['organization_email'] ?? ''), 'email');
                b_input('organization_phone', 'Phone', (string)($s['organization_phone'] ?? ''));
                b_input('organization_street', 'Street address', (string)($s['organization_street'] ?? ''));
                b_input('organization_zip', 'Postal code', (string)($s['organization_zip'] ?? ''));
                b_input('organization_city', 'City', (string)($s['organization_city'] ?? ''));
                b_input('organization_region', 'Region', (string)($s['organization_region'] ?? ''));
                b_input('organization_country', 'Country code', (string)($s['organization_country'] ?? ''), 'text', 'IT, US, ...');
                b_input('organization_vat', 'VAT ID', (string)($s['organization_vat'] ?? ''));
                b_input('search_url_template', 'Search URL template', (string)($s['search_url_template'] ?? ''),
                    'text', 'https://site.com/search?q={search_term_string}');
                ?>
            </div>
        </div>

        <div class="panel">
            <h2 style="margin-top:0">Injected code</h2>
            <?php
            b_editor('head_extra', 'Extra &lt;head&gt; code (all pages)', (string)($s['head_extra'] ?? ''), 'php',
                'analytics, verification tags, preloads - PHP allowed', 220);
            b_editor('body_extra', 'Code before &lt;/body&gt; (all pages)', (string)($s['body_extra'] ?? ''), 'php', '', 220);
            ?>
        </div>

        <div class="actions"><button class="btn">Save settings</button></div>
    </form>
<?php
    break;

/* =========================================================================
 * LANGUAGES
 * ====================================================================== */
case 'languages':
    $editId = (int)b_get('edit', '0');
    $edit   = $editId > 0 ? Store::find('languages', $editId) : null;
?>
    <h1>Languages</h1>

    <div class="panel">
        <table>
            <tr><th>Code</th><th>Name</th><th>Locale</th><th>hreflang</th><th>Default</th><th>Active</th><th>Pages</th><th></th></tr>
            <?php foreach ($langs as $l): ?>
                <tr>
                    <td class="mono"><strong><?= e((string)$l['code']) ?></strong></td>
                    <td><?= e((string)$l['name']) ?> <span class="muted">(<?= e((string)$l['native_name']) ?>)</span></td>
                    <td class="mono"><?= e((string)$l['locale']) ?></td>
                    <td class="mono"><?= e((string)$l['hreflang']) ?></td>
                    <td><?= !empty($l['is_default']) ? '<span class="tag ok">default</span>' : '' ?></td>
                    <td><?= !empty($l['active']) ? 'yes' : '<span class="muted">no</span>' ?></td>
                    <td><?= count(Store::where('pages', ['lang' => (string)$l['code']])) ?></td>
                    <td>
                        <a class="btn sec sm" href="<?= b_link(['p' => 'languages', 'edit' => (int)$l['id']]) ?>">Edit</a>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Delete this language?')">
                            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
                            <input type="hidden" name="action" value="delete_language">
                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <button class="btn danger sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <h2><?= $edit ? 'Edit language' : 'New language' ?></h2>
    <div class="panel">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
            <input type="hidden" name="action" value="save_language">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="row">
                <?php
                b_input('code', 'Code', (string)($edit['code'] ?? ''), 'text', 'it, en, de - used in URLs');
                b_input('name', 'Name (English)', (string)($edit['name'] ?? ''));
                b_input('native_name', 'Native name', (string)($edit['native_name'] ?? ''), 'text', 'shown in the switcher');
                b_input('locale', 'Locale', (string)($edit['locale'] ?? ''), 'text', 'it_IT - used by og:locale');
                b_input('hreflang', 'hreflang', (string)($edit['hreflang'] ?? ''), 'text', 'it, it-CH, en-GB');
                b_select('direction', 'Direction', (string)($edit['direction'] ?? 'ltr'), ['ltr' => 'ltr', 'rtl' => 'rtl']);
                b_input('sort', 'Sort', (string)($edit['sort'] ?? '0'), 'number');
                ?>
            </div>
            <?php
            b_checkbox('active', 'Active', (bool)($edit['active'] ?? true));
            b_checkbox('is_default', 'Default language', (bool)($edit['is_default'] ?? false),
                'its homepage is served at /');
            ?>
            <div class="actions">
                <button class="btn"><?= $edit ? 'Update' : 'Create' ?></button>
                <?php if ($edit): ?><a class="btn sec" href="<?= b_link(['p' => 'languages']) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
<?php
    break;

/* =========================================================================
 * PAGES
 * ====================================================================== */
case 'pages':
    $editId = (int)b_get('edit', '0');
    $edit   = $editId > 0 ? Pages::find($editId) : null;

    if ($edit === null && !isset($_GET['new'])):
        $filterLang = b_get('lang', '');
?>
    <h1>Pages</h1>
    <div class="actions" style="margin:0 0 1rem">
        <a class="btn" href="<?= b_link(['p' => 'pages', 'new' => 1]) ?>">New page</a>
        <a class="btn sec<?= $filterLang === '' ? ' active' : '' ?>" href="<?= b_link(['p' => 'pages']) ?>">All</a>
        <?php foreach ($langs as $l): ?>
            <a class="btn sec" href="<?= b_link(['p' => 'pages', 'lang' => (string)$l['code']]) ?>"><?= e((string)$l['code']) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="panel">
        <table>
            <tr><th>Name</th><th>Lang</th><th>URL</th><th>Group</th><th>Status</th><th>Index</th><th>Updated</th><th></th></tr>
            <?php foreach (Pages::all($filterLang !== '' ? $filterLang : null) as $p): ?>
                <tr>
                    <td>
                        <strong><?= e((string)$p['name']) ?></strong>
                        <?= !empty($p['is_default']) ? ' <span class="tag ok">home</span>' : '' ?>
                    </td>
                    <td><span class="tag"><?= e((string)$p['lang']) ?></span></td>
                    <td class="mono"><a href="<?= e(cms_page_url($p)) ?>" target="_blank" rel="noopener"><?= e(cms_page_url($p)) ?></a></td>
                    <td class="mono muted"><?= e((string)($p['translation_group'] ?? '')) ?></td>
                    <td><?= ($p['status'] ?? '') === 'published'
                        ? '<span class="tag ok">published</span>'
                        : '<span class="tag draft">draft</span>' ?></td>
                    <td><?= !empty($p['robots_index']) ? 'index' : '<span class="muted">noindex</span>' ?></td>
                    <td class="muted"><?= e(date('Y-m-d', strtotime((string)($p['updated_at'] ?? 'now')))) ?></td>
                    <td style="white-space:nowrap">
                        <a class="btn sec sm" href="<?= b_link(['p' => 'pages', 'edit' => (int)$p['id']]) ?>">Edit</a>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
                            <input type="hidden" name="action" value="duplicate_page">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn sec sm">Copy</button>
                        </form>
                        <?php if (empty($p['is_default'])): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this page?')">
                            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
                            <input type="hidden" name="action" value="delete_page">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn danger sm">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php
    else:
        $p = $edit ?? [
            'id' => 0, 'name' => '', 'slug' => '', 'lang' => Languages::defaultCode(),
            'is_default' => false, 'status' => 'published', 'translation_group' => '',
            'html' => "<section class=\"section wrap\">\n  <h1>New page</h1>\n  <p>Content goes here.</p>\n</section>",
            'css' => '', 'js' => '', 'schema' => '', 'schema_page_type' => 'WebPage',
            'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '', 'canonical' => '',
            'robots_index' => true, 'robots_follow' => true, 'robots_extra' => '',
            'og_title' => '', 'og_description' => '', 'og_image' => '', 'og_type' => '',
            'twitter_card' => '', 'head_extra' => '', 'body_class' => '',
            'sitemap_include' => true, 'sitemap_priority' => '0.5', 'sitemap_changefreq' => 'monthly',
        ];
        $langOptions = [];
        foreach ($langs as $l) {
            $langOptions[(string)$l['code']] = $l['name'] . ' (' . $l['code'] . ')';
        }
?>
    <h1><?= $edit ? 'Edit page: ' . e((string)$p['name']) : 'New page' ?></h1>
    <?php if ($edit): ?>
        <p class="muted">
            Public URL:
            <a class="mono" href="<?= e(cms_page_url($p)) ?>" target="_blank" rel="noopener"><?= e(cms_page_abs_url($p)) ?></a>
            <?php if (($p['status'] ?? '') === 'draft'): ?>
                &middot; <a class="mono" href="<?= e(cms_page_url($p)) ?>?preview=1" target="_blank" rel="noopener">preview draft</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post" id="pageform">
        <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
        <input type="hidden" name="action" value="save_page">
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

        <div class="panel">
            <div class="row">
                <?php
                b_input('name', 'Page name', (string)$p['name'], 'text', 'used as fallback title and in menus');
                b_input('slug', 'URL slug', (string)$p['slug'], 'text', 'lowercase, hyphens - left empty it is derived from the name');
                b_select('lang', 'Language', (string)$p['lang'], $langOptions);
                b_input('translation_group', 'Translation group', (string)($p['translation_group'] ?? ''), 'text',
                    'same value across languages links them via hreflang');
                b_select('status', 'Status', (string)$p['status'], ['published' => 'Published', 'draft' => 'Draft']);
                b_input('body_class', 'Body CSS class', (string)($p['body_class'] ?? ''));
                ?>
            </div>
            <?php b_checkbox('is_default', 'This is the homepage of its language', (bool)$p['is_default']); ?>
        </div>

        <div class="tabs" role="tablist">
            <button type="button" class="on" data-tab="t-html">HTML + PHP</button>
            <button type="button" data-tab="t-css">CSS</button>
            <button type="button" data-tab="t-js">JS</button>
            <button type="button" data-tab="t-schema">Schema.org</button>
            <button type="button" data-tab="t-meta">Meta tags</button>
            <button type="button" data-tab="t-seo">SEO &amp; sitemap</button>
        </div>

        <div class="tabpane on" id="t-html">
            <div class="panel visual-editor-wrapper">
                <div class="visual-editor-container" id="visualEditor" contenteditable="true" spellcheck="false"></div>
                <div class="visual-editor-toolbar" id="visualToolbar">
                    <button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>
                    <button type="button" data-cmd="italic" title="Italic"><em>I</em></button>
                    <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
                    <button type="button" data-cmd="strikethrough" title="Strikethrough"><s>S</s></button>
                    <span style="width:1px;background:var(--line);margin:0 .2rem"></span>
                    <button type="button" data-cmd="formatblock" data-value="p" title="Paragraph">P</button>
                    <button type="button" data-cmd="formatblock" data-value="h2" title="Heading 2">H2</button>
                    <button type="button" data-cmd="formatblock" data-value="h3" title="Heading 3">H3</button>
                    <span style="width:1px;background:var(--line);margin:0 .2rem"></span>
                    <button type="button" data-cmd="insertunorderedlist" title="Bullet list">• List</button>
                    <button type="button" data-cmd="insertorderedlist" title="Numbered list">1. List</button>
                </div>
                <div class="visual-editor-toggle">
                    <button type="button" id="btnCode" class="active">code</button>
                    <button type="button" id="btnVisual">visual</button>
                </div>
                <?php b_editor('html', 'Page markup', (string)$p['html'], 'php',
                    'plain PHP+HTML. Helpers: e() cms_page_url() cms_menu_html() cms_media() cms_setting() cms_language_switcher(). $page, $lang, $settings are in scope.',
                    520); ?>
            </div>
        </div>

        <div class="tabpane" id="t-css">
            <div class="panel">
                <?php b_editor('css', 'Page CSS', (string)$p['css'], 'css',
                    'inlined in the head of this page only - keep it critical and small', 480); ?>
            </div>
        </div>

        <div class="tabpane" id="t-js">
            <div class="panel">
                <?php b_editor('js', 'Page JS', (string)$p['js'], 'javascript',
                    'inlined right before &lt;/body&gt; on this page only', 480); ?>
            </div>
        </div>

        <div class="tabpane" id="t-schema">
            <div class="panel">
                <?php
                b_select('schema_page_type', 'WebPage @type', (string)($p['schema_page_type'] ?? 'WebPage'), [
                    'WebPage' => 'WebPage', 'AboutPage' => 'AboutPage', 'ContactPage' => 'ContactPage',
                    'CollectionPage' => 'CollectionPage', 'ItemPage' => 'ItemPage', 'FAQPage' => 'FAQPage',
                    'ProfilePage' => 'ProfilePage', 'CheckoutPage' => 'CheckoutPage', 'SearchResultsPage' => 'SearchResultsPage',
                ], 'the system always emits WebSite + Organization + WebPage + BreadcrumbList');
                b_editor('schema', 'Additional JSON-LD', (string)$p['schema'], 'json',
                    'a single node, an array of nodes or {"@graph":[...]}. @context is added automatically. PHP is allowed.',
                    420);
                ?>
                <p class="muted">Validate with the
                   <a href="https://validator.schema.org/" target="_blank" rel="noopener">Schema Markup Validator</a> and
                   <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Rich Results Test</a>.</p>
            </div>
        </div>

        <div class="tabpane" id="t-meta">
            <div class="panel">
                <div class="row">
                    <?php
                    b_input('meta_title', 'Meta title', (string)$p['meta_title'], 'text',
                        'ideal 50-60 characters', ['data-counter' => '60']);
                    b_input('canonical', 'Canonical URL override', (string)$p['canonical'], 'text',
                        'leave empty to use the page URL');
                    ?>
                </div>
                <?php
                b_textarea('meta_description', 'Meta description', (string)$p['meta_description'], 3,
                    'ideal 120-158 characters', ['data-counter' => '158']);
                b_input('meta_keywords', 'Meta keywords', (string)$p['meta_keywords'], 'text',
                    'ignored by Google - fill only if a specific engine needs it');
                ?>
                <h2>Open Graph / Twitter</h2>
                <div class="row">
                    <?php
                    b_input('og_title', 'og:title', (string)$p['og_title'], 'text', 'defaults to the meta title');
                    b_input('og_image', 'og:image', (string)$p['og_image'], 'text', 'media file name or absolute URL');
                    b_select('og_type', 'og:type', (string)$p['og_type'], [
                        '' => 'auto (website on home, article elsewhere)',
                        'website' => 'website', 'article' => 'article', 'product' => 'product', 'profile' => 'profile',
                    ]);
                    b_select('twitter_card', 'twitter:card', (string)$p['twitter_card'], [
                        '' => 'auto', 'summary' => 'summary', 'summary_large_image' => 'summary_large_image',
                    ]);
                    ?>
                </div>
                <?php b_textarea('og_description', 'og:description', (string)$p['og_description'], 2,
                    'defaults to the meta description'); ?>
                <h2>Extra head code</h2>
                <?php b_editor('head_extra', 'Injected in &lt;head&gt; for this page', (string)($p['head_extra'] ?? ''), 'php', '', 200); ?>
            </div>
        </div>

        <div class="tabpane" id="t-seo">
            <div class="panel">
                <h2 style="margin-top:0">Robots</h2>
                <?php
                b_checkbox('robots_index', 'Allow indexing (index)', (bool)$p['robots_index']);
                b_checkbox('robots_follow', 'Follow links (follow)', (bool)$p['robots_follow']);
                b_input('robots_extra', 'Extra robots directives', (string)($p['robots_extra'] ?? ''), 'text',
                    'max-snippet:-1, max-image-preview:large, noarchive ...');
                ?>
                <h2>Sitemap</h2>
                <?php
                b_checkbox('sitemap_include', 'Include this page in sitemap.xml', (bool)$p['sitemap_include'],
                    'noindex pages are always excluded');
                ?>
                <div class="row">
                    <?php
                    b_select('sitemap_priority', 'Priority', (string)$p['sitemap_priority'], [
                        '1.0' => '1.0', '0.9' => '0.9', '0.8' => '0.8', '0.7' => '0.7',
                        '0.6' => '0.6', '0.5' => '0.5', '0.4' => '0.4', '0.3' => '0.3', '0.1' => '0.1',
                    ]);
                    b_select('sitemap_changefreq', 'Change frequency', (string)$p['sitemap_changefreq'], [
                        '' => '(none)', 'always' => 'always', 'hourly' => 'hourly', 'daily' => 'daily',
                        'weekly' => 'weekly', 'monthly' => 'monthly', 'yearly' => 'yearly', 'never' => 'never',
                    ]);
                    ?>
                </div>

                <?php if ($edit): $alts = Seo::alternates($p); ?>
                <h2>hreflang cluster</h2>
                <?php if ($alts === []): ?>
                    <p class="muted">No translation linked. Give the same <em>translation group</em> to the
                       equivalent pages in the other languages to generate hreflang alternates.</p>
                <?php else: ?>
                    <table>
                        <tr><th>hreflang</th><th>URL</th></tr>
                        <?php foreach ($alts as $hl => $href): ?>
                            <tr><td class="mono"><?= e((string)$hl) ?></td><td class="mono"><?= e($href) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; endif; ?>
            </div>
        </div>

        <div class="actions">
            <button class="btn">Save page</button>
            <a class="btn sec" href="<?= b_link(['p' => 'pages']) ?>">Back to list</a>
        </div>
    </form>
<?php
    endif;
    break;

/* =========================================================================
 * BLOCKS (header / footer / any template position)
 * ====================================================================== */
case 'block':
    $posKey = b_get('position', 'header');
    $valid  = array_column($positions, 'key');
    if (!in_array($posKey, $valid, true)) {
        $posKey = (string)($valid[0] ?? 'header');
    }
    $posLabel = $posKey;
    foreach ($positions as $pos) {
        if ($pos['key'] === $posKey) {
            $posLabel = $pos['label'];
        }
    }
    $blockLang = b_get('lang', Languages::defaultCode());
    $block     = Blocks::get($posKey, $blockLang) ?? ['html' => '', 'css' => '', 'js' => '', 'lang' => $blockLang];
    $ownLang   = (string)($block['lang'] ?? $blockLang) === $blockLang;
?>
    <h1><?= e($posLabel) ?></h1>
    <div class="actions" style="margin:0 0 1rem">
        <?php foreach ($langs as $l): ?>
            <a class="btn <?= (string)$l['code'] === $blockLang ? '' : 'sec' ?>"
               href="<?= b_link(['p' => 'block', 'position' => $posKey, 'lang' => (string)$l['code']]) ?>">
                <?= e((string)$l['code']) ?>
            </a>
        <?php endforeach; ?>
        <a class="btn <?= $blockLang === '*' ? '' : 'sec' ?>"
           href="<?= b_link(['p' => 'block', 'position' => $posKey, 'lang' => '*']) ?>">all languages</a>
    </div>

    <?php if (!$ownLang): ?>
        <p class="flash warn">No block defined for "<?= e($blockLang) ?>" yet: you are seeing the
           all-languages fallback. Saving creates a dedicated version.</p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
        <input type="hidden" name="action" value="save_block">
        <input type="hidden" name="position" value="<?= e($posKey) ?>">
        <input type="hidden" name="lang" value="<?= e($blockLang) ?>">

        <div class="tabs">
            <button type="button" class="on" data-tab="b-html">HTML + PHP</button>
            <button type="button" data-tab="b-css">CSS</button>
            <button type="button" data-tab="b-js">JS</button>
        </div>
        <div class="tabpane on" id="b-html"><div class="panel">
            <?php b_editor('html', $posLabel . ' markup', (string)$block['html'], 'php',
                'wrapped by the tag declared in template.xml', 460); ?>
        </div></div>
        <div class="tabpane" id="b-css"><div class="panel">
            <?php b_editor('css', $posLabel . ' CSS', (string)$block['css'], 'css', 'inlined on every page', 420); ?>
        </div></div>
        <div class="tabpane" id="b-js"><div class="panel">
            <?php b_editor('js', $posLabel . ' JS', (string)$block['js'], 'javascript', 'inlined before &lt;/body&gt;', 420); ?>
        </div></div>

        <div class="actions"><button class="btn">Save <?= e($posLabel) ?></button></div>
    </form>
<?php
    break;

/* =========================================================================
 * GLOBAL CSS / JS
 * ====================================================================== */
case 'assets':
    $type = b_get('type', 'css') === 'js' ? 'js' : 'css';
    $code = (string)Settings::get('global_' . $type, '');
?>
    <h1>Global <?= strtoupper($type) ?></h1>
    <p class="muted">Applied to every page of every language.
        <?= $type === 'css'
            ? 'Inlined in &lt;head&gt; before the page CSS: no extra request, no render blocking.'
            : 'Inlined right before &lt;/body&gt;, after the framework scripts.' ?></p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
        <input type="hidden" name="action" value="save_assets">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <div class="panel">
            <?php b_editor('code', 'Global ' . strtoupper($type), $code,
                $type === 'css' ? 'css' : 'javascript', '', 560); ?>
        </div>
        <div class="actions"><button class="btn">Save</button></div>
    </form>
<?php
    break;

/* =========================================================================
 * MENU
 * ====================================================================== */
case 'menu':
    $menuKey  = b_get('menu', 'main');
    $menuLang = b_get('lang', Languages::defaultCode());
    $editId   = (int)b_get('edit', '0');
    $item     = $editId > 0 ? Store::find('menu', $editId) : null;

    $items = [];
    foreach (Store::all('menu') as $m) {
        if ((string)$m['menu'] === $menuKey && (string)$m['lang'] === $menuLang) {
            $items[] = $m;
        }
    }
    usort($items, static fn($a, $b) => ((int)$a['sort']) <=> ((int)$b['sort']));

    $pageOptions = ['0' => '- custom URL -'];
    foreach (Pages::all($menuLang) as $pg) {
        $pageOptions[(string)$pg['id']] = $pg['name'] . ' (/' . $pg['slug'] . ')';
    }
    $parentOptions = ['0' => '- top level -'];
    foreach ($items as $m) {
        if ((int)$m['id'] !== $editId) {
            $parentOptions[(string)$m['id']] = (string)$m['label'];
        }
    }
?>
    <h1>Menu</h1>
    <div class="actions" style="margin:0 0 1rem">
        <?php foreach (Menu::keys() as $k): ?>
            <a class="btn <?= $k === $menuKey ? '' : 'sec' ?>"
               href="<?= b_link(['p' => 'menu', 'menu' => $k, 'lang' => $menuLang]) ?>"><?= e($k) ?></a>
        <?php endforeach; ?>
        <span class="muted">|</span>
        <?php foreach ($langs as $l): ?>
            <a class="btn <?= (string)$l['code'] === $menuLang ? '' : 'sec' ?>"
               href="<?= b_link(['p' => 'menu', 'menu' => $menuKey, 'lang' => (string)$l['code']]) ?>">
                <?= e((string)$l['code']) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
            <input type="hidden" name="action" value="reorder_menu">
            <input type="hidden" name="menu" value="<?= e($menuKey) ?>">
            <input type="hidden" name="lang" value="<?= e($menuLang) ?>">
            <table>
                <tr><th style="width:5rem">Sort</th><th>Label</th><th>Target</th><th>Parent</th><th>Active</th><th></th></tr>
                <?php foreach ($items as $m):
                    $target = (int)$m['page_id'] > 0 ? Pages::find((int)$m['page_id']) : null; ?>
                    <tr>
                        <td><input type="number" name="sort[<?= (int)$m['id'] ?>]" value="<?= (int)$m['sort'] ?>" style="width:4.5rem"></td>
                        <td><strong><?= e((string)$m['label']) ?></strong></td>
                        <td class="mono">
                            <?php if ($target !== null): ?>
                                <?= e(cms_page_url($target)) ?>
                            <?php elseif ((int)$m['page_id'] > 0): ?>
                                <span class="tag draft">missing page #<?= (int)$m['page_id'] ?></span>
                            <?php else: ?>
                                <?= e((string)$m['url']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= (int)$m['parent_id'] > 0
                            ? e((string)(Store::find('menu', (int)$m['parent_id'])['label'] ?? '?')) : '-' ?></td>
                        <td><?= !empty($m['active']) ? 'yes' : '<span class="muted">no</span>' ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn sec sm" href="<?= b_link(['p' => 'menu', 'menu' => $menuKey, 'lang' => $menuLang, 'edit' => (int)$m['id']]) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($items === []): ?>
                    <tr><td colspan="6" class="muted">No item in this menu yet.</td></tr>
                <?php endif; ?>
            </table>
            <?php if ($items !== []): ?><div class="actions"><button class="btn sec sm">Save order</button></div><?php endif; ?>
        </form>
    </div>

    <h2><?= $item ? 'Edit item' : 'New item' ?></h2>
    <div class="panel">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
            <input type="hidden" name="action" value="save_menu_item">
            <input type="hidden" name="id" value="<?= (int)($item['id'] ?? 0) ?>">
            <div class="row">
                <?php
                b_input('menu', 'Menu key', (string)($item['menu'] ?? $menuKey), 'text', 'main, footer, legal ...');
                b_select('lang', 'Language', (string)($item['lang'] ?? $menuLang),
                    array_combine(array_column($langs, 'code'), array_column($langs, 'code')));
                b_input('label', 'Label', (string)($item['label'] ?? ''));
                b_select('page_id', 'Linked page', (string)($item['page_id'] ?? '0'), $pageOptions,
                    'internal links always follow the page URL');
                b_input('url', 'Custom URL', (string)($item['url'] ?? ''), 'text', 'used when no page is selected');
                b_select('parent_id', 'Parent', (string)($item['parent_id'] ?? '0'), $parentOptions);
                b_input('sort', 'Sort', (string)($item['sort'] ?? '0'), 'number');
                b_select('target', 'Target', (string)($item['target'] ?? ''), ['' => 'same tab', '_blank' => 'new tab']);
                b_input('rel', 'rel', (string)($item['rel'] ?? ''), 'text', 'nofollow, noopener ...');
                ?>
            </div>
            <?php b_checkbox('active', 'Active', (bool)($item['active'] ?? true)); ?>
            <div class="actions">
                <button class="btn"><?= $item ? 'Update item' : 'Add item' ?></button>
                <?php if ($item): ?>
                    <a class="btn sec" href="<?= b_link(['p' => 'menu', 'menu' => $menuKey, 'lang' => $menuLang]) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($item): ?>
            <form method="post" onsubmit="return confirm('Delete this menu item?')" style="margin-top:.5rem">
                <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
                <input type="hidden" name="action" value="delete_menu_item">
                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                <button class="btn danger sm">Delete item</button>
            </form>
        <?php endif; ?>
    </div>
<?php
    break;

/* =========================================================================
 * MEDIA
 * ====================================================================== */
case 'media':
    $files = Store::all('media');
    usort($files, static fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
?>
    <h1>Media</h1>
    <div class="panel">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
            <input type="hidden" name="action" value="upload_media">
            <div class="field">
                <label for="files">Upload files
                    <span class="hint">max <?= b_bytes(CMS_UPLOAD_MAX_SIZE) ?> each &middot;
                    <?= e(implode(', ', CMS_UPLOAD_EXTENSIONS)) ?></span></label>
                <input type="file" id="files" name="files[]" multiple>
            </div>
            <button class="btn">Upload</button>
        </form>
    </div>

    <div class="media-grid">
        <?php foreach ($files as $f):
            $isImage = (bool)preg_match('/\.(jpe?g|png|gif|webp|avif|svg|ico)$/i', (string)$f['file']); ?>
            <div class="media-item">
                <div class="thumb">
                    <?php if ($isImage): ?>
                        <img src="<?= e(cms_media((string)$f['file'])) ?>" alt="<?= e((string)$f['alt']) ?>" loading="lazy">
                    <?php else: ?>
                        <span class="ext">.<?= e(strtolower(pathinfo((string)$f['file'], PATHINFO_EXTENSION))) ?></span>
                    <?php endif; ?>
                </div>
                <div class="name"><?= e((string)$f['file']) ?></div>
                <div class="muted"><?= b_bytes((int)$f['size']) ?>
                    <?= (int)$f['width'] > 0 ? ' &middot; ' . (int)$f['width'] . '&times;' . (int)$f['height'] : '' ?>
                </div>
                <input class="mono copy" type="text" readonly value="<?= e((string)$f['file']) ?>"
                       title="Click to copy the file name">
                <form method="post" style="margin-top:.35rem">
                    <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
                    <input type="hidden" name="action" value="update_media">
                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                    <input type="text" name="alt" value="<?= e((string)$f['alt']) ?>" placeholder="alt text">
                    <div class="actions" style="margin-top:.35rem">
                        <button class="btn sec sm">Save alt</button>
                    </div>
                </form>
                <form method="post" onsubmit="return confirm('Delete this file?')">
                    <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
                    <input type="hidden" name="action" value="delete_media">
                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                    <button class="btn danger sm">Delete</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if ($files === []): ?><p class="muted">No media uploaded yet.</p><?php endif; ?>
    </div>
<?php
    break;

/* =========================================================================
 * SITEMAP & SEO TOOLS
 * ====================================================================== */
case 'sitemap':
    $sitemapFile = CMS_ROOT . '/sitemap.xml';
    $robotsFile  = CMS_ROOT . '/robots.txt';
?>
    <h1>Sitemap &amp; SEO</h1>

    <?php if (trim((string)Settings::get('site_url', '')) === ''): ?>
        <p class="flash warn">
            <strong>Site URL is empty.</strong> Canonical URLs, hreflang and the sitemap are
            currently derived from the request (<span class="mono"><?= e(cms_site_url()) ?></span>).
            Set it explicitly under <a href="<?= b_link(['p' => 'settings']) ?>">Settings</a>
            so every page always advertises one, stable, absolute URL.
        </p>
    <?php endif; ?>
    <?php if (CMS_BASE_PATH !== ''):
        $domainRoot = cms_site_url();
        if (str_ends_with($domainRoot, CMS_BASE_PATH)) {
            $domainRoot = substr($domainRoot, 0, -strlen(CMS_BASE_PATH));
        }
    ?>
        <p class="flash warn">
            This installation runs in the subfolder <span class="mono"><?= e(CMS_BASE_PATH) ?></span>.
            Crawlers only read <span class="mono">robots.txt</span> from the domain root, so copy the
            generated file to <span class="mono"><?= e($domainRoot) ?>/robots.txt</span>
            (or add its <span class="mono">Sitemap:</span> line to the existing one).
        </p>
    <?php endif; ?>

    <div class="panel">
        <h2 style="margin-top:0">Generation</h2>
        <p>
            sitemap.xml:
            <?= is_file($sitemapFile)
                ? '<a class="mono" href="' . e(cms_site_url()) . '/sitemap.xml" target="_blank" rel="noopener">'
                  . e(cms_site_url()) . '/sitemap.xml</a> &middot; <span class="muted">updated '
                  . e(date('Y-m-d H:i', (int)filemtime($sitemapFile))) . '</span>'
                : '<span class="muted">not generated yet</span>' ?>
            <br>
            robots.txt:
            <?= is_file($robotsFile)
                ? '<span class="muted">updated ' . e(date('Y-m-d H:i', (int)filemtime($robotsFile))) . '</span>'
                : '<span class="muted">not generated yet</span>' ?>
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
            <input type="hidden" name="action" value="generate_sitemap">
            <button class="btn">Generate sitemap.xml + robots.txt</button>
        </form>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">URLs that will be included</h2>
        <table>
            <tr><th>URL</th><th>Lang</th><th>Priority</th><th>Changefreq</th><th>hreflang</th></tr>
            <?php foreach (Pages::published() as $p):
                if (empty($p['sitemap_include']) || empty($p['robots_index'])) { continue; } ?>
                <tr>
                    <td class="mono"><?= e(cms_page_abs_url($p)) ?></td>
                    <td><span class="tag"><?= e((string)$p['lang']) ?></span></td>
                    <td><?= e((string)$p['sitemap_priority']) ?></td>
                    <td><?= e((string)$p['sitemap_changefreq']) ?></td>
                    <td class="mono muted"><?= e(implode(' ', array_keys(Seo::alternates($p)))) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">Data integrity</h2>
        <p class="muted">Repairs menu items pointing at deleted pages or missing parents.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(b_csrf()) ?>">
            <input type="hidden" name="action" value="purge_orphans">
            <button class="btn sec">Check references</button>
        </form>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">Duplicate content check</h2>
        <?php
        $seen = [];
        $dups = [];
        foreach (Pages::published() as $p) {
            $key = strtolower(trim(Seo::title($p)));
            if ($key === '') { continue; }
            if (isset($seen[$key])) { $dups[$key][] = $p; } else { $seen[$key] = $p; }
        }
        if ($dups === []) {
            echo '<p class="muted">No duplicate title found.</p>';
        } else {
            echo '<ul>';
            foreach ($dups as $title => $list) {
                echo '<li><strong>' . e((string)$title) . '</strong> &mdash; '
                    . (count($list) + 1) . ' pages share this title</li>';
            }
            echo '</ul>';
        }
        ?>
    </div>
<?php
    break;

default:
    echo '<h1>Not found</h1><p class="muted">Unknown backend section.</p>';
}
?>
</main>
</div>

<script src="<?= e(CMS_ACE_URL) ?>" crossorigin="anonymous"></script>
<script>
(function () {
    'use strict';

    /* ---- Tabs ---------------------------------------------------------- */
    document.querySelectorAll('.tabs').forEach(function (tabs) {
        tabs.addEventListener('click', function (ev) {
            var btn = ev.target.closest('button[data-tab]');
            if (!btn) { return; }
            tabs.querySelectorAll('button').forEach(function (b) { b.classList.remove('on'); });
            btn.classList.add('on');
            var group = btn.dataset.tab.split('-')[0];
            document.querySelectorAll('.tabpane').forEach(function (pane) {
                if (pane.id.split('-')[0] === group) { pane.classList.remove('on'); }
            });
            var pane = document.getElementById(btn.dataset.tab);
            if (pane) {
                pane.classList.add('on');
                pane.querySelectorAll('.ace').forEach(function (el) {
                    if (el.env && el.env.editor) { el.env.editor.resize(); }
                });
            }
        });
    });

    /* ---- Ace editors ---------------------------------------------------- */
    if (window.ace) {
        ace.config.set('basePath', <?= json_encode($aceBase, JSON_UNESCAPED_SLASHES) ?>);
        var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

        document.querySelectorAll('.ace').forEach(function (host) {
            var ta = document.getElementById(host.dataset.target);
            if (!ta) { return; }
            var editor = ace.edit(host);
            editor.setTheme(dark ? 'ace/theme/tomorrow_night' : 'ace/theme/chrome');
            editor.session.setMode('ace/mode/' + (host.dataset.mode || 'text'));
            editor.session.setValue(ta.value);
            editor.session.setUseWrapMode(true);
            editor.setOptions({
                fontSize: '13px',
                showPrintMargin: false,
                useSoftTabs: true,
                tabSize: 2,
                enableAutoIndent: true,
                scrollPastEnd: 0.25
            });
            editor.session.on('change', function () { ta.value = editor.getValue(); });
            editor.commands.addCommand({
                name: 'save',
                bindKey: { win: 'Ctrl-S', mac: 'Command-S' },
                exec: function () {
                    ta.value = editor.getValue();
                    var form = ta.closest('form');
                    if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
                }
            });
        });

        /* Belt and braces: sync every editor right before submitting. */
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.querySelectorAll('.ace').forEach(function (host) {
                    var ta = document.getElementById(host.dataset.target);
                    if (ta && host.env && host.env.editor) { ta.value = host.env.editor.getValue(); }
                });
            });
        });
    }

    /* ---- Character counters for SEO fields ------------------------------ */
    document.querySelectorAll('[data-counter]').forEach(function (field) {
        var max = parseInt(field.dataset.counter, 10);
        var out = document.createElement('div');
        out.className = 'counter';
        field.parentNode.appendChild(out);
        var update = function () {
            var n = field.value.length;
            out.textContent = n + ' / ' + max + ' characters';
            out.classList.toggle('over', n > max);
        };
        field.addEventListener('input', update);
        update();
    });

    /* ---- Click-to-copy media file names --------------------------------- */
    document.querySelectorAll('input.copy').forEach(function (input) {
        input.addEventListener('click', function () {
            input.select();
            if (navigator.clipboard) { navigator.clipboard.writeText(input.value); }
            var old = input.title;
            input.title = 'Copied';
            setTimeout(function () { input.title = old; }, 1200);
        });
    });

    /* ---- Auto slug from the page name ----------------------------------- */
    var nameField = document.getElementById('f_name');
    var slugField = document.getElementById('f_slug');
    if (nameField && slugField) {
        nameField.addEventListener('input', function () {
            if (slugField.dataset.touched === '1' || slugField.value.trim() !== '') { return; }
            slugField.placeholder = nameField.value.toLowerCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        });
        slugField.addEventListener('input', function () { slugField.dataset.touched = '1'; });
    }

    /* ---- Visual HTML editor toggle (pages only) ----------------------- */
    var visualEditor = document.getElementById('visualEditor');
    var htmlTextarea = document.getElementById('ed_html');
    var btnCode = document.getElementById('btnCode');
    var btnVisual = document.getElementById('btnVisual');
    var aceEditor = htmlTextarea && htmlTextarea.previousElementSibling ? htmlTextarea.previousElementSibling.env && htmlTextarea.previousElementSibling.env.editor : null;

    if (visualEditor && htmlTextarea && btnCode && btnVisual && aceEditor) {
        var syncToVisual = function () {
            visualEditor.innerHTML = htmlTextarea.value;
        };

        var syncToCode = function () {
            htmlTextarea.value = visualEditor.innerHTML;
            if (aceEditor) { aceEditor.session.setValue(htmlTextarea.value); }
        };

        var protectStructure = function () {
            document.querySelectorAll('[data-protected]').forEach(function (el) {
                el.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); });
                el.addEventListener('keydown', function (e) { if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); } });
                el.contentEditable = 'false';
            });
        };

        btnCode.addEventListener('click', function () {
            btnCode.classList.add('active');
            btnVisual.classList.remove('active');
            visualEditor.classList.remove('active');
            syncToCode();
        });

        btnVisual.addEventListener('click', function () {
            btnVisual.classList.add('active');
            btnCode.classList.remove('active');
            visualEditor.classList.add('active');
            syncToVisual();
        });

        visualEditor.addEventListener('input', function () {
            syncToCode();
        });

        visualEditor.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = e.clipboardData.getData('text/html') || e.clipboardData.getData('text/plain');
            document.execCommand('insertHTML', false, text);
        });

        document.querySelectorAll('#visualToolbar button[data-cmd]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                visualEditor.focus();
                var cmd = btn.dataset.cmd;
                var value = btn.dataset.value || '';
                if (cmd === 'formatblock') {
                    document.execCommand(cmd, false, '<' + value + '>');
                } else {
                    document.execCommand(cmd, false, value);
                }
                syncToCode();
            });
        });

        visualEditor.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
                e.preventDefault();
                visualEditor.undo();
                syncToCode();
            }
        });

        syncToVisual();
    }
}());
</script>

<?php endif; ?>
</body>
</html>
