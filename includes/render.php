<?php
/**
 * SpartanCMS - Renderer: turns a page row into a complete HTML document.
 */

declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/seo.php';

final class Renderer
{
    /**
     * Full HTML document of a page.
     */
    public static function page(array $page, string $lang): string
    {
        cms_set_context($page, $lang);

        $language  = Languages::byCode($lang) ?? [];
        $direction = (string)($language['direction'] ?? 'ltr');
        $template  = Template::load();

        // ---- Collect the CSS that gets inlined in the head -------------------
        $css = [];
        $globalCss = (string)Settings::get('global_css', '');
        if (trim($globalCss) !== '') {
            $css[] = "/* global */\n" . $globalCss;
        }
        foreach ($template['layout'] as $slot) {
            $key = $slot['key'];
            if ($key === 'page') {
                continue;
            }
            $block = Blocks::get($key, $lang);
            if ($block !== null && trim((string)$block['css']) !== '') {
                $css[] = "/* {$key} */\n" . $block['css'];
            }
        }
        if (trim((string)($page['css'] ?? '')) !== '') {
            $css[] = "/* page */\n" . $page['css'];
        }

        // ---- Body: layout slots ------------------------------------------------
        $body = [];
        $js   = [];

        $globalJs = (string)Settings::get('global_js', '');
        if (trim($globalJs) !== '') {
            $js[] = $globalJs;
        }

        foreach ($template['layout'] as $slot) {
            $key   = $slot['key'];
            $tag   = trim((string)($slot['tag'] ?? ''));
            $attrs = trim((string)($slot['attrs'] ?? ''));

            if ($key === 'page') {
                $inner = cms_render_code((string)($page['html'] ?? ''));
                if (trim((string)($page['js'] ?? '')) !== '') {
                    $js[] = (string)$page['js'];
                }
            } else {
                $block = Blocks::get($key, $lang);
                if ($block === null) {
                    continue;
                }
                $inner = cms_render_code((string)$block['html']);
                if (trim((string)$block['js']) !== '') {
                    $js[] = (string)$block['js'];
                }
            }

            if (trim($inner) === '' && $key !== 'page') {
                continue;
            }

            $body[] = $tag !== ''
                ? '<' . $tag . ($attrs !== '' ? ' ' . $attrs : '') . '>' . $inner . '</' . $tag . '>'
                : $inner;
        }

        // ---- Head ----------------------------------------------------------------
        $head = Seo::head($page, $lang, implode("\n", $css));

        // ---- Assemble -------------------------------------------------------------
        $bodyClass = trim('lang-' . $lang . ' ' . (string)($page['body_class'] ?? ''));
        $skipLabel = (string)Settings::get('skip_link_label', 'Skip to main content');

        $html  = '<!doctype html>' . "\n";
        $html .= '<html lang="' . e((string)($language['hreflang'] ?: $lang)) . '"'
            . ($direction === 'rtl' ? ' dir="rtl"' : '') . '>' . "\n";
        $html .= '<head>' . "\n" . $head . "\n" . '</head>' . "\n";
        $html .= '<body class="' . e($bodyClass) . '">' . "\n";

        if ($skipLabel !== '') {
            $html .= '<a class="skip-link" href="#main">' . e($skipLabel) . '</a>' . "\n";
        }

        $html .= implode("\n", $body) . "\n";

        // Framework scripts, then site/page scripts, deferred to the end of body.
        $componentJs = Seo::componentAssets('js', 'body');
        if ($componentJs !== '') {
            $html .= $componentJs . "\n";
        }
        $inlineJs = trim(implode("\n;\n", array_filter($js, static fn($s) => trim($s) !== '')));
        if ($inlineJs !== '') {
            $html .= '<script>' . $inlineJs . '</script>' . "\n";
        }
        $bodyExtra = (string)Settings::get('body_extra', '');
        if (trim($bodyExtra) !== '') {
            $html .= cms_render_code($bodyExtra) . "\n";
        }

        $html .= '</body>' . "\n" . '</html>';

        return CMS_MINIFY_HTML ? cms_minify_html($html) : $html;
    }

    /**
     * Minimal, dependency-free document used when no page can be resolved.
     */
    public static function fallback(int $status, string $title, string $message, string $lang = 'en'): string
    {
        $home = Pages::home($lang) ?? Pages::home(Languages::defaultCode());
        $link = $home !== null ? cms_page_url($home) : CMS_BASE_PATH . '/';

        $html  = '<!doctype html>' . "\n";
        $html .= '<html lang="' . e($lang) . '">' . "\n<head>\n";
        $html .= '<meta charset="utf-8">' . "\n";
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        $html .= '<title>' . e($title) . '</title>' . "\n";
        $html .= '<meta name="robots" content="noindex, follow">' . "\n";
        $html .= '<style>body{font:16px/1.6 system-ui,sans-serif;margin:0;display:grid;place-items:center;'
            . 'min-height:100vh;color:#111;background:#fff}main{max-width:38rem;padding:2rem;text-align:center}'
            . 'h1{font-size:clamp(2rem,6vw,3rem);margin:0 0 .5rem}a{color:#06c}</style>' . "\n";
        $html .= "</head>\n<body>\n<main id=\"main\">\n";
        $html .= '<h1>' . e((string)$status) . '</h1>' . "\n";
        $html .= '<p>' . e($message) . '</p>' . "\n";
        $html .= '<p><a href="' . e($link) . '">&larr; ' . e((string)Settings::get('site_name', 'Home')) . '</a></p>' . "\n";
        $html .= "</main>\n</body>\n</html>";

        return $html;
    }
}
