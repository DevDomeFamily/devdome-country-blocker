<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): DevDome Country Blocker exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Coverage = every action of the plugin's own screen, audited against the code: settings.php (every
 * setting), admin.php (Save Settings, Download / Update database, Clear statistics), helpers.php (the
 * statistics the Overview shows), geo.php + dbip.php (country lookup). Every write goes through the same
 * shared function the screen uses (one sanitizer, one proved write). Turning blocking on, downloading the
 * database and clearing the statistics need confirm: true. On WordPress older than 6.9 the API does not
 * exist and this file registers nothing.
 */

defined('ABSPATH') || exit;

function devdcoun_ability_ids()
{
    return array(
        'devdome-country-blocker/get-status',
        'devdome-country-blocker/get-settings',
        'devdome-country-blocker/update-settings',
        'devdome-country-blocker/get-statistics',
        'devdome-country-blocker/lookup-country',
        'devdome-country-blocker/update-database',
        'devdome-country-blocker/refresh-summary',
        'devdome-country-blocker/clear-statistics',
    );
}

add_action('wp_abilities_api_categories_init', 'devdcoun_register_ability_category');
function devdcoun_register_ability_category()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    wp_register_ability_category('devdome-country-blocker', array(
        'label'       => __('DevDome Country Blocker', 'devdome-country-blocker'),
        'description' => __('Block or allow website visitors by country: block-list or allow-only mode, a 403 page or a redirect for blocked visitors, safety exemptions (logged-in users, search engines, allowed addresses), the local IP database and the blocked-visit statistics.', 'devdome-country-blocker'),
    ));
}

function devdcoun_ability_can()
{
    return current_user_can(devdcoun_capability());
}

/** read / modify / destroy annotations; $idempotent overrides the default for that kind. */
function devdcoun_ability_meta($kind, $idempotent = null)
{
    $map = array(
        'read'    => array('readonly' => true,  'destructive' => false, 'idempotent' => true),
        'modify'  => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
        'destroy' => array('readonly' => false, 'destructive' => true,  'idempotent' => true),
    );
    $ann = isset($map[$kind]) ? $map[$kind] : $map['modify'];
    if (null !== $idempotent) {
        $ann['idempotent'] = (bool) $idempotent;
    }
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => $ann,
        'mcp'          => array('type' => 'tool'),
    );
}

/** confirm: true or a WP_Error the agent must show the user. */
function devdcoun_ability_confirmed($input, $why)
{
    if (is_array($input) && isset($input['confirm']) && true === $input['confirm']) {
        return true;
    }
    return new WP_Error('devdcoun_confirm_required', $why . ' ' . __('Pass confirm: true after the user agreed.', 'devdome-country-blocker'));
}

/** Output sanitizer at the ability boundary: full visitor addresses, e-mail addresses and server paths never reach an agent. */
function devdcoun_ability_strip($v)
{
    if ($v instanceof WP_Error) {
        $e = new WP_Error();
        foreach ($v->get_error_codes() as $code) {
            $data = $v->get_error_data($code);
            foreach ($v->get_error_messages($code) as $m) {
                $e->add($code, devdcoun_ability_strip($m), null === $data ? null : devdcoun_ability_strip($data));
            }
        }
        return $e;
    }
    if (is_object($v)) {
        return (object) devdcoun_ability_strip(get_object_vars($v));
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            // The configured allow list and trusted proxies are the caller's own settings: returned exactly as stored,
            // so reading and resubmitting them changes nothing (an anonymized copy would alter the addresses).
            if (('ip_allowlist' === $k || 'trusted_proxies' === $k) && is_array($x)) {
                $v[$k] = array_values(array_map('strval', $x));
                continue;
            }
            if ('redirect_url' === $k && is_string($x)) {
                continue; // the configured destination (may hold an address literal) comes back exactly as stored
            }
            $v[$k] = devdcoun_ability_strip($x);
        }
        return $v;
    }
    if (!is_string($v) || '' === $v) {
        return $v;
    }
    $v = devdcoun_redact($v);
    if (defined('ABSPATH') && '' !== (string) ABSPATH && false !== strpos($v, (string) ABSPATH)) {
        $v = str_replace((string) ABSPATH, '<site>/', $v);
    }
    $v = preg_replace('~(?<![\w:/.])(?:/[A-Za-z0-9._-]+){2,}/?~', '<path>', $v);
    $v = preg_replace('~(?<![\w])[A-Za-z]:[\\\\/][^\s"\'<>]*~', '<path>', $v);
    return $v;
}

