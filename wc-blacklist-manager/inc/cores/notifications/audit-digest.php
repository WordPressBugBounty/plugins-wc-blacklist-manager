<?php
/** One additional closed digest; its state and in-process authority are separate. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/digest-lifecycle.php';
final class WC_Blacklist_Notification_Audit_Digest {
	const ID = 'premium.digest.weekly_manual_blacklist';
	const TOGGLE = 'wc_blacklist_email_weekly_manual_blacklist_digest';
	const OPTION = 'wc_blacklist_notification_manual_digest_v1';
	const HOOK = 'wc_blacklist_notification_digest_poll_v1';
	const COUNTS = array( 'manual_block' => 'manual_block_records', 'manual_suspect' => 'manual_suspect_records', 'manual_remove' => 'manual_remove_records' );
	const REASONS = array( 'supported_records', 'record_limits' );
	const ACTION = 'manual_blacklist_activity';
	const LOCK_PREFIX = 'bm-a1:';
	const DELIVERY = 'deliver_audit_digest';
	use WC_Blacklist_Notification_Digest_Lifecycle;
}
// The existing hourly hook runs weekly first. Each facade contains its own failures.
add_action( 'admin_init', array( 'WC_Blacklist_Notification_Audit_Digest', 'maintain' ), 21 );
add_action( 'init', array( 'WC_Blacklist_Notification_Audit_Digest', 'background_maintenance' ), 21 );
add_action( WC_Blacklist_Notification_Audit_Digest::HOOK, array( 'WC_Blacklist_Notification_Audit_Digest', 'poll' ), 11 );
if ( function_exists( 'add_filter' ) ) { add_filter( 'pre_update_option_' . WC_Blacklist_Notification_Audit_Digest::TOGGLE, array( 'WC_Blacklist_Notification_Audit_Digest', 'rearm_toggle' ), PHP_INT_MAX, 2 ); }

if ( defined( 'WC_BLACKLIST_MANAGER_PLUGIN_FILE' ) ) {
	register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( 'WC_Blacklist_Notification_Audit_Digest', 'maintain' ) );
}
