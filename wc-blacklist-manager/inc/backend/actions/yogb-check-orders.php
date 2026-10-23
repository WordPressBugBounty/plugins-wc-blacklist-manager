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
 *      moderate → notes + order admin advisory notices
 *      strict   → notes + post-order status changes
 */
final class YOGB_BM_Check_Orders {

	const META_DECISION               = '_yogb_gbl_decision';
	const META_TIER                   = '_yogb_gbl_tier';
	const META_REASONS                = '_yogb_gbl_reasons';
	const META_RAW                    = '_yogb_gbl_raw';
	const META_REASON_SUMMARIES       = '_yogb_gbl_reason_summaries';
	const META_REPORT_SUMMARIES       = '_yogb_gbl_report_summaries';
	const META_SIGNAL_SUMMARIES       = '_yogb_gbl_signal_summaries';
	const META_CHECKED                = '_yogb_gbl_checked';
	const META_CHECK_STATUS           = '_yogb_gbl_check_status';
	const META_CHECK_ATTEMPTS         = '_yogb_gbl_check_attempts';
	const META_CHECK_STARTED_AT       = '_yogb_gbl_check_started_at';
	const META_CHECK_NEXT_RETRY_AT    = '_yogb_gbl_check_next_retry_at';
	const META_CHECK_LAST_ERROR       = '_yogb_gbl_check_last_error';
	const META_CHECK_LAST_HTTP_CODE   = '_yogb_gbl_check_last_http_code';
	const META_DECISION_REF          = '_yogb_gbl_decision_ref';
	const META_DECISION_AT           = '_yogb_gbl_decision_at';
	const META_DECISION_SUMMARY      = '_yogb_gbl_decision_summary';
	const META_DECISION_REASON_CODE  = '_yogb_gbl_decision_reason_code';
	const META_RESPONSE_SCHEMA       = '_yogb_gbl_response_schema';
	const META_DETAIL_AVAILABLE      = '_yogb_gbl_detail_available';
	const META_STORAGE_PROFILE       = '_yogb_gbl_storage_profile';
	const META_MATCH_SUMMARY         = '_yogb_gbl_match_summary_v2';

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

	public static function is_enabled() : bool {
		return 1 === (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 )
			&& 1 !== (int) get_option( 'wc_blacklist_development_mode', '1' )
			&& '' !== trim( (string) get_option( 'yogb_bm_api_key', '' ) )
			&& '' !== trim( (string) get_option( 'yogb_bm_api_secret', '' ) )
			&& '' !== trim( (string) get_option( 'yogb_bm_reporter_id', '' ) );
	}

