<?php
/**
 * DevDome Tools — suite-wide account connection state + disconnect handler.
 *
 * The account exists for one user-facing purpose: the optional DevDome Monitoring service
 * (email alerts + the account dashboard). This file holds the server-verified
 * connection-state helper and the hub Disconnect handler. Nothing here gates any feature.
 */

defined('ABSPATH') || exit;

/**
 * Site identity = the DOMAIN (owner 2026-09-13). On a subdirectory multisite every blog shares
 * the network's host, so every blog shares ONE token and ONE site id: reads and writes of the two
 * identity options are routed to the main site through option filters, which covers every
 * plugin's own get_option() calls without touching them. Subdomain multisite blogs keep their own
 * host and their own identity. Connection state stays per blog: each blog presses Connect once
 * and the server treats it as the same site. Core 1.7.0.
 */
if (!function_exists('devdcorev1_shared_identity')) {
    function devdcorev1_shared_identity()
    {
        // Three cheap calls, evaluated each time: switch_to_blog() can change the answer within one request.
        return function_exists('is_multisite') && is_multisite()
            && function_exists('is_subdomain_install') && !is_subdomain_install()
            && function_exists('is_main_site') && !is_main_site();
    }
    /** pre_option_<identity option>: the main site's value, read once per request. */
    function devdcorev1_identity_read($pre, $option, $reset = null)
    {
        static $cache = array(), $reading = false;
        if (null !== $reset) {
            unset($cache[$option]); // a write went to the main site: drop the per-request copy
            return $pre;
        }
        if ($reading) {
            return $pre; // get_blog_option() below runs get_option() on the main site: no recursion
        }
        if (!array_key_exists($option, $cache)) {
            $reading = true;
            $cache[$option] = (string) get_blog_option(get_main_site_id(), $option, '');
            $reading = false;
        }
        return $cache[$option];
    }
    /** pre_update_option_<identity option>: the write lands on the main site; the local row is never read. */
    function devdcorev1_identity_write($value, $old_value, $option)
    {
        static $writing = false;
        if ($writing) {
            return $value; // update_blog_option() below runs update_option() on the main site, which fires this same filter: let that inner write land
        }
        $writing = true;
        update_blog_option(get_main_site_id(), $option, $value);
        $writing = false;
        devdcorev1_identity_read(false, $option, true); // drop the per-request copy
        return $old_value; // unchanged locally = WordPress skips the local write
    }
    if (devdcorev1_shared_identity()) {
        foreach (array('devdcorev1_site_id', 'devdcorev1_site_token') as $devdcorev1_o) {
            add_filter('pre_option_' . $devdcorev1_o, 'devdcorev1_identity_read', 10, 2);
            add_filter('pre_update_option_' . $devdcorev1_o, 'devdcorev1_identity_write', 10, 3);
        }
        unset($devdcorev1_o);
    }
}

/**
 * The stored identity must be THIS host. A site that moved (migration, clone, staging copy) keeps
 * the old domain's id in its options: it would show the old domain's connection and its reconnect
 * would fail the callback check on the server (review 2026-09-13). When the host changed, the id
 * follows the host and the connection is reset so the hub shows Connect again; the token stays
 * (the new domain proves it on the next Connect). Returns true when a move was handled.
 */
if (!function_exists('devdcorev1_reconcile_identity')) {
    function devdcorev1_reconcile_identity()
    {
        if (function_exists('devdcorev1_shared_identity') && devdcorev1_shared_identity()) {
            return false; // the identity is the network's (read through the main site): a mapped-domain subsite must never rewrite it (Codex high round 7)
        }
        $current = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $stored  = strtolower((string) get_option('devdcorev1_site_id', ''));
        if ('' === $current || '' === $stored || $stored === $current) {
            return false;
        }
        update_option('devdcorev1_site_id', $current);
        foreach (array('devdcorev1_conn_state', 'devdcorev1_connected_at', 'devdcorev1_account_id', 'devdcorev1_account_email', 'devdcorev1_connect_started', 'devdcorev1_inventory_consent', 'devdcorev1_connection_gen', 'devdcorev1_hub_connect_dismissed') as $o) {
            delete_option($o);
        }
        delete_transient('devdcorev1_conn_checked');
        return true;
    }
}

