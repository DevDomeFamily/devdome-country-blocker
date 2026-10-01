<?php
/**
 * DevDome Tools — the Suite Hub (v1).
 *
 * The always-present "DevDome Tools → Overview" screen: a registry-driven list of every
 * DevDome plugin (installed + available), live status tiles, an aggregated health band,
 * and the seams for reporting + a Site-Monitor host-takeover. It lives in
 * devdome-core so it loads ONCE (highest version across the suite) and works with any
 * subset of plugins — including just one.
 *
 * HARD RULE: render is READ-ONLY. Provider callbacks (`tiles`/`health`) read a small
 * pre-computed option/transient blob ONLY — never a DB query or HTTP call on render.
 * No cron, no data collection here; heavy providers (Site Monitor) compute on their own
 * cron and write `<prefix>_hub_summary` (e.g. devdsame_hub_summary) (autoload off).
 */

defined('ABSPATH') || exit;

if (!defined('DEVDCOREV1_TOOLS_MENU_SLUG')) {
    define('DEVDCOREV1_TOOLS_MENU_SLUG', 'devdcorev1-tools');
}

/* ------------------------------------------------------------------ catalog */

/**
 * The ONE hardcoded list — marketing data for plugins that may NOT be installed.
 * Installed plugins self-register (§ registry) and their live descriptor always wins;
 * the catalog only ever fills gaps + powers the "available to install" upsell cards.
 */
function devdcorev1_hub_catalog()
{
    // wporg_slug: set ONLY for plugins published on WordPress.org (enables one-click Install).
    // listed: public-catalog flag — an entry only renders as an "available" promo tile when true
    // (installed plugins always show). Flip to true when a plugin is released; never delete entries.
    // Empty => the card shows "Get it →" (link out to devdome.com) only. plugin_file: folder/main.php.
    // Canonical suite order (position): insight -> protection -> money/links -> cleanup -> backup.
    $list = array(
        'devdome-analytics'        => array('name' => 'Analytics',        'icon' => 'dashicons-chart-area',  'desc' => 'Real traffic without bot noise: visitors, AI referrals &amp; outbound clicks, bots reported separately.',            'get_url' => 'https://devdome.com/wp-plugins/analytics/',        'position' => 10,  'wporg_slug' => 'devdome-analytics', 'listed' => true, 'plugin_file' => 'devdome-analytics/devdome-analytics.php'),
        'devdome-site-monitor'     => array('name' => 'Site Monitor',     'icon' => 'dashicons-heart',       'desc' => 'Uptime, speed, security, SEO &amp; affiliate health in one dashboard.',     'get_url' => 'https://devdome.com/wp-plugins/',     'position' => 20,  'wporg_slug' => '', 'listed' => false, 'plugin_file' => 'devdome-site-monitor/devdome-site-monitor.php'),
        'devdome-bot-protection'   => array('name' => 'Bot Protection',   'icon' => 'dashicons-shield',      'desc' => 'Block fake clicks &amp; bot traffic so your stats stay real.',               'get_url' => 'https://devdome.com/wp-plugins/',   'position' => 30,  'wporg_slug' => '', 'listed' => false, 'plugin_file' => 'devdome-bot-protection/devdome-bot-protection.php'),
        'devdome-security-scanner' => array('name' => 'Security Scanner', 'icon' => 'dashicons-shield-alt',  'desc' => 'Scan for malware, vulnerabilities &amp; hardening gaps.',                    'get_url' => 'https://devdome.com/wp-plugins/', 'position' => 40,  'wporg_slug' => '', 'listed' => false, 'plugin_file' => 'devdome-security-scanner/devdome-security-scanner.php'),
        'devdome-redirect-manager' => array('name' => 'Redirect Manager', 'icon' => 'dashicons-randomize',   'desc' => 'Rule-based redirects &amp; smart affiliate links with per-rule stats.',              'get_url' => 'https://devdome.com/wp-plugins/redirect-manager/', 'position' => 50,  'wporg_slug' => 'devdome-redirect-manager', 'listed' => true, 'plugin_file' => 'devdome-redirect-manager/devdome-redirect-manager.php'),
        'devdome-affiliate-manager'=> array('name' => 'Affiliate Manager','icon' => 'dashicons-admin-links', 'desc' => 'Sitewide affiliate tags, geo-routing, link scanning &amp; click stats.',      'get_url' => 'https://devdome.com/wp-plugins/affiliate-manager/','position' => 60,  'wporg_slug' => 'devdome-affiliate-manager', 'listed' => true, 'plugin_file' => 'devdome-affiliate-manager/devdome-affiliate-manager.php'),
        'devdome-product-importer' => array('name' => 'Product Importer', 'icon' => 'dashicons-cart',        'desc' => 'Import Amazon products into WooCommerce with AI content &amp; transit links.', 'get_url' => 'https://devdome.com/wp-plugins/', 'position' => 70,  'wporg_slug' => '', 'listed' => false, 'plugin_file' => 'devdome-product-importer/devdome-product-importer.php'),
        'devdome-safe-media-cleaner'    => array('name' => 'Safe Media Cleaner', 'icon' => 'dashicons-format-image','desc' => 'Find unused images, orphaned files &amp; duplicates. Recycle Bin restore.',                      'get_url' => 'https://devdome.com/wp-plugins/safe-media-cleaner/',    'position' => 80,  'wporg_slug' => 'devdome-safe-media-cleaner', 'listed' => true, 'plugin_file' => 'devdome-safe-media-cleaner/devdome-safe-media-cleaner.php'),
        'devdome-link-monitor'     => array('name' => 'Link Monitor',     'icon' => 'dashicons-editor-unlink', 'desc' => 'Find broken links and monitor 404s, with a conservative checker that says unverified instead of guessing.', 'get_url' => 'https://devdome.com/wp-plugins/link-monitor/', 'position' => 85,  'wporg_slug' => 'devdome-link-monitor', 'listed' => true, 'plugin_file' => 'devdome-link-monitor/devdome-link-monitor.php'),
        'devdome-malware-scanner'  => array('name' => 'Malware Scanner',  'icon' => 'dashicons-shield-alt',  'desc' => 'Malware and backdoor scanner: file integrity, database injections, rogue admins, cron persistence, quarantine and one-click repair.', 'get_url' => 'https://devdome.com/wp-plugins/', 'position' => 45,  'wporg_slug' => '', 'listed' => false, 'plugin_file' => 'devdome-malware-scanner/devdome-malware-scanner.php'),
        'devdome-admin-cleaner'    => array('name' => 'Admin Cleaner',    'icon' => 'dashicons-hidden',      'desc' => 'Hide admin nags, declutter the dashboard &amp; create safer client screens.', 'get_url' => 'https://devdome.com/wp-plugins/',    'position' => 90,  'wporg_slug' => '', 'listed' => false, 'plugin_file' => 'devdome-admin-cleaner/devdome-admin-cleaner.php'),
        'devdome-backup-migration' => array('name' => 'Backup &amp; Recovery', 'icon' => 'dashicons-backup', 'desc' => 'Reversible updates: a restore point, verify &amp; auto-rollback on every update.', 'get_url' => 'https://devdome.com/wp-plugins/', 'position' => 100, 'wporg_slug' => '', 'listed' => false, 'plugin_file' => 'devdome-backup-migration/devdome-backup-migration.php'),
    );
    // The live list comes from devdome.com (names, descriptions, links, logos, versions) so an
    // old bundled core still shows the current plugin lineup. The list above is the offline fallback
    // AND the only source of installer identity: wporg_slug / plugin_file are compiled in here and
    // are never taken from the network (the sanitizer drops them), so a wrong or tampered catalog can
    // only change what a card says, never which WordPress.org plugin an Install click fetches.
    foreach (devdcorev1_hub_remote_catalog() as $slug => $row) {
        unset($row['wporg_slug'], $row['plugin_file']); // also covers a catalog cached by an older core
        $list[$slug] = isset($list[$slug]) && is_array($list[$slug]) ? array_merge($list[$slug], $row) : $row;
    }
    return apply_filters('devdcorev1_hub_catalog', $list);
}

/**
 * True once this site is connected to a DevDome account (the stored connection state, no remote
 * call). The catalog fetch is opt-in by connection; disconnected sites never contact devdome.com.
 */
