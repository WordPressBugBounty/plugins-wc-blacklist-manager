<?php
/** One bounded authenticated-connection lifecycle; never an enforcement authority. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Operational {
	const OPTION = 'wc_blacklist_notification_operational_v1';
	const TOGGLE = 'wc_blacklist_email_global_connection';
	const ATTENTION = 'premium.global.connection_attention';
	const RESTORED = 'premium.global.connection_restored';
	const RECOVERY_REASON = 'authenticated_connection_restored';
	const HOOK = 'wc_blacklist_notification_operational_poll_v1';
	const SCHEDULE = 'wc_blacklist_notification_operational_five_minutes';
	const DAY = 86400;
	private static $descriptors = array();
	private static $worker = null;

	public static function register( $service, $pair ) {
		if ( $pair !== WC_Blacklist_Notification_Global_Events::connection() ) { return false; }
		if ( ! is_array( $pair ) || array_keys( $pair ) !== array( 0, 1 ) ) { return false; }
		foreach ( $pair as $i => $d ) {
			$reasons = $i ? array( self::RECOVERY_REASON ) : array( 'authentication_attention', 'reporter_inactive' );
			if ( ! is_array( $d ) || ( $d['id'] ?? null ) !== ( $i ? self::RESTORED : self::ATTENTION ) || ( $d['version'] ?? null ) !== 1
				|| ( $d['entitlement'] ?? null ) !== 'free' || ( $d['option'] ?? null ) !== self::TOGGLE
				|| ( $d['severity'] ?? null ) !== ( $i ? 'info' : 'warning' ) || ( $d['fields'] ?? null ) !== array( 'timestamp', 'reasons' )
				|| ! isset( $d['reasons'] ) || ! is_array( $d['reasons'] ) || array_keys( $d['reasons'] ) !== $reasons
				|| ! array_key_exists( 'cooldown', $d ) || null !== $d['cooldown'] || ( $d['action'] ?? null ) !== 'global_connection'
				|| ! $service->register( $d ) ) { return false; }
		}
		self::$descriptors = $pair;
		return true;
	}
	public static function ready() { return WC_Blacklist_Notifications::global_ready() && 2 === count( self::$descriptors ); }
	public static function owns( $id ) { return in_array( $id, array( self::ATTENTION, self::RESTORED ), true ); }
	public static function worker( $ticket ) { return null !== self::$worker && self::$worker === $ticket && WC_Blacklist_Notification_Policy::background(); }
	public static function lock_name() {
		global $wpdb;
		return 'bm-op1:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->options ), 0, 48 );
	}
	private static function fresh( $attention = 0, $restored = 0 ) {
		return array( 'v' => 1, 'family' => 'global_connection', 'phase' => 'idle', 'direction' => '', 'reason' => '', 'episode' => '',
			'source' => '', 'fault_source' => '', 'fault_reason' => '', 'fault_at' => 0, 'at' => 0, 'expires' => 0, 'failures' => 0, 'preflight_at' => 0,
			'claim' => '', 'fault_accepted' => false, 'attention_until' => $attention, 'restored_until' => $restored,
			'unknown_at' => 0, 'healthy_at' => 0, 'healthy_source' => '' );
	}
	private static function valid( $s, $now ) {
		if ( ! is_array( $s ) || array_keys( $s ) !== array_keys( self::fresh() ) || 1 !== $s['v'] || 'global_connection' !== $s['family']
			|| ! in_array( $s['phase'], array( 'idle', 'candidate', 'pending', 'attempted', 'spent' ), true )
			|| ! in_array( $s['direction'], array( '', 'attention', 'restored' ), true )
			|| ! in_array( $s['reason'], array( '', 'authentication_attention', 'reporter_inactive', self::RECOVERY_REASON ), true )
			|| ! in_array( $s['fault_reason'], array( '', 'authentication_attention', 'reporter_inactive' ), true )
			|| ! is_bool( $s['fault_accepted'] ) ) { return false; }
		foreach ( array( 'episode', 'claim', 'source', 'fault_source', 'healthy_source' ) as $k ) {
			$length = in_array( $k, array( 'episode', 'claim' ), true ) ? 32 : 128;
			if ( ! is_string( $s[$k] ) || ( '' !== $s[$k] && ! preg_match( '/^[a-f0-9]{' . $length . '}$/D', $s[$k] ) ) ) { return false; }
		}
		foreach ( array( 'fault_at', 'at', 'expires', 'failures', 'preflight_at', 'attention_until', 'restored_until', 'unknown_at', 'healthy_at' ) as $k ) {
			if ( ! is_int( $s[$k] ) || $s[$k] < 0 || $s[$k] > 2147483647 ) { return false; }
			$limit = in_array( $k, array( 'expires', 'attention_until', 'restored_until' ), true ) ? $now + self::DAY : $now;
			if ( $s[$k] > $limit ) { return false; }
		}
		if ( $s['failures'] > 3 || ( $s['fault_accepted'] && ( 'attempted' !== $s['phase'] && 'pending' !== $s['phase'] ) ) ) { return false; }
		if ( 'idle' === $s['phase'] ) { return $s === self::fresh( $s['attention_until'], $s['restored_until'] ); }
		if ( ! $s['episode'] || ! $s['fault_source'] || ! $s['fault_at'] || ! $s['direction'] || ! $s['reason'] ) { return false; }
		if ( in_array( $s['phase'], array( 'candidate', 'pending' ), true ) && ( ! $s['source'] || ! $s['at'] || $s['expires'] !== $s['at'] + self::DAY || $s['failures'] >= 3 ) ) { return false; }
		return ! $s['fault_accepted'] || ( '' !== $s['claim'] && 'attention' === $s['direction'] ) || ( 'restored' === $s['direction'] && 'pending' === $s['phase'] );
	}
	private static function start( $s, $o, $now ) {
		$n = self::fresh( $s['attention_until'], $s['restored_until'] );
		$n['phase'] = 'candidate'; $n['direction'] = 'attention'; $n['reason'] = $o['category'];
		$n['episode'] = bin2hex( random_bytes( 16 ) ); $n['source'] = $o['generation']; $n['fault_source'] = $o['generation'];
		$n['fault_reason'] = $o['category']; $n['fault_at'] = $o['at']; $n['at'] = $now; $n['expires'] = $now + self::DAY;
		return $n;
	}
	/** Pure transition function. Unchanged observations return exactly the old bytes. */
	private static function evolve( $s, $o, $now, $enabled ) {
		if ( ! $enabled ) { return self::fresh( $s['attention_until'], $s['restored_until'] ); }
		$c = $o['category'];
		// A confirmed reporter replacement inherits neither admission nor suppression history.
		if ( 'idle' !== $s['phase'] && 'unknown' !== $c && '' !== $o['generation']
			&& substr( $o['generation'], 64 ) !== substr( $s['fault_source'], 64 ) ) {
			$s = self::fresh( $s['attention_until'], $s['restored_until'] );
		}
		if ( 'unknown' === $c || 'transient' === $c ) {
			if ( 'idle' === $s['phase'] || 'candidate' === $s['phase'] ) { return self::fresh( $s['attention_until'], $s['restored_until'] ); }
			$s['healthy_at'] = 0; $s['healthy_source'] = '';
			if ( 'pending' === $s['phase'] ) {
				if ( 'restored' === $s['direction'] && $s['fault_accepted'] ) {
					$s['phase'] = 'attempted'; $s['direction'] = 'attention'; $s['reason'] = $s['fault_reason']; $s['source'] = $s['fault_source'];
				} else { $s['phase'] = 'spent'; $s['fault_accepted'] = false; }
			}
			if ( 'unknown' === $c ) {
				if ( ! $s['unknown_at'] ) { $s['unknown_at'] = $now; }
				if ( $now - $s['unknown_at'] >= 2 * self::DAY ) { $s['fault_accepted'] = false; }
			} else { $s['unknown_at'] = 0; }
			return $s;
		}
		// Expire stale history even when the first resumed poll sees a healthy source.
		if ( $s['unknown_at'] && $now - $s['unknown_at'] >= 2 * self::DAY ) { $s['fault_accepted'] = false; }
		$s['unknown_at'] = 0;
		if ( in_array( $s['phase'], array( 'candidate', 'pending' ), true ) && $s['expires'] <= $now ) { $s['phase'] = 'spent'; $s['fault_accepted'] = false; }
		if ( 'healthy' === $c ) {
			if ( 'idle' === $s['phase'] || 'candidate' === $s['phase'] ) { return self::fresh( $s['attention_until'], $s['restored_until'] ); }
			if ( 'restored' === $s['direction'] && 'pending' === $s['phase'] && $s['source'] === $o['generation'] ) { return $s; }
			if ( $o['generation'] === $s['fault_source'] || $o['at'] < $s['fault_at'] ) { return $s; }
			if ( $s['healthy_source'] !== $o['generation'] ) { $s['healthy_source'] = $o['generation']; $s['healthy_at'] = $now; return $s; }
			if ( $now - $s['healthy_at'] < 300 ) { return $s; }
			if ( ! $s['fault_accepted'] || $s['restored_until'] > $now || 'restored' === $s['direction'] ) { return self::fresh( $s['attention_until'], $s['restored_until'] ); }
			$s['phase'] = 'pending'; $s['direction'] = 'restored'; $s['reason'] = self::RECOVERY_REASON;
			$s['source'] = $o['generation']; $s['at'] = $now; $s['expires'] = $now + self::DAY; $s['failures'] = 0; $s['preflight_at'] = 0;
			return $s;
		}
		$s['healthy_at'] = 0; $s['healthy_source'] = '';
		if ( 'idle' === $s['phase'] || 'restored' === $s['direction'] ) { return self::start( $s, $o, $now ); }
		if ( 'candidate' === $s['phase'] ) {
			if ( $s['reason'] !== $c ) { return self::start( $s, $o, $now ); }
			if ( $now - $s['at'] < 300 ) { return $s; }
			$s['phase'] = $s['attention_until'] > $now ? 'spent' : 'pending';
			$s['source'] = $o['generation'];
		} elseif ( 'pending' === $s['phase'] ) {
			if ( $s['reason'] !== $c ) { $s['phase'] = 'spent'; }
			else { $s['source'] = $o['generation']; }
		}
		return $s;
	}
	private static function eligible( $db, $table ) {
		if ( ! self::ready() || ! class_exists( 'YOGB_BM_Registrar' ) ) { return false; }
		foreach ( array( self::TOGGLE => 'yes', 'wc_blacklist_enable_global_blacklist' => '1' ) as $key => $expected ) {
			$r = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=3,option_value,NULL) FROM {$table} WHERE option_name=" . $db::hex( $key ) );
			if ( ! $r || $r[0] !== $expected ) { return false; }
		}
		$components = array();
		foreach ( array( YOGB_BM_Registrar::OPT_API_KEY, YOGB_BM_Registrar::OPT_API_SECRET, YOGB_BM_Registrar::OPT_REPORTER_ID ) as $key ) {
			$components[] = "EXISTS(SELECT 1 FROM {$table} WHERE option_name=" . $db::hex( $key ) . ' AND OCTET_LENGTH(option_value) BETWEEN 1 AND 4096)';
		}
		return '1' === (string) $db->row( 'SELECT ' . implode( ' AND ', $components ) )[0];
	}
	private static function observation( $db, $table, $now ) {
		return class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::notification_observation( $db, $table, $now ) : array( 'category' => 'unknown', 'generation' => '', 'at' => 0 );
	}
	private static function pending( $s, $now ) {
		return 'pending' === $s['phase'] && $s['expires'] > $now && ( ! $s['preflight_at'] || $now - $s['preflight_at'] >= 300 );
	}
	private static function run( $op, $ticket = null ) {
		return WC_Blacklist_Notification_Provider_Store::run( static function( $db ) use ( $op, $ticket ) {
			global $wpdb;
			$table = $db->table( $wpdb->options );
			$index = $db->row( "SHOW INDEX FROM {$table} WHERE Key_name='option_name'" );
			if ( ! $index || '0' !== (string) $index[1] || 'option_name' !== $index[4] || null !== $index[7] ) { throw new RuntimeException(); }
			$db->lock( self::lock_name() );
			$now = (int) $db->row( 'SELECT UNIX_TIMESTAMP()' )[0];
			$key = $db::hex( self::OPTION );
			$row = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=4096,option_value,NULL),autoload FROM {$table} WHERE option_name={$key}" );
			$s = $row && is_string( $row[0] ) ? json_decode( $row[0], true, 8 ) : null;
			$valid = self::valid( $s, $now ) && in_array( $row[1], array( 'no', 'off', 'auto-off' ), true );
			if ( 'diagnostic' === $op ) { return array( 'status' => self::eligible( $db, $table ) ? 'ready' : 'unavailable' ); }
			$n = $s; $status = 'ready';
			if ( ! $valid ) {
				if ( 'maintain' !== $op ) { throw new RuntimeException(); }
				$n = self::fresh( $now + self::DAY, $now + self::DAY );
			} elseif ( 'read' !== $op && 'maintain' !== $op ) {
				$enabled = self::eligible( $db, $table );
				$o = $enabled ? self::observation( $db, $table, $now ) : array( 'category' => 'unknown', 'generation' => '', 'at' => 0 );
				if ( 'poll' === $op ) { $n = self::evolve( $s, $o, $now, $enabled ); }
				else {
					if ( ! $enabled || ! is_array( $ticket ) || $s['episode'] !== $ticket['episode'] || $s['source'] !== $ticket['source']
						|| $o['generation'] !== $ticket['source'] || $s['direction'] !== $ticket['direction'] || $s['reason'] !== $ticket['reason'] ) { throw new RuntimeException(); }
					$id = 'attention' === $s['direction'] ? self::ATTENTION : self::RESTORED;
					if ( true !== apply_filters( 'wc_blacklist_manager_notification_policy_v1', true, $id ) ) { throw new RuntimeException(); }
					// Policy callbacks are trusted, but cannot make an already-read source/toggle current.
					if ( ! self::eligible( $db, $table ) || self::observation( $db, $table, $now ) !== $o ) { throw new RuntimeException(); }
					if ( 'complete' === $op || 'confirm' === $op ) {
						if ( 'attempted' !== $s['phase'] || ! $s['claim'] || $s['claim'] !== $ticket['claim'] ) { throw new RuntimeException(); }
						if ( 'complete' === $op && 'attention' === $s['direction'] ) { $n['fault_accepted'] = true; }
					} else {
						if ( ! self::pending( $s, $now ) || $s['failures'] !== $ticket['failures'] ) { throw new RuntimeException(); }
						if ( 'failure' === $op ) {
							++$n['failures']; $n['preflight_at'] = $now; $status = 'retry';
							if ( 3 === $n['failures'] ) { $n['phase'] = 'spent'; $n['fault_accepted'] = false; }
						} else {
							if ( $s[$s['direction'] . '_until'] > $now ) { throw new RuntimeException(); }
							$n['phase'] = 'attempted'; $n['claim'] = bin2hex( random_bytes( 16 ) ); $n[$s['direction'] . '_until'] = $now + self::DAY;
							$n['fault_accepted'] = false; $status = 'claimed';
							if ( 'attention' === $s['direction'] ) { $n['fault_source'] = $o['generation']; $n['fault_at'] = $o['at']; }
						}
					}
				}
			}
			if ( $n !== $s ) {
				if ( ! self::valid( $n, $now ) ) { throw new RuntimeException(); }
				$json = json_encode( $n );
				if ( ! is_string( $json ) || strlen( $json ) > 4096 ) { throw new RuntimeException(); }
				$value = $db::hex( $json );
				// CAS includes oversized/corrupt old values through a server-side digest, never a full read.
				if ( $row ) {
					$digest = $db->row( "SELECT SHA2(LEFT(option_value,4096),256),autoload FROM {$table} WHERE option_name={$key}" );
					if ( ! $digest || $digest[1] !== $row[1] || ( is_string( $row[0] ) && ! hash_equals( hash( 'sha256', $row[0] ), $digest[0] ) ) ) { throw new RuntimeException(); }
					$db->query( "UPDATE {$table} SET option_value={$value},autoload='no' WHERE option_name={$key} AND SHA2(LEFT(option_value,4096),256)=" . $db::hex( $digest[0] ) . ' AND BINARY autoload=' . $db::hex( $row[1] ) );
				} else { $db->query( "INSERT IGNORE INTO {$table}(option_name,option_value,autoload) VALUES({$key},{$value},'no')" ); }
				if ( '1' !== (string) $db->row( 'SELECT ROW_COUNT()' )[0] ) { throw new RuntimeException(); }
				$check = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=4096,option_value,NULL),autoload FROM {$table} WHERE option_name={$key}" );
				if ( ! $check || $check[0] !== $json || 'no' !== $check[1] ) { throw new RuntimeException(); }
			}
			$db->confirm();
			return array( 'status' => $status, 'state' => $n, 'now' => $now );
		} );
	}
	public static function claim( $ticket, $ready = true ) {
		return self::worker( $ticket ) ? self::run( $ready ? 'claim' : 'failure', $ticket ) : array( 'status' => 'state_unavailable' );
	}
	public static function confirm( $ticket, $claim ) {
		return self::worker( $ticket ) && 'ready' === self::run( 'confirm', $claim )['status'];
	}
	public static function complete( $ticket, $claim ) {
		if ( self::worker( $ticket ) && 'attention' === $claim['direction'] ) { self::run( 'complete', $claim ); }
	}
	public static function poll() {
		if ( ! WC_Blacklist_Notification_Policy::background() || null !== self::$worker ) { return; }
		$r = self::run( 'poll' );
		if ( 'ready' !== $r['status'] || ! self::pending( $r['state'], $r['now'] ) ) { return; }
		self::$worker = $r['state'];
		try { return wc_blacklist_manager_notifications()->deliver_operational( self::$worker ); }
		finally { self::$worker = null; }
	}
	public static function maintain() {
		if ( ! WC_Blacklist_Notification_Blocked_State::maintenance_context() ) { return false; }
		$r = self::run( 'maintain' );
		if ( 'ready' !== $r['status'] ) { return false; }
		return wp_next_scheduled( self::HOOK ) ? true : true === wp_schedule_event( ( (int) floor( time() / 300 ) + 1 ) * 300, self::SCHEDULE, self::HOOK );
	}
	public static function background_maintenance() { if ( WC_Blacklist_Notification_Policy::background() ) { self::maintain(); } }
	public static function schedules( $s ) { $s[self::SCHEDULE] = array( 'interval' => 300, 'display' => 'Blacklist Manager connection notifications' ); return $s; }
	public static function deactivate() { wp_clear_scheduled_hook( self::HOOK ); }
	public static function diagnostic() {
		if ( ! self::ready() || 'yes' !== get_option( self::TOGGLE, 'no' ) ) { return ''; }
		if ( '1' !== (string) get_option( 'wc_blacklist_enable_global_blacklist', '0' ) ) { return __( 'Connection alerts are saved, but Global Blacklist is disabled.', 'wc-blacklist-manager' ); }
		$available = self::run( 'diagnostic' );
		if ( 'ready' !== $available['status'] ) { return __( 'Connection alerts need configured Global Blacklist credentials and available notification storage.', 'wc-blacklist-manager' ); }
		$r = self::run( 'read' );
		if ( 'ready' !== $r['status'] ) { return __( 'Connection notifications are unavailable with the current database adapter or notification state. Protection continues independently.', 'wc-blacklist-manager' ); }
		$next = wp_next_scheduled( self::HOOK );
		return ! $next || $next < time() - 900 || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
			? __( 'Connection notifications need a running WordPress cron worker. Check your server cron setup; short connection episodes may be missed.', 'wc-blacklist-manager' ) : '';
	}
}
add_action( 'admin_init', array( 'WC_Blacklist_Notification_Operational', 'maintain' ), 20 );
add_action( 'init', array( 'WC_Blacklist_Notification_Operational', 'background_maintenance' ), 20 );
add_action( WC_Blacklist_Notification_Operational::HOOK, array( 'WC_Blacklist_Notification_Operational', 'poll' ) );
if ( function_exists( 'add_filter' ) ) { add_filter( 'cron_schedules', array( 'WC_Blacklist_Notification_Operational', 'schedules' ) ); }
if ( defined( 'WC_BLACKLIST_MANAGER_PLUGIN_FILE' ) ) {
	register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Operational', 'maintain' ) );
	register_deactivation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Operational', 'deactivate' ) );
}
