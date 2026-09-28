<?php
/**
 * Shared helpers: client IP, CIDR matching, IP anonymization for the log, the redactor the error
 * report and the abilities use, and the cached dashboard summary refresh (cron + after-save).
 */

defined('ABSPATH') || exit;

/**
 * The connecting client IP. REMOTE_ADDR only: forwarding headers are spoofable and are handled
 * explicitly by the geo layer (Cloudflare ranges check), never trusted blindly here.
 */
function devdcoun_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
}

/** True when $ip is inside $cidr like 1.2.3.0/24 (v4) or 2001:db8::/32 (v6). Plain IPs compare exact. */
function devdcoun_ip_in_cidr($ip, $cidr)
{
    if (strpos($cidr, '/') === false) {
        // Address equality, not string equality: 2001:db8::1 and 2001:0db8:0:0:0:0:0:1 are one address.
        $a = filter_var($ip, FILTER_VALIDATE_IP) ? @inet_pton($ip) : false; // phpcs:ignore
        $b = filter_var($cidr, FILTER_VALIDATE_IP) ? @inet_pton($cidr) : false; // phpcs:ignore
        return false !== $a && false !== $b && $a === $b;
    }
    list($net, $bits) = explode('/', $cidr, 2);
    $bits = (int) $bits;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
        return ((ip2long($ip) & $mask) === (ip2long($net) & $mask));
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if ($bits < 0 || $bits > 128) {
            return false;
        }
        $ipb  = inet_pton($ip);
        $netb = inet_pton($net);
        if ($ipb === false || $netb === false) {
            return false;
        }
        $full = intdiv($bits, 8);
        $rem  = $bits % 8;
        if ($full > 0 && substr($ipb, 0, $full) !== substr($netb, 0, $full)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = ~(0xFF >> $rem) & 0xFF;
        return ((ord($ipb[$full]) & $mask) === (ord($netb[$full]) & $mask));
    }
    return false;
}

/** True when $ip matches any entry (plain IP or CIDR) in the list. */
function devdcoun_ip_in_list($ip, array $list)
{
    foreach ($list as $entry) {
        if (devdcoun_ip_in_cidr($ip, (string) $entry)) {
            return true;
        }
    }
    return false;
}

/** Privacy: the log never stores the full address. IPv4 keeps the /24 (last octet zeroed); IPv6 keeps the /48. */
function devdcoun_anonymize_ip($ip)
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = inet_pton($ip);
        if ($bin !== false) {
            $bin = substr($bin, 0, 6) . str_repeat("\0", 10);
            $out = inet_ntop($bin);
            if ($out !== false) {
                return $out . '/48';
            }
        }
    }
    return '';
}

/**
 * Redactor for everything that leaves the site (error reports, agent answers): full IP addresses
 * become their anonymized form, e-mail addresses a placeholder. The plugin holds no secrets, but a
 * database error text can quote a visitor's address.
 */
function devdcoun_redact($text)
{
    $text = (string) $text;
    $text = preg_replace_callback('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', function ($m) {
        $a = devdcoun_anonymize_ip($m[0]);
        return '' !== $a ? $a : $m[0];
    }, $text);
    // IPv6: any colon-and-hex run that PHP accepts as an address (a time like 10:00:21 is not one).
    $text = preg_replace_callback('/(?<![\w:.])([0-9a-f:]{3,39})(\/(\d{1,3}))?(?![\w:.\/])/i', function ($m) {
        if (!filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $m[0];
        }
        $bin = @inet_pton($m[1]); // phpcs:ignore
        if (isset($m[3]) && '' !== $m[3] && (int) $m[3] <= 48 && is_string($bin) && substr($bin, 6) === str_repeat("\0", 10)) {
            return $m[0]; // a /48 or wider NETWORK (no host bits set) names no person; an anonymized value stays as it is
        }
        $a = devdcoun_anonymize_ip($m[1]);
        return '' !== $a ? $a : $m[0];
    }, $text);
    return preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '<email>', $text);
}

/**
 * Rebuild the cached dashboard summary (tiles, hub health and the Overview read ONLY this).
 * Called by the daily cron, after a settings save and after a database build.
 * @return bool true when the summary was read AND stored (proved); false sets $GLOBALS['devdcoun_summary_write_failed'].
 */
