<?php
/**
 * The enforcement layer. Front-end page views, plus wp-login.php and xmlrpc.php when the owner turned those on.
 * Deliberately conservative:
 *   - wp-admin is NEVER touched; wp-login.php only by the owner's explicit setting (refused on Save when it would
 *     lock the owner out), and define('DEVDCOUN_DISABLE', true) in wp-config.php switches every block off.
 *   - Test mode logs who would be blocked and blocks nobody.
 *   - Logged-in users are exempt by default (setting, default on).
 *   - Unknown country is ALWAYS allowed (fail-open).
 *   - The IP allowlist and (optionally) major search-engine crawlers bypass it.
 * With full-page caching, cache HITS are served before WordPress runs: blocking applies to requests
 * that reach WordPress. Front-of-site blocking belongs to a CDN or firewall (said in the UI and readme).
 */

defined('ABSPATH') || exit;

/** Major search-engine user agents and the DevDome site health monitor (optional bypass; user-agent based, stated in the UI). */
function devdcoun_is_search_engine()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    return $ua !== '' && preg_match('/Googlebot|bingbot|DuckDuckBot|Applebot|DevDomeHealth/i', $ua);
}

/**
 * The verdict for the current request as array(blocked, country, reason). Pure (no output, no log), so
 * the enforcement, the Overview "your IP" line and the lookup ability share one decision.
 * reason: disabled | off | no_countries | logged_in | search_engine | allowlist | unknown | listed | not_listed
 * $area: site | login | xmlrpc. The logged-in and crawler exemptions belong to the site only: a faked crawler
 * user agent must never open the login page.
 */
function devdcoun_decide($cc = null, $ip = null, $area = 'site')
{
    if (defined('DEVDCOUN_DISABLE') && DEVDCOUN_DISABLE) {
        return array(false, '', 'disabled'); // wp-config.php recovery switch
    }
    $s = devdcoun_settings();
    if ($s['mode'] === 'off') {
        return array(false, '', 'off');
    }
    $list = devdcoun_active_countries($s);
    if (empty($list)) {
        return array(false, '', 'no_countries');
    }
    if ('site' === $area && $s['skip_logged_in'] && is_user_logged_in()) {
        return array(false, '', 'logged_in');
    }
    if ('site' === $area && $s['allow_search'] && devdcoun_is_search_engine()) {
        return array(false, '', 'search_engine');
    }
    $ip = null === $ip ? devdcoun_visitor_ip() : (string) $ip;
    if ($ip !== '' && !empty($s['ip_allowlist']) && devdcoun_ip_in_list($ip, $s['ip_allowlist'])) {
        return array(false, '', 'allowlist');
    }
    if (null === $cc) {
        list($cc, ) = devdcoun_country();
    }
    $cc = strtoupper((string) $cc);
    if ($cc === '') {
        return array(false, '', 'unknown'); // unknown = allowed, always
    }
    $listed  = in_array($cc, $list, true);
    $blocked = ($s['mode'] === 'block') ? $listed : !$listed;
    return array($blocked, $cc, $listed ? 'listed' : 'not_listed');
}

/**
 * True when storing $clean would shut the person saving it out of the login page: login blocking on, live (not test
 * mode), their own country blocked and their own address not on the allowlist. The screen and the abilities both ask.
 */
function devdcoun_would_lock_out(array $clean)
{
    if (empty($clean['protect_login']) || !empty($clean['test_mode']) || 'off' === $clean['mode']) {
        return false;
    }
    list($cc, $src) = devdcoun_country();
    $ip = devdcoun_visitor_ip(isset($clean['trusted_proxies']) ? (array) $clean['trusted_proxies'] : null);
    if ('cloudflare' !== $src) {
        // Everything but a verified Cloudflare answer is judged again with the PROPOSED settings: the address as the
        // proposed Trusted Proxies list resolves it, looked up locally; a proxy header only counts below, when it stays on.
        $cc = devdcoun_db_ready() ? devdcoun_db_lookup($ip) : '';
    }
    // Judged with the country source AS IT WOULD BE STORED: proxy headers being switched on decide the country from
    // the next request on (Cloudflare, when verified, still wins, exactly like devdcoun_country()).
    if (!empty($clean['trust_proxy']) && !(isset($_SERVER['HTTP_CF_IPCOUNTRY']) && devdcoun_behind_cloudflare())) {
        foreach (array('HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY') as $h) {
            $hc = isset($_SERVER[$h]) ? strtoupper(substr(sanitize_text_field(wp_unslash($_SERVER[$h])), 0, 2)) : '';
            if (preg_match('/^[A-Z]{2}$/', $hc)) {
                $cc = $hc;
                break;
            }
        }
    }
    $list = devdcoun_active_countries($clean);
    if ('' === $cc || empty($list)) {
        return false;
    }
    $listed = in_array($cc, $list, true);
    if (!(('block' === $clean['mode']) ? $listed : !$listed)) {
        return false;
    }
    return !('' !== $ip && devdcoun_ip_in_list($ip, (array) $clean['ip_allowlist']));
}

