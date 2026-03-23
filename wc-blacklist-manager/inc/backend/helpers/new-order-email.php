<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_New_Order_Email_Coordinator {

	const META_ADMIN_NEW_ORDER_SENT = '_yobm_admin_new_order_email_sent';

	public function __construct() {
		$license_active        = WC_Blacklist_Manager_Validator::is_premium_active();
		$premium_plugin_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		$premium_active = ( $premium_plugin_active && $license_active );

		$local_risk_enabled  = ( get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1' );
		$global_gbl_enabled  = ( get_option( 'wc_blacklist_enable_global_blacklist', '0' ) === '1' );

		// Enable if either local async risk jobs or Global Blacklist async checks are on.
		if ( ! ($local_risk_enabled && $premium_active) && ! $global_gbl_enabled ) {
			return;
		}

		add_action( 'init', [ $this, 'yobm_delay_new_order_email_init' ], 20 );
		add_action( 'yobm_after_job', [ $this, 'maybe_trigger_admin_email' ], 10, 2 );

		// Also listen for the Global Blacklist async completion path.
		add_action( 'yogb_after_gbl_check', [ $this, 'maybe_trigger_admin_email' ], 10, 2 );
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
	 * Only fire the New Order email if no pending AS actions remain
	 * for this order across enabled checks.
	 *
	 * @param int    $order_id
	 * @param string $finished_hook
	 */
	public function maybe_trigger_admin_email( $order_id, $finished_hook ) {
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
		$premium_active = $settings_instance->is_premium_active();
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
			// Skip the job that just finished.
			if ( $hook === $finished_hook ) {
				continue;
			}

			$args = $this->get_job_args_for_order( $hook, $order_id );

			if ( as_next_scheduled_action( $hook, $args ) ) {
				return;
			}
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
	 * @param string $hook
	 * @param int    $order_id
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

// instantiate it early in your plugin bootstrap
new WC_Blacklist_Manager_New_Order_Email_Coordinator();
