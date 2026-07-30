<?php
/**
 * SpartanCMS - installer / demo content seeder.
 *
 * Creates the JSON tables in /data with a working bilingual demo site.
 * Run it once from the CLI (php install.php) or from the browser, then
 * DELETE this file (or keep it: it refuses to run when data already exists,
 * unless you pass --force).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/seo.php';

$cli   = PHP_SAPI === 'cli';
$force = $cli
    ? in_array('--force', $argv ?? [], true)
    : isset($_GET['force']);

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$existing = glob(CMS_DATA_DIR . '/*.json') ?: [];
if ($existing !== [] && !$force) {
    echo "Data already present in /data. Use --force (CLI) or ?force=1 to overwrite.\n";
    exit(1);
}

foreach ($existing as $file) {
    unlink($file);
}
Store::flush();

foreach ([CMS_DATA_DIR, CMS_MEDIA_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

/* -------------------------------------------------------------------------
 * Languages
 * ---------------------------------------------------------------------- */

Store::insert('languages', [
    'code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano',
    'locale' => 'it_IT', 'hreflang' => 'it', 'direction' => 'ltr',
    'is_default' => true, 'active' => true, 'sort' => 1,
]);
Store::insert('languages', [
    'code' => 'en', 'name' => 'English', 'native_name' => 'English',
    'locale' => 'en_US', 'hreflang' => 'en', 'direction' => 'ltr',
    'is_default' => false, 'active' => true, 'sort' => 2,
]);

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

