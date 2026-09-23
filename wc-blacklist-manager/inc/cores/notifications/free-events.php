<?php
/** Stage 2A: existing Free suspicious orders only; blocked mail stays legacy. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Free_Events {
	const EVENT = 'core.order.suspect';
	const HOOK = 'wc_blacklist_deliver_suspect_notification';
	private static $action_id = 0;

	public static function register( $service ) {
		$service->register( array(
			'id' => self::EVENT, 'version' => 1, 'entitlement' => 'free',
			'option' => 'wc_blacklist_email_notification',
			'subject' => __( 'Suspected order placement detected', 'wc-blacklist-manager' ),
			'heading' => __( 'Suspicious order', 'wc-blacklist-manager' ), 'severity' => 'warning',
			'fields' => array( 'order_id', 'timestamp', 'email', 'phone', 'ip', 'reasons' ),
			'reasons' => array( 'suspect' => __( 'Review the suspicious order in your store.', 'wc-blacklist-manager' ) ), 'cooldown' => null,
		) );
	}

	public static function begin( $action_id ) {
		if ( doing_action( 'action_scheduler_before_execute' ) ) { self::$action_id = (int) $action_id; }
	}
	public static function end() { self::$action_id = 0; }

	public static function worker( $order_id ) {
		if ( WC_Blacklist_Notification_Policy::background() ) { return true; }
		if ( ! self::$action_id || ! class_exists( 'ActionScheduler' ) || ! in_array( current_filter(), array( 'wc_blacklist_check_and_notify', self::HOOK ), true ) ) { return false; }
		try {
			$store = ActionScheduler::store();
			$action = $store->fetch_action( self::$action_id );
			$args = $action->get_args();
			return 'in-progress' === $store->get_status( self::$action_id ) && current_filter() === $action->get_hook() && isset( $args['order_id'] ) && (int) $args['order_id'] === $order_id;
		} catch ( Throwable $error ) { return false; }
	}

	public static function suspect( $order_id, $retry = 0 ) {
		if ( ! is_numeric( $order_id ) || (int) $order_id <= 0 || ! is_int( $retry ) || $retry < 0 || $retry > 2 ) { return false; }
		$order_id = (int) $order_id;
		if ( 'yes' !== get_option( 'wc_blacklist_email_notification', 'no' ) ) { return false; }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return false; }
		if ( ! self::worker( $order_id ) ) { return self::schedule( $order_id, 0 ); }
		$result = wc_blacklist_manager_notifications()->dispatch( self::EVENT, 'order:' . $order_id . ':retry:' . $retry, array(
			'order_id' => $order_id, 'timestamp' => time(), 'email' => $order->get_billing_email(),
			'phone' => $order->get_billing_phone(), 'ip' => $order->get_customer_ip_address(), 'reasons' => array( 'suspect' ),
		) );
		if ( $retry < 2 && in_array( $result['status'], array( 'invalid_config', 'render_failed', 'state_unavailable', 'overflow' ), true ) ) { self::schedule( $order_id, $retry + 1 ); }
		return 'accepted' === $result['status'];
	}

	public static function schedule( $order_id, $retry ) {
		$args = array( 'order_id' => $order_id, 'retry' => $retry );
		try {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				if ( as_has_scheduled_action( self::HOOK, $args, 'wc-blacklist-notifications' ) ) { return true; }
				if ( as_schedule_single_action( time() + 60, self::HOOK, $args, 'wc-blacklist-notifications', true ) ) { return true; }
			}
		} catch ( Throwable $error ) { /* One bounded cron fallback, no inline mail. */ }
		return wp_next_scheduled( self::HOOK, $args ) || true === wp_schedule_single_event( time() + 60, self::HOOK, $args );
	}
}

add_action( 'wc_blacklist_manager_notifications_register', array( 'WC_Blacklist_Notification_Free_Events', 'register' ) );
add_action( WC_Blacklist_Notification_Free_Events::HOOK, array( 'WC_Blacklist_Notification_Free_Events', 'suspect' ), 10, 2 );
add_action( 'action_scheduler_before_execute', array( 'WC_Blacklist_Notification_Free_Events', 'begin' ) );
foreach ( array( 'action_scheduler_after_execute', 'action_scheduler_failed_execution', 'action_scheduler_execution_ignored' ) as $wc_blacklist_notification_hook ) {
	add_action( $wc_blacklist_notification_hook, array( 'WC_Blacklist_Notification_Free_Events', 'end' ) );
}
unset( $wc_blacklist_notification_hook );