/** Strict switch value: bool, 0/1 or the spellings true/false/yes/no/on/off; anything else = null (refused). */
function devdcoun_ability_bool($v)
{
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) && (0 === $v || 1 === $v)) {
        return 1 === $v;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }
        if (in_array($s, array('0', 'false', 'no', 'off'), true)) {
            return false;
        }
    }
    return null;
}

/** Settings as the agent sees them, one shape for get-settings and the update read-back. */
function devdcoun_ability_settings()
{
    $s = devdcoun_settings();
    return array(
        'mode'           => (string) $s['mode'],
        'block_countries' => array_values(array_map('strval', (array) $s['block_countries'])),
        'allow_countries' => array_values(array_map('strval', (array) $s['allow_countries'])),
        'action'         => (string) $s['action'],
        'message'        => (string) $s['message'],
        'redirect_url'   => (string) $s['redirect_url'],
        'skip_logged_in' => !empty($s['skip_logged_in']),
        'allow_search'   => !empty($s['allow_search']),
        'trust_proxy'    => !empty($s['trust_proxy']),
        'test_mode'      => !empty($s['test_mode']),
        'protect_login'  => !empty($s['protect_login']),
        'protect_xmlrpc' => !empty($s['protect_xmlrpc']),
        'ip_allowlist'   => array_values(array_map('strval', (array) $s['ip_allowlist'])),
        'trusted_proxies' => array_values(array_map('strval', (array) $s['trusted_proxies'])),
    );
}

/* ------------------------------ handlers ------------------------------ */

function devdcoun_ability_get_status($input = array())
{
    $s    = devdcoun_settings();
    $sum  = devdcoun_summary();
    $meta = devdcoun_db_meta();
    $names = devdcoun_countries();
    $listed = array();
    foreach (devdcoun_active_countries($s) as $cc) {
        $listed[] = array('code' => (string) $cc, 'name' => isset($names[$cc]) ? $names[$cc] : (string) $cc);
    }
    return array(
        'mode'               => (string) $s['mode'],
        'active'             => $s['mode'] !== 'off' && !empty(devdcoun_active_countries($s)) && empty($s['test_mode']) && !(defined('DEVDCOUN_DISABLE') && DEVDCOUN_DISABLE) && !empty($sum['source_ok']),
        'test_mode'          => !empty($s['test_mode']),
        'disabled_by_constant' => defined('DEVDCOUN_DISABLE') && (bool) DEVDCOUN_DISABLE,
        'countries'          => $listed,
        'action'             => (string) $s['action'],
        'source_available'   => !empty($sum['source_ok']),
        'database_installed' => devdcoun_db_ready(),
        'database_month'     => isset($meta['month']) ? (string) $meta['month'] : '',
        'database_ranges'    => isset($meta['records']) ? (int) $meta['records'] : 0,
        'database_last_error' => isset($meta['last_error']) ? (string) $meta['last_error'] : '',
        'blocked_today'      => (int) $sum['blocked_today'],
        'blocked_7d'         => (int) $sum['blocked_7d'],
        'blocked_30d'        => (int) $sum['blocked_30d'],
        'summary_updated_at' => (int) $sum['updated_at'],
        'plugin_version'     => defined('DEVDCOUN_VERSION') ? DEVDCOUN_VERSION : '',
    );
}

function devdcoun_ability_get_settings($input = array())
{
    return array('settings' => devdcoun_ability_settings());
}

