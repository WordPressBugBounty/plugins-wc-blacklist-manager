<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if ( ! interface_exists( IntegrationInterface::class ) ) {
	return;
}

final class WC_Blacklist_Manager_Checkout_Verification_Blocks_Integration implements IntegrationInterface {

	const HANDLE = 'wc-blacklist-checkout-verification-blocks';

	public function get_name() {
		return 'wc-blacklist-manager-checkout-verification';
	}

	public function initialize() {
		self::register_script();
	}

	public static function register_script() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			plugins_url( '../../js/checkout-verification-blocks.js', __FILE__ ),
			array( 'wp-element', 'wc-blocks-checkout' ),
			WC_BLACKLIST_MANAGER_VERSION,
			true
		);
	}

	public function get_script_handles() {
		return array( self::HANDLE );
	}

	public function get_editor_script_handles() {
		return array( self::HANDLE );
	}

	public function get_script_data() {
		return array();
	}
}
