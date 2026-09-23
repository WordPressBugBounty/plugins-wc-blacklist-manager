<?php
/** Fixed private site slot; the options cache is never admission authority. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Blocked_State {

	const OPTION = 'wc_blacklist_blocked_notification_v1';
	const GATE = 'wc_blacklist_blocked_notification_gate_v1';
	const WINDOW = 900;
	private static $busy = false;

	public static function lock_name() {
		global $wpdb;
		return 'bm-b1:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->options ), 0, 48 );
	}

	public static function suppressed() {
		// A cold option cache must not issue SQL before the bounded session scope.
		$all = wp_cache_get( 'alloptions', 'options' );
		$hint = is_array( $all ) && isset( $all[self::GATE] ) ? $all[self::GATE] : false;
		$now = time();
		return ( is_int( $hint ) || ( is_string( $hint ) && preg_match( '/^[0-9]{1,10}$/D', $hint ) ) )
			&& (int) $hint > $now && (int) $hint <= $now + self::WINDOW;
	}

	public static function admit( array $reasons ) {
		if ( ! self::reasons( $reasons ) || ! $reasons ) { return self::result( 'invalid_event' ); }
		if ( self::suppressed() ) { return self::result( 'cooldown' ); }
		return self::run( 'admit', $reasons );
	}

	public static function pending() { return self::run( 'read' ); }
	public static function maintain() { return self::run( 'maintain' ); }
	public static function claim( $sample_id, $ready = true ) {
		if ( ! WC_Blacklist_Notification_Policy::background() || ! is_string( $sample_id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $sample_id ) ) { return self::result( 'state_unavailable' ); }
		return self::run( $ready ? 'claim' : 'failure', $sample_id );
	}

	public static function maintenance_context() {
		return WC_Blacklist_Notification_Policy::background()
			|| ( is_admin() && ! wp_doing_ajax() && current_user_can( 'manage_options' ) );
	}

	private static function result( $status, $state = null ) { return array( 'status' => $status, 'state' => $state ); }

	private static function run( $op, $data = null ) {
		global $wpdb;
		if ( self::$busy || ( 'maintain' === $op && ! self::maintenance_context() ) ) { return self::result( 'state_unavailable' ); }
		self::$busy = true;
		$conn = null; $session = null; $held = false; $changed = false; $invalidate_gate = false;
		$result = self::result( 'state_unavailable' );
		// Leave at least one second for each bounded SQL step and time for cleanup.
		$deadline = microtime( true ) + 1.1;
		set_error_handler( static function() { return true; } );
		try {
			if ( ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) )
				|| ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof mysqli
				|| get_class( $wpdb ) !== 'wpdb' || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->options ) ) { throw new RuntimeException(); }
			$conn = $wpdb->dbh;
			$session = self::row( $conn, 'SELECT @@session.autocommit,@@session.innodb_lock_wait_timeout,@@session.lock_wait_timeout,CONNECTION_ID(),VERSION()', $deadline );
			if ( '1' !== (string) $session[0] || ! self::autocommitted( $conn, $deadline ) ) { $session = null; throw new RuntimeException(); }
			// Older MySQL GET_LOCK implicitly releases a caller's existing named lock.
			$version = preg_replace( '/^5\.5\.5-/', '', $session[4] );
			if ( version_compare( $version, false !== stripos( $version, 'MariaDB' ) ? '10.0.2' : '5.7.5', '<' ) ) { throw new RuntimeException(); }
			self::query( $conn, 'SET SESSION innodb_lock_wait_timeout=1,lock_wait_timeout=1', $deadline );
			$table = $wpdb->options;
			$engine = self::row( $conn, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=0x' . bin2hex( $table ), $deadline );
			if ( ! $engine || 'InnoDB' !== $engine[0] ) { throw new RuntimeException(); }
			$index = self::row( $conn, "SHOW INDEX FROM `{$table}` WHERE Key_name='option_name'", $deadline );
			if ( ! $index || '0' !== (string) $index[1] || 'option_name' !== $index[4] || null !== $index[7] ) { throw new RuntimeException(); }
			$lock = '0x' . bin2hex( self::lock_name() );
			if ( '1' !== (string) self::row( $conn, 'SELECT GET_LOCK(' . $lock . ',0)', $deadline )[0] ) { throw new RuntimeException(); }
			$held = true;
			$key = '0x' . bin2hex( self::OPTION );
			$gate = '0x' . bin2hex( self::GATE );
			$clock = self::row( $conn, 'SELECT UNIX_TIMESTAMP()', $deadline );
			$now = (int) $clock[0];
			$row = self::row( $conn, "SELECT IF(OCTET_LENGTH(option_value)<=4096,option_value,NULL),autoload FROM `{$table}` WHERE option_name={$key}", $deadline );
			$s = $row ? json_decode( (string) $row[0], true ) : null;
			$valid = self::valid( $s ) && in_array( $row[1], array( 'no', 'off', 'auto-off' ), true );
			$next = null; $hint = null;
			if ( 'maintain' === $op ) {
				// Missing/corrupt private state is repaired only outside rejection, closed.
				if ( ! $valid ) {
					$next = array( 'v' => 1, 'sample_id' => bin2hex( random_bytes( 16 ) ), 'phase' => 'idle', 'at' => $now, 'until' => $now + self::WINDOW, 'expires' => $now + self::WINDOW, 'failures' => 0, 'reasons' => array() );
				} elseif ( $s['expires'] <= $now && $s['reasons'] ) {
					$next = $s; $next['reasons'] = array(); $next['phase'] = 'exhausted';
				}
				$hint = ( $next ?: $s )['until'];
				$result = self::result( 'ready', $next ?: $s );
			} elseif ( ! $valid ) { throw new RuntimeException(); }
			elseif ( 'read' === $op ) { $result = self::result( 'pending' === $s['phase'] && $s['expires'] > $now ? 'pending' : 'duplicate', $s ); }
			elseif ( 'admit' === $op ) {
				if ( $s['until'] > $now || ( 'pending' === $s['phase'] && $s['expires'] > $now ) ) { $result = self::result( 'cooldown' ); }
				else {
					$next = array( 'v' => 1, 'sample_id' => bin2hex( random_bytes( 16 ) ), 'phase' => 'pending', 'at' => $now, 'until' => $now + self::WINDOW, 'expires' => $now + self::WINDOW, 'failures' => 0, 'reasons' => $data );
					$hint = $next['until']; $result = self::result( 'buffered' );
				}
			} elseif ( ! hash_equals( $s['sample_id'], $data ) || 'pending' !== $s['phase'] || $s['expires'] <= $now ) { $result = self::result( 'duplicate' ); }
			else {
				$next = $s;
				if ( 'failure' === $op ) {
					++$next['failures'];
					if ( 3 === $next['failures'] ) { $next['phase'] = 'exhausted'; }
					$result = self::result( 3 === $next['failures'] ? 'duplicate' : 'retry' );
				} else {
					$next['phase'] = 'attempted'; $next['until'] = max( $next['until'], $now + self::WINDOW );
					$result = self::result( 'claimed' );
				}
			}
			if ( $next ) {
				$value = '0x' . bin2hex( json_encode( $next ) );
				$sql = ! $row ? "INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES ({$key},{$value},'no')"
					: "UPDATE `{$table}` SET option_value={$value},autoload='no' WHERE option_name={$key}";
				self::query( $conn, $sql, $deadline ); $changed = true;
				$confirmed = self::row( $conn, "SELECT option_value,CONNECTION_ID(),IS_USED_LOCK({$lock}),@@session.autocommit FROM `{$table}` WHERE option_name={$key}", $deadline );
				if ( ! $confirmed || $confirmed[0] !== json_encode( $next ) || $wpdb->dbh !== $conn || $confirmed[1] !== $session[3] || $confirmed[2] !== $session[3] || '1' !== (string) $confirmed[3] || ! self::autocommitted( $conn, $deadline ) ) { throw new RuntimeException(); }
			}
			if ( null !== $hint ) {
				// No hot-path upsert: a missing gate is harmless and waits for maintenance.
				$existing = self::row( $conn, "SELECT IF(OCTET_LENGTH(option_value)<=10,option_value,NULL),autoload FROM `{$table}` WHERE option_name={$gate}", $deadline );
				if ( $existing && ( (string) $hint !== $existing[0] || ! in_array( $existing[1], array( 'yes', 'on', 'auto-on', 'auto' ), true ) ) ) {
					$invalidate_gate = true;
					self::query( $conn, "UPDATE `{$table}` SET option_value='" . (int) $hint . "',autoload='yes' WHERE option_name={$gate}", $deadline ); $changed = true;
				} elseif ( ! $existing && 'maintain' === $op ) {
					$invalidate_gate = true;
					self::query( $conn, "INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES ({$gate},'" . (int) $hint . "','yes')", $deadline ); $changed = true;
				}
			}
		} catch ( Throwable $error ) { $result = self::result( 'state_unavailable' ); }
		finally {
			try {
				if ( $held && '1' !== (string) self::row( $conn, 'SELECT RELEASE_LOCK(' . $lock . ')', null )[0] ) { $result = self::result( 'state_unavailable' ); }
				if ( $session ) { self::query( $conn, 'SET SESSION innodb_lock_wait_timeout=' . (int) $session[1] . ',lock_wait_timeout=' . (int) $session[2], null ); }
			} catch ( Throwable $error ) { $result = self::result( 'state_unavailable' ); }
			restore_error_handler(); self::$busy = false;
		}
		// Cache callbacks must never execute inside the SQL/advisory critical section.
		if ( $invalidate_gate || ( $changed && 'maintain' === $op ) ) {
			try { wp_cache_delete( 'alloptions', 'options' ); }
			catch ( Throwable $error ) { /* A stale hint may suppress or revalidate, never grant. */ }
		}
		return $result;
	}

	private static function query( $conn, $sql, $deadline ) {
		if ( null !== $deadline && microtime( true ) > $deadline ) { throw new RuntimeException(); }
		$result = $conn->query( $sql );
		if ( false === $result ) { throw new RuntimeException(); }
		return $result;
	}
	private static function row( $conn, $sql, $deadline ) {
		$result = self::query( $conn, $sql, $deadline );
		if ( $result->num_rows > 1 ) { $result->free(); throw new RuntimeException(); }
		$row = $result->fetch_row(); $result->free(); return $row;
	}
	private static function autocommitted( $conn, $deadline ) {
		$name = 'bm_b_' . bin2hex( random_bytes( 12 ) );
		self::query( $conn, 'SAVEPOINT ' . $name, $deadline );
		try { $r = $conn->query( 'RELEASE SAVEPOINT ' . $name ); return false === $r && 1305 === $conn->errno; }
		catch ( mysqli_sql_exception $e ) { return 1305 === $e->getCode(); }
	}
	private static function reasons( $reasons ) {
		if ( ! is_array( $reasons ) ) { return false; }
		foreach ( $reasons as $reason ) { if ( ! is_string( $reason ) ) { return false; } }
		return is_array( $reasons ) && count( $reasons ) <= 11 && array_values( array_unique( $reasons, SORT_REGULAR ) ) === $reasons
			&& ! array_diff( $reasons, array( 'phone', 'email', 'ip', 'domain', 'name', 'billing', 'shipping', 'disposable_phone', 'disposable_email', 'proxy', 'device' ) );
	}
	private static function valid( $s ) {
		if ( ! is_array( $s ) || array_keys( $s ) !== array( 'v', 'sample_id', 'phase', 'at', 'until', 'expires', 'failures', 'reasons' ) || 1 !== $s['v']
			|| ! is_string( $s['sample_id'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $s['sample_id'] )
			|| ! in_array( $s['phase'], array( 'idle', 'pending', 'attempted', 'exhausted' ), true ) || ! self::reasons( $s['reasons'] ) ) { return false; }
		foreach ( array( 'at', 'until', 'expires', 'failures' ) as $key ) { if ( ! is_int( $s[$key] ) || $s[$key] < 0 || $s[$key] > 2147483647 ) { return false; } }
		return $s['failures'] <= 3 && $s['until'] >= $s['at'] + self::WINDOW && $s['until'] <= $s['at'] + 2 * self::WINDOW && $s['expires'] === $s['at'] + self::WINDOW
			&& ( 'pending' !== $s['phase'] || ( $s['reasons'] && $s['failures'] < 3 ) );
	}
}
