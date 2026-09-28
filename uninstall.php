<?php
/**
 * Full removal on DELETE (not deactivation): tables, options, schedules and the downloaded database
 * files. Per-site on multisite.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function devdcoun_uninstall_site()
{
    global $wpdb;
    foreach (array('devdcoun_daily', 'devdcoun_log') as $t) {
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . $t) . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall
    }
    foreach (array('devdcoun_settings', 'devdcoun_summary', 'devdcoun_db_meta', 'devdcoun_db_version', 'devdcoun_db_build_lock') as $o) {
        delete_option($o);
    }
    foreach (array('devdcoun_summary_refresh', 'devdcoun_db_refresh') as $h) {
        wp_clear_scheduled_hook($h);
    }

    // Remove the compiled database directory in uploads.
    $up  = wp_upload_dir(null, false);
    $dir = trailingslashit($up['basedir']) . 'devdome-country-blocker';
    // Only the files this plugin wrote, never a recursive delete, and nothing through a link: whatever else sits in
    // that folder is not ours to remove.
    if (is_dir($dir) && !is_link($dir)) {
        foreach (array('dbip-v4.bin', 'dbip-v6.bin', 'dbip-download.csv.gz', 'index.php') as $devdcoun_f) {
            foreach (array('', '.tmp', '.prev') as $devdcoun_sfx) {
                $devdcoun_path = $dir . '/' . $devdcoun_f . $devdcoun_sfx;
                if (is_file($devdcoun_path) || is_link($devdcoun_path)) {
                    wp_delete_file($devdcoun_path); // a link is unlinked itself, its target is not touched
                }
            }
        }
        @rmdir($dir); // phpcs:ignore -- succeeds only when the folder is empty
    }
}

// Shared-core cleanup runs PER SITE too: the connection state (site ID, site token, account, connection cache) is
// stored per site, so a network uninstall visits every site. It removes shared data only when this is the LAST
// DevDome plugin installed.
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
if (is_multisite()) {
    $devdcoun_offset = 0;
    do {
        $devdcoun_sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $devdcoun_offset));
        foreach ($devdcoun_sites as $devdcoun_blog_id) {
            switch_to_blog((int) $devdcoun_blog_id);
            devdcoun_uninstall_site();
            devdcorev1_uninstall_cleanup('devdome-country-blocker/devdome-country-blocker.php');
            restore_current_blog();
        }
        $devdcoun_offset += 100;
    } while (count($devdcoun_sites) === 100);
} else {
    devdcoun_uninstall_site();
}

// The current site (single site, or the main site of a network).
devdcorev1_uninstall_cleanup('devdome-country-blocker/devdome-country-blocker.php');