/**
 * Suite-wide, server-VERIFIED account connection state: {ok, account_id, email}.
 * "Connected" means the suite's site token (option devdcorev1_site_token, provisioned by the
 * one-click connect in any DevDome plugin) is linked to a DevDome account RIGHT NOW — the
 * alert service resolves token -> account -> email. Local option presence alone (stale or
 * test leftovers) never renders a connected UI. Cached: a transient marker skips the remote
 * call on normal page loads and a persistent last-known state survives transient outages.
 * function_exists-guarded so plugins can ship helpers of the same name safely.
 */
if (!function_exists('devdcorev1_connection_state')) {
    function devdcorev1_connection_state($force = false, $reconcile = true)
    {
        if ($reconcile) {
            devdcorev1_reconcile_identity(); // a moved site resets here (hub and plugin screens), never from a read-only ability
        }
        $state = get_option('devdcorev1_conn_state', array('ok' => 0, 'account_id' => '', 'email' => '', 'plan' => ''));
        if (!is_array($state)) {
            $state = array('ok' => 0, 'account_id' => '', 'email' => '', 'plan' => '');
        }
        $state += array('plan' => ''); // states stored before the plan field existed
        global $wpdb;
        if (isset($wpdb->last_error)) {
            $wpdb->last_error = '';
        }
        $token = (string) get_option('devdcorev1_site_token', '');
        if (isset($wpdb->last_error) && '' !== (string) $wpdb->last_error) {
            // The token could not be READ (core 1.7.1, Analytics round 1): an empty answer from a broken options
            // read is not "never connected". Last known state, nothing written, nothing called.
            return $state;
        }
        if ('' === $token) {
            // No token = never connected anywhere in the suite. Definitive, no remote call, and checked BEFORE the
            // cached answer (DeepSeek core round 1): a token removed elsewhere must not read as connected for the
            // rest of the cache window.
            if (!empty($state['ok']) || !get_transient('devdcorev1_conn_checked')) {
                $state = array('ok' => 0, 'account_id' => '', 'email' => '');
                update_option('devdcorev1_conn_state', $state, false);
                set_transient('devdcorev1_conn_checked', 1, 12 * HOUR_IN_SECONDS);
            }
            return array('ok' => 0, 'account_id' => '', 'email' => '', 'plan' => '');
        }
        if (!$force && get_transient('devdcorev1_conn_checked')) {
            return $state;
        }
        // No calling home before the user opts in (wp.org Guideline 7): the token is
        // auto-provisioned locally, so its mere presence is NOT consent. Until some explicit
        // connect action has been recorded on this site — a completed connect, a saved Account
        // ID, or the user pressing a Connect button (devdcorev1_connect_started, stamped by
        // devdcorev1_hub_handle_connect_go) — the state is definitively "not connected" and no
        // remote request is made. Deliberately NOT transient-cached: the first connect action
        // must be able to verify immediately.
        if (empty($state['ok'])
            && '' === (string) get_option('devdcorev1_connected_at', '')
            && '' === (string) get_option('devdcorev1_account_id', '')
            && !get_option('devdcorev1_connect_started', 0)) {
            return array('ok' => 0, 'account_id' => '', 'email' => '');
        }
        $site = (string) get_option('devdcorev1_site_id', '');
        if ('' === $site) {
            $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }
        // The site token is a long-lived secret: it travels in the Authorization header of a
        // POST, never in a URL (query strings end up in proxy, CDN and server logs). Core 1.6.0.
        $resp = wp_remote_post('https://api.devdome.com/plugin/account', array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'body'    => wp_json_encode(devdcorev1_inventory_allowed() ? array('site' => $site, 'inventory' => devdcorev1_inventory_payload()) : array('site' => $site)),
        ));
        if (is_wp_error($resp)) {
            set_transient('devdcorev1_conn_checked', 1, 10 * MINUTE_IN_SECONDS); // outage: keep last known
            return $state;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (200 === $code && is_array($data) && !empty($data['ok']) && !empty($data['email']) && is_email($data['email'])) {
            $acct = isset($data['account_id']) ? strtoupper(sanitize_text_field($data['account_id'])) : '';
            $state = array(
                'ok'         => 1,
                'account_id' => preg_match('/^DD\d{8}$/', $acct) ? $acct : '',
                'email'      => sanitize_email($data['email']),
                'plan'       => isset($data['plan']) ? sanitize_key((string) $data['plan']) : '',
            );
            update_option('devdcorev1_conn_state', $state, false);
            // 15 min, not 12 h: the hub shows the account PLAN from this state, and a plan
            // change (upgrade/downgrade) must reach the dashboard within minutes.
            set_transient('devdcorev1_conn_checked', 1, 15 * MINUTE_IN_SECONDS);
        } elseif (in_array($code, array(400, 401, 403, 404), true) && is_array($data) && isset($data['ok']) && !$data['ok']) {
            // Definitive: the token is not linked to an account (or was unlinked server-side).
            // Only the account server's own structured refusal counts (core 1.7.0): a 404 page
            // from a proxy or a host outage carries no JSON and must not wipe a live connection.
            $state = array('ok' => 0, 'account_id' => '', 'email' => '');
            update_option('devdcorev1_conn_state', $state, false);
            delete_option('devdcorev1_inventory_consent'); // unlinked on the server: later checks carry the site only (1.7.6)
            set_transient('devdcorev1_conn_checked', 1, HOUR_IN_SECONDS);
        } else {
            set_transient('devdcorev1_conn_checked', 1, 10 * MINUTE_IN_SECONDS); // 5xx: keep last known
        }
        return $state;
    }
}

