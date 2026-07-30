<?php
/**
 * SpartanCMS - SEO: head builder, Schema.org graph, sitemap, robots.
 */

declare(strict_types=1);

require_once __DIR__ . '/core.php';

final class Seo
{
    /**
     * Full <head> content for a page, in the order search engines and
     * browsers prefer (charset and viewport first, then title, then the rest).
     */
    public static function head(array $page, string $lang, string $inlineCss = ''): string
    {
        $s       = Settings::all();
        $isError = cms_http_status() >= 400;
        $out     = [];

        // --- 1. Mandatory technical tags ----------------------------------
        $out[] = '<meta charset="utf-8">';
        $out[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';

        // --- 2. Title ------------------------------------------------------
        $out[] = '<title>' . e(self::title($page)) . '</title>';

        // --- 3. Description / keywords -------------------------------------
        $description = self::description($page);
        if ($description !== '') {
            $out[] = '<meta name="description" content="' . e($description) . '">';
        }
        $keywords = trim((string)($page['meta_keywords'] ?? ''));
        if ($keywords !== '') {
            $out[] = '<meta name="keywords" content="' . e($keywords) . '">';
        }

        // --- 4. Robots ------------------------------------------------------
        $out[] = '<meta name="robots" content="'
            . e($isError ? 'noindex, follow' : self::robots($page)) . '">';

        // --- 5. Canonical ----------------------------------------------------
        // On an error response the requested URL is not this page, so no
        // canonical and no alternates are emitted at all.
        $canonical = trim((string)($page['canonical'] ?? ''));
        $canonical = $canonical !== '' ? cms_absolute_url($canonical) : cms_page_abs_url($page);
        if (!$isError) {
            $out[] = '<link rel="canonical" href="' . e($canonical) . '">';

            // --- 6. hreflang alternates --------------------------------------
            foreach (self::alternates($page) as $hreflang => $href) {
                $out[] = '<link rel="alternate" hreflang="' . e($hreflang) . '" href="' . e($href) . '">';
            }
        }

        // --- 7/8. Open Graph + Twitter ---------------------------------------
        if (!$isError) {
            foreach (self::socialTags($page, $lang, $canonical, $description) as $tag) {
                $out[] = $tag;
            }
        }

        // --- 9. Author / verification / theme ---------------------------------
        $author = trim((string)Settings::get('author', ''));
        if ($author !== '') {
            $out[] = '<meta name="author" content="' . e($author) . '">';
        }
        $gsv = trim((string)Settings::get('google_site_verification', ''));
        if ($gsv !== '') {
            $out[] = '<meta name="google-site-verification" content="' . e($gsv) . '">';
        }
        $theme = trim((string)Settings::get('theme_color', ''));
        if ($theme !== '') {
            $out[] = '<meta name="theme-color" content="' . e($theme) . '">';
        }

        // --- 10. Icons --------------------------------------------------------
        $favicon = trim((string)Settings::get('favicon', ''));
        if ($favicon !== '') {
            $ext  = strtolower(pathinfo($favicon, PATHINFO_EXTENSION));
            $type = match ($ext) {
                'png'  => 'image/png',
                'svg'  => 'image/svg+xml',
                'ico'  => 'image/x-icon',
                'webp' => 'image/webp',
                default => '',
            };
            $out[] = '<link rel="icon"' . ($type !== '' ? ' type="' . e($type) . '"' : '')
                . ' href="' . e(cms_absolute_url($favicon)) . '">';
        }
        $appleIcon = trim((string)Settings::get('apple_touch_icon', ''));
        if ($appleIcon !== '') {
            $out[] = '<link rel="apple-touch-icon" href="' . e(cms_absolute_url($appleIcon)) . '">';
        }

        // --- 11. Preconnect + framework assets ---------------------------------
        foreach (self::preconnects() as $origin) {
            $out[] = '<link rel="preconnect" href="' . e($origin) . '" crossorigin>';
            $out[] = '<link rel="dns-prefetch" href="' . e($origin) . '">';
        }
        $out[] = self::componentAssets('css');
        $out[] = self::componentAssets('js', 'head');

        // --- 12. Inline critical CSS (global + block + page) ---------------------
        if (trim($inlineCss) !== '') {
            $out[] = '<style>' . (CMS_MINIFY_HTML ? cms_minify_css($inlineCss) : $inlineCss) . '</style>';
        }

        // --- 13. Structured data --------------------------------------------------
        // Structured data describes a real resource: never emit it on a 404.
        $out[] = $isError ? '' : self::jsonLd($page, $lang);

        // --- 14. Free-form head code (page first, then global) ---------------------
        $globalHead = (string)Settings::get('head_extra', '');
        if (trim($globalHead) !== '') {
            $out[] = cms_render_code($globalHead);
        }
        $pageHead = (string)($page['head_extra'] ?? '');
        if (trim($pageHead) !== '') {
            $out[] = cms_render_code($pageHead);
        }

        return implode("\n", array_filter($out, static fn($l) => trim((string)$l) !== ''));
    }

    /**
     * Open Graph and Twitter card tags. Skipped on error responses, where the
     * document does not represent the requested resource.
     *
     * @return array<int,string>
     */
    private static function socialTags(array $page, string $lang, string $canonical, string $description): array
    {
        $out = [];
        $ogTitle = trim((string)($page['og_title'] ?? '')) ?: self::title($page);
        $ogDesc  = trim((string)($page['og_description'] ?? '')) ?: $description;
        $ogType  = trim((string)($page['og_type'] ?? '')) ?: (!empty($page['is_default']) ? 'website' : 'article');
        $ogImage = trim((string)($page['og_image'] ?? '')) ?: (string)Settings::get('default_og_image', '');

        $out[] = '<meta property="og:type" content="' . e($ogType) . '">';
        $out[] = '<meta property="og:site_name" content="' . e((string)Settings::get('site_name', '')) . '">';
        $out[] = '<meta property="og:title" content="' . e($ogTitle) . '">';
        if ($ogDesc !== '') {
            $out[] = '<meta property="og:description" content="' . e($ogDesc) . '">';
        }
        $out[] = '<meta property="og:url" content="' . e($canonical) . '">';
        $out[] = '<meta property="og:locale" content="' . e(self::locale($lang)) . '">';
        foreach (self::alternateLocales($page, $lang) as $altLocale) {
            $out[] = '<meta property="og:locale:alternate" content="' . e($altLocale) . '">';
        }
        if ($ogImage !== '') {
            $imgUrl = cms_absolute_url($ogImage);
            $out[]  = '<meta property="og:image" content="' . e($imgUrl) . '">';
            $out[]  = '<meta property="og:image:alt" content="' . e($ogTitle) . '">';
            [$w, $h] = self::imageSize($ogImage);
            if ($w > 0) {
                $out[] = '<meta property="og:image:width" content="' . $w . '">';
                $out[] = '<meta property="og:image:height" content="' . $h . '">';
            }
        }
        if ($ogType === 'article') {
            if (!empty($page['created_at'])) {
                $out[] = '<meta property="article:published_time" content="' . e(date('c', strtotime((string)$page['created_at']))) . '">';
            }
            if (!empty($page['updated_at'])) {
                $out[] = '<meta property="article:modified_time" content="' . e(date('c', strtotime((string)$page['updated_at']))) . '">';
            }
        }

        // Twitter card
        $card = trim((string)($page['twitter_card'] ?? '')) ?: ($ogImage !== '' ? 'summary_large_image' : 'summary');
        $out[] = '<meta name="twitter:card" content="' . e($card) . '">';
        $twSite = trim((string)Settings::get('twitter_site', ''));
        if ($twSite !== '') {
            $out[] = '<meta name="twitter:site" content="' . e($twSite) . '">';
        }
        $out[] = '<meta name="twitter:title" content="' . e($ogTitle) . '">';
        if ($ogDesc !== '') {
            $out[] = '<meta name="twitter:description" content="' . e($ogDesc) . '">';
        }
        if ($ogImage !== '') {
            $out[] = '<meta name="twitter:image" content="' . e(cms_absolute_url($ogImage)) . '">';
        }

        return $out;
    }

    /**
     * Effective <title>: the meta title, or the page name, plus the site
     * suffix.
     *
     * The suffix is skipped when the title already carries the brand, so a
     * hand written title never ends up as "Brand ... | Brand".
     */
    public static function title(array $page): string
    {
        $title  = trim((string)($page['meta_title'] ?? '')) ?: trim((string)($page['name'] ?? ''));
        $suffix = trim((string)Settings::get('title_suffix', ''));

        if ($title === '' || $suffix === '' || !Settings::get('append_title_suffix', true)) {
            return $title !== '' ? $title : $suffix;
        }

        $haystack = mb_strtolower($title);
        $brand    = mb_strtolower(trim((string)Settings::get('site_name', '')));
        $needle   = mb_strtolower(trim($suffix, " |-–—·•"));

        if (($needle !== '' && str_contains($haystack, $needle))
            || ($brand !== '' && str_contains($haystack, $brand))) {
            return $title;
        }

        return $title . ' ' . $suffix;
    }

    public static function description(array $page): string
    {
        $desc = trim((string)($page['meta_description'] ?? ''));
        if ($desc === '') {
            $desc = trim((string)Settings::get('default_description', ''));
        }
        return cms_truncate($desc, 320);
    }

    /** robots directive built from the page flags plus global overrides. */
    public static function robots(array $page): string
    {
        if (!Settings::get('allow_indexing', true)) {
            return 'noindex, nofollow';
        }
        if (($page['status'] ?? 'published') !== 'published') {
            return 'noindex, nofollow';
        }
        $parts = [];
        $parts[] = !empty($page['robots_index']) ? 'index' : 'noindex';
        $parts[] = !empty($page['robots_follow']) ? 'follow' : 'nofollow';
        $extra = trim((string)($page['robots_extra'] ?? ''));
        if ($extra !== '') {
            foreach (preg_split('/\s*,\s*/', $extra) ?: [] as $token) {
                if ($token !== '') {
                    $parts[] = $token;
                }
            }
        } elseif (!empty($page['robots_index'])) {
            $parts[] = 'max-snippet:-1';
            $parts[] = 'max-image-preview:large';
            $parts[] = 'max-video-preview:-1';
        }
        return implode(', ', $parts);
    }

    /**
     * hreflang map for a page, including x-default.
     *
     * @return array<string,string> hreflang => absolute URL
     */
    public static function alternates(array $page): array
    {
        $translations = Pages::translations($page, true);
        if (count($translations) < 2) {
            return [];
        }
        $out = [];
        foreach ($translations as $code => $p) {
            $lang = Languages::byCode($code);
            if ($lang === null || empty($lang['active'])) {
                continue;
            }
            $out[(string)($lang['hreflang'] ?: $code)] = cms_page_abs_url($p);
        }
        if ($out === []) {
            return [];
        }
        $defaultCode = Languages::defaultCode();
        $defaultLang = Languages::byCode($defaultCode);
        $defaultKey  = (string)($defaultLang['hreflang'] ?? $defaultCode);
        if (isset($out[$defaultKey])) {
            $out['x-default'] = $out[$defaultKey];
        }
        return $out;
    }

    /** BCP47-ish locale used by Open Graph (it_IT, en_US, ...). */
    public static function locale(string $code): string
    {
        $lang = Languages::byCode($code);
        $loc  = trim((string)($lang['locale'] ?? ''));
        if ($loc !== '') {
            return str_replace('-', '_', $loc);
        }
        return strtolower($code) . '_' . strtoupper($code);
    }

    /** @return array<int,string> */
    private static function alternateLocales(array $page, string $current): array
    {
        $out = [];
        foreach (Pages::translations($page, false) as $code => $_) {
            if ($code !== $current) {
                $out[] = self::locale($code);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The full Schema.org graph of the page:
     * WebSite + Organization/LocalBusiness/Person + WebPage + BreadcrumbList,
     * merged with the page specific JSON-LD written in the backend.
     */
    public static function jsonLd(array $page, string $lang): string
    {
        $graph = [];
        $site  = cms_site_url();
        $orgId = $site . '/#organization';
        $webId = $site . '/#website';

        $siteName = (string)Settings::get('site_name', '');

        // Organization / Person
        if ((bool)Settings::get('schema_organization_enabled', true) && $siteName !== '') {
            $org = [
                '@type' => (string)Settings::get('schema_organization_type', 'Organization'),
                '@id'   => $orgId,
                'name'  => (string)Settings::get('organization_name', $siteName),
                'url'   => $site . '/',
            ];
            $logo = trim((string)Settings::get('logo', ''));
            if ($logo !== '') {
                $org['logo'] = [
                    '@type' => 'ImageObject',
                    '@id'   => $site . '/#logo',
                    'url'   => cms_absolute_url($logo),
                    'contentUrl' => cms_absolute_url($logo),
                ];
                $org['image'] = ['@id' => $site . '/#logo'];
            }
            $phone = trim((string)Settings::get('organization_phone', ''));
            $email = trim((string)Settings::get('organization_email', ''));
            if ($phone !== '') {
                $org['telephone'] = $phone;
            }
            if ($email !== '') {
                $org['email'] = $email;
            }
            $street = trim((string)Settings::get('organization_street', ''));
            if ($street !== '') {
                $org['address'] = array_filter([
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $street,
                    'postalCode'      => trim((string)Settings::get('organization_zip', '')),
                    'addressLocality' => trim((string)Settings::get('organization_city', '')),
                    'addressRegion'   => trim((string)Settings::get('organization_region', '')),
                    'addressCountry'  => trim((string)Settings::get('organization_country', '')),
                ], static fn($v) => $v !== '');
            }
            $vat = trim((string)Settings::get('organization_vat', ''));
            if ($vat !== '') {
                $org['vatID'] = $vat;
            }
            $profiles = array_values(array_filter(array_map(
                'trim',
                preg_split('/[\r\n,]+/', (string)Settings::get('social_profiles', '')) ?: []
            )));
            if ($profiles !== []) {
                $org['sameAs'] = $profiles;
            }
            $graph[] = $org;
        }

        // WebSite (+ optional SearchAction)
        if ((bool)Settings::get('schema_website_enabled', true)) {
            $website = [
                '@type'         => 'WebSite',
                '@id'           => $webId,
                'url'           => $site . '/',
                'name'          => $siteName,
                'inLanguage'    => $lang,
            ];
            if ($graph !== []) {
                $website['publisher'] = ['@id' => $orgId];
            }
            $desc = trim((string)Settings::get('default_description', ''));
            if ($desc !== '') {
                $website['description'] = $desc;
            }
            $search = trim((string)Settings::get('search_url_template', ''));
            if ($search !== '') {
                $website['potentialAction'] = [
                    '@type'       => 'SearchAction',
                    'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $search],
                    'query-input' => 'required name=search_term_string',
                ];
            }
            $graph[] = $website;
        }

        // WebPage
        $pageUrl = cms_page_abs_url($page);
        $webPage = [
            '@type'      => !empty($page['is_default']) ? 'WebPage' : (string)($page['schema_page_type'] ?? 'WebPage'),
            '@id'        => $pageUrl . '#webpage',
            'url'        => $pageUrl,
            'name'       => self::title($page),
            'inLanguage' => $lang,
        ];
        $d = self::description($page);
        if ($d !== '') {
            $webPage['description'] = $d;
        }
        if ((bool)Settings::get('schema_website_enabled', true)) {
            $webPage['isPartOf'] = ['@id' => $webId];
        }
        if (!empty($page['updated_at'])) {
            $webPage['dateModified'] = date('c', strtotime((string)$page['updated_at']));
        }
        if (!empty($page['created_at'])) {
            $webPage['datePublished'] = date('c', strtotime((string)$page['created_at']));
        }
        $img = trim((string)($page['og_image'] ?? '')) ?: (string)Settings::get('default_og_image', '');
        if ($img !== '') {
            $webPage['primaryImageOfPage'] = [
                '@type' => 'ImageObject',
                'url'   => cms_absolute_url($img),
            ];
        }
        $graph[] = $webPage;

        // BreadcrumbList
        if ((bool)Settings::get('schema_breadcrumbs_enabled', true) && empty($page['is_default'])) {
            $home = Pages::home($lang);
            $items = [];
            $pos = 1;
            if ($home !== null) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => (string)$home['name'],
                    'item'     => cms_page_abs_url($home),
                ];
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos,
                'name'     => (string)$page['name'],
                'item'     => $pageUrl,
            ];
            $graph[] = [
                '@type'           => 'BreadcrumbList',
                '@id'             => $pageUrl . '#breadcrumb',
                'itemListElement' => $items,
            ];
            $graph[count($graph) - 2]['breadcrumb'] = ['@id' => $pageUrl . '#breadcrumb'];
        }

        // Page specific JSON-LD written in the backend (PHP allowed inside).
        $custom = trim((string)($page['schema'] ?? ''));
        if ($custom !== '') {
            $rendered = trim(cms_render_code($custom));
            $decoded  = json_decode($rendered, true);
            if (is_array($decoded)) {
                if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    foreach ($decoded['@graph'] as $node) {
                        $graph[] = $node;
                    }
                } elseif (array_is_list($decoded)) {
                    foreach ($decoded as $node) {
                        $graph[] = $node;
                    }
                } else {
                    unset($decoded['@context']);
                    $graph[] = $decoded;
                }
            } elseif (CMS_DEBUG) {
                error_log('[SpartanCMS] invalid JSON-LD on page ' . ($page['id'] ?? '?'));
            }
        }

        if ($graph === []) {
            return '';
        }

        // JSON_HEX_TAG/AMP escape < > & so a string value can never terminate
        // the <script> block early.
        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** @return array<int,string> unique preconnect origins of the enabled components */
    public static function preconnects(): array
    {
        $origins = [];
        foreach (CMS_HEAD_COMPONENTS as $c) {
            if (!empty($c['enabled']) && !empty($c['preconnect'])) {
                $origins[$c['preconnect']] = true;
            }
        }
        foreach (preg_split('/[\r\n,]+/', (string)Settings::get('preconnect_origins', '')) ?: [] as $o) {
            $o = trim($o);
            if ($o !== '') {
                $origins[$o] = true;
            }
        }
        return array_keys($origins);
    }

    /** <link>/<script> tags of the enabled frameworks for one position. */
    public static function componentAssets(string $type, string $position = 'head'): string
    {
        $tags = [];
        foreach (CMS_HEAD_COMPONENTS as $c) {
            if (empty($c['enabled'])) {
                continue;
            }
            if ($type === 'css') {
                foreach ($c['css'] ?? [] as $css) {
                    $tags[] = '<link rel="stylesheet" href="' . e($css['href']) . '"'
                        . (!empty($css['integrity']) ? ' integrity="' . e($css['integrity']) . '" crossorigin="anonymous"' : '')
                        . ' referrerpolicy="no-referrer">';
                }
            } else {
                foreach ($c['js'] ?? [] as $js) {
                    if (($js['position'] ?? 'body') !== $position) {
                        continue;
                    }
                    $tags[] = '<script src="' . e($js['src']) . '"'
                        . (!empty($js['defer']) ? ' defer' : '')
                        . (!empty($js['integrity']) ? ' integrity="' . e($js['integrity']) . '" crossorigin="anonymous"' : '')
                        . ' referrerpolicy="no-referrer"></script>';
                }
            }
        }
        return implode("\n", $tags);
    }

    /** @return array{0:int,1:int} width/height of a local media image, 0 when unknown */
    private static function imageSize(string $file): array
    {
        if (preg_match('#^https?://#i', $file)) {
            return [0, 0];
        }
        $path = CMS_MEDIA_DIR . '/' . ltrim(str_replace(CMS_MEDIA_URL, '', $file), '/');
        if (!is_file($path)) {
            return [0, 0];
        }
        $info = @getimagesize($path);
        return $info === false ? [0, 0] : [(int)$info[0], (int)$info[1]];
    }
}

/* =========================================================================
 * SITEMAP + ROBOTS
 * ====================================================================== */

final class Sitemap
{
    /**
     * Write sitemap.xml (with xhtml:link alternates) and return the URL count.
     */
    public static function generate(): int
    {
        $urls = [];
        foreach (Pages::published() as $page) {
            if (empty($page['sitemap_include'])) {
                continue;
            }
            if (empty($page['robots_index'])) {
                continue; // never advertise a noindex page
            }
            $lang = Languages::byCode((string)$page['lang']);
            if ($lang === null || empty($lang['active'])) {
                continue;
            }
            $urls[] = $page;
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($urls as $page) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . e(cms_page_abs_url($page)) . "</loc>\n";
            if (!empty($page['updated_at'])) {
                $xml .= '    <lastmod>' . e(date('Y-m-d', strtotime((string)$page['updated_at']))) . "</lastmod>\n";
            }
            $freq = trim((string)($page['sitemap_changefreq'] ?? ''));
            if ($freq !== '') {
                $xml .= '    <changefreq>' . e($freq) . "</changefreq>\n";
            }
            $prio = (string)($page['sitemap_priority'] ?? '');
            if ($prio !== '') {
                $xml .= '    <priority>' . e(number_format((float)$prio, 1, '.', '')) . "</priority>\n";
            }
            foreach (Seo::alternates($page) as $hreflang => $href) {
                if ($hreflang === 'x-default') {
                    continue;
                }
                $xml .= '    <xhtml:link rel="alternate" hreflang="' . e($hreflang)
                    . '" href="' . e($href) . "\"/>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>' . "\n";

        file_put_contents(CMS_ROOT . '/sitemap.xml', $xml, LOCK_EX);
        return count($urls);
    }

    /** Write robots.txt pointing at the sitemap. */
    public static function generateRobots(): void
    {
        $lines = [];
        if (!Settings::get('allow_indexing', true)) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /backend.php';
            $lines[] = 'Disallow: /data/';
            $lines[] = 'Disallow: /includes/';
            $lines[] = 'Allow: /';
            $custom = trim((string)Settings::get('robots_extra', ''));
            if ($custom !== '') {
                $lines[] = '';
                $lines[] = $custom;
            }
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . cms_site_url() . '/sitemap.xml';
        $lines[] = '';

        file_put_contents(CMS_ROOT . '/robots.txt', implode("\n", $lines), LOCK_EX);
    }
}
