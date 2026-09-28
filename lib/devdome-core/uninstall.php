<?php
/**
 * Shared-core uninstall coordination. Each DevDome plugin's uninstall.php calls
 * devdcorev1_uninstall_cleanup() with its OWN plugin basename. The shared-core artifacts
 * (the daily feed cron + the cached feed option) are removed only when the plugin being
 * uninstalled is the LAST DevDome plugin still installed — so removing one plugin never
 * orphans the core for the others. (The beacon flood-guard transients auto-expire on their
 * own, so there's nothing to sweep.)
 */

defined('ABSPATH') || exit;

if (!function_exists('devdcorev1_uninstall_cleanup')) {
    function devdcorev1_uninstall_cleanup($self_basename)
    {
        // Detect suite plugins dynamically (a hardcoded list goes stale as the suite
        // grows): any OTHER installed devdome-*/devdome-*.php plugin that vendors the
        // shared core still needs the shared artifacts.
        foreach (array_keys(get_plugins()) as $p) {
            if ($p === $self_basename) {
                continue;
            }
            if (!preg_match('#^devdome-[^/]+/devdome-[^/]+\.php$#', $p)) {
                continue;
            }
            if (is_dir(WP_PLUGIN_DIR . '/' . dirname($p) . '/lib/devdome-core')) {
                return; // another DevDome plugin still uses the shared core — keep it
            }
        }

        // Last one out — remove the shared-core artifacts.
        wp_unschedule_hook('devdcorev1_refresh_feeds'); // argument-independent (DeepSeek core round 1)
        delete_site_transient('devdcorev1_hub_catalog_remote'); // the 12 h remote catalog is a SITE transient, outside the sweep below
        delete_option('devdcorev1_feeds');
        delete_transient('devdcorev1_feed_init');

        // ...and the shared hub/account state written by the bundled hub.
        delete_option('devdcorev1_account_connected');
        delete_option('devdcorev1_connected_at');
        delete_option('devdcorev1_hub_connect_dismissed');
        delete_option('devdcorev1_connect_started');
        delete_option('devdcorev1_inventory_consent');
        delete_option('devdcorev1_connection_gen');
        // The connection itself (core 1.6.1): identifiers, the site token, the cached account
        // and connection state, and every short-lived connect/beacon transient.
        foreach (array('devdcorev1_site_id', 'devdcorev1_site_token', 'devdcorev1_account_id', 'devdcorev1_account_email', 'devdcorev1_account', 'devdcorev1_conn_state', 'devdcorev1_conn_checked', 'devdcorev1_hub_catalog') as $opt) {
            delete_option($opt);
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep of this library's own transients.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_devdcorev1_') . '%',
            $wpdb->esc_like('_transient_timeout_devdcorev1_') . '%'
        ));
    }
}
