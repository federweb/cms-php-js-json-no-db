<?php
/**
 * SpartanCMS - System configuration.
 *
 * Everything that is not editable from the backend lives here.
 * Keep this file out of the web root backups and never expose it publicly.
 *
 * PHP >= 8.1
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------
 * 1. BACKEND ACCESS
 * ---------------------------------------------------------------------- */

/**
 * Backend password, hardcoded on purpose (no user table, no database).
 *
 * Store a password_hash() string (recommended, starts with "$2y$" / "$argon").
 * A plain string is also accepted for quick local setups.
 *
 * Generate a new hash with:
 *   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
 *
 * Default password below is: admin
 */
const CMS_ADMIN_PASSWORD = '$2y$10$Io.s7YRvUmk4yo9Xzm2wV.FG8vhtR4fu3G/smjH7qCjWnLIMIc88G';

/** Backend session name and idle timeout (seconds). */
const CMS_SESSION_NAME    = 'FederCMS';
const CMS_SESSION_TIMEOUT = 7200;

/** Brute force protection: max failed logins per window, window in seconds. */
const CMS_LOGIN_MAX_ATTEMPTS = 8;
const CMS_LOGIN_WINDOW       = 900;

/* -------------------------------------------------------------------------
 * 2. PATHS
 * ---------------------------------------------------------------------- */

const CMS_ROOT     = __DIR__;
const CMS_DATA_DIR = __DIR__ . '/data';
const CMS_MEDIA_DIR = __DIR__ . '/media';

/**
 * Public base path of the installation.
 *
 * null   = detect it automatically from the running script, so the CMS works
 *          at the domain root and in any subfolder (/CMS, /sites/foo, ...)
 *          with no configuration at all. This is the recommended value.
 * '/sub' = force it, when the rewrite goes through a front controller that
 *          lives outside this folder.
 * ''     = force the domain root.
 */
const CMS_BASE_PATH_OVERRIDE = null;

define('CMS_BASE_PATH', CMS_BASE_PATH_OVERRIDE ?? (static function (): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        return '';
    }
    $dir = str_replace('\\', '/', dirname($script));
    return ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
})());

/** Public URL of the media folder, relative to the base path. */
const CMS_MEDIA_URL = '/media';

/* -------------------------------------------------------------------------
 * 3. SYSTEM-WIDE HEAD COMPONENTS
 *
 * Front-end frameworks embedded on every page of the site.
 * Enable only what you really use: each entry costs requests and bytes.
 *
 * Structure:
 *   enabled     bool    include it or not
 *   preconnect  string  origin to preconnect/dns-prefetch (optional)
 *   css         array   [href, integrity(optional)]
 *   js          array   [src, integrity(optional), position: head|body, defer: bool]
 * ---------------------------------------------------------------------- */

const CMS_HEAD_COMPONENTS = [

    'bootstrap' => [
        'enabled'    => false,
        'label'      => 'Bootstrap 5.3',
        'preconnect' => 'https://cdn.jsdelivr.net',
        'css' => [
            [
                'href'      => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
                'integrity' => 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
            ],
        ],
        'js' => [
            [
                'src'       => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
                'integrity' => 'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
                'position'  => 'body',
                'defer'     => true,
            ],
        ],
    ],

    'uikit' => [
        'enabled'    => false,
        'label'      => 'UIkit 3',
        'preconnect' => 'https://cdn.jsdelivr.net',
        'css' => [
            ['href' => 'https://cdn.jsdelivr.net/npm/uikit@3.21.7/dist/css/uikit.min.css'],
        ],
        'js' => [
            ['src' => 'https://cdn.jsdelivr.net/npm/uikit@3.21.7/dist/js/uikit.min.js', 'position' => 'body', 'defer' => true],
            ['src' => 'https://cdn.jsdelivr.net/npm/uikit@3.21.7/dist/js/uikit-icons.min.js', 'position' => 'body', 'defer' => true],
        ],
    ],

    'tailwind_play' => [
        'enabled'    => false,
        'label'      => 'Tailwind (CDN, prototyping only)',
        'preconnect' => 'https://cdn.tailwindcss.com',
        'css' => [],
        'js'  => [
            ['src' => 'https://cdn.tailwindcss.com', 'position' => 'head', 'defer' => false],
        ],
    ],

    'alpine' => [
        'enabled'    => false,
        'label'      => 'Alpine.js 3',
        'preconnect' => 'https://cdn.jsdelivr.net',
        'css' => [],
        'js'  => [
            ['src' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js', 'position' => 'head', 'defer' => true],
        ],
    ],
];

/* -------------------------------------------------------------------------
 * 4. OUTPUT
 * ---------------------------------------------------------------------- */

/** Collapse whitespace in the generated HTML (safe: skips pre/textarea/script/style). */
const CMS_MINIFY_HTML = true;

/** Send gzip/deflate compressed output when the client supports it. */
const CMS_GZIP = true;

/** Browser cache lifetime for HTML pages, in seconds. 0 disables caching headers. */
const CMS_HTML_CACHE_TTL = 0;

/** Show PHP errors of page code inside the page (development) or log them silently. */
const CMS_DEBUG = false;

/** Allowed upload extensions and max upload size in bytes. */
const CMS_UPLOAD_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt',
    'zip', 'mp4', 'webm', 'mp3', 'woff', 'woff2',
];
const CMS_UPLOAD_MAX_SIZE = 20971520; // 20 MB

/** Ace editor (backend code editing). */
const CMS_ACE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/ace/1.43.3/ace.js';