function devdcorev1_hub_catalog_consented()
{
    if ('' === (string) get_option('devdcorev1_site_token', '')) {
        return false;
    }
    $state = get_option('devdcorev1_conn_state', array());
    return is_array($state) && !empty($state['ok']);
}

/** Where the live catalog lives. A plain JSON file, fetched at most once every 12 hours. */
function devdcorev1_hub_catalog_url()
{
    return 'https://devdome.com/wp-plugins/catalog.json';
}

/**
 * Remote catalog rows keyed by slug (sanitized), or an empty array. Cached in a site transient for
 * 12 hours; a failed fetch is cached for 1 hour so a slow or unreachable devdome.com never delays
 * every admin page load. Only the core version is sent (custom user agent), no site data.
 */
function devdcorev1_hub_remote_catalog()
{
    static $memo = null;
    if (is_array($memo)) {
        return $memo;
    }
    // Consent gate (1.6.5): devdome.com is contacted for the catalog only once the site owner has
    // connected a DevDome account. Before that the bundled list above is all the hub shows.
    if (!devdcorev1_hub_catalog_consented()) {
        return $memo = array();
    }
    $cached = get_site_transient('devdcorev1_hub_catalog_remote');
    if (is_array($cached)) {
        return $memo = $cached;
    }
    if (!empty($GLOBALS['devdcorev1_catalog_cached_only'])) {
        // a read-only agent ability (get-connection) never triggers the fetch or its cache write (Codex high round 13)
        return array();
    }
    $rows = array();
    $res = wp_remote_get(devdcorev1_hub_catalog_url(), array(
        'timeout'    => 4,
        'user-agent' => 'DevDome-Core/' . (defined('DEVDCOREV1_VERSION') ? DEVDCOREV1_VERSION : '0'),
    ));
    if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (is_array($data) && isset($data['plugins']) && is_array($data['plugins'])) {
            foreach ($data['plugins'] as $slug => $row) {
                $slug = (string) $slug;
                if (!preg_match('/^devdome-[a-z0-9-]{2,40}$/', $slug) || !is_array($row)) {
                    continue;
                }
                $rows[$slug] = devdcorev1_hub_sanitize_catalog_row($row);
            }
        }
    }
    set_site_transient('devdcorev1_hub_catalog_remote', $rows, $rows ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    return $memo = $rows;
}

