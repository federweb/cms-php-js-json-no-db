<?php
/**
 * SpartanCMS - Front controller.
 *
 * Resolves /{lang}/{slug} (or /{slug} for the default language) to a page,
 * renders it and sends it with the right status and cache headers.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/render.php';

/* -------------------------------------------------------------------------
 * 1. Request path
 * ---------------------------------------------------------------------- */

$uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

if (CMS_BASE_PATH !== '' && str_starts_with($path, CMS_BASE_PATH)) {
    $path = substr($path, strlen(CMS_BASE_PATH));
}

$hadTrailingSlash = strlen($path) > 1 && str_ends_with($path, '/');
$clean            = trim($path, '/');
$segments         = $clean === '' ? [] : explode('/', $clean);
$segments         = array_values(array_filter($segments, static fn($s) => $s !== ''));

/** Send a permanent redirect and stop. (void, not never: PHP 8.0 compatible) */
function cms_redirect(string $to, int $status = 301): void
{
    if (!headers_sent()) {
        header('Location: ' . $to, true, $status);
        header('X-Robots-Tag: noindex', true);
    }
    exit;
}

/* -------------------------------------------------------------------------
 * 2. Canonical URL normalisation (one URL per page, always)
 * ---------------------------------------------------------------------- */

$queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
$suffix      = $queryString !== '' ? '?' . $queryString : '';

// The front controller never belongs in a URL. Apache strips it through
// .htaccess; this keeps the rule enforced on nginx and on hosts that ignore it.
if (str_ends_with($path, 'index.php')) {
    cms_redirect(CMS_BASE_PATH . rtrim(substr($path, 0, -9), '/') . '/' . $suffix);
}

// Lowercase URLs only.
$lower = strtolower($clean);
if ($clean !== $lower) {
    cms_redirect(CMS_BASE_PATH . '/' . $lower . $suffix);
}

/* -------------------------------------------------------------------------
 * 3. Language + slug resolution
 * ---------------------------------------------------------------------- */

$languages   = Languages::active();
if ($languages === []) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo Renderer::fallback(503, 'Not configured', 'No active language configured. Open backend.php to set the site up.');
    exit;
}

$defaultCode = Languages::defaultCode();
$codes       = array_map(static fn($l) => (string)$l['code'], $languages);
$hidePrefix  = cms_hide_default_prefix();

$lang       = $defaultCode;
$slug       = '';
$hasPrefix  = false;

if ($segments !== [] && in_array($segments[0], $codes, true)) {
    $hasPrefix = true;
    $lang      = array_shift($segments);
}
$slug = implode('/', $segments);

// The default language must not be reachable both with and without prefix.
if ($hasPrefix && $hidePrefix && $lang === $defaultCode) {
    cms_redirect(CMS_BASE_PATH . ($slug === '' ? '/' : '/' . $slug) . $suffix);
}
if (!$hasPrefix && !$hidePrefix && $slug !== '') {
    cms_redirect(CMS_BASE_PATH . '/' . $defaultCode . '/' . $slug . $suffix);
}

/* -------------------------------------------------------------------------
 * 4. Page lookup
 * ---------------------------------------------------------------------- */

$status = 200;

if ($slug === '') {
    $page = Pages::home($lang);
    // A homepage is never served with a trailing-slash-less prefix mismatch:
    // "/it" redirects to "/it/".
    if ($page !== null && $hasPrefix && !$hadTrailingSlash) {
        cms_redirect(CMS_BASE_PATH . '/' . $lang . '/' . $suffix);
    }
} else {
    // Inner pages are canonical without the trailing slash.
    if ($hadTrailingSlash) {
        cms_redirect(CMS_BASE_PATH . rtrim($path, '/') . $suffix);
    }
    $page = Pages::bySlug($lang, $slug);
}

/* Draft preview for a logged-in editor (?preview=1 with a valid session). */
$isPreview = false;
if ($page !== null && ($page['status'] ?? 'published') !== 'published') {
    if (isset($_GET['preview']) && isset($_COOKIE[CMS_SESSION_NAME])) {
        session_name(CMS_SESSION_NAME);
        session_start();
        $isPreview = !empty($_SESSION['cms_authenticated']);
        session_write_close();
    }
    if (!$isPreview) {
        $page = null;
    }
}

/* -------------------------------------------------------------------------
 * 5. 404 handling
 * ---------------------------------------------------------------------- */

if ($page === null) {
    $status = 404;
    $page   = Pages::bySlug($lang, (string)Settings::get('not_found_slug', '404'))
        ?? Pages::bySlug($defaultCode, (string)Settings::get('not_found_slug', '404'));

    if ($page !== null && ($page['status'] ?? 'published') !== 'published') {
        $page = null;
    }
}

/* -------------------------------------------------------------------------
 * 6. Output
 * ---------------------------------------------------------------------- */

if (CMS_GZIP && !ini_get('zlib.output_compression') && extension_loaded('zlib')) {
    ob_start('ob_gzhandler');
} else {
    ob_start();
}

cms_set_status($status);

/*
 * Error boundary: a broken page must degrade into a proper 500 document, never
 * into the blank response a fatal error would otherwise produce.
 */
try {
    if ($page === null) {
        $html = Renderer::fallback(404, '404', 'The page you are looking for does not exist.', $lang);
    } else {
        $html = Renderer::page($page, $lang);
    }
} catch (Throwable $ex) {
    error_log('[SpartanCMS] render failed for "' . ($page['slug'] ?? '?') . '" (' . $lang . '): '
        . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());

    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    $status = 500;
    cms_set_status($status);
    try {
        $html = Renderer::fallback(500, 'Error', CMS_DEBUG
            ? $ex->getMessage() . ' - ' . $ex->getFile() . ':' . $ex->getLine()
            : 'This page could not be rendered. The details are in the server error log.', $lang);
    } catch (Throwable $inner) {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex"><title>500</title></head>'
            . '<body><h1>500</h1><p>This page could not be rendered.</p></body></html>';
    }
}

http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: ' . (string)Settings::get('referrer_policy', 'strict-origin-when-cross-origin'));

$permissions = trim((string)Settings::get('permissions_policy', ''));
if ($permissions !== '') {
    header('Permissions-Policy: ' . $permissions);
}
if ($isPreview || !Settings::get('allow_indexing', true)) {
    header('X-Robots-Tag: noindex, nofollow');
}

// Conditional GET: cheap 304 for unchanged pages.
if ($status === 200 && !$isPreview) {
    $etag = '"' . md5($html) . '"';
    header('ETag: ' . $etag);
    if (CMS_HTML_CACHE_TTL > 0) {
        header('Cache-Control: public, max-age=' . CMS_HTML_CACHE_TTL);
    } else {
        header('Cache-Control: no-cache');
    }
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && str_contains($ifNoneMatch, $etag)) {
        http_response_code(304);
        ob_end_clean();
        exit;
    }
}

echo $html;
ob_end_flush();
