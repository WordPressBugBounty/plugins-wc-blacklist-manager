<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Checkout_Phone_Country_Code {
	public function __construct() {
		if ((is_plugin_active('wc-advanced-accounts/wc-advanced-accounts.php') 
			|| is_plugin_active('wc-advanced-accounts-premium/wc-advanced-accounts-premium.php'))
			&& get_option('yoaa_wc_enable_phone_number_account') === 'yes') {
			return;
		}

		$allowed_countries_option = get_option( 'woocommerce_allowed_countries', 'all' );
		$specific_countries       = get_option( 'woocommerce_specific_allowed_countries', array() );
		$skip_country_code        = ( 'specific' === $allowed_countries_option && 1 === count( $specific_countries ) );

		if ( '1' !== get_option( 'wc_blacklist_phone_verification_country_code_disabled' ) && !$skip_country_code ) {
			return;
		}

		add_action('woocommerce_checkout_create_order', [$this, 'maybe_add_country_code_to_phone'], 10, 2);
		add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'maybe_add_country_code_to_phone_blocks'], 10, 2);
	}

	/**
	 * Add dial code to billing/shipping phones.
	 * - Uses posted *_dial_code first.
	 * - If empty, falls back to WooCommerce country calling code.
	 * - Stores normalized phone as +<digits>.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted checkout data.
	 */
	public function maybe_add_country_code_to_phone( $order, $data ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ($premium_active) {
			return;
		}

		// === BILLING ===
		$billing_phone = isset( $data['billing_phone'] ) ? $data['billing_phone'] : '';

		if ( ! empty( $billing_phone ) ) {
			$billing_country   = isset( $data['billing_country'] ) ? $data['billing_country'] : $order->get_billing_country();
			$billing_dial_code = yobm_get_country_dial_code( $billing_country );

			$normalized_billing_phone = yobm_normalize_phone( $billing_phone, $billing_dial_code );

			if ( '' !== $normalized_billing_phone ) {
				$order->set_billing_phone( '+' . $normalized_billing_phone );
			}
		}

		// === SHIPPING ===
		$shipping_phone = isset( $data['shipping_phone'] ) ? $data['shipping_phone'] : '';

		if ( ! empty( $shipping_phone ) ) {
			$shipping_country   = isset( $data['shipping_country'] ) ? $data['shipping_country'] : $order->get_shipping_country();
			$shipping_dial_code = yobm_get_country_dial_code( $shipping_country );

			$normalized_shipping_phone = yobm_normalize_phone( $shipping_phone, $shipping_dial_code );

			if ( '' !== $normalized_shipping_phone && method_exists( $order, 'set_shipping_phone' ) ) {
				$order->set_shipping_phone( '+' . $normalized_shipping_phone );
			}
		}
	}

	/**
	 * Add dial code to billing/shipping phones for Block-based Checkout.
	 * Uses WooCommerce country calling code helper.
	 *
	 * @param WC_Order        $order   Order object.
	 * @param WP_REST_Request $request REST request.
	 */
	public function maybe_add_country_code_to_phone_blocks( $order, $request ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ($premium_active) {
			return;
		}

		// === BILLING ===
		$billing_phone   = (string) $order->get_billing_phone();
		$billing_country = (string) $order->get_billing_country();

		if ( '' !== $billing_phone ) {
			$billing_dial_code         = yobm_get_country_dial_code( $billing_country );
			$normalized_billing_phone  = yobm_normalize_phone( $billing_phone, $billing_dial_code );

			if ( '' !== $normalized_billing_phone ) {
				$order->set_billing_phone( '+' . $normalized_billing_phone );
			}
		}

		// === SHIPPING ===
		$shipping_phone   = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
		$shipping_country = (string) $order->get_shipping_country();

		if ( '' !== $shipping_phone ) {
			$shipping_dial_code        = yobm_get_country_dial_code( $shipping_country );
			$normalized_shipping_phone = yobm_normalize_phone( $shipping_phone, $shipping_dial_code );

			if ( '' !== $normalized_shipping_phone && method_exists( $order, 'set_shipping_phone' ) ) {
				$order->set_shipping_phone( '+' . $normalized_shipping_phone );
			}
		}
	}
}

new WC_Blacklist_Manager_Checkout_Phone_Country_Code();
