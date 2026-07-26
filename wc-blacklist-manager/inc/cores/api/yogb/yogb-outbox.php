<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable client-side delivery queue for Global Blacklist writes. */
final class YOGB_BM_Outbox {
	const ACTION_HOOK = 'yogb_bm_process_outbox_item';
	const SWEEP_HOOK  = 'yogb_bm_process_outbox_due';
	const GROUP       = 'yogb-global-blacklist';

	private static $table_ready = false;

	public static function init() : void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		add_action( 'init', [ __CLASS__, 'schedule_sweep' ] );
		add_action( self::ACTION_HOOK, [ __CLASS__, 'process_action' ], 10, 1 );
		add_action( self::SWEEP_HOOK, [ __CLASS__, 'process_due' ] );
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
	public static function enqueue_outcome(array $payload,int $order_id):int{$uuid=(string)($payload['event_uuid']??'');return self::enqueue('outcome',YOGB_BM_Report::REST_ROUTE.'/decision/outcomes',$payload,['Idempotency-Key'=>$uuid],$order_id,hash('sha256','outcome|'.$uuid));}

	private static function enqueue( string $type, string $route, array $payload, array $headers, int $order_id, string $event_key ) : int {
		global $wpdb;
		if ( ! self::ensure_table() ) {
			return 0;
		}

		$table   = self::table();
		$now     = current_time( 'mysql', true );
		$encoded = self::encode_payload( $payload );
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
						'status'          => 'pending',
						'next_attempt_at' => null,
						'last_http_code'  => 0,
						'last_error'      => '',
						'updated_at'      => $now,
					],
					[ 'id' => $id ]
				);
			}
			self::schedule_item( $id, 3 );
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
				'status'          => 'pending',
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
		if ( $id > 0 ) {
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
		$wpdb->query(
			"UPDATE {$table} SET status='retry',next_attempt_at=UTC_TIMESTAMP(),last_error='stale_claim_recovered'
			WHERE status='processing' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)"
		);
		$ids = $wpdb->get_col(
			"SELECT id FROM {$table}
			WHERE status IN ('pending','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())
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
		$payload = self::decode_payload( (string) $row['payload_json'] );
		$headers = json_decode( (string) $row['headers_json'], true );
		if ( ! is_array( $payload ) || ! is_array( $headers ) ) {
			if ( 'outcome' === (string) $row['event_type'] ) {
				self::store_outcome_delivery( (int) $row['order_id'], 'failed' );
			}
			self::mark_dead( $id, 0, 'payload_decode_failed' );
			return;
		}

		$res  = YOGB_BM_Report::post_json_signed( (string) $row['route'], $payload, $headers );
		$code = (int) ( $res['code'] ?? 0 );
		if ( ! empty( $res['ok'] ) ) {
			if ( 'report' === (string) $row['event_type'] ) {
				self::store_report_id( (int) $row['order_id'], (string) ( $res['body'] ?? '' ) );
			} elseif ( 'outcome' === (string) $row['event_type'] ) {
				self::store_outcome_delivery( (int) $row['order_id'], 'delivered' );
			}
			$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
			do_action( 'yogb_bm_outbox_event', 'delivered', [ 'type' => (string) $row['event_type'], 'code' => $code, 'attempts' => (int) $row['attempts'] ] );
			return;
		}

		$attempts = (int) $row['attempts'];
		$error    = self::response_error_code( $res );
		if ( $attempts >= (int) $row['max_attempts'] || ! self::is_retryable( $res ) ) {
			if ( 'outcome' === (string) $row['event_type'] ) {
				self::store_outcome_delivery( (int) $row['order_id'], 'failed' );
			}
			self::mark_dead( $id, $code, $error );
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
			self::store_outcome_delivery( (int) $row['order_id'], 'retrying' );
		}
		do_action( 'yogb_bm_outbox_event', 'retry', [ 'type' => (string) $row['event_type'], 'code' => $code, 'attempts' => $attempts ] );
	}

	private static function store_outcome_delivery( int $order_id, string $status ) : void {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( YOGB_BM_Decision_Actions::META_OUTCOME_DELIVERY, sanitize_key( $status ) );
		$order->save();
	}

	private static function mark_dead( int $id, int $code, string $error ) : void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'payload_json'    => '',
				'headers_json'    => '',
				'status'          => 'dead',
				'next_attempt_at' => null,
				'last_http_code'  => $code,
				'last_error'      => substr( sanitize_key( $error ), 0, 255 ),
				'updated_at'      => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
		do_action( 'yogb_bm_outbox_event', 'dead', [ 'code' => $code, 'error_code' => sanitize_key( $error ) ] );
	}

	private static function is_retryable( array $res ) : bool {
		$code = (int) ( $res['code'] ?? 0 );
		return 0 === $code || in_array( $code, [ 401, 408, 425, 429 ], true ) || $code >= 500;
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
		$ids = $order->get_meta( '_yogb_gbl_report_ids', true );
		$ids = is_array( $ids ) ? $ids : ( $ids ? [ (string) $ids ] : [] );
		$ids[] = (string) $data['report_id'];
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

	private static function encode_payload( array $payload ) : string {
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
				// Fall through to encoded JSON; delivery must remain available.
			}
		}
		return 'json1:' . base64_encode( $json );
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

	private static function canonical_json( array $payload ) : string {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}
}

YOGB_BM_Outbox::init();
