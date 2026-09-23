<?php
/** Free sampled blocked-checkout alerts; no caller evidence is retained. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Blocked_Events {
	const EVENT = 'core.checkout.blocked';
	private static $reasons = array();
	private static $scheduled = false;
	private static $flushed = false;

	public static function register( $service ) {
		$service->register( array(
			'id' => self::EVENT, 'version' => 1, 'entitlement' => 'free', 'option' => 'wc_blacklist_email_blocking_notification',
			'subject' => __( 'Sampled blocked checkout activity', 'wc-blacklist-manager' ),
			'heading' => __( 'A sample of blocked checkout activity', 'wc-blacklist-manager' ),
			'severity' => 'warning', 'fields' => array( 'timestamp', 'reasons', 'sample_id' ), 'cooldown' => null,
			'reasons' => array(
				'phone' => __( 'Blocked phone detected in the sampled request.', 'wc-blacklist-manager' ),
				'email' => __( 'Blocked email detected in the sampled request.', 'wc-blacklist-manager' ),
				'ip' => __( 'Blocked IP detected in the sampled request.', 'wc-blacklist-manager' ),
				'domain' => __( 'Blocked email domain detected in the sampled request.', 'wc-blacklist-manager' ),
				'name' => __( 'Blocked customer name detected in the sampled request.', 'wc-blacklist-manager' ),
				'billing' => __( 'Blocked billing address detected in the sampled request.', 'wc-blacklist-manager' ),
				'shipping' => __( 'Blocked shipping address detected in the sampled request.', 'wc-blacklist-manager' ),
				'disposable_phone' => __( 'Disposable phone detected in the sampled request.', 'wc-blacklist-manager' ),
				'disposable_email' => __( 'Disposable email detected in the sampled request.', 'wc-blacklist-manager' ),
				'proxy' => __( 'Proxy or VPN detected in the sampled request.', 'wc-blacklist-manager' ),
				'device' => __( 'Blocked device detected in the sampled request.', 'wc-blacklist-manager' ),
			),
		) );
	}

	public static function buffer( array $values ) {
		if ( self::$flushed || 'yes' !== get_option( 'wc_blacklist_email_blocking_notification', 'no' ) ) { return; }
		$keys = array( 'phone', 'email', 'ip', 'domain', 'name', 'billing', 'shipping', 'disposable_phone', 'disposable_email', 'proxy', 'device' );
		foreach ( $keys as $i => $key ) {
			if ( isset( $values[$i] ) && is_scalar( $values[$i] ) && ! empty( $values[$i] ) ) { self::$reasons[$key] = $key; }
		}
		if ( self::$reasons && ! self::$scheduled ) {
			self::$scheduled = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	public static function flush() {
		if ( self::$flushed || ! self::$reasons || 'yes' !== get_option( 'wc_blacklist_email_blocking_notification', 'no' ) ) { return; }
		self::$flushed = true;
		$reasons = array_values( self::$reasons ); self::$reasons = array();
		return WC_Blacklist_Notification_Blocked_State::admit( $reasons )['status'];
	}
}
add_action( 'wc_blacklist_manager_notifications_register', array( 'WC_Blacklist_Notification_Blocked_Events', 'register' ) );
