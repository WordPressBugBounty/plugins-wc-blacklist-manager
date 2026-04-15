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

	const META_DECISION               = '_yogb_gbl_decision';
	const META_TIER                   = '_yogb_gbl_tier';
	const META_REASONS                = '_yogb_gbl_reasons';
	const META_RAW                    = '_yogb_gbl_raw';
	const META_REASON_SUMMARIES       = '_yogb_gbl_reason_summaries';
	const META_REPORT_SUMMARIES       = '_yogb_gbl_report_summaries';
	const META_SIGNAL_SUMMARIES       = '_yogb_gbl_signal_summaries';

	// Structured Phase 3 signal meta.
	const META_EFFECTIVE_SCORE        = '_yogb_gbl_effective_score';
	const META_DIRECT_SCORE           = '_yogb_gbl_direct_score';
	const META_LINKED_BOOST           = '_yogb_gbl_linked_boost';
	const META_LINKED_NEIGHBORS_COUNT = '_yogb_gbl_linked_neighbors_count';
	const META_MATCHED_IDENTITIES     = '_yogb_gbl_matched_identities';
	const META_PRIMARY_SIGNAL_TYPE    = '_yogb_gbl_primary_signal_type';
	const META_PRIMARY_RISK_LEVEL     = '_yogb_gbl_primary_risk_level';
	const META_PRIMARY_LAST_REPORTED  = '_yogb_gbl_primary_last_reported';
	const META_MATCHED_IDENTITY_NODES        = '_yogb_gbl_matched_identity_nodes';
	const META_PRIMARY_MATCH_MODE            = '_yogb_gbl_primary_match_mode';
	const META_PRIMARY_MATCHED_VARIANT       = '_yogb_gbl_primary_matched_variant';
	const META_PRIMARY_MATCHED_IDENTITY_COUNT = '_yogb_gbl_primary_matched_identity_count';

	/**
	 * Bootstrap.
	 */
	public static function init() : void {
		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		$development_mode = (int) get_option( 'wc_blacklist_development_mode', '1' );

		if ( 1 !== $enabled || 1 === $development_mode ) {
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

		add_action(
			'yogb_gbl_run_check_async',
			[ __CLASS__, 'run_global_check_async' ],
			10,
			1
		);

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

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 3,
				'yogb_gbl_run_check_async',
				[ 'order_id' => $order_id ],
				'yogb-global-blacklist'
			);
		} else {
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
	 * Validate before the order is created.
	 *
	 * @param array              $fields
	 * @param WP_Error|WC_Errors $errors
	 */
	public static function validate_classic_strict( $fields, $errors ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}

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
				wc_add_notice( $message, 'error' );
			}
		}
	}

	/**
	 * Build a temporary WC_Order object from checkout fields for strict validation.
	 */
	private static function build_ephemeral_order_from_fields( array $fields ) : ?WC_Order {
		if ( ! class_exists( 'WC_Order' ) ) {
			return null;
		}

		$order = new WC_Order();

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
			throw new Exception( esc_html( $message ) );
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

		if ( $order->get_meta( '_yogb_gbl_checked', true ) ) {
			return;
		}
		$order->update_meta_data( '_yogb_gbl_checked', 1 );

		$mode = self::get_decision_mode();

		$resp      = YOGB_BM_Check::check_order( $order );
		$decision  = YOGB_BM_Check::get_overall_decision( $resp );
		$reasons   = YOGB_BM_Check::get_reasons( $resp );
		$tier      = isset( $resp['tier'] ) ? (string) $resp['tier'] : '';
		$http_code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;

		$details        = self::extract_identity_details_from_response( $resp );
		$overall_signal = YOGB_BM_Check::get_overall_signal_metrics( $resp );

		$signal_summaries = $details['signal_summaries'];
		$reason_summaries = $details['reason_summaries'];
		$report_summaries = $details['report_summaries'];
		$primary_meta     = $details['primary_meta'];

		$order->update_meta_data( self::META_DECISION, $decision );
		$order->update_meta_data( self::META_TIER, $tier );

		// Structured signal meta.
		$order->update_meta_data( self::META_EFFECTIVE_SCORE, (float) $overall_signal['max_effective_score'] );
		$order->update_meta_data( self::META_DIRECT_SCORE, (float) $overall_signal['max_direct_score'] );
		$order->update_meta_data( self::META_LINKED_BOOST, (float) $overall_signal['max_linked_boost'] );
		$order->update_meta_data( self::META_LINKED_NEIGHBORS_COUNT, (int) $overall_signal['max_neighbors_count'] );
		$order->update_meta_data( self::META_MATCHED_IDENTITIES, (int) $overall_signal['matched_identities'] );
		$order->update_meta_data( self::META_PRIMARY_SIGNAL_TYPE, (string) $overall_signal['primary_signal_type'] );
		$order->update_meta_data( self::META_PRIMARY_RISK_LEVEL, (string) $overall_signal['max_risk_level'] );
		$order->update_meta_data( self::META_PRIMARY_LAST_REPORTED, $overall_signal['primary_last_reported'] );

		// New smarter match meta.
		$order->update_meta_data(
			self::META_MATCHED_IDENTITY_NODES,
			isset( $details['matched_identity_nodes'] ) ? (int) $details['matched_identity_nodes'] : 0
		);

		$order->update_meta_data(
			self::META_PRIMARY_MATCH_MODE,
			isset( $primary_meta['match_mode'] ) ? (string) $primary_meta['match_mode'] : ''
		);

		$order->update_meta_data(
			self::META_PRIMARY_MATCHED_VARIANT,
			isset( $primary_meta['matched_variant'] ) ? (string) $primary_meta['matched_variant'] : ''
		);

		$order->update_meta_data(
			self::META_PRIMARY_MATCHED_IDENTITY_COUNT,
			isset( $primary_meta['matched_identity_count'] ) ? (int) $primary_meta['matched_identity_count'] : 0
		);

		if ( ! empty( $reasons ) ) {
			$order->update_meta_data( self::META_REASONS, $reasons );
		} else {
			$order->delete_meta_data( self::META_REASONS );
		}

		if ( ! empty( $signal_summaries ) ) {
			$order->update_meta_data( self::META_SIGNAL_SUMMARIES, $signal_summaries );
		} else {
			$order->delete_meta_data( self::META_SIGNAL_SUMMARIES );
		}

		if ( ! empty( $reason_summaries ) ) {
			$order->update_meta_data( self::META_REASON_SUMMARIES, $reason_summaries );
		} else {
			$order->delete_meta_data( self::META_REASON_SUMMARIES );
		}

		if ( ! empty( $report_summaries ) ) {
			$order->update_meta_data( self::META_REPORT_SUMMARIES, $report_summaries );
		} else {
			$order->delete_meta_data( self::META_REPORT_SUMMARIES );
		}

		if ( isset( $resp['body'] ) && '' !== $resp['body'] ) {
			$order->update_meta_data( self::META_RAW, (string) $resp['body'] );
		}

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

			set_transient(
				$transient_key,
				[
					'tier' => $tier_safe,
					'ts'   => time(),
				],
				35 * DAY_IN_SECONDS
			);

			$order->save();

			do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
			return;
		}

		if ( ! $resp['ok'] ) {
			$order->add_order_note(
				sprintf(
					__( 'Global Blacklist Decisions check could not be completed (HTTP %1$d). Order allowed by default.', 'wc-blacklist-manager' ),
					$http_code
				)
			);

			$order->save();

			do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
			return;
		}

		self::apply_decision_to_order(
			$order,
			$decision,
			$reasons,
			$tier,
			$mode,
			$signal_summaries,
			$reason_summaries,
			$report_summaries
		);

		$order->save();

		do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
	}

	/**
	 * Parse the raw server response and extract identity details.
	 *
	 * @param array $resp Response from YOGB_BM_Check::check_order()
	 * @return array{
	 *     signal_summaries: string[],
	 *     reason_summaries: string[],
	 *     report_summaries: string[]
	 * }
	 */
	private static function extract_identity_details_from_response( array $resp ) : array {
		$signal_summaries       = [];
		$reason_summaries       = [];
		$report_summaries       = [];
		$matched_identity_nodes = 0;

		$primary_meta = [
			'type'                   => '',
			'risk_level'             => '',
			'last_reported'          => '',
			'match_mode'             => '',
			'matched_variant'        => '',
			'matched_identity_count' => 0,
			'effective_score'        => 0.0,
		];

		$payload = $resp['json'] ?? null;

		if ( ! is_array( $payload ) && ! empty( $resp['body'] ) && is_string( $resp['body'] ) ) {
			$decoded = json_decode( $resp['body'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		if ( ! is_array( $payload ) || empty( $payload['results'] ) || ! is_array( $payload['results'] ) ) {
			return [
				'signal_summaries'       => $signal_summaries,
				'reason_summaries'       => $reason_summaries,
				'report_summaries'       => $report_summaries,
				'matched_identity_nodes' => 0,
				'primary_meta'           => $primary_meta,
			];
		}

		foreach ( $payload['results'] as $res ) {
			if ( ! is_array( $res ) ) {
				continue;
			}

			$type            = isset( $res['type'] ) ? (string) $res['type'] : 'unknown';
			$match_mode      = isset( $res['match_mode'] ) ? (string) $res['match_mode'] : 'none';
			$matched_variant = isset( $res['matched_variant'] ) ? (string) $res['matched_variant'] : '';
			$matched_count   = isset( $res['matched_identity_count'] ) ? (int) $res['matched_identity_count'] : 0;

			$aggregate = ( isset( $res['aggregate'] ) && is_array( $res['aggregate'] ) ) ? $res['aggregate'] : [];
			$matches   = ( isset( $res['matches'] ) && is_array( $res['matches'] ) ) ? $res['matches'] : [];

			$report_count           = isset( $aggregate['report_count'] ) ? (int) $aggregate['report_count'] : 0;
			$direct_score           = isset( $aggregate['direct_score'] ) ? (float) $aggregate['direct_score'] : 0.0;
			$linked_boost           = isset( $aggregate['linked_boost'] ) ? (float) $aggregate['linked_boost'] : 0.0;
			$effective_score        = isset( $aggregate['score'] ) ? (float) $aggregate['score'] : 0.0;
			$linked_neighbors_count = isset( $aggregate['linked_neighbors_count'] ) ? (int) $aggregate['linked_neighbors_count'] : 0;
			$risk_level             = isset( $aggregate['risk_level'] ) ? (string) $aggregate['risk_level'] : 'low';
			$last_reported          = isset( $aggregate['last_reported'] ) ? (string) $aggregate['last_reported'] : '';

			$found = ! empty( $matches ) || $report_count > 0 || $effective_score > 0 || $direct_score > 0 || $linked_boost > 0;

			if ( $found ) {
				$matched_identity_nodes += max( 1, $matched_count );

				$type_label = self::format_identity_type_label_static( $type );

				$summary_parts = [];

				if ( 'none' !== $match_mode && '' !== $match_mode ) {
					$summary_parts[] = sprintf(
						__( '%1$s matched through %2$s.', 'wc-blacklist-manager' ),
						$type_label,
						strtolower( self::format_match_mode_label_static( $match_mode ) )
					);
				} else {
					$summary_parts[] = sprintf(
						__( '%s matched.', 'wc-blacklist-manager' ),
						$type_label
					);
				}

				$summary_parts[] = sprintf(
					__( 'Risk: %s.', 'wc-blacklist-manager' ),
					strtolower( $risk_level )
				);

				if ( $report_count > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Reports: %d.', 'wc-blacklist-manager' ),
						$report_count
					);
				}

				if ( $matched_count > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Related records: %d.', 'wc-blacklist-manager' ),
						$matched_count
					);
				}

				if ( '' !== $matched_variant && 'submitted' !== strtolower( $matched_variant ) ) {
					$summary_parts[] = sprintf(
						__( 'Matched detail: %s.', 'wc-blacklist-manager' ),
						self::format_matched_variant_label_static( $matched_variant )
					);
				}

				if ( '' !== $last_reported ) {
					$summary_parts[] = sprintf(
						__( 'Last reported: %s.', 'wc-blacklist-manager' ),
						$last_reported
					);
				}

				// Keep score details, but soften wording.
				if ( $effective_score > 0 || $direct_score > 0 || $linked_boost > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Scores — direct %1$s, related +%2$s, effective %3$s.', 'wc-blacklist-manager' ),
						number_format_i18n( $direct_score, 2 ),
						number_format_i18n( $linked_boost, 2 ),
						number_format_i18n( $effective_score, 2 )
					);
				}

				if ( $linked_neighbors_count > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Related neighbors: %d.', 'wc-blacklist-manager' ),
						$linked_neighbors_count
					);
				}

				$signal_summaries[] = implode( ' ', $summary_parts );
			}

			if ( $effective_score > (float) $primary_meta['effective_score'] ) {
				$primary_meta = [
					'type'                   => $type,
					'risk_level'             => $risk_level,
					'last_reported'          => $last_reported,
					'match_mode'             => $match_mode,
					'matched_variant'        => $matched_variant,
					'matched_identity_count' => $matched_count,
					'effective_score'        => $effective_score,
				];
			}

			$chunks = [];

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
						__( '%1$s — %2$d', 'wc-blacklist-manager' ),
						$label,
						$total
					);
				}
			} elseif ( ! empty( $res['reasons_all_time'] ) && is_array( $res['reasons_all_time'] ) ) {
				foreach ( $res['reasons_all_time'] as $item ) {
					if ( ! is_string( $item ) ) {
						continue;
					}
					$chunks[] = $item;
				}
			}

			if ( ! empty( $chunks ) ) {
				$type_label = self::format_identity_type_label_static( $type );

				$prefix = sprintf(
					__( 'Past reasons for %1$s: %2$s.', 'wc-blacklist-manager' ),
					strtolower( $type_label ),
					implode( ', ', $chunks )
				);

				$reason_meta = [];

				if ( 'none' !== $match_mode && '' !== $match_mode ) {
					$reason_meta[] = self::format_match_mode_label_static( $match_mode );
				}

				if ( '' !== $matched_variant && 'submitted' !== strtolower( $matched_variant ) ) {
					$reason_meta[] = self::format_matched_variant_label_static( $matched_variant );
				}

				if ( ! empty( $reason_meta ) ) {
					$prefix .= ' ' . sprintf(
						__( 'Match detail: %s.', 'wc-blacklist-manager' ),
						implode( ' · ', $reason_meta )
					);
				}

				$reason_summaries[] = $prefix;
			}

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

					$type_label = self::format_identity_type_label_static( $type );

					$parts = [
						sprintf(
							__( '%1$s report: %2$s.', 'wc-blacklist-manager' ),
							$type_label,
							$reason_label
						),
					];

					if ( '' !== $reporter ) {
						$parts[] = sprintf(
							__( 'Source: %s.', 'wc-blacklist-manager' ),
							$reporter
						);
					}

					if ( '' !== $status ) {
						$parts[] = sprintf(
							__( 'Status: %s.', 'wc-blacklist-manager' ),
							$status
						);
					}

					if ( '' !== $created ) {
						$parts[] = sprintf(
							__( 'Date: %s.', 'wc-blacklist-manager' ),
							$created
						);
					}

					$report_meta = [];

					if ( 'none' !== $match_mode && '' !== $match_mode ) {
						$report_meta[] = self::format_match_mode_label_static( $match_mode );
					}

					if ( '' !== $matched_variant && 'submitted' !== strtolower( $matched_variant ) ) {
						$report_meta[] = self::format_matched_variant_label_static( $matched_variant );
					}

					if ( ! empty( $report_meta ) ) {
						$parts[] = sprintf(
							__( 'Match detail: %s.', 'wc-blacklist-manager' ),
							implode( ' / ', $report_meta )
						);
					}

					$report_summaries[] = implode( ' ', $parts );
				}
			}
		}

		unset( $primary_meta['effective_score'] );

		return [
			'signal_summaries'       => $signal_summaries,
			'reason_summaries'       => $reason_summaries,
			'report_summaries'       => $report_summaries,
			'matched_identity_nodes' => $matched_identity_nodes,
			'primary_meta'           => $primary_meta,
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
	 * @param array    $signal_summaries  Identity-level signal summaries
	 * @param array    $reason_summaries  Identity-level reason summaries
	 * @param array    $report_summaries  Individual report lines
	 */
	private static function apply_decision_to_order(
		WC_Order $order,
		string $decision,
		array $reasons,
		string $tier,
		string $mode,
		array $signal_summaries = [],
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

		$overall_effective      = (float) $order->get_meta( self::META_EFFECTIVE_SCORE, true );
		$overall_direct         = (float) $order->get_meta( self::META_DIRECT_SCORE, true );
		$overall_linked         = (float) $order->get_meta( self::META_LINKED_BOOST, true );
		$overall_neighbors      = (int) $order->get_meta( self::META_LINKED_NEIGHBORS_COUNT, true );
		$primary_type           = (string) $order->get_meta( self::META_PRIMARY_SIGNAL_TYPE, true );
		$primary_risk           = (string) $order->get_meta( self::META_PRIMARY_RISK_LEVEL, true );
		$primary_match_mode     = (string) $order->get_meta( self::META_PRIMARY_MATCH_MODE, true );
		$primary_match_variant  = (string) $order->get_meta( self::META_PRIMARY_MATCHED_VARIANT, true );
		$primary_match_count    = (int) $order->get_meta( self::META_PRIMARY_MATCHED_IDENTITY_COUNT, true );

		if ( $overall_effective > 0 || $overall_direct > 0 || $overall_linked > 0 ) {
			$note_line = sprintf(
				__( 'Primary signal (%1$s): direct %2$s, linked +%3$s, effective %4$s, neighbors %5$d, risk %6$s.', 'wc-blacklist-manager' ),
				$primary_type ?: __( 'unknown', 'wc-blacklist-manager' ),
				number_format_i18n( $overall_direct, 2 ),
				number_format_i18n( $overall_linked, 2 ),
				number_format_i18n( $overall_effective, 2 ),
				$overall_neighbors,
				$primary_risk ?: __( 'low', 'wc-blacklist-manager' )
			);

			$smart_bits = [];

			if ( '' !== $primary_match_mode && 'none' !== strtolower( $primary_match_mode ) ) {
				$smart_bits[] = sprintf(
					__( 'mode %s', 'wc-blacklist-manager' ),
					self::format_match_mode_label_static( $primary_match_mode )
				);
			}

			if ( '' !== $primary_match_variant && 'submitted' !== strtolower( $primary_match_variant ) ) {
				$smart_bits[] = sprintf(
					__( 'variant %s', 'wc-blacklist-manager' ),
					self::format_matched_variant_label_static( $primary_match_variant )
				);
			}

			if ( $primary_match_count > 0 ) {
				$smart_bits[] = sprintf(
					__( 'nodes %d', 'wc-blacklist-manager' ),
					$primary_match_count
				);
			}

			if ( ! empty( $smart_bits ) ) {
				$note_line .= ' ' . sprintf(
					__( 'How found: %s.', 'wc-blacklist-manager' ),
					implode( ', ', $smart_bits )
				);
			}

			$note_lines[] = $note_line;
		}

		if ( ! empty( $reasons ) ) {
			$note_lines[] = __( 'Decision reasons:', 'wc-blacklist-manager' );
			foreach ( $reasons as $r ) {
				$note_lines[] = ' - ' . $r;
			}
		}

		if ( ! empty( $signal_summaries ) ) {
			$note_lines[] = __( 'Signal summary:', 'wc-blacklist-manager' );
			foreach ( $signal_summaries as $r ) {
				$note_lines[] = ' - ' . $r;
			}
		}

		if ( ! empty( $reason_summaries ) ) {
			$note_lines[] = __( 'Identity risk summary:', 'wc-blacklist-manager' );
			foreach ( $reason_summaries as $r ) {
				$note_lines[] = ' - ' . $r;
			}
		}

		if ( ! empty( $report_summaries ) ) {
			$note_lines[] = __( 'Individual reports:', 'wc-blacklist-manager' );
			foreach ( $report_summaries as $r ) {
				$note_lines[] = ' - ' . $r;
			}
		}

		$note = implode( "\n", $note_lines );

		if ( 'light' === $mode ) {
			$order->add_order_note( $note );
			return;
		}

		switch ( $decision ) {
			case 'block':
				$order->set_status(
					'cancelled',
					__( 'Order cancelled: blocked by Global Blacklist Decisions.', 'wc-blacklist-manager' )
				);
				$order->add_order_note( $note );
				$order->update_meta_data( '_yogb_gbl_blocked', '1' );
				break;

			case 'challenge':
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

	private static function format_match_mode_label_static( string $mode ) : string {
		switch ( strtolower( $mode ) ) {
			case 'exact':
				return __( 'Exact match', 'wc-blacklist-manager' );

			case 'variant_core':
				return __( 'Main address match', 'wc-blacklist-manager' );

			case 'variant_premise':
				return __( 'Unit / apartment match', 'wc-blacklist-manager' );

			case 'linked':
				return __( 'Related match', 'wc-blacklist-manager' );

			case 'none':
			default:
				return __( 'No match', 'wc-blacklist-manager' );
		}
	}

	private static function format_matched_variant_label_static( string $variant ) : string {
		switch ( strtolower( $variant ) ) {
			case 'submitted':
				return __( 'Submitted details', 'wc-blacklist-manager' );

			case 'core':
				return __( 'Main address', 'wc-blacklist-manager' );

			case 'premise':
				return __( 'Unit / apartment', 'wc-blacklist-manager' );

			case 'full':
				return __( 'Full address', 'wc-blacklist-manager' );

			default:
				return '' !== $variant ? ucfirst( str_replace( '_', ' ', $variant ) ) : '';
		}
	}

	private static function format_identity_type_label_static( string $type ) : string {
		switch ( strtolower( $type ) ) {
			case 'email':
				return __( 'Email', 'wc-blacklist-manager' );

			case 'phone':
				return __( 'Phone', 'wc-blacklist-manager' );

			case 'ip':
				return __( 'IP address', 'wc-blacklist-manager' );

			case 'address':
				return __( 'Address', 'wc-blacklist-manager' );

			case 'domain':
				return __( 'Domain', 'wc-blacklist-manager' );

			default:
				return '' !== $type ? ucfirst( str_replace( '_', ' ', $type ) ) : __( 'Unknown', 'wc-blacklist-manager' );
		}
	}
}

YOGB_BM_Check_Orders::init();