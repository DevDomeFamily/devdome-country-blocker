<?php
/**
 * Settings storage: a single NOT-autoloaded option holding one array. Readers never hit a missing
 * key thanks to the defaults merge; a per-request static cache keeps the front-end hit path to
 * exactly one option read. The save is a proved write (DESIGN.md 24.5): it answers true only when
 * the row holds the saved array afterwards, and callers say "not saved" on false.
 */

defined('ABSPATH') || exit;

function devdcoun_settings_option()
{
    return 'devdcoun_settings';
}

/** Default settings. Every persisted key is listed here so the shape stays stable. */
function devdcoun_defaults()
{
    return array(
        'mode'           => 'off',     // off | block (block listed) | allow (allow ONLY listed)
        'block_countries' => array(),  // block mode list: ISO 3166-1 alpha-2 codes, validated against countries.php
        'allow_countries' => array(),  // allow mode list: each mode keeps its own list (DESIGN.md 28), a mode switch never swaps their meaning
        'action'         => '403',     // 403 | redirect: what a blocked visitor gets
        'message'        => 'Sorry, this website is not available in your region.',
        'redirect_url'   => '',        // 302 target when action = redirect
        'skip_logged_in' => 1,         // never block logged-in users (wp-login is ALWAYS exempt)
        'allow_search'   => 1,         // never block Googlebot / Bingbot / DuckDuckBot / Applebot (user-agent check)
        'trust_proxy'    => 0,         // trust CloudFront-Viewer-Country / X-Vercel-IP-Country (spoofable off-platform, default off)
        'ip_allowlist'   => array(),   // plain IPs + IPv4 / IPv6 CIDR, always allowed
        'trusted_proxies' => array(),  // the site's OWN reverse proxies / load balancers (IPs + CIDR): only from these is X-Forwarded-For believed
        'test_mode'      => 0,         // log who WOULD be blocked, block nobody
        'protect_login'  => 0,         // also enforce on wp-login.php (off by default: lockout safety)
        'protect_xmlrpc' => 0,         // also enforce on xmlrpc.php
    );
}

/** All settings merged over defaults, cached per request. $flush drops the memo after a save. */
function devdcoun_settings($flush = false)
{
    static $cache = null;
    if ($flush) {
        $cache = null;
        return array();
    }
    if ($cache !== null) {
        return $cache;
    }
    $opt = get_option(devdcoun_settings_option());
    if (!is_array($opt)) {
        $opt = array();
    }
    // Pre-release builds stored one shared "countries" list: it becomes the list of the mode it was saved with.
    if (isset($opt['countries']) && is_array($opt['countries']) && !isset($opt['block_countries']) && !isset($opt['allow_countries'])) {
        $opt[(isset($opt['mode']) && 'allow' === $opt['mode']) ? 'allow_countries' : 'block_countries'] = $opt['countries'];
    }
    $cache = array_merge(devdcoun_defaults(), array_intersect_key($opt, devdcoun_defaults()));
    return $cache;
}

/** One setting by key. */
function devdcoun_setting($name, $default = '')
{
    $settings = devdcoun_settings();
    return array_key_exists($name, $settings) ? $settings[$name] : $default;
}

/**
 * Sanitize a partial input array against the current settings: only known keys, every value validated.
 * Pure (no write), so the screen, the abilities and the tests share one allowlist.
 */
