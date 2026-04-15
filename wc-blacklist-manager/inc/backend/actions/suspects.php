<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Suspicious_Actions {

	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'schedule_check_and_notify_any' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'schedule_check_and_notify_any' ), 10, 1 );

		add_action( 'wc_blacklist_check_and_notify', array( $this, 'check_order_and_notify' ), 10, 1 );
	}

	public function schedule_check_and_notify_any( $arg1, $maybe_posted = null, $maybe_order = null ) {
		if ( $arg1 instanceof \WC_Order ) {
			$order_id = $arg1->get_id();
		} elseif ( is_numeric( $arg1 ) ) {
			$order_id = (int) $arg1;
		} elseif ( $maybe_order instanceof \WC_Order ) {
			$order_id = $maybe_order->get_id();
		} else {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			if ( defined( 'WC_ABSPATH' ) && file_exists( WC_ABSPATH . 'packages/action-scheduler/action-scheduler.php' ) ) {
				require_once WC_ABSPATH . 'packages/action-scheduler/action-scheduler.php';
			}
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wc_blacklist_check_and_notify', array( 'order_id' => $order_id ) );
		} else {
			$this->check_order_and_notify( $order_id );
		}
	}

	/**
	 * Queue the check/notify into Action Scheduler (works for BOTH classic + blocks).
	 *
	 * @param int|\WC_Order $arg1 Order ID (classic) or WC_Order (blocks)
	 */
	public function check_order_and_notify( $order_id ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		global $wpdb;

		$table_name          = $wpdb->prefix . 'wc_blacklist';
		$address_table_name  = $wpdb->prefix . 'wc_blacklist_addresses';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$first_name    = sanitize_text_field( $order->get_billing_first_name() );
		$last_name     = sanitize_text_field( $order->get_billing_last_name() );
		$customer_name = trim( $first_name . ' ' . $last_name );

		$shipping_first_name = sanitize_text_field( $order->get_shipping_first_name() );
		$shipping_last_name  = sanitize_text_field( $order->get_shipping_last_name() );
		$shipping_full_name  = trim( $shipping_first_name . ' ' . $shipping_last_name );

		$billing_phone  = sanitize_text_field( $order->get_billing_phone() );
		$shipping_phone = sanitize_text_field( $order->get_shipping_phone() );
		
		$email          = sanitize_email( $order->get_billing_email() );
		$device_id      = $this->get_order_device_id( $order );
		$user_ip        = sanitize_text_field( $order->get_customer_ip_address() );
		$order_edit_url = admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );

		$normalized_email = '';
		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		$billing_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_billing_address_1() ),
				'address_2' => sanitize_text_field( $order->get_billing_address_2() ),
				'city'      => sanitize_text_field( $order->get_billing_city() ),
				'state'     => sanitize_text_field( $order->get_billing_state() ),
				'postcode'  => sanitize_text_field( $order->get_billing_postcode() ),
				'country'   => sanitize_text_field( $order->get_billing_country() ),
			)
		);

		$shipping_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_shipping_address_1() ),
				'address_2' => sanitize_text_field( $order->get_shipping_address_2() ),
				'city'      => sanitize_text_field( $order->get_shipping_city() ),
				'state'     => sanitize_text_field( $order->get_shipping_state() ),
				'postcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
				'country'   => sanitize_text_field( $order->get_shipping_country() ),
			)
		);

		$customer_address         = $billing_address['address_display'];
		$shipping_address_display = $shipping_address['address_display'];

		$send_email = false;
		$reasons    = array();
		$view_data  = array(
			'device_id'           => $device_id,
			'ip_address'          => $user_ip,
			'first_name'          => $first_name,
			'last_name'           => $last_name,
			'shipping_first_name' => $shipping_first_name,
			'shipping_last_name'  => $shipping_last_name,
			'phone'               => $billing_phone,
		);

		if ( ! empty( $shipping_phone ) ) {
			$view_data['shipping_phone'] = $shipping_phone;
		}

		$view_data += array(
			'email'            => $email,
			'normalized_email' => $normalized_email,
			'billing'          => $customer_address,
			'shipping'         => $shipping_address_display,
		);

		// Phone.
		$phones_to_check = array();
		if ( ! empty( $billing_phone ) ) {
			$phones_to_check['billing'] = $billing_phone;
		}
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$phones_to_check['shipping'] = $shipping_phone;
		}

		$phones_matched = array();

		foreach ( $phones_to_check as $label => $p ) {
			$normalized_phone = yobm_normalize_phone( $p );

			$sql_phone = $wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE is_blocked = 0
				AND (
					phone_number = %s
					OR ( normalized_phone <> '' AND normalized_phone = %s )
				)
				LIMIT 1",
				$p,
				$normalized_phone
			);

			$phone_blacklisted = ! empty( $wpdb->get_var( $sql_phone ) );

			if ( $phone_blacklisted ) {
				$send_email       = true;
				$phones_matched[] = $p;
				$reasons[]        = ( 'shipping' === $label ? 'suspected_shipping_phone_attempt: ' : 'suspected_phone_attempt: ' ) . $p;

				update_option( 'wc_blacklist_sum_block_phone', (int) get_option( 'wc_blacklist_sum_block_phone', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
			}
		}

		if ( ! in_array( $billing_phone, $phones_matched, true ) ) {
			$billing_phone = '';
		}
		if ( ! in_array( $shipping_phone, $phones_matched, true ) ) {
			$shipping_phone = '';
		}

		// Email.
		if ( ! empty( $email ) && is_email( $email ) ) {
			$sql_email = $wpdb->prepare(
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
			);

			$email_blacklisted = ! empty( $wpdb->get_var( $sql_email ) );

			if ( $email_blacklisted ) {
				$send_email = true;
				$reason     = 'suspected_email_attempt: ' . $email;

				if ( ! empty( $normalized_email ) && $normalized_email !== $email ) {
					$reason .= ' | normalized: ' . $normalized_email;
				}

				$reasons[] = $reason;

				update_option( 'wc_blacklist_sum_block_email', (int) get_option( 'wc_blacklist_sum_block_email', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
			} else {
				$email = '';
			}
		} else {
			$email = '';
		}

		// Billing name.
		if ( ! empty( $customer_name ) && $premium_active && get_option( 'wc_blacklist_customer_name_blocking_enabled' ) === '1' ) {
			$sql_billing_name = $wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE LOWER(CONCAT(first_name, ' ', last_name)) = %s
				AND is_blocked = 0
				LIMIT 1",
				strtolower( $customer_name )
			);

			$customer_name_blacklisted = ! empty( $wpdb->get_var( $sql_billing_name ) );

			if ( $customer_name_blacklisted ) {
				$send_email = true;
				$reasons[]  = 'suspected_billing_name_attempt: ' . $customer_name;

				update_option( 'wc_blacklist_sum_block_name', (int) get_option( 'wc_blacklist_sum_block_name', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
			} else {
				$customer_name = '';
			}
		} else {
			$customer_name = '';
		}

		// Shipping name.
		if ( ! empty( $shipping_full_name ) && $premium_active && get_option( 'wc_blacklist_customer_name_blocking_enabled' ) === '1' ) {
			$sql_shipping_name = $wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE LOWER(CONCAT(first_name, ' ', last_name)) = %s
				AND is_blocked = 0
				LIMIT 1",
				strtolower( $shipping_full_name )
			);

			$shipping_name_blacklisted = ! empty( $wpdb->get_var( $sql_shipping_name ) );

			if ( $shipping_name_blacklisted ) {
				$send_email = true;
				$reasons[]  = 'suspected_shipping_name_attempt: ' . $shipping_full_name;

				update_option( 'wc_blacklist_sum_block_name', (int) get_option( 'wc_blacklist_sum_block_name', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );

				if ( '' === $customer_name ) {
					$customer_name = $shipping_full_name;
				}
			}
		}

		// IP.
		if ( ! empty( $user_ip ) && get_option( 'wc_blacklist_ip_enabled' ) === '1' ) {
			$sql_ip = $wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE ip_address = %s
				AND is_blocked = 0
				LIMIT 1",
				$user_ip
			);

			$ip_blacklisted = ! empty( $wpdb->get_var( $sql_ip ) );

			if ( $ip_blacklisted ) {
				$send_email = true;
				$reasons[]  = 'suspected_ip_attempt: ' . $user_ip;

				update_option( 'wc_blacklist_sum_block_ip', (int) get_option( 'wc_blacklist_sum_block_ip', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
			} else {
				$user_ip = '';
			}
		} else {
			$user_ip = '';
		}

		// Device identity.
		if ( $premium_active && ! empty( $device_id ) && '1' === (string) get_option( 'wc_blacklist_enable_device_identity', '0' ) ) {
			$sql_device = $wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE device_id = %s
				AND is_blocked = 0
				LIMIT 1",
				$device_id
			);

			$device_suspected = ! empty( $wpdb->get_var( $sql_device ) );

			if ( $device_suspected ) {
				$send_email = true;
				$reasons[]  = 'suspected_device_attempt: ' . $device_id;

				update_option( 'wc_blacklist_sum_block_device', (int) get_option( 'wc_blacklist_sum_block_device', 0 ) + 1 );
				update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
			} else {
				$device_id = '';
			}
		} else {
			$device_id = '';
		}

		// Address checks.
		if ( $premium_active && get_option( 'wc_blacklist_enable_customer_address_blocking' ) === '1' ) {
			$region_blocking           = get_option( 'wc_blacklist_region_blocking', array() );
			$shipping_blocking_enabled = get_option( 'wc_blacklist_enable_shipping_address_blocking' ) === '1';
			$address_matching_mode     = get_option( 'wc_blacklist_address_matching_mode', 'standard' );

			$billing_address_matched        = false;
			$shipping_address_matched       = false;
			$billing_address_exact_matched  = false;
			$shipping_address_exact_matched = false;
			$billing_address_core_matched   = false;
			$shipping_address_core_matched  = false;

			if ( ! empty( $billing_address['address_full_norm'] ) ) {
				$billing_address_exact_matched = $this->address_exists_by_hash( $address_table_name, $billing_address['address_hash'] );

				if ( $billing_address_exact_matched ) {
					$billing_address_matched = true;
					$send_email              = true;
					$reasons[]               = 'suspected_billing_address_attempt: ' . $customer_address;

					update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
					update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
				} elseif (
					in_array( $address_matching_mode, array( 'advanced', 'strict' ), true ) &&
					! empty( $billing_address['address_core_hash'] ) &&
					$this->address_exists_by_core_hash( $address_table_name, $billing_address['address_core_hash'] )
				) {
					$billing_address_core_matched = true;
					$billing_address_matched      = true;
					$send_email                   = true;
					$reasons[]                    = 'suspected_billing_address_core_attempt: ' . $customer_address;

					update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
					update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
				}
			}

			if (
				! empty( $shipping_address['address_full_norm'] ) &&
				$shipping_blocking_enabled &&
				$shipping_address['address_hash'] !== $billing_address['address_hash']
			) {
				$shipping_address_exact_matched = $this->address_exists_by_hash( $address_table_name, $shipping_address['address_hash'] );

				if ( $shipping_address_exact_matched ) {
					$shipping_address_matched = true;
					$send_email               = true;
					$reasons[]                = 'suspected_shipping_address_attempt: ' . $shipping_address_display;

					update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
					update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
				} elseif (
					in_array( $address_matching_mode, array( 'advanced', 'strict' ), true ) &&
					! empty( $shipping_address['address_core_hash'] ) &&
					$shipping_address['address_core_hash'] !== $billing_address['address_core_hash'] &&
					$this->address_exists_by_core_hash( $address_table_name, $shipping_address['address_core_hash'] )
				) {
					$shipping_address_core_matched = true;
					$shipping_address_matched      = true;
					$send_email                    = true;
					$reasons[]                     = 'suspected_shipping_address_core_attempt: ' . $shipping_address_display;

					update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
					update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
				}
			}

			if ( is_array( $region_blocking ) && ! empty( $region_blocking ) ) {
				if ( in_array( 'postcode', $region_blocking, true ) ) {
					if ( ! empty( $billing_address['country_code'] ) && ! empty( $billing_address['postcode_norm'] ) ) {
						$billing_postcode_match = $this->address_region_exists( $address_table_name, 'postcode', $billing_address['country_code'], '', $billing_address['postcode_norm'] );

						if ( $billing_postcode_match ) {
							$send_email = true;
							$reasons[]  = 'suspected_billing_postcode_attempt: ' . $customer_address;

							update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
							update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
						}
					}

					if (
						$shipping_blocking_enabled &&
						! empty( $shipping_address['country_code'] ) &&
						! empty( $shipping_address['postcode_norm'] )
					) {
						$shipping_postcode_match = $this->address_region_exists( $address_table_name, 'postcode', $shipping_address['country_code'], '', $shipping_address['postcode_norm'] );

						if ( $shipping_postcode_match ) {
							$send_email = true;
							$reasons[]  = 'suspected_shipping_postcode_attempt: ' . $shipping_address_display;

							update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
							update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
						}
					}
				}

				if ( in_array( 'state', $region_blocking, true ) ) {
					if ( ! empty( $billing_address['country_code'] ) && ! empty( $billing_address['state_code'] ) ) {
						$billing_state_match = $this->address_region_exists( $address_table_name, 'state', $billing_address['country_code'], $billing_address['state_code'], '' );

						if ( $billing_state_match ) {
							$send_email = true;
							$reasons[]  = 'suspected_billing_state_attempt: ' . $customer_address;

							update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
							update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
						}
					}

					if (
						$shipping_blocking_enabled &&
						! empty( $shipping_address['country_code'] ) &&
						! empty( $shipping_address['state_code'] )
					) {
						$shipping_state_match = $this->address_region_exists( $address_table_name, 'state', $shipping_address['country_code'], $shipping_address['state_code'], '' );

						if ( $shipping_state_match ) {
							$send_email = true;
							$reasons[]  = 'suspected_shipping_state_attempt: ' . $shipping_address_display;

							update_option( 'wc_blacklist_sum_block_address', (int) get_option( 'wc_blacklist_sum_block_address', 0 ) + 1 );
							update_option( 'wc_blacklist_sum_block_total', (int) get_option( 'wc_blacklist_sum_block_total', 0 ) + 1 );
						}
					}
				}
			}

			if ( ! $billing_address_matched ) {
				$customer_address = '';
			}

			if ( ! $shipping_address_matched ) {
				$shipping_address_display = '';
			}
		} else {
			$customer_address         = '';
			$shipping_address_display = '';
		}

		if ( ! empty( $reasons ) && $premium_active ) {
			$details   = implode( ', ', $reasons );
			$view_json = wp_json_encode( $view_data );

			$wpdb->insert(
				$table_detection_log,
				array(
					'timestamp' => current_time( 'mysql' ),
					'type'      => 'bot',
					'source'    => 'woo_order_' . $order_id,
					'action'    => 'suspect',
					'details'   => $details,
					'view'      => $view_json,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		if ( $send_email ) {
			$phones_to_email = array_filter( array_unique( array( $billing_phone, $shipping_phone ) ) );
			$phone_for_email = implode( ', ', $phones_to_email );

			$email_sender = new WC_Blacklist_Manager_Email();
			$email_sender->send_email_order_suspect(
				$order_id,
				$customer_name,
				$phone_for_email,
				$email,
				$user_ip,
				$customer_address,
				$shipping_address_display,
				$order_edit_url,
				$device_id
			);
		}
	}

	private function address_exists_by_hash( $address_table_name, $address_hash ) {
		global $wpdb;

		if ( empty( $address_hash ) ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"SELECT 1
			FROM {$address_table_name}
			WHERE is_blocked = 0
			AND match_type = %s
			AND address_hash = %s
			LIMIT 1",
			'address',
			$address_hash
		);

		$matched = ! empty( $wpdb->get_var( $sql ) );

		return $matched;
	}

	private function address_exists_by_core_hash( $address_table_name, $address_core_hash ) {
		global $wpdb;

		if ( empty( $address_core_hash ) ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"SELECT 1
			FROM {$address_table_name}
			WHERE is_blocked = 0
			AND match_type IN ('address', 'address_core')
			AND address_core_hash = %s
			LIMIT 1",
			$address_core_hash
		);

		$matched = ! empty( $wpdb->get_var( $sql ) );

		return $matched;
	}

	private function address_region_exists( $address_table_name, $match_type, $country_code, $state_code = '', $postcode_norm = '' ) {
		global $wpdb;

		if ( 'postcode' === $match_type ) {
			if ( '' === $country_code || '' === $postcode_norm ) {
				return false;
			}

			$sql = $wpdb->prepare(
				"SELECT 1
				FROM {$address_table_name}
				WHERE is_blocked = 0
				AND match_type = %s
				AND country_code = %s
				AND postcode_norm = %s
				LIMIT 1",
				'postcode',
				$country_code,
				$postcode_norm
			);

			$matched = ! empty( $wpdb->get_var( $sql ) );

			return $matched;
		}

		if ( 'state' === $match_type ) {
			if ( '' === $country_code || '' === $state_code ) {
				return false;
			}

			$sql = $wpdb->prepare(
				"SELECT 1
				FROM {$address_table_name}
				WHERE is_blocked = 0
				AND match_type = %s
				AND country_code = %s
				AND state_code = %s
				LIMIT 1",
				'state',
				$country_code,
				$state_code
			);

			$matched = ! empty( $wpdb->get_var( $sql ) );

			return $matched;
		}

		return false;
	}

	private function get_order_device_id( WC_Order $order ): string {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		if ( ! $premium_active ) {
			return '';
		}

		if ( '1' !== (string) get_option( 'wc_blacklist_enable_device_identity', '0' ) ) {
			return '';
		}

		$device_id = (string) $order->get_meta( '_wc_bm_device_id', true );

		if ( empty( $device_id ) ) {
			return '';
		}

		$device_id = sanitize_text_field( $device_id );

		if ( ! preg_match( '/^[a-f0-9]{32,64}$/', $device_id ) ) {
			return '';
		}

		return $device_id;
	}
}

new WC_Blacklist_Manager_Suspicious_Actions();
