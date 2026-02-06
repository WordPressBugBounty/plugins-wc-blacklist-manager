<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook global blacklist checks into new orders (classic + blocks checkout),
 * controlled by:
 *
 * - wc_blacklist_enable_global_blacklist (1 = enabled)
 * - wc_blacklist_global_blacklist_decision_mode:
 *      light    → notes only
 *      moderate → notes + status changes
 *      strict   → notes + status changes + hard block at checkout for "block"
 */
final class YOGB_BM_Check_Orders {

	const META_DECISION         = '_yogb_gbl_decision';
	const META_TIER             = '_yogb_gbl_tier';
	const META_REASONS          = '_yogb_gbl_reasons';
	const META_RAW              = '_yogb_gbl_raw';
	const META_REASON_SUMMARIES = '_yogb_gbl_reason_summaries';
	const META_REPORT_SUMMARIES = '_yogb_gbl_report_summaries';

	/**
	 * Simple debug logger.
	 */
	private static function debug_log( string $message, array $context = [] ) : void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$line = '[YOGB][gbl-check] ' . $message;

		if ( ! empty( $context ) ) {
			$json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false !== $json ) {
				$line .= ' ' . $json;
			}
		}

		error_log( $line );
	}

	/**
	 * Bootstrap.
	 */
	public static function init() : void {
		// Only run if global blacklist feature is enabled.
		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled ) {
			return;
		}

		$api_key     = trim( (string) get_option( 'yogb_bm_api_key', '' ) );
		$api_secret  = trim( (string) get_option( 'yogb_bm_api_secret', '' ) );
		$reporter_id = trim( (string) get_option( 'yogb_bm_reporter_id', '' ) );

		$missing_connection = ( '' === $api_key || '' === $api_secret || '' === $reporter_id );
		if ( $missing_connection ) {
			return;
		}

		$mode = self::get_decision_mode();

		// Instead of running the check inline, we enqueue an async action.
		add_action(
			'woocommerce_checkout_order_processed',
			[ __CLASS__, 'enqueue_global_check_async' ],
			20,
			3
		);

		add_action(
			'woocommerce_store_api_checkout_order_processed',
			[ __CLASS__, 'enqueue_global_check_async' ],
			20,
			1
		);

		// Action Scheduler worker.
		add_action(
			'yogb_gbl_run_check_async',
			[ __CLASS__, 'run_global_check_async' ],
			10,
			1
		);

		// Optional: only keep strict pre-validation if you still want hard blocks.
		if ( 'strict' === $mode ) {
			add_action(
				'woocommerce_after_checkout_validation',
				[ __CLASS__, 'validate_classic_strict' ],
				20,
				2
			);

			add_action(
				'woocommerce_store_api_checkout_update_order_meta',
				[ __CLASS__, 'validate_store_api_strict' ],
				20,
				1
			);
		}
	}

	/**
	 * Enqueue a background Global Blacklist check for the order.
	 *
	 * Classic:
	 *   enqueue_global_check_async( int $order_id, array $posted_data, WC_Order $order )
	 *
	 * Blocks:
	 *   enqueue_global_check_async( WC_Order $order )
	 */
	public static function enqueue_global_check_async( $order_or_id, $posted_data = [], $legacy_order = null ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}

		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled ) {
			return;
		}

		$order    = null;
		$order_id = 0;

		if ( $order_or_id instanceof WC_Order ) {
			$order    = $order_or_id;
			$order_id = $order->get_id();
		} else {
			$order_id = (int) $order_or_id;
			if ( $legacy_order instanceof WC_Order ) {
				$order = $legacy_order;
			} elseif ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
			}
		}

		if ( ! $order instanceof WC_Order || $order_id <= 0 ) {
			return;
		}

		// Avoid double-enqueue.
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$existing = as_next_scheduled_action(
				'yogb_gbl_run_check_async',
				[ 'order_id' => $order_id ],
				'yogb-global-blacklist'
			);
			if ( $existing ) {
				return;
			}
		}

		// Schedule a background run a few seconds later.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 3, // small delay; adjust as you like
				'yogb_gbl_run_check_async',
				[ 'order_id' => $order_id ],
				'yogb-global-blacklist'
			);
		} else {
			// Fallback in case Action Scheduler is not available.
			self::run_global_check_async( $order_id );
		}
	}

	/**
	 * Get decision mode: light | moderate | strict
	 */
	private static function get_decision_mode() : string {
		$mode = get_option( 'wc_blacklist_global_blacklist_decision_mode', 'light' );
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : 'light';

		if ( ! in_array( $mode, [ 'light', 'moderate', 'strict' ], true ) ) {
			$mode = 'light';
		}

		return $mode;
	}

	/**
	 * STRICT MODE (classic checkout):
	 * Validate before the order is created. If decision is "block", add an error
	 * and prevent the order from being placed.
	 *
	 * @param array              $fields
	 * @param WP_Error|WC_Errors $errors
	 */
	public static function validate_classic_strict( $fields, $errors ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}

		// Double-check feature + mode in case options changed mid-request.
		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled || 'strict' !== self::get_decision_mode() ) {
			return;
		}

		$order = self::build_ephemeral_order_from_fields( (array) $fields );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$resp     = YOGB_BM_Check::check_order( $order );
		$decision = YOGB_BM_Check::get_overall_decision( $resp );

		if ( 'block' === $decision ) {
			$message = __(
				'Your order cannot be placed at this time due to our fraud protection rules. Please contact the store owner for assistance.',
				'wc-blacklist-manager'
			);

			if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
				$errors->add( 'yogb_gbl_blocked', $message );
			} else {
				// Fallback, just in case.
				wc_add_notice( $message, 'error' );
			}
		}
	}

	/**
	 * Build a temporary WC_Order object from checkout fields for strict validation.
	 * This order is NOT saved; it only exists so we can reuse YOGB_BM_Check::check_order().
	 */
	private static function build_ephemeral_order_from_fields( array $fields ) : ?WC_Order {
		if ( ! class_exists( 'WC_Order' ) ) {
			return null;
		}

		$order = new WC_Order();

		// Billing.
		if ( isset( $fields['billing_first_name'] ) ) {
			$order->set_billing_first_name( wc_clean( $fields['billing_first_name'] ) );
		}
		if ( isset( $fields['billing_last_name'] ) ) {
			$order->set_billing_last_name( wc_clean( $fields['billing_last_name'] ) );
		}
		if ( isset( $fields['billing_email'] ) ) {
			$order->set_billing_email( sanitize_email( $fields['billing_email'] ) );
		}
		if ( isset( $fields['billing_phone'] ) ) {
			$order->set_billing_phone( wc_clean( $fields['billing_phone'] ) );
		}
		if ( isset( $fields['billing_address_1'] ) ) {
			$order->set_billing_address_1( wc_clean( $fields['billing_address_1'] ) );
		}
		if ( isset( $fields['billing_address_2'] ) ) {
			$order->set_billing_address_2( wc_clean( $fields['billing_address_2'] ) );
		}
		if ( isset( $fields['billing_city'] ) ) {
			$order->set_billing_city( wc_clean( $fields['billing_city'] ) );
		}
		if ( isset( $fields['billing_state'] ) ) {
			$order->set_billing_state( wc_clean( $fields['billing_state'] ) );
		}
		if ( isset( $fields['billing_postcode'] ) ) {
			$order->set_billing_postcode( wc_clean( $fields['billing_postcode'] ) );
		}
		if ( isset( $fields['billing_country'] ) ) {
			$order->set_billing_country( wc_clean( $fields['billing_country'] ) );
		}

		// Shipping (if provided).
		if ( isset( $fields['ship_to_different_address'] ) && $fields['ship_to_different_address'] ) {
			if ( isset( $fields['shipping_first_name'] ) ) {
				$order->set_shipping_first_name( wc_clean( $fields['shipping_first_name'] ) );
			}
			if ( isset( $fields['shipping_last_name'] ) ) {
				$order->set_shipping_last_name( wc_clean( $fields['shipping_last_name'] ) );
			}
			if ( isset( $fields['shipping_address_1'] ) ) {
				$order->set_shipping_address_1( wc_clean( $fields['shipping_address_1'] ) );
			}
			if ( isset( $fields['shipping_address_2'] ) ) {
				$order->set_shipping_address_2( wc_clean( $fields['shipping_address_2'] ) );
			}
			if ( isset( $fields['shipping_city'] ) ) {
				$order->set_shipping_city( wc_clean( $fields['shipping_city'] ) );
			}
			if ( isset( $fields['shipping_state'] ) ) {
				$order->set_shipping_state( wc_clean( $fields['shipping_state'] ) );
			}
			if ( isset( $fields['shipping_postcode'] ) ) {
				$order->set_shipping_postcode( wc_clean( $fields['shipping_postcode'] ) );
			}
			if ( isset( $fields['shipping_country'] ) ) {
				$order->set_shipping_country( wc_clean( $fields['shipping_country'] ) );
			}
		}

		// IP address.
		if ( class_exists( 'WC_Geolocation' ) ) {
			$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$order->set_customer_ip_address( wc_clean( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
		}

		return $order;
	}

	/**
	 * STRICT MODE (blocks checkout):
	 * Run the check during Store API checkout and throw an exception if "block".
	 * This prevents the order from being placed.
	 *
	 * @param WC_Order $order
	 *
	 * @throws Exception When order is blocked.
	 */
	public static function validate_store_api_strict( $order ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled || 'strict' !== self::get_decision_mode() ) {
			return;
		}

		$resp     = YOGB_BM_Check::check_order( $order );
		$decision = YOGB_BM_Check::get_overall_decision( $resp );

		if ( 'block' === $decision ) {
			$message = __(
				'Your order cannot be placed at this time due to our fraud protection rules. Please contact the store owner for assistance.',
				'wc-blacklist-manager'
			);
			// Throwing a generic Exception here is supported by Store API examples.
			throw new Exception( $message );
		}
	}

	/**
	 * Background worker: actually run the Global Blacklist check for an order.
	 *
	 * @param int $order_id
	 */
	public static function run_global_check_async( int $order_id ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}

		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Mark that we already processed this order.
		if ( $order->get_meta( '_yogb_gbl_checked', true ) ) {
			return;
		}
		$order->update_meta_data( '_yogb_gbl_checked', 1 );

		$mode     = self::get_decision_mode();

		// Run the check via the client.
		$resp      = YOGB_BM_Check::check_order( $order );
		$decision  = YOGB_BM_Check::get_overall_decision( $resp );
		$reasons   = YOGB_BM_Check::get_reasons( $resp );
		$tier      = isset( $resp['tier'] ) ? (string) $resp['tier'] : '';
		$http_code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;

		$details          = self::extract_identity_details_from_response( $resp );
		$reason_summaries = $details['reason_summaries'];
		$report_summaries = $details['report_summaries'];

		// Persist meta.
		$order->update_meta_data( self::META_DECISION, $decision );
		$order->update_meta_data( self::META_TIER, $tier );

		if ( ! empty( $reasons ) ) {
			$order->update_meta_data( self::META_REASONS, $reasons );
		}
		if ( ! empty( $reason_summaries ) ) {
			$order->update_meta_data( self::META_REASON_SUMMARIES, $reason_summaries );
		}
		if ( ! empty( $report_summaries ) ) {
			$order->update_meta_data( self::META_REPORT_SUMMARIES, $report_summaries );
		}
		if ( isset( $resp['body'] ) && $resp['body'] !== '' ) {
			$order->update_meta_data( self::META_RAW, wp_trim_words( $resp['body'], 80 ) );
		}

		// Local rate-limit.
		if ( ! $resp['ok'] && 429 === $http_code ) {
			$order->add_order_note(
				sprintf(
					__( 'Global Blacklist Decisions check skipped: monthly limit exceeded for tier "%1$s" (HTTP %2$d).', 'wc-blacklist-manager' ),
					$tier ?: 'free',
					$http_code
				)
			);
			$order->update_meta_data( self::META_DECISION, 'skipped_rate_limit' );

			$month_key = gmdate( 'Ym' );
			$tier_safe = $tier ?: 'free';

			$transient_key = 'yogb_gbd_limit_reached_' . $tier_safe . '_' . $month_key;

			// Keep flag long enough to cover the rest of the month (simple approach: 35 days).
			set_transient(
				$transient_key,
				[
					'tier' => $tier_safe,
					'ts'   => time(),
				],
				35 * DAY_IN_SECONDS
			);

			$order->save();
			return;
		}

		// Other failures: log, but do not block anything.
		if ( ! $resp['ok'] ) {
			$order->add_order_note(
				sprintf(
					__( 'Global Blacklist Decisions check could not be completed (HTTP %1$d). Order allowed by default.', 'wc-blacklist-manager' ),
					$http_code
				)
			);
			$order->save();
			return;
		}

		// Valid response; apply decision per mode.
		self::apply_decision_to_order(
			$order,
			$decision,
			$reasons,
			$tier,
			$mode,
			$reason_summaries,
			$report_summaries
		);

		$order->save();
	}

	/**
	 * Parse the raw server response and extract identity details.
	 *
	 * @param array $resp Response from YOGB_BM_Check::check_order()
	 * @return array{reason_summaries: string[], report_summaries: string[]}
	 */
	private static function extract_identity_details_from_response( array $resp ) : array {
		$reason_summaries = [];
		$report_summaries = [];

		$payload = $resp['json'] ?? null;

		// Fallback: try to decode the raw body.
		if ( ! is_array( $payload ) && ! empty( $resp['body'] ) && is_string( $resp['body'] ) ) {
			$decoded = json_decode( $resp['body'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		if ( ! is_array( $payload ) || empty( $payload['results'] ) || ! is_array( $payload['results'] ) ) {
			return [
				'reason_summaries' => $reason_summaries,
				'report_summaries' => $report_summaries,
			];
		}

		foreach ( $payload['results'] as $res ) {
			if ( ! is_array( $res ) ) {
				continue;
			}

			$type = isset( $res['type'] ) ? (string) $res['type'] : 'unknown';

			// ---- Reason summaries ----
			$chunks = [];

			// 1) Old-style: reason_stats (map of code => {label, total})
			if ( ! empty( $res['reason_stats'] ) && is_array( $res['reason_stats'] ) ) {
				foreach ( $res['reason_stats'] as $code => $info ) {
					if ( ! is_array( $info ) ) {
						continue;
					}

					$label = isset( $info['label'] ) && is_string( $info['label'] )
						? $info['label']
						: ucfirst( str_replace( '_', ' ', (string) $code ) );

					$total = isset( $info['total'] ) ? (int) $info['total'] : 0;

					$chunks[] = sprintf(
						/* translators: 1: reason label, 2: count */
						__( '%1$s — %2$d', 'wc-blacklist-manager' ),
						$label,
						$total
					);
				}
			}
			// 2) New-style: reasons_all_time (array of human-readable strings)
			elseif ( ! empty( $res['reasons_all_time'] ) && is_array( $res['reasons_all_time'] ) ) {
				foreach ( $res['reasons_all_time'] as $item ) {
					if ( ! is_string( $item ) ) {
						continue;
					}
					$chunks[] = $item;
				}
			}

			if ( ! empty( $chunks ) ) {
				$reason_summaries[] = sprintf(
					/* translators: 1: identity type, 2: list of reasons with counts */
					__( 'Reasons (all time, %1$s): %2$s', 'wc-blacklist-manager' ),
					$type,
					implode( ', ', $chunks )
				);
			}

			// ---- Individual report lines ----
			if ( ! empty( $res['reports'] ) && is_array( $res['reports'] ) ) {
				foreach ( $res['reports'] as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$reason_label = '';
					if ( ! empty( $row['reason'] ) ) {
						$reason_label = (string) $row['reason'];
					} elseif ( ! empty( $row['reason_code'] ) ) {
						$reason_label = ucfirst( str_replace( '_', ' ', (string) $row['reason_code'] ) );
					}

					$reporter = isset( $row['reporter'] ) ? (string) $row['reporter'] : '';
					$status   = isset( $row['status'] ) ? (string) $row['status'] : 'active';
					$created  = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';

					$base = sprintf(
						/* translators: 1: identity type, 2: reason, 3: reporter masked domain, 4: status */
						__( 'Report (%1$s): %2$s — %3$s (%4$s', 'wc-blacklist-manager' ),
						$type,
						$reason_label,
						$reporter,
						$status
					);

					if ( $created !== '' ) {
						$base .= ', ' . $created;
					}

					$base .= ')';

					$report_summaries[] = $base;
				}
			}
		}

		return [
			'reason_summaries' => $reason_summaries,
			'report_summaries' => $report_summaries,
		];
	}

	/**
	 * Map global decision to WooCommerce order status / notes.
	 *
	 * Decision: 'allow' | 'challenge' | 'block'
	 * Mode:     'light' | 'moderate' | 'strict'
	 *
	 * @param WC_Order $order
	 * @param string   $decision
	 * @param array    $reasons           Overall reasons from YOGB_BM_Check::get_reasons()
	 * @param string   $tier
	 * @param string   $mode
	 * @param array    $reason_summaries  Identity-level reason summaries
	 * @param array    $report_summaries  Individual report lines
	 */
	private static function apply_decision_to_order(
		WC_Order $order,
		string $decision,
		array $reasons,
		string $tier,
		string $mode,
		array $reason_summaries = [],
		array $report_summaries = []
	) : void {

		$note_lines   = [];
		$note_lines[] = sprintf(
			/* translators: 1: decision, 2: tier */
			__( 'Global Blacklist decision: %1$s (tier: %2$s).', 'wc-blacklist-manager' ),
			$decision,
			$tier ?: 'free'
		);

		// Overall reasons from the decision logic.
		if ( ! empty( $reasons ) ) {
			$note_lines[] = __( 'Decision reasons:', 'wc-blacklist-manager' );
			foreach ( $reasons as $r ) {
				$note_lines[] = ' - ' . $r;
			}
		}

		// Identity-level aggregates (all-time reasons per email/phone/ip/address).
		if ( ! empty( $reason_summaries ) ) {
			$note_lines[] = __( 'Identity risk summary:', 'wc-blacklist-manager' );
			foreach ( $reason_summaries as $r ) {
				$note_lines[] = ' - ' . $r;
			}
		}

		// Individual reports (reason, masked reporter, status, created_at).
		if ( ! empty( $report_summaries ) ) {
			$note_lines[] = __( 'Individual reports:', 'wc-blacklist-manager' );
			foreach ( $report_summaries as $r ) {
				$note_lines[] = ' - ' . $r;
			}
		}

		$note = implode( "\n", $note_lines );

		// LIGHT: note only, never change status.
		if ( 'light' === $mode ) {
			$order->add_order_note( $note );
			return;
		}

		// MODERATE / STRICT: status changes allowed.
		switch ( $decision ) {
			case 'block':
				// In strict mode, block is already handled pre-checkout where possible,
				// but we still cancel here as a safety net.
				$order->set_status(
					'cancelled',
					__( 'Order cancelled: blocked by Global Blacklist Decisions.', 'wc-blacklist-manager' )
				);
				$order->add_order_note( $note );
				$order->update_meta_data( '_yogb_gbl_blocked', '1' );
				break;

			case 'challenge':
				// Put order on-hold for manual review (both moderate + strict).
				if ( $order->has_status( [ 'pending', 'processing' ] ) ) {
					$order->set_status(
						'on-hold',
						__( 'Order placed on hold: requires review by Global Blacklist Decisions.', 'wc-blacklist-manager' )
					);
				}
				$order->add_order_note( $note );
				$order->update_meta_data( '_yogb_gbl_challenged', '1' );
				break;

			case 'allow':
			default:
				$order->add_order_note( $note );
				break;
		}
	}
}

YOGB_BM_Check_Orders::init();