Settings::save([
    'site_name'                 => 'SpartanCMS',
    'site_url'                  => '', // empty = derived from the request until you set it
    'title_suffix'              => '| SpartanCMS',
    'append_title_suffix'       => true,
    'default_description'       => 'A database-free, SEO first, blazing fast PHP CMS.',
    'author'                    => 'SpartanCMS',
    'hide_default_lang_prefix'  => true,
    'allow_indexing'            => true,
    'not_found_slug'            => '404',
    'theme_color'               => '#111111',
    'favicon'                   => '',
    'apple_touch_icon'          => '',
    'logo'                      => '',
    'default_og_image'          => '',
    'twitter_site'              => '',
    'google_site_verification'  => '',
    'referrer_policy'           => 'strict-origin-when-cross-origin',
    'permissions_policy'        => 'geolocation=(), microphone=(), camera=()',
    'preconnect_origins'        => '',
    'skip_link_label'           => 'Skip to main content',
    'schema_organization_enabled' => true,
    'schema_organization_type'  => 'Organization',
    'schema_website_enabled'    => true,
    'schema_breadcrumbs_enabled' => true,
    'organization_name'         => 'SpartanCMS',
    'organization_email'        => '',
    'organization_phone'        => '',
    'organization_street'       => '',
    'organization_zip'          => '',
    'organization_city'         => '',
    'organization_region'       => '',
    'organization_country'      => 'IT',
    'organization_vat'          => '',
    'social_profiles'           => '',
    'search_url_template'       => '',
    'robots_extra'              => '',
    'head_extra'                => '',
    'body_extra'                => '',
    'global_css'                => <<<'CSS'
/* ---- Design tokens -------------------------------------------------- */
:root{
  --ink:#12151a; --muted:#5b6472; --bg:#ffffff; --soft:#f4f6f9;
  --line:#e3e8ef; --accent:#1a56db; --radius:10px;
  --wrap:min(72rem, 100% - 2.5rem);
  --font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
}
@media (prefers-color-scheme: dark){
  :root{ --ink:#e8ecf2; --muted:#9aa5b5; --bg:#0f1216; --soft:#171b22; --line:#242b35; --accent:#7aa2ff; }
}

/* ---- Reset ------------------------------------------------------------ */
*,*::before,*::after{ box-sizing:border-box; }
html{ -webkit-text-size-adjust:100%; scroll-behavior:smooth; }
body{ margin:0; font-family:var(--font); font-size:1.05rem; line-height:1.65;
      color:var(--ink); background:var(--bg); text-rendering:optimizeLegibility; }
img,svg,video{ max-width:100%; height:auto; display:block; }
a{ color:var(--accent); text-underline-offset:.15em; }
h1,h2,h3{ line-height:1.15; letter-spacing:-.02em; margin:0 0 .5em; text-wrap:balance; }
h1{ font-size:clamp(2rem,5vw,3.25rem); }
h2{ font-size:clamp(1.4rem,3vw,2rem); }
p{ margin:0 0 1em; max-width:68ch; }

/* ---- Layout ----------------------------------------------------------- */
.wrap{ width:var(--wrap); margin-inline:auto; }
.section{ padding:clamp(2.5rem,6vw,5rem) 0; }
.grid{ display:grid; gap:1.5rem; grid-template-columns:repeat(auto-fit,minmax(16rem,1fr)); }
.card{ background:var(--soft); border:1px solid var(--line); border-radius:var(--radius); padding:1.5rem; }
.lead{ font-size:1.2rem; color:var(--muted); }

/* ---- Header / footer -------------------------------------------------- */
#site-header{ border-bottom:1px solid var(--line); position:sticky; top:0; z-index:10;
              background:color-mix(in srgb, var(--bg) 88%, transparent); backdrop-filter:blur(8px); }
.bar{ display:flex; align-items:center; justify-content:space-between; gap:1.5rem;
      padding:.9rem 0; flex-wrap:wrap; }
.brand{ font-weight:700; font-size:1.15rem; color:var(--ink); text-decoration:none; letter-spacing:-.02em; }
nav ul{ display:flex; gap:1.25rem; list-style:none; margin:0; padding:0; flex-wrap:wrap; }
nav a{ color:var(--ink); text-decoration:none; font-size:.97rem; }
nav a:hover, nav a[aria-current]{ color:var(--accent); text-decoration:underline; }
.lang-switcher ul{ gap:.6rem; }
.lang-switcher a[aria-current]{ font-weight:700; }
#site-footer{ border-top:1px solid var(--line); color:var(--muted); font-size:.92rem;
              padding:2rem 0; margin-top:3rem; }

/* ---- Buttons ---------------------------------------------------------- */
.btn{ display:inline-block; padding:.7rem 1.4rem; border-radius:var(--radius);
      background:var(--accent); color:#fff; text-decoration:none; font-weight:600; }
.btn:hover{ filter:brightness(1.08); }

/* ---- Accessibility ---------------------------------------------------- */
.skip-link{ position:absolute; left:-9999px; top:0; background:var(--ink); color:var(--bg);
            padding:.6rem 1rem; z-index:99; }
.skip-link:focus{ left:0; }
:focus-visible{ outline:3px solid var(--accent); outline-offset:2px; }
@media (prefers-reduced-motion: reduce){ html{ scroll-behavior:auto; } }
CSS,
    'global_js' => <<<'JS'
/* Global site scripts. Kept tiny on purpose: this is inlined on every page. */
document.documentElement.classList.add('js');
JS,
]);

/* -------------------------------------------------------------------------
 * Header / footer blocks
 * ---------------------------------------------------------------------- */

$headerIt = <<<'HTML'
<div class="wrap bar">
  <a class="brand" href="<?= e(cms_page_url(Pages::home('it'))) ?>">
    <?= e(cms_setting('site_name')) ?>
  </a>
  <?= cms_menu_html('main', 'it', ['aria_label' => 'Menu principale']) ?>
  <?= cms_language_switcher(['aria_label' => 'Lingua']) ?>
</div>
HTML;

$headerEn = <<<'HTML'
<div class="wrap bar">
  <a class="brand" href="<?= e(cms_page_url(Pages::home('en'))) ?>">
    <?= e(cms_setting('site_name')) ?>
  </a>
  <?= cms_menu_html('main', 'en', ['aria_label' => 'Main menu']) ?>
  <?= cms_language_switcher(['aria_label' => 'Language']) ?>
