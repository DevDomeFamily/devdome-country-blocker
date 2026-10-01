<?php
/**
 * devdome-core — shared bot-DETECTION library (the minimal, unified "is this an
 * OBVIOUS bot" layer used by every DevDome plugin). Deliberately small:
 *   - one feed-sync of /list + /asns + /drop from api.devdome.com (one cron, one cache)
 *   - three matchers: ua_is_bot / is_datacenter_asn / ip_in_drop
 *
 * Everything advanced (IP-verified anti-spoof allowlist, behavioral blocking,
 * reputation, enforcement, dashboard) stays in the bot-protection PRODUCT — NOT here.
 *
 * This file is loaded ONCE per request for the highest version present across all
 * installed DevDome plugins (see loader.php). All symbols are guarded so a second
 * (older) copy is a harmless no-op. The byte-wise CIDR math is correct on 32- and
 * 64-bit PHP (no ip2long overflow, no float deprecation).
 */

defined('ABSPATH') || exit;

if (defined('DEVDCOREV1_LOADED')) {
    return; // a higher/equal version already loaded the library
}
define('DEVDCOREV1_LOADED', true);
define('DEVDCOREV1_VERSION', '1.7.10');

if (!defined('DEVDCOREV1_FEED_ENDPOINT')) {
    define('DEVDCOREV1_FEED_ENDPOINT', 'https://api.devdome.com/bot-protection');
}

/*
 * EVERY DECLARATION BELOW IS CONDITIONAL, ON PURPOSE (audit 2, batch 1).
 *
 * The constant guard above cannot prevent a redeclaration: PHP binds
 * unconditional top-level functions when this file is COMPILED, before the
 * guard line ever executes. A site running an older DevDome plugin whose
 * loader predates the shared-registry fix can therefore still include a second
 * copy of this library from a different path — and that used to be a fatal
 * `Cannot redeclare` fatal (the feeds function) that took down wp-admin.
 *
 * Declaring inside function_exists() moves the binding to RUNTIME, so a second
 * include is a harmless no-op no matter which loader performed it. This is the
 * defence that does not depend on what is already installed elsewhere.
 */