if (!function_exists('devdcorev1_inventory_allowed')) {
    /**
     * Plugin inventory sharing (core 1.7.6). True only when an administrator pressed Connect on a screen that showed the
     * inventory disclosure: the consent record is written by that connect and by nothing else, so a site connected before
     * 1.7.6, or connected from a button without the disclosure, never sends the list. Disconnect removes it.
     * The record names the connection it belongs to: devdcorev1_connection_gen, a random id written by every completed
     * connect (a date would repeat within one second). A record that outlived its connection because a delete failed can
     * never speak for a later one (Codex + DeepSeek 1.7.6 rounds 1 and 2).
     */
    function devdcorev1_inventory_allowed()
    {
        $c = get_option('devdcorev1_inventory_consent', null);
        $gen = (string) get_option('devdcorev1_connection_gen', '');
        return is_array($c) && isset($c['v'], $c['gen']) && 1 === (int) $c['v']
            && strlen($gen) >= 20 && hash_equals($gen, (string) $c['gen']);
    }

    /**
     * What a consenting connected site adds to its account status check: the ACTIVE DevDome plugins (slug + version) and the
     * library, WordPress and PHP versions. Only plugins that registered themselves with the DevDome suite AND carry a
     * devdome- slug are listed (the built-in catalog misses newer plugins such as Country Blocker); never another plugin of the site.
     */
    function devdcorev1_inventory_payload()
    {
        $live = apply_filters('devdcorev1_suite_register', array());
        $plugins = array();
        if (is_array($live)) {
            foreach ($live as $slug => $d) {
                $slug = sanitize_key((string) $slug);
                if (!preg_match('/^devdome-[a-z0-9-]{2,60}$/', $slug)) {
                    continue;
                }
                $ver = (is_array($d) && isset($d['version'])) ? preg_replace('/[^0-9A-Za-z.\-+]/', '', (string) $d['version']) : '';
                $plugins[] = array('slug' => $slug, 'version' => substr((string) $ver, 0, 20));
            }
        }
        return array(
            'v'       => 1,
            'plugins' => array_slice($plugins, 0, 40),
            'core'    => defined('DEVDCOREV1_VERSION') ? (string) DEVDCOREV1_VERSION : '',
            'wp'      => (string) get_bloginfo('version'),
            'php'     => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        );
    }
}

/** Convenience: is the site connected to a DevDome account (server-verified, cached)? */
if (!function_exists('devdcorev1_account_is_connected')) {
    function devdcorev1_account_is_connected()
    {
        $state = devdcorev1_connection_state();
        return !empty($state['ok']);
    }
}

/**
 * The hub's "Connect your DevDome account" button posts here (admin-post). This is the explicit
 * opt-in: it stamps devdcorev1_connect_started — which is what first allows
 * devdcorev1_connection_state() to verify remotely — then registers a short-lived connect
 * request with DevDome (site-authenticated, the same two-sided flow Analytics 1.0.4 uses) and
 * sends the browser to devdome.com carrying ONLY the opaque request token. Before this click,
 * the suite makes no account request at all.
 */