function devdcoun_ability_update_settings($input = array())
{
    $input = is_array($input) ? $input : array();
    $was   = devdcoun_settings();
    $patch = array();
    if (array_key_exists('mode', $input)) {
        $m = is_string($input['mode']) ? strtolower(trim($input['mode'])) : '';
        if (!in_array($m, array('off', 'block', 'allow'), true)) {
            return new WP_Error('devdcoun_invalid_input', __('mode must be off, block or allow.', 'devdome-country-blocker'));
        }
        $patch['mode'] = $m;
    }
    foreach (array('block_countries', 'allow_countries') as $list_key) {
        if (!array_key_exists($list_key, $input)) {
            continue;
        }
        $list = $input[$list_key];
        if (!is_array($list) || count(array_filter($list, 'is_string')) !== count($list)) {
            /* translators: %s is the setting name, block_countries or allow_countries. */
            return new WP_Error('devdcoun_invalid_input', sprintf(__('%s must be a list of ISO 3166-1 alpha-2 codes.', 'devdome-country-blocker'), $list_key));
        }
        $valid   = devdcoun_countries();
        $refused = array();
        foreach ($list as $cc) {
            if (!isset($valid[strtoupper(trim($cc))])) {
                $refused[] = $cc;
            }
        }
        if ($refused) {
            /* translators: %s is the list of unknown country codes. */
            return new WP_Error('devdcoun_invalid_input', sprintf(__('Unknown country codes: %s.', 'devdome-country-blocker'), implode(', ', array_map('sanitize_text_field', $refused))));
        }
        $patch[$list_key] = $list;
    }
    if (array_key_exists('action', $input)) {
        $a = is_string($input['action']) ? strtolower(trim($input['action'])) : '';
        if (!in_array($a, array('403', 'redirect'), true)) {
            return new WP_Error('devdcoun_invalid_input', __('action must be 403 or redirect.', 'devdome-country-blocker'));
        }
        $patch['action'] = $a;
    }
    if (array_key_exists('message', $input)) {
        if (!is_string($input['message'])) {
            return new WP_Error('devdcoun_invalid_input', __('message must be a string.', 'devdome-country-blocker'));
        }
        $patch['message'] = $input['message'];
    }
    if (array_key_exists('redirect_url', $input)) {
        $u = is_string($input['redirect_url']) ? trim($input['redirect_url']) : null;
        if (null === $u || ('' !== $u && !preg_match('#^https?://#i', $u))) {
            return new WP_Error('devdcoun_invalid_input', __('redirect_url must be an http(s) URL or an empty string.', 'devdome-country-blocker'));
        }
        // The sanitizer drops a redirect to this site itself (it would loop back into the blocker): refused here, so the
        // old value is kept instead of being silently replaced by an empty one.
        $own_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $new_host = '' === $u ? '' : wp_parse_url(esc_url_raw($u), PHP_URL_HOST);
        if ('' !== $u && (!$new_host || ($own_host && strcasecmp((string) $new_host, (string) $own_host) === 0))) {
            return new WP_Error('devdcoun_invalid_input', __('redirect_url must point to another site: a redirect to this site would loop back into the blocker.', 'devdome-country-blocker'));
        }
        $patch['redirect_url'] = $u;
    }
    foreach (array('skip_logged_in', 'allow_search', 'trust_proxy', 'test_mode', 'protect_login', 'protect_xmlrpc') as $k) {
        if (array_key_exists($k, $input)) {
            $b = devdcoun_ability_bool($input[$k]);
            if (null === $b) {
                /* translators: %s is a setting key. */
                return new WP_Error('devdcoun_invalid_input', sprintf(__('%s must be true or false.', 'devdome-country-blocker'), $k));
            }
            $patch[$k] = $b ? 1 : 0;
        }
    }
    foreach (array('ip_allowlist', 'trusted_proxies') as $list_key) {
        if (!array_key_exists($list_key, $input)) {
            continue;
        }
        $list = $input[$list_key];
        if (!is_array($list) || count(array_filter($list, 'is_string')) !== count($list)) {
            /* translators: %s is the setting name, ip_allowlist or trusted_proxies. */
            return new WP_Error('devdcoun_invalid_input', sprintf(__('%s must be a list of addresses or ranges.', 'devdome-country-blocker'), $list_key));
        }
        $refused = array();
        foreach ($list as $line) {
            if (!devdcoun_valid_allowlist_entry(trim($line))) {
                $refused[] = $line;
            }
        }
        if ($refused) {
            /* translators: %s is the list of refused entries. */
            return new WP_Error('devdcoun_invalid_input', sprintf(__('Not an address or range: %s.', 'devdome-country-blocker'), implode(', ', array_map('sanitize_text_field', $refused))));
        }
        $patch[$list_key] = $list;
    }
    if (!$patch) {
        return new WP_Error('devdcoun_invalid_input', __('Pass at least one setting to change.', 'devdome-country-blocker'));
    }
    // The result as it will be stored, judged BEFORE the write: turning blocking on, or switching to allow-only, blocks
    // real visitors from now on and needs the owner's yes.
    $next = devdcoun_sanitize_settings($patch, $was);
    if ('allow' === $next['mode'] && empty($next['allow_countries'])) {
        return new WP_Error('devdcoun_invalid_input', __('Allow-only mode needs at least one country in allow_countries: with an empty list every visitor would be let in.', 'devdome-country-blocker'));
    }
    if (devdcoun_would_lock_out($next)) {
        return new WP_Error('devdcoun_lockout', __('Refused: with the login page blocked, these settings would block the country this request comes from and lock the site owner out. Put the owner\'s address on ip_allowlist first.', 'devdome-country-blocker'));
    }
    $confirmed  = isset($input['confirm']) && true === $input['confirm'];
    $more_doors = ($next['protect_login'] && !$was['protect_login']) || ($next['protect_xmlrpc'] && !$was['protect_xmlrpc']);
    if ($more_doors && !$confirmed) {
        return new WP_Error('devdcoun_confirm_required', __('Blocking the login page or xmlrpc.php can shut out the site owner or a connected service. Pass confirm: true after the site owner agreed.', 'devdome-country-blocker'));
    }
    // Every other change of WHO gets blocked while blocking is (or was) live, in either direction: test mode going live,
    // a shrinking allow list, and the ones that lower protection (blocking off, test mode on, a door reopened, a
    // shorter block list).
    $was_live  = 'off' !== $was['mode'] && !empty(devdcoun_active_countries($was));
    $next_live = 'off' !== $next['mode'] && !empty(devdcoun_active_countries($next));
    $a = devdcoun_active_countries($was);
    $b = devdcoun_active_countries($next);
    sort($a);
    sort($b);
    $changes_who = $was['mode'] !== $next['mode'] || $a !== $b
        || (int) $was['test_mode'] !== (int) $next['test_mode']
        || (int) $was['protect_login'] !== (int) $next['protect_login']
        || (int) $was['protect_xmlrpc'] !== (int) $next['protect_xmlrpc']
        || (int) $was['skip_logged_in'] !== (int) $next['skip_logged_in']
        || (int) $was['allow_search'] !== (int) $next['allow_search']
        || (int) $was['trust_proxy'] !== (int) $next['trust_proxy']
        || array_values((array) $was['trusted_proxies']) !== array_values((array) $next['trusted_proxies']) // decides whose address (and country) counts
        || array_values((array) $was['ip_allowlist']) !== array_values((array) $next['ip_allowlist']); // 0.0.0.0/0 on the allowlist switches blocking off
    if (($was_live || $next_live) && $changes_who && !$confirmed) {
        return new WP_Error('devdcoun_confirm_required', __('This changes who is blocked on a site where blocking is live (or turns it on or off). Pass confirm: true after the site owner agreed.', 'devdome-country-blocker'));
    }
    $next_list  = devdcoun_active_countries($next);
    $was_list   = $was['mode'] === $next['mode'] ? devdcoun_active_countries($was) : array(); // a mode switch makes the other list active as a whole
    $turns_on   = $was['mode'] === 'off' && $next['mode'] !== 'off' && !empty($next_list);
    $to_allow   = $next['mode'] === 'allow' && $was['mode'] !== 'allow';
    $grows_list = $next['mode'] !== 'off' && count(array_diff($next_list, $was_list)) > 0;
    if (($turns_on || $to_allow || ($grows_list && $next['mode'] === 'allow' ? false : $grows_list)) && (!isset($input['confirm']) || true !== $input['confirm'])) {
        return new WP_Error('devdcoun_confirm_required', $to_allow
            ? __('Allow-only mode blocks every visitor from a country that is not on the list. Pass confirm: true after the site owner agreed.', 'devdome-country-blocker')
            : __('This change blocks real visitors from now on. Pass confirm: true after the site owner agreed.', 'devdome-country-blocker'));
    }
    $saved = devdcoun_save_settings($patch);
    if ($saved) {
        devdcoun_refresh_summary();
    }
    $now = devdcoun_ability_settings();
    $not_applied = array();
    foreach (array_keys($patch) as $k) {
        $want = $next[$k];
        $have = devdcoun_settings()[$k];
        if (is_array($want)) {
            sort($want);
            $have = (array) $have;
            sort($have);
        }
        if (devdcoun_option_norm($want) !== devdcoun_option_norm($have)) {
            $not_applied[] = $k;
        }
    }
    return array('updated' => $saved && empty($not_applied), 'not_applied' => $not_applied, 'settings' => $now);
}

