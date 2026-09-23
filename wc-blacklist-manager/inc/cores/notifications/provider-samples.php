<?php
/** Six fixed, independent sample slots. Cache can suppress, never authorize. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Provider_Samples {
	const OPTION = 'wc_blacklist_notification_samples_v1';
	const GATE = 'wc_blacklist_notification_sample_gates_v1';
	const WINDOW = 900;
	const HOOK = 'wc_blacklist_notification_samples_poll_v1';
	const SCHEDULE = 'wc_blacklist_notification_samples_five_minutes';

	public static function lock_name() {
		global $wpdb;
		return 'bm-ps1:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->options ), 0, 48 );
	}
	public static function suppressed( $slot ) {
		$all = wp_cache_get( 'alloptions', 'options' );
		$raw = is_array( $all ) ? ( $all[self::GATE] ?? null ) : null;
		$gate = is_string( $raw ) && strlen( $raw ) <= 256 ? json_decode( $raw, true ) : null;
		$until = is_array( $gate ) ? ( $gate[$slot] ?? null ) : null;
		return is_int( $until ) && $until > time() && $until <= time() + self::WINDOW;
	}
	public static function admit( array $batch ) {
		$due = array();
		foreach ( $batch as $slot => $reasons ) {
			if ( ! self::reasons( $slot, $reasons ) || ! $reasons ) { return array( 'status' => 'invalid_event' ); }
			if ( ! self::suppressed( $slot ) && WC_Blacklist_Notification_Provider::may_signal( $slot ) ) { $due[$slot] = $reasons; }
		}
		return $due ? self::run( 'admit', $due ) : array( 'status' => 'cooldown' );
	}
	public static function pending() { return self::run( 'read' ); }
	public static function claim( $slot, $id, $ready = true ) {
		if ( ! WC_Blacklist_Notification_Policy::background() || ! is_int( $slot ) || $slot < 0 || $slot > 5
			|| ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) { return array( 'status' => 'state_unavailable' ); }
		return self::run( $ready ? 'claim' : 'failure', array( $slot, $id ) );
	}
	private static function reasons( $slot, $reasons ) {
		$d = WC_Blacklist_Notification_Provider::sample_descriptor( $slot );
		if ( ! $d || ! is_array( $reasons ) || count( $reasons ) > 7 ) { return false; }
		foreach ( $reasons as $r ) { if ( ! is_string( $r ) || ! isset( $d['reasons'][$r] ) ) { return false; } }
		return array_values( array_unique( $reasons ) ) === $reasons;
	}
	private static function valid( $state ) {
		if ( ! is_array( $state ) || array_keys( $state ) !== array( 'v', 'slots' ) || 1 !== $state['v']
			|| ! is_array( $state['slots'] ) || array_keys( $state['slots'] ) !== range( 0, 5 ) ) { return false; }
		foreach ( $state['slots'] as $i => $s ) {
			if ( ! is_array( $s ) || array_keys( $s ) !== array( 'id', 'phase', 'at', 'until', 'expires', 'failures', 'reasons' )
				|| ! is_string( $s['id'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $s['id'] )
				|| ! in_array( $s['phase'], array( 'idle', 'pending', 'attempted', 'exhausted' ), true ) || ! self::reasons( $i, $s['reasons'] ) ) { return false; }
			foreach ( array( 'at', 'until', 'expires', 'failures' ) as $k ) { if ( ! is_int( $s[$k] ) || $s[$k] < 0 || $s[$k] > 2147483647 ) { return false; } }
			if ( $s['failures'] > 3 || $s['expires'] !== $s['at'] + self::WINDOW || $s['until'] < $s['expires']
				|| $s['until'] > $s['at'] + 2 * self::WINDOW || ( 'pending' === $s['phase'] && ( ! $s['reasons'] || 3 === $s['failures'] ) ) ) { return false; }
		}
		return true;
	}
	private static function fresh( $now, $reasons = array() ) {
		return array( 'id' => bin2hex( random_bytes( 16 ) ), 'phase' => $reasons ? 'pending' : 'idle', 'at' => $now,
			'until' => $now + self::WINDOW, 'expires' => $now + self::WINDOW, 'failures' => 0, 'reasons' => $reasons );
	}
	private static function run( $op, $data = null ) {
		$invalidate = false;
		$result = WC_Blacklist_Notification_Provider_Store::run( static function( $db ) use ( $op, $data, &$invalidate ) {
			global $wpdb;
			$table = $db->table( $wpdb->options );
			$index = $db->row( "SHOW INDEX FROM {$table} WHERE Key_name='option_name'" );
			if ( ! $index || '0' !== (string) $index[1] || 'option_name' !== $index[4] || null !== $index[7] ) { throw new RuntimeException(); }
			if ( 'admit' === $op ) {
				foreach ( $data as $i => $reasons ) {
					if ( ! WC_Blacklist_Notification_Provider::admission_eligible( $i, $db, $table ) ) { unset( $data[$i] ); }
				}
				if ( ! $data ) { return array( 'status' => 'cooldown' ); }
			}
			$db->lock( self::lock_name() );
			$key = $db::hex( self::OPTION ); $gate = $db::hex( self::GATE );
			$now = (int) $db->row( 'SELECT UNIX_TIMESTAMP()' )[0];
			$row = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=16384,option_value,NULL),autoload FROM {$table} WHERE option_name={$key}" );
			$state = $row ? json_decode( (string) $row[0], true ) : null;
			$valid = self::valid( $state ) && in_array( $row[1], array( 'no', 'off', 'auto-off' ), true );
			$next = $state; $changed = false; $update_gate = false;
			$status = 'duplicate';
			if ( 'maintain' === $op ) {
				if ( ! $valid ) {
					$next = array( 'v' => 1, 'slots' => array() );
					for ( $i = 0; $i < 6; ++$i ) { $next['slots'][] = self::fresh( $now ); }
					$changed = true;
				} else {
					foreach ( $next['slots'] as &$s ) {
						if ( $s['expires'] <= $now && $s['reasons'] ) { $s['reasons'] = array(); $s['phase'] = 'exhausted'; $changed = true; }
					}
					unset( $s );
				}
				$status = 'ready'; $update_gate = true;
			} elseif ( ! $valid ) { throw new RuntimeException(); }
			elseif ( 'read' === $op ) { return array( 'status' => 'ready', 'state' => $state, 'now' => $now ); }
			elseif ( 'admit' === $op ) {
				$status = 'cooldown';
				foreach ( $data as $i => $reasons ) {
					if ( $next['slots'][$i]['until'] <= $now ) {
						$next['slots'][$i] = self::fresh( $now, $reasons ); $changed = true; $update_gate = true; $status = 'buffered';
					}
				}
			} else {
				list( $i, $id ) = $data; $s = &$next['slots'][$i];
				if ( hash_equals( $s['id'], $id ) && 'pending' === $s['phase'] && $s['expires'] > $now ) {
					$changed = true;
					if ( 'failure' === $op ) {
						++$s['failures']; $status = $s['failures'] < 3 ? 'retry' : 'duplicate';
						if ( 3 === $s['failures'] ) { $s['phase'] = 'exhausted'; }
					} else { $s['phase'] = 'attempted'; $s['until'] = max( $s['until'], $now + self::WINDOW ); $status = 'claimed'; }
				}
				unset( $s );
			}
			if ( $changed ) {
				$json = json_encode( $next ); $value = $db::hex( $json );
				$db->query( $row ? "UPDATE {$table} SET option_value={$value},autoload='no' WHERE option_name={$key}"
					: "INSERT INTO {$table}(option_name,option_value,autoload) VALUES({$key},{$value},'no')" );
				if ( $db->row( "SELECT option_value FROM {$table} WHERE option_name={$key}" )[0] !== $json ) { throw new RuntimeException(); }
				$db->confirm();
			}
			if ( $update_gate ) {
				$hint = json_encode( array_column( $next['slots'], 'until' ) );
				$old = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=256,option_value,NULL),autoload FROM {$table} WHERE option_name={$gate}" );
				if ( $old && ( $old[0] !== $hint || ! in_array( $old[1], array( 'yes', 'on', 'auto-on', 'auto' ), true ) ) ) {
					$invalidate = true;
					$db->query( "UPDATE {$table} SET option_value=" . $db::hex( $hint ) . ",autoload='yes' WHERE option_name={$gate}" );
				} elseif ( ! $old && 'maintain' === $op ) {
					$invalidate = true;
					$db->query( "INSERT INTO {$table}(option_name,option_value,autoload) VALUES({$gate}," . $db::hex( $hint ) . ",'yes')" );
				}
			}
			return array( 'status' => $status );
		} );
		if ( $invalidate ) { try { wp_cache_delete( 'alloptions', 'options' ); } catch ( Throwable $e ) { /* Suppression-only cache. */ } }
		return $result;
	}
	public static function maintain() {
		if ( ! WC_Blacklist_Notification_Provider::ready() || ! WC_Blacklist_Notification_Policy::premium()
			|| ! WC_Blacklist_Notification_Blocked_State::maintenance_context() ) { return false; }
		$r = self::run( 'maintain' );
		if ( 'ready' !== $r['status'] ) { return false; }
		return wp_next_scheduled( self::HOOK ) ? true : true === wp_schedule_event( ( (int) floor( time() / 300 ) + 1 ) * 300, self::SCHEDULE, self::HOOK );
	}
	public static function background_maintenance() { if ( WC_Blacklist_Notification_Policy::background() ) { self::maintain(); } }
	public static function schedules( $s ) { $s[self::SCHEDULE] = array( 'interval' => 300, 'display' => 'Blacklist Manager notification samples' ); return $s; }
	public static function deactivate() { wp_clear_scheduled_hook( self::HOOK ); }
	public static function diagnostic() {
		if ( ! WC_Blacklist_Notification_Policy::premium() ) { return ''; }
		if ( ! WC_Blacklist_Notification_Provider::present() ) { return __( 'Premium emails use legacy delivery until the supported Premium plugin is updated.', 'wc-blacklist-manager' ); }
		if ( ! WC_Blacklist_Notification_Provider::ready() ) { return __( 'The Premium notification provider is unavailable. Protection continues. Check that both supported plugins are up to date.', 'wc-blacklist-manager' ); }
		$enabled = false;
		for ( $i = 0; $i < 6; ++$i ) { $enabled = $enabled || WC_Blacklist_Notification_Provider::eligible( $i ); }
		if ( ! $enabled ) { return ''; }
		$r = self::pending();
		if ( 'ready' !== $r['status'] ) { return __( 'Sampled registration, comment and form alerts are unavailable with the current notification state or database adapter. Protection continues. Ask an administrator to check the setup and reload this page.', 'wc-blacklist-manager' ); }
		$next = wp_next_scheduled( self::HOOK );
		if ( ! $next || $next < time() - 900 || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) { return __( 'Sampled registration, comment and form alerts need a running WordPress cron worker. Delayed samples may expire without email.', 'wc-blacklist-manager' ); }
		return __( 'Registration, comment and form alerts sample attempts independently, at most once per category every 15 minutes. They contain reason categories only and may be delayed or lost.', 'wc-blacklist-manager' );
	}
	public static function poll() {
		if ( ! WC_Blacklist_Notification_Policy::background() || ! WC_Blacklist_Notification_Provider::ready() || ! WC_Blacklist_Notification_Policy::premium() ) { return; }
		$r = self::pending();
		if ( 'ready' !== $r['status'] ) { return; }
		foreach ( $r['state']['slots'] as $i => $s ) {
			if ( 'pending' !== $s['phase'] || $s['expires'] <= $r['now'] || ! WC_Blacklist_Notification_Provider::eligible( $i ) ) { continue; }
			$d = WC_Blacklist_Notification_Provider::sample_descriptor( $i );
			wc_blacklist_manager_notifications()->dispatch( $d['id'], $s['id'] . ':' . $s['failures'], array( 'sample_id' => $s['id'], 'timestamp' => $s['at'], 'reasons' => $s['reasons'] ) );
		}
	}
}
add_action( 'admin_init', array( 'WC_Blacklist_Notification_Provider_Samples', 'maintain' ), 21 );
add_action( 'init', array( 'WC_Blacklist_Notification_Provider_Samples', 'background_maintenance' ), 21 );
add_action( WC_Blacklist_Notification_Provider_Samples::HOOK, array( 'WC_Blacklist_Notification_Provider_Samples', 'poll' ) );
if ( function_exists( 'add_filter' ) ) { add_filter( 'cron_schedules', array( 'WC_Blacklist_Notification_Provider_Samples', 'schedules' ) ); }
if ( defined( 'WC_BLACKLIST_MANAGER_PLUGIN_FILE' ) ) {
	register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Provider_Samples', 'maintain' ) );
	register_deactivation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Provider_Samples', 'deactivate' ) );
}