/** Allow-list every field of a remote catalog row; anything unexpected is dropped. */
function devdcorev1_hub_sanitize_catalog_row($row)
{
    $out = array();
    if (isset($row['name']))     { $out['name'] = sanitize_text_field(substr((string) $row['name'], 0, 60)); }
    if (isset($row['desc']))     { $out['desc'] = sanitize_text_field(substr((string) $row['desc'], 0, 200)); } // stored plain, escaped once at render (DeepSeek core round 1: '&amp;' showed literally)
    if (isset($row['position'])) { $out['position'] = (int) $row['position']; }
    if (isset($row['version']) && preg_match('/^[0-9][0-9.]{0,11}$/', (string) $row['version'])) { $out['version'] = (string) $row['version']; }
    if (isset($row['listed']))   { $out['listed'] = !empty($row['listed']); }
    if (isset($row['get_url'])) {
        $u = esc_url_raw((string) $row['get_url']);
        if (strpos($u, 'https://devdome.com/') === 0 || strpos($u, 'https://wordpress.org/plugins/') === 0) {
            $out['get_url'] = $u;
        }
    }
    // wporg_slug / plugin_file are deliberately NOT accepted from the remote catalog (1.6.4): the
    // installer identity stays compiled into devdcorev1_hub_catalog().
    if (isset($row['logo'])) {
        $attrs = array('d' => true, 'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true, 'x' => true, 'y' => true,
            'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'width' => true, 'height' => true, 'points' => true,
            'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true);
        $logo = wp_kses(substr((string) $row['logo'], 0, 2000), array(
            'path' => $attrs, 'circle' => $attrs, 'rect' => $attrs, 'line' => $attrs, 'polyline' => $attrs, 'polygon' => $attrs, 'ellipse' => $attrs,
        ));
        if (trim($logo) !== '') {
            $out['logo'] = $logo;
        }
    }
    return $out;
}

/** Health-score weight per slug — HUB-owned (a provider can't vote up its own importance). */
function devdcorev1_hub_weights()
{
    return array(
        'devdome-site-monitor' => 40,
        'devdome-bot-protection' => 20,
        'devdome-analytics' => 10,
        'devdome-redirect-manager' => 10,
        'devdome-affiliate-manager' => 10,
        'devdome-product-importer' => 10,
    );
}

/* ----------------------------------------------------------------- registry */

/**
 * The merged, normalized, sorted plugin list driving the Overview. Live descriptors
 * (`devdcorev1_suite_register` filter) win per key; the catalog fills gaps and adds the
 * not-installed entries. Cap-gated, position-then-name sorted, resolved tiles attached.
 */
function devdcorev1_hub_registry()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $live = apply_filters('devdcorev1_suite_register', array());
    if (!is_array($live)) {
        $live = array();
    }
    $catalog = devdcorev1_hub_catalog();
    $remote_cat = devdcorev1_hub_remote_catalog();

    $slugs = array_unique(array_merge(array_keys($catalog), array_keys($live)));
    $rows = array();
    foreach ($slugs as $slug) {
        $cat  = (isset($catalog[$slug]) && is_array($catalog[$slug])) ? $catalog[$slug] : array();
        $desc = (isset($live[$slug]) && is_array($live[$slug])) ? $live[$slug] : null;
        $installed = ($desc !== null);
        $d = $installed ? array_merge($cat, $desc) : $cat; // live wins per key, catalog fills gaps

        // Cap-gate: hide a card the current user can't open.
        $cap = isset($d['cap']) ? (string) $d['cap'] : 'manage_options';
        if ($installed && !current_user_can($cap)) {
            continue;
        }

        $rows[] = array(
            'slug'      => (string) $slug,
            'name'      => isset($d['name']) ? (string) $d['name'] : (string) $slug,
            // The live listing copy (remote catalog) wins over what the plugin registered about itself.
            'desc'      => (isset($remote_cat[$slug]['desc']) && $remote_cat[$slug]['desc'] !== '') ? (string) $remote_cat[$slug]['desc'] : (isset($d['desc']) ? (string) $d['desc'] : ''),
            'icon'      => isset($d['icon']) ? (string) $d['icon'] : 'dashicons-screenoptions',
            'position'  => isset($d['position']) ? (int) $d['position'] : 500, // never 0 (would jump above Overview)
            'installed' => $installed,
            'version'   => isset($d['version']) ? (string) $d['version'] : '',
            'page'      => isset($d['page']) ? (string) $d['page'] : '',
            'get_url'   => isset($d['get_url']) ? (string) $d['get_url'] : 'https://devdome.com/',
            'tiles'     => $installed ? devdcorev1_hub_resolve_tiles($d) : array(),
            'listed'    => !empty($d['listed']),
            'plugin_file' => isset($d['plugin_file']) ? (string) $d['plugin_file'] : '', // folder/main.php — powers the per-card Disable link
        );
    }

    usort($rows, function ($a, $b) {
        if ($a['position'] !== $b['position']) {
            return $a['position'] - $b['position'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $cache = $rows;
    return $cache;
}

/** Resolve a descriptor's `tiles` callback safely (read-only; a throw degrades to no tiles). */
function devdcorev1_hub_resolve_tiles($d)
{
    if (empty($d['tiles']) || !is_callable($d['tiles'])) {
        return array();
    }
    try {
        $tiles = call_user_func($d['tiles']);
    } catch (\Throwable $e) {
        return array();
    }
    if (!is_array($tiles)) {
        return array();
    }
    $out = array();
    foreach ($tiles as $t) {
        if (!is_array($t)) {
            continue;
        }
        $out[] = array(
            'label' => isset($t['label']) ? (string) $t['label'] : '',
            'value' => array_key_exists('value', $t) ? $t['value'] : null, // null => grey "not connected"
            'unit'  => isset($t['unit']) ? (string) $t['unit'] : '',
            'fmt'   => isset($t['fmt']) ? (string) $t['fmt'] : 'text',
            'state' => isset($t['state']) ? (string) $t['state'] : 'idle',
            'href'  => isset($t['href']) ? (string) $t['href'] : '',
        );
    }
    return $out;
}

/** Format a tile value for display. null/'' => grey em-dash; unknown fmt => plain text. */
function devdcorev1_hub_fmt($value, $fmt)
{
    if ($value === null || $value === '') {
        return '—';
    }
    switch ($fmt) {
        case 'int':
            return number_format_i18n((int) $value);
        case 'pct':
            return (string) (0 + $value) . '%';
        case 'money':
            return '$' . number_format_i18n((float) $value, 2);
        case 'ms':
            return (string) (int) $value . ' ms';
        default:
            return (string) $value;
    }
}

/* ------------------------------------------------------------------- health */

/**
 * Aggregated health. Weighted mean over ONLY providers that supply a numeric score;
 * none present => null (rendered as "—" + install tip, never a fabricated number).
 * v1 ships NO `health` callbacks, so this returns null by design.
 */
function devdcorev1_hub_health()
{
    $live = apply_filters('devdcorev1_suite_register', array());
    if (!is_array($live)) {
        $live = array();
    }
    $weights = devdcorev1_hub_weights();
    $sum = 0.0;
    $wsum = 0.0;
    $issues = array();
    $scorers = array();    // slugs that supplied a numeric score
    $scope_label = '';     // the single-provider scope label (e.g. "Protection")

    foreach ($live as $slug => $d) {
        if (!is_array($d) || empty($d['health']) || !is_callable($d['health'])) {
            continue;
        }
        try {
            $h = call_user_func($d['health']);
        } catch (\Throwable $e) {
            continue;
        }
        if (!is_array($h)) {
            continue;
        }
        if (isset($h['score']) && $h['score'] !== null) {
            $w = isset($weights[$slug]) ? (float) $weights[$slug] : 5.0; // unknown slug => small weight
            $sum += ((float) $h['score']) * $w;
            $wsum += $w;
            $scorers[] = (string) $slug;
            $scope_label = isset($h['scope_label']) ? (string) $h['scope_label'] : (isset($d['name']) ? (string) $d['name'] : (string) $slug);
        }
        if (!empty($h['issues']) && is_array($h['issues'])) {
            foreach ($h['issues'] as $iss) {
                if (is_array($iss)) {
                    $iss['_slug'] = (string) $slug;
                    $issues[] = $iss;
                }
            }
        }
    }

    $score = $wsum > 0 ? (int) round($sum / $wsum) : null;
    // Bare "Site Health" (whole-site) is reserved for when a true whole-site provider
    // (Site Monitor's WP-Health monitor) contributes — never fabricated from the tools alone.
    // 1 tool => its own scope ("Protection: 80"); 2+ tools => a qualified "DevDome health" aggregate.
    $has_sm = in_array('devdome-site-monitor', $scorers, true);
    if ($score === null || $has_sm) {
        $label = 'Site Health';
    } elseif (count($scorers) === 1 && $scope_label !== '') {
        $label = $scope_label;
    } else {
        $label = 'DevDome health';
    }

    return array(
        'score'  => $score,
        'issues' => $issues,
        'label'  => $label,
        'scoped' => ($score !== null && !$has_sm), // still "install Site Monitor for whole-site" until SM exists
    );
}

/* --------------------------------------------------------------------- menu */

/**
 * Force the suite submenus into the canonical product order, regardless of plugin load order.
 * Runs after every plugin has registered (priority 999). Keyed by submenu page-slug; unknown
 * slugs sort to the end. The Dashboard (the parent slug) always stays first.
 */
function devdcorev1_hub_submenu_rank($slug, $parent)
{
    if ($slug === $parent) {
        return 0; // Dashboard (the parent slug, shown first)
    }
    // Token match on the page-slug so suffixes (-dashboard, -settings, sub-pages) still sort right.
    // Checked in order; first token found wins. The product order is the canonical suite order.
    $tokens = array(
        'analytics'      => 10,
        'site-monitor'   => 20,
        'bot-protection' => 30,
        'security'       => 40,
        'redirect'       => 50,
        'rm-'            => 50,
        'aff'            => 60,
        'importer'       => 70,
        'media'          => 80,
        'admin-cleaner'  => 90,
        'backup'         => 100,
    );
    foreach ($tokens as $token => $rank) {
        if (strpos($slug, $token) !== false) {
            return $rank;
        }
    }
    return 500; // unknown (e.g. a leftover Account page) sorts to the end
}

function devdcorev1_hub_reorder_submenus()
{
    global $submenu;
    $parent = defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools';
    if (empty($submenu[$parent]) || !is_array($submenu[$parent])) {
        return;
    }
    usort($submenu[$parent], function ($a, $b) use ($parent) {
        $ra = devdcorev1_hub_submenu_rank((string) $a[2], $parent);
        $rb = devdcorev1_hub_submenu_rank((string) $b[2], $parent);
        if ($ra === $rb) {
            return strcmp((string) $a[0], (string) $b[0]);
        }
        return $ra - $rb;
    });
}
add_action('admin_menu', 'devdcorev1_hub_reorder_submenus', 999);

/** Enqueue the self-contained, .dd-app-scoped hub styles on the Overview screen ONLY. */
function devdcorev1_hub_enqueue($hook)
{
    if ($hook !== 'toplevel_page_' . DEVDCOREV1_TOOLS_MENU_SLUG) {
        return;
    }
    $css = __DIR__ . '/assets/hub.css';
    $ver = file_exists($css) ? filemtime($css) : (defined('DEVDCOREV1_VERSION') ? DEVDCOREV1_VERSION : '1.2.0');
    wp_enqueue_style('devdcorev1-hub', plugins_url('assets/hub.css', __FILE__), array('dashicons'), $ver);
}
add_action('admin_enqueue_scripts', 'devdcorev1_hub_enqueue');

/** Dismiss the "Connect to DevDome" strip without connecting (N6). */
function devdcorev1_hub_handle_dismiss_connect()
{
    if (empty($_GET['devdcorev1_hub_dismiss_connect']) || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('devdcorev1_hub_dismiss');
    update_option('devdcorev1_hub_connect_dismissed', 1, false);
    wp_safe_redirect(add_query_arg(array('page' => DEVDCOREV1_TOOLS_MENU_SLUG), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdcorev1_hub_handle_dismiss_connect');

/* ------------------------------------------------------------------- render */

/**
 * The Overview entry point. Host-takeover seam: Site Monitor (later) may claim the screen
 * via `devdcorev1_suite_hub_renderer` and skin it into the full "Site Health" dashboard. The
 * host call is wrapped — a throwing host falls back to the default renderer, never a WSOD.
 * The menu shim delegates to this when devdome-core 1.2.0+ is present.
 */
function devdcorev1_hub_render_overview()
{
    $host = apply_filters('devdcorev1_suite_hub_renderer', null);
    if (is_callable($host)) {
        try {
            call_user_func($host);
            return;
        } catch (\Throwable $e) {
            // fall through to the default Overview
        }
    }
    devdcorev1_hub_render_default();
}

/** Core's registry-driven Overview (the default v1 hub). */
function devdcorev1_hub_render_default()
{
    if (!current_user_can('manage_options')) {
        return; // the menu registers with this capability; the renderer checks it itself too (DeepSeek core round 1)
    }
    $rows   = devdcorev1_hub_registry();
    $health = devdcorev1_hub_health();

    $active    = array_values(array_filter($rows, function ($r) { return $r['installed']; }));
    $not_inst  = array_values(array_filter($rows, function ($r) { return !$r['installed']; }));

    // Split the not-registered catalog entries into INACTIVE (present on disk but off) vs AVAILABLE (not installed).
    // (is_plugin_active() is there: this is a wp-admin screen, core loads its plugin functions itself.)
    $inactive  = array();
    $available = array();
    foreach ($not_inst as $r) {
        $pf = $r['plugin_file'];
        if ($pf && file_exists(WP_PLUGIN_DIR . '/' . $pf) && !is_plugin_active($pf)) {
            $inactive[] = $r;
        } elseif (!empty($r['listed'])) {
            $available[] = $r; // unreleased plugins stay out of the public promo list
        }
    }

    // Real WP update map (devdome-* only), keyed by plugin file.
    $updates = devdcorev1_hub_plugin_updates();

    // Regroup health issues back under their plugin slug (each plugin owns its own issues).
    $issues_by_slug = array();
    if (!empty($health['issues']) && is_array($health['issues'])) {
        foreach ($health['issues'] as $iss) {
            if (is_array($iss) && !empty($iss['_slug'])) {
                $issues_by_slug[(string) $iss['_slug']][] = $iss;
            }
        }
    }

    // Counts for the summary band.
    $active_n = count($active);
    $update_n = 0;
    foreach ($active as $r) {
        if ($r['plugin_file'] && isset($updates[$r['plugin_file']])) {
            $update_n++;
        }
    }
    // Connect state (server-verified via the site token, cached — never bare local options).
    // Rendering makes no remote call until an explicit connect action has been recorded (see
    // devdcorev1_connection_state). The Connect button posts to admin-post so the opt-in is a
    // real nonce-checked user action (devdcorev1_hub_handle_connect_go), never a bare link.
    $conn_state        = devdcorev1_connection_state();
    $connected         = !empty($conn_state['ok']);
    $connect_dismissed = (bool) get_option('devdcorev1_hub_connect_dismissed', 0);
    $dismiss_url = wp_nonce_url(add_query_arg(array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'devdcorev1_hub_dismiss_connect' => 1), admin_url('admin.php')), 'devdcorev1_hub_dismiss');

    $can_deactivate = current_user_can('activate_plugins');

    // Reusable inline SVGs, printed through wp_kses() with this allow-list.
    $ic_kses   = array(
        'svg'  => array('viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true),
        'path' => array('d' => true),
    );
    $ic_arrow  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    $ic_update = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
    $ic_power  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/></svg>';
    $ic_dl     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>';
    $ic_check  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    $ic_caret  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    ?>
    <div class="dd-app">
        <?php
        if (function_exists('devdcorev1_hub_install_notice')) { echo wp_kses_post(devdcorev1_hub_install_notice()); }
        ?>
        <!-- header bar: the suite-wide §14 frame (white bar, brand badge, title, bug report) -->
        <div class="ddh-bar">
            <div class="ddh-bar-in">
                <span class="ddh-brand">DD</span>
                <h1>DevDome Dashboard</h1>
                <a class="ddh-bug" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-tools&v=' . DEVDCOREV1_VERSION); ?>" target="_blank" rel="noopener noreferrer" title="Report a bug">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                </a>
            </div>
        </div>
        <div class="ddh">

            <?php // Display-only notice flag set by our own redirect; no data is processed.
            $ddacct = isset($_GET['ddacct']) ? sanitize_key(wp_unslash($_GET['ddacct'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from our own PRG redirect
            if ('disconnected' === $ddacct) : ?>
                <div style="margin:0 0 14px;padding:11px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;color:#374151;font-weight:600;">Disconnected. This site is no longer linked to a DevDome account.</div>
            <?php elseif ('disconnect-failed' === $ddacct) : ?>
                <div style="margin:0 0 14px;padding:11px 16px;border:1px solid #fecaca;border-radius:10px;background:#fef2f2;color:#991b1b;font-weight:600;">Not disconnected: the connection could not be cleared on this site (database error, or it verified as still connected). Nothing changed; try again, and check the database with your host if it keeps happening.</div>
            <?php elseif ('disconnected-local' === $ddacct) : ?>
                <div style="margin:0 0 14px;padding:11px 16px;border:1px solid #f59e0b;border-radius:10px;background:#fffbeb;color:#92400e;font-weight:600;">Disconnected on this site, but the DevDome account server could not be reached, so the account may still list this site. Remove it from your account at devdome.com, or reconnect and disconnect again.</div>
            <?php endif; ?>
            <?php
            // Connect failures (set by devdcorev1_hub_handle_connect_go / devdcorev1_hub_maybe_complete_connect,
            // review 2026-09-11): these redirects carried dd_error and nothing displayed it, so a failed
            // connect looked like nothing happened. Display-only flags from our own redirect.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $dd_err = isset($_GET['dd_error']) && is_string($_GET['dd_error']) ? sanitize_key(wp_unslash($_GET['dd_error'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $dd_why = isset($_GET['dd_why']) && is_string($_GET['dd_why']) ? sanitize_text_field(wp_unslash($_GET['dd_why'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $dd_retry = isset($_GET['dd_retry']) && is_string($_GET['dd_retry']) ? sanitize_key(wp_unslash($_GET['dd_retry'])) : '';
            $dd_retry_url = '' !== $dd_retry ? add_query_arg(array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'dd_connect' => 1, 'rt' => $dd_retry), admin_url('admin.php')) : '';
            $dd_msgs = array(
                'start'   => 'Could not start the connection. The detail below says why (a blocked outbound request, or a callback address DevDome does not accept). Fix that, then press Connect again.',
                'expired' => 'This connect link has expired or was already used. Press Connect again to start a fresh one (it stays valid for 10 minutes).',
                'verify'  => 'DevDome did not confirm the connection for this site. Press Try again or Connect again; if it keeps failing, send the detail below to support@devdome.com.',
            );
            if ('' !== $dd_err && isset($dd_msgs[$dd_err])) : ?>
                <div style="margin:0 0 14px;padding:11px 16px;border:1px solid #ef4444;border-radius:10px;background:#fef2f2;color:#991b1b;font-weight:600;">
                    <?php echo esc_html($dd_msgs[$dd_err]); ?>
                    <?php if ('' !== $dd_why) : ?><div style="margin-top:6px;font-weight:500;font-family:ui-monospace,Menlo,monospace;font-size:12px;">Detail: <?php echo esc_html($dd_why); ?></div><?php endif; ?>
                    <?php if ('' !== $dd_retry_url) : ?><div style="margin-top:8px;"><a class="ddh-btn ddh-btn-solid" href="<?php echo esc_url($dd_retry_url); ?>">Try again</a></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- connect (disconnected only; the connected account lives inside the summary card) -->
            <?php if (!$connected && !$connect_dismissed) : ?>
                <div class="ddh-conn">
                    <span class="ddh-ci">DD</span>
                    <div class="ddh-cbody">
                        <strong>Connect this site to your DevDome account</strong>
                        <span>Connect sends your domain, site token, active DevDome plugins and their versions, and the DevDome library, WordPress and PHP versions to api.devdome.com with the recurring account checks. Nothing about users, content or visitors. See each plugin&rsquo;s readme, External services, for details. <a href="https://devdome.com/privacy-policy" target="_blank" rel="noopener noreferrer" style="color:#2563eb;text-decoration:underline;">Privacy Policy</a> &middot; <a href="https://devdome.com/terms-of-service" target="_blank" rel="noopener noreferrer" style="color:#2563eb;text-decoration:underline;">Terms of Service</a></span>
                    </div>
                    <div class="ddh-cctl">
                        <div class="ddh-cpanel">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                <input type="hidden" name="action" value="devdcorev1_connect_go">
                                <input type="hidden" name="devdcorev1_inventory" value="1"><?php // printed only beside the disclosure above: this press carries the inventory permission ?>
                                <?php wp_nonce_field('devdcorev1_connect_go'); ?>
                                <button type="submit" class="ddh-btn ddh-btn-solid ddh-cgo">Connect your DevDome account</button>
                            </form>
                            <p class="ddh-chint">Opens devdome.com to sign in, then links this site.</p>
                        </div>
                    </div>
                    <a class="ddh-cx" title="Dismiss" href="<?php echo esc_url($dismiss_url); ?>">&times;</a>
                </div>
            <?php endif; ?>

            <!-- Suite health ring REMOVED (owner 2026-08-20): issues live on the
                 plugin rows; the card now exists only to carry the account row. -->
            <?php if ($connected) : ?>
            <div class="ddh-sum">
                <div class="ddh-sum-acct">
                    <span class="ddh-ci">DD</span>
                    <div class="ddh-cbody"><strong>Connected to your DevDome account</strong><span><?php
                        $ddh_plan = !empty($conn_state['plan']) ? ' ' . ucfirst((string) $conn_state['plan']) . ' plan.' : '';
                        echo esc_html(($conn_state['email'] !== '' ? 'Signed in as ' . $conn_state['email'] . ($conn_state['account_id'] !== '' ? ' (' . $conn_state['account_id'] . ').' : '.') : 'Email alerts are connected to your DevDome account.') . $ddh_plan);
                    ?></span></div>
                    <span class="ddh-st ok ddh-connpill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Connected</span>
                    <form method="post" action="" style="margin:0;">
                        <?php wp_nonce_field('devdcorev1_account', '_ddacct'); ?>
                        <button type="submit" name="devdcorev1_disconnect" value="1" class="ddh-btn ddh-btn-ghost">Disconnect</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- active -->
            <div class="ddh-sec"><h3>Active plugins</h3><span class="ddh-count"><?php echo (int) $active_n; ?></span>
                <?php if ($update_n > 0) :
                    $upd_all = wp_nonce_url(self_admin_url('update-core.php'), ''); ?>
                    <a class="ddh-btn ddh-btn-ghost ddh-btn-sm ddh-updall" data-dd-updall="1" href="<?php echo esc_url(self_admin_url('update-core.php')); ?>"><?php echo wp_kses($ic_update, $ic_kses); ?>Update all (<?php echo (int) $update_n; ?>)</a>
                <?php endif; ?>
            </div>
            <?php if (empty($active)) : ?>
                <div class="ddh-empty">No DevDome plugins active yet. Add one from <strong>Worth trying</strong> below.</div>
            <?php else : ?>
            <div class="ddh-list">
                <?php foreach ($active as $r) :
                    $r_issues = isset($issues_by_slug[$r['slug']]) ? $issues_by_slug[$r['slug']] : array();
                    $has = !empty($r_issues);
                    $pf  = $r['plugin_file'];
                    $new_ver = ($pf && isset($updates[$pf])) ? $updates[$pf] : '';
                    $open_url = $r['page'] ? admin_url('admin.php?page=' . $r['page']) : '';
                    $upd_url  = ($pf && $new_ver !== '') ? wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($pf)), 'upgrade-plugin_' . $pf) : '';
                    ?>
                    <?php if ($has) : ?><details class="ddh-row"><summary class="ddh-rsum"><?php else : ?><div class="ddh-row"><div class="ddh-rsum"><?php endif; ?>
                        <span class="ddh-logo"><?php echo wp_kses(devdcorev1_hub_logo_svg($r['slug']), devdcorev1_hub_svg_kses()); ?></span>
                        <div class="ddh-rid"><div class="ddh-rmain"><div class="ddh-rline">
                            <div class="ddh-rname"><?php echo esc_html(strpos($r['name'], 'DevDome') === 0 ? $r['name'] : 'DevDome ' . $r['name']); ?></div>
                            <div class="ddh-rver">v<?php echo esc_html($r['version'] ? $r['version'] : '1.0'); ?><?php if ($new_ver !== '') : ?> <span class="ddh-up">&rarr; v<?php echo esc_html($new_ver); ?></span><?php if ($upd_url) : ?><a class="ddh-upbtn" href="<?php echo esc_url($upd_url); ?>" data-dd-plugin="<?php echo esc_attr($pf); ?>" data-dd-ver="<?php echo esc_attr($new_ver); ?>" data-dd-nonce="<?php echo esc_attr(wp_create_nonce('updates')); ?>" onclick="event.stopPropagation()"><?php echo wp_kses($ic_update, $ic_kses); ?>Update</a><?php endif; ?><?php endif; ?></div>
                        </div><?php if (!empty($r['desc'])) : ?><div class="ddh-rsub"><?php echo esc_html(wp_strip_all_tags($r['desc'])); ?></div><?php endif; ?></div></div>
                        <?php if ($has) : ?>
                            <span class="ddh-st warn"><span class="dot"></span><?php echo (int) count($r_issues); ?> Issue<?php echo esc_html(count($r_issues) === 1 ? '' : 's'); ?><span class="caret"><?php echo wp_kses($ic_caret, $ic_kses); ?></span></span>
                        <?php else : ?>
                            <span class="ddh-st ok"><?php echo wp_kses($ic_check, $ic_kses); ?>No Issues</span><?php // every clean row says No Issues; the registry row never carried a health key, so the old elseif never fired (owner 2026-09-30) ?>
                        <?php endif; ?>
                        <?php if (!empty($r['get_url'])) : ?><a class="ddh-btn ddh-btn-ghost ddh-docs" href="<?php echo esc_url($r['get_url']); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">Docs</a><?php endif; ?>
                        <?php if ($open_url) : ?><a class="ddh-btn ddh-btn-ghost" href="<?php echo esc_url($open_url); ?>" onclick="event.stopPropagation()">Open <?php echo wp_kses($ic_arrow, $ic_kses); ?></a><?php endif; ?>
                    <?php if ($has) : ?></summary><div class="ddh-drop">
                        <?php foreach ($r_issues as $iss) : ?>
                            <?php $fix_url = !empty($iss['href']) ? (string) $iss['href'] : $open_url; ?>
                            <div class="ddh-iss"><div class="ddh-iss-txt"><b><?php echo esc_html(isset($iss['problem']) ? $iss['problem'] : 'Needs attention'); ?></b><?php if (!empty($iss['fix'])) : ?><span><?php echo esc_html($iss['fix']); ?></span><?php elseif (!empty($iss['why_it_matters'])) : ?><span><?php echo esc_html($iss['why_it_matters']); ?></span><?php endif; ?></div><?php if ($fix_url) : ?><a class="ddh-btn ddh-btn-solid ddh-btn-sm ddh-fix" href="<?php echo esc_url($fix_url); ?>">Fix <?php echo wp_kses($ic_arrow, $ic_kses); ?></a><?php endif; ?></div>
                        <?php endforeach; ?>
                    </div></details><?php else : ?></div></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- inactive -->
            <?php if (!empty($inactive)) : ?>
                <div class="ddh-sec"><h3>Inactive plugins</h3><span class="ddh-count"><?php echo (int) count($inactive); ?></span></div>
                <div class="ddh-list">
                    <?php foreach ($inactive as $r) :
                        $pf = $r['plugin_file'];
                        $act_url = ($pf && $can_deactivate) ? wp_nonce_url(add_query_arg(array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'devdcorev1_hub_activate' => $r['slug']), admin_url('admin.php')), 'devdcorev1_hub_activate_' . $r['slug']) : '';
                        ?>
                        <div class="ddh-row inact"><div class="ddh-rsum">
                            <span class="ddh-logo"><?php echo wp_kses(devdcorev1_hub_logo_svg($r['slug']), devdcorev1_hub_svg_kses()); ?></span>
                            <div class="ddh-rid"><div class="ddh-rmain"><div class="ddh-rline"><div class="ddh-rname"><?php echo esc_html(strpos($r['name'], 'DevDome') === 0 ? $r['name'] : 'DevDome ' . $r['name']); ?></div><?php if ($r['version']) : ?><div class="ddh-rver">v<?php echo esc_html($r['version']); ?></div><?php endif; ?></div><?php if (!empty($r['desc'])) : ?><div class="ddh-rsub"><?php echo esc_html(wp_strip_all_tags($r['desc'])); ?></div><?php endif; ?></div></div>
                            <span class="ddh-st off"><span class="odot"></span>Inactive</span>
                            <?php if (!empty($r['get_url'])) : ?><a class="ddh-btn ddh-btn-ghost ddh-docs" href="<?php echo esc_url($r['get_url']); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">Docs</a><?php endif; ?>
                            <?php if ($act_url) : ?><a class="ddh-btn ddh-btn-solid" href="<?php echo esc_url($act_url); ?>"><?php echo wp_kses($ic_power, $ic_kses); ?>Activate</a><?php endif; ?>
                        </div></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- available -->
            <?php if (!empty($available)) : ?>
                <div class="ddh-sec"><h3>Worth trying</h3><span class="ddh-count"><?php echo (int) count($available); ?></span></div>
                <div class="ddh-list">
                    <?php foreach ($available as $r) : ?>
                        <div class="ddh-row avail"><div class="ddh-rsum">
                            <span class="ddh-logo"><?php echo wp_kses(devdcorev1_hub_logo_svg($r['slug']), devdcorev1_hub_svg_kses()); ?></span>
                            <div class="ddh-rid"><div class="ddh-rmain"><div class="ddh-rline"><div class="ddh-rname"><?php echo esc_html(strpos($r['name'], 'DevDome') === 0 ? $r['name'] : 'DevDome ' . $r['name']); ?></div><?php if (!empty($r['version'])) : ?><div class="ddh-rver">v<?php echo esc_html($r['version']); ?></div><?php endif; ?></div><div class="ddh-rsub"><?php echo esc_html(wp_strip_all_tags($r['desc'])); ?></div></div></div>
                            <span class="ddh-st off"><span class="odot"></span>Not installed</span>
                            <?php if (!empty($r['get_url'])) : ?><a class="ddh-btn ddh-btn-ghost ddh-docs" href="<?php echo esc_url($r['get_url']); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">Docs</a><?php endif; ?>
                            <?php
                            if (function_exists('devdcorev1_hub_install_actions')) {
                                echo wp_kses_post(devdcorev1_hub_install_actions($r));
                            } else { ?>
                                <a class="ddh-btn ddh-btn-solid" href="<?php echo esc_url($r['get_url']); ?>" target="_blank" rel="noopener"><?php echo wp_kses($ic_dl, $ic_kses); ?>Get it</a>
                            <?php } ?>
                        </div></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

    </div>
    <?php
}

/** Hub screen assets ride wp_add_inline_style()/wp_add_inline_script() on handle-only registrations. */
function devdcorev1_hub_enqueue_assets($hook)
{
    $slug = defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools';
    if ('toplevel_page_' . $slug !== $hook) {
        return;
    }
    $ver = defined('DEVDCOREV1_VERSION') ? DEVDCOREV1_VERSION : '1.0';
    wp_register_style('devdcorev1-hub', false, array(), $ver);
    wp_enqueue_style('devdcorev1-hub');
    wp_add_inline_style('devdcorev1-hub', devdcorev1_hub_inline_css());
    wp_register_script('devdcorev1-hub', false, array(), $ver, true);
    wp_enqueue_script('devdcorev1-hub');
    wp_add_inline_script('devdcorev1-hub', devdcorev1_hub_inline_js());
}
add_action('admin_enqueue_scripts', 'devdcorev1_hub_enqueue_assets');

/** The hub Overview stylesheet (scoped to .dd-app / .ddh-*). */
function devdcorev1_hub_inline_css()
{
    return <<<'CSS'
        .dd-app{background:#f4f6fa;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#0f172a;min-height:100vh;margin:0 0 0 -20px;padding:0}
        .dd-app .ddh{max-width:1024px;margin:0;padding:12px 24px 24px}
        .dd-app .ddh *{box-sizing:border-box}
        /* §14 header bar: same frame as every plugin page. */
        .dd-app .ddh-bar{background:#fff;border-bottom:1px solid #e5e7eb;box-shadow:0 1px 2px rgba(0,0,0,.05)}
        .dd-app .ddh-bar-in{max-width:1024px;padding:16px 24px;display:flex;align-items:center;gap:12px}
        .dd-app .ddh-bar-in h1{margin:0;font-size:20px;font-weight:700;color:#1f2937;line-height:1.25}
        .dd-app .ddh-bug{margin-left:auto;width:36px;height:36px;border-radius:50px;border:1px solid #dadce0;background:#fff;display:grid;place-items:center;color:#5f6368;text-decoration:none;transition:all .2s}
        .dd-app .ddh-bug:hover{background:#f8fbff;border-color:#1967d2;color:#1967d2}
        .dd-app .ddh-bug svg{width:16px;height:16px}
        /* Buttons: same geometry as the design-system .dd-btn (px-5 py-2.5, 14px, rounded-lg). */
        .dd-app .ddh-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:14px;font-weight:600;border-radius:8px;padding:10px 20px;text-decoration:none;flex:none;cursor:pointer;line-height:1;border:1px solid transparent;white-space:nowrap;transition:.12s}
        .dd-app .ddh-btn svg{width:14px;height:14px}
        .dd-app .ddh-btn-ghost{color:#2563eb;background:#eaf1ff;border-color:#cfe0ff}
        .dd-app .ddh-btn-ghost:hover{background:#2563eb;color:#fff;border-color:#2563eb}
        .dd-app .ddh-btn-solid{color:#fff;background:#2563eb;border-color:#2563eb;box-shadow:0 4px 10px -3px rgba(37,99,235,.5)}
        .dd-app .ddh-btn-solid:hover{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
        .dd-app .ddh-btn-sm{padding:6px 12px;font-size:12px}
        .dd-app .ddh-head{display:flex;align-items:center;gap:14px;margin-bottom:24px}
        .dd-app .ddh-brand{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:linear-gradient(150deg,#3b82f6,#2563eb 55%,#1d4ed8);box-shadow:0 7px 15px -6px rgba(37,99,235,.6);flex:none;color:#fff;font-weight:800;letter-spacing:-.8px;font-size:14px}
        .dd-app .ddh-htt h1{margin:0;font-size:21px;font-weight:800;letter-spacing:-.02em;color:#0f172a}
        .dd-app .ddh-htt p{margin:3px 0 0;font-size:13.5px;color:#475569;font-weight:450;line-height:1.45}
        .dd-app .ddh-conn{display:flex;align-items:center;gap:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:24px;box-shadow:0 1px 2px rgba(0,0,0,.05);position:relative}
        .dd-app .ddh-conn.is-on{border-color:#a7f3d0;background:#f0fdf6}
        .dd-app .ddh-ci{display:inline-flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:12px;background:linear-gradient(150deg,#3b82f6,#2563eb 55%,#1d4ed8);box-shadow:0 7px 15px -6px rgba(37,99,235,.6);flex:none;color:#fff;font-weight:800;letter-spacing:-1px;font-size:18px}
        .dd-app .ddh-conn.is-on .ddh-ci{background:#10b981;box-shadow:none}
        .dd-app .ddh-st.ok.ddh-connpill{margin-left:auto;color:#15803d;background:#ecfdf5;border:1px solid #bbe7d4;padding:8px 14px 8px 12px;font-size:13px;line-height:1;gap:7px}
        .dd-app .ddh-st.ok.ddh-connpill svg{width:14px;height:14px;color:#15a34a}
        .dd-app .ddh-ci svg{width:23px;height:23px}
        .dd-app .ddh-cbody{flex:1;min-width:0}
        .dd-app .ddh-cbody strong{display:block;font-size:14.5px;font-weight:700;color:#0f172a}
        .dd-app .ddh-cbody span{font-size:12.5px;color:#475569;line-height:1.45}
        .dd-app .ddh-cctl{display:flex;flex-direction:column;gap:10px;flex:none;width:272px}
        .dd-app .ddh-cpanel{display:flex;flex-direction:column;gap:8px;margin:0}
        .dd-app .ddh-cpanel[hidden]{display:none}
        .dd-app .ddh-cgo{width:100%;padding:9px 14px}
        .dd-app .ddh-cinput{font-family:inherit;font-size:13px;border:1px solid #e6eaf1;border-radius:9px;padding:9px 12px;width:100%;color:#0f172a;background:#fff}
        .dd-app .ddh-chint{font-size:11px;color:#8a94a6;text-align:center;margin:0}
        .dd-app .ddh-cx{position:absolute;top:11px;right:14px;color:#aab2c2;font-size:17px;line-height:1;text-decoration:none}
        .dd-app .ddh-cx:hover{color:#475569}
        .dd-app .ddh-sum{display:flex;align-items:stretch;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.05);margin-bottom:24px;overflow:hidden}
        .dd-app .ddh-sum-score{display:flex;align-items:center;gap:18px;padding:20px 24px;flex:none}
        .dd-app .ddh-sum-acct{display:flex;align-items:center;gap:14px;padding:20px 24px;flex:1;min-width:0;border-left:1px solid #f3f4f6}
        .dd-app .ddh-ci.on{background:#10b981;box-shadow:none}
        .dd-app .ddh-ring{position:relative;width:80px;height:80px;flex:none}
        .dd-app .ddh-ring svg{transform:rotate(-90deg)}
        .dd-app .ddh-ring-txt{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1}
        .dd-app .ddh-ring-txt b{font-size:25px;font-weight:800;letter-spacing:-.02em;color:#2563eb}
        .dd-app .ddh-ring-txt span{font-size:9.5px;font-weight:700;letter-spacing:.08em;color:#8a94a6;text-transform:uppercase;margin-top:2px}
        .dd-app .ddh-sum-label{min-width:140px}
        .dd-app .ddh-sum-label h2{margin:0;font-size:15px;font-weight:700;letter-spacing:-.01em;color:#0f172a}
        .dd-app .ddh-sum-label p{margin:5px 0 0;font-size:12px;color:#8a94a6;line-height:1.5}
        .dd-app .ddh-sum-label .gd{color:#2563eb;font-weight:600}
        .dd-app .ddh-sec{display:flex;align-items:center;gap:10px;margin:0 2px 12px}
        .dd-app .ddh-sec h3{margin:0;font-size:18px;font-weight:650;letter-spacing:-.015em;color:#111827}
        .dd-app .ddh-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 8px;border-radius:999px;background:#2563eb;color:#fff;font-size:12.5px;font-weight:800;line-height:1;letter-spacing:0;box-shadow:0 1px 2px rgba(37,99,235,.35)}
        .dd-app .ddh-updall{margin-left:auto}
        .dd-app .ddh-empty{border:1px dashed #cfe0ff;border-radius:12px;background:#fff;padding:18px;font-size:13px;color:#8a94a6;margin-bottom:28px}
        /* §14 card list: one white card, one row per tool. */
        .dd-app .ddh-list{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.05);overflow:hidden;margin-bottom:28px}
        .dd-app .ddh-row{border-top:1px solid #f3f4f6}
        .dd-app .ddh-list>.ddh-row:first-child{border-top:0}
        .dd-app details.ddh-row>summary{list-style:none}
        .dd-app details.ddh-row>summary::-webkit-details-marker{display:none}
        .dd-app .ddh-rsum{display:flex;align-items:center;gap:14px;padding:15px 18px;transition:background .12s}
        .dd-app details.ddh-row>summary.ddh-rsum{cursor:pointer}
        .dd-app .ddh-rsum:hover{background:#fbfcff}
        .dd-app .ddh-logo{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:linear-gradient(150deg,#3b82f6,#2563eb 55%,#1d4ed8);box-shadow:0 6px 13px -6px rgba(37,99,235,.6);flex:none}
        .dd-app .ddh-logo svg{width:23px;height:23px;color:#fff}
        .dd-app .ddh-rid{flex:1;min-width:0;display:flex;align-items:center;gap:12px}
        .dd-app .ddh-rname{font-size:16px;font-weight:650;letter-spacing:-.012em;color:#0f172a}
        .dd-app .ddh-rver{display:flex;align-items:center;gap:8px;font-size:14px;color:#374151;font-weight:700;white-space:nowrap}
        .dd-app .ddh-up{color:#2563eb;font-weight:700}
        .dd-app .ddh-upbtn{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:#fff;background:#dc2626;border:1px solid #dc2626;border-radius:8px;padding:5px 14px;text-decoration:none;margin-left:4px;transition:.12s}
        .dd-app .ddh-upbtn:hover{background:#b91c1c;border-color:#b91c1c;color:#fff}
        .dd-app .ddh-upbtn svg{width:13px;height:13px}
        .dd-app .ddh-upbtn.is-loading{pointer-events:none;background:#dc2626;color:#fff}
        .dd-app .ddh-upbtn .ddh-spin{width:11px;height:11px;border-color:rgba(255,255,255,.4);border-top-color:#fff}
        .dd-app .ddh-upbtn.is-done{pointer-events:none;color:#059669;background:#ecfdf5;border-color:#bbe7d4}
        .dd-app .ddh-upbtn.is-err{color:#dc2626;background:#fef2f2;border-color:#fecaca}
        .dd-app .ddh-rsub{font-size:13px;line-height:1.45;color:#64748b;font-weight:500;margin-top:4px;white-space:normal;text-wrap:balance;max-width:620px;padding-right:12px}
        .dd-app .ddh-st{display:inline-flex;align-items:center;gap:7px;flex:none;font-size:12px;font-weight:600;border-radius:999px}
        .dd-app .ddh-st.warn{background:#fdf4e6;color:#c2740a;border:1px solid #f1dcb6;padding:6px 6px 6px 11px}
        .dd-app .ddh-st.ok{color:#8a94a6;padding:6px 4px 6px 0}
        .dd-app .ddh-st.ok svg{width:14px;height:14px;color:#15a34a}
        .dd-app .ddh-st.off{color:#8a94a6;padding:6px 4px 6px 0}
        .dd-app .ddh-st.off .odot{width:7px;height:7px;border-radius:50%;background:#c3cad6}
        .dd-app .ddh-st.warn .dot{width:7px;height:7px;border-radius:50%;background:#e08a14}
        .dd-app .ddh-st .caret{display:inline-flex}
        .dd-app .ddh-st .caret svg{width:15px;height:15px;display:block;color:#c08a2e}
        .dd-app details.ddh-row[open] .ddh-st .caret{transform:rotate(180deg)}
        .dd-app .ddh-drop{padding:2px 18px 16px 76px;background:#fcfdff}
        .dd-app .ddh-iss{display:flex;align-items:center;gap:14px;border:1px solid #f1dcb6;background:#fdf4e6;border-radius:10px;padding:11px 13px;margin-top:8px}
        .dd-app .ddh-iss-txt{flex:1;min-width:0}
        .dd-app .ddh-fix{flex:none;margin-left:auto;white-space:nowrap}
        .dd-app .ddh-fix svg{width:13px;height:13px}
        .dd-app .ddh-iss b{display:block;font-size:12.5px;font-weight:700;color:#92560a}
        .dd-app .ddh-iss span{display:block;font-size:11.5px;color:#475569;margin-top:3px;line-height:1.45}
        .dd-app .ddh-row.inact .ddh-logo,.dd-app .ddh-row.avail .ddh-logo{background:#e9edf4;box-shadow:none}
        .dd-app .ddh-row.inact .ddh-logo svg,.dd-app .ddh-row.avail .ddh-logo svg{color:#9aa3b4}
        .dd-app .ddh-row.inact .ddh-rname{color:#475569}
        .dd-app .ddh-rname{white-space:nowrap}
        .dd-app .ddh-rmain{min-width:0;flex:1}
        .dd-app .ddh-rline{display:flex;align-items:center;gap:12px}
        .dd-app .ddh-docs{margin-right:2px}
        .dd-app .ddh-btn.is-loading{pointer-events:none;cursor:default;opacity:.92}
        .dd-app .ddh-spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:ddh-spin .6s linear infinite;flex:none}
        @keyframes ddh-spin{to{transform:rotate(360deg)}}
        @media (max-width:782px){.dd-app{margin-left:-10px}.dd-app .ddh-conn{flex-wrap:wrap}.dd-app .ddh-cctl{width:100%}.dd-app .ddh-sum{flex-wrap:wrap}.dd-app .ddh-sum-acct{border-left:0;border-top:1px solid #f3f4f6;flex-wrap:wrap}}
CSS;
}

/** Install-button spinner feedback (footer inline script). */
function devdcorev1_hub_inline_js()
{
    return <<<'JS'
        (function(){
            // Result notices (install / activate): drop the flag from the URL so a reload or a
            // later visit never shows it again, then fade it out after a few seconds.
            var notice = document.querySelector('.dd-app .dd-hub-connect[data-ddnotice]');
            if (notice) {
                if (window.history && window.history.replaceState) {
                    try { var u = new URL(window.location.href); u.searchParams.delete('ddinstall'); u.searchParams.delete('ddslug'); window.history.replaceState(null, '', u.toString()); } catch (e) {}
                }
                setTimeout(function () {
                    notice.style.transition = 'opacity .4s ease, max-height .4s ease';
                    notice.style.opacity = '0';
                    setTimeout(function () { if (notice.parentNode) { notice.parentNode.removeChild(notice); } }, 450);
                }, 5000);
            }
            // Install buttons trigger a full-page install+redirect — swap to an inline spinner on click
            // so the user gets immediate feedback during the (multi-second) download/activate.
            document.querySelectorAll('.dd-app a.ddh-install').forEach(function(a){
                a.addEventListener('click', function(){
                    if (a.classList.contains('is-loading')) { return; }
                    a.style.minWidth = a.offsetWidth + 'px';
                    a.classList.add('is-loading');
                    a.setAttribute('aria-busy', 'true');
                    a.innerHTML = '<span class="ddh-spin" aria-hidden="true"></span>Installing…';
                });
            });

            // Plugin updates run in place via WP's own AJAX updater (owner 2026-08-20):
            // the pill flips to Updated and the user never lands on the update-log
            // screen. The href stays as the classic update.php fallback for no-JS
            // and as the retry path after a failure.
            function ddhUpdate(a){
                if (a.classList.contains('is-loading') || a.classList.contains('is-done')) { return Promise.resolve(true); }
                a.classList.remove('is-err');
                a.classList.add('is-loading');
                a.setAttribute('aria-busy', 'true');
                a.innerHTML = '<span class="ddh-spin" aria-hidden="true"></span>Updating…';
                var body = new URLSearchParams();
                body.set('action', 'update-plugin');
                body.set('plugin', a.getAttribute('data-dd-plugin') || '');
                body.set('slug', (a.getAttribute('data-dd-plugin') || '').split('/')[0]);
                body.set('_ajax_nonce', a.getAttribute('data-dd-nonce') || '');
                return fetch(window.ajaxurl || 'admin-ajax.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body.toString()
                }).then(function(r){ return r.json(); }).then(function(j){
                    if (!j || !j.success) {
                        var m = j && j.data && (j.data.errorMessage || j.data.error);
                        throw new Error(m || 'Update failed');
                    }
                    a.classList.remove('is-loading');
                    a.classList.add('is-done');
                    a.removeAttribute('aria-busy');
                    a.innerHTML = 'Updated';
                    var rver = a.closest('.ddh-rver');
                    if (rver) {
                        var nv = a.getAttribute('data-dd-ver');
                        if (nv && rver.childNodes.length && rver.childNodes[0].nodeType === 3) { rver.childNodes[0].nodeValue = 'v' + nv; }
                        var up = rver.querySelector('.ddh-up');
                        if (up) { up.remove(); }
                    }
                    ddhCount(-1);
                    return true;
                }).catch(function(e){
                    a.classList.remove('is-loading');
                    a.classList.add('is-err');
                    a.removeAttribute('aria-busy');
                    a.innerHTML = 'Retry update';
                    a.title = (e && e.message) ? e.message : 'Update failed';
                    return false;
                });
            }
            function ddhCount(delta){
                var all = document.querySelector('.dd-app [data-dd-updall]');
                if (!all) { return; }
                var m = (all.textContent || '').match(/\((\d+)\)/);
                var n = m ? Math.max(0, parseInt(m[1], 10) + delta) : 0;
                if (n === 0) {
                    all.classList.add('is-loading');
                    all.textContent = 'All updated';
                } else {
                    all.innerHTML = all.innerHTML.replace(/\(\d+\)/, '(' + n + ')');
                }
            }
            document.querySelectorAll('.dd-app a.ddh-upbtn[data-dd-plugin]').forEach(function(a){
                a.addEventListener('click', function(ev){
                    if (a.classList.contains('is-err')) { return; } // second click follows the href fallback
                    ev.preventDefault();
                    ddhUpdate(a);
                });
            });
            var updall = document.querySelector('.dd-app [data-dd-updall]');
            if (updall) {
                updall.addEventListener('click', function(ev){
                    var btns = Array.prototype.slice.call(document.querySelectorAll('.dd-app a.ddh-upbtn[data-dd-plugin]:not(.is-done)'));
                    if (!btns.length) { return; } // nothing ajaxable: follow update-core.php
                    ev.preventDefault();
                    // Sequential on purpose: parallel upgrades fight over the WP filesystem lock.
                    btns.reduce(function(p, b){ return p.then(function(){ return ddhUpdate(b); }); }, Promise.resolve());
                });
            }
        })();
JS;
}

/** wp_kses() allowlist for the hub's inline SVG glyphs (bundled or from the catalog cache): shapes + presentation attributes only. */
function devdcorev1_hub_svg_kses()
{
    $attrs = array('d' => true, 'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true, 'x' => true, 'y' => true,
        'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'width' => true, 'height' => true, 'points' => true,
        'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true);
    return array(
        'svg'  => array('viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true, 'focusable' => true),
        'path' => $attrs, 'circle' => $attrs, 'rect' => $attrs, 'line' => $attrs, 'polyline' => $attrs, 'polygon' => $attrs, 'ellipse' => $attrs,
    );
}

/** Per-plugin logo (white glyph on the blue tile) resolved by slug keyword: a catalog-cache glyph (kses'd on download) or a bundled one. Escaped at output with devdcorev1_hub_svg_kses(). */
function devdcorev1_hub_logo_svg($slug)
{
    $s = (string) $slug;
    $remote = devdcorev1_hub_remote_catalog();
    if (isset($remote[$s]['logo']) && $remote[$s]['logo'] !== '') {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $remote[$s]['logo'] . '</svg>';
    }
    if (strpos($s, 'site-monitor') !== false)       { $p = '<path d="M22 12h-4l-3 8L9 4l-3 8H2"/>'; }
    elseif (strpos($s, 'analytic') !== false)        { $p = '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>'; }
    elseif (strpos($s, 'redirect') !== false)        { $p = '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>'; }
    elseif (strpos($s, 'bot') !== false)             { $p = '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>'; }
    elseif (strpos($s, 'security') !== false || strpos($s, 'scanner') !== false) { $p = '<rect x="4" y="10.5" width="16" height="11" rx="2.2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/><path d="M12 14.5v3"/>'; }
    elseif (strpos($s, 'affiliate') !== false)       { $p = '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>'; }
    elseif (strpos($s, 'product') !== false || strpos($s, 'import') !== false) { $p = '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'; }
    elseif (strpos($s, 'media') !== false)           { $p = '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="8.5" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/><path d="M16.6 5.4l.8 1.8 1.8.8-1.8.8-.8 1.8-.8-1.8-1.8-.8 1.8-.8z" fill="currentColor" stroke="none"/>'; }
    elseif (strpos($s, 'admin') !== false)           { $p = '<path d="M5 3v4M3 5h4M6 17v4M4 19h4"/><path d="M13 3l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5z"/>'; }
    elseif (strpos($s, 'backup') !== false)          { $p = '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.66 3.6 3 8 3s8-1.34 8-3V5"/><path d="M4 12c0 1.66 3.6 3 8 3s8-1.34 8-3"/>'; }
    else { $p = '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'; }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/** Activate an installed DevDome plugin from the dashboard and return to it (no trip to plugins.php). */
function devdcorev1_hub_handle_activate()
{
    if (empty($_GET['devdcorev1_hub_activate']) || !is_string($_GET['devdcorev1_hub_activate'])) {
        return;
    }
    $slug = sanitize_key(wp_unslash($_GET['devdcorev1_hub_activate']));
    check_admin_referer('devdcorev1_hub_activate_' . $slug);
    if (!current_user_can('activate_plugins')) {
        wp_die(esc_html('You do not have permission to activate plugins.'));
    }
    $file = '';
    foreach (devdcorev1_hub_registry() as $r) {
        if ($r['slug'] === $slug && !empty($r['plugin_file'])) {
            $file = (string) $r['plugin_file'];
            break;
        }
    }
    $result = 'fail';
    if ($file !== '') {
        if (file_exists(WP_PLUGIN_DIR . '/' . $file)) {
            $act = activate_plugin($file);
            $result = (is_wp_error($act) || !is_plugin_active($file)) ? 'fail' : 'activated'; // read back, never assumed (DeepSeek core round 1)
        }
    }
    wp_safe_redirect(add_query_arg(
        array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'ddinstall' => $result, 'ddslug' => $slug),
        admin_url('admin.php')
    ));
    exit;
}
add_action('admin_init', 'devdcorev1_hub_handle_activate');

/** Real WP plugin-update map for devdome-* plugins: plugin_file => new_version. */
function devdcorev1_hub_plugin_updates()
{
    $out = array();
    $upd = get_site_transient('update_plugins');
    if (is_object($upd) && !empty($upd->response) && is_array($upd->response)) {
        foreach ($upd->response as $file => $data) {
            if (stripos((string) $file, 'devdome') !== false) {
                $out[(string) $file] = (is_object($data) && isset($data->new_version)) ? (string) $data->new_version : '';
            }
        }
    }
    return $out;
}