function devdcoun_ability_get_statistics($input = array())
{
    $input = is_array($input) ? $input : array();
    $limit = isset($input['limit']) ? (int) $input['limit'] : 50;
    $limit = max(1, min(200, $limit));
    $names = devdcoun_countries();
    $top   = devdcoun_top_countries(20);
    $hits  = devdcoun_recent_hits($limit);
    if (null === $top || null === $hits) {
        return new WP_Error('devdcoun_db_read', __('The statistics could not be read from the database; try again.', 'devdome-country-blocker'));
    }
    $sum = devdcoun_summary();
    $top_out = array();
    foreach ($top as $r) {
        $cc = (string) $r['country'];
        $top_out[] = array('code' => $cc, 'name' => isset($names[$cc]) ? $names[$cc] : $cc, 'blocked' => (int) $r['n']);
    }
    $hits_out = array();
    foreach ($hits as $r) {
        $cc = (string) $r['country'];
        $hits_out[] = array('time' => (string) $r['created_at'], 'code' => $cc, 'name' => isset($names[$cc]) ? $names[$cc] : $cc, 'result' => !empty($r['test']) ? 'test' : 'blocked', 'address' => (string) $r['ip_display'], 'path' => (string) $r['path']);
    }
    return array(
        'blocked_today'  => (int) $sum['blocked_today'],
        'blocked_7d'     => (int) $sum['blocked_7d'],
        'blocked_30d'    => (int) $sum['blocked_30d'],
        'top_countries'  => $top_out,
        'recent_hits'    => $hits_out,
    );
}

