<?php
/** One periodic worker, provisioned only outside customer execution. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Blocked_Worker {
	const HOOK = 'wc_blacklist_blocked_notification_poll_v1';
	const SCHEDULE = 'wc_blacklist_blocked_five_minutes';

	public static function schedules( $schedules ) {
		$schedules[self::SCHEDULE] = array( 'interval' => 300, 'display' => 'Blacklist Manager blocked notification poll' );
		return $schedules;
	}

	public static function maintain() {
		if ( ! WC_Blacklist_Notification_Blocked_State::maintenance_context() ) { return false; }
		$r = WC_Blacklist_Notification_Blocked_State::maintain();
		if ( 'ready' !== $r['status'] ) { return false; }
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// Concurrent maintenance within a cadence chooses the same native cron key.
			return true === wp_schedule_event( ( (int) floor( time() / 300 ) + 1 ) * 300, self::SCHEDULE, self::HOOK );
		}
		return true;
	}

	public static function deactivate() { wp_clear_scheduled_hook( self::HOOK ); }
	public static function background_maintenance() {
		if ( WC_Blacklist_Notification_Policy::background() ) { self::maintain(); }
	}

	public static function poll() {
		if ( ! WC_Blacklist_Notification_Policy::background() || 'yes' !== get_option( 'wc_blacklist_email_blocking_notification', 'no' ) ) { return; }
		$r = WC_Blacklist_Notification_Blocked_State::pending();
		if ( 'pending' !== $r['status'] ) { return; }
		$s = $r['state'];
		return wc_blacklist_manager_notifications()->dispatch( WC_Blacklist_Notification_Blocked_Events::EVENT, $s['sample_id'] . ':' . $s['failures'], array(
			'sample_id' => $s['sample_id'], 'timestamp' => $s['at'], 'reasons' => $s['reasons'],
		) );
	}

	public static function diagnostic() {
		if ( 'yes' !== get_option( 'wc_blacklist_email_blocking_notification', 'no' ) ) { return ''; }
		$r = WC_Blacklist_Notification_Blocked_State::pending();
		if ( 'state_unavailable' === $r['status'] ) {
			return __( 'Sampled blocked-checkout alerts are unavailable with the current database adapter or notification state. Checkout protection remains active. Ask a site administrator to check the database setup and reload this page.', 'wc-blacklist-manager' );
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( ! $next || $next < time() - 900 || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return __( 'Sampled blocked-checkout alerts need a running WordPress cron worker. Verify your server cron setup; delayed samples may expire without email.', 'wc-blacklist-manager' );
		}
		return '';
}
}
add_action( 'admin_init', array( 'WC_Blacklist_Notification_Blocked_Worker', 'maintain' ), 20 );
add_action( 'init', array( 'WC_Blacklist_Notification_Blocked_Worker', 'background_maintenance' ), 20 );
add_action( WC_Blacklist_Notification_Blocked_Worker::HOOK, array( 'WC_Blacklist_Notification_Blocked_Worker', 'poll' ) );
if ( function_exists( 'add_filter' ) ) { add_filter( 'cron_schedules', array( 'WC_Blacklist_Notification_Blocked_Worker', 'schedules' ) ); }
if ( defined( 'WC_BLACKLIST_MANAGER_PLUGIN_FILE' ) ) {
	register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Blocked_Worker', 'maintain' ) );
	register_deactivation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Blocked_Worker', 'deactivate' ) );
}