function devdcoun_refresh_summary()
{
    global $wpdb;
    $daily = $wpdb->prefix . 'devdcoun_daily';
    $s     = devdcoun_settings();
    $GLOBALS['devdcoun_summary_write_failed'] = false;

    $today = current_time('Y-m-d');
    $d7    = gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);
    $d30   = gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS);

    devdcoun_db_reset_error();
    $blocked_today = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(blocked),0) FROM {$daily} WHERE day = %s", $today)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $blocked_7d    = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(blocked),0) FROM {$daily} WHERE day >= %s", $d7)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $blocked_30d   = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(blocked),0) FROM {$daily} WHERE day >= %s", $d30)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    if (devdcoun_db_failed()) {
        // A failed count is not zero blocked visits: the previous summary stands (DESIGN.md 24).
        $GLOBALS['devdcoun_summary_write_failed'] = true;
        return false;
    }

    $ok = devdcoun_option_write('devdcoun_summary', array(
        'mode'             => $s['mode'],
        'blocked_today'    => $blocked_today,
        'blocked_7d'       => $blocked_7d,
        'blocked_30d'      => $blocked_30d,
        'countries_listed' => count(devdcoun_active_countries($s)),
        'source_ok'        => devdcoun_source_available(),
        'updated_at'       => time(),
    ), false);
    if (!$ok) {
        $GLOBALS['devdcoun_summary_write_failed'] = true;
    }
    return $ok;
}

/**
 * Daily retention trim (cron only): the hit log keeps its newest 200 rows, daily counters older than 13 months age
 * out. Kept OUT of devdcoun_refresh_summary() so a refresh (screen, button, ability) never deletes anything.
 */
function devdcoun_retention_trim()
{
    global $wpdb;
    $log   = $wpdb->prefix . 'devdcoun_log';
    $daily = $wpdb->prefix . 'devdcoun_daily';
    $wpdb->query("DELETE FROM {$log} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$log} ORDER BY id DESC LIMIT 200) keep)"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query($wpdb->prepare("DELETE FROM {$daily} WHERE day < %s", gmdate('Y-m-d', time() - 396 * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

/** Cron entry: the daily trim + summary refresh inside its own guard window (DESIGN.md 24). */
function devdcoun_summary_refresh_cron()
{
    devdcoun_db_guard_begin();
    try {
        devdcoun_retention_trim();
        devdcoun_refresh_summary();
    } finally {
        devdcoun_db_guard_end();
    }
}
add_action('devdcoun_summary_refresh', 'devdcoun_summary_refresh_cron');

/** Top blocked countries of the last 30 days: array of ['country' => 'DE', 'n' => 12], or null when the read failed. */
function devdcoun_top_countries($limit = 10)
{
    global $wpdb;
    $daily = $wpdb->prefix . 'devdcoun_daily';
    devdcoun_db_reset_error();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT country, SUM(blocked) AS n FROM {$daily} WHERE day >= %s GROUP BY country ORDER BY n DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS),
        max(1, (int) $limit)
    ), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    if (devdcoun_db_failed() || !is_array($rows)) {
        return null;
    }
    return $rows;
}

/** The most recent blocked hits (newest first): rows with created_at, country, ip_display, path, ua; null when the read failed. */
function devdcoun_recent_hits($limit = 200)
{
    global $wpdb;
    $log = $wpdb->prefix . 'devdcoun_log';
    devdcoun_db_reset_error();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, created_at, country, ip_display, path, ua, test FROM {$log} ORDER BY id DESC LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    if (devdcoun_db_failed() || !is_array($rows)) {
        return null;
    }
    return $rows;
}

/**
 * Clear the statistics: every daily counter and every logged hit. Returns the number of rows removed,
 * or false when a delete failed (the caller reports it, nothing is called "cleared").
 */
function devdcoun_clear_statistics()
{
    global $wpdb;
    $log   = $wpdb->prefix . 'devdcoun_log';
    $daily = $wpdb->prefix . 'devdcoun_daily';
    devdcoun_db_reset_error();
    $GLOBALS['devdcoun_clear_partial'] = false;
    $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- both deletes or neither (InnoDB); a no-op on engines without transactions
    $a = $wpdb->query("DELETE FROM {$log}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $b = $wpdb->query("DELETE FROM {$daily}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    if (false === $a || false === $b || devdcoun_db_failed()) {
        $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // Without transactions the first delete stays done: said plainly, so the owner knows what is left.
        $left = (int) $wpdb->get_var("SELECT (SELECT COUNT(*) FROM {$log}) + (SELECT COUNT(*) FROM {$daily})"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $GLOBALS['devdcoun_clear_partial'] = (false !== $a && (int) $a > 0 && $left > 0);
        return false;
    }
    $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    return (int) $a + (int) $b;
}
