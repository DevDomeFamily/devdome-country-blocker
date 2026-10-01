<?php
/*
Plugin Name: DevDome Country Blocker
Plugin URI: https://devdome.com/
Description: Block or allow website visitors by country. Works with Cloudflare out of the box or with a free local IP database. No account, no external calls at runtime, nothing sent anywhere.
Version: 1.0.4
Author: DevDome
Author URI: https://devdome.com
Text Domain: devdome-country-blocker
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

// The WordPress.org zip ships this marker file (defines DEVDCOREV1_WPORG_BUILD) so the same codebase
// can switch off self-hosted updates and the suite phone-home for a wp.org-compliant build.
if (file_exists(__DIR__ . '/wporg-build.php')) {
    require __DIR__ . '/wporg-build.php';
}

define('DEVDCOUN_VERSION', '1.0.4');
define('DEVDCOUN_DIR', plugin_dir_path(__FILE__));
define('DEVDCOUN_URL', plugin_dir_url(__FILE__));
define('DEVDCOUN_FILE', __FILE__);
define('DEVDCOUN_PAGE', 'devdome-country-blocker');

// Shared DevDome core (vendored, version-guarded; only the highest copy across all installed
// DevDome plugins actually loads). Loaded FIRST, above every other require.
require_once DEVDCOUN_DIR . 'lib/devdome-core/loader.php';

require_once DEVDCOUN_DIR . 'includes/db-guard.php'; // DESIGN.md 24 / 24.5: failed-query guard + proved option writes
require_once DEVDCOUN_DIR . 'includes/settings.php';
require_once DEVDCOUN_DIR . 'includes/install.php';
if (file_exists(DEVDCOUN_DIR . 'includes/migrate.php')) {
    require_once DEVDCOUN_DIR . 'includes/migrate.php'; // self-hosted builds only: pre-release data moves to the devdcoun_ names
}
require_once DEVDCOUN_DIR . 'includes/helpers.php';
require_once DEVDCOUN_DIR . 'includes/countries.php';
require_once DEVDCOUN_DIR . 'includes/cf-ranges.php';
require_once DEVDCOUN_DIR . 'includes/dbip.php';
require_once DEVDCOUN_DIR . 'includes/geo.php';
require_once DEVDCOUN_DIR . 'includes/blocker.php';
require_once DEVDCOUN_DIR . 'includes/abilities.php'; // WordPress Abilities API (6.9+): registers nothing on older versions

if (is_admin()) {
    require_once DEVDCOUN_DIR . 'includes/admin.php';
}

// Request-wide database guard (DESIGN.md 24): every failed query is recorded before wpdb::query() clears it.
add_filter('query', 'devdcoun_db_guard_record', 1);

/**
 * Capability gate. Agencies can scope who manages blocking without editing code.
 * Used by every mutating handler and every ability.
 */
function devdcoun_capability()
{
    return apply_filters('devdcoun_capability', 'manage_options');
}

