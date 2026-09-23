<?php
/** One bounded calendar-week lifecycle. No customer-path collection or transport. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/digest-lifecycle.php';
final class WC_Blacklist_Notification_Digest {
	const ID = 'premium.digest.weekly_security';
	const TOGGLE = 'wc_blacklist_email_weekly_security_digest';
	const OPTION = 'wc_blacklist_notification_digest_v1';
	const HOOK = 'wc_blacklist_notification_digest_poll_v1';
	const COUNTS = array( 'protection' => 'protection_records', 'suspicious' => 'suspicious_records' );
	const REASONS = array( 'retained_records' );
	const ACTION = 'security_activity';
	const LOCK_PREFIX = 'bm-d1:';
	const DELIVERY = 'deliver_digest';
	use WC_Blacklist_Notification_Digest_Lifecycle;
}

add_action( 'admin_init', array( 'WC_Blacklist_Notification_Digest', 'maintain' ), 20 );
add_action( 'init', array( 'WC_Blacklist_Notification_Digest', 'background_maintenance' ), 20 );
add_action( WC_Blacklist_Notification_Digest::HOOK, array( 'WC_Blacklist_Notification_Digest', 'poll' ) );
if ( function_exists( 'add_filter' ) ) { add_filter( 'pre_update_option_' . WC_Blacklist_Notification_Digest::TOGGLE, array( 'WC_Blacklist_Notification_Digest', 'rearm_toggle' ), PHP_INT_MAX, 2 ); }
if ( defined( 'WC_BLACKLIST_MANAGER_PLUGIN_FILE' ) ) {
	register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Digest', 'maintain' ) );
	register_deactivation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Digest', 'deactivate' ) );
}