</div>
HTML;

$footerIt = <<<'HTML'
<div class="wrap">
  <p>&copy; <?= date('Y') ?> <?= e(cms_setting('site_name')) ?> &middot; Realizzato con SpartanCMS</p>
</div>
HTML;

$footerEn = <<<'HTML'
<div class="wrap">
  <p>&copy; <?= date('Y') ?> <?= e(cms_setting('site_name')) ?> &middot; Built with SpartanCMS</p>
</div>
HTML;

Blocks::put('header', 'it', ['html' => $headerIt, 'css' => '', 'js' => '']);
Blocks::put('header', 'en', ['html' => $headerEn, 'css' => '', 'js' => '']);
Blocks::put('footer', 'it', ['html' => $footerIt, 'css' => '', 'js' => '']);
Blocks::put('footer', 'en', ['html' => $footerEn, 'css' => '', 'js' => '']);

/* -------------------------------------------------------------------------
 * Pages
 * ---------------------------------------------------------------------- */

/** Defaults shared by every page. */
function seed_page(array $data): array
{
    return Store::insert('pages', array_merge([
        'name'               => '',
        'slug'               => '',
        'lang'               => 'it',
        'is_default'         => false,
        'status'             => 'published',
        'translation_group'  => '',
        'html'               => '',
        'css'                => '',
        'js'                 => '',
        'schema'             => '',
        'schema_page_type'   => 'WebPage',
        'meta_title'         => '',
        'meta_description'   => '',
        'meta_keywords'      => '',
        'canonical'          => '',
        'robots_index'       => true,
        'robots_follow'      => true,
        'robots_extra'       => '',
        'og_title'           => '',
        'og_description'     => '',
        'og_image'           => '',
        'og_type'            => '',
        'twitter_card'       => '',
        'head_extra'         => '',
        'body_class'         => '',
        'sitemap_include'    => true,
        'sitemap_priority'   => '0.5',
        'sitemap_changefreq' => 'monthly',
    ], $data));
}

$homeIt = <<<'HTML'
<section class="section wrap">
  <h1>Un CMS spartano, veloce, tutto SEO</h1>
  <p class="lead">Niente database, niente overhead: pagine in PHP+HTML, CSS e JS
     scritti da te, servite con markup pulito e Schema.org completo.</p>
  <p><a class="btn" href="<?= e(cms_page_url(Pages::bySlug('it', 'chi-siamo'))) ?>">Scopri di piu</a></p>
</section>

<section class="section wrap">
  <h2>Perche funziona</h2>
  <div class="grid">
    <article class="card">
      <h3>Zero database</h3>
      <p>Tabelle JSON con id e integrita referenziale. Backup = copia di una cartella.</p>
    </article>
    <article class="card">
      <h3>SEO nativa</h3>
      <p>Canonical, hreflang, Open Graph, JSON-LD e sitemap generati dal sistema.</p>
    </article>
    <article class="card">
      <h3>Liberta totale</h3>
      <p>Ogni pagina ha il suo HTML+PHP, il suo CSS e il suo JS. Nessun tema da combattere.</p>
    </article>
  </div>
</section>
HTML;

$homeEn = <<<'HTML'
<section class="section wrap">
  <h1>A spartan, fast, SEO-first CMS</h1>
  <p class="lead">No database, no overhead: PHP+HTML pages, your own CSS and JS,
     served with clean markup and a complete Schema.org graph.</p>
  <p><a class="btn" href="<?= e(cms_page_url(Pages::bySlug('en', 'about-us'))) ?>">Learn more</a></p>
</section>

<section class="section wrap">
  <h2>Why it works</h2>
  <div class="grid">
    <article class="card">
      <h3>No database</h3>
      <p>JSON tables with ids and referential integrity. Backup = copy one folder.</p>
    </article>
    <article class="card">
      <h3>SEO built in</h3>
      <p>Canonical, hreflang, Open Graph, JSON-LD and sitemap generated by the system.</p>
    </article>
    <article class="card">
      <h3>Total freedom</h3>
      <p>Every page owns its HTML+PHP, CSS and JS. No theme to fight against.</p>
    </article>
  </div>
