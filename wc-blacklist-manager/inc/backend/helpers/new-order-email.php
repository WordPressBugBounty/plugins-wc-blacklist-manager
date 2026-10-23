<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_New_Order_Email_Coordinator {

	const META_ADMIN_NEW_ORDER_SENT = '_yobm_admin_new_order_email_sent';
	private $coordinates_new_orders = false;
	private $sending_order = 0;

	public function __construct() {
		add_action( 'yobm_new_order_email_recover', [ $this, 'recover_admin_email' ], 10, 1 );
		add_action( 'init', [ $this, 'register_checks' ], 15 );
		add_filter( 'woocommerce_email_enabled_new_order', [ $this, 'native_delivery_enabled' ], 20, 3 );
		add_action( 'woocommerce_email_before_order_table', [ $this, 'render_unavailable' ], 9, 4 );
		add_filter( 'wc_blacklist_manager_global_email_render_standalone', [ $this, 'global_summary_available' ], 20, 4 );
	}

	public function register_checks() {
		$premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();

		$local_risk_enabled = get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1' || ( $premium_active && (bool) $this->local_blocking_checks() );
		$global_gbl_enabled = ( class_exists( 'YOGB_BM_Check_Orders' ) && YOGB_BM_Check_Orders::is_enabled() );

		$this->coordinates_new_orders = ( $local_risk_enabled && $premium_active ) || $global_gbl_enabled;
		if ( $this->coordinates_new_orders ) {
			add_action( 'init', [ $this, 'yobm_delay_new_order_email_init' ], 20 );
			add_action( 'woocommerce_checkout_order_processed', [ $this, 'admit_checkout_order' ], 1, 1 );
			add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'admit_checkout_order' ], 1, 1 );
		}
		// Existing admitted work remains governed when switches or entitlement change.
		add_action( 'yobm_after_job', [ $this, 'maybe_trigger_admin_email' ], 100, 2 );
		add_action( 'action_scheduler_after_execute', [ $this, 'after_scheduled_check' ], 100, 1 );

		// Also listen for the Global Blacklist async completion path.
		add_action( 'yogb_after_gbl_check', [ $this, 'maybe_trigger_admin_email' ], 10, 2 );

		// Retry when the order later becomes eligible to send the admin New Order email.
		foreach ( [ 'pending', 'failed', 'cancelled' ] as $from ) {
			foreach ( [ 'processing', 'on-hold', 'completed' ] as $to ) {
				add_action( 'woocommerce_order_status_' . $from . '_to_' . $to, [ $this, 'retry_admin_email_on_status_change' ], 10, 1 );
			}
		}
		add_action( 'woocommerce_payment_complete', [ $this, 'retry_admin_email_on_payment_complete' ], 10, 1 );
	}

	public function yobm_delay_new_order_email_init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( ! empty( $emails['WC_Email_New_Order'] ) ) {
			foreach ( [ 'pending', 'failed', 'cancelled' ] as $from ) {
				foreach ( [ 'processing', 'on-hold', 'completed' ] as $to ) {
					remove_action( 'woocommerce_order_status_' . $from . '_to_' . $to . '_notification', [ $emails['WC_Email_New_Order'], 'trigger' ] );
				}
			}
			remove_action( 'woocommerce_thankyou', [ $emails['WC_Email_New_Order'], 'trigger' ] );
		}
	}

	/**
	 * Retry sending the admin New Order email when the order status changes
	 * to an eligible state.
	 *
	 * @param int $order_id Order ID.
	 */
	public function retry_admin_email_on_status_change( $order_id ) {
		if ( $this->should_coordinate_native_event( $order_id ) ) { $this->maybe_trigger_admin_email( $order_id, 'status_retry' ); }
	}

	/**
	 * Retry sending the admin New Order email when payment is completed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function retry_admin_email_on_payment_complete( $order_id ) {
		if ( $this->should_coordinate_native_event( $order_id ) ) { $this->maybe_trigger_admin_email( $order_id, 'payment_complete_retry' ); }
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

		// WCS owns separate renewal/switch notification IDs and routing.
		if ( ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) )
			|| ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'switch' ) ) ) { return; }

		// Prevent duplicate sends.
		if ( (int) $order->get_meta( self::META_ADMIN_NEW_ORDER_SENT, true ) === 1 || $order->get_new_order_email_sent() ) {
			return;
		}

		// Configured checkout jobs do not apply to newly created admin/REST orders.
		$native_event = in_array( $finished_hook, [ 'status_retry', 'payment_complete_retry' ], true );
		if ( ! $this->ensure_coordination_admission( $order, $native_event ) ) {
			if ( $native_event && ! $this->has_delivery_hold( $order ) && $order->has_status( [ 'processing', 'on-hold', 'completed' ] ) ) {
				$emails = WC()->mailer()->get_emails();
				if ( ! empty( $emails['WC_Email_New_Order'] ) ) { $emails['WC_Email_New_Order']->trigger( $order_id, $order ); }
			}
			return;
		}

		if ( $native_event ) { $this->schedule_recovery( $order, 6 * MINUTE_IN_SECONDS ); }
		if ( ! $this->risk_allows_email( $order, 'recovery' === $finished_hook ) ) {
			if ( 'recovery' === $finished_hook ) { $this->schedule_recovery( $order, 6 * MINUTE_IN_SECONDS ); }
			return;
		}

		$job_hooks = [];

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		/*
		 * Local premium risk-score async jobs.
		 */
		if ( get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1' && $premium_active
			&& empty( $order->get_meta( '_yobm_admin_new_order_email_coordinated', true )['score_protocol'] ) ) {
			$job_hooks[] = 'wc_blacklist_first_time_order_job';
			foreach ( [
				'block_device_email_phone' => 'wc_blacklist_device_vs_email_phone_job',
				'block_device_address' => 'wc_blacklist_order_device_vs_address_job',
				'block_device_identity_spread' => 'wc_blacklist_device_identity_spread_job',
				'payment_paypal_payer_email' => 'wc_blacklist_order_paypal_payer_vs_customer_job',
			] as $setting => $hook ) {
				if ( '1' === get_option( 'wc_blacklist_' . $setting, '0' ) ) { $job_hooks[] = $hook; }
			}

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
				$job_hooks[] = 'wc_blacklist_order_ip_proxy_vpn_job';
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

		foreach ( $job_hooks as $hook ) {
			// Skip the async hook that just finished.
			if ( '' !== $finished_hook && $hook === $finished_hook ) {
				continue;
			}

			$args = $this->get_job_args_for_order( $hook, $order_id );

			if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( $hook, $args ) ) {
				$this->diagnose( $order, 'evaluation', 'legacy_jobs_pending' );
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
			$this->diagnose( $order, 'evaluation', 'order_ineligible' );
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( empty( $emails['WC_Email_New_Order'] ) ) { return; }
		$emails['WC_Email_New_Order']->object = $order;
		if ( ! $this->email_enabled( $emails['WC_Email_New_Order'], $order ) || ! $emails['WC_Email_New_Order']->get_recipient() ) {
			$this->diagnose( $order, 'dispatch', 'disabled_or_no_recipient', 'noop' );
			return;
		}
		// Native sent metadata is not atomic: two triggers can both read false.
		// Use a non-waiting connection-owned order lock, including custom mysqli adapters.
		global $wpdb;
		$connection = $wpdb->dbh;
		if ( ! $connection instanceof mysqli ) { return; }
		$lock = 'yobm_new_mail_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $order_id ), 0, 40 );
		$owned = false;
		$thread = $connection->thread_id;
		try {
			// A mail side effect must not be admitted inside a transaction that can roll back its attempt marker.
			$result = $connection->query( 'SELECT @@SESSION.autocommit' );
			$row = $result ? $result->fetch_row() : [];
			if ( $result ) { $result->free(); }
			if ( '1' !== (string) ( $row[0] ?? '' ) ) { return; }
			$result = $connection->query( "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation','tx_isolation')" );
			$row = $result ? $result->fetch_row() : [];
			if ( $result ) { $result->free(); }
			$isolation = str_replace( '-', ' ', (string) ( $row[1] ?? '' ) );
			if ( ! in_array( $isolation, [ 'READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE' ], true )
				|| ! $connection->query( 'SET TRANSACTION ISOLATION LEVEL ' . $isolation ) ) { return; }
			$result = $connection->query( "SELECT GET_LOCK('" . $lock . "',0)" );
			$row = $result ? $result->fetch_row() : [];
			if ( $result ) { $result->free(); }
			if ( '1' !== (string) ( $row[0] ?? '' ) ) { return; }
			$owned = true;
			$order = wc_get_order( $order_id );
			$order->read_meta_data( true );
			if ( $wpdb->dbh !== $connection || $connection->thread_id !== $thread || ! $this->risk_allows_email( $order, 'recovery' === $finished_hook )
				|| ! $order->has_status( [ 'processing', 'on-hold', 'completed' ] )
				|| $order->get_new_order_email_sent() || $order->get_meta( self::META_ADMIN_NEW_ORDER_SENT )
				|| 'ambiguous' === $order->get_meta( '_yobm_admin_new_order_email_attempt' )
				|| ( 'failed' === $order->get_meta( '_yobm_admin_new_order_email_attempt' ) && 'recovery' !== $finished_hook )
				|| (int) $order->get_meta( '_yobm_admin_new_order_email_attempts' ) >= 2 ) { return; }
			// Persist uncertainty before invoking an external transport. A crash must not replay it.
			$order->update_meta_data( '_yobm_admin_new_order_email_attempt', 'ambiguous' );
			$order->update_meta_data( '_yobm_admin_new_order_email_attempts', 1 + (int) $order->get_meta( '_yobm_admin_new_order_email_attempts' ) );
			$order->save();
			$persisted = wc_get_order( $order_id );
			$persisted->read_meta_data( true );
			if ( $wpdb->dbh !== $connection || $connection->thread_id !== $thread || 'ambiguous' !== $persisted->get_meta( '_yobm_admin_new_order_email_attempt' ) ) { return; }
			$result = $connection->query( "SELECT IS_USED_LOCK('" . $lock . "')" );
			$row = $result ? $result->fetch_row() : [];
			if ( $result ) { $result->free(); }
			if ( (string) ( $row[0] ?? '' ) !== (string) $thread ) { return; }
			$outcome = null;
			$observe = static function( $sent, $email_id, $email ) use ( &$outcome, $order_id ) {
				if ( 'new_order' === $email_id && $email->object instanceof WC_Order && (int) $email->object->get_id() === $order_id ) {
					$outcome = (bool) $sent;
				}
			};
			add_action( 'woocommerce_email_sent', $observe, PHP_INT_MAX, 3 );
			try {
				// Passing the freshly read order also avoids a stale Woo sent-marker cache.
				$this->sending_order = $order_id;
				$emails['WC_Email_New_Order']->trigger( $order_id, $order );
			} finally {
				$this->sending_order = 0;
				remove_action( 'woocommerce_email_sent', $observe, PHP_INT_MAX );
			}
			if ( true === $outcome ) {
				$order->update_meta_data( self::META_ADMIN_NEW_ORDER_SENT, 1 );
				$order->update_meta_data( '_yobm_admin_new_order_email_attempt', 'accepted' );
				$this->diagnose( $order, 'dispatch', 'transport_accepted', 'accepted' );
				wp_clear_scheduled_hook( 'yobm_new_order_email_recover', [ $order_id ] );
			} elseif ( false === $outcome ) {
				// Woo 7.1 marks native sent even when its transport returns false.
				$order->set_new_order_email_sent( false );
				$order->update_meta_data( '_yobm_admin_new_order_email_attempt', 'failed' );
				$order->save();
				$this->diagnose( $order, 'dispatch', 'transport_false', 'failed' );
				$this->schedule_recovery( $order, MINUTE_IN_SECONDS, true );
			} elseif ( ! $this->email_enabled( $emails['WC_Email_New_Order'], $order ) || ! $emails['WC_Email_New_Order']->get_recipient() ) {
				$order->delete_meta_data( '_yobm_admin_new_order_email_attempt' );
				$order->update_meta_data( '_yobm_admin_new_order_email_attempts', max( 0, (int) $order->get_meta( '_yobm_admin_new_order_email_attempts' ) - 1 ) );
			}
			$order->save();
			if ( null === $outcome && $order->get_meta( '_yobm_admin_new_order_email_attempt' ) === 'ambiguous' ) { $this->diagnose( $order, 'dispatch', 'transport_uncertain', 'uncertain' ); }
		} catch ( Throwable $error ) {
			$this->sending_order = 0;
			$this->diagnose( $order, 'native_trigger', 'native_trigger_exception', 'uncertain' );
			// Extension hooks may have effects before the transport observer.
		} finally {
			if ( $owned ) {
				try { $connection->query( "SELECT RELEASE_LOCK('" . $lock . "')" ); } catch ( Throwable $ignored ) {}
			}
		}
	}


	private function held( WC_Order $order, $reason ) : bool {
		$this->diagnose( $order, 'evaluation', $reason );
		return false;
	}

	private function diagnose( WC_Order $order, $phase, $reason, $outcome = 'held' ) {
		$data = [ 'phase' => $phase, 'reason' => $reason, 'outcome' => $outcome,
			'count' => (int) $order->get_meta( '_yobm_admin_new_order_email_attempts' ), 'time' => time() ];
		$previous = $order->get_meta( '_yobm_admin_new_order_email_diagnostic', true );
		if ( is_array( $previous ) && array_diff_assoc( array_diff_key( $data, [ 'time' => true ] ), $previous ) === [] ) { return; }
		$order->update_meta_data( '_yobm_admin_new_order_email_diagnostic', $data );
		$order->save();
		if ( function_exists( 'yoohw_diagnostic_log' ) ) { yoohw_diagnostic_log( 'order_email', 'coordination', $data ); }
	}

	private function schedule_recovery( WC_Order $order, $delay, $delivery_retry = false ) {
		global $wpdb;
		$db = $wpdb->dbh;
		if ( ! $db instanceof mysqli ) { return; }
		$name = 'yobm_mail_schedule_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $order->get_id() ), 0, 38 );
		$owned = false;
		try {
			$result = $db->query( "SELECT GET_LOCK('" . $name . "',0)" );
			$row = $result ? $result->fetch_row() : [];
			if ( $result ) { $result->free(); }
			if ( '1' !== (string) ( $row[0] ?? '' ) ) { return; }
			$owned = true;
			$order->read_meta_data( true );
			$args = [ (int) $order->get_id() ];
			$existing = wp_next_scheduled( 'yobm_new_order_email_recover', $args );
			if ( $existing && $existing > time() ) {
				$order->update_meta_data( '_yobm_admin_new_order_email_recover_at', (int) $existing );
				$order->save();
				return;
			}
			$work = $order->get_meta( '_yobm_admin_new_order_email_coordinated', true );
			if ( ! is_array( $work ) ) { return; }
			$key = $delivery_retry ? 'retry_schedules' : 'recovery_schedules';
			$limit = $delivery_retry ? 1 : 2;
			if ( (int) ( $work[ $key ] ?? 0 ) >= $limit ) { return; }
			$work[ $key ] = 1 + (int) ( $work[ $key ] ?? 0 );
			$order->update_meta_data( '_yobm_admin_new_order_email_coordinated', $work );
			$order->delete_meta_data( '_yobm_admin_new_order_email_recover_at' );
			$order->save();
			$at = time() + $delay;
			$scheduled = wp_schedule_single_event( $at, 'yobm_new_order_email_recover', $args );
			$actual = wp_next_scheduled( 'yobm_new_order_email_recover', $args );
			if ( ! $scheduled || ! $actual || $actual <= time() ) {
				$this->diagnose( $order, 'scheduling', 'recovery_schedule_failed', 'failed' );
				return;
			}
			$order->update_meta_data( '_yobm_admin_new_order_email_recover_at', (int) $actual );
			$order->save();
		} catch ( Throwable $error ) {
			$this->diagnose( $order, 'scheduling', 'recovery_schedule_failed', 'failed' );
		} finally {
			if ( $owned ) { try { $db->query( "SELECT RELEASE_LOCK('" . $name . "')" ); } catch ( Throwable $ignored ) {} }
		}
	}

	private function should_coordinate_native_event( $order_id ) : bool {
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order && ( $this->coordinates_new_orders || (bool) $order->get_meta( '_yobm_admin_new_order_email_coordinated', true ) );
	}

	public function native_delivery_enabled( $enabled, $order, $email ) {
		if ( ! $order instanceof WC_Order || $this->sending_order === (int) $order->get_id()
			|| apply_filters( 'woocommerce_new_order_email_allows_resend', false ) ) { return $enabled; }
		return $order->get_meta( '_yobm_admin_new_order_email_coordinated', true ) ? false : $enabled;
	}

	private function email_enabled( $email, WC_Order $order ) : bool {
		$this->sending_order = (int) $order->get_id();
		try { return $email->is_enabled(); } finally { $this->sending_order = 0; }
	}

	public function global_summary_available( $render, $order, $plain_text, $email ) {
		return $render && ( ! $order instanceof WC_Order || in_array( $order->get_meta( '_yogb_gbl_check_status', true ), [ 'success', 'skipped_rate_limit', 'failed' ], true ) );
	}

	public function render_unavailable( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $sent_to_admin || ! $order instanceof WC_Order || ! is_object( $email ) || 'new_order' !== ( $email->id ?? '' ) ) { return; }
		$work = $order->get_meta( '_yobm_admin_new_order_email_coordinated', true );
		if ( ! is_array( $work ) ) { return; }
		$warnings = [];
		if ( ! empty( $work['local_score'] ) && '1' === get_option( 'wc_blacklist_manager_enable_order_risk', '0' )
			&& ( ! function_exists( 'wc_blacklist_manager_is_premium_available' ) || ! wc_blacklist_manager_is_premium_available() ) ) {
			$warnings[] = __( 'Store risk score unavailable. Review this order before relying on a risk assessment.', 'wc-blacklist-manager' );
		}
		if ( ! empty( $work['global'] ) && '1' === get_option( 'wc_blacklist_enable_global_blacklist', '0' )
			&& ! in_array( $order->get_meta( '_yogb_gbl_check_status', true ), [ 'success', 'skipped_rate_limit', 'failed' ], true ) ) {
			$warnings[] = __( 'Global Blacklist result unavailable. This is not an Allow verdict.', 'wc-blacklist-manager' );
		}
		foreach ( $warnings as $warning ) {
			echo $plain_text ? esc_html( $warning ) . "\n\n" : '<p><strong>' . esc_html( $warning ) . '</strong></p>';
		}
	}

	public function recover_admin_email( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) { return; }
		$deadline = (int) $order->get_meta( '_yobm_admin_new_order_email_recover_at', true );
		if ( ! $deadline || $deadline > time() ) { return; }
		$this->maybe_trigger_admin_email( $order_id, 'recovery' );
	}

	public function after_scheduled_check( $action_id ) {
		$action = ActionScheduler::store()->fetch_action( $action_id );
		if ( 'wc_blacklist_manager_check_risk_score_job' === $action->get_hook() ) {
			$args = $action->get_args();
			$order_id = (int) reset( $args );
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && $order->get_meta( '_yobm_admin_new_order_email_recover_at', true ) ) {
				$this->maybe_trigger_admin_email( $order_id, $action->get_hook() );
			}
		}
	}

	public function admit_checkout_order( $order_or_id ) {
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
		if ( $order instanceof WC_Order && ! $order->get_meta( '_yobm_admin_new_order_email_coordinated', true )
			&& ! $order->get_meta( '_yobm_admin_new_order_email_recover_at', true )
			&& ! $order->get_meta( self::META_ADMIN_NEW_ORDER_SENT, true )
			&& ! $order->get_new_order_email_sent()
			&& ! $order->get_meta( '_yobm_admin_new_order_email_attempt', true ) ) {
			$work = $this->configured_work( $order );
			$work['global_policy_version'] = 2;
			$order->update_meta_data( '_yobm_admin_new_order_email_coordinated', $work );
			$order->save();
		}
	}

	private function configured_work( $order = null ) : array {
		$premium = function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available();
		$work = [
			'global' => class_exists( 'YOGB_BM_Check_Orders' ) && YOGB_BM_Check_Orders::is_enabled(),
			'local' => $premium && ( get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1' || (bool) $this->local_blocking_checks() ),
			'local_checks' => $premium ? array_column( $this->local_blocking_checks(), 0 ) : [],
			'local_score' => $premium && get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1',
		];
		$mode = strtolower( trim( (string) get_option( 'wc_blacklist_global_blacklist_decision_mode', 'light' ) ) );
		$work['global_mode'] = in_array( $mode, [ 'light', 'moderate', 'strict' ], true ) ? $mode : 'light';
		$work['score_enforcing'] = $work['local_score'] && '0' === get_option( 'wc_blacklist_manager_disable_risk_score_auto_action', '0' );
		return $order instanceof WC_Order ? apply_filters( 'wc_blacklist_manager_new_order_email_requirements', $work, $order ) : $work;
	}

	private function ensure_coordination_admission( WC_Order $order, bool $native_event = false ) : bool {
		if ( $order->get_meta( '_yobm_admin_new_order_email_coordinated', true ) || $order->get_meta( '_yobm_admin_new_order_email_recover_at', true ) ) { return true; }
		// Historical check callbacks never acquire notification ownership.
		if ( ! $native_event ) { return false; }
		$configured = $this->configured_work( $order );
		$work = array_merge( $configured, [ 'global' => false, 'local' => false, 'local_checks' => [], 'local_score' => false, 'score_enforcing' => false ] );
		if ( in_array( $order->get_created_via(), [ 'checkout', 'store-api' ], true ) ) {
			$work = $configured;
		} elseif ( function_exists( 'as_next_scheduled_action' ) ) {
			try {
				$work['global'] = $configured['global'] && (bool) as_next_scheduled_action( 'yogb_gbl_run_check_async', [ 'order_id' => (int) $order->get_id() ], 'yogb-global-blacklist' );
				if ( $configured['local'] ) {
					foreach ( $this->local_blocking_checks() as $check ) {
						$class = $check[2];
						if ( class_exists( $class ) && defined( $class . '::ACTION_HOOK' )
							&& as_next_scheduled_action( $class::ACTION_HOOK, [ (int) $order->get_id(), 'blocked' ] ) ) {
						$work['local'] = true;
						$work['local_checks'][] = $check[0];
					}
					}
					if ( get_option( 'wc_blacklist_manager_enable_order_risk', '0' ) === '1' ) {
						$work['local_score'] = (bool) ( as_next_scheduled_action( 'wc_blacklist_first_time_order_job', [ (int) $order->get_id() ] )
							|| as_next_scheduled_action( 'wc_blacklist_manager_check_risk_score_job', [ (int) $order->get_id() ] ) );
						$work['local'] = $work['local'] || $work['local_score'];
						$work['score_enforcing'] = $work['local_score'] && ! empty( $configured['score_enforcing'] );
					}
				}
			} catch ( Throwable $error ) { $work = $configured; }
		}
		if ( ! $work['global'] && ! $work['local'] ) { return false; }
		$order->update_meta_data( '_yobm_admin_new_order_email_coordinated', $work );
		$order->save();
		return true;
	}

	private function has_delivery_hold( WC_Order $order ) : bool {
		foreach ( [ '_yogb_gbl_blocked', '_yogb_gbl_challenged', '_blacklist_blocked_ids_main', '_blacklist_blocked_ids_address', '_blacklist_blocked_id' ] as $key ) {
			if ( $order->get_meta( $key, true ) ) { return true; }
		}
		return 'blocked_added' === $order->get_meta( '_yobm_risk_score_auto_result', true );
	}

	private function risk_allows_email( WC_Order $order, bool $recovery ) : bool {
		$work = $order->get_meta( '_yobm_admin_new_order_email_coordinated', true );
		$historical = ! empty( $work );
		$admitted = is_array( $work );
		$work = $admitted ? $work : [ 'global' => true, 'local' => true, 'local_score' => true ];
		if ( $this->has_delivery_hold( $order ) ) { return $this->held( $order, 'fraud_hold' ); }
		$global_now = class_exists( 'YOGB_BM_Check_Orders' ) && YOGB_BM_Check_Orders::is_enabled();
		$mode = $work['global_mode'] ?? ( $historical || $admitted ? 'strict' : strtolower( trim( (string) get_option( 'wc_blacklist_global_blacklist_decision_mode', 'light' ) ) ) );
		if ( ! in_array( $mode, [ 'light', 'moderate', 'strict' ], true ) ) { $mode = 'strict'; }
		if ( ! empty( $work['global'] ) && ( $global_now || 'light' !== $mode ) ) {
			$state = (string) $order->get_meta( '_yogb_gbl_check_status', true );
			$decision = (string) $order->get_meta( '_yogb_gbl_decision', true );
			$moderate_v2 = false;
			if ( 'success' === $state && 'light' !== $mode && 2 === ( $work['global_policy_version'] ?? null ) ) {
				$policy = YOGB_BM_Check_Orders::get_applied_policy( $order );
				if ( $mode !== ( $policy['mode'] ?? '' ) ) { return $this->held( $order, 'global_policy_unresolved' ); }
				$moderate_v2 = 'moderate' === $mode;
			}
			if ( 'light' !== $mode && ! $moderate_v2 && in_array( $decision, [ 'block', 'challenge' ], true ) ) { return $this->held( $order, 'global_denied' ); }
			if ( ! in_array( $state, [ 'success', 'skipped_rate_limit', 'failed' ], true )
				&& ( 'light' !== $mode || ( 'unavailable' !== $state && ! $recovery ) ) ) { return $this->held( $order, 'global_pending' ); }
		}
		if ( empty( $work['local'] ) ) { return true; }
		$premium = function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available();
		$score_now = '1' === get_option( 'wc_blacklist_manager_enable_order_risk', '0' );
		$score_applies = ! array_key_exists( 'local_score', $work ) || ! empty( $work['local_score'] );
		$enforcing = array_key_exists( 'score_enforcing', $work ) ? $work['score_enforcing']
			: ( $score_applies && $score_now && '0' === get_option( 'wc_blacklist_manager_disable_risk_score_auto_action', '0' ) );
		if ( $score_applies && ( $score_now || $enforcing ) ) {
			$lock = $order->get_meta( '_yobm_risk_score_auto_lock', true );
			if ( $lock && 'unavailable' !== $lock ) { return $this->held( $order, 'score_pending' ); }
			$state = $premium ? apply_filters( 'wc_blacklist_manager_new_order_email_score_state', 'pending', $order, $work ) : 'unavailable';
			if ( empty( $work['score_protocol'] ) && $premium ) {
				// Older counterparts lack a reliable advisory completion signal.
				if ( '1' === (string) $order->get_meta( '_yobm_risk_score_auto_checked', true )
					&& ! $order->get_meta( '_yobm_risk_score_auto_lock', true )
					&& in_array( $order->get_meta( '_yobm_risk_score_auto_result', true ), [ 'below_threshold', 'suspect_added' ], true ) ) { $state = 'valid'; }
			}
			if ( 'pending' === $state ) { return $this->held( $order, 'score_pending' ); }
			if ( 'valid' !== $state && $enforcing ) { return $this->held( $order, 'score_enforcement_unresolved' ); }
		}
		$checks = isset( $work['local_checks'] ) ? $work['local_checks'] : null;
		if ( ! empty( $work['local_context'] ) && $this->local_blocking_checks( $checks )
			&& ! apply_filters( 'wc_blacklist_manager_new_order_email_local_context_valid', false, $order, $work ) ) { return $this->held( $order, 'local_context_changed' ); }
		if ( ! $this->local_blocking_checks_complete( $order, $checks ) ) { return $this->held( $order, 'local_enforcement_unresolved' ); }
		return true;
	}

	private function local_blocking_checks( $admitted_checks = null ) : array {
		$checks = [
			[ 'block_billing_shipping', 'action_billing_shipping', 'WC_Blacklist_Manager_Premium_Suspect_Billing_Shipping', 'match,no_shipping_address', '' ],
			[ 'block_order_attempts', 'action_order_attempts', 'Blacklist_Manager_Premium_Automation_Order_Attempts', 'not_matched', '' ],
			[ 'block_phone_email_address', 'action_phone_email_address', 'WC_Blacklist_Manager_Premium_Automation_Phone_Email_Vs_Address', '', 'META_BILLING_MISMATCH,META_SHIPPING_MISMATCH' ],
			[ 'block_phone_email_ip', 'action_phone_email_ip', 'WC_Blacklist_Manager_Premium_Suspect_Phone_Email_Vs_IP', '', 'META_IP_MISMATCH' ],
			[ 'block_order_value', 'action_order_value', 'WC_Blacklist_Manager_Premium_Suspect_Order_Value', 'not_matched', '' ],
			[ 'block_ip_address', 'action_ip_address', 'WC_Blacklist_Manager_Premium_Suspect_IP_Vs_Address_Coordinates', 'within_radius', '' ],
			[ 'block_ip_country', 'action_ip_country', 'WC_Blacklist_Manager_Premium_Suspect_IP_Vs_Country', 'match', '' ],
			[ 'block_hosting_ip', 'action_hosting_ip', 'WC_Blacklist_Manager_Premium_Suspect_Hosting_IP', 'not_hosting,trusted_bypass', '' ],
			[ 'block_proxy_vpn', 'action_proxy_vpn', 'WC_Blacklist_Manager_Premium_Suspect_IP_Proxy_VPN', 'not_proxy_vpn,trusted_bypass', '' ],
			[ 'block_device_email_phone', 'action_device_email_phone', 'WC_Blacklist_Manager_Premium_Device_Vs_Email_Phone', '', 'META_MISMATCH' ],
			[ 'block_device_address', 'action_device_address', 'WC_Blacklist_Manager_Premium_Automation_Device_Vs_Address', '', 'META_BILLING_MISMATCH,META_SHIPPING_MISMATCH' ],
			[ 'block_device_identity_spread', 'action_device_identity_spread', 'WC_Blacklist_Manager_Premium_Automation_Device_Identity_Spread', 'not_matched', '' ],
			[ 'payment_avs_check', 'action_payment_avs_check', 'WC_Blacklist_Manager_Premium_Automation_AVS_Check', 'not_applicable_gateway,pass_or_neutral', '' ],
			[ 'payment_card_billing_country', 'action_payment_card_billing_country', 'WC_Blacklist_Manager_Premium_Automation_Card_Country_Billing_Country', 'not_applicable_gateway,match', '' ],
			[ 'payment_suspected_country', 'action_payment_suspected_country', 'WC_Blacklist_Manager_Premium_Suspect_Card_Country_High_Risk_Country', 'not_applicable_gateway,not_high_risk,no_configured_countries', '' ],
			[ 'payment_paypal_payer_email', 'action_payment_paypal_payer_email', 'WC_Blacklist_Manager_Premium_Automation_PayPal_Payer_Vs_Customer', 'not_applicable_gateway,not_matched', '' ],
		];
		return array_values( array_filter( $checks, static function( $check ) use ( $admitted_checks ) {
			list( $enabled, $action ) = $check;
			if ( is_array( $admitted_checks ) ) { return in_array( $enabled, $admitted_checks, true ); }
			// Match the Premium producer's effective IP-coordinate capability.
			if ( 'block_ip_address' === $enabled && ( '1' !== get_option( 'wc_blacklist_manager_enable_google_maps_geocoding', '0' )
				|| ( class_exists( 'YOBMP_Integration_Health' ) && ! YOBMP_Integration_Health::effective( 'google_geocoding' ) ) ) ) { return false; }
			return ( 0 !== strpos( $enabled, 'payment_' ) || '1' === get_option( 'wc_blacklist_manager_enable_gateway_protection', '0' ) )
				&& '1' === get_option( 'wc_blacklist_' . $enabled, '0' )
				&& 'blocked' === sanitize_key( (string) get_option( 'wc_blacklist_' . $action, 'email' ) );
		} ) );
	}

	private function local_blocking_checks_complete( WC_Order $order, $admitted_checks = null ) : bool {
		// Direct enforcing rules are independent of the scoring master.
		foreach ( $this->local_blocking_checks( $admitted_checks ) as $check ) {
			list( $enabled, $action, $class, $safe_results, $mismatches ) = $check;
			if ( ! class_exists( $class ) || ! defined( $class . '::META_CHECKED' ) || ! defined( $class . '::META_LOCK' ) || '1' !== (string) $order->get_meta( $class::META_CHECKED, true )
				|| $order->get_meta( $class::META_LOCK, true ) ) { return false; }
			if ( '' !== $safe_results && ( ! defined( $class . '::META_RESULT' ) || ! in_array( $order->get_meta( $class::META_RESULT, true ), explode( ',', $safe_results ), true ) ) ) { return false; }
			foreach ( array_filter( explode( ',', $mismatches ) ) as $constant ) {
				if ( ! defined( $class . '::' . $constant ) || '0' !== (string) $order->get_meta( constant( $class . '::' . $constant ), true ) ) { return false; }
			}
		}
		return true;
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
		$extra = [
			'wc_blacklist_device_vs_email_phone_job' => 'action_device_email_phone',
			'wc_blacklist_order_device_vs_address_job' => 'action_device_address',
			'wc_blacklist_device_identity_spread_job' => 'action_device_identity_spread',
			'wc_blacklist_order_paypal_payer_vs_customer_job' => 'action_payment_paypal_payer_email',
			'wc_blacklist_gateway_high_risk_country_job' => 'action_payment_suspected_country',
			'wc_blacklist_gateway_card_billing_job' => 'action_payment_card_billing_country',
			'wc_blacklist_gateway_avs_job' => 'action_payment_avs_check',
		];
		if ( isset( $extra[ $hook ] ) ) { $action = sanitize_key( (string) get_option( 'wc_blacklist_' . $extra[ $hook ], 'email' ) ); return [ (int) $order_id, in_array( $action, [ 'email', 'suspect', 'blocked', 'score' ], true ) ? $action : 'email' ]; }

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
		} elseif ( 'wc_blacklist_order_ip_proxy_vpn_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_proxy_vpn', 'email' );
		} elseif ( 'wc_blacklist_gateway_high_risk_country_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_payment_suspected_country', 'email' );
		} elseif ( 'wc_blacklist_gateway_card_billing_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_payment_card_billing_country', 'email' );
		} elseif ( 'wc_blacklist_gateway_avs_job' === $hook ) {
			$args[] = get_option( 'wc_blacklist_action_payment_avs_check', 'email' );
		}

		if ( isset( $args[1] ) ) { $args[1] = sanitize_key( (string) $args[1] ); }
		if ( isset( $args[1] ) && ! in_array( $args[1], [ 'email', 'suspect', 'blocked', 'score' ], true ) ) { $args[1] = 'email'; }
		return $args;
	}
}

new WC_Blacklist_Manager_New_Order_Email_Coordinator();
