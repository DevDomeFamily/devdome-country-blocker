<?php
/**
 * Local IP-to-country database: the free DB-IP "IP to Country Lite" dataset (https://db-ip.com,
 * Creative Commons Attribution 4.0; the attribution link is rendered on the Settings tab and the
 * download is disclosed in readme.txt "External services").
 *
 * The monthly CSV.gz is streamed to disk (never held in memory) and parsed line by line. IPv4 rows are
 * compiled into a compact sorted binary file of 10-byte records: pack('NNa2', start, end, country); IPv6
 * rows into a second file of 34-byte records (16-byte start, 16-byte end, country), streamed straight to
 * disk because the dataset is already in ascending order (checked; sorted in memory only if it is not).
 * Lookups binary-search those files with fseek: a few reads per request, no memory cost, no PHP
 * extension beyond zlib.
 *
 * The download runs ONLY on the site owner's explicit button click, the update-database ability or
 * the scheduled refresh, never on a visitor request.
 */

defined('ABSPATH') || exit;

/** Directory holding the compiled database. */
function devdcoun_db_dir()
{
    $up = wp_upload_dir(null, false);
    return trailingslashit($up['basedir']) . 'devdome-country-blocker';
}

function devdcoun_db_file()
{
    return devdcoun_db_dir() . '/dbip-v4.bin';
}

function devdcoun_db_file6()
{
    return devdcoun_db_dir() . '/dbip-v6.bin';
}

/** True when the IPv6 file exists and matches its recorded size (the IPv4 file decides "ready"; this one is extra). */
function devdcoun_db_ready6()
{
    $meta = devdcoun_db_meta();
    $file = devdcoun_db_file6();
    return !empty($meta['records6']) && file_exists($file) && filesize($file) === (int) $meta['records6'] * 34;
}

/** Meta about the compiled database (option): month, records, built_at, last_error, last_attempt. */
function devdcoun_db_meta()
{
    $m = get_option('devdcoun_db_meta');
    return is_array($m) ? $m : array();
}

/** True when a compiled database exists and matches its recorded size. */
function devdcoun_db_ready()
{
    $meta = devdcoun_db_meta();
    $file = devdcoun_db_file();
    return !empty($meta['records']) && file_exists($file) && filesize($file) === (int) $meta['records'] * 10;
}

/** Remember the outcome of a build attempt on the meta row (the Settings tab and the error report show it). */
function devdcoun_db_note_attempt($ok, $msg)
{
    $meta = devdcoun_db_meta();
    $meta['last_attempt'] = time();
    $meta['last_error']   = $ok ? '' : (string) $msg;
    devdcoun_option_write('devdcoun_db_meta', $meta, false);
}

/**
 * Download + compile the current month's dataset. Returns array(ok, message). Falls back to the
 * previous month around publication day. About 30-60 s on shared hosting; callers run it from an admin
 * action, an ability or cron, never inline on a view.
 */
function devdcoun_db_build()
{
    // One build at a time (button, ability and cron share the staging files). add_option is the atomic test-and-set;
    // a lock older than 15 minutes belongs to a build that died and is taken over.
    // The lock value is "time|token": a stale lock is removed only by the caller that still sees exactly that value
    // (conditional DELETE), and a build releases only its own token, never a lock a later caller took over.
    global $wpdb;
    $mine = time() . '|' . str_replace('.', '', uniqid('', true));
    $held = get_option('devdcoun_db_build_lock', '');
    if (false !== $held && '' !== $held && time() - (int) $held > 15 * MINUTE_IN_SECONDS) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'devdcoun_db_build_lock', (string) $held)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- conditional take-over of a stale lock; the options API has no compare-and-delete
        wp_cache_delete('devdcoun_db_build_lock', 'options');
    }
    if (!add_option('devdcoun_db_build_lock', $mine, '', false)) {
        return array(false, __('A database download is already running. Wait a minute and check again.', 'devdome-country-blocker'));
    }
    try {
        $r = devdcoun_db_build_run();
    } finally {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'devdcoun_db_build_lock', $mine)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases this build's own lock only
        wp_cache_delete('devdcoun_db_build_lock', 'options');
    }
    devdcoun_db_note_attempt($r[0], $r[1]);
    return $r;
}

