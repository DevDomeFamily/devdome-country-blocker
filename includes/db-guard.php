<?php
/**
 * Request-wide database guard + proved option writes (DESIGN.md 24 / 24.5, suite standard since 2026-09-14).
 *
 * $wpdb reports a failed query only through last_error, and the NEXT query clears it. get_option() on a broken
 * table answers the default, so a failed read used to pass as "not connected", "setting off" or "saved". The
 * guard records every failed query as it happens (the 'query' filter at priority 1 runs inside wpdb::query()
 * BEFORE the flush), an ACTION opens a window (every admin-post / self-POST handler, the admin page render), inside a
 * window the option helpers refuse once a query failed and every boundary answers a database error instead of
 * "done". Outside a window (notice capture on other screens, cron, the test suite) nothing changes: the option helpers still prove their writes.
 *
 * Reference implementation: Admin Cleaner 1.0.5 includes/db-guard.php (devdadcl_), itself from the Malware Scanner.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['devdcoun_db_guard'] = array( 'errors' => array(), 'count' => 0, 'last' => '', 'mark' => 0, 'open' => 0, 'pending' => false );

/** Clear $wpdb->last_error before a read whose empty answer must be told from a failure; records first. */
function devdcoun_db_reset_error() {
	global $wpdb;
	devdcoun_db_guard_sync();
	$GLOBALS['devdcoun_db_guard']['pending'] = false;
	if ( isset( $wpdb->last_error ) ) {
		$wpdb->last_error = '';
	}
}

/** True when the query run since devdcoun_db_reset_error() failed. */
function devdcoun_db_failed() {
	global $wpdb;
	return isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error;
}

/** Record the pending $wpdb->last_error once (internal). */
function devdcoun_db_guard_sync() {
	global $wpdb;
	$g = &$GLOBALS['devdcoun_db_guard'];
	if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error && empty( $g['pending'] ) ) {
		$g['count'] = (int) $g['count'] + 1; // monotonic; the list below is diagnostic and capped
		$g['last']  = (string) $wpdb->last_error;
		if ( count( $g['errors'] ) < 50 ) {
			$g['errors'][] = (string) $wpdb->last_error;
		}
		$g['pending'] = true;
	}
}

/** 'query' filter (priority 1): the previous query's error is recorded before wpdb::query() flushes it. */
function devdcoun_db_guard_record( $query ) {
	devdcoun_db_guard_sync();
	$GLOBALS['devdcoun_db_guard']['pending'] = false;
	return $query;
}

/** Open a guard window. Nested windows share the outer list; only the outermost begin() clears it. */
function devdcoun_db_guard_begin() {
	global $wpdb;
	devdcoun_db_guard_sync();
	$g = &$GLOBALS['devdcoun_db_guard'];
	$g['open'] = (int) $g['open'] + 1;
	if ( 1 === $g['open'] ) {
		$g['errors']  = array();
		$g['count']   = 0;
		$g['last']    = '';
		$g['mark']    = 0;
		$g['pending'] = false;
		if ( isset( $wpdb->last_error ) ) {
			$wpdb->last_error = ''; // an error from before this action is not this action's
		}
	}
}

function devdcoun_db_guard_end() {
	$g = &$GLOBALS['devdcoun_db_guard'];
	$g['open'] = max( 0, (int) $g['open'] - 1 );
}

function devdcoun_db_guard_open() {
	return (int) $GLOBALS['devdcoun_db_guard']['open'] > 0;
}

/** Move the mark to now: errors before it are handled. */
function devdcoun_db_guard_rebase() {
	devdcoun_db_guard_sync();
	$GLOBALS['devdcoun_db_guard']['mark'] = (int) $GLOBALS['devdcoun_db_guard']['count'];
}

/** True when a query failed since the mark. */
function devdcoun_db_guard_failed() {
	devdcoun_db_guard_sync();
	$g = $GLOBALS['devdcoun_db_guard'];
	return (int) $g['count'] > (int) $g['mark'];
}

/** True when a window is open AND a query failed since its mark: writes and boundaries refuse on this. */
function devdcoun_db_guard_active() {
	return devdcoun_db_guard_open() && devdcoun_db_guard_failed();
}

function devdcoun_db_guard_error() {
	devdcoun_db_guard_sync();
	return (string) $GLOBALS['devdcoun_db_guard']['last'];
}

