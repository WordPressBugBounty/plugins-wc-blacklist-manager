<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable client-side delivery queue for Global Blacklist writes. */
final class YOGB_BM_Outbox {
	const ACTION_HOOK = 'yogb_bm_process_outbox_item';
	const SWEEP_HOOK  = 'yogb_bm_process_outbox_due';
	const AUTH_RESUME_HOOK = 'yogb_bm_resume_auth_paused_outbox';
	const AUTH_RESUME_BATCH = 20;
	const GROUP       = 'yogb-global-blacklist';

	private static $table_ready = false;

	public static function init() : void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		add_action( 'init', [ __CLASS__, 'schedule_sweep' ] );
		add_action( self::ACTION_HOOK, [ __CLASS__, 'process_action' ], 10, 1 );
		add_action( self::SWEEP_HOOK, [ __CLASS__, 'process_due' ] );
		add_action( self::AUTH_RESUME_HOOK, [ __CLASS__, 'resume_after_auth' ] );
	}

	public static function add_schedule( array $schedules ) : array {
		if ( ! isset( $schedules['yogb_five_minutes'] ) ) {
			$schedules['yogb_five_minutes'] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every Five Minutes (YOGB)', 'wc-blacklist-manager' ),
			];
		}
		return $schedules;
	}

	public static function schedule_sweep() : void {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'yogb_five_minutes', self::SWEEP_HOOK );
		}
	}

	private static function auth_is_paused() : bool {
		return class_exists( 'YOGB_BM_Registrar' ) && YOGB_BM_Registrar::is_auth_paused();
	}

	public static function enqueue_report( array $payload, string $idempotency, int $order_id ) : int {
		return self::enqueue(
			'report',
			YOGB_BM_Report::REST_ROUTE . '/reports',
			$payload,
			[ 'Idempotency-Key' => $idempotency ],
			$order_id,
			hash( 'sha256', 'report|' . $idempotency )
		);
	}

	/** Persist one immutable v2 request body without changing its bytes on replay. */
	public static function enqueue_report_v2( string $body, string $idempotency, int $order_id, string $candidate_id, string $payload_hash, int $captured_at ) : int {
		global $wpdb;
		if ( ! self::ensure_table() || '' === $body || hash( 'sha256', $body ) !== $payload_hash || $captured_at <= 0 ) {
			return 0;
		}
		$encoded = self::encode_raw_payload( $body );
		if ( '' === $encoded ) {
			return 0;
		}
		$table     = self::table();
		$event_key = hash( 'sha256', 'report_v2|' . $idempotency );
		$headers   = [
			'Idempotency-Key' => $idempotency,
			'__yogb_v2'       => [
				'candidate_id' => substr( sanitize_text_field( $candidate_id ), 0, 64 ),
				'payload_hash' => $payload_hash,
				'captured_at'  => $captured_at,
			],
		];
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,payload_json FROM {$table} WHERE event_key=%s LIMIT 1", $event_key ), ARRAY_A );
		if ( $existing ) {
			$existing_body = self::decode_raw_payload( (string) $existing['payload_json'] );
			if ( ! is_string( $existing_body ) || ! hash_equals( hash( 'sha256', $existing_body ), $payload_hash ) || ! hash_equals( $existing_body, $body ) ) {
				do_action( 'yogb_bm_outbox_event', 'local_conflict', [ 'type' => 'report_v2' ] );
				return 0;
			}
			$id = (int) $existing['id'];
			if ( 'deferred' === (string) $existing['status'] && ! self::auth_is_paused() && class_exists( 'YOGB_BM_Report_V2' ) && YOGB_BM_Report_V2::supports_v2() ) {
				$resumed = $wpdb->update(
					$table,
					[ 'status' => 'pending', 'next_attempt_at' => null, 'updated_at' => current_time( 'mysql', true ) ],
					[ 'id' => $id, 'event_type' => 'report_v2', 'status' => 'deferred' ]
				);
				if ( 1 === $resumed ) {
					self::schedule_item( $id, 3 );
				}
			}
			return $id;
		}

		$fresh  = class_exists( 'YOGB_BM_Report_V2' ) && YOGB_BM_Report_V2::supports_v2();
		$status = ! $fresh ? 'deferred' : ( self::auth_is_paused() ? 'auth_paused' : 'pending' );
		$now    = current_time( 'mysql', true );
		$ok = $wpdb->insert(
			$table,
			[
				'event_key'       => $event_key,
				'event_type'      => 'report_v2',
				'route'           => YOGB_BM_Report::REST_ROUTE . '/reports',
				'payload_json'    => $encoded,
				'headers_json'    => wp_json_encode( $headers ),
				'order_id'        => max( 0, $order_id ),
				'attempts'        => 0,
				'max_attempts'    => 8,
				'status'          => $status,
				'next_attempt_at' => 'deferred' === $status ? gmdate( 'Y-m-d H:i:s', time() + 300 ) : null,
				'last_http_code'  => 0,
				'last_error'      => '',
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);
		if ( false === $ok ) {
			$raced = $wpdb->get_row( $wpdb->prepare( "SELECT id,payload_json FROM {$table} WHERE event_key=%s LIMIT 1", $event_key ), ARRAY_A );
			$raced_body = is_array( $raced ) ? self::decode_raw_payload( (string) $raced['payload_json'] ) : null;
			if ( ! is_string( $raced_body ) || ! hash_equals( $raced_body, $body ) ) {
				do_action( 'yogb_bm_outbox_event', 'local_conflict', [ 'type' => 'report_v2' ] );
				return 0;
			}
			$id = (int) $raced['id'];
		} else {
			$id = (int) $wpdb->insert_id;
		}
		if ( $id > 0 && 'pending' === $status ) {
			self::schedule_item( $id, 3 );
		} elseif ( $id > 0 && 'deferred' === $status && class_exists( 'YOGB_BM_Report_V2' ) ) {
			YOGB_BM_Report_V2::schedule_capability_refresh();
		}
		return $id;
	}

	public static function enqueue_revoke( string $route, array $payload ) : int {
		return self::enqueue(
			'revoke',
			$route,
			$payload,
			[],
			0,
			hash( 'sha256', 'revoke|' . $route . '|' . self::canonical_json( $payload ) )
		);
	}
	public static function enqueue_outcome( array $payload, int $order_id ) : int {
		return self::enqueue_outcome_route( $payload, $order_id, '/decision/outcomes' );
	}

	public static function enqueue_outcome_v2( array $payload, int $order_id ) : int {
		return self::enqueue_outcome_route( $payload, $order_id, '/decision/outcomes/v2' );
	}

	private static function enqueue_outcome_route( array $payload, int $order_id, string $suffix ) : int {
		$uuid = (string) ( $payload['event_uuid'] ?? '' );
		return self::enqueue(
			'outcome',
			YOGB_BM_Report::REST_ROUTE . $suffix,
			$payload,
			[ 'Idempotency-Key' => $uuid ],
			$order_id,
			hash( 'sha256', 'outcome|' . $uuid )
		);
	}

	public static function retry_outcome( string $event_uuid, int $order_id ) : bool {
		global $wpdb;
		if ( ! wp_is_uuid( $event_uuid, 4 ) || $order_id <= 0 || ! self::ensure_table() ) {
			return false;
		}
		$table = self::table();
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE event_key=%s AND event_type='outcome' AND order_id=%d
				AND payload_json<>'' LIMIT 1",
				hash( 'sha256', 'outcome|' . $event_uuid ),
				$order_id
			)
		);
		if ( $id <= 0 ) {
			return false;
		}
		$status  = self::auth_is_paused() ? 'auth_paused' : 'pending';
		$updated = $wpdb->update(
			$table,
			[
				'status'          => $status,
				'attempts'        => 0,
				'next_attempt_at' => null,
				'last_http_code'  => 0,
				'last_error'      => '',
				'updated_at'      => current_time( 'mysql', true ),
			],
			[ 'id' => $id, 'event_type' => 'outcome' ]
		);
		if ( false === $updated ) {
			return false;
		}
		if ( 'pending' === $status ) {
			self::schedule_item( $id, 3 );
		}
		return true;
	}

	private static function enqueue( string $type, string $route, array $payload, array $headers, int $order_id, string $event_key ) : int {
		global $wpdb;
		if ( ! self::ensure_table() ) {
			return 0;
		}

		$table   = self::table();
		$now     = current_time( 'mysql', true );
		$status  = self::auth_is_paused() ? 'auth_paused' : 'pending';
		$requires_encryption = 'outcome' === $type && ! empty( $payload['evidence_reference'] );
		$encoded = self::encode_payload( $payload, $requires_encryption );
		if ( '' === $encoded ) {
			return 0;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id,status FROM {$table} WHERE event_key=%s LIMIT 1", $event_key ),
			ARRAY_A
		);
		if ( $existing ) {
			$id = (int) $existing['id'];
			if ( 'dead' === (string) $existing['status'] ) {
				$wpdb->update(
					$table,
					[
						'payload_json'    => $encoded,
						'headers_json'    => wp_json_encode( $headers ),
						'attempts'        => 0,
						'status'          => $status,
						'next_attempt_at' => null,
						'last_http_code'  => 0,
						'last_error'      => '',
						'updated_at'      => $now,
					],
					[ 'id' => $id ]
				);
			}
			if ( self::auth_is_paused() && in_array( (string) $existing['status'], [ 'pending', 'retry' ], true ) ) {
				$wpdb->update( $table, [ 'status' => 'auth_paused', 'next_attempt_at' => null, 'last_error' => 'auth_paused', 'updated_at' => $now ], [ 'id' => $id, 'status' => (string) $existing['status'] ] );
			}
			if ( ! self::auth_is_paused() && 'auth_paused' !== (string) $existing['status'] ) {
				self::schedule_item( $id, 3 );
			}
			return $id;
		}

		$ok = $wpdb->insert(
			$table,
			[
				'event_key'       => $event_key,
				'event_type'      => $type,
				'route'           => $route,
				'payload_json'    => $encoded,
				'headers_json'    => wp_json_encode( $headers ),
				'order_id'        => max( 0, $order_id ),
				'attempts'        => 0,
				'max_attempts'    => 8,
				'status'          => $status,
				'next_attempt_at' => null,
				'last_http_code'  => 0,
				'last_error'      => '',
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		if ( false === $ok ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_key=%s", $event_key ) );
		} else {
			$id = (int) $wpdb->insert_id;
		}
		if ( $id > 0 && 'pending' === $status ) {
			self::schedule_item( $id, 3 );
		}
		return $id;
	}

	public static function process_action( $outbox_id ) : void {
		self::process_id( absint( $outbox_id ) );
	}

	public static function process_due() : void {
		global $wpdb;
		if ( ! self::ensure_table() ) {
			return;
		}
		$table = self::table();
		if ( self::auth_is_paused() ) {
			$wpdb->query(
				"UPDATE {$table} SET status='auth_paused',next_attempt_at=NULL,last_error='auth_paused_stale_claim'
				WHERE status='processing' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)"
			);
			self::pause_for_auth();
		} else {
			$wpdb->query(
				"UPDATE {$table} SET status='retry',next_attempt_at=UTC_TIMESTAMP(),last_error='stale_claim_recovered'
				WHERE status='processing' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)"
			);
			self::resume_after_auth();
		}
		$ids = $wpdb->get_col(
			"SELECT id FROM {$table}
			WHERE status IN ('pending','retry','deferred') AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())
			ORDER BY id ASC LIMIT 20"
		);
		foreach ( (array) $ids as $id ) {
			self::process_id( (int) $id );
		}
		$wpdb->query( "DELETE FROM {$table} WHERE status='dead' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)" );
	}

	private static function process_id( int $id ) : void {
		global $wpdb;
		if ( $id <= 0 || ! self::ensure_table() ) {
			return;
		}
		$table = self::table();
		$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
		if ( ! $before ) {
			return;
		}
		if ( self::auth_is_paused() ) {
			if ( in_array( (string) $before['status'], [ 'pending', 'retry', 'processing' ], true ) ) {
				self::mark_auth_paused_if_state( $id, (string) $before['status'], 0, 'auth_paused' );
			}
			return;
		}
		if ( 'report_v2' === (string) $before['event_type'] && ! self::prepare_v2_for_delivery( $before ) ) {
			return;
		}
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='processing',attempts=attempts+1,updated_at=UTC_TIMESTAMP()
				WHERE id=%d AND status IN ('pending','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())",
				$id
			)
		);
		if ( 1 !== $claimed ) {
			return;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$is_v2   = 'report_v2' === (string) $row['event_type'];
		$raw_body = $is_v2 ? self::decode_raw_payload( (string) $row['payload_json'] ) : null;
		$payload = $is_v2 ? ( is_string( $raw_body ) ? json_decode( $raw_body, true ) : null ) : self::decode_payload( (string) $row['payload_json'] );
		$headers = json_decode( (string) $row['headers_json'], true );
		if ( ! is_array( $payload ) || ! is_array( $headers ) ) {
			self::project_outcome_decode_failure( $row );
			self::mark_dead( $id, 0, 'payload_decode_failed', true );
			return;
		}
		$outcome_ref  = 'outcome' === (string) $row['event_type'] ? sanitize_text_field( (string) ( $payload['decision_ref'] ?? '' ) ) : '';
		$outcome_uuid = 'outcome' === (string) $row['event_type'] ? strtolower( sanitize_text_field( (string) ( $payload['event_uuid'] ?? '' ) ) ) : '';
		if ( 'outcome' === (string) $row['event_type'] ) {
			self::store_outcome_delivery( (int) $row['order_id'], 'sending', $outcome_ref, $outcome_uuid, 0, '', (int) $row['attempts'], '' );
		}

		$local_v2 = is_array( $headers ) && isset( $headers['__yogb_v2'] ) ? (array) $headers['__yogb_v2'] : [];
		unset( $headers['__yogb_v2'] );
		if ( $is_v2 && ( ! is_string( $raw_body ) || ! hash_equals( (string) ( $local_v2['payload_hash'] ?? '' ), hash( 'sha256', $raw_body ) ) ) ) {
			self::mark_dead( $id, 0, 'payload_hash_mismatch', true );
			return;
		}
		$res  = $is_v2
			? YOGB_BM_Report::post_raw_json_signed( (string) $row['route'], (string) $raw_body, $headers, $payload, true )
			: YOGB_BM_Report::post_json_signed( (string) $row['route'], $payload, $headers );
		$code = (int) ( $res['code'] ?? 0 );
		if ( ! empty( $res['ok'] ) ) {
			if ( in_array( (string) $row['event_type'], [ 'report', 'report_v2' ], true ) ) {
				self::store_report_id( (int) $row['order_id'], (string) ( $res['body'] ?? '' ) );
			} elseif ( 'outcome' === (string) $row['event_type'] ) {
				self::store_outcome_delivery( (int) $row['order_id'], 'delivered', $outcome_ref, $outcome_uuid, $code, '', (int) $row['attempts'], '' );
			}
			$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
			do_action( 'yogb_bm_outbox_event', 'delivered', [ 'type' => (string) $row['event_type'], 'code' => $code, 'attempts' => (int) $row['attempts'] ] );
			return;
		}

		$attempts = (int) $row['attempts'];
		$error    = self::response_error_code( $res );
		$observed_fingerprint = isset( $res['credential_fingerprint'] ) ? (string) $res['credential_fingerprint'] : '';
		$current_fingerprint  = class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::credential_fingerprint() : '';
		$stale_auth_epoch = 401 === $code
			&& '' !== $observed_fingerprint
			&& '' !== $current_fingerprint
			&& ! hash_equals( $current_fingerprint, $observed_fingerprint );
		if ( $stale_auth_epoch && ! self::auth_is_paused() ) {
			$next = gmdate( 'Y-m-d H:i:s', time() + 3 );
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status='retry',attempts=IF(attempts>0,attempts-1,0),next_attempt_at=%s,
						last_http_code=%d,last_error='stale_auth_epoch',updated_at=UTC_TIMESTAMP()
					WHERE id=%d AND status='processing'",
					$next,
					$code,
					$id
				)
			);
			if ( 1 === $updated ) {
				self::schedule_item( $id, 3 );
			}
			if ( 'outcome' === (string) $row['event_type'] ) {
				self::store_outcome_delivery( (int) $row['order_id'], 'retrying', $outcome_ref, $outcome_uuid, $code, 'stale_auth_epoch', max( 0, $attempts - 1 ), $next );
			}
			do_action( 'yogb_bm_outbox_event', 'stale_auth_epoch_retry', [ 'type' => (string) $row['event_type'], 'code' => $code ] );
			return;
		}
		if ( 401 === $code || 'auth_paused' === $error ) {
			self::mark_auth_paused_if_state( $id, 'processing', $code, 'auth_paused' );
			if ( 'outcome' === (string) $row['event_type'] ) {
				self::store_outcome_delivery( (int) $row['order_id'], 'auth_paused', $outcome_ref, $outcome_uuid, $code, 'auth_paused', max( 0, $attempts - 1 ), '' );
			}
			return;
		}
		if ( $is_v2 && 409 === $code && 'report_v2_unavailable' === $error ) {
			do_action( 'yogb_bm_outbox_event', 'deferred', [ 'type' => 'report_v2', 'code' => 409, 'error_code' => 'report_v2_unavailable', 'attempts' => $attempts ] );
			if ( class_exists( 'YOGB_BM_Report_V2' ) ) {
				YOGB_BM_Report_V2::invalidate_capability();
			}
			if ( self::v2_expired( $local_v2 ) ) {
				self::mark_dead( $id, $code, 'v2_capability_expired', true );
			} else {
				$wpdb->update( $table, [ 'status' => 'deferred', 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'last_http_code' => $code, 'last_error' => $error, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
			}
			return;
		}
		if ( $attempts >= (int) $row['max_attempts'] || ! self::is_retryable( $res ) ) {
			if ( 'outcome' === (string) $row['event_type'] ) {
				$status = 401 === $code
					? 'auth_failed'
					: ( $code >= 400 && $code < 500 ? 'rejected' : 'failed' );
				self::store_outcome_delivery( (int) $row['order_id'], $status, $outcome_ref, $outcome_uuid, $code, $error, $attempts, '' );
			}
			self::mark_dead( $id, $code, $error, 'outcome' !== (string) $row['event_type'] );
			return;
		}

		$delay = self::retry_delay( $attempts, (int) ( $res['retry_after'] ?? 0 ) );
		$next  = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$wpdb->update(
			$table,
			[
				'status'          => 'retry',
				'next_attempt_at' => $next,
				'last_http_code'  => $code,
				'last_error'      => substr( sanitize_key( $error ), 0, 255 ),
				'updated_at'      => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
		self::schedule_item( $id, $delay );
		if ( 'outcome' === (string) $row['event_type'] ) {
			self::store_outcome_delivery( (int) $row['order_id'], 'retrying', $outcome_ref, $outcome_uuid, $code, $error, $attempts, $next );
		}
		do_action( 'yogb_bm_outbox_event', 'retry', [ 'type' => (string) $row['event_type'], 'code' => $code, 'attempts' => $attempts ] );
	}

	/** Pause only claimable durable delivery; capability-deferred v2 rows remain deferred. */
	public static function pause_for_auth() : int {
		global $wpdb;
		if ( ! self::ensure_table() ) {
			return 0;
		}
		$updated = $wpdb->query(
			"UPDATE " . self::table() . " SET status='auth_paused',next_attempt_at=NULL,last_error='auth_paused',updated_at=UTC_TIMESTAMP()
			WHERE status IN ('pending','retry')"
		);
		if ( false === $updated ) {
			return 0;
		}
		do_action( 'yogb_bm_outbox_event', 'auth_paused', [ 'count' => (int) $updated ] );
		return (int) $updated;
	}

	private static function schedule_auth_resume( int $delay = 30 ) : void {
		if ( ! wp_next_scheduled( self::AUTH_RESUME_HOOK ) ) {
			wp_schedule_single_event( time() + max( 10, $delay ), self::AUTH_RESUME_HOOK );
		}
	}

	/** Resume one bounded batch; route v2 through its existing capability gate again. */
	public static function resume_after_auth() : int {
		global $wpdb;
		if ( self::auth_is_paused() || ! self::ensure_table() ) {
			return 0;
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,event_type FROM {$table} WHERE status='auth_paused' ORDER BY id ASC LIMIT %d",
				self::AUTH_RESUME_BATCH
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return 0;
		}
		// Persist continuation before mutating rows. The periodic sweep is the
		// reconciliation fallback if this event cannot be scheduled or is lost.
		self::schedule_auth_resume();
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id      = (int) $row['id'];
			$is_v2   = 'report_v2' === (string) $row['event_type'];
			$status  = $is_v2 ? 'deferred' : 'pending';
			$updated = $wpdb->update(
				$table,
				[ 'status' => $status, 'next_attempt_at' => null, 'last_http_code' => 0, 'last_error' => '', 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $id, 'status' => 'auth_paused' ]
			);
			if ( 1 === $updated ) {
				$count++;
				self::schedule_item( $id, 3 );
			}
		}
		do_action( 'yogb_bm_outbox_event', 'auth_resumed', [ 'count' => $count ] );
		return $count;
	}

	private static function mark_auth_paused_if_state( int $id, string $status, int $code, string $error ) : bool {
		global $wpdb;
		$attempts_sql = 'processing' === $status ? 'IF(attempts>0,attempts-1,0)' : 'attempts';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . "
				SET status='auth_paused',next_attempt_at=NULL,last_http_code=%d,last_error=%s,updated_at=UTC_TIMESTAMP(),
					attempts={$attempts_sql}
				WHERE id=%d AND status=%s",
				$code,
				substr( sanitize_key( $error ), 0, 255 ),
				$id,
				$status
			)
		);
		if ( 1 === $updated ) {
			do_action( 'yogb_bm_outbox_event', 'auth_paused', [ 'code' => $code ] );
			return true;
		}
		return false;
	}

	private static function store_outcome_delivery(
		int $order_id,
		string $status,
		string $decision_ref,
		string $event_uuid,
		int $http_code = 0,
		string $error = '',
		int $attempts = 0,
		string $next_at = ''
	) : void {
		if ( $order_id <= 0
			|| ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $decision_ref )
			|| ! wp_is_uuid( $event_uuid, 4 )
			|| ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		YOGB_BM_Decision_Actions::store_outcome_delivery_state(
			$order,
			$decision_ref,
			$event_uuid,
			$status,
			$http_code,
			$error,
			$attempts,
			$next_at
		);
	}

	private static function project_outcome_decode_failure( array $row ) : void {
		if ( 'outcome' !== (string) ( $row['event_type'] ?? '' ) || empty( $row['order_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $row['order_id'] );
		if ( ! $order ) {
			return;
		}
		$decision_ref = (string) $order->get_meta( YOGB_BM_Decision_Actions::META_OUTCOME_DECISION_REF, true );
		$event_uuid   = strtolower( (string) $order->get_meta( YOGB_BM_Decision_Actions::META_OUTCOME_UUID, true ) );
		$event_key    = (string) ( $row['event_key'] ?? '' );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $decision_ref )
			|| ! wp_is_uuid( $event_uuid, 4 )
			|| ! hash_equals( hash( 'sha256', 'outcome|' . $event_uuid ), $event_key ) ) {
			return;
		}
		YOGB_BM_Decision_Actions::store_outcome_delivery_state(
			$order,
			$decision_ref,
			$event_uuid,
			'queue_failed',
			0,
			'payload_decode_failed',
			max( 0, (int) ( $row['attempts'] ?? 0 ) ),
			''
		);
	}

	private static function prepare_v2_for_delivery( array $row ) : bool {
		global $wpdb;
		$headers = json_decode( (string) ( $row['headers_json'] ?? '' ), true );
		$meta    = is_array( $headers ) ? (array) ( $headers['__yogb_v2'] ?? [] ) : [];
		$id      = (int) ( $row['id'] ?? 0 );
		$status  = (string) ( $row['status'] ?? '' );
		if ( $id <= 0 || ! in_array( $status, [ 'pending', 'retry', 'deferred' ], true ) ) {
			return false;
		}
		if ( self::v2_expired( $meta ) ) {
			self::mark_v2_dead_if_state( $id, $status, 'v2_capability_expired' );
			return false;
		}
		if ( ! class_exists( 'YOGB_BM_Report_V2' ) || ! YOGB_BM_Report_V2::supports_v2() ) {
			$wpdb->update(
				self::table(),
				[
					'status'          => 'deferred',
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
					'last_error'      => 'v2_capability_unavailable',
					'updated_at'      => current_time( 'mysql', true ),
				],
				[ 'id' => $id, 'event_type' => 'report_v2', 'status' => $status ]
			);
			if ( class_exists( 'YOGB_BM_Report_V2' ) ) {
				YOGB_BM_Report_V2::schedule_capability_refresh();
			}
			return false;
		}
		if ( 'deferred' === $status ) {
			$resumed = $wpdb->update(
				self::table(),
				[ 'status' => 'pending', 'next_attempt_at' => null, 'last_error' => '', 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $id, 'event_type' => 'report_v2', 'status' => 'deferred' ]
			);
			return 1 === $resumed;
		}
		return true;
	}

	private static function v2_expired( array $meta ) : bool {
		$captured_at = (int) ( $meta['captured_at'] ?? 0 );
		return $captured_at <= 0 || time() - $captured_at >= YOGB_BM_Report_V2::CANDIDATE_RETENTION_SECONDS;
	}

	/** Scrub an expired v2 candidate only while it remains in the observed claimable state. */
	private static function mark_v2_dead_if_state( int $id, string $status, string $error ) : bool {
		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			[
				'status'          => 'dead',
				'next_attempt_at' => null,
				'last_http_code'  => 0,
				'last_error'      => substr( sanitize_key( $error ), 0, 255 ),
				'updated_at'      => current_time( 'mysql', true ),
				'payload_json'    => '',
				'headers_json'    => '',
			],
			[ 'id' => $id, 'event_type' => 'report_v2', 'status' => $status ]
		);
		if ( 1 === $updated ) {
			do_action( 'yogb_bm_outbox_event', 'dead', [ 'code' => 0, 'error_code' => sanitize_key( $error ) ] );
			return true;
		}
		return false;
	}

	private static function mark_dead( int $id, int $code, string $error, bool $clear_payload = true ) : void {
		global $wpdb;
		$values = [
			'status'          => 'dead',
			'next_attempt_at' => null,
			'last_http_code'  => $code,
			'last_error'      => substr( sanitize_key( $error ), 0, 255 ),
			'updated_at'      => current_time( 'mysql', true ),
		];
		if ( $clear_payload ) {
			$values['payload_json'] = '';
			$values['headers_json'] = '';
		}
		$wpdb->update( self::table(), $values, [ 'id' => $id ] );
		do_action( 'yogb_bm_outbox_event', 'dead', [ 'code' => $code, 'error_code' => sanitize_key( $error ) ] );
	}

	private static function is_retryable( array $res ) : bool {
		$code = (int) ( $res['code'] ?? 0 );
		return 0 === $code || in_array( $code, [ 408, 425, 429 ], true ) || $code >= 500;
	}

	private static function retry_delay( int $attempt, int $retry_after ) : int {
		if ( $retry_after > 0 ) {
			return min( DAY_IN_SECONDS, max( MINUTE_IN_SECONDS, $retry_after ) );
		}
		$delays = [ 1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 10800, 6 => 21600, 7 => 43200 ];
		$base   = $delays[ $attempt ] ?? DAY_IN_SECONDS;
		return $base + wp_rand( 0, min( 120, (int) floor( $base / 10 ) ) );
	}

	private static function response_error_code( array $res ) : string {
		if ( ! empty( $res['error_code'] ) ) {
			return (string) $res['error_code'];
		}
		if ( ! empty( $res['err'] ) ) {
			return 0 === (int) ( $res['code'] ?? 0 ) ? 'transport_error' : (string) $res['err'];
		}
		return 'http_' . (int) ( $res['code'] ?? 0 );
	}

	private static function store_report_id( int $order_id, string $body ) : void {
		if ( $order_id <= 0 ) {
			return;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['report_id'] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$report_id = class_exists( 'YOGB_BM_Report_V2' )
			? YOGB_BM_Report_V2::normalize_report_reference( $data['report_id'] )
			: (string) $data['report_id'];
		if ( '' === $report_id ) {
			return;
		}
		$ids = $order->get_meta( '_yogb_gbl_report_ids', true );
		$ids = is_array( $ids ) ? $ids : ( $ids ? [ (string) $ids ] : [] );
		$ids[] = $report_id;
		$order->update_meta_data( '_yogb_gbl_report_ids', array_values( array_unique( array_filter( $ids ) ) ) );
		$order->save();
	}

	private static function schedule_item( int $id, int $delay ) : void {
		if ( $id <= 0 ) {
			return;
		}
		$args = [ 'outbox_id' => $id ];
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_next_scheduled_action' ) || ! as_next_scheduled_action( self::ACTION_HOOK, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + max( 1, $delay ), self::ACTION_HOOK, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::ACTION_HOOK, [ $id ] ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::ACTION_HOOK, [ $id ] );
		}
	}

	private static function ensure_table() : bool {
		global $wpdb;
		if ( self::$table_ready ) {
			return true;
		}
		$table = self::table();
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			self::$table_ready = true;
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,event_key varchar(64) NOT NULL,event_type varchar(20) NOT NULL,
			route varchar(255) NOT NULL,payload_json longtext NOT NULL,headers_json text NOT NULL,order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts int(10) unsigned NOT NULL DEFAULT 0,max_attempts int(10) unsigned NOT NULL DEFAULT 8,status varchar(20) NOT NULL DEFAULT 'pending',
			next_attempt_at datetime DEFAULT NULL,last_http_code int(11) NOT NULL DEFAULT 0,last_error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY  (id),UNIQUE KEY event_key (event_key),
			KEY status_next (status,next_attempt_at),KEY order_id (order_id)
		) {$charset};";
		dbDelta( $sql );
		self::$table_ready = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		return self::$table_ready;
	}

	private static function table() : string {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist_gbl_outbox';
	}

	private static function encode_payload( array $payload, bool $requires_encryption = false ) : string {
		$json = self::canonical_json( $payload );
		if ( '' === $json ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			try {
				$key = hash( 'sha256', wp_salt( 'auth' ) . '|yogb-outbox-v1', true );
				$iv  = random_bytes( 12 );
				$tag = '';
				$raw = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				if ( false !== $raw ) {
					return 'enc1:' . base64_encode( $iv . $tag . $raw );
				}
			} catch ( Throwable $e ) {
				// Strong evidence references must never fall back to plain base64.
			}
		}
		if ( $requires_encryption ) {
			return '';
		}
		return 'json1:' . base64_encode( $json );
	}

	private static function encode_raw_payload( string $json ) : string {
		if ( '' === $json ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			try {
				$key = hash( 'sha256', wp_salt( 'auth' ) . '|yogb-outbox-v1', true );
				$iv  = random_bytes( 12 );
				$tag = '';
				$raw = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				if ( false !== $raw ) {
					return 'rawenc1:' . base64_encode( $iv . $tag . $raw );
				}
			} catch ( Throwable $e ) {
				// Existing report payload policy permits encoded plaintext fallback.
			}
		}
		return 'raw1:' . base64_encode( $json );
	}

	private static function decode_payload( string $encoded ) : ?array {
		if ( 0 === strpos( $encoded, 'enc1:' ) ) {
			$raw = base64_decode( substr( $encoded, 5 ), true );
			if ( false === $raw || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
				return null;
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$key    = hash( 'sha256', wp_salt( 'auth' ) . '|yogb-outbox-v1', true );
			$json   = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		} elseif ( 0 === strpos( $encoded, 'json1:' ) ) {
			$json = base64_decode( substr( $encoded, 6 ), true );
		} else {
			return null;
		}
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	private static function decode_raw_payload( string $encoded ) : ?string {
		if ( 0 === strpos( $encoded, 'rawenc1:' ) ) {
			$raw = base64_decode( substr( $encoded, 8 ), true );
			if ( false === $raw || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
				return null;
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$key    = hash( 'sha256', wp_salt( 'auth' ) . '|yogb-outbox-v1', true );
			$json   = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return is_string( $json ) ? $json : null;
		}
		if ( 0 === strpos( $encoded, 'raw1:' ) ) {
			$json = base64_decode( substr( $encoded, 5 ), true );
			return is_string( $json ) ? $json : null;
		}
		return null;
	}

	private static function canonical_json( array $payload ) : string {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}
}

YOGB_BM_Outbox::init();