</section>
HTML;

$aboutIt = <<<'HTML'
<article class="section wrap">
  <h1>Chi siamo</h1>
  <p class="lead">Questa pagina dimostra come ogni contenuto sia codice tuo al 100%.</p>
  <p>Puoi usare PHP direttamente nel markup: oggi &egrave;
     <time datetime="<?= date('Y-m-d') ?>"><?= date('d/m/Y') ?></time>.</p>
  <h2>Helper disponibili</h2>
  <ul>
    <li><code>cms_page_url($page|$id)</code> - URL SEO friendly di una pagina</li>
    <li><code>cms_menu_html('main')</code> - menu accessibile gia pronto</li>
    <li><code>cms_media('foto.webp')</code> - file della cartella media</li>
    <li><code>cms_setting('site_name')</code> - impostazioni globali</li>
    <li><code>e($string)</code> - escape HTML</li>
  </ul>
</article>
HTML;

$aboutEn = <<<'HTML'
<article class="section wrap">
  <h1>About us</h1>
  <p class="lead">This page shows how every piece of content is 100% your own code.</p>
  <p>You can use PHP straight in the markup: today is
     <time datetime="<?= date('Y-m-d') ?>"><?= date('F j, Y') ?></time>.</p>
  <h2>Available helpers</h2>
  <ul>
    <li><code>cms_page_url($page|$id)</code> - SEO friendly URL of a page</li>
    <li><code>cms_menu_html('main')</code> - ready-made accessible menu</li>
    <li><code>cms_media('photo.webp')</code> - file from the media folder</li>
    <li><code>cms_setting('site_name')</code> - global settings</li>
    <li><code>e($string)</code> - HTML escaping</li>
  </ul>
</article>
HTML;

$notFound = <<<'HTML'
<section class="section wrap" style="text-align:center">
  <h1>404</h1>
  <p class="lead"><?= cms_current_lang() === 'it'
      ? 'La pagina che cerchi non esiste.'
      : 'The page you are looking for does not exist.' ?></p>
  <p><a class="btn" href="<?= e(cms_page_url(Pages::home(cms_current_lang()))) ?>">
     <?= cms_current_lang() === 'it' ? 'Torna alla home' : 'Back to home' ?></a></p>
</section>
HTML;

/*
 * Page specific JSON-LD: the system already emits WebSite, Organization,
 * WebPage and BreadcrumbList, so here we only add what is unique to this page.
 */
$aboutSchema = <<<'JSON'
{
  "@type": "SoftwareApplication",
  "name": "SpartanCMS",
  "applicationCategory": "DeveloperApplication",
  "operatingSystem": "PHP 8.1+",
  "offers": { "@type": "Offer", "price": "0", "priceCurrency": "EUR" }
}
JSON;

