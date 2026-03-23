<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Blocklist_Actions {
	public function __construct() {
		add_action('woocommerce_checkout_process', [$this, 'prevent_order']);
		add_action('woocommerce_store_api_checkout_order_processed', [$this, 'prevent_order_for_blocks'], 10, 1);
		add_filter('registration_errors', [$this, 'prevent_blocked_email_registration'], 10, 3);
		add_filter('woocommerce_registration_errors', [$this, 'prevent_blocked_email_registration_woocommerce'], 10, 3);
		add_action('woocommerce_order_status_changed', [$this, 'schedule_order_cancellation'], 10, 4);
		add_action('wc_blacklist_delayed_order_cancel', [$this, 'delayed_order_cancel']);
		add_filter('preprocess_comment', [$this, 'prevent_comment'], 10, 1);
	}

	public function prevent_order() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		// Countries.
		$billing_country  = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '';
		$shipping_country = isset( $_POST['shipping_country'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_country'] ) ) : '';

		// Selected dial codes from checkout.
		$billing_dial_code  = isset( $_POST['billing_dial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_dial_code'] ) ) : '';
		$shipping_dial_code = isset( $_POST['shipping_dial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_dial_code'] ) ) : '';

		// Fallback to WooCommerce country calling codes if dial codes are missing.
		if ( '' === $billing_dial_code && '' !== $billing_country ) {
			$billing_dial_code = yobm_get_country_dial_code( $billing_country );
		}

		if ( '' === $shipping_dial_code && '' !== $shipping_country ) {
			$shipping_dial_code = yobm_get_country_dial_code( $shipping_country );
		}

		// Normalize phones into canonical comparable values.
		$billing_phone  = isset( $_POST['billing_phone'] ) ? yobm_normalize_phone( $_POST['billing_phone'], $billing_dial_code ) : '';
		$shipping_phone = isset( $_POST['shipping_phone'] ) ? yobm_normalize_phone( $_POST['shipping_phone'], $shipping_dial_code ) : '';

		$billing_email            = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
		$normalized_billing_email = '';

		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
			$normalized_billing_email = yobm_normalize_email( $billing_email );
		}

		if ( $premium_active ) {
			$user_ip            = $this->get_the_user_ip();
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

			// Pull cart items (product_id => quantity).
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

			$view_json = wp_json_encode( $view_data );

			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( $view_json );
		}

		$blacklist_action = get_option( 'wc_blacklist_action', 'none' );
		$checkout_notice  = get_option(
			'wc_blacklist_checkout_notice',
			__( 'Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
		);

		// Check if the phone is blocked.
		$is_phone_blocked      = false;
		$blocked_phones        = array();
		$phones_to_check       = array();
		$matched_phone_context = array();

		if ( ! empty( $billing_phone ) ) {
			$phones_to_check['billing'] = $billing_phone;
		}

		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$phones_to_check['shipping'] = $shipping_phone;
		}

		if ( ! empty( $phones_to_check ) ) {
			foreach ( $phones_to_check as $label => $phone_val ) {
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

			if ( $is_phone_blocked ) {
				$sum_block_phone = (int) get_option( 'wc_blacklist_sum_block_phone', 0 );
				update_option( 'wc_blacklist_sum_block_phone', $sum_block_phone + 1 );

				$sum_block_total = (int) get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

				if ( $premium_active ) {
					$reason_phone = 'blocked_phone_attempt: ' . implode( ', ', array_unique( $blocked_phones ) );
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', $reason_phone );
				}

				$billing_phone  = isset( $matched_phone_context['billing'] ) ? $matched_phone_context['billing'] : '';
				$shipping_phone = isset( $matched_phone_context['shipping'] ) ? $matched_phone_context['shipping'] : '';
			} else {
				$billing_phone  = '';
				$shipping_phone = '';
			}
		}

		// Check if the email is blocked.
		$is_email_blocked = false;

		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
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
					$billing_email,
					$normalized_billing_email,
					$normalized_billing_email
				)
			);

			$is_email_blocked = ! empty( $result_email );

			if ( $is_email_blocked ) {
				$sum_block_email = (int) get_option( 'wc_blacklist_sum_block_email', 0 );
				update_option( 'wc_blacklist_sum_block_email', $sum_block_email + 1 );

				$sum_block_total = (int) get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

				if ( $premium_active ) {
					$reason_email = 'blocked_email_attempt: ' . $billing_email;

					if ( ! empty( $normalized_billing_email ) && $normalized_billing_email !== $billing_email ) {
						$reason_email .= ' | normalized: ' . $normalized_billing_email;
					}

					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', '', $reason_email );
				}
			} else {
				$billing_email = '';
			}
		}

		// If either the phone or email is blocked and the blacklist action is set to "prevent".
		if ( ( $is_phone_blocked || $is_email_blocked ) && 'prevent' === $blacklist_action ) {
			$phones_to_email         = array_filter( array( $billing_phone, $shipping_phone ) );
			$blocked_phone_for_email = implode( ', ', array_unique( $phones_to_email ) );

			WC_Blacklist_Manager_Email::send_email_order_block( $blocked_phone_for_email, $billing_email );

			// Hard block checkout.
			if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
				throw new \Exception( esc_html( $checkout_notice ) );
			}

			// Fallback for AJAX context.
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

	public function prevent_order_for_blocks( \WC_Order $order ) {
		if ( ! class_exists( 'WooCommerce' ) || ! $order instanceof \WC_Order ) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		// Countries.
		$billing_country  = (string) $order->get_billing_country();
		$shipping_country = (string) $order->get_shipping_country();

		// Phones from order.
		$raw_billing_phone  = (string) $order->get_billing_phone();
		$raw_shipping_phone = (string) $order->get_shipping_phone();

		/*
		* For Checkout Blocks orders, we usually no longer have the checkout POST dial code,
		* so use country-based fallback when needed.
		*
		* Keep the same "skip country code" behavior as your classic checkout function.
		*/
		$billing_dial_code  = '';
		$shipping_dial_code = '';

		if ( '' !== $billing_country ) {
			$billing_dial_code = yobm_get_country_dial_code( $billing_country );
		}

		if ( '' !== $shipping_country ) {
			$shipping_dial_code = yobm_get_country_dial_code( $shipping_country );
		}

		// Normalize phones into canonical comparable values.
		$billing_phone  = '' !== $raw_billing_phone ? yobm_normalize_phone( $raw_billing_phone, $billing_dial_code ) : '';
		$shipping_phone = '' !== $raw_shipping_phone ? yobm_normalize_phone( $raw_shipping_phone, $shipping_dial_code ) : '';

		$billing_email            = sanitize_email( (string) $order->get_billing_email() );
		$normalized_billing_email = '';

		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
			$normalized_billing_email = yobm_normalize_email( $billing_email );
		}

		// Premium snapshot logging.
		if ( $premium_active ) {
			$items = array();

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

		$blacklist_action = get_option( 'wc_blacklist_action', 'none' );
		$checkout_notice  = get_option(
			'wc_blacklist_checkout_notice',
			__( 'Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
		);

		// Check if the phone is blocked.
		$is_phone_blocked      = false;
		$blocked_phones        = array();
		$phones_to_check       = array();
		$matched_phone_context = array();

		if ( ! empty( $billing_phone ) ) {
			$phones_to_check['billing'] = $billing_phone;
		}

		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$phones_to_check['shipping'] = $shipping_phone;
		}

		if ( ! empty( $phones_to_check ) ) {
			foreach ( $phones_to_check as $label => $phone_val ) {
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

			if ( $is_phone_blocked ) {
				update_option( 'wc_blacklist_sum_block_phone', (int) get_option( 'wc_blacklist_sum_block_phone', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );

				if ( $premium_active ) {
					$reason_phone = 'blocked_phone_attempt: ' . implode( ', ', array_unique( $blocked_phones ) );
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', $reason_phone );
				}

				$billing_phone  = isset( $matched_phone_context['billing'] ) ? $matched_phone_context['billing'] : '';
				$shipping_phone = isset( $matched_phone_context['shipping'] ) ? $matched_phone_context['shipping'] : '';
			} else {
				$billing_phone  = '';
				$shipping_phone = '';
			}
		}

		$is_email_blocked = false;

		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
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
					$billing_email,
					$normalized_billing_email,
					$normalized_billing_email
				)
			);

			$is_email_blocked = ! empty( $result_email );

			if ( $is_email_blocked ) {
				update_option( 'wc_blacklist_sum_block_email', (int) get_option( 'wc_blacklist_sum_block_email', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );

				if ( $premium_active ) {
					$reason_email = 'blocked_email_attempt: ' . $billing_email;

					if ( ! empty( $normalized_billing_email ) && $normalized_billing_email !== $billing_email ) {
						$reason_email .= ' | normalized: ' . $normalized_billing_email;
					}

					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', '', $reason_email );
				}
			} else {
				$billing_email = '';
			}
		}

		// Block & surface an error to the Checkout Block.
		if ( ( $is_phone_blocked || $is_email_blocked ) && 'prevent' === $blacklist_action ) {
			$phones_to_email         = array_filter( array( $billing_phone, $shipping_phone ) );
			$blocked_phone_for_email = implode( ', ', array_unique( $phones_to_email ) );

			WC_Blacklist_Manager_Email::send_email_order_block( $blocked_phone_for_email, $billing_email );

			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				wc_release_stock_for_order( $order );
				$order->get_data_store()->release_held_coupons( $order );
				$order->delete( true );

				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'wc_blacklist_blocked',
					esc_html( $checkout_notice ),
					400
				);
			} else {
				throw new \Exception( esc_html( $checkout_notice ) );
			}
		}
	}
			
	public function prevent_blocked_email_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_registration_block($errors, $sanitized_user_login, $user_email);
	}

	public function prevent_blocked_email_registration_woocommerce($errors, $username, $email) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		return $this->handle_registration_block($errors, $username, $email);
	}

	private function handle_registration_block( $errors, $username, $email ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		global $wpdb;

		if ( ! get_option( 'wc_blacklist_block_user_registration', 0 ) ) {
			return $errors;
		}

		$table_name = $wpdb->prefix . 'wc_blacklist';

		$email            = sanitize_email( $email );
		$normalized_email = '';

		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		// Check blocked / suspected email.
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

			/*
			* Username may come in formats like:
			* - 84-912345678
			* - +84912345678
			* - 0912345678
			* - 912345678
			*
			* If it matches <dial>-<number>, reconstruct it as +<dial><number>
			* before normalizing. Otherwise pass the raw username through.
			*/
			if ( preg_match( '/^\d+\-\d+$/', (string) $username ) ) {
				$phone = '+' . str_replace( '-', '', (string) $username );
			} else {
				$phone = sanitize_text_field( wp_unslash( $username ) );
			}

			$normalized_phone = yobm_normalize_phone( $phone );
		}

		if ( $premium_active ) {
			$user_ip   = $this->get_the_user_ip();
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
			$sum_block_email = get_option( 'wc_blacklist_sum_block_email', 0 );
			update_option( 'wc_blacklist_sum_block_email', $sum_block_email + 1 );

			$sum_block_total = get_option( 'wc_blacklist_sum_block_total', 0 );
			update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

			WC_Blacklist_Manager_Email::send_email_registration_block( '', $email );

			if ( $premium_active ) {
				$reason_email = 'blocked_email_attempt: ' . $email;

				if ( ! empty( $normalized_email ) && $normalized_email !== $email ) {
					$reason_email .= ' | normalized: ' . $normalized_email;
				}

				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block( '', '', $reason_email );
			}

			wc_blacklist_add_registration_notice( $errors );
		} elseif ( $email_suspected ) {
			$sum_block_email = get_option( 'wc_blacklist_sum_block_email', 0 );
			update_option( 'wc_blacklist_sum_block_email', $sum_block_email + 1 );

			$sum_block_total = get_option( 'wc_blacklist_sum_block_total', 0 );
			update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

			WC_Blacklist_Manager_Email::send_email_registration_suspect( '', $email );

			if ( $premium_active ) {
				$reason_email = 'suspected_email_attempt: ' . $email;

				if ( ! empty( $normalized_email ) && $normalized_email !== $email ) {
					$reason_email .= ' | normalized: ' . $normalized_email;
				}

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
				$sum_phone = get_option( 'wc_blacklist_sum_block_phone', 0 );
				update_option( 'wc_blacklist_sum_block_phone', $sum_phone + 1 );

				$sum_total = get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_total + 1 );

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
				$sum_phone = get_option( 'wc_blacklist_sum_block_phone', 0 );
				update_option( 'wc_blacklist_sum_block_phone', $sum_phone + 1 );

				$sum_total = get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_total + 1 );

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

	public function schedule_order_cancellation( $order_id, $old_status, $new_status, $order ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! in_array( $new_status, array( 'on-hold', 'processing', 'completed' ), true ) ) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name          = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$blacklist_action    = get_option( 'wc_blacklist_action', 'none' );

		if ( 'cancel' !== $blacklist_action ) {
			return;
		}

		$billing_phone            = sanitize_text_field( $order->get_billing_phone() );
		$normalized_billing_phone = '';

		if ( ! empty( $billing_phone ) ) {
			$billing_country         = $order->get_billing_country();
			$billing_dial_code       = yobm_get_country_dial_code( $billing_country );
			$normalized_billing_phone = yobm_normalize_phone( $billing_phone, $billing_dial_code );
		}

		$billing_email            = sanitize_email( $order->get_billing_email() );
		$normalized_billing_email = '';

		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
			$normalized_billing_email = yobm_normalize_email( $billing_email );
		}

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

			$phone_blocked = ! empty( $result );

			if ( $phone_blocked ) {
				$is_blocked = true;

				$sum_block_phone = get_option( 'wc_blacklist_sum_block_phone', 0 );
				update_option( 'wc_blacklist_sum_block_phone', $sum_block_phone + 1 );

				$sum_block_total = get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

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

		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
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

			$email_blocked = ! empty( $result );

			if ( $email_blocked ) {
				$is_blocked = true;

				$sum_block_email = get_option( 'wc_blacklist_sum_block_email', 0 );
				update_option( 'wc_blacklist_sum_block_email', $sum_block_email + 1 );

				$sum_block_total = get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

				if ( $premium_active ) {
					$timestamp = current_time( 'mysql' );
					$type      = 'bot';
					$source    = 'woo_order_' . $order_id;
					$action    = 'cancel';
					$details   = 'blocked_email_attempt: ' . $billing_email;

					if ( ! empty( $normalized_billing_email ) && $normalized_billing_email !== $billing_email ) {
						$details .= ' | normalized: ' . $normalized_billing_email;
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

		if ( $is_blocked ) {
			$order_delay = max( 0, intval( get_option( 'wc_blacklist_order_delay', 0 ) ) );

			if ( $order_delay > 0 ) {
				wp_schedule_single_event( time() + ( $order_delay * 60 ), 'wc_blacklist_delayed_order_cancel', array( $order_id ) );
			} else {
				$order->update_status( 'cancelled', __( 'Order cancelled due to blacklist match.', 'wc-blacklist-manager' ) );
			}
		}
	}

	public function delayed_order_cancel($order_id) {
		$order = wc_get_order($order_id);
		if ($order && !$order->has_status('cancelled')) {
			$order->update_status('cancelled', __('Order cancelled due to blocklist match.', 'wc-blacklist-manager'));
		}
	}

	public function prevent_comment( $commentdata ) {
		if ( get_option( 'wc_blacklist_comment_blocking_enabled', 0 ) !== '1' ) {
			return $commentdata;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$author_email = isset( $commentdata['comment_author_email'] )
			? trim( $commentdata['comment_author_email'] )
			: '';

		$author_email            = sanitize_email( $author_email );
		$normalized_author_email = '';

		if ( ! empty( $author_email ) && is_email( $author_email ) ) {
			$normalized_author_email = yobm_normalize_email( $author_email );

			// Check for a blocked email.
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

			$user_ip = $this->get_the_user_ip();

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
				$sum_block_email = get_option( 'wc_blacklist_sum_block_email', 0 );
				update_option( 'wc_blacklist_sum_block_email', $sum_block_email + 1 );

				$sum_block_total = get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

				WC_Blacklist_Manager_Email::send_email_comment_block( $author_email );

				if ( $premium_active ) {
					$reason_email = 'blocked_email_attempt: ' . $author_email;

					if ( ! empty( $normalized_author_email ) && $normalized_author_email !== $author_email ) {
						$reason_email .= ' | normalized: ' . $normalized_author_email;
					}

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
				$sum_block_email = get_option( 'wc_blacklist_sum_block_email', 0 );
				update_option( 'wc_blacklist_sum_block_email', $sum_block_email + 1 );

				$sum_block_total = get_option( 'wc_blacklist_sum_block_total', 0 );
				update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

				WC_Blacklist_Manager_Email::send_email_comment_suspect( $author_email );

				if ( $premium_active ) {
					$reason_email = 'suspected_email_attempt: ' . $author_email;

					if ( ! empty( $normalized_author_email ) && $normalized_author_email !== $author_email ) {
						$reason_email .= ' | normalized: ' . $normalized_author_email;
					}

					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_suspect( $view_json, $source, $reason_email );
				}
			}
		}

		return $commentdata;
	}

	private function get_the_user_ip() {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			// Cloudflare connecting IP
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Client IP
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// X-Forwarded-For header
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			// Remote address
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return sanitize_text_field($ip);
	}
}

new WC_Blacklist_Manager_Blocklist_Actions();
