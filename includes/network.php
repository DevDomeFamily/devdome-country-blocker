<?php
/**
 * Network rules (25 Oct 2026, owner): block by network number (ASN) and, with one switch, every known
 * data-center / hosting / VPN network. Both are OFF by default and nothing here runs until the owner turns one on.
 *
 *  - The visitor's network comes from the DevDome geo service (https://api.devdome.com/geo-resolve/classify):
 *    one lookup per NEW public address (IPv6 per /64), cached 24 hours, at most 60 live lookups a minute, a
 *    2-second timeout and a 2-minute back-off after a failure. Unknown = allowed, exactly like an unknown country.
 *  - The data-center list is the shared DevDome network list (https://api.devdome.com/bot-protection/asns),
 *    downloaded once a day while the switch is on and kept in one option; nothing about the site is sent.
 *
 * Both calls are listed in readme.txt (External services).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DEVDCOUN_GEO_ENDPOINT')) {
    define('DEVDCOUN_GEO_ENDPOINT', 'https://api.devdome.com/geo-resolve/classify');
}
if (!defined('DEVDCOUN_ASN_FEED')) {
    define('DEVDCOUN_ASN_FEED', 'https://api.devdome.com/bot-protection/asns');
}

/** Is any network rule on? Gates every lookup: no rule, no request. */
function devdcoun_network_rules_active($s = null)
{
    if (null === $s) {
        $s = devdcoun_settings();
    }
    return !empty($s['block_asns']) || !empty($s['block_datacenter']);
}

/** The cache key of one address: IPv6 on its /64 (one lookup per household, not per rotated address). */
function devdcoun_asn_cache_key($ip)
{
    $cache_ip = $ip;
    if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = @inet_pton($ip); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed literal answers false, handled below
        if (false !== $packed && 16 === strlen($packed)) {
            $cache_ip = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
    }
    return 'devdcoun_asn_' . substr(md5($cache_ip), 0, 16);
}

/**
 * The network number of a public address, 0 when unknown. Cached 24 h (unknowns too, so an address is never
 * looked up twice a day), budgeted and fail-open: a slow or down service never delays a page.
 */
function devdcoun_asn($ip)
{
    $ip = (string) $ip;
    if ('' === $ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return 0;
    }
    $key    = devdcoun_asn_cache_key($ip);
    $cached = get_transient($key);
    if (false !== $cached) {
        return (int) $cached;
    }
    if (false !== get_transient('devdcoun_asn_down')) {
        return 0; // the service failed a moment ago: back off, do not pile up timeouts
    }
    $minute = (int) floor(time() / 60);
    $b      = get_transient('devdcoun_asn_budget');
    if (!is_array($b) || !isset($b['m'], $b['n']) || (int) $b['m'] !== $minute) {
        $b = array('m' => $minute, 'n' => 0);
    }
    if ((int) $b['n'] >= 60) {
        return 0;
    }
    $b['n'] = (int) $b['n'] + 1;
    set_transient('devdcoun_asn_budget', $b, 120);
    $started = microtime(true);
    $r = wp_remote_post(DEVDCOUN_GEO_ENDPOINT, array(
        'timeout'    => 2,
        'user-agent' => 'DevDome-Country-Blocker/' . DEVDCOUN_VERSION, // never WordPress's default agent: it names the site
        'headers'    => array('Content-Type' => 'application/json'),
        'body'       => wp_json_encode(array('ips' => array($ip))),
    ));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        set_transient('devdcoun_asn_down', 1, 120);
        return 0;
    }
    if ((microtime(true) - $started) > 1.5) {
        set_transient('devdcoun_asn_down', 1, 60); // a slow answer trips the breaker like an error, for a minute
    }
    $d   = json_decode(wp_remote_retrieve_body($r), true);
    $row = (is_array($d) && isset($d['results'][$ip]) && is_array($d['results'][$ip])) ? $d['results'][$ip] : array();
    $asn = isset($row['asn']) ? max(0, (int) $row['asn']) : 0;
    set_transient($key, $asn, DAY_IN_SECONDS);
    return $asn;
}