// "Report this error" (core 1.7.0, DESIGN.md 26): the report carries the plugin's state, never a visitor
// address; every excerpt passes the plugin's own redactor before it leaves the site.
add_filter('devdcorev1_error_report_log', function ($lines, $plugin) {
    if ($plugin !== 'devdome-country-blocker') {
        return $lines;
    }
    $s    = function_exists('devdcoun_settings') ? devdcoun_settings() : array();
    $sum  = function_exists('devdcoun_summary') ? devdcoun_summary() : array();
    $meta = function_exists('devdcoun_db_meta') ? devdcoun_db_meta() : array();
    return array(
        'schema=' . (int) get_option('devdcoun_db_version', 0),
        'mode=' . (isset($s['mode']) ? (string) $s['mode'] : '?') . ' block=' . (isset($s['block_countries']) ? count((array) $s['block_countries']) : 0) . ' allow=' . (isset($s['allow_countries']) ? count((array) $s['allow_countries']) : 0) . ' action=' . (isset($s['action']) ? (string) $s['action'] : '?') . ' skip_logged_in=' . (int) !empty($s['skip_logged_in']) . ' allow_search=' . (int) !empty($s['allow_search']) . ' trust_proxy=' . (int) !empty($s['trust_proxy']) . ' allowlist=' . (isset($s['ip_allowlist']) ? count((array) $s['ip_allowlist']) : 0),
        'source_ok=' . (int) !empty($sum['source_ok']) . ' cloudflare=' . (int) (function_exists('devdcoun_behind_cloudflare') && isset($_SERVER['HTTP_CF_IPCOUNTRY']) && devdcoun_behind_cloudflare()) . ' db_ready=' . (int) (function_exists('devdcoun_db_ready') && devdcoun_db_ready()),
        'db_meta=' . wp_json_encode(array_intersect_key(is_array($meta) ? $meta : array(), array_flip(array('month', 'records', 'built_at', 'last_error')))),
        'summary=' . wp_json_encode(array_intersect_key(is_array($sum) ? $sum : array(), array_flip(array('blocked_today', 'blocked_7d', 'blocked_30d', 'countries_listed', 'updated_at')))),
        'cron_summary=' . (int) wp_next_scheduled('devdcoun_summary_refresh') . ' cron_db=' . (int) wp_next_scheduled('devdcoun_db_refresh'),
        'zlib=' . (int) function_exists('gzopen'),
    );
}, 10, 2);
add_filter('devdcorev1_error_report_redact', function ($text, $plugin) {
    return ($plugin === 'devdome-country-blocker' && function_exists('devdcoun_redact')) ? devdcoun_redact((string) $text) : $text;
}, 10, 2);

// DevDome Tools hub: register this plugin in the suite dashboard. Tiles + health read ONLY the cached
// summary option (no query on render).
add_filter('devdcorev1_suite_register', function ($r) {
    $r['devdome-country-blocker'] = array(
        'slug'     => 'devdome-country-blocker',
        'name'     => __('Country Blocker', 'devdome-country-blocker'),
        'desc'     => __('Block or allow visitors by country: Cloudflare header or local IP database.', 'devdome-country-blocker'),
        'icon'     => 'dashicons-admin-site-alt3',
        'version'  => DEVDCOUN_VERSION,
        'page'     => 'devdome-country-blocker',
        'position' => 88,
        'schema'   => 1,
        'tiles'    => function () {
            $s = devdcoun_summary();
            if (!is_array($s) || empty($s['updated_at'])) {
                return array();
            }
            $href   = 'admin.php?page=devdome-country-blocker';
            $today  = (int) $s['blocked_today'];
            $week   = (int) $s['blocked_7d'];
            $listed = (int) $s['countries_listed'];
            // Blocked counts are the feature WORKING: always neutral, never "sick".
            return array(
                array('label' => __('Blocked Today', 'devdome-country-blocker'),    'value' => $today,  'fmt' => 'int', 'state' => 'good', 'href' => $href),
                array('label' => __('Blocked 7 Days', 'devdome-country-blocker'),   'value' => $week,   'fmt' => 'int', 'state' => 'good', 'href' => $href),
                array('label' => __('Countries Listed', 'devdome-country-blocker'), 'value' => $listed, 'fmt' => 'int', 'state' => $listed ? 'good' : 'idle', 'href' => $href),
            );
        },
        'health'   => function () {
            // Health = is the plugin OPERATIONAL (source available, cron scheduled), never traffic composition.
            $s = devdcoun_summary();
            if (!is_array($s) || empty($s['updated_at'])) {
                return null;
            }
            $href   = admin_url('admin.php?page=devdome-country-blocker');
            $mode   = (string) $s['mode'];
            $score  = 100;
            $issues = array();
            if ('off' !== $mode && empty($s['source_ok'])) {
                $score -= 50;
                $issues[] = array(
                    'problem'        => __('Blocking is on but no country source is available.', 'devdome-country-blocker'),
                    'why_it_matters' => __('Unknown countries are always allowed, so nothing is blocked right now.', 'devdome-country-blocker'),
                    'fix'            => __('Download the local IP database in Settings, or put the site behind Cloudflare.', 'devdome-country-blocker'),
                    'actions'        => array(array('label' => __('Open Country Blocker', 'devdome-country-blocker'), 'href' => $href . '&cb_tab=settings')),
                );
            }
            if (!wp_next_scheduled('devdcoun_summary_refresh')) {
                $score -= 40;
                $issues[] = array(
                    'problem'        => __('The daily maintenance task is not scheduled.', 'devdome-country-blocker'),
                    'why_it_matters' => __('The dashboard summary and the log cleanup depend on it.', 'devdome-country-blocker'),
                    'fix'            => __('Deactivate and reactivate the plugin to schedule it again.', 'devdome-country-blocker'),
                    'actions'        => array(array('label' => __('Open Country Blocker', 'devdome-country-blocker'), 'href' => $href)),
                );
            }
            if ((int) $s['updated_at'] < time() - (3 * DAY_IN_SECONDS)) {
                $score -= 20;
                $issues[] = array(
                    'problem'        => __('The cached summary has not refreshed in over 3 days.', 'devdome-country-blocker'),
                    'why_it_matters' => __('WP-Cron may not be running on this site.', 'devdome-country-blocker'),
                    'fix'            => __('Open the Country Blocker page once, or check the cron setup with your host.', 'devdome-country-blocker'),
                    'actions'        => array(array('label' => __('Open Country Blocker', 'devdome-country-blocker'), 'href' => $href)),
                );
            }
            return array(
                'score'       => max(20, $score),
                'status'      => $score >= 75 ? 'good' : ($score >= 50 ? 'warn' : 'urgent'),
                'scope_label' => __('Blocking', 'devdome-country-blocker'),
                'summary'     => '',
                'issues'      => $issues,
            );
        },
    );
    return $r;
});

