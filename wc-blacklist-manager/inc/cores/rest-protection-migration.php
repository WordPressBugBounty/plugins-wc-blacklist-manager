<?php
/** Bounded admin-only consolidation of the REST protection switches. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Manager_REST_Protection_Migration {
	const STATE = 'wc_blacklist_rest_protection_migration_v1';
	const MASTER_CONTRACT_VERSION = 1;
	const MASTER = 'wc_blacklist_enable_woo_rest_api';
	const LOCAL = 'wc_blacklist_enable_woo_rest_review_local_identity';
	const DISPOSABLE = 'wc_blacklist_enable_woo_rest_review_disposable_email';

	public static function bootstrap() {
		add_action( 'admin_init', array( __CLASS__, 'migrate' ), 4 );
		add_filter( 'pre_option_' . self::LOCAL, array( __CLASS__, 'legacy_alias' ) );
		add_filter( 'pre_option_' . self::DISPOSABLE, array( __CLASS__, 'legacy_alias' ) );
	}

	public static function state() { $state = get_option( self::STATE, array() ); return is_array( $state ) ? $state : array( 'status' => $state ); }
	public static function complete() { return 'complete' === ( self::state()['status'] ?? '' ); }
	public static function master_enabled() { $state = self::state(); return self::complete() ? 1 === (int) get_option( self::MASTER, 0 ) : 1 === (int) ( $state['master'] ?? get_option( self::MASTER, 0 ) ); }
	public static function order_enabled() { return self::master_enabled(); }
	public static function local_identity_enabled() { $state = self::state(); return self::complete() ? self::master_enabled() : (int) ( $state['local'] ?? get_option( self::LOCAL, 0 ) ) === 1; }
	public static function disposable_enabled() { $state = self::state(); return self::complete() ? self::master_enabled() : ( 1 === (int) ( $state['local'] ?? get_option( self::LOCAL, 0 ) ) && 1 === (int) ( $state['disposable'] ?? get_option( self::DISPOSABLE, 0 ) ) ); }
	public static function legacy_alias( $pre ) {
		return self::complete() ? ( self::master_enabled() ? 1 : 0 ) : $pre;
	}
	public static function migrate() {
		if ( self::complete() || ! current_user_can( 'manage_options' ) ) { return; }
		$state = self::state();
		if ( ! isset( $state['status'] ) ) {
			$state = array(
				'status' => 'pending',
				'master' => (int) ( 1 === (int) get_option( self::MASTER, 0 ) ),
				'local' => (int) ( 1 === (int) get_option( self::LOCAL, 0 ) ),
				'disposable' => (int) ( 1 === (int) get_option( self::DISPOSABLE, 0 ) ),
			);
			$state['target'] = ( $state['master'] || $state['local'] ) ? 1 : 0;
			if ( ! add_option( self::STATE, $state, '', 'no' ) ) { $state = self::state(); }
		}
		if ( 'pending' !== ( $state['status'] ?? '' ) || ! isset( $state['target'], $state['master'], $state['local'], $state['disposable'] ) ) { return; }
		$target = (int) $state['target'];
		update_option( self::MASTER, $target );
		if ( (int) get_option( self::MASTER, -1 ) !== $target ) { return; }
		delete_option( self::LOCAL );
		if ( false !== get_option( self::LOCAL, false ) ) { return; }
		delete_option( self::DISPOSABLE );
		if ( false !== get_option( self::DISPOSABLE, false ) ) { return; }
		$complete = array( 'status' => 'complete', 'master' => (int) $state['master'], 'local' => (int) $state['local'], 'disposable' => (int) $state['disposable'], 'target' => $target, 'notice_pending' => true );
		if ( ! update_option( self::STATE, $complete, false ) || self::state() !== $complete ) { return; }
	}
}
WC_Blacklist_Manager_REST_Protection_Migration::bootstrap();
function wc_blacklist_manager_rest_order_protection_enabled() { return WC_Blacklist_Manager_REST_Protection_Migration::order_enabled(); }
