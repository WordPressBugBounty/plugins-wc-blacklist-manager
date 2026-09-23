<?php
/** Fixed Premium usage lifecycle; Core owns observation, claims and delivery. */
defined( 'ABSPATH' ) || exit;
final class WC_Blacklist_Notification_Usage {
	const OPTION = 'wc_blacklist_notification_usage_v1';
	const ELIGIBILITY = 'wc_blacklist_notification_usage_eligibility_v1';
	private static $unarmed = false;
	const TOGGLE = 'wc_blacklist_email_global_usage';
	const HOOK = 'wc_blacklist_notification_usage_poll_v1';
	const SCHEDULE = 'wc_blacklist_notification_usage_five_minutes';
	private static $descriptors = array();
	private static $worker = null;
	private static function epoch_value( $row ) {
		if ( ! $row || ! is_string( $row[0] ) || strlen( $row[0] ) > 256 || ! in_array( $row[1], array( 'no', 'off', 'auto-off' ), true ) ) { return false; }
		$v = json_decode( $row[0], true, 3 );
		return is_array( $v ) && json_encode( $v ) === $row[0] && array_keys( $v ) === array( 'v', 'generation' ) && 1 === $v['v'] && YOGB_BM_Usage::hex_id( $v['generation'] ) ? $v['generation'] : false;
	}
	/** Independent bounded epoch CAS: never enters Provider Store or the usage lock. */
	private static function epoch( $rotate = false ) {
		global $wpdb;
		$authorized = WC_Blacklist_Notification_Policy::background() || ( is_admin() && is_user_logged_in() && ( function_exists( 'wc_blacklist_manager_user_can_manage_area' )
			? wc_blacklist_manager_user_can_manage_area( 'wc_blacklist_notifications_permission', true ) : current_user_can( 'manage_options' ) ) );
		if ( ! $authorized || ! class_exists( 'YOGB_BM_Usage' ) || ! is_object( $wpdb ) || get_class( $wpdb ) !== 'wpdb' || ! $wpdb->dbh instanceof mysqli
			|| ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->options ) ) { return false; }
		$conn = $wpdb->dbh; $session = null; $result = false; $report = ( new mysqli_driver() )->report_mode; $deadline = microtime( true ) + 1.1;
		set_error_handler( static function() { return true; } );
		try {
			mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
			$query = static function( $sql ) use ( $conn, $wpdb, $deadline ) {
				if ( $conn !== $wpdb->dbh || microtime( true ) > $deadline ) { throw new RuntimeException(); }
				return $conn->query( $sql );
			};
			$session = $query( 'SELECT @@autocommit,@@innodb_lock_wait_timeout,@@lock_wait_timeout,CONNECTION_ID()' )->fetch_row();
			if ( '1' !== (string) $session[0] ) { throw new RuntimeException(); }
			$savepoint = 'bm_ue_' . bin2hex( random_bytes( 12 ) ); $query( 'SAVEPOINT ' . $savepoint );
			$autocommit = false;
			try { $query( 'RELEASE SAVEPOINT ' . $savepoint ); } catch ( mysqli_sql_exception $e ) { $autocommit = 1305 === $e->getCode(); }
			if ( ! $autocommit ) { throw new RuntimeException(); }
			$query( 'SET SESSION innodb_lock_wait_timeout=1,lock_wait_timeout=1' );
			$table = '`' . $wpdb->options . '`'; $hex = static function( $v ) { return '0x' . bin2hex( $v ); };
			$engine = $query( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $hex( $wpdb->options ) )->fetch_row();
			$index = $query( "SHOW INDEX FROM {$table} WHERE Key_name='option_name'" );
			$i = $index->fetch_row();
			if ( ! $engine || 'InnoDB' !== $engine[0] || 1 !== $index->num_rows || ! $i || '0' !== (string) $i[1] || 'option_name' !== $i[4] || null !== $i[7] ) { throw new RuntimeException(); }
			$key = $hex( self::ELIGIBILITY );
			$select = "SELECT IF(OCTET_LENGTH(option_value)<=256,option_value,NULL),autoload,SHA2(LEFT(option_value,256),256) FROM {$table} WHERE option_name={$key}";
			$row = $query( $select )->fetch_row(); $result = self::epoch_value( $row );
			if ( $rotate || ! $result ) {
				$result = bin2hex( random_bytes( 32 ) ); $json = json_encode( array( 'v' => 1, 'generation' => $result ) );
				if ( $row ) {
					$query( "UPDATE {$table} SET option_value=" . $hex( $json ) . ",autoload='no' WHERE option_name={$key} AND SHA2(LEFT(option_value,256),256)=" . $hex( $row[2] ) . ' AND BINARY autoload=' . $hex( $row[1] ) );
				} else { $query( "INSERT IGNORE INTO {$table}(option_name,option_value,autoload) VALUES({$key}," . $hex( $json ) . ",'no')" ); }
				if ( 1 !== $conn->affected_rows || self::epoch_value( $query( $select )->fetch_row() ) !== $result ) { throw new RuntimeException(); }
			}
			$end = $query( 'SELECT @@autocommit,CONNECTION_ID()' )->fetch_row();
			if ( '1' !== (string) $end[0] || $end[1] !== $session[3] ) { throw new RuntimeException(); }
		} catch ( Throwable $error ) { $result = false; }
		finally {
			try { if ( $session ) { $conn->query( 'SET SESSION innodb_lock_wait_timeout=' . (int) $session[1] . ',lock_wait_timeout=' . (int) $session[2] ); } }
			catch ( Throwable $error ) { $result = false; }
			mysqli_report( $report ); restore_error_handler();
		}
		return $result;
	}
	public static function rearm_toggle( $value, $old ) {
		if ( 'yes' === $value && 'yes' !== $old && ! self::epoch( true ) ) { return $old; }
		return $value;
	}
	private static function fences( $db, $table ) {
		if ( self::$unarmed ) { return false; }
		$epoch = self::epoch_value( $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=256,option_value,NULL),autoload FROM {$table} WHERE option_name=" . $db::hex( self::ELIGIBILITY ) ) );
		if ( ! $epoch ) { return false; }
		return array( 'eligibility' => YOGB_BM_Usage::digest( 'eligibility', $epoch ) );
	}
	public static function ids() { return array( 'premium.global.usage_75', 'premium.global.usage_90', 'premium.global.usage_limit_reached', 'premium.global.usage_cycle_reset' ); }
	public static function fields() { return array( 'timestamp', 'reasons', 'usage_used', 'usage_limit', 'usage_cycle_end' ); }
	public static function reasons() { return array( 'usage_75_reached', 'usage_90_reached', 'usage_limit_reached', 'usage_cycle_reset' ); }
	public static function register( $service, $items ) {
		if ( $items !== WC_Blacklist_Notification_Global_Events::usage() ) { return false; }
		if ( ! is_array( $items ) || array_keys( $items ) !== array( 0, 1, 2, 3 ) ) { return false; }
		foreach ( $items as $i => $d ) {
			if ( ! is_array( $d ) || ( $d['id'] ?? null ) !== self::ids()[$i] || ( $d['version'] ?? null ) !== 1
				|| ( $d['entitlement'] ?? null ) !== 'free' || ( $d['option'] ?? null ) !== self::TOGGLE
				|| ( $d['fields'] ?? null ) !== self::fields() || ! is_array( $d['reasons'] ?? null ) || array_keys( $d['reasons'] ) !== array( self::reasons()[$i] )
				|| ( $d['severity'] ?? null ) !== array( 'info', 'warning', 'critical', 'info' )[$i]
				|| ! array_key_exists( 'cooldown', $d ) || null !== $d['cooldown'] || ( $d['action'] ?? null ) !== 'global_usage'
				|| ! $service->register( $d ) ) { return false; }
		}
		self::$descriptors = $items; return true;
	}
	public static function ready() { return WC_Blacklist_Notifications::global_ready() && 4 === count( self::$descriptors ); }
	public static function owns( $id ) { return in_array( $id, self::ids(), true ); }
	public static function worker( $ticket ) { return null !== self::$worker && $ticket === self::$worker && WC_Blacklist_Notification_Policy::background(); }
	private static function fresh() {
		return array( 'v' => 2, 'family' => 'global_usage', 'eligibility' => '', 'migration_pending' => false, 'observation_eligibility_epoch' => '', 'binding' => '', 'cycle' => '', 'policy' => '', 'start' => 0, 'end' => 0,
			'observed' => 0, 'used' => 0, 'high_water' => 0, 'accepted' => false, 'closed' => false,
			'phase' => 'idle', 'event' => '', 'token' => '', 'claim' => '', 'at' => 0, 'expires' => 0, 'failures' => 0, 'preflight_at' => 0 );
	}
	private static function valid( $s ) {
		if ( ! is_array( $s ) || array_keys( $s ) !== array_keys( self::fresh() ) || 2 !== $s['v'] || 'global_usage' !== $s['family']
			|| ! is_bool( $s['migration_pending'] ) || ! is_bool( $s['accepted'] ) || ! is_bool( $s['closed'] ) || ! in_array( $s['high_water'], array( 0, 75, 90, 100 ), true )
			|| ! in_array( $s['phase'], array( 'idle', 'pending', 'attempted', 'spent' ), true ) || ( '' !== $s['event'] && ! self::owns( $s['event'] ) ) ) { return false; }
		foreach ( array( 'eligibility', 'observation_eligibility_epoch', 'binding', 'cycle', 'policy', 'token', 'claim' ) as $k ) { if ( '' !== $s[$k] && ! YOGB_BM_Usage::hex_id( $s[$k] ) ) { return false; } }
		foreach ( array( 'start', 'end', 'observed', 'used', 'at', 'expires', 'failures', 'preflight_at' ) as $k ) { if ( ! YOGB_BM_Usage::integer( $s[$k] ) ) { return false; } }
		if ( $s['migration_pending'] && ( $s['accepted'] || 'idle' !== $s['phase'] || $s['event'] || $s['token'] || $s['claim'] || $s['at'] || $s['expires'] || $s['failures'] || $s['preflight_at'] ) ) { return false; }
		if ( $s['failures'] > 3 || ( $s['accepted'] && ( $s['high_water'] < 75 || ! $s['token'] ) ) ) { return false; }
		if ( '' === $s['binding'] ) { return $s === self::fresh(); }
		return YOGB_BM_Usage::hex_id( $s['eligibility'] ) && YOGB_BM_Usage::hex_id( $s['observation_eligibility_epoch'] ) && '' !== $s['cycle'] && '' !== $s['policy'] && $s['end'] > $s['start'] && $s['observed'] >= $s['start'] && $s['observed'] < $s['end']
			&& ( 'pending' !== $s['phase'] || ( $s['event'] && $s['token'] && $s['failures'] < 3 && ! $s['closed'] ) )
			&& ( 'attempted' !== $s['phase'] || ( $s['token'] && $s['claim'] ) );
	}
	private static function legacy_fresh() {
		return array( 'v' => 1, 'family' => 'global_usage', 'eligibility' => '', 'premium_eligibility' => '', 'observation_eligibility_epoch' => '', 'binding' => '', 'cycle' => '', 'policy' => '', 'start' => 0, 'end' => 0,
			'observed' => 0, 'used' => 0, 'high_water' => 0, 'accepted' => false, 'closed' => false,
			'phase' => 'idle', 'event' => '', 'token' => '', 'claim' => '', 'at' => 0, 'expires' => 0, 'failures' => 0, 'preflight_at' => 0 );
	}
	private static function legacy_valid( $s ) {
		if ( ! is_array( $s ) || array_keys( $s ) !== array_keys( self::legacy_fresh() ) || 1 !== $s['v'] || 'global_usage' !== $s['family']
			|| ! is_bool( $s['accepted'] ) || ! is_bool( $s['closed'] ) || ! in_array( $s['high_water'], array( 0, 75, 90, 100 ), true )
			|| ! in_array( $s['phase'], array( 'idle', 'pending', 'attempted', 'spent' ), true ) || ( '' !== $s['event'] && ! self::owns( $s['event'] ) ) ) { return false; }
		foreach ( array( 'eligibility', 'premium_eligibility', 'observation_eligibility_epoch', 'binding', 'cycle', 'policy', 'token', 'claim' ) as $k ) { if ( '' !== $s[$k] && ! YOGB_BM_Usage::hex_id( $s[$k] ) ) { return false; } }
		foreach ( array( 'start', 'end', 'observed', 'used', 'at', 'expires', 'failures', 'preflight_at' ) as $k ) { if ( ! YOGB_BM_Usage::integer( $s[$k] ) ) { return false; } }
		if ( $s['failures'] > 3 || ( $s['accepted'] && ( $s['high_water'] < 75 || ! $s['token'] ) ) ) { return false; }
		if ( '' === $s['binding'] ) { return $s === self::legacy_fresh(); }
		return YOGB_BM_Usage::hex_id( $s['eligibility'] ) && YOGB_BM_Usage::hex_id( $s['premium_eligibility'] ) && YOGB_BM_Usage::hex_id( $s['observation_eligibility_epoch'] ) && '' !== $s['cycle'] && '' !== $s['policy'] && $s['end'] > $s['start'] && $s['observed'] >= $s['start'] && $s['observed'] < $s['end']
			&& ( 'pending' !== $s['phase'] || ( $s['event'] && $s['token'] && $s['failures'] < 3 && ! $s['closed'] ) )
			&& ( 'attempted' !== $s['phase'] || ( $s['token'] && $s['claim'] ) );
	}
	private static function migrate( $s ) {
		if ( ! self::legacy_valid( $s ) || ! $s['binding'] ) { return self::fresh(); }
		$n = self::fresh();
		foreach ( array( 'eligibility', 'observation_eligibility_epoch', 'binding', 'cycle', 'policy', 'start', 'end', 'observed', 'used', 'high_water', 'closed' ) as $key ) { $n[$key] = $s[$key]; }
		$n['migration_pending'] = true;
		return $n;
	}
	private static function baseline( $o, $closed = false, $fences = array() ) {
		$u = $o['usage']; $s = array_replace( self::fresh(), $fences );
		$s['observation_eligibility_epoch'] = $o['observation_eligibility_epoch'];
		$s['binding'] = $o['binding']; $s['cycle'] = $u['cycle_id']; $s['policy'] = $u['policy_id'] ?? str_repeat( '0', 64 );
		$s['start'] = $u['cycle_start']; $s['end'] = $u['cycle_end']; $s['observed'] = $u['observed_at']; $s['used'] = $u['used'] ?? 0;
		$s['closed'] = $closed || $o['closed'] || 'finite' !== $u['mode'];
		$s['high_water'] = $s['closed'] ? 100 : YOGB_BM_Usage::threshold( $u ); return $s;
	}
	/** Pure state transitions; only authoritative observations can create edges. */
	private static function evolve( $s, $o, $now, $enabled, $fences = null ) {
		if ( ! $enabled || ! $fences ) { return $s['migration_pending'] ? $s : self::fresh(); }
		if ( ! $o ) {
			if ( $s['observed'] && $now - $s['observed'] > 45 * 86400 ) { return self::fresh(); }
			if ( 'pending' === $s['phase'] ) { $s['phase'] = 'spent'; }
			return $s;
		}
		$u = $o['usage'];
		if ( $s['migration_pending'] ) {
			if ( ! $o['notification_eligible'] ) { return $s; }
			$n = self::baseline( $o, false, $fences );
			if ( $s['binding'] === $o['binding'] && $s['cycle'] === $u['cycle_id'] && $s['policy'] === $u['policy_id'] && $s['start'] === $u['cycle_start'] && $s['end'] === $u['cycle_end'] ) {
				if ( $u['observed_at'] < $s['observed'] ) { return $s; }
				$n['high_water'] = max( $s['high_water'], $n['high_water'] );
				$n['closed'] = $n['closed'] || $s['closed'] || $u['used'] < $s['used'];
				if ( $n['closed'] ) { $n['high_water'] = 100; }
			}
			return $n;
		}
		if ( ! $o['notification_eligible'] || $s['observation_eligibility_epoch'] !== $o['observation_eligibility_epoch'] || '' === $s['binding'] || $s['binding'] !== $o['binding'] || $s['eligibility'] !== $fences['eligibility'] ) { return self::baseline( $o, false, $fences ); }
		if ( $u['observed_at'] < $s['observed'] ) { return $s; }
		if ( $s['cycle'] === $u['cycle_id'] ) {
			if ( $s['closed'] || $o['closed'] || 'finite' !== $u['mode'] || $s['policy'] !== $u['policy_id'] || $u['used'] < $s['used'] ) { return self::baseline( $o, true, $fences ); }
			$reset = false;
		} else {
			$contiguous = ! $o['closed'] && 'finite' === $u['mode'] && $s['policy'] === $u['policy_id']
				&& $s['end'] === $u['cycle_start'] && $u['cycle_start'] > $s['start'];
			if ( ! $contiguous ) { return self::baseline( $o, false, $fences ); }
			$reset = ! $s['closed'] && $s['accepted']; $s = self::baseline( $o, false, $fences ); $s['high_water'] = 0;
		}
		$s['observed'] = $u['observed_at']; $s['used'] = $u['used'];
		$threshold = YOGB_BM_Usage::threshold( $u ); $event = '';
		if ( $threshold > $s['high_water'] ) { $event = self::ids()[array_search( $threshold, array( 75, 90, 100 ), true )]; $s['high_water'] = $threshold; }
		elseif ( $reset ) { $event = self::ids()[3]; }
		if ( $event ) {
			$s['phase'] = 'pending'; $s['event'] = $event; $s['token'] = bin2hex( random_bytes( 32 ) ); $s['claim'] = '';
			$s['at'] = $now; $s['expires'] = YOGB_BM_Usage::expires( $u ); $s['failures'] = 0; $s['preflight_at'] = 0;
		} elseif ( 'pending' === $s['phase'] && $s['expires'] <= $now ) { $s['phase'] = 'spent'; }
		return $s;
	}
	private static function eligible( $db, $table ) {
		if ( ! self::ready() ) { return false; }
		foreach ( array( self::TOGGLE => 'yes', 'wc_blacklist_enable_global_blacklist' => '1' ) as $k => $v ) {
			$r = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=3,option_value,NULL) FROM {$table} WHERE option_name=" . $db::hex( $k ) );
			if ( ! $r || $r[0] !== $v ) { return false; }
		} return true;
	}
	/** Called only inside the source's supported, locked database scope. */
	public static function observation_eligible( $db, $table ) {
		if ( ! YOGB_BM_Usage::context() ) { return false; }
		try {
			$fences = self::fences( $db, $table );
			return $fences && self::eligible( $db, $table ) && self::fences( $db, $table ) === $fences;
		} catch ( Throwable $error ) { return false; }
	}
	private static function pending( $s, $now ) { return 'pending' === $s['phase'] && $s['expires'] > $now && ( ! $s['preflight_at'] || $now - $s['preflight_at'] >= 300 ); }
	private static function run( $op, $ticket = null ) {
		if ( ! class_exists( 'YOGB_BM_Usage' ) ) { return array( 'status' => 'state_unavailable' ); }
		return YOGB_BM_Usage::database( static function( $db, $table, $now ) use ( $op, $ticket ) {
			$row = YOGB_BM_Usage::row( $db, $table, self::OPTION ); $s = YOGB_BM_Usage::decode( $row ); $valid = self::valid( $s );
			if ( ! $valid && ! in_array( $op, array( 'maintain', 'poll' ), true ) ) { throw new RuntimeException(); }
			$n = $valid ? $s : self::migrate( $s ); $status = 'ready'; $o = null;
			if ( 'read' !== $op && 'maintain' !== $op ) {
				$fences = self::fences( $db, $table ); $enabled = $fences && self::eligible( $db, $table ); $o = $enabled ? YOGB_BM_Usage::read( $db, $table, $now ) : null;
				if ( 'poll' === $op ) { $n = self::evolve( $n, $o, $now, $enabled, $fences ); }
				else {
					if ( ! $enabled || $s['migration_pending'] || $s['eligibility'] !== $fences['eligibility'] || ! $o || ! $o['notification_eligible'] || $s['observation_eligibility_epoch'] !== $o['observation_eligibility_epoch'] || $o['closed'] || 'finite' !== $o['usage']['mode'] || ! is_array( $ticket ) || $ticket['state'] !== $s || $ticket['source'] !== $o ) { throw new RuntimeException(); }
					if ( true !== apply_filters( 'wc_blacklist_manager_notification_policy_v1', true, $s['event'] )
						|| self::fences( $db, $table ) !== $fences || ! self::eligible( $db, $table ) || YOGB_BM_Usage::read( $db, $table, $now ) !== $o
						|| YOGB_BM_Usage::decode( YOGB_BM_Usage::row( $db, $table, self::OPTION ) ) !== $s ) { throw new RuntimeException(); }
					if ( 'confirm' === $op || 'complete' === $op ) {
						if ( 'attempted' !== $s['phase'] || ! $s['claim'] ) { throw new RuntimeException(); }
						if ( 'complete' === $op && self::ids()[3] !== $s['event'] ) { $n['accepted'] = true; }
					} else {
						if ( ! self::pending( $s, $now ) ) { throw new RuntimeException(); }
						if ( 'failure' === $op ) { ++$n['failures']; $n['preflight_at'] = $now; $status = 'retry'; if ( 3 === $n['failures'] ) { $n['phase'] = 'spent'; } }
						else { $n['phase'] = 'attempted'; $n['claim'] = bin2hex( random_bytes( 32 ) ); $status = 'claimed'; }
					}
				}
			}
			if ( ! self::valid( $n ) ) { throw new RuntimeException(); }
			if ( $n !== $s ) { YOGB_BM_Usage::put( $db, $table, self::OPTION, $row, $n ); }
			return array( 'status' => $status, 'state' => $n, 'source' => $o, 'now' => $now );
		} );
	}
	public static function claim( $ticket, $ready = true ) { return self::worker( $ticket ) ? self::run( $ready ? 'claim' : 'failure', $ticket ) : array( 'status' => 'state_unavailable' ); }
	public static function confirm( $ticket, $claimed ) { return self::worker( $ticket ) && 'ready' === self::run( 'confirm', $claimed )['status']; }
	public static function complete( $ticket, $claimed ) { if ( self::worker( $ticket ) ) { self::run( 'complete', $claimed ); } }
	public static function plan_action( $id ) {
		if ( ! self::$worker || self::$worker['state']['event'] !== $id || self::ids()[3] === $id || ! self::$worker['source']['notification_eligible'] || ! self::$worker['source']['plan_options'] ) { return ''; }
		return 'global_plan_options';
	}
	public static function poll() {
		if ( ! WC_Blacklist_Notification_Policy::background() || self::$worker ) { return; }
		$r = self::run( 'poll' ); if ( 'ready' !== $r['status'] || ! self::pending( $r['state'], $r['now'] ) ) { return; }
		self::$worker = array( 'state' => $r['state'], 'source' => $r['source'] );
		try { return wc_blacklist_manager_notifications()->deliver_usage( self::$worker ); } finally { self::$worker = null; }
	}
	/** Missing usage stays opt-in. Retained for callers of the former initializer. */
	public static function initialize() { return false; }
	public static function maintain() {
		if ( ! WC_Blacklist_Notification_Blocked_State::maintenance_context() ) { return false; }
		$rotate = self::$unarmed || ! wp_next_scheduled( self::HOOK );
		if ( ! self::epoch( $rotate ) ) { self::$unarmed = true; return false; } self::$unarmed = false;
		$r = self::run( 'maintain' ); if ( 'ready' !== $r['status'] ) { return false; }
		return wp_next_scheduled( self::HOOK ) ? true : true === wp_schedule_event( ( intdiv( time(), 300 ) + 1 ) * 300, self::SCHEDULE, self::HOOK );
	}
	public static function background_maintenance() { if ( WC_Blacklist_Notification_Policy::background() ) { self::maintain(); } }
	public static function schedules( $s ) { $s[self::SCHEDULE] = array( 'interval' => 300, 'display' => 'Blacklist Manager usage notifications' ); return $s; }
	public static function activate() { self::$unarmed = true; wp_clear_scheduled_hook( self::HOOK ); return self::maintain(); }
	public static function deactivate() { self::$unarmed = true; wp_clear_scheduled_hook( self::HOOK ); }
	public static function diagnostic() {
		if ( ! class_exists( 'YOGB_BM_Usage' ) || ! self::ready() || 'yes' !== get_option( self::TOGGLE, 'no' ) ) { return ''; }
		if ( '1' !== (string) get_option( 'wc_blacklist_enable_global_blacklist', '0' ) ) { return __( 'Usage alerts are saved, but Global Blacklist is disabled.', 'wc-blacklist-manager' ); }
		$r = self::run( 'read' );
		$source = YOGB_BM_Usage::database( static function( $db, $table, $now ) { $o = YOGB_BM_Usage::read( $db, $table, $now ); return array( 'status' => $o && $o['notification_eligible'] && ! $o['closed'] && 'finite' === $o['usage']['mode'] ? 'ready' : 'unavailable' ); } );
		if ( 'ready' !== $r['status'] || 'ready' !== $source['status'] ) { return __( 'Usage notifications need a current verified usage snapshot and available notification storage. Local protection continues independently.', 'wc-blacklist-manager' ); }
		$next = wp_next_scheduled( self::HOOK );
		return ! $next || $next < time() - 900 || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? __( 'Usage notifications need a running WordPress cron worker. Observations may be delayed or missed.', 'wc-blacklist-manager' ) : '';
	}
}
add_action( 'admin_init', array( 'WC_Blacklist_Notification_Usage', 'maintain' ), 20 );
add_action( 'init', array( 'WC_Blacklist_Notification_Usage', 'background_maintenance' ), 20 );
add_action( WC_Blacklist_Notification_Usage::HOOK, array( 'WC_Blacklist_Notification_Usage', 'poll' ) );
if ( function_exists( 'add_filter' ) ) { add_filter( 'cron_schedules', array( 'WC_Blacklist_Notification_Usage', 'schedules' ) ); add_filter( 'pre_update_option_' . WC_Blacklist_Notification_Usage::TOGGLE, array( 'WC_Blacklist_Notification_Usage', 'rearm_toggle' ), PHP_INT_MAX, 2 ); }
if ( defined( 'WC_BLACKLIST_MANAGER_PLUGIN_FILE' ) ) {
	register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Usage', 'activate' ) );
	register_deactivation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Usage', 'deactivate' ) );
}