// Recent-activity digest section (read-only, from the cached summary).
add_filter('devdcorev1_suite_report_sections', function ($s) {
    $sum = devdcoun_summary();
    if (!is_array($sum) || empty($sum['updated_at'])) {
        return $s;
    }
    $labels = array('off' => __('Off', 'devdome-country-blocker'), 'block' => __('Block listed countries', 'devdome-country-blocker'), 'allow' => __('Allow listed countries only', 'devdome-country-blocker'));
    $mode   = (string) $sum['mode'];
    $s[]    = array('title' => __('Country Blocker', 'devdome-country-blocker'), 'lines' => array(
        /* translators: %s is the blocking mode label. */
        sprintf(__('Mode: %s', 'devdome-country-blocker'), isset($labels[$mode]) ? $labels[$mode] : $mode),
        /* translators: %s is a formatted number of blocked visits. */
        sprintf(__('%s blocked visits (7 days)', 'devdome-country-blocker'), number_format_i18n((int) $sum['blocked_7d'])),
        /* translators: %s is a formatted number of countries. */
        sprintf(__('%s countries listed', 'devdome-country-blocker'), number_format_i18n((int) $sum['countries_listed'])),
    ));
    return $s;
});

register_activation_hook(__FILE__, 'devdcoun_activate');
register_deactivation_hook(__FILE__, 'devdcoun_deactivate');

// Settings shortcut on the Plugins screen.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . DEVDCOUN_PAGE)) . '">' . esc_html__('Settings', 'devdome-country-blocker') . '</a>');
    return $links;
});

/* DevDome suite self-hosted updates (admin/cron only, never on the front end). The wp.org build
   defines DEVDCOREV1_WPORG_BUILD and the packager strips the updater class, so the whole block is
   skipped there (wp.org must be the only update source, Guideline 8). */
if ( ! defined( 'DEVDCOREV1_WPORG_BUILD' ) ) {
	require_once __DIR__ . '/includes/class-devdome-suite-updater.php';
	if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		new DevDome_Suite_Updater( __FILE__, 'devdome-country-blocker', 'https://api.devdome.com/plugin-updates/devdome-country-blocker.json' );
	}
}
