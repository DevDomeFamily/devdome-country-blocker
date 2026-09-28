<?php
/**
 * Activation / deactivation lifecycle + versioned schema upgrades: the daily counts and recent-hits
 * tables, the seeded settings option (never wiping existing values), the empty cached summary, the
 * daily summary-refresh and database-refresh schedules. Schema changes ship as a bumped
 * DEVDCOUN_DB_VERSION and run automatically after an update. Deactivation is non-destructive
 * (schedules only); full removal is uninstall.php.
 *
 * 1.0.0 renamed every identifier to the devdcoun_ prefix. A self-hosted site that ran the pre-release
 * build keeps its data through includes/migrate.php (stripped from wp.org builds: those installs are fresh).
 */

defined('ABSPATH') || exit;

define('DEVDCOUN_DB_VERSION', 3);

function devdcoun_table_daily()
{
    global $wpdb;
    return $wpdb->prefix . 'devdcoun_daily';
}

function devdcoun_table_log()
{
    global $wpdb;
    return $wpdb->prefix . 'devdcoun_log';
}

/** Run a per-site callback across every site of a network, in batches of 100. */
function devdcoun_for_each_site($callback)
{
    $offset = 0;
    do {
        $sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $offset));
        foreach ($sites as $blog_id) {
            switch_to_blog((int) $blog_id);
            call_user_func($callback);
            restore_current_blog();
        }
        $offset += 100;
    } while (count($sites) === 100);
}

function devdcoun_activate($network_wide = false)
{
    if (is_multisite() && $network_wide) {
        devdcoun_for_each_site('devdcoun_activate_site');
        return;
    }
    devdcoun_activate_site();
}

/** The dbDelta schema (single source: activation and upgrades both run it). */
function devdcoun_run_schema()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $daily = devdcoun_table_daily();
    $log   = devdcoun_table_log();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Per-country per-day blocked counter (dbDelta: exactly TWO spaces after PRIMARY KEY).
    dbDelta("CREATE TABLE $daily (
        day date NOT NULL,
        country char(2) NOT NULL DEFAULT '',
        blocked bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (day, country)
    ) $charset_collate;");

    // Recent blocked hits, capped at 200 rows (prune on insert + daily cron).
    dbDelta("CREATE TABLE $log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        country char(2) NOT NULL DEFAULT '',
        ip_display varchar(48) NOT NULL DEFAULT '',
        path varchar(191) NOT NULL DEFAULT '',
        ua varchar(191) NOT NULL DEFAULT '',
        test tinyint(1) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_created (created_at)
    ) $charset_collate;");
}

/** Per-site activation body: tables + seeded options + schedules for the CURRENT site. */
function devdcoun_activate_site()
{
    if (function_exists('devdcoun_migrate_prerelease')) {
        devdcoun_migrate_prerelease(); // self-hosted builds only (includes/migrate.php is stripped from wp.org)
    }
    devdcoun_run_schema();

    // Seed only when missing (reactivation and upgrades never wipe). Never autoloaded.
    add_option(devdcoun_settings_option(), devdcoun_defaults(), '', false);
    add_option('devdcoun_summary', devdcoun_summary(), '', false);
    if (devdcoun_schema_ok()) {
        devdcoun_option_write('devdcoun_db_version', DEVDCOUN_DB_VERSION, false); // else the upgrade path retries
    }

    devdcoun_schedule();
}

/** The two daily schedules, idempotent. */
function devdcoun_schedule()
{
    if (!wp_next_scheduled('devdcoun_summary_refresh')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'devdcoun_summary_refresh');
    }
    // Monthly-ish database refresh: WP has no monthly interval; a daily check that only downloads when the
    // stored dataset month is stale keeps it dependency-free.
    if (!wp_next_scheduled('devdcoun_db_refresh')) {
        wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', 'devdcoun_db_refresh');
    }
}

/**
 * Versioned schema upgrade: runs on normal requests after a plugin update, so a schema change never
 * needs a deactivate / reactivate. Cheap fast path: one not-autoloaded option read, admin / cron only.
 */
function devdcoun_maybe_upgrade()
{
    if (!is_admin() && !(defined('DOING_CRON') && DOING_CRON)) {
        return;
    }
    $installed = (int) get_option('devdcoun_db_version', 0);
    if ($installed >= DEVDCOUN_DB_VERSION) {
        return;
    }
    if (function_exists('devdcoun_migrate_prerelease')) {
        devdcoun_migrate_prerelease();
    }
    devdcoun_run_schema();
    if (devdcoun_schema_ok()) {
        devdcoun_option_write('devdcoun_db_version', DEVDCOUN_DB_VERSION, false); // a partial upgrade is retried on the next admin / cron request
    }
    devdcoun_schedule();
}
add_action('init', 'devdcoun_maybe_upgrade', 5);

/** True when both tables exist and the log has every column this version writes. */
function devdcoun_schema_ok()
{
    global $wpdb;
    $log   = devdcoun_table_log();
    $daily = devdcoun_table_daily();
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$log}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $days  = $wpdb->get_col("SHOW COLUMNS FROM {$daily}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    return is_array($cols) && is_array($days)
        && !array_diff(array('id', 'created_at', 'country', 'ip_display', 'path', 'ua', 'test'), $cols)
        && !array_diff(array('day', 'country', 'blocked'), $days);
}

/** New subsite created while the plugin is network-active: give it its tables + options. */
function devdcoun_initialize_site($new_site)
{
    // The network-active list straight from its option: no core file is pulled in for is_plugin_active_for_network()
    // (wp.org review, Admin Cleaner T2: core loading files are never included by hand).
    $network = (array) get_site_option('active_sitewide_plugins', array());
    if (!isset($network[plugin_basename(DEVDCOUN_FILE)])) {
        return;
    }
    switch_to_blog((int) $new_site->blog_id);
    devdcoun_activate_site();
    restore_current_blog();
}
add_action('wp_initialize_site', 'devdcoun_initialize_site', 10, 1);

function devdcoun_deactivate($network_wide = false)
{
    // Non-destructive: settings, counters and the downloaded database survive a toggle. Only the
    // schedules go; on a network deactivation, on EVERY site.
    $clear = function () {
        wp_clear_scheduled_hook('devdcoun_summary_refresh');
        wp_clear_scheduled_hook('devdcoun_db_refresh');
    };
    if (is_multisite() && $network_wide) {
        devdcoun_for_each_site($clear);
        return;
    }
    $clear();
}
