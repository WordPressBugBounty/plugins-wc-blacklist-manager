<?php
/** Internal provider capability: finite signals and persisted job authority. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Provider {
	private static $negotiated = false;
	private static $samples = array();
	private static $order;
	private static $rules = array();
	private static $pending = array();
	private static $flushed = false;
	private static $actions = array();

	public static function register( $service, $samples, $order, $rules ) {
		// Negotiation is sticky even if a provider registration is rejected.
		if ( self::$negotiated ) { return false; }
		self::$negotiated = true;
		if ( ! is_array( $samples ) || array_keys( $samples ) !== range( 0, 5 )
			|| ! is_array( $rules ) || array_keys( $rules ) !== range( 0, 15 ) ) { return false; }
		$ids = array(); $hooks = array(); $reasons = array();
		foreach ( $samples as $d ) {
			if ( ! is_array( $d ) || 'premium' !== ( $d['entitlement'] ?? null )
				|| array( 'sample_id', 'timestamp', 'reasons' ) !== ( $d['fields'] ?? null )
				|| ! array_key_exists( 'cooldown', $d ) || null !== $d['cooldown'] || ! is_array( $d['reasons'] ?? null ) || count( $d['reasons'] ) > 7 || ! is_string( $d['id'] ?? null ) ) { return false; }
			$ids[] = $d['id'] ?? '';
		}
		if ( ! is_array( $order ) || 'premium' !== ( $order['entitlement'] ?? null )
			|| array( 'order_id', 'timestamp', 'reasons' ) !== ( $order['fields'] ?? null )
			|| ! array_key_exists( 'cooldown', $order ) || null !== $order['cooldown'] || ! is_array( $order['reasons'] ?? null ) || count( $order['reasons'] ) !== 16 || ! is_string( $order['id'] ?? null ) ) { return false; }
		$ids[] = $order['id'] ?? '';
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || array_keys( $rule ) !== array( 'hook', 'reason' )
				|| ! is_string( $rule['hook'] ) || ! preg_match( '/^[a-z][a-z0-9_]{0,95}$/D', $rule['hook'] )
				|| ! is_string( $rule['reason'] ) || ! isset( $order['reasons'][$rule['reason']] ) ) { return false; }
			$hooks[] = $rule['hook']; $reasons[] = $rule['reason'];
		}
		if ( count( array_unique( $ids ) ) !== 7 || count( array_unique( $hooks ) ) !== 16 || count( array_unique( $reasons ) ) !== 16 ) { return false; }
		// Validate atomically before registering with the live facade.
		$validator = new WC_Blacklist_Notification_Registry();
		foreach ( array_merge( $samples, array( $order ) ) as $d ) { if ( ! $validator->register( $d ) ) { return false; } }
		foreach ( array_merge( $samples, array( $order ) ) as $d ) { if ( ! $service->register( $d ) ) { return false; } }
		self::$samples = $samples; self::$order = $order; self::$rules = $rules;
		return true;
	}
	public static function present() { return self::$negotiated; }
	public static function ready() { return 6 === count( self::$samples ) && null !== self::$order; }
	public static function sample_descriptor( $slot ) { return is_int( $slot ) ? ( self::$samples[$slot] ?? null ) : null; }
	public static function eligible( $slot ) {
		$d = self::sample_descriptor( $slot );
		return $d && ! WC_Blacklist_Notification_Policy::check( $d );
	}
	public static function may_signal( $slot ) {
		$d = self::sample_descriptor( $slot );
		if ( ! $d ) { return false; }
		// Cache may suppress. An unknown toggle only permits a request-local signal.
		$all = wp_cache_get( 'alloptions', 'options' );
		if ( ! is_array( $all ) ) { return false; } // Do not make the entitlement gate reload alloptions.
		$value = $all[$d['option']] ?? wp_cache_get( $d['option'], 'options' );
		return ( false === $value || 'yes' === $value ) && WC_Blacklist_Notification_Policy::premium();
	}
	public static function admission_eligible( $slot, $db, $table ) {
		$d = self::sample_descriptor( $slot );
		if ( ! $d ) { return false; }
		// Exact, size-bounded lookup shares the admission connection and elapsed budget.
		$row = $db->row( "SELECT IF(OCTET_LENGTH(option_value)<=3,option_value,NULL) FROM {$table} WHERE option_name=" . $db::hex( $d['option'] ) );
		if ( ! $row || 'yes' !== $row[0] || ! WC_Blacklist_Notification_Policy::premium() ) { return false; }
		return true === apply_filters( 'wc_blacklist_manager_notification_policy_v1', true, $d['id'] );
	}
	public static function signal( $signal, array $reasons ) {
		try { return self::buffer_signal( $signal, $reasons ); } catch ( Throwable $e ) { return false; }
	}
	private static function buffer_signal( $signal, array $reasons ) {
		$keys = array( 'registration.suspect', 'registration.blocked', 'comment.suspect', 'comment.blocked', 'form.suspect', 'form.blocked' );
		$slot = array_search( $signal, $keys, true );
		if ( false === $slot || self::$flushed || ! $reasons || ! self::may_signal( $slot ) ) { return false; }
		$d = self::$samples[$slot];
		if ( count( $reasons ) > 7 ) { return false; }
		foreach ( $reasons as $r ) { if ( ! is_string( $r ) || ! isset( $d['reasons'][$r] ) ) { return false; } }
		if ( WC_Blacklist_Notification_Provider_Samples::suppressed( $slot ) ) { return false; }
		if ( ! self::$pending ) { add_action( 'shutdown', array( __CLASS__, 'flush' ) ); }
		self::$pending[$slot] = array_values( array_unique( array_merge( self::$pending[$slot] ?? array(), $reasons ) ) );
		return true;
	}
	public static function flush() {
		if ( self::$flushed ) { return false; }
		self::$flushed = true; $batch = self::$pending; self::$pending = array();
		try { return $batch ? WC_Blacklist_Notification_Provider_Samples::admit( $batch ) : false; } catch ( Throwable $e ) { return false; }
	}
	public static function sample_slot( $id ) {
		foreach ( self::$samples as $i => $d ) { if ( $d['id'] === $id ) { return $i; } }
		return false;
	}
	public static function owns( $id ) { return false !== self::sample_slot( $id ) || ( self::$order && self::$order['id'] === $id ); }
	public static function begin( $id ) {
		if ( doing_action( 'action_scheduler_before_execute' ) && count( self::$actions ) < 16 ) { self::$actions[] = (int) $id; }
	}
	public static function end( $id ) {
		if ( (int) $id === end( self::$actions ) ) { array_pop( self::$actions ); }
	}
	public static function rule_slot( $order_id, $reasons ) {
		if ( ! is_int( $order_id ) || $order_id <= 0 || ! is_array( $reasons ) || count( $reasons ) !== 1
			|| ! self::$actions || ! class_exists( 'ActionScheduler' ) ) { return false; }
		try {
			$store = ActionScheduler::store(); $id = end( self::$actions );
			if ( 'in-progress' !== $store->get_status( $id ) ) { return false; }
			$action = $store->fetch_action( $id ); $args = array_values( $action->get_args() );
			if ( count( $args ) !== 2 || ! ( is_int( $args[0] ) || ( is_string( $args[0] ) && preg_match( '/^[1-9][0-9]*$/D', $args[0] ) ) ) || (int) $args[0] !== $order_id
				|| ! in_array( $args[1], array( 'email', 'suspect', 'blocked' ), true ) ) { return false; }
			foreach ( self::$rules as $i => $r ) {
				if ( $reasons === array( $r['reason'] ) && $r['hook'] === $action->get_hook() && doing_action( $r['hook'] ) ) { return $i; }
			}
		} catch ( Throwable $e ) { return false; }
		return false;
	}
	public static function worker( $id, $context ) {
		return false !== self::sample_slot( $id ) ? WC_Blacklist_Notification_Policy::background()
			: self::$order && self::$order['id'] === $id && false !== self::rule_slot( $context['order_id'] ?? 0, $context['reasons'] ?? array() );
	}
	public static function claim( $id, $context, $ready = true ) {
		if ( ! self::worker( $id, $context ) ) { return 'state_unavailable'; }
		$slot = self::sample_slot( $id );
		if ( false !== $slot ) { return WC_Blacklist_Notification_Provider_Samples::claim( $slot, $context['sample_id'] ?? '', $ready )['status']; }
		return WC_Blacklist_Notification_Provider_Orders::claim( $context['order_id'], $context['reasons'], $ready );
	}
	public static function prepare( $descriptor, $context, $renderer ) {
		return WC_Blacklist_Notification_Provider_Store::run( static function( $db ) use ( $descriptor, $context, $renderer ) {
			if ( false === self::sample_slot( $descriptor['id'] ) ) {
				$order = wc_get_order( $context['order_id'] ?? 0 );
				if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return array( 'status' => 'state_unavailable' ); }
				$db->query( 'SELECT 1' );
			}
			$delivery = WC_Blacklist_Notification_Recipients::resolve();
			if ( false === $delivery ) { return array( 'status' => 'invalid_config' ); }
			try { $message = $renderer->render( $descriptor, $context ); } catch ( Throwable $e ) { $message = false; }
			$db->query( 'SELECT 1' );
			return false === $message ? array( 'status' => 'render_failed' ) : array( 'status' => 'ready', 'delivery' => $delivery, 'message' => $message );
		} );
	}
	public static function automation( $order_id, array $reasons ) {
		try { return self::deliver_order( $order_id, $reasons ); } catch ( Throwable $e ) { return false; }
	}
	private static function deliver_order( $order_id, array $reasons ) {
		if ( ! self::$order || WC_Blacklist_Notification_Policy::check( self::$order ) || ! is_numeric( $order_id ) ) { return false; }
		$order_id = (int) $order_id;
		$slot = self::rule_slot( $order_id, $reasons );
		if ( false === $slot ) { return false; }
		$r = wc_blacklist_manager_notifications()->dispatch( self::$order['id'], $order_id . ':' . $slot . ':' . end( self::$actions ),
			array( 'order_id' => $order_id, 'timestamp' => time(), 'reasons' => $reasons ) );
		return 'accepted' === $r['status'];
	}
}
add_action( 'action_scheduler_before_execute', array( 'WC_Blacklist_Notification_Provider', 'begin' ) );
foreach ( array( 'action_scheduler_after_execute', 'action_scheduler_failed_execution', 'action_scheduler_execution_ignored' ) as $wc_blacklist_provider_hook ) {
	add_action( $wc_blacklist_provider_hook, array( 'WC_Blacklist_Notification_Provider', 'end' ) );
}
unset( $wc_blacklist_provider_hook );