/** Capability for connect/disconnect: the network's, when the identity is shared by every blog of a subdirectory multisite. */
function devdcorev1_connect_cap()
{
    // The main site of a subdirectory network holds the identity every sub-site reads (Codex high round 3): its
    // administrators need the network capability too, not only the sub-sites'.
    $network = function_exists('is_multisite') && is_multisite() && function_exists('is_subdomain_install') && !is_subdomain_install();
    return $network ? 'manage_network' : 'manage_options';
}

function devdcorev1_hub_handle_connect_go()
{
    if (!current_user_can(devdcorev1_connect_cap())) {
        wp_die('You are not allowed to do that.');
    }
    check_admin_referer('devdcorev1_connect_go');
    devdcorev1_reconcile_identity();
    update_option('devdcorev1_connect_started', time(), false);
    // Inventory sharing (1.7.6): only a Connect form that printed the disclosure carries this field. Any other entry point
    // (a plugin's own Connect button, an older screen) clears it, so the connection is made without the inventory permission.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
    $inventory_yes = isset($_POST['devdcorev1_inventory']) && '1' === sanitize_text_field(wp_unslash($_POST['devdcorev1_inventory']));
    // A new connect decides again. Even if this delete fails, the old record names the OLD connection's id, so it is dead
    // the moment this connect writes its own id.
    delete_option('devdcorev1_inventory_consent');
    $tools = admin_url('admin.php?page=' . (defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdome-tools'));
    // Optional `return`: the plugin screen the user came from, shown again once the connect
    // completes (1.5.9). Same-site URLs only (wp_validate_redirect); anything else = the hub.
    $return = isset($_GET['return']) && is_string($_GET['return']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_GET['return'])), '') : '';
    // Auto-provision the suite identity so connecting is one click (mirrors the plugins'
    // activation provisioning; covers a suite where no plugin has provisioned it yet).
    $site = (string) get_option('devdcorev1_site_id', '');
    if ('' === $site) {
        $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        update_option('devdcorev1_site_id', $site);
    }
    $token = (string) get_option('devdcorev1_site_token', '');
    if ('' === $token) {
        $token = wp_generate_password(40, false);
        update_option('devdcorev1_site_token', $token);
    }
    // Two-sided connect, step 1: register the request server-to-server. The single-use nonce
    // stays in a transient here — it is never placed in a browser URL.
    $resp = wp_remote_post('https://analytics.devdome.com/api/plugin/connect/start', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'site_id'     => $site,
            'site_domain' => $site,
            'site_token'  => $token,
            'return_url'  => $tools,
        )),
    ));
    $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
    $data = is_wp_error($resp) ? null : json_decode(wp_remote_retrieve_body($resp), true);
    if (200 !== $code || !is_array($data) || empty($data['request_token']) || empty($data['nonce'])) {
        // Carry the reason (review 2026-09-11 round 2): a callback-URL refusal (HTTP admin URL,
        // custom port, other admin host) used to read like "cannot reach DevDome".
        $why = is_wp_error($resp)
            ? 'network: ' . $resp->get_error_message()
            : 'server ' . $code . (is_array($data) && !empty($data['error']) ? ': ' . (string) $data['error'] : '');
        wp_safe_redirect(add_query_arg(array('dd_error' => 'start', 'dd_why' => rawurlencode(substr(sanitize_text_field($why), 0, 160))), $tools));
        exit;
    }
    $rt = sanitize_key((string) $data['request_token']);
    // 20 min, not 10: the server gives the request 10 min to be authorized and then a fresh
    // 10 min for the claim, so a slow sign-in must not outlive the local correlation.
    // The browser comes back with the opaque request token only. Storing the starting user + a WP
    // nonce with it binds the completion to the session that pressed Connect (wp.org review
    // 2026-09-16); the remote nonce authenticates the server-to-server claim.
    set_transient('devdcorev1_connrt_' . $rt, array(
        'remote_nonce' => (string) $data['nonce'],
        'user_id'      => get_current_user_id(),
        'wp_nonce'     => wp_create_nonce('devdcorev1_connect_complete_' . $rt),
        'inventory'    => $inventory_yes ? 1 : 0, // bound to this request and this user; a parallel attempt cannot lend it (Codex 1.7.6 round 1)
    ), 20 * MINUTE_IN_SECONDS);
    if ('' !== $return) {
        set_transient('devdcorev1_connret_' . $rt, $return, 20 * MINUTE_IN_SECONDS);
    }
    $account_url = rtrim((string) apply_filters('devdcorev1_account_url', 'https://devdome.com'), '/');
    wp_redirect($account_url . '/connect/?' . http_build_query(array('rt' => $rt))); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed first-party host, never user input.
    exit;
}
add_action('admin_post_devdcorev1_connect_go', 'devdcorev1_hub_handle_connect_go');