/** The country the local database gives for an address, and what the current settings would do with it. */
function devdcoun_ability_lookup_country($input = array())
{
    $input = is_array($input) ? $input : array();
    $ip    = isset($input['ip']) && is_string($input['ip']) ? trim($input['ip']) : '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return new WP_Error('devdcoun_invalid_input', __('ip must be a valid IPv4 or IPv6 address.', 'devdome-country-blocker'));
    }
    $source = '';
    $cc     = '';
    if (devdcoun_db_ready()) {
        $cc     = devdcoun_db_lookup($ip);
        $source = 'database';
    }
    $names = devdcoun_countries();
    $s     = devdcoun_settings();
    $verdict = 'unknown';
    $would   = false;
    if ($s['mode'] === 'off' || empty(devdcoun_active_countries($s))) {
        $verdict = 'off'; // an empty list blocks nobody, exactly like the enforcement
    } elseif ('' !== $cc) {
        $listed  = in_array($cc, devdcoun_active_countries($s), true);
        $verdict = (($s['mode'] === 'block') ? $listed : !$listed) ? 'blocked' : 'allowed';
    }
    if (!empty($s['ip_allowlist']) && devdcoun_ip_in_list($ip, (array) $s['ip_allowlist'])) {
        $verdict = 'allowlisted';
    }
    $disabled = defined('DEVDCOUN_DISABLE') && DEVDCOUN_DISABLE;
    if ($disabled) {
        $verdict = 'off'; // the wp-config.php recovery switch: nothing is enforced
    }
    if ('blocked' === $verdict && !empty($s['test_mode'])) {
        $verdict = 'allowed'; // test mode blocks nobody
        $would   = true;
    }
    return array(
        'address'  => devdcoun_anonymize_ip($ip),
        'code'     => $cc,
        'name'     => '' !== $cc ? (isset($names[$cc]) ? $names[$cc] : $cc) : '',
        'source'   => $source,
        'verdict'  => $verdict,
        'note'     => $disabled ? __('DEVDCOUN_DISABLE is set in wp-config.php: all blocking is switched off, whatever the settings say.', 'devdome-country-blocker') : ($would ? __('Test mode is on: this visitor would be blocked, but nobody is blocked while it is on.', 'devdome-country-blocker') : ('' === $cc ? ('database' === $source ? __('The local database has no country for this address; unknown is always allowed.', 'devdome-country-blocker') : __('No local database installed: a live visitor would be resolved through the Cloudflare or proxy header only.', 'devdome-country-blocker')) : '')),
    );
}