/** The stored data-center network map {asn => 1}, empty until the first download. */
function devdcoun_dc_asns()
{
    $o = get_option('devdcoun_dc_asns');
    return (is_array($o) && isset($o['asns']) && is_array($o['asns'])) ? $o['asns'] : array();
}

/** When the stored list was downloaded (0 = never). */
function devdcoun_dc_asns_fetched()
{
    $o = get_option('devdcoun_dc_asns');
    return (is_array($o) && isset($o['fetched'])) ? (int) $o['fetched'] : 0;
}

/**
 * Download the shared network list. Runs from the daily cron and once when the switch is turned on. A failed download
 * keeps the previous copy. Returns true when a fresh list was stored.
 */
function devdcoun_dc_asns_refresh()
{
    if (empty(devdcoun_setting('block_datacenter'))) {
        return false; // the switch is off: nothing is downloaded
    }
    $r = wp_remote_get(DEVDCOUN_ASN_FEED, array('timeout' => 10, 'user-agent' => 'DevDome-Country-Blocker/' . DEVDCOUN_VERSION));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return false;
    }
    $d = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($d) || !isset($d['asns']) || !is_array($d['asns']) || !$d['asns']) {
        return false; // a 200 that is not the list = a failed download, the old copy stays
    }
    $map = array();
    foreach ($d['asns'] as $a) {
        $a = (int) $a;
        if ($a > 0) {
            $map[$a] = 1;
        }
    }
    if (!$map) {
        return false;
    }
    return (bool) update_option('devdcoun_dc_asns', array('asns' => $map, 'fetched' => time()), false);
}

/** The daily download runs only while the switch is on; turning it off drops the list and the schedule. */
function devdcoun_dc_asns_schedule($on)
{
    if ($on) {
        if (!wp_next_scheduled('devdcoun_dc_refresh')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'devdcoun_dc_refresh');
        }
        return;
    }
    wp_clear_scheduled_hook('devdcoun_dc_refresh');
    delete_option('devdcoun_dc_asns');
}
add_action('devdcoun_dc_refresh', 'devdcoun_dc_asns_refresh');

/**
 * Why a network is blocked: 'asn' (on the owner's list), 'datacenter' (on the shared list while the switch is on),
 * '' (not blocked or unknown). Pure given the number; the lookup happens in devdcoun_network_verdict().
 */
function devdcoun_network_reason($asn, $s = null)
{
    $asn = (int) $asn;
    if ($asn <= 0) {
        return '';
    }
    if (null === $s) {
        $s = devdcoun_settings();
    }
    if (!empty($s['block_asns']) && in_array($asn, array_map('intval', (array) $s['block_asns']), true)) {
        return 'asn';
    }
    if (!empty($s['block_datacenter'])) {
        $dc = devdcoun_dc_asns();
        if (isset($dc[$asn])) {
            return 'datacenter';
        }
    }
    return '';
}

/** The verdict for one visitor address (lookup + reason). */
function devdcoun_network_verdict($ip, $s = null)
{
    if (null === $s) {
        $s = devdcoun_settings();
    }
    if (!devdcoun_network_rules_active($s)) {
        return '';
    }
    return devdcoun_network_reason(devdcoun_asn($ip), $s);
}

/** Network numbers as stored: positive integers, unique, in the given order; anything else is dropped. */
function devdcoun_clean_asns(array $list)
{
    $out = array();
    foreach ($list as $v) {
        if (!is_scalar($v)) {
            continue;
        }
        $v = strtoupper(trim((string) $v));
        if ('' === $v) {
            continue;
        }
        if (0 === strpos($v, 'AS')) {
            $v = substr($v, 2); // "AS13335" is how people write it
        }
        if (!preg_match('/^\d{1,10}$/', $v)) {
            continue;
        }
        $n = (int) $v;
        if ($n > 0 && $n <= 4294967295 && !in_array($n, $out, true)) {
            $out[] = $n;
        }
    }
    return $out;
}
