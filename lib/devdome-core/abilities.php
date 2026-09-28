<?php
/**
 * DevDome Tools core: WordPress Abilities API (6.9+) exposure of the suite state, so an AI agent
 * or MCP client can ask "is this site connected to DevDome, on which plan, with which DevDome
 * plugins installed?" before calling any plugin's own abilities. Read-only. Connecting needs a
 * browser (the two-sided handshake on devdome.com) and disconnecting is destructive: neither is
 * an ability. The loader loads ONE core copy per site, so this registers once. On WordPress
 * older than 6.9 the API does not exist and nothing is registered. Core 1.7.0.
 */

defined('ABSPATH') || exit;

if (!function_exists('devdcorev1_ability_can')) {
    function devdcorev1_ability_can()
    {
        return current_user_can('manage_options');
    }
}

if (!function_exists('devdcorev1_ability_get_connection')) {
    /** Execute callback: the suite connection state and the installed DevDome plugins. No emails, no paths. */
    function devdcorev1_ability_get_connection()
    {
        // Read-only (Codex high rounds 3-4): the CACHED state the hub maintains, read as is. No identity
        // reconciliation, no option write, no request to the account server from an agent read.
        global $wpdb;
        $wpdb->last_error = '';
        $state   = get_option('devdcorev1_conn_state', array());
        $failed  = (string) $wpdb->last_error !== ''; // checked per read: the next read clears last_error (Codex full round 13)
        $wpdb->last_error = '';
        $token   = (string) get_option('devdcorev1_site_token', '');
        $failed  = $failed || (string) $wpdb->last_error !== '';
        if ($failed) { // a failed read is unknown, not "not connected" (Codex full round 12)
            return new WP_Error('devdcorev1_db_read_failed', 'The connection state could not be read from the database.');
        }
        $state   = is_array($state) ? $state : array();
        if ('' === $token) {
            $state = array(); // no token = never connected anywhere in the suite
        }
        $home    = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $wpdb->last_error = '';
        $site_id = (string) get_option('devdcorev1_site_id', '');
        if ((string) $wpdb->last_error !== '') { // the identity read is checked too (Codex full round 14)
            return new WP_Error('devdcorev1_db_read_failed', 'The site identity could not be read from the database.');
        }
        $stored  = strtolower($site_id);
        $GLOBALS['devdcorev1_catalog_cached_only'] = true; // cached catalog only: no HTTP, no transient write from a read (Codex high round 13)
        $rows    = function_exists('devdcorev1_hub_registry') ? devdcorev1_hub_registry() : array();
        unset($GLOBALS['devdcorev1_catalog_cached_only']);
        $plugins = array();
        foreach ((array) $rows as $r) {
            if (!empty($r['installed'])) {
                $plugins[] = array(
                    'slug'    => isset($r['slug']) ? (string) $r['slug'] : '',
                    'name'    => isset($r['name']) ? (string) $r['name'] : '',
                    'version' => isset($r['version']) ? (string) $r['version'] : '',
                );
            }
        }
        $connected = !empty($state['ok']);
        $slug = defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdome-tools';
        return array(
            'connected'    => $connected,
            'account_id'   => ($connected && isset($state['account_id'])) ? (string) $state['account_id'] : '',
            'plan'         => ($connected && !empty($state['plan'])) ? (string) $state['plan'] : '',
            'site_id'      => $site_id,
            // true when the stored identity is not this site's host (the site moved): the hub resets it on its next load.
            'identity_stale' => (!(function_exists('devdcorev1_shared_identity') && devdcorev1_shared_identity()) && $stored !== '' && $home !== '' && $stored !== $home),
            'core_version' => defined('DEVDCOREV1_VERSION') ? (string) DEVDCOREV1_VERSION : '',
            'plugins'      => $plugins,
            // Where a human completes the connection (the hub's Connect button); empty once connected.
            'connect_url'  => $connected ? '' : admin_url('admin.php?page=' . $slug),
        );
    }
}

if (!function_exists('devdcorev1_register_ability_category')) {
    function devdcorev1_register_ability_category()
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category('devdome-tools', array(
            'label'       => 'DevDome Tools',
            'description' => 'The DevDome plugin suite: account connection state, plan and installed DevDome plugins.',
        ));
    }
    add_action('wp_abilities_api_categories_init', 'devdcorev1_register_ability_category');
}

if (!function_exists('devdcorev1_register_abilities')) {
    function devdcorev1_register_abilities()
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        wp_register_ability('devdome-tools/get-connection', array(
            'label'       => 'Get DevDome connection',
            'description' => 'Get whether this WordPress site is connected to a DevDome account, the account ID and plan, the shared core version, and which DevDome plugins are installed (slug, name, version). Read-only. Call this first to know which DevDome abilities are available and whether the account features (alerts, dashboard) are active. If not connected, connect_url is the admin page where a human presses Connect.',
            'category'    => 'devdome-tools',
            // Empty properties must be a PHP array, not stdClass (core validates by array access).
            'input_schema'  => array('type' => 'object', 'properties' => array(), 'additionalProperties' => false),
            'output_schema' => array('type' => 'object', 'properties' => array(
                'connected'    => array('type' => 'boolean'),
                'account_id'   => array('type' => 'string'),
                'plan'         => array('type' => 'string'),
                'site_id'      => array('type' => 'string'),
                'identity_stale' => array('type' => 'boolean'),
                'core_version' => array('type' => 'string'),
                'plugins'      => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
                    'slug' => array('type' => 'string'), 'name' => array('type' => 'string'), 'version' => array('type' => 'string'),
                ))),
                'connect_url'  => array('type' => 'string'),
            )),
            'execute_callback'    => 'devdcorev1_ability_get_connection',
            'permission_callback' => 'devdcorev1_ability_can',
            'meta' => array(
                'public'       => true,
                'show_in_rest' => true,
                'annotations'  => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
                'mcp'          => array('type' => 'tool'),
            ),
        ));
    }
    add_action('wp_abilities_api_init', 'devdcorev1_register_abilities');
}
