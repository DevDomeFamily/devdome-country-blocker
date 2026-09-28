<?php
/**
 * DevDome Tools — in-dashboard plugin install (N2). WordPress.org COMPLIANT by design:
 *
 *   - One-click **Install** is ONLY for plugins published on the WordPress.org repo
 *     (plugins_api → Plugin_Upgrader). The hub NEVER downloads or executes code from
 *     devdome's servers — that would violate Directory Guideline 8.
 *   - Plugins NOT on WP.org (Pro / unreleased) show **Get it →** linking out to devdome.com,
 *     where the user downloads the zip and installs it via WP's own uploader. An optional
 *     **Upload a .zip** affordance deep-links WP's native plugin uploader.
 *
 * The self-hosted installer (DevDome update server) lives in hub-install-selfhost.php, which
 * is NOT shipped in WordPress.org builds; this file only ever installs from WordPress.org.
 */

defined('ABSPATH') || exit;

if (!function_exists('devdcorev1_hub_can_install')) {
    /** Can the current user one-click install a plugin here? (cap + file-mods + multisite context) */
    function devdcorev1_hub_can_install()
    {
        if (is_multisite() && !is_network_admin()) {
            return false; // install caps apply only in network-admin context
        }
        return current_user_can('install_plugins')
            && (!function_exists('wp_is_file_mod_allowed') || wp_is_file_mod_allowed('install_plugins'));
    }
}

if (!function_exists('devdcorev1_hub_can_upload')) {
    /** Can the current user upload a plugin .zip here? */
    function devdcorev1_hub_can_upload()
    {
        if (is_multisite() && !is_network_admin()) {
            return false;
        }
        return current_user_can('upload_plugins')
            && (!function_exists('wp_is_file_mod_allowed') || wp_is_file_mod_allowed('upload_plugins'));
    }
}

if (!function_exists('devdcorev1_hub_upload_url')) {
    /** Native "upload a plugin .zip" screen (network-admin on multisite). */
    function devdcorev1_hub_upload_url()
    {
        return is_multisite()
            ? network_admin_url('plugin-install.php?tab=upload')
            : admin_url('plugin-install.php?tab=upload');
    }
}

if (!function_exists('devdcorev1_hub_install_actions')) {
    /** Action HTML for an "Available" card: a single one-click Install. Order of preference:
     *  WP.org (repo-compliant) -> DevDome update server -> a direct zip download (never a 404 page). */
    function devdcorev1_hub_install_actions($row)
    {
        $slug    = isset($row['slug']) ? (string) $row['slug'] : '';
        $catalog = function_exists('devdcorev1_hub_catalog') ? devdcorev1_hub_catalog() : array();
        $wporg   = (isset($catalog[$slug]['wporg_slug']) && $catalog[$slug]['wporg_slug'] !== '') ? (string) $catalog[$slug]['wporg_slug'] : '';

        // 1) WP.org one-click (preferred when the plugin is on the repo).
        if ($wporg !== '' && devdcorev1_hub_can_install()) {
            $url = wp_nonce_url(
                add_query_arg(array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'devdcorev1_hub_install' => $slug), admin_url('admin.php')),
                'devdcorev1_hub_install_' . $slug
            );
            return '<a class="ddh-btn ddh-btn-solid ddh-install" href="' . esc_url($url) . '">Install</a>';
        }

        // 2) One-click install from the DevDome update server (Pro / not-on-WP.org plugins).
        if (function_exists('devdcorev1_hub_selfhost_install_enabled') && devdcorev1_hub_selfhost_install_enabled() && isset($catalog[$slug]) && devdcorev1_hub_can_install()) {
            $url = wp_nonce_url(
                add_query_arg(array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'devdcorev1_hub_dl_install' => $slug), admin_url('admin.php')),
                'devdcorev1_hub_dl_install_' . $slug
            );
            return '<a class="ddh-btn ddh-btn-solid ddh-install" href="' . esc_url($url) . '">Install</a>';
        }

        // 3) Can't install here (no caps / file mods off): offer the direct zip, never a
        // marketing 404 — except on wp.org builds, where linking executable zips from our
        // own server is forbidden (Guideline 8): those link the product page instead.
        if (!function_exists('devdcorev1_hub_selfhost_install_enabled') || !devdcorev1_hub_selfhost_install_enabled()) {
            $learn = isset($catalog[$slug]['get_url']) ? (string) $catalog[$slug]['get_url'] : 'https://devdome.com/';
            return '<a class="ddh-btn ddh-btn-solid" href="' . esc_url($learn) . '" target="_blank" rel="noopener">Learn more</a>';
        }
        $zip = devdcorev1_hub_selfhost_zip_url($slug);
        return '<a class="ddh-btn ddh-btn-solid" href="' . esc_url($zip) . '" rel="noopener">Get it</a>';
    }
}