function devdcoun_ability_update_database($input = array())
{
    $ok = devdcoun_ability_confirmed($input, __('This downloads about 10 MB from download.db-ip.com and rebuilds the local database (30 to 60 seconds).', 'devdome-country-blocker'));
    if (is_wp_error($ok)) {
        return $ok;
    }
    list($built, $msg) = devdcoun_db_build();
    if (!$built) {
        return new WP_Error('devdcoun_db_build_failed', (string) $msg);
    }
    $meta = devdcoun_db_meta();
    return array('built' => true, 'month' => isset($meta['month']) ? (string) $meta['month'] : '', 'ranges' => isset($meta['records']) ? (int) $meta['records'] : 0, 'message' => (string) $msg);
}

function devdcoun_ability_refresh_summary($input = array())
{
    if (!devdcoun_refresh_summary()) {
        return new WP_Error('devdcoun_write_failed', __('The summary was recomputed but could not be stored: the database did not keep it. The previous numbers stand.', 'devdome-country-blocker'));
    }
    return devdcoun_ability_get_status();
}

function devdcoun_ability_clear_statistics($input = array())
{
    $ok = devdcoun_ability_confirmed($input, __('This permanently deletes every daily counter and every logged hit.', 'devdome-country-blocker'));
    if (is_wp_error($ok)) {
        return $ok;
    }
    $n = devdcoun_clear_statistics();
    if (false === $n) {
        return new WP_Error('devdcoun_write_failed', __('The statistics could not be cleared: a delete did not go through. Nothing is reported as cleared.', 'devdome-country-blocker'));
    }
    $fresh = devdcoun_refresh_summary();
    return array('cleared' => true, 'rows_removed' => (int) $n, 'summary_refreshed' => (bool) $fresh);
}

/* ------------------------------ registration ------------------------------ */