	/**
	 * Bootstrap.
	 */
	public static function init() : void {
		if ( ! self::is_enabled() ) { return; }


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

		add_action(
			'admin_post_yogb_gbl_manual_order_check',
			[ __CLASS__, 'handle_manual_order_check' ]
		);

		add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'register_bulk_recheck_action' ] );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_recheck_action' ], 10, 3 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'register_bulk_recheck_action' ] );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_recheck_action' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'show_bulk_recheck_notice' ] );

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

	private static function is_global_check_complete( WC_Order $order ) : bool {
		$status = (string) $order->get_meta( self::META_CHECK_STATUS, true );
		if ( in_array( $status, [ 'success', 'skipped_rate_limit' ], true ) ) {
			return true;
		}

		if ( $order->get_meta( self::META_CHECKED, true ) ) {
			$legacy_decision = (string) $order->get_meta( self::META_DECISION, true );
			return '' !== $legacy_decision && 'check_failed' !== $legacy_decision;
		}

		return false;
	}

	private static function has_recent_pending_check( WC_Order $order ) : bool {
		$status     = (string) $order->get_meta( self::META_CHECK_STATUS, true );
		$started_at = (int) $order->get_meta( self::META_CHECK_STARTED_AT, true );

		return 'pending' === $status && $started_at > ( time() - 5 * MINUTE_IN_SECONDS );
	}

	private static function begin_global_check_attempt( WC_Order $order ) : int {
		$attempts = absint( $order->get_meta( self::META_CHECK_ATTEMPTS, true ) );
		$attempts++;

		self::invalidate_current_decision_context( $order );
		$order->update_meta_data( self::META_CHECK_STATUS, 'pending' );
		$order->update_meta_data( self::META_CHECK_ATTEMPTS, $attempts );
		// Preserve integer lease readers while distinguishing rechecks within the same second.
		$order->update_meta_data( self::META_CHECK_STARTED_AT, sprintf( '%.6F', microtime( true ) ) );
		$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
		$order->delete_meta_data( self::META_CHECK_LAST_ERROR );
		$order->delete_meta_data( self::META_CHECK_LAST_HTTP_CODE );
		$order->delete_meta_data( self::META_CHECKED );
		$order->save();

		return $attempts;
	}

	/** Current local application policy, never inferred from today's settings. */
	public static function get_applied_policy( WC_Order $order ) : array {
		$policy = $order->get_meta( '_yogb_gbl_applied_policy', true );
		if ( ! is_array( $policy ) || 2 !== ( $policy['version'] ?? null )
			|| ! in_array( $policy['mode'] ?? '', [ 'light', 'moderate', 'strict' ], true )
			|| ! in_array( $policy['decision'] ?? '', [ 'allow', 'challenge', 'block' ], true )
			|| 'success' !== $order->get_meta( self::META_CHECK_STATUS, true )
			|| ! $order->get_meta( self::META_CHECKED, true )
			|| ( $policy['decision'] ?? '' ) !== $order->get_meta( self::META_DECISION, true )
			|| ! is_int( $policy['attempt'] ?? null ) || $policy['attempt'] < 1
			|| $policy['attempt'] !== (int) $order->get_meta( self::META_CHECK_ATTEMPTS, true )
			|| ! is_string( $policy['started_at'] ?? null ) || '' === $policy['started_at']
			|| $policy['started_at'] !== (string) $order->get_meta( self::META_CHECK_STARTED_AT, true ) ) { return []; }
		return $policy;
	}

	private static function get_check_error_message( array $resp ) : string {
		if ( ! empty( $resp['err'] ) ) {
			return (string) $resp['err'];
		}

		$code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;
		if ( $code > 0 ) {
			return 'http_' . $code;
		}

		return 'unknown_error';
	}

	private static function is_retryable_check_response( array $resp ) : bool {
		$code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;

		if ( 0 === $code ) {
			return true;
		}

		return 408 === $code || 425 === $code || 429 === $code || $code >= 500;
	}

	private static function get_retry_delay_seconds( int $attempt, array $resp = [] ) : int {
		$code        = isset( $resp['code'] ) ? (int) $resp['code'] : 0;
		$error_code  = isset( $resp['err'] ) ? sanitize_key( (string) $resp['err'] ) : '';
		$retry_after = isset( $resp['retry_after'] ) ? max( 0, (int) $resp['retry_after'] ) : 0;

		if ( 429 === $code && 'rate_limited' === $error_code && $retry_after > 0 ) {
			return min( HOUR_IN_SECONDS, max( 5, $retry_after ) );
		}

		$delays = [
			1 => 5 * MINUTE_IN_SECONDS,
			2 => 15 * MINUTE_IN_SECONDS,
		];

		return $delays[ $attempt ] ?? HOUR_IN_SECONDS;
	}

	private static function schedule_global_check_retry( int $order_id, int $delay ) : int {
		if ( $order_id <= 0 || $delay <= 0 ) {
			return 0;
		}

		try {
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$future = as_get_scheduled_actions( [
					'hook' => 'yogb_gbl_run_check_async', 'args' => [ 'order_id' => $order_id ],
					'group' => 'yogb-global-blacklist', 'status' => 'pending', 'per_page' => 1, 'orderby' => 'date', 'order' => 'ASC',
				], 'ids' );
				if ( $future ) { return (int) ActionScheduler::store()->get_date( reset( $future ) )->getTimestamp(); }
			}
			if ( function_exists( 'as_schedule_single_action' ) ) {
				// The running action is not a future retry; unique=true would suppress it.
				try {
					$at = time() + $delay;
					return as_schedule_single_action( $at, 'yogb_gbl_run_check_async', [ 'order_id' => $order_id ], 'yogb-global-blacklist' ) ? $at : 0;
				} catch ( Throwable $error ) { return 0; }
			}

			$existing = wp_next_scheduled( 'yogb_gbl_run_check_async', [ $order_id ] );
			if ( $existing ) { return (int) $existing; }
			$at = time() + $delay;
			return wp_schedule_single_event( $at, 'yogb_gbl_run_check_async', [ $order_id ] ) ? $at : 0;
		} catch ( Throwable $error ) { return 0; }
	}

	/** Retained compatibility entry point. Global checks run only after checkout. */
	public static function validate_classic_strict( $fields, $errors ) : void {}

	/** Retained compatibility entry point. Never rejects or contacts Global. */
	public static function validate_store_api_strict( $order ) : void {}

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

		if ( self::is_global_check_complete( $order ) || self::has_recent_pending_check( $order ) ) {
			return;
		}

  $start = YOGB_BM_Reconnect_Guard::run( null, static function() use ( $order_id ) {
   $order = wc_get_order( $order_id );
   if ( ! $order instanceof WC_Order || self::is_global_check_complete( $order ) || self::has_recent_pending_check( $order ) ) { return [ 'skip' => true ]; }
   $attempt = self::begin_global_check_attempt( $order );
   return [ 'attempt' => $attempt, 'started_at' => (string) $order->get_meta( self::META_CHECK_STARTED_AT, true ), 'mode' => self::get_decision_mode(), 'authority_token' => (string) ( YOGB_BM_Registrar::auth_recovery_state()['authority_token'] ?? '' ) ];
  } );
  if ( ! empty( $start['skip'] ) ) { return; }
  if ( ! isset( $start['attempt'] ) ) {
   self::record_unavailable_check( $order_id, (string) ( $start['error'] ?? 'reconnect_storage_unavailable' ) );
   return;
  }
  $attempt = (int) $start['attempt'];
  $started_at = (string) $start['started_at'];
		$mode = (string) $start['mode'];
		$resp = YOGB_BM_Check::check_order( $order, (string) $start['authority_token'] );
  // A guard failure has no reusable remote result; retain only a local failure under the attempt's captured authority.
  if ( isset( $resp['error'] ) && ! isset( $resp['authority_context'] ) && ! isset( $resp['local_authority_token'] ) ) {
   $resp = [ 'ok' => false, 'code' => 0, 'err' => $resp['error'], 'local_authority_token' => $start['authority_token'] ];
  }
  $projection = YOGB_BM_Reconnect_Guard::run( $resp['authority_context'] ?? null, static function() use ( $resp, $order, $order_id, $attempt, $mode, $started_at ) {
   // Reload under the existing authority guard: an older request cannot overwrite a recheck.
   $order = wc_get_order( $order_id );
   if ( ! $order instanceof WC_Order || 'pending' !== $order->get_meta( self::META_CHECK_STATUS, true )
    || $attempt !== (int) $order->get_meta( self::META_CHECK_ATTEMPTS, true )
    || $started_at !== (string) $order->get_meta( self::META_CHECK_STARTED_AT, true ) ) { return; }
   if ( ! isset( $resp['authority_context'] ) && ( empty( $resp['local_authority_token'] )
	|| ! hash_equals( (string) $resp['local_authority_token'], (string) ( YOGB_BM_Registrar::auth_recovery_state()['authority_token'] ?? '' ) ) ) ) {
	return [ 'ok' => false, 'error' => 'credential_epoch_changed' ];
   }

		$tier      = isset( $resp['tier'] ) ? (string) $resp['tier'] : '';
		$http_code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;
		$error_code = isset( $resp['err'] ) ? sanitize_key( (string) $resp['err'] ) : '';

		if ( empty( $resp['ok'] ) && 429 === $http_code && in_array( $error_code, [ 'plan_quota_exceeded', 'rate_month' ], true ) ) {
			$order->add_order_note(
				sprintf(
					__( 'Global Blacklist Decisions check skipped: monthly limit exceeded for tier "%1$s" (HTTP %2$d).', 'wc-blacklist-manager' ),
					$tier ?: 'free',
					$http_code
				)
			);
			self::invalidate_current_decision_context( $order );
			$order->update_meta_data( self::META_DECISION, 'skipped_rate_limit' );
			$order->update_meta_data( self::META_TIER, $tier );
			$order->update_meta_data( self::META_CHECK_STATUS, 'skipped_rate_limit' );
			$order->update_meta_data( self::META_CHECKED, 1 );
			$order->update_meta_data( self::META_CHECK_LAST_HTTP_CODE, $http_code );
			$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
			$order->delete_meta_data( self::META_CHECK_LAST_ERROR );

			$tier_safe = $tier ?: 'free';

			YOGB_BM_Check::mark_monthly_limit_reached( $tier_safe, 0, 'order_skip' );

			$order->save();

			do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
			return;
		}

		if ( empty( $resp['ok'] ) ) {
			$error_message = self::get_check_error_message( $resp );
			$max_attempts  = max( 1, (int) apply_filters( 'yogb_gbl_check_max_attempts', 3, $order, $resp ) );
			$will_retry    = $attempt < $max_attempts && self::is_retryable_check_response( $resp );
			$retry_delay   = $will_retry ? self::get_retry_delay_seconds( $attempt, $resp ) : 0;
			$retry_at      = $will_retry ? time() + $retry_delay : 0;

			self::invalidate_current_decision_context( $order );
			$order->update_meta_data( self::META_DECISION, 'check_failed' );
			$order->update_meta_data( self::META_TIER, $tier );
			$order->update_meta_data( self::META_CHECK_STATUS, 'failed' );
			$order->update_meta_data( self::META_CHECK_LAST_ERROR, $error_message );
			$order->update_meta_data( self::META_CHECK_LAST_HTTP_CODE, $http_code );
			$order->delete_meta_data( self::META_CHECKED );

			$retry_requested = $will_retry;
			$retry_at = $will_retry ? self::schedule_global_check_retry( $order_id, $retry_delay ) : 0;
			$will_retry = $retry_at > 0;
			if ( $retry_requested && ! $will_retry ) {
				$order->add_order_note( __( 'The automatic Global Blacklist retry could not be scheduled. Review background action storage.', 'wc-blacklist-manager' ) );
			}
			if ( $will_retry ) {
				$order->update_meta_data( self::META_CHECK_NEXT_RETRY_AT, $retry_at );
				$order->add_order_note(
					sprintf(
						__( 'Global Blacklist Decisions check could not be completed (HTTP %1$d, attempt %2$d/%3$d, error: %4$s). Retrying in %5$d minutes. Order allowed until the check completes.', 'wc-blacklist-manager' ),
						$http_code,
						$attempt,
						$max_attempts,
						$error_message,
						max( 1, (int) ceil( $retry_delay / MINUTE_IN_SECONDS ) )
					)
				);
			} else {
				$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
				$order->add_order_note(
					sprintf(
						__( 'Global Blacklist Decisions check could not be completed (HTTP %1$d, attempt %2$d/%3$d, error: %4$s). No automatic retries remain. Order allowed by default.', 'wc-blacklist-manager' ),
						$http_code,
						$attempt,
						$max_attempts,
						$error_message
					)
				);
			}

			$order->save();

			do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
			return;
		}

		$snapshot  = YOGB_BM_Check::get_decision_snapshot( $resp );
		$decision  = (string) $snapshot['overall'];
		$tier      = '' !== (string) $snapshot['tier'] ? (string) $snapshot['tier'] : $tier;
		$summary   = (string) $snapshot['summary'];
		$reason    = (string) $snapshot['reason_code'];

		if ( empty( $snapshot['decision_valid'] ) ) {
			self::invalidate_current_decision_context( $order );
			$order->update_meta_data( self::META_DECISION, 'unknown' );
			$order->update_meta_data( self::META_TIER, $tier );
			$order->update_meta_data( self::META_CHECK_STATUS, 'success' );
			$order->update_meta_data( self::META_CHECKED, 1 );
			$order->delete_meta_data( self::META_CHECK_LAST_ERROR );
			$order->delete_meta_data( self::META_CHECK_LAST_HTTP_CODE );
			$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
			$order->add_order_note(
				__( 'Global Blacklist Decisions returned a result that could not be interpreted. Order allowed by default.', 'wc-blacklist-manager' )
			);
			$order->save();

			do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
			return;
		}

		$order->update_meta_data( self::META_DECISION, $decision );
		$order->update_meta_data( self::META_TIER, $tier );
		$order->update_meta_data( self::META_DECISION_SUMMARY, $summary );
		$order->update_meta_data( self::META_DECISION_REASON_CODE, $reason );
		$order->update_meta_data( self::META_RESPONSE_SCHEMA, (string) $snapshot['schema'] );
		$order->update_meta_data( self::META_DETAIL_AVAILABLE, ! empty( $snapshot['detail_available'] ) ? 1 : 0 );
		$order->update_meta_data( self::META_STORAGE_PROFILE, 'compact_v1' );
		if ( ! empty( $snapshot['match_summary'] ) ) {
			$order->update_meta_data(
				self::META_MATCH_SUMMARY,
				wp_json_encode( $snapshot['match_summary'], JSON_UNESCAPED_SLASHES )
			);
		} else {
			$order->delete_meta_data( self::META_MATCH_SUMMARY );
		}

		$decision_ref = (string) $snapshot['decision_ref'];
		if ( '' !== $decision_ref ) {
			$order->update_meta_data( self::META_DECISION_REF, $decision_ref );
			$order->update_meta_data( self::META_DECISION_AT, time() );
		} else {
			$order->delete_meta_data( self::META_DECISION_REF );
			$order->delete_meta_data( self::META_DECISION_AT );
		}

		// Do not persist the full server response or per-identity scoring
		// payloads on new checks. The server remains the detailed source of
		// truth; these keys are deleted on recheck to avoid stale diagnostics.
		self::delete_legacy_verbose_meta( $order );

		self::apply_decision_to_order(
			$order,
			$decision,
			$tier,
			$mode,
			$summary,
			$reason
		);

		if ( in_array( $decision, [ 'block', 'challenge' ], true ) ) {
			self::record_global_decision_activity( $order, $decision, $resp, 'async_order_check', 'woo_order_' . (int) $order->get_id(), $mode );
		}

		$order->update_meta_data( '_yogb_gbl_applied_policy', [ 'version' => 2, 'mode' => $mode, 'decision' => $decision, 'attempt' => $attempt, 'started_at' => $started_at ] );
		$order->update_meta_data( self::META_CHECK_STATUS, 'success' );
		$order->update_meta_data( self::META_CHECKED, 1 );
		$order->delete_meta_data( self::META_CHECK_LAST_ERROR );
		$order->delete_meta_data( self::META_CHECK_LAST_HTTP_CODE );
		$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );

		$order->save();

		do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );

  } );
  if ( is_array( $projection ) && ! empty( $projection['error'] ) ) {
   // Queue a fresh request after the pending lease expires. Never replay the discarded response.
   self::record_unavailable_check( $order_id, (string) $projection['error'], $attempt, $started_at );
  }
}

	private static function record_unavailable_check( int $order_id, string $error, int $attempt = 0, string $started_at = '' ) : void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || self::is_global_check_complete( $order ) ) { return; }
		if ( '' !== $started_at && $started_at !== (string) $order->get_meta( self::META_CHECK_STARTED_AT, true ) ) { return; }
		// Do not replace another worker's live attempt or project an untrusted response.
		if ( self::has_recent_pending_check( $order ) && $attempt !== (int) $order->get_meta( self::META_CHECK_ATTEMPTS, true ) ) { return; }
		$attempt = $attempt ?: 1 + (int) $order->get_meta( self::META_CHECK_ATTEMPTS, true );
		$retry_at = $attempt < 3 ? self::schedule_global_check_retry( $order_id, 5 * MINUTE_IN_SECONDS + 1 ) : 0;
		if ( $attempt < 3 && ! $retry_at ) {
			$order->add_order_note( __( 'The automatic Global Blacklist retry could not be scheduled. Review background action storage.', 'wc-blacklist-manager' ) );
		}
		self::invalidate_current_decision_context( $order );
		$order->update_meta_data( self::META_CHECK_ATTEMPTS, $attempt );
		$order->update_meta_data( self::META_CHECK_STATUS, 'unavailable' );
		$order->update_meta_data( self::META_CHECK_LAST_ERROR, sanitize_key( $error ) );
		if ( $retry_at ) { $order->update_meta_data( self::META_CHECK_NEXT_RETRY_AT, $retry_at ); }
		else { $order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT ); }
		$order->save();
		do_action( 'yogb_after_gbl_check', $order_id, 'yogb_gbl_run_check_async' );
	}

	public static function handle_manual_order_check() : void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( $order_id <= 0 ) {
			wp_die( esc_html__( 'Invalid order.', 'wc-blacklist-manager' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'wc-blacklist-manager' ) );
		}

		if (
			! function_exists( 'wc_blacklist_manager_user_can_moderate_order' )
			|| ! wc_blacklist_manager_user_can_moderate_order( $order )
		) {
			wp_die( esc_html__( 'You do not have permission to recheck this order.', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'yogb_gbl_manual_order_check_' . $order_id );

		self::reset_order_check_state( $order );
		$order->save();

		self::run_global_check_async( $order_id );

		wp_safe_redirect(
			add_query_arg(
				[
					'post'   => $order_id,
					'action' => 'edit',
					'yogb_rechecked' => 1,
				],
				admin_url( 'post.php' )
			)
		);
		exit;
	}

	private static function reset_order_check_state( WC_Order $order ) : void {
		// Allow recheck even if a previous async attempt marked it checked or failed.
		self::invalidate_current_decision_context( $order );
		$order->delete_meta_data( self::META_CHECKED );
		$order->delete_meta_data( self::META_CHECK_STATUS );
		$order->delete_meta_data( self::META_CHECK_ATTEMPTS );
		$order->delete_meta_data( self::META_CHECK_STARTED_AT );
		$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
		$order->delete_meta_data( self::META_CHECK_LAST_ERROR );
		$order->delete_meta_data( self::META_CHECK_LAST_HTTP_CODE );
	}

	private static function invalidate_current_decision_context( WC_Order $order ) : void {
		foreach (
			[
				'_yogb_gbl_applied_policy',
				self::META_DECISION,
				self::META_DECISION_REF,
				self::META_DECISION_AT,
				self::META_DECISION_SUMMARY,
				self::META_DECISION_REASON_CODE,
				self::META_RESPONSE_SCHEMA,
				self::META_DETAIL_AVAILABLE,
				self::META_STORAGE_PROFILE,
				self::META_MATCH_SUMMARY,
			] as $meta_key
		) {
			$order->delete_meta_data( $meta_key );
		}
		self::delete_legacy_verbose_meta( $order );
	}

	public static function register_bulk_recheck_action( array $actions ) : array {
		$actions['yogb_gbl_recheck_skipped'] = __( 'Recheck skipped Global Blacklist orders', 'wc-blacklist-manager' );
		return $actions;
	}

	public static function handle_bulk_recheck_action( string $redirect_to, string $action, array $order_ids ) : string {
		if ( 'yogb_gbl_recheck_skipped' !== $action ) {
			return $redirect_to;
		}

		$queued = 0;
		foreach ( array_unique( array_map( 'absint', $order_ids ) ) as $order_id ) {
			if ( $order_id <= 0 || ! current_user_can( 'edit_shop_order', $order_id ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$decision = strtolower( trim( (string) $order->get_meta( self::META_DECISION, true ) ) );
			if ( 'skipped_rate_limit' !== $decision || ! $order->has_status( [ 'pending', 'processing', 'on-hold', 'failed' ] ) ) {
				continue;
			}

			self::reset_order_check_state( $order );
			$order->save();
			self::enqueue_global_check_async( $order );
			$queued++;
		}

		return add_query_arg( 'yogb_gbl_bulk_rechecked', $queued, $redirect_to );
	}

	public static function show_bulk_recheck_notice() : void {
		if ( ! isset( $_GET['yogb_gbl_bulk_rechecked'] ) ) {
			return;
		}

		$count = absint( wp_unslash( $_GET['yogb_gbl_bulk_rechecked'] ) );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of orders queued for Global Blacklist recheck. */
					_n( '%d skipped order queued for recheck.', '%d skipped orders queued for recheck.', $count, 'wc-blacklist-manager' ),
					$count
				)
			)
		);
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

	private static function record_global_decision_activity(
		WC_Order $order,
		string $decision,
		array $resp,
		string $context,
		string $source,
		string $mode
	) : void {
  YOGB_BM_Reconnect_Guard::run( $resp['authority_context'] ?? [], static function() use ( $order, $decision, $resp, $context, $source, $mode ) {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$decision       = sanitize_key( $decision );
		$context        = sanitize_key( $context );
		$source         = sanitize_key( $source );
		$tier           = isset( $resp['tier'] ) ? sanitize_key( (string) $resp['tier'] ) : '';
		$snapshot       = class_exists( 'YOGB_BM_Check' )
			? YOGB_BM_Check::get_decision_snapshot( $resp )
			: [];
		$action         = 'challenge' === $decision ? 'challenge' : 'block';

		$view = array(
			'schema'         => 'yogb_gbl_decision_v2',
			'context'        => $context,
			'mode'           => $mode,
			'decision'       => $decision,
			'tier'           => $tier ?: 'free',
			'decision_ref'   => sanitize_text_field( (string) ( $snapshot['decision_ref'] ?? '' ) ),
			'reason_code'    => sanitize_key( (string) ( $snapshot['reason_code'] ?? '' ) ),
			'summary'        => sanitize_text_field( (string) ( $snapshot['summary'] ?? '' ) ),
			'response_schema'=> sanitize_key( (string) ( $snapshot['schema'] ?? '' ) ),
		);

		$order_id = (int) $order->get_id();
		if ( $order_id > 0 ) {
			$view['order_id'] = $order_id;
		}

		$wpdb->insert(
			$wpdb->prefix . 'wc_blacklist_detection_log',
			array(
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'bot',
				'source'    => $source,
				'action'    => $action,
				'details'   => 'global_blacklist_decision:' . $decision . ' context:' . $context,
				'view'      => wp_json_encode( $view ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
  } );
	}

	/**
	 * Map global decision to WooCommerce order status / notes.
	 *
	 * Decision: 'allow' | 'challenge' | 'block'
	 * Mode:     'light' | 'moderate' | 'strict'
	 *
	 * @param WC_Order $order
	 * @param string   $decision
	 * @param string   $tier
	 * @param string   $mode
	 * @param string   $summary           Compact server explanation.
	 * @param string   $reason_code       Compact machine-readable reason.
	 */
	private static function apply_decision_to_order(
		WC_Order $order,
		string $decision,
		string $tier,
		string $mode,
		string $summary = '',
		string $reason_code = ''
	) : void {
		$note_lines   = [];
		$note_lines[] = sprintf(
			/* translators: 1: decision, 2: tier */
			__( 'Global Blacklist decision: %1$s (tier: %2$s).', 'wc-blacklist-manager' ),
			$decision,
			$tier ?: 'free'
		);
		if ( '' !== $summary ) {
			$note_lines[] = $summary;
		} elseif ( '' !== $reason_code ) {
			$note_lines[] = sprintf(
				__( 'Reason: %s.', 'wc-blacklist-manager' ),
				ucwords( str_replace( '_', ' ', $reason_code ) )
			);
		}

		$note = implode( "\n", $note_lines );

		if ( 'strict' !== $mode ) {
			$order->add_order_note( $note );
			return;
		}

		switch ( $decision ) {
			case 'block':
				if ( class_exists( 'YOGB_BM_Outcomes' ) ) {
					YOGB_BM_Outcomes::mark_system_status_change( (int) $order->get_id(), 'cancelled' );
				}
				$order->set_status(
					'cancelled',
					__( 'Order cancelled: blocked by Global Blacklist Decisions.', 'wc-blacklist-manager' )
				);
				$order->add_order_note( $note );
				$order->update_meta_data( '_yogb_gbl_blocked', '1' );
				break;

			case 'challenge':
				if ( $order->has_status( [ 'pending', 'processing' ] ) ) {
					if ( class_exists( 'YOGB_BM_Outcomes' ) ) {
						YOGB_BM_Outcomes::mark_system_status_change( (int) $order->get_id(), 'on-hold' );
					}
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
		YOGB_BM_Recovery_Store::annotate_order( $order );
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

	/**
	 * Meta written by versions before compact_v1.
	 *
	 * @return string[]
	 */
	public static function legacy_verbose_meta_keys() : array {
		return [
			self::META_REASONS,
			self::META_RAW,
			self::META_REASON_SUMMARIES,
			self::META_REPORT_SUMMARIES,
			self::META_SIGNAL_SUMMARIES,
			self::META_EFFECTIVE_SCORE,
			self::META_DIRECT_SCORE,
			self::META_LINKED_BOOST,
			self::META_LINKED_NEIGHBORS_COUNT,
			self::META_MATCHED_IDENTITIES,
			self::META_PRIMARY_SIGNAL_TYPE,
			self::META_PRIMARY_RISK_LEVEL,
			self::META_PRIMARY_LAST_REPORTED,
			self::META_MATCHED_IDENTITY_NODES,
			self::META_PRIMARY_MATCH_MODE,
			self::META_PRIMARY_MATCHED_VARIANT,
			self::META_PRIMARY_MATCHED_IDENTITY_COUNT,
		];
	}

	public static function delete_legacy_verbose_meta( WC_Order $order ) : void {
		foreach ( self::legacy_verbose_meta_keys() as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}
	}
}

YOGB_BM_Check_Orders::init();