/** The one text every boundary uses. */
function devdcoun_db_guard_message() {
	$err = function_exists( 'devdcoun_redact' ) ? devdcoun_redact( devdcoun_db_guard_error() ) : devdcoun_db_guard_error(); // redact BEFORE the cut
	return 'A database query failed during this action' . ( '' !== $err ? ' (' . substr( $err, 0, 160 ) . ')' : '' ) . '. The result is not trusted and nothing more was changed: reload the page and check the current state before trying again.';
}

/*
 * OPTION WRITES ARE PROVED, NOT ASSUMED (DESIGN.md 24.5). update_option() answers false for "unchanged" and
 * "failed" alike. These write, read the ROW back past the option cache and answer true only when the stored value
 * is the one written (or gone, for a delete). Inside a failed guard window they refuse.
 */

/** @return array{0:bool,1:mixed}|false [found, value] straight from the options table, false when the read failed. */
function devdcoun_option_row( $key ) {
	global $wpdb;
	devdcoun_db_reset_error();
	// get_row(), not get_var(): wpdb::get_var() answers null for an EMPTY value as well as for a missing row
	// (Codex round 1), so a written false / '' would read as "not there" and every such write would fail its proof.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", (string) $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the point is to read past the option cache
	if ( devdcoun_db_failed() ) {
		return false;
	}
	if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
		return array( false, null );
	}
	return array( true, maybe_unserialize( $row['option_value'] ) );
}

/**
 * One shape for both sides of a proof: bools become '1' / '', numbers become strings, null STAYS null, arrays recurse.
 * A loose == compared null with 0 as equal (Codex round 2: a rejected all-admin mute write, row still admins => null,
 * "proved" against admins => 0), so nothing here is loose.
 */
function devdcoun_option_norm( $v ) {
	if ( is_array( $v ) ) {
		$out = array();
		foreach ( $v as $k => $item ) {
			$out[ (string) $k ] = devdcoun_option_norm( $item );
		}
		return $out;
	}
	if ( is_object( $v ) ) {
		return devdcoun_option_norm( get_object_vars( $v ) );
	}
	if ( null === $v ) {
		return null;
	}
	if ( is_bool( $v ) ) {
		return $v ? '1' : '';
	}
	return (string) $v;
}

/** Equality across the serialize round trip, strict on null vs 0 vs ''. */
function devdcoun_option_same( $stored, $value ) {
	return devdcoun_option_norm( $stored ) === devdcoun_option_norm( $value );
}

/** @return bool true only when the row holds $value afterwards. */
function devdcoun_option_write( $key, $value, $autoload = null ) {
	if ( devdcoun_db_guard_active() ) {
		return false;
	}
	if ( null === $autoload ) {
		update_option( $key, $value );
	} else {
		update_option( $key, $value, $autoload ); // per-notice rows never join the autoload set (Codex round 2)
	}
	$row = devdcoun_option_row( $key );
	return is_array( $row ) && $row[0] && devdcoun_option_same( $row[1], $value );
}

/** @return bool true only when no row for $key exists afterwards (a read that failed is not "gone"). */
function devdcoun_option_delete( $key ) {
	if ( devdcoun_db_guard_active() ) {
		return false;
	}
	delete_option( $key );
	$row = devdcoun_option_row( $key );
	return is_array( $row ) && ! $row[0];
}

/*
 * ACTION BOUNDARIES. Every handler opens with its capability check and check_admin_referer() INLINE (wp.org
 * review 2026-09-16: a helper hides them from the scanner), then devdcoun_db_guard_begin(), and ends with
 * devdcoun_finish(): the PRG redirect carries the result flags only when no query failed inside the window;
 * otherwise the flags are replaced by cb_error=db + cb_why and the page shows the database banner with
 * "Report this error" (DESIGN.md 24 + 26).
 */

/** The one PRG exit: success flags only when the window is clean. */
function devdcoun_finish( array $args ) {
	if ( devdcoun_db_guard_active() ) {
		foreach ( array_keys( $args ) as $k ) {
			if ( 0 === strpos( (string) $k, 'cb_' ) && 'cb_tab' !== $k ) {
				unset( $args[ $k ] ); // a "done" flag over a failed query is a lie
			}
		}
		$args['cb_error'] = 'db';
		$args['cb_why']   = substr( devdcoun_db_guard_message(), 0, 200 ); // add_query_arg() encodes once (DeepSeek round 2)
	}
	devdcoun_db_guard_end();
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}