add_action('wp_abilities_api_init', 'devdcoun_register_abilities');
function devdcoun_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    $empty = array('type' => 'object', 'properties' => array(), 'additionalProperties' => false);
    $bool  = function ($desc) { return array('type' => 'boolean', 'description' => $desc); };
    $str   = function ($desc) { return array('type' => 'string', 'description' => $desc); };
    $int   = function ($desc) { return array('type' => 'integer', 'description' => $desc); };
    $list  = function ($desc) { return array('type' => 'array', 'items' => array('type' => 'string'), 'description' => $desc); };

    $settings_props = array(
        'mode'           => array('type' => 'string', 'enum' => array('off', 'block', 'allow'), 'description' => 'off = nothing is blocked; block = visitors from the listed countries are blocked; allow = only the listed countries may visit, everyone else is blocked.'),
        'block_countries' => $list('Block mode list: ISO 3166-1 alpha-2 codes (DE, US, ...) whose visitors are blocked. Block mode and allow mode keep separate lists.'),
        'allow_countries' => $list('Allow mode list: the only countries whose visitors may enter in allow mode. Block mode and allow mode keep separate lists.'),
        'action'         => array('type' => 'string', 'enum' => array('403', 'redirect'), 'description' => '403 = a plain page with the message; redirect = a 302 to redirect_url (never to this site itself).'),
        'message'        => $str('Text of the 403 page.'),
        'redirect_url'   => $str('http(s) URL for action redirect; empty to clear.'),
        'skip_logged_in' => $bool('Never block logged-in users on the site (wp-admin is never blocked; the login page only with protect_login).'),
        'allow_search'   => $bool('Never block Google, Bing, DuckDuckGo and Apple crawlers or the DevDome health monitor (user-agent match).'),
        'trust_proxy'    => $bool('Trust the CloudFront and Vercel country headers (only behind those platforms).'),
        'test_mode'      => $bool('Block nobody; only log the requests that would have been blocked.'),
        'protect_login'  => $bool('Also enforce on wp-login.php. Needs confirm: true to turn on. The allowlist still gets in.'),
        'protect_xmlrpc' => $bool('Also enforce on xmlrpc.php. Needs confirm: true to turn on.'),
        'ip_allowlist'   => $list('Addresses or ranges (CIDR), IPv4 or IPv6, that are always allowed.'),
        'trusted_proxies' => $list('Addresses or ranges (CIDR) of the site\'s OWN reverse proxies or load balancers. Only a request that connects from one of them has its X-Forwarded-For header believed. Leave empty unless the site sits behind such a proxy; Cloudflare needs no entry.'),
    );
    $settings_out = array('type' => 'object', 'properties' => $settings_props);
    $country = array('type' => 'object', 'properties' => array('code' => array('type' => 'string'), 'name' => array('type' => 'string')));
    $status_out = array('type' => 'object', 'properties' => array(
        'mode' => array('type' => 'string'), 'active' => $bool('true only when visitors are actually blocked: a mode is on, the list is not empty, Test Mode is off, DEVDCOUN_DISABLE is not set and a country source is available.'), 'test_mode' => $bool('true when Test Mode is on: nothing is blocked, hits are only logged.'), 'disabled_by_constant' => $bool('true when DEVDCOUN_DISABLE in wp-config.php switches all blocking off.'), 'countries' => array('type' => 'array', 'items' => $country), 'action' => array('type' => 'string'),
        'source_available' => $bool('true when at least one country source works (Cloudflare header, trusted proxy header or the local database).'),
        'database_installed' => $bool(''), 'database_month' => array('type' => 'string'), 'database_ranges' => array('type' => 'integer'), 'database_last_error' => array('type' => 'string'),
        'blocked_today' => array('type' => 'integer'), 'blocked_7d' => array('type' => 'integer'), 'blocked_30d' => array('type' => 'integer'), 'summary_updated_at' => array('type' => 'integer'), 'plugin_version' => array('type' => 'string'),
    ));

    // Every answer passes the guard (DESIGN.md 24: a failed query = a database error, never "done") and the sanitizer.
    $guarded = function ($cb) {
        return function ($input = array()) use ($cb) {
            devdcoun_db_guard_begin();
            try {
                $r = call_user_func($cb, $input);
                if (devdcoun_db_guard_active()) {
                    $r = new WP_Error('devdcoun_db_error', devdcoun_db_guard_message());
                }
            } finally {
                devdcoun_db_guard_end();
            }
            return devdcoun_ability_strip($r);
        };
    };
    $reg = function ($id, $label, $desc, $in, $out, $cb, $kind, $idempotent = null) use ($guarded) {
        wp_register_ability($id, array(
            'label'               => $label,
            'description'         => $desc,
            'category'            => 'devdome-country-blocker',
            'input_schema'        => $in,
            'output_schema'       => $out,
            'execute_callback'    => $guarded($cb),
            'permission_callback' => 'devdcoun_ability_can',
            'meta'                => devdcoun_ability_meta($kind, $idempotent),
        ));
    };

    $reg('devdome-country-blocker/get-status', __('Get Country Blocker status', 'devdome-country-blocker'),
        __('What the blocker is doing right now: the mode, whether it is active, the listed countries with names, the action for blocked visitors, whether a country source is available, the local database state (month, ranges, last error) and the blocked counts for today, 7 and 30 days. Read-only.', 'devdome-country-blocker'),
        $empty, $status_out, 'devdcoun_ability_get_status', 'read');
    $reg('devdome-country-blocker/get-settings', __('Get Country Blocker settings', 'devdome-country-blocker'),
        __('Every setting: mode, the block list and the allow list of countries, action, message, redirect URL, the three safety switches and the always-allowed addresses. Read-only.', 'devdome-country-blocker'),
        $empty, array('type' => 'object', 'properties' => array('settings' => $settings_out)), 'devdcoun_ability_get_settings', 'read');
    $reg('devdome-country-blocker/update-settings', __('Update Country Blocker settings', 'devdome-country-blocker'),
        __('Change any settings; only the keys you pass change, through the same validation and proved write the screen uses. Turning blocking on, switching to allow-only mode or adding countries while blocking is on needs confirm: true (real visitors get blocked from then on). Every value is read back before updated: true is returned; keys that could not be applied are listed in not_applied.', 'devdome-country-blocker'),
        array('type' => 'object', 'properties' => array_merge($settings_props, array('confirm' => $bool('Required (true) when the change starts blocking visitors, switches to allow-only mode or adds countries while blocking is on.'))), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('updated' => $bool(''), 'not_applied' => $list(''), 'settings' => $settings_out)),
        'devdcoun_ability_update_settings', 'modify');
    $reg('devdome-country-blocker/get-statistics', __('Get blocked-visit statistics', 'devdome-country-blocker'),
        __('The Overview numbers: blocked visits today, 7 and 30 days, the top blocked countries of the last 30 days and the most recent hits (time, country, result, anonymized address, path). result is blocked, or test for a Test Mode hit: that visitor was let through. Read-only.', 'devdome-country-blocker'),
        array('type' => 'object', 'properties' => array('limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50, 'description' => 'How many recent hits to return.')), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('blocked_today' => $int(''), 'blocked_7d' => $int(''), 'blocked_30d' => $int(''), 'top_countries' => array('type' => 'array', 'items' => array('type' => 'object')), 'recent_hits' => array('type' => 'array', 'items' => array('type' => 'object')))),
        'devdcoun_ability_get_statistics', 'read');
    $reg('devdome-country-blocker/lookup-country', __('Look up the country of an address', 'devdome-country-blocker'),
        __('The country the local database gives for an IPv4 or IPv6 address and what the current settings would do with a visitor from there (blocked, allowed, allowlisted, unknown, off). Read-only.', 'devdome-country-blocker'),
        array('type' => 'object', 'properties' => array('ip' => $str('An IPv4 or IPv6 address.')), 'required' => array('ip'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('address' => $str('The address, anonymized.'), 'code' => array('type' => 'string'), 'name' => array('type' => 'string'), 'source' => array('type' => 'string'), 'verdict' => array('type' => 'string', 'enum' => array('blocked', 'allowed', 'allowlisted', 'unknown', 'off')), 'note' => array('type' => 'string'))),
        'devdcoun_ability_lookup_country', 'read');
    $reg('devdome-country-blocker/update-database', __('Download or update the local IP database', 'devdome-country-blocker'),
        __('Needs confirm: true. Downloads the free DB-IP country database (about 10 MB, from download.db-ip.com) and compiles the IPv4 and IPv6 ranges into the local lookup files. Takes 30 to 60 seconds. The same action as the Download / Update database button.', 'devdome-country-blocker'),
        array('type' => 'object', 'properties' => array('confirm' => $bool('Must be true: a 10 MB download from db-ip.com starts.')), 'required' => array('confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('built' => $bool(''), 'month' => array('type' => 'string'), 'ranges' => $int(''), 'message' => array('type' => 'string'))),
        'devdcoun_ability_update_database', 'modify', false);
    $reg('devdome-country-blocker/refresh-summary', __('Recompute the Overview numbers', 'devdome-country-blocker'),
        __('Recomputes the cached summary (blocked counts, listed countries, source availability) from the current data and returns the status. Deletes nothing: the retention trim runs in the daily schedule only.', 'devdome-country-blocker'),
        $empty, $status_out, 'devdcoun_ability_refresh_summary', 'modify');
    $reg('devdome-country-blocker/clear-statistics', __('Clear the blocked-visit statistics', 'devdome-country-blocker'),
        __('Needs confirm: true. Deletes every daily counter and every logged hit for good; settings and the downloaded database are not touched. The same action as Clear statistics on the Overview.', 'devdome-country-blocker'),
        array('type' => 'object', 'properties' => array('confirm' => $bool('Must be true: the counters and the log are deleted for good.')), 'required' => array('confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('cleared' => $bool(''), 'rows_removed' => $int(''), 'summary_refreshed' => $bool('false when the rows were deleted but the cached totals could not be recomputed; call refresh-summary.'))),
        'devdcoun_ability_clear_statistics', 'destroy');
}

/** MCP Adapter: list every Country Blocker ability as a tool on the default server. Harmless when the adapter is not installed. */
add_filter('mcp_adapter_default_server_config', 'devdcoun_mcp_default_server_tools');
function devdcoun_mcp_default_server_tools($config)
{
    if (!is_array($config)) {
        return $config;
    }
    $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : array();
    $config['tools'] = array_values(array_unique(array_merge($tools, devdcoun_ability_ids())));
    return $config;
}
