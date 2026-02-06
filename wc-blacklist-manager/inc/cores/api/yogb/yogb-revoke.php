<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class YOGB_BM_Revoke_Report {

	// Async worker hook (similar pattern to YOGB_BM_Report)
	const CRON_HOOK = 'yogb_bm_revoke_report';

	// Order meta that stores remote report IDs.
	// Expected values: array of public report IDs (e.g. "rpt_123") or legacy ints.
	const META_REPORT_IDS = '_yogb_gbl_report_ids';

	/**
	 * Queue revoke calls for all reports linked to this order.
	 *
	 * @param WC_Order $order
	 * @param string   $reason  Human/enum reason (e.g. 'customer_appeal')
	 * @param string   $note    Optional note
	 */
	public static function queue_revoke_for_order( WC_Order $order, string $reason, string $note = '' ) : void {
		// No credentials → nothing to do.
		if ( ! YOGB_BM_Report::is_ready() ) {
			return;
		}

		$report_ids = $order->get_meta( self::META_REPORT_IDS, true );

		// Expecting either an array of IDs or a single ID.
		if ( empty( $report_ids ) ) {
			return;
		}

		if ( ! is_array( $report_ids ) ) {
			$report_ids = [ $report_ids ];
		}

		// Normalize each stored ID to a numeric internal ID (e.g. 123).
		$numeric_ids = array_filter(
			array_map(
				static function( $raw ) : int {
					return self::extract_numeric_report_id( $raw );
				},
				$report_ids
			),
			static function( int $v ) : bool {
				return $v > 0;
			}
		);

		if ( empty( $numeric_ids ) ) {
			return;
		}

		$payload = [
			'reason' => self::normalize_reason( $reason ),
			'note'   => mb_substr( (string) $note, 0, 255 ),
		];

		foreach ( $numeric_ids as $remote_id ) {
			self::queue_single_revoke( $remote_id, $payload );
		}
	}

	/**
	 * Queue revoke for a single remote report ID.
	 *
	 * @param int|string $report_id Remote server report ID (may be "rpt_123" or "123" or 123).
	 * @param array      $payload   ['reason' => string, 'note' => string]
	 */
	public static function queue_single_revoke( $report_id, array $payload ) : void {
		$numeric_id = self::extract_numeric_report_id( $report_id );
		if ( $numeric_id <= 0 ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[YOGB][revoke] queue_single_revoke got invalid report_id=' . var_export( $report_id, true ) );
			}
			return;
		}

		$key = md5(
			wp_json_encode(
				[
					$numeric_id,
					$payload['reason'] ?? '',
					$payload['note']   ?? '',
				]
			)
		);

		// Store payload in a transient so the cron worker can pick it up.
		$transient_key = 'yogb_bm_revoke_' . $key;
		set_transient(
			$transient_key,
			[
				'report_id' => $numeric_id,
				'payload'   => $payload,
			],
			10 * MINUTE_IN_SECONDS
		);

		// Avoid duplicate schedules for the same (report_id + payload).
		if ( ! wp_next_scheduled( self::CRON_HOOK, [ $key ] ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ $key ] );
		}
	}

	/**
	 * Worker: sends the revoke call for one report ID.
	 *
	 * @param string $key Transient key suffix (hash)
	 */
	public static function cron_revoke_report( string $key ) : void {
		$transient_key = 'yogb_bm_revoke_' . $key;
		$data          = get_transient( $transient_key );

		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $transient_key );

		$numeric_id = isset( $data['report_id'] )
			? self::extract_numeric_report_id( $data['report_id'] )
			: 0;

		$payload = isset( $data['payload'] ) ? (array) $data['payload'] : [];

		if ( $numeric_id <= 0 || empty( $payload ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[YOGB][revoke] cron_revoke_report: invalid report_id or empty payload' );
			}
			return;
		}

		// Path uses numeric ID so it matches /reports/(?P<id>\d+)/revoke on the server.
		$route = YOGB_BM_Report::REST_ROUTE . '/reports/' . $numeric_id . '/revoke';

		$res = YOGB_BM_Report::post_json_signed( $route, $payload );

		// Simple retry on transient server errors.
		if ( empty( $res['ok'] ) && in_array( (int) ( $res['code'] ?? 0 ), [ 429, 500, 502, 503, 504 ], true ) ) {
			$retry_key = md5(
				wp_json_encode(
					[
						$numeric_id,
						$payload,
						'retry',
					]
				)
			);

			$retry_transient = 'yogb_bm_revoke_' . $retry_key;
			set_transient(
				$retry_transient,
				[
					'report_id' => $numeric_id,
					'payload'   => $payload,
				],
				10 * MINUTE_IN_SECONDS
			);

			if ( ! wp_next_scheduled( self::CRON_HOOK, [ $retry_key ] ) ) {
				wp_schedule_single_event( time() + 60, self::CRON_HOOK, [ $retry_key ] );
			}
		}

		// Optional debug logging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// For logs we show the public form "rpt_<id>" to match the rest of the UI.
			$public_id = 'rpt_' . $numeric_id;

			error_log(
				sprintf(
					'[YOGB] revoke report_id=%s code=%d ok=%s',
					$public_id,
					(int) ( $res['code'] ?? 0 ),
					! empty( $res['ok'] ) ? '1' : '0'
				)
			);
		}
	}

	/**
	 * Normalize revoke reason to one of the supported enums.
	 *
	 * Server side we expect strings like:
	 *  - customer_appeal
	 *  - merchant_error
	 *  - processor_decision
	 *  - duplicate
	 *  - test_data
	 *  - other
	 */
	private static function normalize_reason( string $reason ) : string {
		$r = strtolower( sanitize_key( $reason ) );

		$allowed = [
			'customer_appeal',
			'merchant_error',
			'processor_decision',
			'duplicate',
			'test_data',
			'other',
		];

		if ( in_array( $r, $allowed, true ) ) {
			return $r;
		}

		// Some common aliases → map to enums.
		switch ( $r ) {
			case 'customer':
			case 'appeal':
			case 'dispute_resolved':
				return 'customer_appeal';

			case 'mistake':
			case 'manual_error':
			case 'typo':
				return 'merchant_error';

			case 'psp':
			case 'gateway':
				return 'processor_decision';

			case 'dup':
			case 'duplicate_order':
				return 'duplicate';

			case 'test':
			case 'sandbox':
				return 'test_data';

			default:
				return 'other';
		}
	}

	/**
	 * Extract a numeric report ID from various forms:
	 *  - "rpt_123"
	 *  - "123"
	 *  - 123
	 *
	 * Returns the integer ID, or 0 if invalid.
	 *
	 * @param mixed $id
	 * @return int
	 */
	private static function extract_numeric_report_id( $id ) : int {
		// Int already?
		if ( is_int( $id ) ) {
			return $id > 0 ? $id : 0;
		}

		if ( ! is_scalar( $id ) ) {
			return 0;
		}

		$raw = trim( (string) $id );
		if ( $raw === '' ) {
			return 0;
		}

		// Strip "rpt_" prefix if present.
		if ( strpos( $raw, 'rpt_' ) === 0 ) {
			$raw = substr( $raw, 4 );
		}

		// Trim whitespace + leading zeros.
		$raw = ltrim( $raw, " \t\n\r\0\x0B0" );
		if ( $raw === '' ) {
			return 0;
		}

		if ( ! ctype_digit( $raw ) ) {
			return 0;
		}

		$val = (int) $raw;
		return $val > 0 ? $val : 0;
	}
}

// Register the worker
add_action( YOGB_BM_Revoke_Report::CRON_HOOK, [ 'YOGB_BM_Revoke_Report', 'cron_revoke_report' ] );
