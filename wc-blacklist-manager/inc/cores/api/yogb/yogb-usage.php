<?php
/** Verified Global usage projection. Local quota estimates are never inputs. */
defined( 'ABSPATH' ) || exit;

final class YOGB_BM_Usage {
	const OPTION = 'yogb_bm_usage_snapshot_v1';
	const MAX = 2147483647;
	public static function context() {
		return WC_Blacklist_Notification_Policy::background() || ( is_admin() && is_user_logged_in() && current_user_can( 'manage_options' ) );
	}
	public static function digest( $purpose, $value ) { return hash_hmac( 'sha256', 'bm-usage-v1|' . $purpose . '|' . $value, wp_salt( 'nonce' ) ); }
	public static function hex_id( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $v ); }
	public static function integer( $v ) { return is_int( $v ) && $v >= 0 && $v <= self::MAX; }
	/** Full closed schema, normalized to a stable key order. */
	public static function normalize( $u, $timestamp ) {
		$keys = array( 'version', 'scope', 'unit', 'cycle_id', 'cycle_start', 'cycle_end', 'observed_at', 'mode', 'used', 'limit', 'remaining', 'limit_reached', 'policy_id' );
		if ( ! is_array( $u ) || count( $u ) !== count( $keys ) || array_diff( $keys, array_keys( $u ) )
			|| 1 !== $u['version'] || 'month' !== $u['scope'] || 'checks' !== $u['unit'] || ! self::hex_id( $u['cycle_id'] )
			|| ! self::integer( $u['cycle_start'] ) || ! self::integer( $u['cycle_end'] ) || ! self::integer( $u['observed_at'] )
			|| $u['observed_at'] !== $timestamp || $u['cycle_start'] > $timestamp || $timestamp >= $u['cycle_end']
			|| gmdate( 'd H:i:s', $u['cycle_start'] ) !== '01 00:00:00'
			|| strtotime( gmdate( 'Y-m-d', $u['cycle_start'] ) . ' +1 month UTC' ) !== $u['cycle_end'] ) { return false; }
		if ( 'unknown' === $u['mode'] ) {
			foreach ( array( 'used', 'limit', 'remaining', 'limit_reached', 'policy_id' ) as $k ) { if ( null !== $u[$k] ) { return false; } }
		} else {
			if ( ! self::integer( $u['used'] ) || ! self::hex_id( $u['policy_id'] ) ) { return false; }
			if ( 'finite' === $u['mode'] ) {
				if ( ! self::integer( $u['limit'] ) || 0 === $u['limit'] || ! self::integer( $u['remaining'] )
					|| $u['remaining'] !== max( 0, $u['limit'] - $u['used'] ) || $u['limit_reached'] !== ( $u['used'] >= $u['limit'] ) ) { return false; }
			} elseif ( 'unlimited' !== $u['mode'] || null !== $u['limit'] || null !== $u['remaining'] || false !== $u['limit_reached'] ) { return false; }
		}
		return array_replace( array_fill_keys( $keys, null ), $u );
	}
	public static function threshold( $u ) {
		if ( ! is_array( $u ) || 'finite' !== $u['mode'] ) { return 0; }
		if ( $u['limit_reached'] ) { return 100; }
		foreach ( array( 90, 75 ) as $p ) {
			$minimum = intdiv( $u['limit'], 100 ) * $p + intdiv( ( $u['limit'] % 100 ) * $p + 99, 100 );
			if ( $u['used'] >= $minimum ) { return $p; }
		}
		return 0;
	}
	public static function expires( $u ) { return min( $u['cycle_end'], $u['observed_at'] + 28800 ); }
	public static function lock_name() { global $wpdb; return 'bm-use1:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->options ), 0, 48 ); }
	public static function database( $callback ) {
		if ( ! class_exists( 'WC_Blacklist_Notification_Provider_Store' ) ) { return array( 'status' => 'state_unavailable' ); }
		return WC_Blacklist_Notification_Provider_Store::run( static function( $db ) use ( $callback ) {
			global $wpdb;
			$table = $db->table( $wpdb->options );
			$i = $db->row( "SHOW INDEX FROM {$table} WHERE Key_name='option_name'" );
			if ( ! $i || '0' !== (string) $i[1] || 'option_name' !== $i[4] || null !== $i[7] ) { throw new RuntimeException(); }
			$db->lock( self::lock_name() );
			$now = (int) $db->row( 'SELECT UNIX_TIMESTAMP()' )[0];
			$r = $callback( $db, $table, $now ); $db->confirm(); return $r;
		} );
	}
	public static function row( $db, $table, $key ) {
		return $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=4096,option_value,NULL),autoload,SHA2(LEFT(option_value,4096),256) FROM {$table} WHERE option_name=" . $db::hex( $key ) );
	}
	public static function decode( $row ) {
		return $row && is_string( $row[0] ) && in_array( $row[1], array( 'no', 'off', 'auto-off' ), true ) ? json_decode( $row[0], true, 8 ) : null;
	}
	/** Existing-connection CAS; raw reads make object-cache entries non-authoritative. */
	public static function put( $db, $table, $key, $row, $value ) {
		$json = json_encode( $value );
		if ( ! is_string( $json ) || strlen( $json ) > 4096 ) { throw new RuntimeException(); }
		if ( $row && $row[0] === $json && in_array( $row[1], array( 'no', 'off', 'auto-off' ), true ) ) { return; }
		$k = $db::hex( $key ); $v = $db::hex( $json );
		if ( $row ) {
			$db->query( "UPDATE {$table} SET option_value={$v},autoload='no' WHERE option_name={$k} AND SHA2(LEFT(option_value,4096),256)=" . $db::hex( $row[2] ) . ' AND BINARY autoload=' . $db::hex( $row[1] ) );
		} else { $db->query( "INSERT IGNORE INTO {$table}(option_name,option_value,autoload) VALUES({$k},{$v},'no')" ); }
		if ( '1' !== (string) $db->row( 'SELECT ROW_COUNT()' )[0] ) { throw new RuntimeException(); }
		$check = self::row( $db, $table, $key );
		if ( ! $check || $check[0] !== $json || 'no' !== $check[1] ) { throw new RuntimeException(); }
	}
	/** Current bound credentials stay inside this scope and never enter persisted envelopes. */
	private static function authority( $db, $table, $now ) {
		if ( ! class_exists( 'YOGB_BM_Registrar' ) || ! class_exists( 'YOGB_BM_Secret_Store' ) ) { return false; }
		$cols = array();
		foreach ( array( YOGB_BM_Registrar::OPT_API_KEY, YOGB_BM_Registrar::OPT_API_SECRET, YOGB_BM_Registrar::OPT_REPORTER_ID, 'yogb_bm_tier_version' ) as $k ) {
			$cols[] = "(SELECT IF(OCTET_LENGTH(option_value)<=4096,option_value,NULL) FROM {$table} WHERE option_name=" . $db::hex( $k ) . ')';
		}
		$auth_key = $db::hex( YOGB_BM_Registrar::OPT_AUTH_RECOVERY );
		$cols[] = "(SELECT IF(OCTET_LENGTH(option_value)<=32768,option_value,NULL) FROM {$table} WHERE option_name={$auth_key})";
		$cols[] = "(SELECT OCTET_LENGTH(option_value) FROM {$table} WHERE option_name={$auth_key})";
		$r = $db->row( 'SELECT ' . implode( ',', $cols ) );
		if ( ! $r || ! is_string( $r[0] ) || '' === $r[0] || ! is_string( $r[1] ) || ! is_string( $r[2] ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', $r[2] ) ) { return false; }
		$secret = YOGB_BM_Secret_Store::decrypt_from_storage( $r[1] ); if ( '' === $secret ) { return false; }
		// A signed active control snapshot is usage authority even when this site
		// has never needed auth recovery. A present record must still prove a
		// committed, healthy current epoch; missing differs from corrupt/oversized.
		if ( null !== $r[5] ) {
			if ( ! is_string( $r[4] ) ) { return false; }
			$auth = unserialize( $r[4], array( 'allowed_classes' => false, 'max_depth' => 32 ) );
			if ( ! is_array( $auth ) || isset( $auth['credential_commit'] ) || ( $auth['state'] ?? null ) !== 'healthy'
				|| ( $auth['failures'] ?? null ) !== 0 || ( $auth['next_attempt_at'] ?? null ) !== 0
				|| ! is_int( $auth['expected_reporter_id'] ?? null ) || (string) $auth['expected_reporter_id'] !== $r[2]
				|| ! is_string( $auth['credential_fingerprint'] ?? null ) || ! hash_equals( hash( 'sha256', $r[0] . "\0" . $secret ), $auth['credential_fingerprint'] )
				|| ! is_string( $auth['authority_generation'] ?? null ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $auth['authority_generation'] )
				|| ! self::integer( $auth['updated_at'] ?? null ) || 0 === $auth['updated_at'] || $auth['updated_at'] > $now ) { return false; }
		}
		$site = strtolower( (string) home_url( '/' ) ) . '|' . get_current_blog_id();
		return array( 'binding' => self::digest( 'binding', $r[2] . '|' . $site ), 'epoch' => self::digest( 'epoch', $r[0] . "\0" . $secret ),
			'id' => (int) $r[2], 'version' => (int) $r[3], 'auth' => self::digest( 'auth', (string) $r[4] ), 'secret' => $secret );
	}
	public static function valid_source( $s ) {
		return is_array( $s ) && array_keys( $s ) === array( 'v', 'binding', 'epoch', 'auth', 'control', 'usage', 'plan_options', 'closed', 'notification_eligible', 'observation_eligibility_epoch', 'mac' ) && in_array( $s['v'], array( 1, 2 ), true )
			&& self::hex_id( $s['auth'] ) && self::integer( $s['control'] ) && $s['control'] > 0 && self::hex_id( $s['binding'] ) && self::hex_id( $s['epoch'] ) && is_bool( $s['plan_options'] ) && is_bool( $s['closed'] )
			&& is_bool( $s['notification_eligible'] ) && self::hex_id( $s['observation_eligibility_epoch'] )
			&& ( $s['notification_eligible'] || ! $s['plan_options'] )
			&& is_array( $s['usage'] ) && isset( $s['usage']['observed_at'] ) && self::normalize( $s['usage'], $s['usage']['observed_at'] ) === $s['usage']
			&& self::hex_id( $s['mac'] ) && hash_equals( self::digest( 'source', json_encode( array_slice( $s, 0, -1, true ) ) ), $s['mac'] );
	}
	public static function read( $db, $table, $now ) {
		$s = self::decode( self::row( $db, $table, self::OPTION ) );
		if ( ! self::valid_source( $s ) || 2 !== $s['v'] || $s['usage']['observed_at'] > $now || self::expires( $s['usage'] ) <= $now ) { return null; }
		$a = self::authority( $db, $table, $now );
		return $a && $a['binding'] === $s['binding'] && $a['epoch'] === $s['epoch'] && $a['version'] === $s['control'] && $a['auth'] === $s['auth'] ? $s : null;
	}
	private static function plan_options( $p ) {
		if ( ! is_array( $p ) || ! isset( $p['plan'], $p['tier'] ) || ! is_array( $p['plan'] ) ) { return false; }
		$plan = $p['plan'];
		foreach ( array( 'active_entitlements', 'active_subscriptions', 'active_legacy' ) as $k ) {
			if ( ! isset( $plan[$k] ) || ! is_int( $plan[$k] ) || $plan[$k] < 0 || $plan[$k] > 1000000 ) { return false; }
		}
		if ( $plan['active_entitlements'] !== $plan['active_subscriptions'] + $plan['active_legacy'] || ! isset( $plan['subscription_ids'] ) || ! is_array( $plan['subscription_ids'] ) || count( $plan['subscription_ids'] ) > 10 ) { return false; }
		foreach ( $plan['subscription_ids'] as $id ) { if ( ! is_string( $id ) || strlen( $id ) > 128 ) { return false; } }
		if ( ( 'active' === ( $plan['status'] ?? null ) ) !== ( $plan['active_entitlements'] > 0 ) ) { return false; }

		if ( ( $plan['tier'] ?? null ) !== $p['tier'] || ! in_array( $p['tier'], array( 'free', 'basic', 'pro' ), true ) ) { return false; }
		if ( 'free' === $p['tier'] ) { return 'none' === ( $plan['status'] ?? null ) && 'none' === ( $plan['type'] ?? null ) && 0 === $plan['active_entitlements'] && array() === $plan['subscription_ids']; }
		$type = $plan['active_subscriptions'] > 0 ? ( $plan['active_legacy'] > 0 ? 'mixed' : 'subscription' ) : 'legacy';
		return 'active' === ( $plan['status'] ?? null ) && ( $plan['type'] ?? null ) === $type
			&& count( $plan['subscription_ids'] ) === min( 10, $plan['active_subscriptions'] );
	}
	/** Invoked only after successful control application; verify exact response bytes again under current DB authority. */
	public static function ingest( $body, $timestamp, $signature ) {
		if ( ! self::context() || ! is_string( $body ) || strlen( $body ) > 32768 || ! self::integer( $timestamp ) || ! is_string( $signature ) ) { return false; }
		$p = json_decode( $body, true, 12 );
		if ( ! is_array( $p ) || ( $p['ts'] ?? null ) !== $timestamp ) { return false; }
		$u = isset( $p['capabilities'] ) && is_array( $p['capabilities'] ) && in_array( 'usage_snapshot_v1', $p['capabilities'], true ) ? self::normalize( $p['usage'] ?? null, $timestamp ) : false;
		$r = self::database( static function( $db, $table, $now ) use ( $p, $u, $body, $timestamp, $signature ) {
			$a = self::authority( $db, $table, $now );
			if ( ! $a || ( $p['reporter_status'] ?? null ) !== 'active' || abs( $now - $timestamp ) > 900 || ( $p['reporter_id'] ?? null ) !== $a['id'] || ( $p['tier_version'] ?? null ) !== $a['version']
				|| ! hash_equals( base64_encode( hash_hmac( 'sha256', $body . "\n" . $timestamp, $a['secret'], true ) ), $signature ) ) { throw new RuntimeException(); }
			$row = self::row( $db, $table, self::OPTION ); $old = self::decode( $row ); $closed = false;
			if ( false === $u ) {
				// A verified control response that withdraws/malforms usage cannot leave
				// an older cached snapshot granting mail until its eight-hour expiry.
				if ( self::valid_source( $old ) && $old['binding'] === $a['binding'] && $timestamp >= $old['usage']['observed_at'] ) {
					$old['closed'] = true; $old['plan_options'] = false;
					$old['mac'] = self::digest( 'source', json_encode( array_slice( $old, 0, -1, true ) ) );
					self::put( $db, $table, self::OPTION, $row, $old );
				} return array( 'status' => 'unavailable' );
			}
			if ( self::valid_source( $old ) && $old['binding'] === $a['binding'] ) {
				$v = $old['usage'];
				if ( $timestamp < $v['observed_at'] ) { return array( 'status' => 'ignored' ); }
				$conflict = $timestamp === $v['observed_at'] && $u !== $v;
				$same = $u['cycle_id'] === $v['cycle_id'];
				$closed = $same && ( $old['closed'] || $u['policy_id'] !== $v['policy_id'] || $u['cycle_start'] !== $v['cycle_start'] || $u['cycle_end'] !== $v['cycle_end']
					|| ( null !== $u['used'] && null !== $v['used'] && $u['used'] < $v['used'] ) );
				if ( $conflict || ( ! $same && $u['cycle_start'] <= $v['cycle_start'] ) ) { $u = $v; $closed = true; }
			}
			// Local eligibility belongs to this authenticated observation, not to a
			// later poll. Keep an observed gap sticky across eligible overwrites.
			$eligible = class_exists( 'WC_Blacklist_Notification_Usage' ) && WC_Blacklist_Notification_Usage::observation_eligible( $db, $table );
			$observation_epoch = self::valid_source( $old ) && $old['binding'] === $a['binding']
				&& ( $eligible || ! $old['notification_eligible'] ) ? $old['observation_eligibility_epoch'] : bin2hex( random_bytes( 32 ) );
			$n = array( 'v' => 2, 'binding' => $a['binding'], 'epoch' => $a['epoch'], 'auth' => $a['auth'], 'control' => $a['version'], 'usage' => $u,
				'plan_options' => $eligible && 'finite' === $u['mode'] && self::plan_options( $p ), 'closed' => $closed,
				'notification_eligible' => $eligible, 'observation_eligibility_epoch' => $observation_epoch );
			$n['mac'] = self::digest( 'source', json_encode( $n ) );
			if ( self::authority( $db, $table, $now ) !== $a ) { throw new RuntimeException(); }
			self::put( $db, $table, self::OPTION, $row, $n ); return array( 'status' => 'stored' );
		} );
		return 'stored' === $r['status'];
	}
}
