<?php
/** Internal mechanics compiled into the two final, closed digest facades. */
defined( 'ABSPATH' ) || exit;
trait WC_Blacklist_Notification_Digest_Lifecycle {
	private static $provider = null;
	private static $descriptor = null;
	private static $worker = null;
	private static $collecting = null;
	private static $polling = false;

	public static function fields() { return array_merge( array( 'week_start', 'week_end', 'week_timezone' ), array_values( self::COUNTS ), array( 'reasons' ) ); }
	public static function register( $service, $descriptor, $provider ) {
		if ( self::ready() || ! is_callable( $provider ) || ! self::descriptor( $descriptor ) || ! $service->register( $descriptor ) ) { return false; }
		self::$descriptor = $descriptor; self::$provider = $provider; return true;
	}
	public static function descriptor( $d ) {
		return is_array( $d ) && ( $d['id'] ?? null ) === self::ID && ( $d['version'] ?? null ) === 1
			&& ( $d['option'] ?? null ) === self::TOGGLE && ( $d['entitlement'] ?? null ) === 'premium'
			&& ( $d['severity'] ?? null ) === 'info' && ( $d['fields'] ?? null ) === self::fields()
			&& array_key_exists( 'cooldown', $d ) && null === $d['cooldown'] && ( $d['action'] ?? null ) === self::ACTION
			&& is_array( $d['reasons'] ?? null ) && array_keys( $d['reasons'] ) === self::REASONS;
	}
	public static function ready() { return null !== self::$descriptor && is_callable( self::$provider ); }
	public static function owns( $id ) { return self::ID === $id; }
	public static function worker( $ticket ) { return null !== self::$worker && self::$worker === $ticket && WC_Blacklist_Notification_Policy::background(); }
	public static function collecting( $db, $window ) { return self::$collecting === array( $db, $window ) && WC_Blacklist_Notification_Policy::background(); }
	public static function lock_name() { global $wpdb; return self::LOCK_PREFIX . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->options ), 0, 48 ); }

	/** Calendar arithmetic deliberately preserves 167/169-hour DST weeks. */
	public static function calendar( $now, DateTimeZone $zone ) {
		$date = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
		$monday = $date->setTime( 0, 0, 0 )->modify( '-' . ( (int) $date->format( 'N' ) - 1 ) . ' days' );
		$start = $monday->modify( '-1 week' );
		return array( 'start' => $start->getTimestamp(), 'end' => $monday->getTimestamp(),
			'start_local' => $start->format( 'Y-m-d H:i:s' ), 'end_local' => $monday->format( 'Y-m-d H:i:s' ),
			'not_before' => ( $now === $monday->getTimestamp() ? $monday : $monday->modify( '+1 week' ) )->getTimestamp() );
	}
	private static function shape() {
		return array_merge( array( 'v' => 1, 'not_before' => 0, 'timezone' => '', 'fence' => '', 'eligible' => false, 'consumed' => 0,
			'phase' => 'idle', 'start' => 0, 'end' => 0, 'start_local' => '', 'end_local' => '', ), array_fill_keys( array_keys( self::COUNTS ), 0 ), array(
			'source' => 'unavailable', 'source_attempts' => 0, 'source_at' => 0,
			'preflight_attempts' => 0, 'preflight_at' => 0, 'token' => '', 'claim' => '' ) );
	}
	private static function valid( $s ) {
		if ( ! is_array( $s ) || array_keys( $s ) !== array_keys( self::shape() ) || 1 !== $s['v'] || ! is_bool( $s['eligible'] )
			|| ! is_string( $s['timezone'] ) || strlen( $s['timezone'] ) > 64 || ! preg_match( '/^[A-Za-z0-9_+:.\/-]{1,64}$/D', $s['timezone'] )
			|| ! in_array( $s['phase'], array( 'idle', 'collecting', 'prepared', 'claimed', 'spent' ), true )
			|| ! in_array( $s['source'], array( 'ready', 'empty', 'saturated', 'unavailable' ), true ) ) { return false; }
		foreach ( array_merge( array( 'not_before', 'consumed', 'start', 'end', 'source_attempts', 'source_at', 'preflight_attempts', 'preflight_at' ), array_keys( self::COUNTS ) ) as $k ) {
			if ( ! is_int( $s[$k] ) || $s[$k] < 0 || $s[$k] > 2147483647 ) { return false; }
		}
		foreach ( array( 'fence', 'token', 'claim' ) as $k ) { if ( ! is_string( $s[$k] ) || ( '' !== $s[$k] && ! preg_match( '/^[a-f0-9]{64}$/D', $s[$k] ) ) ) { return false; } }
		if ( $s['source_attempts'] > 3 || $s['preflight_attempts'] > 3 || array_sum( array_intersect_key( $s, self::COUNTS ) ) > 10000 || ! $s['not_before'] ) { return false; }
		try { $zone = new DateTimeZone( $s['timezone'] ); } catch ( Throwable $e ) { return false; }
		if ( 'idle' === $s['phase'] ) { return 0 === $s['start'] && 0 === $s['end'] && '' === $s['start_local'] && '' === $s['end_local'] && '' === $s['token'] && '' === $s['claim']; }
		$c = self::calendar( $s['end'], $zone );
		if ( $s['start'] !== $c['start'] || $s['end'] !== $c['end'] || $s['start_local'] !== $c['start_local'] || $s['end_local'] !== $c['end_local'] || $s['start'] < $s['not_before'] || ! $s['token'] ) { return false; }
		if ( in_array( $s['phase'], array( 'prepared', 'claimed' ), true ) && ( 'ready' !== $s['source'] || ! $s['source_attempts'] || ! ( array_sum( array_intersect_key( $s, self::COUNTS ) ) ) ) ) { return false; }
		return 'claimed' !== $s['phase'] || ( '' !== $s['claim'] && $s['consumed'] >= $s['end'] );
	}
	private static function read( $db, $table ) {
		return $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=4096,option_value,NULL),autoload,OCTET_LENGTH(option_value),SHA2(LEFT(option_value,4096),256) FROM {$table} WHERE option_name=" . $db::hex( self::OPTION ) );
	}
	private static function decode( $row ) {
		if ( ! $row || ! is_string( $row[0] ) || ! in_array( $row[1], array( 'no', 'off', 'auto-off' ), true ) ) { return null; }
		$s = json_decode( $row[0], true, 4 );
		return self::valid( $s ) && json_encode( $s ) === $row[0] ? $s : null;
	}
	private static function put( $db, $table, $old, $s ) {
		if ( ! self::valid( $s ) || strlen( json_encode( $s ) ) > 4096 || self::read( $db, $table ) !== $old ) { throw new RuntimeException(); }
		$db->confirm(); $key = $db::hex( self::OPTION ); $json = $db::hex( json_encode( $s ) );
		if ( $old ) {
			$db->query( "UPDATE {$table} SET option_value={$json},autoload='no' WHERE option_name={$key} AND OCTET_LENGTH(option_value)=" . (int) $old[2] . ' AND SHA2(LEFT(option_value,4096),256)=' . $db::hex( $old[3] ) . ' AND BINARY autoload=' . $db::hex( $old[1] ) );
		} else { $db->query( "INSERT IGNORE INTO {$table}(option_name,option_value,autoload) VALUES({$key},{$json},'no')" ); }
		global $wpdb;
		if ( 1 !== $wpdb->dbh->affected_rows || self::decode( self::read( $db, $table ) ) !== $s ) { throw new RuntimeException(); }
		$db->confirm(); wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	}
	private static function option( $db, $table, $key, $cap ) {
		$r = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<={$cap},option_value,NULL) FROM {$table} WHERE option_name=" . $db::hex( $key ) );
		return $r ? $r[0] : null;
	}
	private static function authority( $db, $table, $prior = null ) {
		$zone = wp_timezone(); $name = $zone->getName();
		if ( strlen( $name ) > 64 || ! preg_match( '/^[A-Za-z0-9_+:.\/-]{1,64}$/D', $name ) ) { throw new RuntimeException(); }
		// A stale WordPress options cache is never calendar authority.
		$raw = self::option( $db, $table, 'timezone_string', 64 );
		if ( ! $raw ) {
			$offset = self::option( $db, $table, 'gmt_offset', 8 );
			if ( ! is_string( $offset ) || ! is_numeric( $offset ) || abs( (float) $offset ) > 14 ) { throw new RuntimeException(); }
			$minutes = abs( (int) round( (float) $offset * 60 ) );
			$raw = sprintf( '%s%02d:%02d', (float) $offset < 0 ? '-' : '+', intdiv( $minutes, 60 ), $minutes % 60 );
		}
		if ( $name !== $raw ) { throw new RuntimeException(); }
		// Tagged fences remember v6 admission even when its provider later disappears.
		$v6_tag = 'b0232a06';
		$was_v6 = $prior && 0 === strpos( $prior['fence'], $v6_tag );
		$fence = $was_v6 ? $prior['fence'] : ''; $admitted = false;
		$projection = null;
		try { $projection = apply_filters( 'wc_blacklist_manager_premium_digest_authority_v1', null ); }
		catch ( Throwable $error ) { $projection = false; }
		$keys = is_array( $projection ) ? array_keys( $projection ) : array(); sort( $keys );
		$valid = $keys === array( 'generation', 'runtime', 'status', 'v' ) && 1 === $projection['v'] && is_bool( $projection['runtime'] )
			&& is_string( $projection['generation'] ) && strlen( json_encode( $projection ) ) <= 256
			&& in_array( $projection['status'], array( 'legacy', 'v6', 'unavailable' ), true )
			&& ( 'v6' === $projection['status']
				? ( preg_match( '/^[a-f0-9]{64}$/D', $projection['generation'] ) || ( ! $projection['runtime'] && '' === $projection['generation'] ) )
				: false === $projection['runtime'] && '' === $projection['generation'] );
		if ( $valid && 'v6' === $projection['status'] ) {
			// Preserve the mode even when schema/history/authority cannot admit runtime.
			$fence = $v6_tag . substr( hash( 'sha256', 'bm-digest-v6/v1|' . $projection['generation'] ), 0, 56 );
			if ( '' === $projection['generation'] && $was_v6 ) { $fence = $prior['fence']; }
			$admitted = $projection['runtime'];
		} elseif ( ( null === $projection || ( $valid && 'legacy' === $projection['status'] ) )
			&& class_exists( 'YOBM_License_Runtime', false )
			&& ! method_exists( 'YOBM_License_Runtime', 'v6_projection' ) && ! method_exists( 'YOBM_License_Runtime', 'v6_suppresses_legacy' )
			&& is_callable( array( 'YOBM_License_Runtime', 'is_active' ) ) && is_callable( array( 'YOBM_License_Runtime', 'verify_entitlement' ) )
			&& is_callable( array( 'YOBM_License_Runtime', 'normalize_domain' ) ) ) {
			// A v6-capable is_active() may ignore these v5 bindings. Never use it as v5 proof.
			$args = array( 'license_key_option' => 'wc_blacklist_manager_premium_license_key', 'status_option' => 'wc_blacklist_manager_premium_license_status',
				'state_option' => 'wc_blacklist_manager_premium_license_state', 'entitlement_option' => 'wc_blacklist_manager_premium_entitlement',
				'product_id' => '44', 'check_domain' => true, 'current_domain' => YOBM_License_Runtime::normalize_domain( home_url() ) );
			$parts = array(); $current = true;
			foreach ( array( $args['license_key_option'] => 4096, $args['status_option'] => 32, $args['state_option'] => 32768, $args['entitlement_option'] => 16384 ) as $key => $cap ) {
				$raw = self::option( $db, $table, $key, $cap ); $cached = get_option( $key, null );
				$current = $current && is_string( $raw ) && '' !== $raw && $raw === ( is_array( $cached ) ? serialize( $cached ) : $cached );
				$parts[] = $raw;
			}
			$bytes = json_encode( $parts );
			if ( $current && is_string( $bytes ) && '' !== $args['current_domain'] ) {
				try {
					// Runtime grace alone also accepts missing/unbound tokens. Require its signed binding API too.
					$proof = YOBM_License_Runtime::verify_entitlement( $parts[3], array( 'license_key' => $parts[0], 'product_id' => '44', 'domain' => $args['current_domain'] ) );
					$admitted = is_array( $proof ) && true === YOBM_License_Runtime::is_active( $args );
				} catch ( Throwable $error ) { $admitted = false; }
				if ( $admitted ) { $fence = 'b0237a05' . substr( hash( 'sha256', 'bm-digest-v5/v1|' . $args['current_domain'] . '|' . $bytes ), 0, 56 ); }
			}
		}
		$enabled = self::ready() && $admitted && WC_Blacklist_Notification_Policy::premium() && '' !== $fence;
		return array( 'timezone' => $name, 'fence' => $fence, 'eligible' => $enabled, 'enabled' => 'yes' === self::option( $db, $table, self::TOGGLE, 3 ) );
	}
	private static function baseline( $a, $week, $old = null ) {
		$s = array_replace( self::shape(), array_intersect_key( $a, self::shape() ) ); $s['not_before'] = $week['not_before']; $s['consumed'] = $old ? $old['consumed'] : 0; return $s;
	}
	private static function consume( $s ) { $s['consumed'] = max( $s['consumed'], $s['end'] ); $s['phase'] = 'spent'; return $s; }
	private static function due( $s, $now, $week ) { return $s['eligible'] && $week['start'] >= $s['not_before'] && $week['end'] > $s['consumed'] && $now >= $week['end'] && $now < $week['end'] + 86400; }
	/** Only semantic observations can change calendar admission. */
	private static function changed( $s, $a ) { return ! $s || $s['timezone'] !== $a['timezone'] || $s['eligible'] !== $a['eligible']
		|| ( substr( $s['fence'], 0, 8 ) !== substr( $a['fence'], 0, 8 ) )
		|| ( 0 === strpos( $a['fence'], 'b0232a06' ) && $s['fence'] !== $a['fence'] ); }
	private static function invalidate( $db, $table, $row, $s, $a, $now ) {
		$n = self::changed( $s, $a ) ? self::baseline( $a, self::calendar( $now, new DateTimeZone( $a['timezone'] ) ), $s ) : $s;
		if ( $n === $s && in_array( $s['phase'], array( 'collecting', 'prepared' ), true ) ) { $n = self::consume( $s ); }
		if ( self::decode( $row ) !== $n ) { self::put( $db, $table, $row, $n ); }
		$db->confirm(); return array( 'status' => 'state_unavailable', 'state' => $n );
	}
	private static function run( $op, $ticket = null ) {
		return WC_Blacklist_Notification_Provider_Store::run( static function( $db ) use ( $op, $ticket ) {
			global $wpdb;
			$table = $db->table( $wpdb->options );
			$i = $db->row( "SHOW INDEX FROM {$table} WHERE Key_name='option_name'" );
			if ( ! $i || '0' !== (string) $i[1] || 'option_name' !== $i[4] || null !== $i[7] ) { throw new RuntimeException(); }
			$db->lock( self::lock_name() );
			if ( 'initialize' === $op && $db->row( "SELECT 1 FROM {$table} WHERE option_name=" . $db::hex( self::TOGGLE ) ) ) {
				$db->confirm(); return array( 'status' => 'ready' );
			}
			$now = (int) $db->row( 'SELECT UNIX_TIMESTAMP()' )[0];
			$row = self::read( $db, $table ); $s = self::decode( $row );
			$a = self::authority( $db, $table, $s ); $week = self::calendar( $now, new DateTimeZone( $a['timezone'] ) );
			if ( 'read' === $op ) { return array( 'status' => $s ? 'ready' : 'state_unavailable' ); }
			$changed = self::changed( $s, $a );
			$n = $changed || in_array( $op, array( 'rearm', 'initialize' ), true ) ? self::baseline( $a, $week, $s ) : $s;
			$status = 'ready';
			if ( in_array( $op, array( 'claim', 'confirm', 'failure' ), true ) ) {
				if ( $changed ) { return self::invalidate( $db, $table, $row, $s, $a, $now ); }
				if ( ! is_array( $ticket ) || $ticket['state'] !== $s ) { throw new RuntimeException(); }
				if ( ! $a['eligible'] || ! $a['enabled'] || $s['fence'] !== $a['fence'] || $week['end'] !== $s['end'] || $now < $s['end'] || $now >= $s['end'] + 86400 ) {
					return self::invalidate( $db, $table, $row, $s, $a, $now );
				}
				$allowed = null === WC_Blacklist_Notification_Policy::check( self::$descriptor );
				$current = self::authority( $db, $table, $s );
				if ( self::read( $db, $table ) !== $row ) { throw new RuntimeException(); }
				if ( ! $allowed || $current !== $a ) { return self::invalidate( $db, $table, $row, $s, $current, $now ); }
				if ( 'confirm' === $op ) {
					if ( 'claimed' !== $s['phase'] || ! $s['claim'] ) { throw new RuntimeException(); }
				} else {
					if ( 'prepared' !== $s['phase'] || ! self::due( $s, $now, $week ) || ! $s['preflight_attempts'] ) { throw new RuntimeException(); }
					if ( 'failure' === $op ) { if ( 3 === $s['preflight_attempts'] ) { $n = self::consume( $s ); } }
					else { $n['phase'] = 'claimed'; $n['claim'] = bin2hex( random_bytes( 32 ) ); $n['consumed'] = max( $s['consumed'], $s['end'] ); $status = 'claimed'; }
				}
			} elseif ( 'poll' === $op && ! $changed ) {
				// A prepared/source attempt never rebinds to newer validation bytes.
				if ( in_array( $s['phase'], array( 'collecting', 'prepared' ), true ) && ( ! $a['enabled'] || ( $s['end'] === $week['end'] && $s['fence'] !== $a['fence'] ) ) ) {
					return self::invalidate( $db, $table, $row, $s, $a, $now );
				}
				if ( $a['eligible'] && $a['enabled'] && $week['start'] >= $n['not_before'] && $week['end'] > $n['consumed'] ) {
					if ( $n['end'] !== $week['end'] ) {
						$n = array_replace( self::baseline( $a, $week, $s ), array_intersect_key( $week, array_flip( array( 'start', 'end', 'start_local', 'end_local' ) ) ) );
						$n['not_before'] = $s['not_before']; $n['phase'] = 'collecting'; $n['token'] = bin2hex( random_bytes( 32 ) );
					}
					$allowed = null === WC_Blacklist_Notification_Policy::check( self::$descriptor );
					$current = self::authority( $db, $table, $s );
					if ( self::read( $db, $table ) !== $row ) { throw new RuntimeException(); }
					if ( $current !== $a ) { return self::invalidate( $db, $table, $row, $n, $current, $now ); }
					if ( ! self::due( $n, $now, $week ) || ! $allowed ) { $n = self::consume( $n ); }
					elseif ( 'collecting' === $n['phase'] && ( ! $n['source_at'] || $now - $n['source_at'] >= 3600 ) ) {
						if ( 3 === $n['source_attempts'] ) { $n = self::consume( $n ); }
						else {
							// Reserve before callback/SQL: even process death spends one finite attempt.
							++$n['source_attempts']; $n['source_at'] = $now;
							self::put( $db, $table, $row, $n ); $row = self::read( $db, $table ); $s = $n;
							$window = array( 'start_local' => $n['start_local'], 'end_local' => $n['end_local'] );
							self::$collecting = array( $db, $window );
							try { $batch = call_user_func( self::$provider, $db, $window ); } catch ( Throwable $e ) { $batch = null; }
							finally { self::$collecting = null; }
							$db->checkpoint(); $current = self::authority( $db, $table, $s );
							if ( self::read( $db, $table ) !== $row ) { throw new RuntimeException(); }
							if ( $current !== $a ) { return self::invalidate( $db, $table, $row, $s, $current, $now ); }
							$batch = self::batch( $batch ); $n['source'] = $batch['status'];
							if ( 'ready' === $batch['status'] ) { foreach ( self::COUNTS as $key => $field ) { $n[$key] = $batch[$key]; } $n['phase'] = 'prepared'; }
							elseif ( 'unavailable' !== $batch['status'] || 3 === $n['source_attempts'] ) { $n = self::consume( $n ); }
						}
					}
					if ( 'prepared' === $n['phase'] && ( ! $n['preflight_at'] || $now - $n['preflight_at'] >= 3600 ) ) {
						if ( 3 === $n['preflight_attempts'] ) { $n = self::consume( $n ); }
						else { ++$n['preflight_attempts']; $n['preflight_at'] = $now; $status = 'prepared'; }
					}
				}
			}
			if ( $n !== $s ) { self::put( $db, $table, $row, $n ); }
			$db->confirm();
			if ( 'initialize' === $op ) {
				if ( self::decode( self::read( $db, $table ) ) !== $n || self::authority( $db, $table, $n ) !== $a ) { throw new RuntimeException(); }
				$key = $db::hex( self::TOGGLE );
				$db->confirm();
				$db->query( "INSERT IGNORE INTO {$table}(option_name,option_value,autoload) VALUES({$key},'yes','no')" );
				if ( ! $db->row( "SELECT 1 FROM {$table} WHERE option_name={$key}" ) ) { throw new RuntimeException(); }
				$db->confirm();
			}
			return array( 'status' => $status, 'state' => $n );
		} );
	}
	public static function batch( $b ) {
		$no = array_merge( array( 'status' => 'unavailable' ), array_fill_keys( array_keys( self::COUNTS ), 0 ) );
		if ( ! is_array( $b ) || array_keys( $b ) !== array_keys( $no ) || ! in_array( $b['status'], array( 'ready', 'empty', 'saturated', 'unavailable' ), true ) ) { return $no; }
		foreach ( array_keys( self::COUNTS ) as $k ) { if ( ! is_int( $b[$k] ) || $b[$k] < 0 || $b[$k] > 10000 ) { return $no; } }
		$total = array_sum( array_intersect_key( $b, self::COUNTS ) );
		return $total <= 10000 && ( 'ready' === $b['status'] ? $total > 0 : 0 === $total ) ? $b : $no;
	}
	public static function poll() {
		if ( ! WC_Blacklist_Notification_Policy::background() || self::$polling ) { return; }
		self::$polling = true;
		try {
			$r = self::run( 'poll' ); if ( 'prepared' !== $r['status'] ) { return; }
			self::$worker = array( 'state' => $r['state'] );
			$method = self::DELIVERY; return wc_blacklist_manager_notifications()->$method( self::$worker );
		} finally { self::$worker = null; self::$polling = false; }
	}
	public static function claim( $ticket, $ready = true ) { return self::worker( $ticket ) ? self::run( $ready ? 'claim' : 'failure', $ticket ) : array( 'status' => 'state_unavailable' ); }
	public static function confirm( $ticket, $state ) { return self::worker( $ticket ) && 'ready' === self::run( 'confirm', array( 'state' => $state ) )['status']; }
	private static function setting_context() {
		return WC_Blacklist_Notification_Policy::background() || ( is_admin() && is_user_logged_in() && ( function_exists( 'wc_blacklist_manager_user_can_manage_area' )
			? wc_blacklist_manager_user_can_manage_area( 'wc_blacklist_notifications_permission', true ) : current_user_can( 'manage_options' ) ) );
	}
	public static function rearm_toggle( $value, $old ) {
		if ( 'yes' !== $value || 'yes' === $old ) { return $value; }
		if ( ! self::setting_context() ) { return $old; }
		return 'ready' === self::run( 'rearm' )['status'] ? $value : $old;
	}
	/** Each facade must establish its own baseline, despite the shared cron hook. */
	public static function initialize() {
		if ( ! self::setting_context() || ( ! WC_Blacklist_Notification_Policy::background() && wp_doing_ajax() ) ) { return false; }
		$r = self::run( 'initialize' );
		foreach ( array( self::TOGGLE, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
		return 'ready' === $r['status'];
	}
	public static function maintain() {
		if ( ! WC_Blacklist_Notification_Blocked_State::maintenance_context() || ! self::initialize() ) { return false; }
		// Repair a missing schedule without changing a valid calendar baseline.
		$next = wp_next_scheduled( self::HOOK );
		if ( ! $next && 'ready' !== self::run( 'maintain' )['status'] ) { return false; }
		return $next ? true : true === wp_schedule_event( time() + 3600, 'hourly', self::HOOK );
	}
	public static function background_maintenance() { if ( WC_Blacklist_Notification_Policy::background() ) { self::maintain(); } }
	public static function deactivate() { wp_clear_scheduled_hook( self::HOOK ); }
	public static function diagnostic() {
		if ( ! self::ready() || ! WC_Blacklist_Notification_Blocked_State::maintenance_context() || 'yes' !== get_option( self::TOGGLE, 'no' ) ) { return ''; }
		return 'ready' !== self::run( 'read' )['status'] || ! wp_next_scheduled( self::HOOK )
			? __( 'The weekly digest needs a working background worker and local storage. Weeks may be missed.', 'wc-blacklist-manager' ) : '';
	}
}