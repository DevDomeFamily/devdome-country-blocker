<?php
/**
 * Country resolution. Source order, most trustworthy first:
 *   1. Cloudflare's CF-IPCountry header, trusted ONLY when REMOTE_ADDR is inside Cloudflare's published
 *      edge ranges (cf-ranges.php), so a direct visitor cannot spoof a country by sending the header.
 *   2. CloudFront-Viewer-Country / X-Vercel-IP-Country, only when the owner explicitly enabled
 *      "trust proxy headers" (their platform, their call).
 *   3. The local DB-IP database (IPv4 and IPv6).
 * Anything else resolves to '' = unknown, and unknown is ALWAYS allowed.
 */

defined('ABSPATH') || exit;

/** True when REMOTE_ADDR is a Cloudflare edge address. */
function devdcoun_behind_cloudflare($reset = false)
{
    static $cached = null;
    if ($reset) {
        $cached = null; // test harness only: a new request in the same process
        return false;
    }
    if ($cached !== null) {
        return $cached;
    }
    $ip = devdcoun_client_ip();
    if ($ip === '') {
        return $cached = false;
    }
    $ranges = devdcoun_cf_ranges();
    $list   = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $ranges['v6'] : $ranges['v4'];
    return $cached = devdcoun_ip_in_list($ip, $list);
}

/**
 * The visitor's own address.
 *   - Behind Cloudflare REMOTE_ADDR is the Cloudflare edge, so the visitor is in CF-Connecting-IP; that header is
 *     trusted ONLY when REMOTE_ADDR is a verified Cloudflare address (same rule as the country header).
 *   - Behind the site's OWN reverse proxy or load balancer (the Trusted Proxies setting) the visitor is in
 *     X-Forwarded-For. The header is believed ONLY when REMOTE_ADDR is one of those proxies, and the chain is walked
 *     from the right past trusted hops (and Cloudflare edges): the first address that is not ours is the visitor, so
 *     whatever a visitor wrote on the left of the header never counts.
 *   - Everywhere else it is REMOTE_ADDR.
 * $proxies: the list to judge with (null = the stored setting); the Save check passes the list as it would be stored.
 */
function devdcoun_visitor_ip($proxies = null)
{
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && devdcoun_behind_cloudflare()) {
        $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    $remote  = devdcoun_client_ip();
    $proxies = null === $proxies ? (array) devdcoun_setting('trusted_proxies', array()) : (array) $proxies;
    if ('' === $remote || empty($proxies) || !isset($_SERVER['HTTP_X_FORWARDED_FOR']) || !devdcoun_ip_in_list($remote, $proxies)) {
        return $remote;
    }
    $cf    = devdcoun_cf_ranges();
    $chain = array_reverse(array_map('trim', explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])))));
    $last  = $remote;
    foreach ($chain as $hop) {
        if (!filter_var($hop, FILTER_VALIDATE_IP)) {
            break; // junk in the chain: nothing to its left is believed
        }
        $last = $hop;
        $ours = devdcoun_ip_in_list($hop, $proxies) || devdcoun_ip_in_list($hop, filter_var($hop, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $cf['v6'] : $cf['v4']);
        if (!$ours) {
            return $hop;
        }
    }
    return $last;
}

/** True for a loopback, private or link-local address: a connecting address like that is a proxy, never a visitor. */
function devdcoun_ip_is_private($ip)
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * The visitor's country as array(code, source). Source is one of
 * 'cloudflare' | 'proxy-header' | 'database' | '' (unknown). Cached per request.
 */
function devdcoun_country($reset = false)
{
    static $cached = null;
    if ($reset) {
        $cached = null; // test harness only
        return array('', '');
    }
    if ($cached !== null) {
        return $cached;
    }

    // 1. Cloudflare, verified by the connecting address.
    if (isset($_SERVER['HTTP_CF_IPCOUNTRY']) && devdcoun_behind_cloudflare()) {
        $cc = strtoupper(substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])), 0, 2));
        // XX = unknown, T1 = Tor (no country claim): both are unknown.
        if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' && $cc !== 'T1') {
            return $cached = array($cc, 'cloudflare');
        }
        return $cached = array('', 'cloudflare');
    }

    // 2. Other CDN headers, opt-in only (spoofable when the platform is not in front).
    if (devdcoun_setting('trust_proxy')) {
        foreach (array('HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY') as $h) {
            if (isset($_SERVER[$h])) {
                $cc = strtoupper(substr(sanitize_text_field(wp_unslash($_SERVER[$h])), 0, 2));
                // XX / T1 = the platform could not tell: unknown, never a country.
                if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' && $cc !== 'T1') {
                    return $cached = array($cc, 'proxy-header');
                }
            }
        }
    }

    // 3. Local database.
    if (devdcoun_db_ready()) {
        $cc = devdcoun_db_lookup(devdcoun_visitor_ip());
        if ($cc !== '') {
            return $cached = array($cc, 'database');
        }
        return $cached = array('', 'database');
    }

    return $cached = array('', '');
}

/** True when at least one detection source can currently produce a country. */
function devdcoun_source_available()
{
    if (devdcoun_db_ready()) {
        return true;
    }
    if (isset($_SERVER['HTTP_CF_IPCOUNTRY']) && devdcoun_behind_cloudflare()) {
        return true;
    }
    if (devdcoun_setting('trust_proxy')
        && (isset($_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY']) || isset($_SERVER['HTTP_X_VERCEL_IP_COUNTRY']))) {
        return true;
    }
    return false;
}

/** Human label for a source key (status line + agent answers). */
function devdcoun_source_label($source)
{
    $labels = array(
        'cloudflare'   => __('Cloudflare header', 'devdome-country-blocker'),
        'proxy-header' => __('proxy header', 'devdome-country-blocker'),
        'database'     => __('local database', 'devdome-country-blocker'),
    );
    return isset($labels[$source]) ? $labels[$source] : __('no source', 'devdome-country-blocker');
}