/** Handle a one-click install request (WP.org repo only). */
function devdcorev1_hub_handle_install()
{
    if (empty($_GET['devdcorev1_hub_install']) || !is_string($_GET['devdcorev1_hub_install'])) {
        return;
    }
    $slug = sanitize_key(wp_unslash($_GET['devdcorev1_hub_install']));
    // Nonce + capability FIRST: the catalog lookup below may fetch and cache remote data (wp.org review 2026-09-16).
    check_admin_referer('devdcorev1_hub_install_' . $slug);
    if (!devdcorev1_hub_can_install()) {
        wp_die(esc_html('You do not have permission to install plugins.'));
    }
    $catalog = function_exists('devdcorev1_hub_catalog') ? devdcorev1_hub_catalog() : array();

    // Allowlist = catalog keys (the hardcoded DevDome suite) AND the plugin must have a WP.org slug.
    if (!isset($catalog[$slug]) || empty($catalog[$slug]['wporg_slug'])) {
        wp_die(esc_html('Unknown plugin.'));
    }

    $result = 'fail';
    $wporg  = (string) $catalog[$slug]['wporg_slug'];
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; // plugins_api(); plugin.php and file.php are already loaded in wp-admin
    $api = plugins_api('plugin_information', array('slug' => $wporg, 'fields' => array('sections' => false)));

    // Only ever install from the official WP.org download host, and only the exact slug we asked for.
    if (!is_wp_error($api)
        && !empty($api->download_link)
        && isset($api->slug) && $api->slug === $wporg
        && wp_parse_url($api->download_link, PHP_URL_HOST) === 'downloads.wordpress.org') {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $upgrader  = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $installed = $upgrader->install($api->download_link);
        if (!is_wp_error($installed) && $installed === true) {
            // Install only. The plugin stays INACTIVE until the owner clicks Activate on its
            // card (a separate, explicit action); nothing is ever activated on their behalf.
            $result = 'installed';
        }
    }

    wp_safe_redirect(add_query_arg(
        array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'ddinstall' => $result, 'ddslug' => $slug),
        admin_url('admin.php')
    ));
    exit;
}
add_action('admin_init', 'devdcorev1_hub_handle_install');


if (!function_exists('devdcorev1_hub_install_notice')) {
    /** Validated + escaped install-result banner for the Dashboard (or '' if none). Slug/name come from the allowlist, never raw input. */
    function devdcorev1_hub_install_notice()
    {
        if (empty($_GET['ddinstall']) || !is_string($_GET['ddinstall'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a PRG redirect.
            return '';
        }
        $res     = sanitize_key(wp_unslash($_GET['ddinstall'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, sanitized; slug/name resolved via the catalog allowlist.
        $slug    = isset($_GET['ddslug']) && is_string($_GET['ddslug']) ? sanitize_key(wp_unslash($_GET['ddslug'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, sanitized; only used as a catalog-allowlist key.
        $catalog = function_exists('devdcorev1_hub_catalog') ? devdcorev1_hub_catalog() : array();
        $name    = isset($catalog[$slug]['name']) ? (string) $catalog[$slug]['name'] : 'The plugin';
        if (strpos($name, 'DevDome') !== 0 && $name !== 'The plugin') {
            $name = 'DevDome ' . $name; // the same display name the plugin rows use
        }

        if ($res === 'activated' || $res === 'active') {
            return '<div class="dd-hub-connect" data-ddnotice="1" style="border-color:#a7f3d0;background:#ecfdf5;"><span class="dashicons dashicons-yes-alt" style="color:#059669;"></span><div class="dd-hub-connect-body"><strong>' . esc_html($name) . ' is active.</strong></div></div>';
        }
        if ($res === 'installed') {
            return '<div class="dd-hub-connect" data-ddnotice="1"><span class="dashicons dashicons-info"></span><div class="dd-hub-connect-body"><strong>' . esc_html($name) . ' is installed.</strong><span>Activate it from the list below.</span></div></div>';
        }
        return '<div class="dd-hub-connect" data-ddnotice="1" style="border-color:#fecaca;background:#fef2f2;"><span class="dashicons dashicons-warning" style="color:#b91c1c;"></span><div class="dd-hub-connect-body"><strong>' . esc_html($name) . ' could not be installed.</strong><span>Try again, or install it from Plugins &rsaquo; Add New.</span></div></div>';
    }
}