if (!function_exists('devdcorev1_get_feeds')) {
    /* ----------------------------- shared cache ----------------------------- */

    /** The synced feeds, read once per request from the shared option. */
    function devdcorev1_get_feeds()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $f = get_option('devdcorev1_feeds', array());
        $cache = is_array($f) ? $f : array();
        return $cache;
    }

    /* ------------------------------ feed sync ------------------------------- */

    /** Daily cron: fetch /list + /asns + /drop, build the DROP index, cache. A failed feed keeps the old one. */
    function devdcorev1_refresh_feeds()
    {
        if (devdcorev1_wporg_feeds_blocked()) {
            return;
        }
        $feeds = get_option('devdcorev1_feeds', array());
        if (!is_array($feeds)) {
            $feeds = array();
        }

        // /list — verified-bot UA patterns (lowercased substrings; these are the "good bot" names too)
        $r = wp_remote_get(DEVDCOREV1_FEED_ENDPOINT . '/list', array('timeout' => 10));
        if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            if (is_array($d) && !empty($d['patterns']) && is_array($d['patterns'])) {
                $feeds['patterns'] = array_values(array_filter(array_map(function ($p) {
                    return strtolower(trim((string) $p));
                }, $d['patterns'])));
            }
        }

        // /asns — datacenter ASNs as an O(1) lookup map {asn:1}
        $r2 = wp_remote_get(DEVDCOREV1_FEED_ENDPOINT . '/asns', array('timeout' => 10));
        if (!is_wp_error($r2) && (int) wp_remote_retrieve_response_code($r2) === 200) {
            $d2 = json_decode(wp_remote_retrieve_body($r2), true);
            if (is_array($d2) && !empty($d2['asns']) && is_array($d2['asns'])) {
                $map = array();
                foreach ($d2['asns'] as $a) {
                    $map[(int) $a] = 1;
                }
                $feeds['asns'] = $map;
            }
        }

        // /drop — Spamhaus CIDRs; build the searchable index now (once), not per request.
        $r3 = wp_remote_get(DEVDCOREV1_FEED_ENDPOINT . '/drop', array('timeout' => 10));
        if (!is_wp_error($r3) && (int) wp_remote_retrieve_response_code($r3) === 200) {
            $d3 = json_decode(wp_remote_retrieve_body($r3), true);
            if (is_array($d3) && !empty($d3['cidrs']) && is_array($d3['cidrs'])) {
                $idx = devdcorev1_build_cidr_index(array_values(array_filter(array_map('trim', $d3['cidrs']))));
                $feeds['drop_v4'] = $idx['v4']; // sorted [base64(start),base64(end)] packed pairs
                $feeds['drop_v6'] = $idx['v6']; // [base64(packed prefix), bits]
            }
        }

        $feeds['fetched'] = time();
        update_option('devdcorev1_feeds', $feeds, false);

        return $feeds;
    }
    add_action('devdcorev1_refresh_feeds', 'devdcorev1_refresh_feeds');

    /**
     * Self-sufficient scheduling (core is a library, not a plugin — it can't use an
     * activation hook). Must work on EVERY install, including DISABLE_WP_CRON and
     * broken-cron sites, without ever slowing a front-end visitor:
     *   - the daily cron event stays the primary refresh path
     *   - empty feed on an admin request -> fetch inline NOW (instant protection;
     *     one admin pageload pays ~1s once, visitors never do)
     *   - empty feed on the front end, or a stale feed anywhere -> make the refresh
     *     due and fire a NON-BLOCKING loopback to wp-cron.php. That endpoint runs
     *     even when DISABLE_WP_CRON is set (the constant only stops WP's automatic
     *     spawn), so the refresh self-heals on real traffic with no cron at all.
     * Transient-locked to one attempt per 10 minutes so nothing can stampede.
     */

    /**
     * WordPress.org builds must not call home before the user opts in: feeds stay
     * off until the suite is connected (Guideline 7). Fleet/self-hosted builds
     * (no DEVDCOREV1_WPORG_BUILD constant) are unaffected.
     */
    function devdcorev1_wporg_feeds_blocked()
    {
        // WP.org builds NEVER sync the bot feeds — no plugin in that distribution consumes
        // them, and the readme discloses account-connect as email-alerts only.
        return defined('DEVDCOREV1_WPORG_BUILD') && DEVDCOREV1_WPORG_BUILD;
    }
    function devdcorev1_maybe_schedule()
    {
        if (devdcorev1_wporg_feeds_blocked()) {
            return;
        }
        if (!wp_next_scheduled('devdcorev1_refresh_feeds')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', 'devdcorev1_refresh_feeds');
        }

        $feeds = devdcorev1_get_feeds();
        $empty = empty($feeds['patterns']);
        $stale = !$empty && time() - (int) ($feeds['fetched'] ?? 0) > DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS;
        if (!$empty && !$stale) {
            return;
        }
        if (get_transient('devdcorev1_feed_init') !== false) {
            return; // an attempt ran in the last 10 minutes
        }
        set_transient('devdcorev1_feed_init', 1, 10 * MINUTE_IN_SECONDS);

        if ($empty && is_admin() && !wp_doing_ajax() && !wp_doing_cron()) {
            devdcorev1_refresh_feeds(); // first fill: synchronous, admin pageloads only
            return;
        }

        // Make the refresh due now, then spawn the cron runner ourselves (same request
        // shape as core's spawn_cron and hosting crontabs: non-blocking, ~0 added latency).
        wp_schedule_single_event(time() - 1, 'devdcorev1_refresh_feeds');
        wp_remote_post(site_url('wp-cron.php'), array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, not a plugin hook.
        ));
    }
    add_action('init', 'devdcorev1_maybe_schedule');

    /* ------------------------------- matchers ------------------------------- */

    /** Does the UA match a known verified-bot pattern? (Lowercased substring — mirrors the suite-wide rule.) */
    function devdcorev1_ua_is_bot($ua, $feeds = null)
    {
        $ua = strtolower(trim((string) $ua));
        if ($ua === '') {
            return false;
        }
        if ($feeds === null) {
            $feeds = devdcorev1_get_feeds();
        }
        if (empty($feeds['patterns']) || !is_array($feeds['patterns'])) {
            return false;
        }
        foreach ($feeds['patterns'] as $p) {
            if ($p !== '' && strpos($ua, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Is this ASN a known datacenter/hosting ASN? */
    function devdcorev1_is_datacenter_asn($asn, $feeds = null)
    {
        $asn = (int) $asn;
        if (!$asn) {
            return false;
        }
        if ($feeds === null) {
            $feeds = devdcorev1_get_feeds();
        }
        return !empty($feeds['asns']) && is_array($feeds['asns']) && isset($feeds['asns'][$asn]);
    }

    /** Is the IP inside a Spamhaus DROP range? O(log n) for IPv4; prefix-compare for IPv6. */
    function devdcorev1_ip_in_drop($ip, $feeds = null)
    {
        if ($feeds === null) {
            $feeds = devdcorev1_get_feeds();
        }
        return devdcorev1_ip_in_cidr_index(
            $ip,
            isset($feeds['drop_v4']) ? $feeds['drop_v4'] : array(),
            isset($feeds['drop_v6']) ? $feeds['drop_v6'] : array()
        );
    }

    /* ------------------------- shared behavioral beacon --------------------- */

    /** Enqueue the shared beacon on front-end pages (skip admin / logged-in / opt-out filter). */
    function devdcorev1_beacon_enqueue()
    {
        if (is_admin() || is_user_logged_in()) {
            return;
        }
        if (!has_action('devdcorev1_beacon')) {
            return; // nothing consumes the beacon on this site — don't fire a useless one
        }
        if (!apply_filters('devdcorev1_beacon_enabled', true)) {
            return;
        }
        wp_enqueue_script(
            'devdcorev1-beacon',
            plugins_url('beacon.js', __FILE__),
            array(),
            DEVDCOREV1_VERSION,
            true
        );
        // Do NOT localize a per-request value (e.g. REQUEST_URI) here: this inline script is combined and
        // content-hashed by page-cache optimizers (LiteSpeed "combine inline JS"), so a per-URL value mints
        // a brand-new cached bundle for every URL and balloons disk usage. The path is read client-side in
        // beacon.js instead, keeping this inline script identical on every page.
        wp_localize_script('devdcorev1-beacon', 'DEVDCOREV1_BEACON', array(
            'url' => esc_url_raw(rest_url('devdome-core/v1/beacon')),
        ));
    }
    add_action('wp_enqueue_scripts', 'devdcorev1_beacon_enqueue');

    /** Register the public, anonymous beacon endpoint (only when something consumes it). */
    function devdcorev1_beacon_rest_init()
    {
        if (!has_action('devdcorev1_beacon')) {
            return; // no listener on this site — never expose a useless public endpoint
        }
        if (!apply_filters('devdcorev1_beacon_enabled', true)) {
            return; // same switch as the script enqueue
        }
        $flag = array(
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'validate_callback' => static function ($v) { return in_array((string) $v, array('0', '1', ''), true) || true === $v || false === $v; },
        );
        register_rest_route('devdome-core/v1', '/beacon', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'devdcorev1_beacon_receive',
            // Intentionally public: an anonymous front-end visitor beacon, not an administrative
            // command (nothing is read back; core stores only a short-lived per-IP rate counter).
            // Registered only when a DevDome plugin subscribes to devdcorev1_beacon; Admin Cleaner
            // registers no consumer, so on its own this route does not exist. Inputs are
            // schema-validated here and re-checked in the callback; per-IP flood guard inside.
            'permission_callback' => '__return_true',
            'args'                => array(
                'sid'  => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static function ($v) { return is_string($v) && 1 === preg_match('/^[a-f0-9]{16,64}$/D', $v); },
                ),
                'path' => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static function ($v) { return is_string($v) && strlen($v) <= 2000; },
                ),
                'c' => $flag, 's' => $flag, 'm' => $flag, 't' => $flag, 'f' => $flag, 'wd' => $flag,
            ),
        ));
    }
    add_action('rest_api_init', 'devdcorev1_beacon_rest_init');

    /** Client IP for the beacon (Cloudflare-aware). */
    function devdcorev1_request_ip()
    {
        // CF-Connecting-IP is believed only when a plugin vouches that the peer is Cloudflare (the bot-protection
        // plugin checks REMOTE_ADDR against the published ranges); otherwise anyone could name a victim address.
        $trust_cf = (bool) apply_filters('devdcorev1_trust_cf_ip', false);
        foreach ($trust_cf ? array('HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR') : array('REMOTE_ADDR') as $h) {
            if (!empty($_SERVER[$h])) {
                // FILTER_VALIDATE_IP is the real sanitizer here: anything that is not a literal
                // IP is discarded. sanitize_text_field() runs first so the read is unambiguous.
                $ip = filter_var(trim(sanitize_text_field(wp_unslash($_SERVER[$h]))), FILTER_VALIDATE_IP);
                if (false !== $ip) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /**
     * Receive one beacon: parse + classify, then re-broadcast to listeners via the
     * `devdcorev1_beacon` action. Core stores NOTHING — each plugin decides what to do with it
     * (e.g. bot-protection upserts a session row + flags sustained automated browsers). A light
     * per-IP/minute flood guard keeps a script from hammering the endpoint.
     */
    function devdcorev1_beacon_receive(WP_REST_Request $req)
    {
        $sid = preg_replace('/[^a-f0-9]/', '', (string) $req->get_param('sid'));
        if (strlen($sid) < 16 || strlen($sid) > 64) {
            return new WP_REST_Response(array('ok' => false), 400);
        }

        $ip = devdcorev1_request_ip();
        // Flood-guard bucket: key on the TRANSPORT peer (REMOTE_ADDR), never on the
        // client-supplied CF header — otherwise an attacker rotates the header to mint a
        // fresh rate-limit bucket (two wp_options rows) per request. Sites strictly locked
        // to Cloudflare origin may opt in to the CF header via DEVDCOREV1_TRUST_CF_IP.
        // FILTER_VALIDATE_IP sanitizes the transport peer; a non-IP value (impossible from a real
        // TCP peer) collapses into the shared '' bucket, which is still rate-limited.
        $bucket_ip = (defined('DEVDCOREV1_TRUST_CF_IP') && DEVDCOREV1_TRUST_CF_IP)
            ? $ip
            : (string) filter_var(isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '', FILTER_VALIDATE_IP);
        $key = 'devdcorev1_beacon_rate_' . md5($bucket_ip) . '_' . (int) floor(time() / 60);
        $n = (int) get_transient($key);
        if ($n >= 120) {
            return new WP_REST_Response(array('ok' => true), 202); // accepted, dropped
        }
        set_transient($key, $n + 1, 120);

        $flag = static function ($k) use ($req) { return $req->get_param($k) ? 1 : 0; };
        $cookie     = $flag('c');
        $interacted = ($flag('s') || $flag('m') || $flag('t') || $flag('f'));
        $wd         = $flag('wd');
        $class      = $wd ? 'automated' : (($cookie && $interacted) ? 'human' : 'unconfirmed');

        do_action('devdcorev1_beacon', array(
            'sid'        => $sid,
            'ip'         => $ip,
            'ua'         => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'cookie'     => $cookie,
            'scroll'     => $flag('s'),
            'mouse'      => $flag('m'),
            'touch'      => $flag('t'),
            'form'       => $flag('f'),
            'webdriver'  => $wd,
            'interacted' => $interacted ? 1 : 0,
            'class'      => $class,
            'path'       => substr(sanitize_text_field((string) $req->get_param('path')), 0, 2000), // bounded, no control bytes (DeepSeek core round 1)
        ));

        return new WP_REST_Response(array('ok' => true), 200);
    }

    /* -------------------------- CIDR index + math --------------------------- */

    /**
     * Turn a list of CIDRs into a sorted IPv4 [start,end] index + an IPv6 prefix list.
     * Ranges are stored as base64'd PACKED bytes and compared with binary-safe strcmp
     * (unsigned big-endian) — no 32-bit integer overflow, works on any PHP build.
     */
    function devdcorev1_build_cidr_index($cidrs)
    {
        $v4 = array();
        $v6 = array();
        foreach ($cidrs as $cidr) {
            if (strpos($cidr, '/') === false) {
                continue;
            }
            list($net, $bits) = explode('/', $cidr, 2);
            $bits = (int) $bits;
            $packed = @inet_pton($net);
            if ($packed === false) {
                continue;
            }
            $len = strlen($packed);
            if ($len === 4) { // IPv4
                if ($bits < 0 || $bits > 32) { continue; }
                $mask = devdcorev1_cidr_mask($bits, 4);
                $start = $packed & $mask;       // byte-wise AND (string operands)
                $end = $start | (~$mask);       // byte-wise OR with inverted mask
                $v4[] = array(base64_encode($start), base64_encode($end));
            } elseif ($len === 16) { // IPv6
                if ($bits < 0 || $bits > 128) { continue; }
                $v6[] = array(base64_encode($packed), $bits);
            }
        }
        usort($v4, function ($a, $b) { return strcmp(base64_decode($a[0]), base64_decode($b[0])); });
        // Overlapping or adjacent ranges are merged (DeepSeek core round 1): the binary search below assumes disjoint
        // intervals, and a feed with 10.0.0.0/8 followed by 10.1.0.0/16 used to hide 10.5.0.0 behind the narrower entry.
        $merged = array();
        foreach ($v4 as $r) {
            $n = count($merged);
            if ($n > 0 && strcmp(base64_decode($r[0]), base64_decode($merged[$n - 1][1])) <= 0) {
                if (strcmp(base64_decode($r[1]), base64_decode($merged[$n - 1][1])) > 0) {
                    $merged[$n - 1][1] = $r[1];
                }
                continue;
            }
            $merged[] = $r;
        }
        return array('v4' => $merged, 'v6' => $v6);
    }

    /** Build a packed network mask of $bytes bytes for a /$bits prefix. */
    function devdcorev1_cidr_mask($bits, $bytes)
    {
        $m = '';
        for ($i = 0; $i < $bytes; $i++) {
            if ($bits >= 8) {
                $m .= chr(0xFF); $bits -= 8;
            } elseif ($bits <= 0) {
                $m .= chr(0x00);
            } else {
                $m .= chr((0xFF << (8 - $bits)) & 0xFF); $bits = 0;
            }
        }
        return $m;
    }

    /** Core membership test: is $ip inside the prebuilt [$v4 sorted [start,end] index, $v6 prefix list]? */
    function devdcorev1_ip_in_cidr_index($ip, $v4, $v6)
    {
        if ($ip === '' || $ip === null) {
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) { // IPv4 — binary search the sorted [start,end] index
            if (empty($v4) || !is_array($v4)) {
                return false;
            }
            $lo = 0;
            $hi = count($v4) - 1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                if (strcmp($packed, base64_decode($v4[$mid][0])) < 0) {
                    $hi = $mid - 1;
                } elseif (strcmp($packed, base64_decode($v4[$mid][1])) > 0) {
                    $lo = $mid + 1;
                } else {
                    return true; // start <= ip <= end (unsigned big-endian bytes)
                }
            }
            return false;
        }

        if (strlen($packed) === 16) { // IPv6 — prefix compare
            if (empty($v6) || !is_array($v6)) {
                return false;
            }
            foreach ($v6 as $row) {
                $prefix = base64_decode($row[0]);
                if ($prefix !== false && devdcorev1_v6_in_prefix($packed, $prefix, (int) $row[1])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Compare the first $bits bits of two 16-byte packed IPv6 addresses. */
    function devdcorev1_v6_in_prefix($ip_packed, $prefix_packed, $bits)
    {
        $whole = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($whole > 0 && substr($ip_packed, 0, $whole) !== substr($prefix_packed, 0, $whole)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ip_packed[$whole]) & $mask) === (ord($prefix_packed[$whole]) & $mask);
    }

    /* ----------------------------- DevDome Tools hub ------------------------------ */
    // The suite Dashboard + registry + account + reporting seams. Loaded here so
    // the single highest-version core copy owns one hub across the whole suite.
    require_once __DIR__ . '/hub.php';
    require_once __DIR__ . '/hub-account.php';
    require_once __DIR__ . '/hub-report.php';
    require_once __DIR__ . '/hub-error-report.php'; // "Report this error" button + admin-ajax sender, core 1.7.0
    require_once __DIR__ . '/abilities.php'; // devdome-tools/get-connection (WP 6.9+ Abilities API), core 1.7.0
    require_once __DIR__ . '/hub-bug-hint.php'; // one-time "Report a bug" bubble, once per user across the suite
    if (file_exists(__DIR__ . '/hub-update-heal.php')) { // self-hosted builds only: wp.org strips it (review 27 Sep 2026, no hooks on the updater)
        require_once __DIR__ . '/hub-update-heal.php'; // updates work on root-owned plugin folders, core 1.7.2
    }
    // Absent from the WordPress.org build (.wporg-strip): a wp.org-distributed
    // plugin must never install or activate other plugins on the user's behalf, so the
    // hub links out to the product page instead (hub.php falls back when absent).
    if (file_exists(__DIR__ . '/hub-install.php')) {
        require_once __DIR__ . '/hub-install.php';
    }
    if (file_exists(__DIR__ . '/hub-install-selfhost.php')) {
        require_once __DIR__ . '/hub-install-selfhost.php';
    }
}
