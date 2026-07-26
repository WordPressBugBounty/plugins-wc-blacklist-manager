<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wc_blacklist_manager_premium_license_is_active' ) ) {
	function wc_blacklist_manager_premium_license_is_active() {
		if ( function_exists( 'yobmp_premium_license_active' ) ) {
			return (bool) yobmp_premium_license_active();
		}

		/*
		 * Core is loaded before its required Premium add-on. Use the legacy
		 * status only as a read-only bootstrap snapshot until Premium exposes
		 * its authoritative API later in the same request.
		 */
		$status = strtolower( trim( (string) get_option( 'wc_blacklist_manager_premium_license_status', 'deactivated' ) ) );

		return in_array( $status, array( 'activated', 'active', 'valid' ), true );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_is_premium_available' ) ) {
	function wc_blacklist_manager_is_premium_available() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' ) ) {
			return false;
		}

		return wc_blacklist_manager_premium_license_is_active();
	}
}

if ( ! class_exists( 'WC_Blacklist_Manager_Validator' ) ) {
	class WC_Blacklist_Manager_Validator {

		public static function is_premium_active() {
			return function_exists( 'wc_blacklist_manager_premium_license_is_active' )
				&& wc_blacklist_manager_premium_license_is_active();
		}

		public function register_settings() {}

		public function validate_license_key( $input ) {
			add_settings_error(
				'wc_blacklist_manager_premium_license_key',
				'wc_blacklist_manager_license_moved_to_premium',
				__( 'License activation is now handled by the Blacklist Manager Premium add-on. Update and activate Premium, then use the Premium setup wizard to activate your license.', 'wc-blacklist-manager' ),
				'error'
			);

			return is_string( $input ) ? trim( $input ) : '';
		}
	}
}

if ( ! function_exists( 'wc_blacklist_manager_register_legacy_license_page' ) ) {
	function wc_blacklist_manager_register_legacy_license_page() {
		if ( class_exists( 'YoOhw_License_Manager' ) ) {
			return;
		}

		add_submenu_page(
			null,
			__( 'License Manager', 'wc-blacklist-manager' ),
			__( 'License Manager', 'wc-blacklist-manager' ),
			'manage_options',
			'yoohw-license-manager',
			'wc_blacklist_manager_render_legacy_license_page'
		);
	}

	add_action( 'admin_menu', 'wc_blacklist_manager_register_legacy_license_page', 99 );
}

if ( ! function_exists( 'wc_blacklist_manager_render_legacy_license_page' ) ) {
	function wc_blacklist_manager_render_legacy_license_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ), 403 );
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-blacklist-manager-setup&step=license' ) );
			exit;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'License Manager', 'wc-blacklist-manager' ) . '</h1>';
		echo '<div class="notice notice-info"><p>' . esc_html__( 'License management has moved to the Blacklist Manager Premium add-on. Install and activate Premium to manage your license.', 'wc-blacklist-manager' ) . '</p></div>';
		echo '</div>';
	}
}

if ( ! function_exists( 'wc_blacklist_manager_premium_denied_message' ) ) {
	function wc_blacklist_manager_premium_denied_message() {
		return __( 'A valid Blacklist Manager Premium license is required to use this feature.', 'wc-blacklist-manager' );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_require_premium' ) ) {
	function wc_blacklist_manager_require_premium( $context = 'default' ) {
		if ( wc_blacklist_manager_is_premium_available() ) {
			return true;
		}

		$message = wc_blacklist_manager_premium_denied_message();

		if ( 'ajax' === $context ) {
			wp_send_json_error(
				array(
					'message' => $message,
				),
				403
			);
		}

		if ( 'rest' === $context ) {
			return new WP_Error(
				'wc_blacklist_manager_premium_required',
				$message,
				array( 'status' => 403 )
			);
		}

		if ( 'admin' === $context ) {
			wp_die(
				esc_html( $message ),
				esc_html__( 'Premium license required', 'wc-blacklist-manager' ),
				array( 'response' => 403 )
			);
		}

		return false;
	}
}