$pHomeIt = seed_page([
    'name' => 'Home', 'slug' => 'home', 'lang' => 'it', 'is_default' => true,
    'translation_group' => 'home', 'html' => $homeIt,
    'meta_title' => 'SpartanCMS - CMS PHP senza database, SEO e velocita',
    'meta_description' => 'CMS in PHP, JS, CSS e JSON senza database: massima velocita, SEO completa e liberta totale per lo sviluppatore.',
    'og_type' => 'website', 'sitemap_priority' => '1.0', 'sitemap_changefreq' => 'weekly',
]);
$pHomeEn = seed_page([
    'name' => 'Home', 'slug' => 'home', 'lang' => 'en', 'is_default' => true,
    'translation_group' => 'home', 'html' => $homeEn,
    'meta_title' => 'SpartanCMS - database-free PHP CMS built for SEO and speed',
    'meta_description' => 'A PHP, JS, CSS and JSON CMS with no database: maximum speed, complete SEO and total freedom for the developer.',
    'og_type' => 'website', 'sitemap_priority' => '1.0', 'sitemap_changefreq' => 'weekly',
]);
$pAboutIt = seed_page([
    'name' => 'Chi siamo', 'slug' => 'chi-siamo', 'lang' => 'it',
    'translation_group' => 'about', 'html' => $aboutIt, 'schema' => $aboutSchema,
    'schema_page_type' => 'AboutPage',
    'meta_title' => 'Chi siamo',
    'meta_description' => 'Come funziona SpartanCMS: pagine in PHP e HTML, CSS e JS dedicati, SEO tecnica inclusa.',
    'sitemap_priority' => '0.8',
]);
$pAboutEn = seed_page([
    'name' => 'About us', 'slug' => 'about-us', 'lang' => 'en',
    'translation_group' => 'about', 'html' => $aboutEn,
    'schema_page_type' => 'AboutPage',
    'meta_title' => 'About us',
    'meta_description' => 'How SpartanCMS works: PHP and HTML pages, dedicated CSS and JS, technical SEO included.',
    'sitemap_priority' => '0.8',
]);
seed_page([
    'name' => 'Pagina non trovata', 'slug' => '404', 'lang' => 'it',
    'translation_group' => '', 'html' => $notFound,
    'meta_title' => 'Pagina non trovata', 'robots_index' => false,
    'sitemap_include' => false,
]);
seed_page([
    'name' => 'Page not found', 'slug' => '404', 'lang' => 'en',
    'translation_group' => '', 'html' => $notFound,
    'meta_title' => 'Page not found', 'robots_index' => false,
    'sitemap_include' => false,
]);

/* -------------------------------------------------------------------------
 * Menu
 * ---------------------------------------------------------------------- */

Store::insert('menu', ['menu' => 'main', 'lang' => 'it', 'label' => 'Home',
    'page_id' => (int)$pHomeIt['id'], 'url' => '', 'parent_id' => 0, 'sort' => 1,
    'target' => '', 'rel' => '', 'active' => true]);
Store::insert('menu', ['menu' => 'main', 'lang' => 'it', 'label' => 'Chi siamo',
    'page_id' => (int)$pAboutIt['id'], 'url' => '', 'parent_id' => 0, 'sort' => 2,
    'target' => '', 'rel' => '', 'active' => true]);
Store::insert('menu', ['menu' => 'main', 'lang' => 'en', 'label' => 'Home',
    'page_id' => (int)$pHomeEn['id'], 'url' => '', 'parent_id' => 0, 'sort' => 1,
    'target' => '', 'rel' => '', 'active' => true]);
Store::insert('menu', ['menu' => 'main', 'lang' => 'en', 'label' => 'About us',
    'page_id' => (int)$pAboutEn['id'], 'url' => '', 'parent_id' => 0, 'sort' => 2,
    'target' => '', 'rel' => '', 'active' => true]);

/* -------------------------------------------------------------------------
 * Media table + generated files
 * ---------------------------------------------------------------------- */

Store::load('media');
Store::replaceAll('media', []);

/*
 * sitemap.xml and robots.txt carry absolute URLs, so they are only written
 * once the real Site URL is known. Set it in Settings, then press Generate.
 */
$count = trim((string)Settings::get('site_url', '')) !== '' ? Sitemap::generate() : -1;
if ($count >= 0) {
    Sitemap::generateRobots();
}

echo "SpartanCMS installed.\n";
echo "  languages : " . count(Store::all('languages')) . "\n";
echo "  pages     : " . count(Store::all('pages')) . "\n";
echo "  menu items: " . count(Store::all('menu')) . "\n";
echo "  blocks    : " . count(Store::all('blocks')) . "\n";
echo "  sitemap   : " . ($count >= 0
    ? "{$count} URLs\n"
    : "skipped (set Site URL in Settings, then press Generate)\n");
echo "\nOpen backend.php and log in with the password set in config.php (default: admin).\n";
echo "Delete install.php when you are done.\n";