/**
 * True when the last 8 bytes of a gzip file (CRC-32, then the uncompressed length mod 2^32, both little-endian) match
 * the data that was decompressed from it. A truncated download fails this even when gzeof() said the stream ended.
 */
function devdcoun_gz_trailer_ok($path, $crc_hex, $plain_len)
{
    clearstatcache();
    $size = @filesize($path); // phpcs:ignore
    if (!is_int($size) || $size < 18) {
        return false;
    }
    $fh = @fopen($path, 'rb'); // phpcs:ignore
    if (!$fh) {
        return false;
    }
    fseek($fh, -8, SEEK_END);
    $tail = fread($fh, 8); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- 8-byte trailer read
    fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    if (!is_string($tail) || strlen($tail) !== 8) {
        return false;
    }
    $t = unpack('Vcrc/Vlen', $tail);
    return (int) $t['crc'] === (int) hexdec($crc_hex) && (int) $t['len'] === (int) ($plain_len & 0xFFFFFFFF);
}

function devdcoun_db_build_run()
{
    if (!function_exists('gzopen')) {
        return array(false, __('The PHP zlib extension is required to build the database. Ask your host to enable it.', 'devdome-country-blocker'));
    }
    $dir = devdcoun_db_dir();
    // Nothing here may be a link, checked BEFORE the first write: a linked folder or file would send the download or the
    // compiled files somewhere else. (The uploads base itself may be a link: that is the host's own layout.)
    if (is_link($dir)) {
        return array(false, __('The database folder or one of its files is a symbolic link. Nothing was written: remove the link and try again.', 'devdome-country-blocker'));
    }
    if (!wp_mkdir_p($dir)) {
        return array(false, __('Could not create the uploads folder for the database.', 'devdome-country-blocker'));
    }
    $targets = array($dir, $dir . '/index.php', $dir . '/dbip-download.csv.gz', devdcoun_db_file(), devdcoun_db_file() . '.tmp', devdcoun_db_file() . '.prev', devdcoun_db_file6(), devdcoun_db_file6() . '.tmp', devdcoun_db_file6() . '.prev');
    foreach ($targets as $t) {
        if (is_link($t)) {
            return array(false, __('The database folder or one of its files is a symbolic link. Nothing was written: remove the link and try again.', 'devdome-country-blocker'));
        }
    }
    $real_up  = realpath(dirname($dir));
    $real_dir = realpath($dir);
    if (false === $real_up || false === $real_dir || dirname($real_dir) !== $real_up) {
        return array(false, __('The database folder does not resolve inside the uploads folder. Nothing was written.', 'devdome-country-blocker'));
    }
    // Deny direct listing of the folder.
    if (!file_exists($dir . '/index.php')) {
        @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n"); // phpcs:ignore
    }
    // A previous build that failed half way and could not put its files back left them as .prev: recover them first,
    // never delete the only copy.
    if (!devdcoun_db_restore_prev(true)) {
        return array(false, __('An earlier database update left its files half replaced and they could not be put back. Check that the uploads folder is writable, then try again.', 'devdome-country-blocker'));
    }

    $months = array(gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month')));
    $tmp    = $dir . '/dbip-download.csv.gz';
    $month  = '';
    foreach ($months as $try) {
        $url = 'https://download.db-ip.com/free/dbip-country-lite-' . $try . '.csv.gz';
        $res = wp_remote_get($url, array(
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $tmp,
        ));
        if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200 && file_exists($tmp) && filesize($tmp) > 100000) {
            $month = $try;
            break;
        }
        @unlink($tmp); // phpcs:ignore
    }
    if ($month === '') {
        return array(false, __('Could not download the DB-IP dataset. Check that the server can reach download.db-ip.com and try again.', 'devdome-country-blocker'));
    }

    // Stream-parse: IPv4 rows are collected, sorted and written; IPv6 rows go straight to their temp file.
    $gz = gzopen($tmp, 'rb');
    if (!$gz) {
        @unlink($tmp); // phpcs:ignore
        return array(false, __('Could not open the downloaded file.', 'devdome-country-blocker'));
    }
    $records  = array();
    $out6     = @fopen(devdcoun_db_file6() . '.tmp', 'wb'); // phpcs:ignore
    $records6 = 0;
    $prev6    = '';
    $sorted6  = true;
    $crc      = hash_init('crc32b');
    $plain    = 0; // uncompressed bytes seen, compared with the gzip trailer below
    while (($line = gzgets($gz, 512)) !== false) {
        hash_update($crc, $line);
        $plain += strlen($line);
        // Format: start_ip,end_ip,country (unquoted, v4 and v6 rows mixed).
        $parts = explode(',', trim($line));
        if (count($parts) !== 3) {
            continue; // malformed
        }
        if (strpos($parts[0], ':') !== false) {
            $s6  = @inet_pton($parts[0]); // phpcs:ignore
            $e6  = @inet_pton($parts[1]); // phpcs:ignore
            $cc6 = strtoupper(substr($parts[2], 0, 2));
            if ($out6 && is_string($s6) && is_string($e6) && strlen($s6) === 16 && strlen($e6) === 16 && preg_match('/^[A-Z]{2}$/', $cc6)) {
                if (strcmp($s6, $prev6) < 0) {
                    $sorted6 = false;
                }
                $prev6 = $s6;
                if (34 === fwrite($out6, $s6 . $e6 . $cc6)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- 34-byte binary records streamed to a temp file
                    $records6++;
                } else {
                    $sorted6 = false; // a short write: the IPv6 file is dropped below (size check), never half used
                    $records6 = 0;
                    fclose($out6); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    $out6 = false;
                }
            }
            continue;
        }
        $start = ip2long($parts[0]);
        $end   = ip2long($parts[1]);
        $cc    = strtoupper(substr($parts[2], 0, 2));
        if ($start === false || $end === false || !preg_match('/^[A-Z]{2}$/', $cc)) {
            continue;
        }
        $records[] = pack('NNa2', $start, $end, $cc);
    }
    $whole = gzeof($gz); // a read error or a cut-off stream ends the loop early, before the end of the file
    gzclose($gz);
    // gzeof is also true for a download cut off mid-stream: the gzip trailer (CRC-32 + length of the uncompressed data)
    // must match what was read, otherwise the file was not the whole dataset.
    if ($whole && !devdcoun_gz_trailer_ok($tmp, hash_final($crc), $plain)) {
        $whole = false;
    }
    @unlink($tmp); // phpcs:ignore
    if ($out6) {
        fclose($out6); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the stream above
    }

    // Complete or nothing: the stream was read to its end, the dataset has a plausible size (the real one holds about
    // 350,000 IPv4 ranges) and did not shrink to under half of the installed one. A short dataset would leave the
    // missing ranges "unknown", which means allowed.
    $had = devdcoun_db_meta();
    $had = isset($had['records']) ? (int) $had['records'] : 0;
    if (!$whole || count($records) < 100000 || ($had > 0 && count($records) < $had / 2)) {
        @unlink(devdcoun_db_file6() . '.tmp'); // phpcs:ignore
        return array(false, __('The downloaded dataset looked incomplete; nothing was replaced.', 'devdome-country-blocker'));
    }
    sort($records, SORT_STRING); // packed big-endian starts sort correctly as strings

    $out = @fopen(devdcoun_db_file() . '.tmp', 'wb'); // phpcs:ignore
    if (!$out) {
        return array(false, __('Could not write the database file. Check that the uploads folder is writable.', 'devdome-country-blocker'));
    }
    foreach ($records as $r) {
        fwrite($out, $r); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- 10-byte binary records streamed to a temp file; WP_Filesystem writes whole strings only, and the sorted set is too large to hold as one string on shared hosting
    }
    fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the stream above
    clearstatcache();
    // A short write (full disk, quota) is caught here, before anything live is touched.
    if (filesize(devdcoun_db_file() . '.tmp') !== count($records) * 10) {
        @unlink(devdcoun_db_file() . '.tmp'); // phpcs:ignore
        @unlink(devdcoun_db_file6() . '.tmp'); // phpcs:ignore
        return array(false, __('The database file could not be written completely (disk full?). Nothing was replaced.', 'devdome-country-blocker'));
    }
    // The previous generation is kept until the new one is proved, and comes back on any failure below.
    $prev_meta = devdcoun_db_meta();
    foreach (array(devdcoun_db_file(), devdcoun_db_file6()) as $live) {
        if (file_exists($live) && file_exists($live . '.prev')) {
            @unlink($live . '.prev'); // phpcs:ignore -- live copy present (recovered above), the older backup may go
        }
        if (file_exists($live) && !@rename($live, $live . '.prev')) { // phpcs:ignore
            devdcoun_db_restore_prev();
            @unlink(devdcoun_db_file() . '.tmp'); // phpcs:ignore
            @unlink(devdcoun_db_file6() . '.tmp'); // phpcs:ignore
            return array(false, __('Could not replace the database file. Check that the uploads folder is writable.', 'devdome-country-blocker'));
        }
    }
    // Atomic swap so a lookup never sees a half-written file.
    if (!@rename(devdcoun_db_file() . '.tmp', devdcoun_db_file())) { // phpcs:ignore
        @unlink(devdcoun_db_file() . '.tmp'); // phpcs:ignore
        @unlink(devdcoun_db_file6() . '.tmp'); // phpcs:ignore
        devdcoun_db_restore_prev();
        return array(false, __('Could not replace the database file. Check that the uploads folder is writable.', 'devdome-country-blocker'));
    }

    // IPv6: the streamed file goes live only when it is complete and in order; otherwise IPv6 stays unknown (allowed).
    if ($records6 >= 1000 && !$sorted6) {
        $all6 = str_split((string) file_get_contents(devdcoun_db_file6() . '.tmp'), 34); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file
        sort($all6, SORT_STRING);
        $sorted6 = false !== file_put_contents(devdcoun_db_file6() . '.tmp', implode('', $all6)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local temp file
        unset($all6);
    }
    clearstatcache();
    if ($records6 < 1000 || !$sorted6 || !file_exists(devdcoun_db_file6() . '.tmp') || filesize(devdcoun_db_file6() . '.tmp') !== $records6 * 34 || !@rename(devdcoun_db_file6() . '.tmp', devdcoun_db_file6())) { // phpcs:ignore
        @unlink(devdcoun_db_file6() . '.tmp'); // phpcs:ignore
        // The new IPv6 file did not make it: the previous one (if any) stays in service with its own count.
        $records6 = 0;
        if (file_exists(devdcoun_db_file6() . '.prev') && !file_exists(devdcoun_db_file6()) && @rename(devdcoun_db_file6() . '.prev', devdcoun_db_file6())) { // phpcs:ignore
            $records6 = isset($prev_meta['records6']) ? (int) $prev_meta['records6'] : 0;
            $v6_stale = true; // the OLD IPv6 data stays in service: never labelled with the new month, retried weekly
        }
    }

    $meta = devdcoun_db_meta();
    $meta['month']    = $month;
    $meta['records']  = count($records);
    $meta['records6'] = $records6;
    $meta['v6_stale'] = !empty($v6_stale) ? 1 : 0;
    $meta['built_at'] = time();
    clearstatcache();
    if (!devdcoun_option_write('devdcoun_db_meta', $meta, false) || !devdcoun_db_ready()) {
        // The record did not land (or the files do not match it): the previous generation comes back with its own
        // record, so lookups keep working on the old data. Said plainly, never "ready".
        $files_back = devdcoun_db_restore_prev();
        $meta_back  = devdcoun_db_meta_rollback($prev_meta); // both always attempted: a failed file restore must not leave the new record in place (Codex 1.0.3)
        if (!$files_back || !$meta_back) {
            return array(false, __('The database update failed and the previous database could not be put back completely. Country detection from the local database is off until a download succeeds; try again.', 'devdome-country-blocker'));
        }
        return array(false, __('The database was downloaded, but its record could not be saved to the database. Try again; if it keeps happening, check the database with your host.', 'devdome-country-blocker'));
    }
    // A backup goes only when its live file is in place: an IPv6 file that could not be put back keeps its .prev, and the
    // next build recovers it first.
    foreach (array(devdcoun_db_file(), devdcoun_db_file6()) as $live) {
        if (file_exists($live)) {
            @unlink($live . '.prev'); // phpcs:ignore
        }
    }
    devdcoun_refresh_summary();

    if (!empty($v6_stale)) {
        return array(true, sprintf(
            /* translators: 1: number of IPv4 ranges, 2: dataset month. */
            __('Database ready: %1$s IPv4 ranges (DB-IP %2$s). The IPv6 part could not be updated, so the previous IPv6 data stays in use; the update is retried automatically every week.', 'devdome-country-blocker'),
            number_format_i18n(count($records)),
            $month
        ));
    }
    if ($records6 < 1) {
        return array(true, sprintf(
            /* translators: 1: number of IPv4 ranges, 2: dataset month. */
            __('Database ready: %1$s IPv4 ranges (DB-IP %2$s). The IPv6 part could not be installed, so IPv6 visitors count as unknown (allowed) until an update succeeds; it is retried automatically every week.', 'devdome-country-blocker'),
            number_format_i18n(count($records)),
            $month
        ));
    }
    return array(true, sprintf(
        /* translators: 1: number of IPv4 ranges, 2: number of IPv6 ranges, 3: dataset month. */
        __('Database ready: %1$s IPv4 and %2$s IPv6 ranges (DB-IP %3$s).', 'devdome-country-blocker'),
        number_format_i18n(count($records)),
        number_format_i18n($records6),
        $month
    ));
}

/**
 * Write the PREVIOUS meta row back after a failed build. The guard refuses writes once a query failed in its window
 * (that is its job), but this write is the repair: old files with the new record counts would switch local detection
 * off. So the window is rebased for this one proved write, and the original failure is recorded again afterwards, so
 * the action still ends as failed.
 */
function devdcoun_db_meta_rollback($prev_meta)
{
    $failed = devdcoun_db_guard_failed();
    $error  = $failed ? devdcoun_db_guard_error() : '';
    devdcoun_db_guard_rebase();
    $ok = devdcoun_option_write('devdcoun_db_meta', $prev_meta, false);
    if ($failed && isset($GLOBALS['devdcoun_db_guard']) && is_array($GLOBALS['devdcoun_db_guard'])) {
        $GLOBALS['devdcoun_db_guard']['count']++;
        $GLOBALS['devdcoun_db_guard']['last']     = $error;
        $GLOBALS['devdcoun_db_guard']['errors'][] = $error;
    }
    return $ok;
}

/**
 * Put the previous database generation back (a failed build never leaves the site without the data it had).
 * $only_missing: recover a .prev only where no live file exists (the start of a build). True when every .prev that
 * had to come back is back; a .prev is never deleted here, so a failed restore can be retried.
 */
function devdcoun_db_restore_prev($only_missing = false)
{
    $ok = true;
    foreach (array(devdcoun_db_file(), devdcoun_db_file6()) as $live) {
        if (!file_exists($live . '.prev') || ($only_missing && file_exists($live))) {
            continue;
        }
        if (file_exists($live)) {
            @unlink($live); // phpcs:ignore
        }
        clearstatcache();
        if (file_exists($live) || !@rename($live . '.prev', $live)) { // phpcs:ignore
            $ok = false;
        }
    }
    clearstatcache();
    return $ok;
}

/** Binary-search the IPv6 file. '' when unknown or not installed. */
function devdcoun_db_lookup6($ip)
{
    $needle = @inet_pton($ip); // phpcs:ignore
    if (!is_string($needle) || strlen($needle) !== 16 || !devdcoun_db_ready6()) {
        return '';
    }
    $meta = devdcoun_db_meta();
    $fh   = @fopen(devdcoun_db_file6(), 'rb'); // phpcs:ignore
    if (!$fh) {
        return '';
    }
    $lo = 0;
    $hi = (int) $meta['records6'] - 1;
    $cc = '';
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        if (fseek($fh, $mid * 34) !== 0) {
            break;
        }
        $rec = fread($fh, 34); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary search needs fseek + a 34-byte read
        if (strlen($rec) !== 34) {
            break;
        }
        if (strcmp($needle, substr($rec, 0, 16)) < 0) {
            $hi = $mid - 1;
        } elseif (strcmp($needle, substr($rec, 16, 16)) > 0) {
            $lo = $mid + 1;
        } else {
            $cc = strtoupper(substr($rec, 32, 2));
            break;
        }
    }
    fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the read above
    return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
}

