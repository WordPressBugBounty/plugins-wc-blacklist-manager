<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_New_Order_Email_Coordinator {

	const META_ADMIN_NEW_ORDER_SENT = '_yobm_admin_new_order_email_sent';

	public function __construct() {
		$license_active        = WC_Blacklist_Manager_Validator::is_premium_active();
		$premium_plugin_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		$premium_active        = ( $premium_plugin_active && $license_active );

		$local_risk_enabled = ( get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1' );
		$global_gbl_enabled = ( get_option( 'wc_blacklist_enable_global_blacklist', '0' ) === '1' );

		// Enable if either local async risk jobs or Global Blacklist async checks are on.
		if ( ! ( $local_risk_enabled && $premium_active ) && ! $global_gbl_enabled ) {
			return;
		}

		add_action( 'init', [ $this, 'yobm_delay_new_order_email_init' ], 20 );
		add_action( 'yobm_after_job', [ $this, 'maybe_trigger_admin_email' ], 10, 2 );

		// Also listen for the Global Blacklist async completion path.
		add_action( 'yogb_after_gbl_check', [ $this, 'maybe_trigger_admin_email' ], 10, 2 );

		// Retry when the order later becomes eligible to send the admin New Order email.
		add_action( 'woocommerce_order_status_pending_to_processing', [ $this, 'retry_admin_email_on_status_change' ], 10, 1 );
		add_action( 'woocommerce_order_status_pending_to_on-hold', [ $this, 'retry_admin_email_on_status_change' ], 10, 1 );
		add_action( 'woocommerce_payment_complete', [ $this, 'retry_admin_email_on_payment_complete' ], 10, 1 );
	}

	public function yobm_delay_new_order_email_init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( ! empty( $emails['WC_Email_New_Order'] ) ) {
			remove_action(
				'woocommerce_order_status_pending_to_processing_notification',
				[ $emails['WC_Email_New_Order'], 'trigger' ]
			);
			remove_action(
				'woocommerce_order_status_pending_to_on-hold_notification',
				[ $emails['WC_Email_New_Order'], 'trigger' ]
			);
			remove_action(
				'woocommerce_thankyou',
				[ $emails['WC_Email_New_Order'], 'trigger' ]
			);
		}
	}

	/**
	 * Retry sending the admin New Order email when the order status changes
	 * to an eligible state.
	 *
	 * @param int $order_id Order ID.
	 */
	public function retry_admin_email_on_status_change( $order_id ) {
		$this->maybe_trigger_admin_email( $order_id, 'status_retry' );
	}

	/**
	 * Retry sending the admin New Order email when payment is completed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function retry_admin_email_on_payment_complete( $order_id ) {
		$this->maybe_trigger_admin_email( $order_id, 'payment_complete_retry' );
	}

	/**
	 * Only fire the New Order email if no pending Action Scheduler actions remain
	 * for this order across enabled checks, and only when the order has reached
	 * an allowed status.
	 *
	 * @param int    $order_id      Order ID.
	 * @param string $finished_hook Hook name that just finished.
	 */
	public function maybe_trigger_admin_email( $order_id, $finished_hook = '' ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Prevent duplicate sends.
		if ( (int) $order->get_meta( self::META_ADMIN_NEW_ORDER_SENT, true ) === 1 ) {
			return;
		}

		$job_hooks = [];

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		/*
		 * Local premium risk-score async jobs.
		 */
		if ( get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1' && $premium_active ) {
			$job_hooks[] = 'wc_blacklist_first_time_order_job';

			if ( get_option( 'wc_blacklist_block_billing_shipping', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_order_billing_shipping_job';
			}
			if ( get_option( 'wc_blacklist_block_order_attempts', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_order_attempts_job';
			}
			if ( get_option( 'wc_blacklist_block_phone_email_address', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_order_phone_email_vs_address_job';
			}
			if ( get_option( 'wc_blacklist_block_phone_email_ip', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_order_phone_email_vs_ip_job';
			}
			if ( get_option( 'wc_blacklist_block_order_value', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_order_value_job';
			}
			if ( get_option( 'wc_blacklist_block_ip_address', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_ip_coordinates_job';
			}
			if ( get_option( 'wc_blacklist_block_ip_country', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_ip_country_job';
			}
			if ( get_option( 'wc_blacklist_block_hosting_ip', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_ip_hosting_job';
			}
			if ( get_option( 'wc_blacklist_block_proxy_vpn', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_ip_proxy_vpn_job';
			}
			if ( get_option( 'wc_blacklist_payment_suspected_country', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_gateway_high_risk_country_job';
			}
			if ( get_option( 'wc_blacklist_payment_card_billing_country', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_gateway_card_billing_job';
			}
			if ( get_option( 'wc_blacklist_payment_avs_check', '0' ) === '1' ) {
				$job_hooks[] = 'wc_blacklist_gateway_avs_job';
			}
		}

		/*
		 * Global Blacklist async job.
		 */
		if ( get_option( 'wc_blacklist_enable_global_blacklist', '0' ) === '1' ) {
			$job_hooks[] = 'yogb_gbl_run_check_async';
		}

		foreach ( $job_hooks as $hook ) {
			// Skip the async hook that just finished.
			if ( '' !== $finished_hook && $hook === $finished_hook ) {
				continue;
			}

			$args = $this->get_job_args_for_order( $hook, $order_id );

			if ( as_next_scheduled_action( $hook, $args ) ) {
				return;
			}
		}

		/*
		 * Only send after the order reaches an allowed status.
		 * Prevent pending/unpaid orders from sending the admin New Order email.
		 */
		$allowed_statuses = [ 'processing', 'on-hold', 'completed' ];
		$current_status   = $order->get_status();

		if ( ! in_array( $current_status, $allowed_statuses, true ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( ! empty( $emails['WC_Email_New_Order'] ) ) {
			/** @var WC_Email_New_Order $new_order */
			$new_order = $emails['WC_Email_New_Order'];
			$new_order->trigger( $order_id );

			$order->update_meta_data( self::META_ADMIN_NEW_ORDER_SENT, 1 );
			$order->save();
		}
	}

	/**
	 * Build the exact args array used when the async action was scheduled.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $order_id Order ID.
	 * @return array
	 */
	private function get_job_args_for_order( $hook, $order_id ) {
		// Global Blacklist uses named args.
		if ( 'yogb_gbl_run_check_async' === $hook ) {
			return [ 'order_id' => (int) $order_id ];
		}

		$args = [ (int) $order_id ];

		if ( 'wc_blacklist_order_billing_shipping_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_billing_shipping', 'email' );
		} elseif ( 'wc_blacklist_order_attempts_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_order_attempts', 'email' );
		} elseif ( 'wc_blacklist_order_phone_email_vs_address_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_phone_email_address', 'email' );
		} elseif ( 'wc_blacklist_order_phone_email_vs_ip_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_phone_email_ip', 'email' );
		} elseif ( 'wc_blacklist_order_value_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_order_value', 'email' );
		} elseif ( 'wc_blacklist_ip_coordinates_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_ip_address', 'email' );
		} elseif ( 'wc_blacklist_ip_country_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_ip_country', 'email' );
		} elseif ( 'wc_blacklist_ip_hosting_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_hosting_ip', 'email' );
		} elseif ( 'wc_blacklist_ip_proxy_vpn_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_proxy_vpn', 'email' );
		} elseif ( 'wc_blacklist_gateway_high_risk_country_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_payment_suspected_country', 'email' );
		} elseif ( 'wc_blacklist_gateway_card_billing_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_payment_card_billing_country', 'email' );
		} elseif ( 'wc_blacklist_gateway_avs_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_payment_avs_check', 'email' );
		}

		return $args;
	}
}

new WC_Blacklist_Manager_New_Order_Email_Coordinator();