/** Decide + act. Runs at template_redirect 0 = front-end page requests only. */
function devdcoun_enforce()
{
    // Context exemptions. template_redirect already excludes admin / AJAX / REST / cron in practice; the
    // guards stay as a belt for unusual setups.
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    list($blocked, $cc, ) = devdcoun_decide();
    if ($blocked) {
        devdcoun_respond($cc);
    }
}
add_action('template_redirect', 'devdcoun_enforce', 0);

/** The login page, only when the owner turned it on. */
function devdcoun_enforce_login()
{
    if (!devdcoun_setting('protect_login')) {
        return;
    }
    list($blocked, $cc, ) = devdcoun_decide(null, null, 'login');
    if ($blocked) {
        devdcoun_respond($cc);
    }
}
add_action('login_init', 'devdcoun_enforce_login', 0);

/** xmlrpc.php, only when the owner turned it on. */
function devdcoun_enforce_xmlrpc()
{
    if (!(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || !devdcoun_setting('protect_xmlrpc')) {
        return;
    }
    list($blocked, $cc, ) = devdcoun_decide(null, null, 'xmlrpc');
    if ($blocked) {
        devdcoun_respond($cc);
    }
}
add_action('init', 'devdcoun_enforce_xmlrpc', 0);

/** Log the hit, then answer it: nothing in test mode, else the redirect or the 403 page. */
function devdcoun_respond($cc)
{
    $s = devdcoun_settings();
    devdcoun_log_hit($cc, !empty($s['test_mode']));
    if (!empty($s['test_mode'])) {
        return; // test mode: the visitor goes through
    }

    if ($s['action'] === 'redirect' && $s['redirect_url'] !== '') {
        // Never redirect to this site's own host: that would loop straight back into the blocker.
        $target = (string) $s['redirect_url'];
        $host   = wp_parse_url($target, PHP_URL_HOST);
        $own    = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host && $own && strcasecmp($host, $own) !== 0) {
            nocache_headers();
            wp_redirect($target, 302); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external target is the feature; validated http(s) URL, own host excluded.
            exit;
        }
    }

    nocache_headers();
    header('X-Robots-Tag: noindex');
    status_header(403);
    $msg = $s['message'] !== '' ? $s['message'] : __('Sorry, this website is not available in your region.', 'devdome-country-blocker');
    header('Content-Type: text/html; charset=utf-8');
    devdcoun_print_block_page($msg);
    exit;
}

/**
 * The 403 page: deliberately unbranded, no theme, no assets. The message sits in the middle of the screen on every device:
 * flex centring over the full viewport height (100dvh where supported, 100vh before), padding so it never touches the
 * edges, long words wrap.
 */
function devdcoun_print_block_page($msg)
{
    // Styles ride on the elements themselves (no <style> or <script> block anywhere in PHP, Affiliate Manager T1).
    echo '<!DOCTYPE html><html style="height:100%"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>403</title>'
        . '</head><body style="height:100%;margin:0;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f8f9fa;color:#3c4043;-webkit-text-size-adjust:100%;text-size-adjust:100%">'
        . '<main style="width:100%;max-width:480px;text-align:center;overflow-wrap:anywhere"><h1 style="font-size:42px;line-height:1.1;margin:0 0 10px;color:#9aa0a6">403</h1><p style="font-size:15px;line-height:1.6;margin:0">';
    echo esc_html($msg);
    echo '</p></main></body></html>';
}

/**
 * Count the hit (daily upsert) + append to the capped recent-hits log. Best effort on the front end.
 * A test-mode hit blocked nobody: it is logged with its flag and never counted as blocked.
 */
function devdcoun_log_hit($cc, $test = false)
{
    global $wpdb;
    $daily = $wpdb->prefix . 'devdcoun_daily';
    $log   = $wpdb->prefix . 'devdcoun_log';
    $day   = current_time('Y-m-d');

    if (!$test) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$daily} (day, country, blocked) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE blocked = blocked + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $day,
            $cc
        )); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    // Path only: a query string can carry tokens, passwords or e-mail addresses and is never stored.
    $path = isset($_SERVER['REQUEST_URI']) ? substr((string) strtok(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), '?'), 0, 191) : '';
    $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 191) : '';
    $row = array(
        'created_at' => current_time('mysql'),
        'country'    => $cc,
        'ip_display' => devdcoun_anonymize_ip(devdcoun_visitor_ip()),
        'path'       => $path,
        'ua'         => $ua,
    );
    $fmt = array('%s', '%s', '%s', '%s', '%s');
    if ($test) {
        $row['test'] = 1;
        $fmt[]       = '%d';
    }
    $wpdb->insert($log, $row, $fmt); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

    // Cheap probabilistic prune keeps the table at ~200 rows without a COUNT(*) on every blocked hit; the
    // daily cron enforces the cap exactly.
    if (wp_rand(1, 20) === 1) {
        $wpdb->query("DELETE FROM {$log} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$log} ORDER BY id DESC LIMIT 200) keep)"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}