/** Binary-search the compiled files for an address (IPv4 or IPv6). '' when unknown. */
function devdcoun_db_lookup($ip)
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return devdcoun_db_lookup6($ip);
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return '';
    }
    $meta = devdcoun_db_meta();
    $n    = isset($meta['records']) ? (int) $meta['records'] : 0;
    if ($n < 1) {
        return '';
    }
    $fh = @fopen(devdcoun_db_file(), 'rb'); // phpcs:ignore
    if (!$fh) {
        return '';
    }
    $needle = sprintf('%u', ip2long($ip)) + 0;
    $lo = 0;
    $hi = $n - 1;
    $cc = '';
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        if (fseek($fh, $mid * 10) !== 0) {
            break;
        }
        $rec = fread($fh, 10); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary search needs fseek + a 10-byte read; WP_Filesystem can only read whole files (the database is ~3 MB, per visitor)
        if (strlen($rec) !== 10) {
            break;
        }
        $u = unpack('Nstart/Nend/a2cc', $rec);
        $start = sprintf('%u', $u['start']) + 0;
        $end   = sprintf('%u', $u['end']) + 0;
        if ($needle < $start) {
            $hi = $mid - 1;
        } elseif ($needle > $end) {
            $lo = $mid + 1;
        } else {
            $cc = strtoupper($u['cc']);
            break;
        }
    }
    fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the read above
    return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
}

/** Cron: rebuild only when the stored dataset month is behind the current month. */
function devdcoun_db_refresh_cron()
{
    $meta = devdcoun_db_meta();
    if (empty($meta['month'])) {
        return; // never built: building is the owner's explicit choice
    }
    $no_v6 = empty($meta['records6']) || !empty($meta['v6_stale']);
    $waited = empty($meta['last_attempt']) || time() - (int) $meta['last_attempt'] > WEEK_IN_SECONDS;
    if ($meta['month'] === gmdate('Y-m') && (!$no_v6 || !$waited)) {
        return; // current; a missing IPv6 file (older build, or an install that failed) is retried once a week
    }
    devdcoun_db_guard_begin();
    try {
        devdcoun_db_build();
    } finally {
        devdcoun_db_guard_end();
    }
}
add_action('devdcoun_db_refresh', 'devdcoun_db_refresh_cron');