/**
 * Two-sided connect, step 3: devdome.com sends the browser back to the hub page with
 * ?dd_connect=1&rt=. The rt must match a request THIS site started (its single-use nonce is
 * in our transient) — that correlation is the CSRF guard. The Account ID itself is claimed
 * server-to-server with the site token; it never rides the browser.
 */
function devdcorev1_hub_maybe_complete_connect()
{
    if (!current_user_can(devdcorev1_connect_cap())) {
        return;
    }
    $slug = defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdome-tools';
    // This IS the connect return: the CSRF guard is the single-use `rt` correlation whose stored WP nonce is verified below.
    if (!isset($_GET['page'], $_GET['dd_connect'], $_GET['rt']) || !is_string($_GET['page']) || !is_string($_GET['rt']) || $slug !== sanitize_key(wp_unslash($_GET['page']))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below against the rt record
        return;
    }
    $tools = admin_url('admin.php?page=' . (defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdome-tools'));
    $rt      = sanitize_key(wp_unslash($_GET['rt'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next lines
    $pending = '' !== $rt ? get_transient('devdcorev1_connrt_' . $rt) : false;
    $nonce   = false;
    if (is_array($pending) && isset($pending['remote_nonce'], $pending['user_id'], $pending['wp_nonce'])
        && get_current_user_id() === (int) $pending['user_id']
        && wp_verify_nonce((string) $pending['wp_nonce'], 'devdcorev1_connect_complete_' . $rt)) {
        $nonce = (string) $pending['remote_nonce'];
    }
    if (false === $nonce) { // no record, another user, or a record from an older core: start Connect again
        wp_safe_redirect(add_query_arg('dd_error', 'expired', $tools));
        exit;
    }
    // The handshake transients stay until the claim SUCCEEDS (review 2026-09-11): a timeout, an
    // outbound block or a server refusal used to burn the nonce first, so a refresh of this same
    // callback said "expired" while the account server still had the request as authorized.
    // Now a refresh retries the claim; the server side stays single-use on its own.
    $back  = get_transient('devdcorev1_connret_' . $rt);
    $site  = (string) get_option('devdcorev1_site_id', '');
    $token = (string) get_option('devdcorev1_site_token', '');
    $resp  = wp_remote_post('https://analytics.devdome.com/api/plugin/connect/claim', array(
        'timeout' => 30, // several sequential DB round trips server-side; 15 s abandoned live claims
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'site_id'       => $site,
            'site_domain'   => $site,
            'site_token'    => $token,
            'request_token' => $rt,
            'nonce'         => (string) $nonce,
        )),
    ));
    $data = is_wp_error($resp) ? null : json_decode(wp_remote_retrieve_body($resp), true);
    // HTTP 200 as well as the payload (core 1.7.0): success-shaped JSON on an error status is not a claim.
    $acct = ( !is_wp_error($resp) && 200 === (int) wp_remote_retrieve_response_code($resp) && is_array($data) && !empty($data['ok']) && !empty($data['account_id']) )
        ? strtoupper(sanitize_text_field((string) $data['account_id'])) : '';
    if (!preg_match('/^DD\d{8}$/', $acct)) {
        // Say WHY (the hub renders dd_error + dd_why): transport error text, or the server's
        // HTTP code and error field. The user can press Connect again; nothing is lost locally.
        if (is_wp_error($resp)) {
            $why = 'network: ' . $resp->get_error_message();
        } else {
            $why = 'server ' . (int) wp_remote_retrieve_response_code($resp)
                . (is_array($data) && !empty($data['error']) ? ': ' . (string) $data['error'] : '');
        }
        // dd_retry: the hub renders a "Try again" link back to this callback (same rt, the kept
        // nonce), so the retained request is actually retried instead of a fresh Connect.
        wp_safe_redirect(add_query_arg(array('dd_error' => 'verify', 'dd_why' => rawurlencode(substr(sanitize_text_field($why), 0, 160)), 'dd_retry' => $rt), $tools));
        exit;
    }
    $stamp = gmdate('c');
    // Fail closed FIRST (Codex Analytics round 7): a negative verdict with no account is written and read back before
    // any identity row changes. Whatever fails from here on, every reader sees "not connected" and the retry link
    // (the correlation transient stays) finishes the handshake; a verdict that did not even land changes nothing.
    $neg = array('ok' => 0, 'account_id' => '', 'email' => '', 'plan' => '');
    update_option('devdcorev1_conn_state', $neg, false);
    $chk = get_option('devdcorev1_conn_state', null);
    if (!is_array($chk) || !empty($chk['ok']) || '' !== (string) (isset($chk['account_id']) ? $chk['account_id'] : '')) {
        wp_safe_redirect(add_query_arg(array('dd_error' => 'verify', 'dd_why' => rawurlencode('the connection could not be saved: the database did not keep it'), 'dd_retry' => $rt), $tools));
        exit;
    }
    update_option('devdcorev1_account_id', $acct);
    update_option('devdcorev1_connected_at', $stamp);
    // Every completed connect gets its own random id, consent or not: an older consent record names an older id.
    $gen = wp_generate_password(32, false);
    update_option('devdcorev1_connection_gen', $gen, false);
    if (is_array($pending) && !empty($pending['inventory'])) {
        update_option('devdcorev1_inventory_consent', array('v' => 1, 'at' => time(), 'user' => get_current_user_id(), 'gen' => $gen), false);
    } else {
        delete_option('devdcorev1_inventory_consent');
    }
    // The claim itself is the proof of connection: record it locally FIRST, so the UI lands
    // connected even when the follow-up account lookup below fails (it then keeps this state).
    update_option('devdcorev1_conn_state', array('ok' => 1, 'account_id' => $acct, 'email' => '', 'plan' => ''), false);
    // Proved before the retry handle goes (core 1.7.1, Analytics round 2): a lost write used to end in a clean
    // redirect with no connection and no way to retry. update_option() leaves the cache untouched when the row
    // did not take, so the read-back is the row's answer.
    $st = get_option('devdcorev1_conn_state', array());
    // The stamp too (Codex Analytics round 5): the plugins lift their own stop markers from devdcorev1_connected_at,
    // so a lost stamp write would leave Analytics stopped on a site the hub calls connected.
    if ((string) get_option('devdcorev1_account_id', '') !== $acct || (string) get_option('devdcorev1_connected_at', '') !== $stamp
        || (string) get_option('devdcorev1_connection_gen', '') !== $gen
        || !is_array($st) || empty($st['ok']) || (string) (isset($st['account_id']) ? $st['account_id'] : '') !== $acct) { // the verdict's own account too (Codex Analytics round 6)
        // Half a connection is no connection: back to the negative verdict and no stamp, each step read back; when
        // even the verdict cannot be rewritten the rows that would paint "connected" are dropped instead.
        update_option('devdcorev1_conn_state', $neg, false);
        delete_option('devdcorev1_connected_at');
        delete_option('devdcorev1_inventory_consent');
        delete_option('devdcorev1_connection_gen');
        $chk = get_option('devdcorev1_conn_state', null);
        if (!is_array($chk) || !empty($chk['ok'])) {
            delete_option('devdcorev1_conn_state');
            delete_option('devdcorev1_account_id');
        }
        wp_safe_redirect(add_query_arg(array('dd_error' => 'verify', 'dd_why' => rawurlencode('the connection could not be saved: the database did not keep it'), 'dd_retry' => $rt), $tools));
        exit;
    }
    delete_transient('devdcorev1_connrt_' . $rt);
    delete_transient('devdcorev1_connret_' . $rt);
    delete_transient('devdcorev1_conn_checked');
    devdcorev1_connection_state(true); // fetch email + plan now so the UI shows them
    /**
     * The site just connected (core 1.7.1): plugins that keep their own "disconnected" markers lift them here
     * instead of watching devdcorev1_connected_at.
     *
     * @param string $acct The account id (DD + 8 digits).
     */
    do_action('devdcorev1_connected', $acct);
    // Back to the screen the user connected from (a plugin's own page), else the hub.
    wp_safe_redirect((is_string($back) && '' !== $back) ? $back : $tools);
    exit;
}
add_action('admin_init', 'devdcorev1_hub_maybe_complete_connect');

/**
 * Serve /.well-known/devdome-connect-proof.txt — the sha256 of THIS site's token. It lets the
 * DevDome server adopt a fresh install's auto-provisioned token at connect start (only someone
 * who controls the site can serve the file); the hash is one-way, reading it reveals nothing
 * usable. Analytics 1.0.4+ ships the same handler — identical output, first one to run exits.
 */
if (!function_exists('devdcorev1_serve_connect_proof')) {
    function devdcorev1_serve_connect_proof()
    {
        $path = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH)
            : '';
        // Query form (core 1.6.6): hosts that answer /.well-known/* from the web server never let
        // this handler run, so the same proof is also served on the home URL with a query flag.
        $query_form = isset($_GET['devdome-connect-proof']) && is_string($_GET['devdome-connect-proof']) && '1' === sanitize_key(wp_unslash($_GET['devdome-connect-proof'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public one-way hash, no state change.
        if ('/.well-known/devdome-connect-proof.txt' !== $path && !$query_form) {
            return;
        }
        $token = (string) get_option('devdcorev1_site_token', '');
        if ('' === $token) {
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html(hash('sha256', $token));
        exit;
    }
    add_action('init', 'devdcorev1_serve_connect_proof');
}

/**
 * Handle the hub Disconnect: unlink the site from the account server-side (authenticated by
 * sending the site id + site token to the DevDome API), then clear every local connect
 * artifact so the verified state flips to disconnected everywhere.
 */
function devdcorev1_hub_handle_account()
{
    if (empty($_POST['devdcorev1_disconnect']) || !current_user_can(devdcorev1_connect_cap())) {
        return;
    }
    check_admin_referer('devdcorev1_account', '_ddacct');
    delete_option('devdcorev1_account_connected'); // legacy flag: nothing reads it since 1.5.0 — delete, don't zero
    $site  = (string) get_option('devdcorev1_site_id', '');
    $token = (string) get_option('devdcorev1_site_token', '');
    $remote_ok = true; // nothing to unlink when the site was never registered
    if ($site !== '' && $token !== '') {
        $res = wp_remote_post('https://api.devdome.com/plugin/disconnect', array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('site' => $site, 'token' => $token)),
        ));
        // The local state is cleared either way (the owner asked to disconnect), but a timeout or a refusal means
        // the account server still lists this site: say so instead of "Disconnected" (review 2026-09-10).
        $code      = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $rbody     = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
        // 2xx alone is not an unlink (core 1.7.0): a proxy page or {"ok":false} on 200 leaves the site listed.
        $remote_ok = $code >= 200 && $code < 300 && is_array($rbody) && isset($rbody['ok']) && true === $rbody['ok']; // strict: {"ok":"false"} is not an unlink (Codex round 6)
    }
    delete_option('devdcorev1_conn_state');
    delete_transient('devdcorev1_conn_checked');
    delete_option('devdcorev1_connected_at');
    delete_option('devdcorev1_account_id'); // disconnect wipes the WHOLE identity — a surviving id re-paints "connected" UIs
    delete_option('devdcorev1_account_email');
    delete_option('devdcorev1_connect_started');
    delete_option('devdcorev1_inventory_consent'); // disconnecting stops inventory sharing (1.7.6)
    delete_option('devdcorev1_connection_gen');
    delete_option('devdcorev1_hub_connect_dismissed'); // the connect card should reappear
    // The local clear is proved on the rows, not assumed (core 1.7.0, Codex high round 24): a delete that did not
    // land leaves the site connected and every cloud switch on while the screen said "Disconnected".
    global $wpdb;
    // The consent stamp too (Codex round 6): a surviving devdcorev1_connect_started let the next verification
    // call home and re-paint "connected" without another Connect action.
    $left = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s, %s)",
        'devdcorev1_conn_state', 'devdcorev1_account_id', 'devdcorev1_connected_at', 'devdcorev1_connect_started', 'devdcorev1_inventory_consent'
    ));
    $local_ok = $left !== null && (int) $left === 0 && (string) $wpdb->last_error === '';
    $state    = devdcorev1_connection_state(true); // re-verify now so the UI flips immediately
    if (is_array($state) && !empty($state['ok'])) {
        $local_ok = false; // still verified as connected after the clear: nothing was disconnected (the state key is "ok"; DeepSeek core round 1)
    }
    wp_safe_redirect(add_query_arg(
        array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'ddacct' => !$local_ok ? 'disconnect-failed' : ($remote_ok ? 'disconnected' : 'disconnected-local')),
        admin_url('admin.php')
    ));
    exit;
}
add_action('admin_init', 'devdcorev1_hub_handle_account');
