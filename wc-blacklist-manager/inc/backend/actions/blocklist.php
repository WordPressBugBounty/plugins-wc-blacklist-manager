<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Blocklist_Prevention {

	public function __construct() {
		add_action( 'woocommerce_checkout_process', array( $this, 'prevent_order' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'prevent_order_for_blocks' ), 20, 2 );
		add_filter( 'rest_dispatch_request', array( $this, 'prevent_wc_rest_api_orders' ), 10, 4 );
		add_filter( 'registration_errors', array( $this, 'prevent_blocked_email_registration' ), 10, 3 );
		add_filter( 'woocommerce_registration_errors', array( $this, 'prevent_blocked_email_registration_woocommerce' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'schedule_order_cancellation' ), 10, 4 );
		add_action( 'wc_blacklist_delayed_order_cancel', array( $this, 'delayed_order_cancel' ) );
		add_filter( 'preprocess_comment', array( $this, 'prevent_comment' ), 10, 1 );
	}

	/**
	 * ---------------------------------------------------------------------
	 * Shared helpers.
	 * ---------------------------------------------------------------------
	 */

	private function is_woocommerce_ready() {
		return class_exists( 'WooCommerce' );
	}

	private function is_premium_active() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		return $settings_instance->is_premium_active();
	}

	private function get_blacklist_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist';
	}

	private function get_detection_log_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist_detection_log';
	}

	private function get_blacklist_action() {
		return get_option( 'wc_blacklist_action', 'none' );
	}

	private function get_checkout_notice() {
		return get_option(
			'wc_blacklist_checkout_notice',
			__( 'Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
		);
	}

	private function increment_option_counter( $option_name, $step = 1 ) {
		$current = (int) get_option( $option_name, 0 );
		update_option( $option_name, $current + (int) $step );
	}

	private function increment_block_phone_counters() {
		$this->increment_option_counter( 'wc_blacklist_sum_block_phone' );
		$this->increment_option_counter( 'wc_blacklist_sum_block_total' );
	}

	private function increment_block_email_counters() {
		$this->increment_option_counter( 'wc_blacklist_sum_block_email' );
		$this->increment_option_counter( 'wc_blacklist_sum_block_total' );
	}

	private function sanitize_email_if_valid( $email ) {
		$email = sanitize_email( (string) $email );

		if ( ! empty( $email ) && is_email( $email ) ) {
			return $email;
		}

		return '';
	}

	private function normalize_email_if_valid( $email ) {
		$email = $this->sanitize_email_if_valid( $email );

		if ( '' === $email ) {
			return '';
		}

		return yobm_normalize_email( $email );
	}

	private function get_normalized_phone( $phone, $dial_code = '' ) {
		$phone = (string) $phone;

		if ( '' === $phone ) {
			return '';
		}

		return yobm_normalize_phone( $phone, $dial_code );
	}

	private function format_phone_reason( $phones ) {
		$phones = array_filter( array_unique( array_map( 'strval', (array) $phones ) ) );

		$phones = array_map( function( $phone ) {
			$phone = trim( $phone );

			// Remove existing '+' to avoid '++'
			$phone = ltrim( $phone, '+' );

			// Add '+' back
			return '+' . $phone;
		}, $phones );

		return 'blocked_phone_attempt: ' . implode( ', ', $phones );
	}

	private function format_email_reason( $email, $normalized_email = '', $prefix = 'blocked_email_attempt: ' ) {
		$reason = $prefix . $email;

		if ( ! empty( $normalized_email ) && $normalized_email !== $email ) {
			$reason .= ' | normalized: ' . $normalized_email;
		}

		return $reason;
	}

	private function get_phone_match_result( $phones_to_check ) {
		global $wpdb;

		$table_name            = $this->get_blacklist_table_name();
		$is_phone_blocked      = false;
		$blocked_phones        = array();
		$matched_phone_context = array();

		if ( empty( $phones_to_check ) ) {
			return array(
				'is_blocked'            => false,
				'blocked_phones'        => array(),
				'matched_phone_context' => array(),
			);
		}

		foreach ( $phones_to_check as $label => $phone_val ) {
			if ( '' === $phone_val ) {
				continue;
			}

			$hit = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE normalized_phone = %s
					AND is_blocked = 1
					LIMIT 1",
					$phone_val
				)
			);

			if ( ! empty( $hit ) ) {
				$is_phone_blocked                = true;
				$blocked_phones[]                = $phone_val;
				$matched_phone_context[ $label ] = $phone_val;
			}
		}

		return array(
			'is_blocked'            => $is_phone_blocked,
			'blocked_phones'        => array_values( array_unique( $blocked_phones ) ),
			'matched_phone_context' => $matched_phone_context,
		);
	}

	private function get_email_match_result( $email, $normalized_email ) {
		global $wpdb;

		$table_name = $this->get_blacklist_table_name();

		if ( empty( $email ) || ! is_email( $email ) ) {
			return array(
				'is_blocked' => false,
			);
		}

		$result_email = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE is_blocked = 1
				AND (
					email_address = %s
					OR ( %s <> '' AND normalized_email = %s )
				)
				LIMIT 1",
				$email,
				$normalized_email,
				$normalized_email
			)
		);

		return array(
			'is_blocked' => ! empty( $result_email ),
		);
	}

	private function get_checkout_block_match_result( $billing_phone, $shipping_phone, $billing_email, $normalized_billing_email ) {
		$phones_to_check = array();

		if ( ! empty( $billing_phone ) ) {
			$phones_to_check['billing'] = $billing_phone;
		}

		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$phones_to_check['shipping'] = $shipping_phone;
		}

		$phone_result = $this->get_phone_match_result( $phones_to_check );
		$email_result = $this->get_email_match_result( $billing_email, $normalized_billing_email );

		return array(
			'is_phone_blocked'      => $phone_result['is_blocked'],
			'blocked_phones'        => $phone_result['blocked_phones'],
			'matched_phone_context' => $phone_result['matched_phone_context'],
			'is_email_blocked'      => $email_result['is_blocked'],
		);
	}

	private function handle_checkout_match_side_effects( $match_result, $premium_active, $billing_email, $normalized_billing_email ) {
		if ( ! empty( $match_result['is_phone_blocked'] ) ) {
			$this->increment_block_phone_counters();

			if ( $premium_active ) {
				$reason_phone = $this->format_phone_reason( $match_result['blocked_phones'] );
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', $reason_phone );
			}
		}

		if ( ! empty( $match_result['is_email_blocked'] ) ) {
			$this->increment_block_email_counters();

			if ( $premium_active ) {
				$reason_email = $this->format_email_reason( $billing_email, $normalized_billing_email, 'blocked_email_attempt: ' );
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', '', $reason_email );
			}
		}
	}

	private function get_matched_blocked_phones_for_notice( $matched_phone_context ) {
		$billing_phone  = isset( $matched_phone_context['billing'] ) ? $matched_phone_context['billing'] : '';
		$shipping_phone = isset( $matched_phone_context['shipping'] ) ? $matched_phone_context['shipping'] : '';
		$phones         = array_filter( array( $billing_phone, $shipping_phone ) );

		return implode( ', ', array_unique( $phones ) );
	}

	private function log_checkout_snapshot_from_classic( $premium_active, $billing_phone, $shipping_phone, $billing_email, $normalized_billing_email, $billing_country, $shipping_country ) {
		if ( ! $premium_active ) {
			return;
		}

		$user_ip            = get_real_customer_ip();
		$billing_first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
		$billing_last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';

		$billing_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => isset( $_POST['billing_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '',
				'address_2' => isset( $_POST['billing_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) ) : '',
				'city'      => isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '',
				'state'     => isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '',
				'postcode'  => isset( $_POST['billing_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) ) : '',
				'country'   => $billing_country,
			)
		);

		$shipping_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => isset( $_POST['shipping_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_address_1'] ) ) : '',
				'address_2' => isset( $_POST['shipping_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_address_2'] ) ) : '',
				'city'      => isset( $_POST['shipping_city'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_city'] ) ) : '',
				'state'     => isset( $_POST['shipping_state'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_state'] ) ) : '',
				'postcode'  => isset( $_POST['shipping_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_postcode'] ) ) : '',
				'country'   => $shipping_country,
			)
		);

		$billing_address  = $billing_address_data['address_display'];
		$shipping_address = $shipping_address_data['address_display'];

		$items = array();
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$product_id = $cart_item['product_id'];
				$quantity   = (int) $cart_item['quantity'];
				$unit_price = (float) $cart_item['data']->get_price();
				$line_total = (float) $cart_item['line_total'];

				$items[ $product_id ] = array(
					'quantity'   => $quantity,
					'unit_price' => $unit_price,
					'line_total' => $line_total,
				);
			}
		}

		$fees = array();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_fees() as $fee_item ) {
				$fees[] = array(
					'name'   => $fee_item->name,
					'amount' => (float) $fee_item->amount,
				);
			}
		}

		$subtotal          = WC()->cart ? WC()->cart->get_subtotal() : 0;
		$discount_total    = WC()->cart ? WC()->cart->get_discount_total() : 0;
		$shipping_total    = WC()->cart ? WC()->cart->get_shipping_total() : 0;
		$total             = WC()->cart ? WC()->cart->total : 0;
		$chosen_methods    = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$shipping_method   = ! empty( $chosen_methods ) ? $chosen_methods[0] : '';
		$cart_contents_tax = WC()->cart ? WC()->cart->get_cart_contents_tax() : 0;
		$shipping_tax      = WC()->cart ? WC()->cart->get_shipping_tax() : 0;
		$tax_total         = $cart_contents_tax + $shipping_tax;

		$payment_method = '';
		if ( isset( $_POST['payment_method'] ) ) {
			$payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
		} elseif ( WC()->session ) {
			$payment_method = WC()->session->get( 'chosen_payment_method', '' );
		}

		$view_data = array(
			'ip_address'        => $user_ip,
			'first_name'        => $billing_first_name,
			'last_name'         => $billing_last_name,
			'phone'             => $billing_phone,
			'email'             => $billing_email,
			'normalized_email'  => $normalized_billing_email,
			'billing'           => $billing_address,
			'shipping'          => $shipping_address,
			'cart_items'        => $items,
			'fees'              => $fees,
			'cart_subtotal'     => $subtotal,
			'cart_contents_tax' => $cart_contents_tax,
			'coupons'           => WC()->cart ? WC()->cart->get_applied_coupons() : array(),
			'cart_discount'     => $discount_total,
			'cart_shipping'     => array(
				'method'       => $shipping_method,
				'fee'          => $shipping_total,
				'shipping_tax' => $shipping_tax,
			),
			'cart_tax'          => $tax_total,
			'cart_total'        => $total,
			'payment_method'    => $payment_method,
			'currency'          => get_woocommerce_currency(),
		);

		if ( ! empty( $shipping_phone ) ) {
			$view_data['shipping_phone'] = $shipping_phone;
		}

		WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( wp_json_encode( $view_data ) );
	}

	private function log_checkout_snapshot_from_blocks( $premium_active, \WC_Order $order, $billing_phone, $shipping_phone, $billing_email, $normalized_billing_email ) {
		if ( ! $premium_active ) {
			return;
		}

		$billing_country  = (string) $order->get_billing_country();
		$shipping_country = (string) $order->get_shipping_country();
		$items            = array();

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$quantity   = (int) $item->get_quantity();
			$subtotal   = (float) $item->get_subtotal();

			$items[ $product_id ] = array(
				'quantity'   => $quantity,
				'unit_price' => $subtotal / max( 1, $quantity ),
				'line_total' => (float) $item->get_total(),
			);
		}

		$fees = array();
		foreach ( $order->get_fees() as $fee ) {
			$fees[] = array(
				'name'   => $fee->get_name(),
				'amount' => (float) $fee->get_total(),
			);
		}

		$shipping_items        = $order->get_items( 'shipping' );
		$shipping_method_title = '';

		if ( ! empty( $shipping_items ) ) {
			$first_shipping        = reset( $shipping_items );
			$shipping_method_title = $first_shipping ? $first_shipping->get_name() : '';
		}

		$view_data = array(
			'ip_address'        => $order->get_customer_ip_address(),
			'first_name'        => $order->get_billing_first_name(),
			'last_name'         => $order->get_billing_last_name(),
			'phone'             => $billing_phone,
			'email'             => $billing_email,
			'normalized_email'  => $normalized_billing_email,
			'billing'           => yobm_normalize_address_parts(
				array(
					'address_1' => sanitize_text_field( $order->get_billing_address_1() ),
					'address_2' => sanitize_text_field( $order->get_billing_address_2() ),
					'city'      => sanitize_text_field( $order->get_billing_city() ),
					'state'     => sanitize_text_field( $order->get_billing_state() ),
					'postcode'  => sanitize_text_field( $order->get_billing_postcode() ),
					'country'   => $billing_country,
				)
			)['address_display'],
			'shipping'          => yobm_normalize_address_parts(
				array(
					'address_1' => sanitize_text_field( $order->get_shipping_address_1() ),
					'address_2' => sanitize_text_field( $order->get_shipping_address_2() ),
					'city'      => sanitize_text_field( $order->get_shipping_city() ),
					'state'     => sanitize_text_field( $order->get_shipping_state() ),
					'postcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
					'country'   => $shipping_country,
				)
			)['address_display'],
			'cart_items'        => $items,
			'fees'              => $fees,
			'cart_subtotal'     => (float) $order->get_subtotal(),
			'cart_contents_tax' => (float) $order->get_cart_tax(),
			'coupons'           => $order->get_coupon_codes(),
			'cart_discount'     => (float) $order->get_discount_total(),
			'cart_shipping'     => array(
				'method'       => $shipping_method_title,
				'fee'          => (float) $order->get_shipping_total(),
				'shipping_tax' => (float) $order->get_shipping_tax(),
			),
			'cart_tax'          => (float) $order->get_total_tax(),
			'cart_total'        => (float) $order->get_total(),
			'payment_method'    => $order->get_payment_method(),
			'currency'          => $order->get_currency(),
		);

		if ( ! empty( $shipping_phone ) ) {
			$view_data['shipping_phone'] = $shipping_phone;
		}

		WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( wp_json_encode( $view_data ) );
	}

	/**
	 * ---------------------------------------------------------------------
	 * Checkout prevention - Classic.
	 * ---------------------------------------------------------------------
	 */

	public function prevent_order() {
		if ( ! $this->is_woocommerce_ready() ) {
			return;
		}

		$premium_active = $this->is_premium_active();

		$billing_country  = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '';
		$shipping_country = isset( $_POST['shipping_country'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_country'] ) ) : '';

		$billing_dial_code  = isset( $_POST['billing_dial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_dial_code'] ) ) : '';
		$shipping_dial_code = isset( $_POST['shipping_dial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_dial_code'] ) ) : '';

		if ( '' === $billing_dial_code && '' !== $billing_country ) {
			$billing_dial_code = yobm_get_country_dial_code( $billing_country );
		}

		if ( '' === $shipping_dial_code && '' !== $shipping_country ) {
			$shipping_dial_code = yobm_get_country_dial_code( $shipping_country );
		}

		$billing_phone  = isset( $_POST['billing_phone'] ) ? $this->get_normalized_phone( wp_unslash( $_POST['billing_phone'] ), $billing_dial_code ) : '';
		$shipping_phone = isset( $_POST['shipping_phone'] ) ? $this->get_normalized_phone( wp_unslash( $_POST['shipping_phone'] ), $shipping_dial_code ) : '';

		$billing_email            = isset( $_POST['billing_email'] ) ? $this->sanitize_email_if_valid( wp_unslash( $_POST['billing_email'] ) ) : '';
		$normalized_billing_email = $this->normalize_email_if_valid( $billing_email );

		$this->log_checkout_snapshot_from_classic(
			$premium_active,
			$billing_phone,
			$shipping_phone,
			$billing_email,
			$normalized_billing_email,
			$billing_country,
			$shipping_country
		);

		$match_result = $this->get_checkout_block_match_result(
			$billing_phone,
			$shipping_phone,
			$billing_email,
			$normalized_billing_email
		);

		$this->handle_checkout_match_side_effects(
			$match_result,
			$premium_active,
			$billing_email,
			$normalized_billing_email
		);

		if ( ( $match_result['is_phone_blocked'] || $match_result['is_email_blocked'] ) && 'prevent' === $this->get_blacklist_action() ) {
			$blocked_phone_for_email = $this->get_matched_blocked_phones_for_notice( $match_result['matched_phone_context'] );

			if ($match_result['is_phone_blocked']) {
				$display_phone = '+' . ltrim( $blocked_phone_for_email, '+' );

				WC_Blacklist_Manager_Email::send_email_order_block( $display_phone );
			} elseif ($match_result['is_email_blocked']) {
				WC_Blacklist_Manager_Email::send_email_order_block( '', $billing_email );
			}

			if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
				throw new \Exception( esc_html( $this->get_checkout_notice() ) );
			}

			if ( wp_doing_ajax() ) {
				wp_send_json(
					array(
						'result'   => 'failure',
						'messages' => wc_print_notices( true ),
						'refresh'  => true,
						'reload'   => false,
					)
				);
			}
		}
	}

	/**
	 * ---------------------------------------------------------------------
	 * Checkout prevention - Blocks.
	 * ---------------------------------------------------------------------
	 */

	public function prevent_order_for_blocks( \WC_Order $order, $request ) {
		if ( ! $this->is_woocommerce_ready() || ! $order instanceof \WC_Order ) {
			return;
		}

		$premium_active = $this->is_premium_active();

		$billing_country  = (string) $order->get_billing_country();
		$shipping_country = (string) $order->get_shipping_country();

		$raw_billing_phone  = (string) $order->get_billing_phone();
		$raw_shipping_phone = (string) $order->get_shipping_phone();

		$billing_dial_code  = (string) $order->get_meta( '_billing_dial_code' );
		$shipping_dial_code = (string) $order->get_meta( '_shipping_dial_code' );

		$params = array();

		if ( $request && is_object( $request ) && method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();
		}

		if ( '' === $billing_dial_code && isset( $params['billing_dial_code'] ) ) {
			$billing_dial_code = sanitize_text_field( wp_unslash( $params['billing_dial_code'] ) );
		}

		if ( '' === $shipping_dial_code && isset( $params['shipping_dial_code'] ) ) {
			$shipping_dial_code = sanitize_text_field( wp_unslash( $params['shipping_dial_code'] ) );
		}

		// Extra fallback when shipping uses the same phone/address.
		if ( '' === $billing_dial_code && '' !== $shipping_dial_code && $raw_billing_phone === $raw_shipping_phone ) {
			$billing_dial_code = $shipping_dial_code;
		}

		if ( '' === $shipping_dial_code && '' !== $billing_dial_code && $raw_billing_phone === $raw_shipping_phone ) {
			$shipping_dial_code = $billing_dial_code;
		}

		if ( '' === $billing_dial_code && ! empty( $billing_country ) ) {
			$billing_dial_code = yobm_get_country_dial_code( $billing_country );
		}

		if ( '' === $shipping_dial_code && ! empty( $shipping_country ) ) {
			$shipping_dial_code = yobm_get_country_dial_code( $shipping_country );
		}

		$billing_phone  = '' !== $raw_billing_phone ? $this->get_normalized_phone( $raw_billing_phone, $billing_dial_code ) : '';
		$shipping_phone = '' !== $raw_shipping_phone ? $this->get_normalized_phone( $raw_shipping_phone, $shipping_dial_code ) : '';

		$billing_email            = $this->sanitize_email_if_valid( (string) $order->get_billing_email() );
		$normalized_billing_email = $this->normalize_email_if_valid( $billing_email );

		$this->log_checkout_snapshot_from_blocks(
			$premium_active,
			$order,
			$billing_phone,
			$shipping_phone,
			$billing_email,
			$normalized_billing_email
		);

		$match_result = $this->get_checkout_block_match_result(
			$billing_phone,
			$shipping_phone,
			$billing_email,
			$normalized_billing_email
		);

		$this->handle_checkout_match_side_effects(
			$match_result,
			$premium_active,
			$billing_email,
			$normalized_billing_email
		);

		if ( ( $match_result['is_phone_blocked'] || $match_result['is_email_blocked'] ) && 'prevent' === $this->get_blacklist_action() ) {
			$blocked_phone_for_email = $this->get_matched_blocked_phones_for_notice( $match_result['matched_phone_context'] );

			if ($match_result['is_phone_blocked']) {
				$display_phone = '+' . ltrim( $blocked_phone_for_email, '+' );

				WC_Blacklist_Manager_Email::send_email_order_block( $display_phone );
			} elseif ($match_result['is_email_blocked']) {
				WC_Blacklist_Manager_Email::send_email_order_block( '', $billing_email );
			}

			throw new \Exception( esc_html( $this->get_checkout_notice() ) );
		}
	}

	/**
	 * ---------------------------------------------------------------------
	 * REST API order prevention.
	 * Covers raw WooCommerce REST API requests like:
	 * - POST /wp-json/wc/v3/orders
	 * - PUT/PATCH /wp-json/wc/v3/orders/{id}
	 * - POST /wp-json/wc/v3/orders/batch
	 * ---------------------------------------------------------------------
	 */

	public function prevent_wc_rest_api_orders( $dispatch_result, $request, $route, $handler ) {
		$rest_enabled = (int) get_option( 'wc_blacklist_enable_woo_rest_api', 0 );

		if ( 1 !== $rest_enabled ) {
			return $dispatch_result;
		}

		if ( ! $this->is_woocommerce_ready() ) {
			return $dispatch_result;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $dispatch_result;
		}

		$route  = (string) $request->get_route();
		$method = method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '';

		if ( ! $this->is_wc_rest_orders_request( $request ) ) {
			return $dispatch_result;
		}

		$blacklist_action = $this->get_blacklist_action();

		if ( 'prevent' !== $blacklist_action ) {
			return $dispatch_result;
		}

		$premium_active = $this->is_premium_active();

		// Batch endpoint: /wc/v3/orders/batch
		if ( preg_match( '#^/wc/v\d+/orders/batch$#', $route ) ) {
			$batch_groups = array(
				'create' => $request->get_param( 'create' ),
				'update' => $request->get_param( 'update' ),
			);

			foreach ( $batch_groups as $group_key => $group_items ) {
				if ( empty( $group_items ) || ! is_array( $group_items ) ) {
					continue;
				}

				foreach ( $group_items as $index => $payload ) {
					$payload = is_array( $payload ) ? $payload : array();

					$evaluation = $this->evaluate_rest_order_payload( $payload, $premium_active );

					if ( ! empty( $evaluation['is_blocked'] ) ) {
						return new WP_Error(
							'wc_blacklist_blocked',
							$this->get_checkout_notice(),
							array(
								'status'  => 400,
								'details' => array(
									'group'   => $group_key,
									'index'   => $index,
									'reasons' => isset( $evaluation['reasons'] ) ? $evaluation['reasons'] : array(),
								),
							)
						);
					}
				}
			}

			return $dispatch_result;
		}

		// Single order endpoint: /wc/v3/orders or /wc/v3/orders/{id}
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$evaluation = $this->evaluate_rest_order_payload( $payload, $premium_active );

		if ( ! empty( $evaluation['is_blocked'] ) ) {
			return new WP_Error(
				'wc_blacklist_blocked',
				$this->get_checkout_notice(),
				array(
					'status'  => 400,
					'details' => array(
						'reasons' => isset( $evaluation['reasons'] ) ? $evaluation['reasons'] : array(),
					),
				)
			);
		}

		return $dispatch_result;
	}

	private function is_wc_rest_orders_request( WP_REST_Request $request ) {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );

		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return false;
		}

		// Matches:
		// /wc/v3/orders
		// /wc/v3/orders/123
		// /wc/v3/orders/batch
		if ( preg_match( '#^/wc/v\d+/orders(?:/\d+|/batch)?$#', $route ) ) {
			return true;
		}

		return false;
	}

	private function evaluate_rest_order_payload( array $payload, $premium_active = false ) {
		$billing  = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();

		$billing_country  = isset( $billing['country'] ) ? sanitize_text_field( $billing['country'] ) : '';
		$shipping_country = isset( $shipping['country'] ) ? sanitize_text_field( $shipping['country'] ) : '';

		$billing_dial_code  = $this->extract_rest_dial_code( $payload, $billing, 'billing' );
		$shipping_dial_code = $this->extract_rest_dial_code( $payload, $shipping, 'shipping' );

		if ( '' === $billing_dial_code && '' !== $billing_country ) {
			$billing_dial_code = yobm_get_country_dial_code( $billing_country );
		}

		if ( '' === $shipping_dial_code && '' !== $shipping_country ) {
			$shipping_dial_code = yobm_get_country_dial_code( $shipping_country );
		}

		$raw_billing_phone  = isset( $billing['phone'] ) ? wp_unslash( $billing['phone'] ) : '';
		$raw_shipping_phone = isset( $shipping['phone'] ) ? wp_unslash( $shipping['phone'] ) : '';

		$billing_phone  = '' !== $raw_billing_phone ? $this->get_normalized_phone( $raw_billing_phone, $billing_dial_code ) : '';
		$shipping_phone = '' !== $raw_shipping_phone ? $this->get_normalized_phone( $raw_shipping_phone, $shipping_dial_code ) : '';

		$billing_email            = isset( $billing['email'] ) ? $this->sanitize_email_if_valid( wp_unslash( $billing['email'] ) ) : '';
		$normalized_billing_email = $this->normalize_email_if_valid( $billing_email );

		if ( $premium_active ) {
			$this->log_checkout_snapshot_from_rest_payload(
				$payload,
				$billing_phone,
				$shipping_phone,
				$billing_email,
				$normalized_billing_email,
				$billing_country,
				$shipping_country
			);
		}

		$match_result = $this->get_checkout_block_match_result(
			$billing_phone,
			$shipping_phone,
			$billing_email,
			$normalized_billing_email
		);

		$this->handle_checkout_match_side_effects(
			$match_result,
			$premium_active,
			$billing_email,
			$normalized_billing_email
		);

		$reasons = array();

		if ( ! empty( $match_result['is_phone_blocked'] ) ) {
			$reasons[] = $this->format_phone_reason( $match_result['blocked_phones'] );
		}

		if ( ! empty( $match_result['is_email_blocked'] ) ) {
			$reasons[] = $this->format_email_reason( $billing_email, $normalized_billing_email, 'blocked_email_attempt: ' );
		}

		if ( ! empty( $match_result['is_phone_blocked'] ) || ! empty( $match_result['is_email_blocked'] ) ) {
			$blocked_phone_for_email = $this->get_matched_blocked_phones_for_notice( $match_result['matched_phone_context'] );

			if ($match_result['is_phone_blocked']) {
				$display_phone = '+' . ltrim( $blocked_phone_for_email, '+' );

				WC_Blacklist_Manager_Email::send_email_order_block( $display_phone );
			} elseif ($match_result['is_email_blocked']) {
				WC_Blacklist_Manager_Email::send_email_order_block( '', $billing_email );
			}
		}

		return array(
			'is_blocked' => ( ! empty( $match_result['is_phone_blocked'] ) || ! empty( $match_result['is_email_blocked'] ) ),
			'reasons'    => $reasons,
		);
	}

	private function extract_rest_dial_code( array $payload, array $address, $context = 'billing' ) {
		$context = 'shipping' === $context ? 'shipping' : 'billing';

		// Try nested address field first.
		if ( isset( $address['dial_code'] ) ) {
			return sanitize_text_field( $address['dial_code'] );
		}

		// Then top-level custom payload fields.
		$top_level_key = $context . '_dial_code';
		if ( isset( $payload[ $top_level_key ] ) ) {
			return sanitize_text_field( $payload[ $top_level_key ] );
		}

		return '';
	}

	private function log_checkout_snapshot_from_rest_payload( array $payload, $billing_phone, $shipping_phone, $billing_email, $normalized_billing_email, $billing_country, $shipping_country ) {
		$billing  = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();

		$billing_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => isset( $billing['address_1'] ) ? sanitize_text_field( $billing['address_1'] ) : '',
				'address_2' => isset( $billing['address_2'] ) ? sanitize_text_field( $billing['address_2'] ) : '',
				'city'      => isset( $billing['city'] ) ? sanitize_text_field( $billing['city'] ) : '',
				'state'     => isset( $billing['state'] ) ? sanitize_text_field( $billing['state'] ) : '',
				'postcode'  => isset( $billing['postcode'] ) ? sanitize_text_field( $billing['postcode'] ) : '',
				'country'   => $billing_country,
			)
		);

		$shipping_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => isset( $shipping['address_1'] ) ? sanitize_text_field( $shipping['address_1'] ) : '',
				'address_2' => isset( $shipping['address_2'] ) ? sanitize_text_field( $shipping['address_2'] ) : '',
				'city'      => isset( $shipping['city'] ) ? sanitize_text_field( $shipping['city'] ) : '',
				'state'     => isset( $shipping['state'] ) ? sanitize_text_field( $shipping['state'] ) : '',
				'postcode'  => isset( $shipping['postcode'] ) ? sanitize_text_field( $shipping['postcode'] ) : '',
				'country'   => $shipping_country,
			)
		);

		$items = array();

		if ( ! empty( $payload['line_items'] ) && is_array( $payload['line_items'] ) ) {
			foreach ( $payload['line_items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				$quantity   = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
				$line_total = isset( $item['total'] ) ? (float) $item['total'] : 0;
				$unit_price = ( $quantity > 0 ) ? ( $line_total / $quantity ) : 0;

				if ( $product_id > 0 ) {
					$items[ $product_id ] = array(
						'quantity'   => $quantity,
						'unit_price' => $unit_price,
						'line_total' => $line_total,
					);
				}
			}
		}

		$fees = array();
		if ( ! empty( $payload['fee_lines'] ) && is_array( $payload['fee_lines'] ) ) {
			foreach ( $payload['fee_lines'] as $fee ) {
				if ( ! is_array( $fee ) ) {
					continue;
				}

				$fees[] = array(
					'name'   => isset( $fee['name'] ) ? sanitize_text_field( $fee['name'] ) : '',
					'amount' => isset( $fee['total'] ) ? (float) $fee['total'] : 0,
				);
			}
		}

		$shipping_method = '';
		if ( ! empty( $payload['shipping_lines'] ) && is_array( $payload['shipping_lines'] ) ) {
			$first_shipping = reset( $payload['shipping_lines'] );
			if ( is_array( $first_shipping ) && isset( $first_shipping['method_title'] ) ) {
				$shipping_method = sanitize_text_field( $first_shipping['method_title'] );
			}
		}

		$view_data = array(
			'ip_address'        => get_real_customer_ip(),
			'first_name'        => isset( $billing['first_name'] ) ? sanitize_text_field( $billing['first_name'] ) : '',
			'last_name'         => isset( $billing['last_name'] ) ? sanitize_text_field( $billing['last_name'] ) : '',
			'phone'             => $billing_phone,
			'email'             => $billing_email,
			'normalized_email'  => $normalized_billing_email,
			'billing'           => $billing_address_data['address_display'],
			'shipping'          => $shipping_address_data['address_display'],
			'cart_items'        => $items,
			'fees'              => $fees,
			'cart_subtotal'     => isset( $payload['subtotal'] ) ? (float) $payload['subtotal'] : 0,
			'cart_contents_tax' => isset( $payload['cart_tax'] ) ? (float) $payload['cart_tax'] : 0,
			'coupons'           => isset( $payload['coupon_lines'] ) && is_array( $payload['coupon_lines'] ) ? $payload['coupon_lines'] : array(),
			'cart_discount'     => isset( $payload['discount_total'] ) ? (float) $payload['discount_total'] : 0,
			'cart_shipping'     => array(
				'method'       => $shipping_method,
				'fee'          => isset( $payload['shipping_total'] ) ? (float) $payload['shipping_total'] : 0,
				'shipping_tax' => isset( $payload['shipping_tax'] ) ? (float) $payload['shipping_tax'] : 0,
			),
			'cart_tax'          => isset( $payload['total_tax'] ) ? (float) $payload['total_tax'] : 0,
			'cart_total'        => isset( $payload['total'] ) ? (float) $payload['total'] : 0,
			'payment_method'    => isset( $payload['payment_method'] ) ? sanitize_text_field( $payload['payment_method'] ) : '',
			'currency'          => isset( $payload['currency'] ) ? sanitize_text_field( $payload['currency'] ) : get_woocommerce_currency(),
		);

		if ( ! empty( $shipping_phone ) ) {
			$view_data['shipping_phone'] = $shipping_phone;
		}

		WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( wp_json_encode( $view_data ) );
	}

	/**
	 * ---------------------------------------------------------------------
	 * Registration.
	 * ---------------------------------------------------------------------
	 */

	public function prevent_blocked_email_registration( $errors, $sanitized_user_login, $user_email ) {
		return $this->handle_registration_block( $errors, $sanitized_user_login, $user_email );
	}

	public function prevent_blocked_email_registration_woocommerce( $errors, $username, $email ) {
		if ( ! $this->is_woocommerce_ready() ) {
			return $errors;
		}

		return $this->handle_registration_block( $errors, $username, $email );
	}

	private function handle_registration_block( $errors, $username, $email ) {
		global $wpdb;

		if ( ! get_option( 'wc_blacklist_block_user_registration', 0 ) ) {
			return $errors;
		}

		$premium_active = $this->is_premium_active();
		$table_name     = $this->get_blacklist_table_name();

		$email            = $this->sanitize_email_if_valid( $email );
		$normalized_email = $this->normalize_email_if_valid( $email );

		$email_blocked = ! empty(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 1
					AND (
						email_address = %s
						OR ( %s <> '' AND normalized_email = %s )
					)
					LIMIT 1",
					$email,
					$normalized_email,
					$normalized_email
				)
			)
		);

		$email_suspected = ! empty(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 0
					AND (
						email_address = %s
						OR ( %s <> '' AND normalized_email = %s )
					)
					LIMIT 1",
					$email,
					$normalized_email,
					$normalized_email
				)
			)
		);

		$phone                = '';
		$normalized_phone     = '';
		$phone_account_active = false;

		if (
			(
				is_plugin_active( 'wc-advanced-accounts/wc-advanced-accounts.php' ) ||
				(
					is_plugin_active( 'wc-advanced-accounts-premium/wc-advanced-accounts-premium.php' ) &&
					get_option( 'wc_advanced_accounts_premium_license_status' ) === 'activated'
				)
			) &&
			'yes' === get_option( 'yoaa_wc_enable_phone_number_account' )
		) {
			$phone_account_active = true;

			if ( preg_match( '/^\d+\-\d+$/', (string) $username ) ) {
				$phone = '+' . str_replace( '-', '', (string) $username );
			} else {
				$phone = sanitize_text_field( wp_unslash( $username ) );
			}

			$normalized_phone = yobm_normalize_phone( $phone );
		}

		if ( $premium_active ) {
			$user_ip   = get_real_customer_ip();
			$view_data = array(
				'ip_address'       => $user_ip,
				'email'            => $email,
				'normalized_email' => $normalized_email,
			);

			if ( ! empty( $phone ) ) {
				$view_data['phone'] = $phone;
			}

			if ( ! empty( $normalized_phone ) ) {
				$view_data['normalized_phone'] = $normalized_phone;
			}

			$view_json = wp_json_encode( $view_data );

			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block( $view_json );
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect( $view_json );
		}

		if ( $email_blocked ) {
			$this->increment_block_email_counters();
			WC_Blacklist_Manager_Email::send_email_registration_block( '', $email );

			if ( $premium_active ) {
				$reason_email = $this->format_email_reason( $email, $normalized_email, 'blocked_email_attempt: ' );
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block( '', '', $reason_email );
			}

			wc_blacklist_add_registration_notice( $errors );
		} elseif ( $email_suspected ) {
			$this->increment_block_email_counters();
			WC_Blacklist_Manager_Email::send_email_registration_suspect( '', $email );

			if ( $premium_active ) {
				$reason_email = $this->format_email_reason( $email, $normalized_email, 'suspected_email_attempt: ' );
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect( '', '', $reason_email );
			}
		} else {
			$email = '';
		}

		if ( $phone_account_active && '' !== $normalized_phone ) {
			$phone_blocked = ! empty(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1
						FROM {$table_name}
						WHERE is_blocked = 1
						AND (
							phone_number = %s
							OR ( normalized_phone <> '' AND normalized_phone = %s )
						)
						LIMIT 1",
						$normalized_phone,
						$normalized_phone
					)
				)
			);

			$phone_suspected = ! empty(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1
						FROM {$table_name}
						WHERE is_blocked = 0
						AND (
							phone_number = %s
							OR ( normalized_phone <> '' AND normalized_phone = %s )
						)
						LIMIT 1",
						$normalized_phone,
						$normalized_phone
					)
				)
			);

			if ( $phone_blocked ) {
				$this->increment_block_phone_counters();
				WC_Blacklist_Manager_Email::send_email_registration_block( $normalized_phone );

				if ( $premium_active ) {
					$reason_phone = 'blocked_phone_attempt: ' . $phone;

					if ( ! empty( $normalized_phone ) && $normalized_phone !== $phone ) {
						$reason_phone .= ' | normalized: ' . $normalized_phone;
					}

					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block( '', $reason_phone );
				}

				wc_blacklist_add_registration_notice( $errors );
			} elseif ( $phone_suspected ) {
				$this->increment_block_phone_counters();
				WC_Blacklist_Manager_Email::send_email_registration_suspect( $normalized_phone );

				if ( $premium_active ) {
					$reason_phone = 'suspected_phone_attempt: ' . $phone;

					if ( ! empty( $normalized_phone ) && $normalized_phone !== $phone ) {
						$reason_phone .= ' | normalized: ' . $normalized_phone;
					}

					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect( '', '', $reason_phone );
				}
			}
		}

		return $errors;
	}

	/**
	 * ---------------------------------------------------------------------
	 * Cancel later if action = cancel.
	 * ---------------------------------------------------------------------
	 */

	public function schedule_order_cancellation( $order_id, $old_status, $new_status, $order ) {
		if ( ! $this->is_woocommerce_ready() ) {
			return;
		}

		if ( ! in_array( $new_status, array( 'on-hold', 'processing', 'completed' ), true ) ) {
			return;
		}

		if ( 'cancel' !== $this->get_blacklist_action() ) {
			return;
		}

		global $wpdb;

		$premium_active      = $this->is_premium_active();
		$table_name          = $this->get_blacklist_table_name();
		$table_detection_log = $this->get_detection_log_table_name();

		$billing_phone            = sanitize_text_field( $order->get_billing_phone() );
		$normalized_billing_phone = '';

		if ( ! empty( $billing_phone ) ) {
			$billing_country          = $order->get_billing_country();
			$billing_dial_code        = yobm_get_country_dial_code( $billing_country );
			$normalized_billing_phone = yobm_normalize_phone( $billing_phone, $billing_dial_code );
		}

		$billing_email            = $this->sanitize_email_if_valid( $order->get_billing_email() );
		$normalized_billing_email = $this->normalize_email_if_valid( $billing_email );

		$is_blocked = false;

		if ( '' !== $normalized_billing_phone ) {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 1
					AND (
						phone_number = %s
						OR ( normalized_phone <> '' AND normalized_phone = %s )
					)
					LIMIT 1",
					$normalized_billing_phone,
					$normalized_billing_phone
				)
			);

			if ( ! empty( $result ) ) {
				$is_blocked = true;
				$this->increment_block_phone_counters();

				if ( $premium_active ) {
					$timestamp = current_time( 'mysql' );
					$type      = 'bot';
					$source    = 'woo_order_' . $order_id;
					$action    = 'cancel';
					$details   = 'blocked_phone_attempt: ' . $billing_phone;

					if ( $normalized_billing_phone !== $billing_phone ) {
						$details .= ' | normalized: ' . $normalized_billing_phone;
					}

					$wpdb->insert(
						$table_detection_log,
						array(
							'timestamp' => $timestamp,
							'type'      => $type,
							'source'    => $source,
							'action'    => $action,
							'details'   => $details,
						)
					);
				}
			}
		}

		if ( ! empty( $billing_email ) ) {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 1
					AND (
						email_address = %s
						OR ( %s <> '' AND normalized_email = %s )
					)
					LIMIT 1",
					$billing_email,
					$normalized_billing_email,
					$normalized_billing_email
				)
			);

			if ( ! empty( $result ) ) {
				$is_blocked = true;
				$this->increment_block_email_counters();

				if ( $premium_active ) {
					$timestamp = current_time( 'mysql' );
					$type      = 'bot';
					$source    = 'woo_order_' . $order_id;
					$action    = 'cancel';
					$details   = $this->format_email_reason( $billing_email, $normalized_billing_email, 'blocked_email_attempt: ' );

					$wpdb->insert(
						$table_detection_log,
						array(
							'timestamp' => $timestamp,
							'type'      => $type,
							'source'    => $source,
							'action'    => $action,
							'details'   => $details,
						)
					);
				}
			}
		}

		if ( $is_blocked ) {
			$order_delay = max( 0, intval( get_option( 'wc_blacklist_order_delay', 0 ) ) );

			if ( $order_delay > 0 ) {
				wp_schedule_single_event( time() + ( $order_delay * 60 ), 'wc_blacklist_delayed_order_cancel', array( $order_id ) );
			} else {
				$order->update_status( 'cancelled', __( 'Order cancelled due to blacklist match.', 'wc-blacklist-manager' ) );
			}
		}
	}

	public function delayed_order_cancel( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order && ! $order->has_status( 'cancelled' ) ) {
			$order->update_status( 'cancelled', __( 'Order cancelled due to blocklist match.', 'wc-blacklist-manager' ) );
		}
	}

	/**
	 * ---------------------------------------------------------------------
	 * Comments / reviews.
	 * ---------------------------------------------------------------------
	 */

	public function prevent_comment( $commentdata ) {
		if ( get_option( 'wc_blacklist_comment_blocking_enabled', 0 ) !== '1' ) {
			return $commentdata;
		}

		global $wpdb;

		$premium_active = $this->is_premium_active();
		$table_name     = $this->get_blacklist_table_name();

		$author_email = isset( $commentdata['comment_author_email'] )
			? trim( $commentdata['comment_author_email'] )
			: '';

		$author_email            = $this->sanitize_email_if_valid( $author_email );
		$normalized_author_email = $this->normalize_email_if_valid( $author_email );

		if ( ! empty( $author_email ) ) {
			$is_blocked = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 1
					AND (
						email_address = %s
						OR ( %s <> '' AND normalized_email = %s )
					)
					LIMIT 1",
					$author_email,
					$normalized_author_email,
					$normalized_author_email
				)
			);

			$is_suspected = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 0
					AND (
						email_address = %s
						OR ( %s <> '' AND normalized_email = %s )
					)
					LIMIT 1",
					$author_email,
					$normalized_author_email,
					$normalized_author_email
				)
			);

			$user_ip = get_real_customer_ip();

			$view_data = array(
				'ip_address'       => $user_ip,
				'email'            => $author_email,
				'normalized_email' => $normalized_author_email,
			);
			$view_json = wp_json_encode( $view_data );

			$source = 'comment';
			if ( ! empty( $commentdata['comment_type'] ) && 'review' === $commentdata['comment_type'] ) {
				$source = 'woo_review';
			}

			if ( $is_blocked ) {
				$this->increment_block_email_counters();
				WC_Blacklist_Manager_Email::send_email_comment_block( $author_email );

				if ( $premium_active ) {
					$reason_email = $this->format_email_reason( $author_email, $normalized_author_email, 'blocked_email_attempt: ' );
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block( $view_json, $source, $reason_email );
				}

				$notice_template = get_option(
					'wc_blacklist_comment_notice',
					__( 'Sorry! You are no longer allowed to submit a comment on our site. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
				);

				$notice = wp_kses_post( $notice_template );

				wp_die(
					esc_html( $notice ),
					esc_html__( 'Comment Blocked', 'wc-blacklist-manager' ),
					array( 'response' => 403 )
				);
			} elseif ( $is_suspected ) {
				$this->increment_block_email_counters();
				WC_Blacklist_Manager_Email::send_email_comment_suspect( $author_email );

				if ( $premium_active ) {
					$reason_email = $this->format_email_reason( $author_email, $normalized_author_email, 'suspected_email_attempt: ' );
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_suspect( $view_json, $source, $reason_email );
				}
			}
		}

		return $commentdata;
	}
}

new WC_Blacklist_Manager_Blocklist_Prevention();