function devdcoun_sanitize_settings(array $input, $current = null)
{
    $clean = null === $current ? devdcoun_settings() : array_merge(devdcoun_defaults(), $current);

    if (isset($input['mode'])) {
        $m = (string) $input['mode'];
        $clean['mode'] = in_array($m, array('off', 'block', 'allow'), true) ? $m : 'off';
    }
    foreach (array('block_countries', 'allow_countries') as $list_key) {
        if (isset($input[$list_key]) && is_array($input[$list_key])) {
            $clean[$list_key] = devdcoun_clean_country_codes($input[$list_key]);
        }
    }
    if (isset($input['action'])) {
        $a = (string) $input['action'];
        $clean['action'] = in_array($a, array('403', 'redirect'), true) ? $a : '403';
    }
    if (isset($input['message'])) {
        $clean['message'] = sanitize_textarea_field((string) $input['message']);
    }
    if (isset($input['redirect_url'])) {
        $url = esc_url_raw(trim((string) $input['redirect_url']));
        $clean['redirect_url'] = preg_match('#^https?://#i', $url) ? $url : '';
        // A redirect to this site itself would loop straight back into the blocker: not stored, the screen says so.
        $host = wp_parse_url($clean['redirect_url'], PHP_URL_HOST);
        $own  = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host && $own && strcasecmp($host, $own) === 0) {
            $clean['redirect_url'] = '';
        }
    }
    foreach (array('skip_logged_in', 'allow_search', 'trust_proxy', 'test_mode', 'protect_login', 'protect_xmlrpc') as $flag) {
        if (array_key_exists($flag, $input)) {
            $clean[$flag] = (int) !empty($input[$flag]);
        }
    }
    foreach (array('ip_allowlist', 'trusted_proxies') as $list_key) {
        if (!isset($input[$list_key]) || !is_array($input[$list_key])) {
            continue;
        }
        $list = array();
        foreach ($input[$list_key] as $line) {
            if (!is_scalar($line)) {
                continue;
            }
            $line = trim((string) $line);
            if ('' !== $line && devdcoun_valid_allowlist_entry($line)) {
                $list[] = $line;
            }
        }
        $clean[$list_key] = array_values(array_unique($list));
    }
    return $clean;
}

/** A country list as stored: upper-case ISO 3166-1 alpha-2 codes known to countries.php, unique, in the given order. */
function devdcoun_clean_country_codes(array $list)
{
    $valid = devdcoun_countries();
    $codes = array();
    foreach ($list as $cc) {
        if (!is_scalar($cc)) {
            continue;
        }
        $cc = strtoupper(substr(trim((string) $cc), 0, 2));
        if (isset($valid[$cc])) {
            $codes[] = $cc;
        }
    }
    return array_values(array_unique($codes));
}

/** The list the current mode uses: the block list in block mode, the allow list in allow mode, none when off. */
function devdcoun_active_countries($s = null)
{
    $s = is_array($s) ? $s : devdcoun_settings();
    if (isset($s['mode']) && 'block' === $s['mode']) {
        return isset($s['block_countries']) ? (array) $s['block_countries'] : array();
    }
    if (isset($s['mode']) && 'allow' === $s['mode']) {
        return isset($s['allow_countries']) ? (array) $s['allow_countries'] : array();
    }
    return array();
}

/**
 * Persist settings: validated input over the current values, never autoloaded, PROVED.
 * @return bool true only when the row holds the saved array afterwards.
 */
function devdcoun_save_settings(array $input)
{
    $clean = devdcoun_sanitize_settings($input);
    $ok    = devdcoun_option_write(devdcoun_settings_option(), $clean, false);
    devdcoun_settings(true); // bust the memo so later reads in this request see the saved values
    return $ok;
}

/** True when a line is a valid allowlist entry: plain IPv4/IPv6, or an IPv4 / IPv6 range (CIDR). */
function devdcoun_valid_allowlist_entry($line)
{
    if (filter_var($line, FILTER_VALIDATE_IP)) {
        return true;
    }
    if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/(\d{1,2})$/', $line, $m)) {
        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) $m[2] <= 32;
    }
    if (preg_match('/^([0-9a-f:.]+)\/(\d{1,3})$/i', $line, $m)) {
        return (bool) filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && (int) $m[2] <= 128;
    }
    return false;
}

/** The cached dashboard summary (tiles, hub health and the Overview read ONLY this). */
function devdcoun_summary()
{
    $s = get_option('devdcoun_summary');
    return is_array($s) ? array_merge(array(
        'mode'             => 'off',
        'blocked_today'    => 0,
        'blocked_7d'       => 0,
        'blocked_30d'      => 0,
        'countries_listed' => 0,
        'source_ok'        => false,
        'updated_at'       => 0,
    ), $s) : array(
        'mode'             => 'off',
        'blocked_today'    => 0,
        'blocked_7d'       => 0,
        'blocked_30d'      => 0,
        'countries_listed' => 0,
        'source_ok'        => false,
        'updated_at'       => 0,
    );
}
