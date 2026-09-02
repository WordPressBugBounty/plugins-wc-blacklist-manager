<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait YOBM_Bot_Signal_Analyzer {

	abstract protected function get_bot_signal_cache_key(): string;

	/**
	 * Read the request's cached summary without deleting expired option rows.
	 */
	protected function get_bot_signal_transient_read_only( string $cache_key ) {
		$pre = apply_filters( "pre_transient_{$cache_key}", false, $cache_key );

		if ( false !== $pre ) {
			return $pre;
		}

		if ( wp_using_ext_object_cache() || wp_installing() ) {
			$value = wp_cache_get( $cache_key, 'transient' );
		} else {
			$transient_option = '_transient_' . $cache_key;
			$alloptions       = wp_load_alloptions();

			if ( ! isset( $alloptions[ $transient_option ] ) ) {
				$timeout = get_option( '_transient_timeout_' . $cache_key );
				if ( false !== $timeout && (int) $timeout < time() ) {
					$value = false;
				}
			}

			if ( ! isset( $value ) ) {
				$value = get_option( $transient_option );
			}
		}

		return apply_filters( "transient_{$cache_key}", $value, $cache_key );
	}

	protected function get_bot_signal_summary_shared( bool $write_cache = true ): array {
		$cache_key = $this->get_bot_signal_cache_key();
		$cached    = $write_cache
			? get_transient( $cache_key )
			: $this->get_bot_signal_transient_read_only( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$window_start_ts  = time() - self::ANALYSIS_WINDOW_SECONDS;
		$window_start_sql = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::ANALYSIS_WINDOW_SECONDS );

		$order_ids = wc_get_orders(
			[
				'limit'   => 100,
				'return'  => 'ids',
				// Pending/on-hold are contextual alert candidates; they are only counted
				// below with supporting repeat/burst signals.
				'status'  => [ 'failed', 'cancelled', 'pending', 'on-hold', 'checkout-draft' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$order_data = $this->analyze_orders_shared( $order_ids, $window_start_ts );
		$log_data   = $this->analyze_detection_logs_shared( $window_start_sql );

		$data = $this->merge_bot_signal_data( $order_data, $log_data );

		$incidents        = $this->classify_bot_signal_incidents( $order_data, $log_data );
		$primary_incident = ! empty( $incidents ) ? (string) $incidents[0] : '';
		$severity         = '' !== $primary_incident ? 'warning' : 'none';

		$fingerprint = md5(
			wp_json_encode(
				[
					'incident' => $primary_incident,
					'orders'   => $data['suspicious_orders'],
					'blocked'  => $data['blocked_attempts'],
					'payment'  => $data['payment_flow_attempts'],
					'ip'       => $data['top_ip'],
					'device'   => $data['top_device'],
					'time'     => $data['newest_ts'],
				]
			)
		);

		$summary = array_merge(
			$data,
			[
				'severity'         => $severity,
				'show'             => ( '' !== $primary_incident ),
				'incidents'        => $incidents,
				'primary_incident' => $primary_incident,
				'fingerprint'      => $fingerprint,
			]
		);

		if ( $write_cache ) {
			set_transient( $cache_key, $summary, self::CACHE_TTL );
		}

		return $summary;
	}

	protected function analyze_orders_shared( $order_ids, $window_start ): array {
		$data              = $this->empty_bot_signal_data();
		$context_data      = $this->empty_bot_signal_data();
		$contextual_events = [];

		foreach ( $order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}

			$date_created = $order->get_date_created();
			if ( ! $date_created ) {
				continue;
			}

			$ts = (int) $date_created->getTimestamp();
			if ( $ts < $window_start ) {
				continue;
			}

			$event = $this->build_order_signal_event( $order, $ts );
			$this->add_order_signal_event( $context_data, $event );

			if ( $this->is_contextual_order_signal_status( $event['status'] ) ) {
				$contextual_events[] = $event;
				continue;
			}

			$this->add_order_signal_event( $data, $event );
		}

		foreach ( $contextual_events as $event ) {
			if ( $this->contextual_order_signal_has_support( $event, $context_data ) ) {
				$this->add_order_signal_event( $data, $event );
			}
		}

		return $this->finalize_bot_signal_data( $data );
	}

	protected function analyze_detection_logs_shared( $window_start_sql ): array {
		global $wpdb;

		$data  = $this->empty_bot_signal_data();
		$table = $wpdb->prefix . 'wc_blacklist_detection_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $this->finalize_bot_signal_data( $data );
		}

		$date_column = $this->get_existing_column( $table, [ 'timestamp', 'date_added', 'created_at', 'date', 'log_date' ] );
		if ( ! $date_column ) {
			return $this->finalize_bot_signal_data( $data );
		}

		$date_column_sql = '`' . esc_sql( $date_column ) . '`';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$date_column_sql} >= %s ORDER BY {$date_column_sql} DESC LIMIT 300",
				$window_start_sql
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return $this->finalize_bot_signal_data( $data );
		}

		foreach ( $rows as $row ) {
			$type    = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
			$source  = isset( $row['source'] ) ? sanitize_key( $row['source'] ) : '';
			$action  = isset( $row['action'] ) ? sanitize_key( $row['action'] ) : '';
			$details = isset( $row['details'] ) ? (string) $row['details'] : '';

			$is_bot_or_block = $this->is_detection_log_abuse_signal( $type, $source, $action, $details, $row );

			if ( ! $is_bot_or_block ) {
				continue;
			}

			$data['blocked_attempts']++;
			$view = $this->extract_log_view( $row );

			$gateway = $this->normalize_bot_signal_gateway(
				$this->extract_log_value(
					$row,
					[
						'payment_method',
						'gateway',
						'integration',
						'processor',
						'request.payment_method',
						'payment.method',
						'payment.processor',
					]
				)
			);

			if ( $this->log_is_explicit_store_api_abuse( $source, $details, $view ) ) {
				$data['store_api_attempts']++;
			}

			if ( $this->log_is_explicit_rest_checkout_abuse( $source, $details, $view ) ) {
				$data['rest_api_attempts']++;
			}

			$is_payment_flow = $this->log_looks_like_payment_flow( $source, $details, $view );
			$is_paypal_flow  = $this->log_looks_like_paypal_flow( $source, $details, $view, $gateway );

			if ( $is_payment_flow ) {
				$data['payment_flow_attempts']++;
			}

			if ( $is_paypal_flow ) {
				$data['paypal_attempts']++;
			} elseif ( $is_payment_flow ) {
				$data['card_payment_flow_attempts']++;
			}

			if ( $this->log_looks_like_risk_engine( $source, $details, $view ) ) {
				$data['risk_engine_attempts']++;
			}

			if ( $this->log_looks_like_challenge( $source, $details, $view ) ) {
				$data['challenge_attempts']++;
			}

			if ( $gateway ) {
				$this->increment_counter( $data['gateways'], $gateway );
			}

			$ip = $this->extract_log_value( $row, [ 'ip_address', 'customer_ip', 'ip', 'request.ip' ] );
			$ip = $this->normalize_bot_signal_ip( $ip );
			if ( $ip ) {
				$this->increment_counter( $data['ips'], $ip );
			}

			$device_id = $this->extract_log_value( $row, [ 'device_id', 'device', 'device.id', 'device.device_id' ] );
			$device_id = $this->normalize_bot_signal_token( $device_id );
			if ( $device_id ) {
				$this->increment_counter( $data['devices'], $device_id );
			}

			$session_id = $this->extract_log_value( $row, [ 'session_id', 'session', 'request.session_id', 'device.session_id' ] );
			$session_id = $this->normalize_bot_signal_token( $session_id );
			if ( $session_id ) {
				$this->increment_counter( $data['sessions'], $session_id );
			}

			$email = $this->normalize_bot_signal_email( $this->extract_log_value( $row, [ 'email', 'email_address', 'billing.email', 'billing_email' ] ) );
			if ( $email ) {
				$data['emails'][ $email ] = true;

				$domain = $this->get_email_domain( $email );
				if ( $domain ) {
					$this->increment_counter( $data['email_domains'], $domain );
				}
			}

			$phone = $this->normalize_bot_signal_phone( $this->extract_log_value( $row, [ 'phone', 'phone_number', 'billing.phone', 'billing_phone' ] ) );
			if ( $phone ) {
				$this->increment_counter( $data['phones'], $phone );
			}

			$ts = ! empty( $row[ $date_column ] ) ? strtotime( $row[ $date_column ] ) : 0;
			if ( $ts ) {
				$data['timestamps'][] = $ts;
				$this->increment_counter( $data['minute_buckets'], gmdate( 'Y-m-d H:i', $ts ) );
			}
		}

		return $this->finalize_bot_signal_data( $data );
	}

	/**
	 * Decide whether a detection-log row is evidence of abuse for an admin
	 * bot-signal notice. A successful policy block is not, by itself, abuse
	 * evidence: country, region, blacklist, and access-policy rules legitimately
	 * produce action=block rows during normal enforcement.
	 *
	 * The existing log schema has enough stable taxonomy to identify explicit
	 * bot, challenge, CAPTCHA, Store API, payment-flow, and risk-engine signals
	 * without changing producers or the shared table contract.
	 */
	protected function is_detection_log_abuse_signal( string $type, string $source, string $action, string $details, array $row ): bool {
		unset( $action ); // action=block alone is intentionally not abuse evidence.

		$view     = $this->extract_log_view( $row );
		$haystack = strtolower( $details . ' ' . wp_json_encode( $view ) );
		$markers  = [
			'block_bot_js_proof_attempt:',
			'block_captcha_attempt:',
			'payment_flow_captcha:',
			'paypal_flow_captcha:',
			'block_fingerprint_anomalies_attempt:',
			'block_session_continuity_attempt:',
			'block_rest_api_attempt:',
			'active_challenge:',
			'protection: checkout anti-bot, result: blocked',
			'protection: store api rate limit, result: blocked',
		];

		foreach ( $markers as $marker ) {
			if ( false !== strpos( $haystack, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	protected function build_order_signal_event( $order, int $timestamp ): array {
		$email = $this->normalize_bot_signal_email( $order->get_billing_email() );

		return [
			'timestamp'     => $timestamp,
			'status'        => (string) $order->get_status(),
			'email'         => $email,
			'email_domain'  => $email ? $this->get_email_domain( $email ) : '',
			'phone'         => $this->normalize_bot_signal_phone( $order->get_billing_phone() ),
			'ip'            => $this->normalize_bot_signal_ip( $order->get_customer_ip_address() ),
			'device_id'     => $this->normalize_bot_signal_token( $order->get_meta( '_wc_bm_device_id', true ) ),
			'session_id'    => $this->normalize_bot_signal_token( $order->get_meta( '_wc_bm_session_id', true ) ),
			'gateway'       => $this->normalize_bot_signal_gateway( $order->get_payment_method() ),
			'minute_bucket' => gmdate( 'Y-m-d H:i', $timestamp ),
		];
	}

	protected function add_order_signal_event( array &$data, array $event ): void {
		if ( ! empty( $event['timestamp'] ) ) {
			$data['timestamps'][] = (int) $event['timestamp'];
		}

		if ( ! empty( $event['email'] ) ) {
			$data['emails'][ $event['email'] ] = true;
		}

		foreach ( [
			'email_domain'  => 'email_domains',
			'phone'         => 'phones',
			'ip'            => 'ips',
			'device_id'     => 'devices',
			'session_id'    => 'sessions',
			'gateway'       => 'gateways',
			'minute_bucket' => 'minute_buckets',
		] as $event_key => $data_key ) {
			if ( ! empty( $event[ $event_key ] ) ) {
				$this->increment_counter( $data[ $data_key ], (string) $event[ $event_key ] );
			}
		}

		if ( ! empty( $event['status'] ) && 'checkout-draft' === $event['status'] ) {
			$data['checkout_draft_count']++;
		}

		if ( ! empty( $event['status'] ) && 'failed' === $event['status'] ) {
			$data['failed_order_count']++;
		}

		if ( ! empty( $event['status'] ) && 'cancelled' === $event['status'] ) {
			$data['cancelled_order_count']++;
		}

		if ( ! empty( $event['status'] ) && $this->is_contextual_order_signal_status( (string) $event['status'] ) ) {
			$data['contextual_order_count']++;
		}
	}

	protected function is_contextual_order_signal_status( string $status ): bool {
		return in_array( $status, [ 'pending', 'on-hold' ], true );
	}

	protected function contextual_order_signal_has_support( array $event, array $context_data ): bool {
		$checks = [
			[ 'ip', 'ips', self::MIN_TOP_IP_HITS ],
			[ 'device_id', 'devices', 3 ],
			[ 'session_id', 'sessions', 4 ],
			[ 'phone', 'phones', 3 ],
			[ 'email_domain', 'email_domains', 5 ],
			[ 'minute_bucket', 'minute_buckets', self::MIN_HOT_MINUTE_HITS ],
		];

		foreach ( $checks as $check ) {
			[ $event_key, $data_key, $threshold ] = $check;
			$value = isset( $event[ $event_key ] ) ? (string) $event[ $event_key ] : '';

			if ( '' !== $value && isset( $context_data[ $data_key ][ $value ] ) && (int) $context_data[ $data_key ][ $value ] >= $threshold ) {
				return true;
			}
		}

		return false;
	}

	protected function classify_bot_signal_incidents( array $order_data, array $log_data ): array {
		$incidents = [];

		if ( $log_data['paypal_attempts'] >= 2 ) {
			$incidents[] = 'paypal_flow_suspected';
		}

		if ( $log_data['card_payment_flow_attempts'] >= 2 ) {
			$incidents[] = 'card_testing_suspected';
		}

		if ( $log_data['store_api_attempts'] >= 3 || $log_data['rest_api_attempts'] >= 2 ) {
			$incidents[] = 'store_api_abuse';
		}

		if ( $log_data['challenge_attempts'] >= 2 ) {
			$incidents[] = 'challenge_abuse';
		}

		if (
			$log_data['blocked_attempts'] >= 3
			&& (
				$log_data['top_ip_hits'] >= self::MIN_TOP_IP_HITS
				|| $log_data['top_device_hits'] >= 3
				|| $log_data['top_session_hits'] >= 4
				|| $log_data['top_phone_hits'] >= 3
			)
		) {
			$incidents[] = 'repeat_blocked_identity';
		}

		if (
			$order_data['suspicious_orders'] >= self::MIN_SUSPICIOUS_ORDERS
			&& (
				$order_data['top_ip_hits'] >= self::MIN_TOP_IP_HITS
				|| $order_data['top_device_hits'] >= 3
				|| $order_data['top_session_hits'] >= 4
				|| $order_data['top_phone_hits'] >= 3
				|| $order_data['top_email_domain_hits'] >= 5
			)
		) {
			$incidents[] = 'checkout_velocity_spike';
		}

		return array_values( array_unique( $incidents ) );
	}

	protected function empty_bot_signal_data(): array {
		return [
			'timestamps'            => [],
			'emails'                => [],
			'ips'                   => [],
			'phones'                => [],
			'devices'               => [],
			'sessions'              => [],
			'email_domains'         => [],
			'gateways'              => [],
			'minute_buckets'        => [],
			'blocked_attempts'      => 0,
			'store_api_attempts'    => 0,
			'rest_api_attempts'     => 0,
			'payment_flow_attempts' => 0,
			'card_payment_flow_attempts' => 0,
			'paypal_attempts'       => 0,
			'risk_engine_attempts'  => 0,
			'challenge_attempts'    => 0,
			'checkout_draft_count'  => 0,
			'failed_order_count'    => 0,
			'cancelled_order_count'  => 0,
			'contextual_order_count' => 0,
		];
	}

	protected function finalize_bot_signal_data( array $data ): array {
		arsort( $data['ips'] );
		arsort( $data['phones'] );
		arsort( $data['devices'] );
		arsort( $data['sessions'] );
		arsort( $data['email_domains'] );
		arsort( $data['gateways'] );
		arsort( $data['minute_buckets'] );

		$oldest_ts = ! empty( $data['timestamps'] ) ? min( $data['timestamps'] ) : 0;
		$newest_ts = ! empty( $data['timestamps'] ) ? max( $data['timestamps'] ) : 0;

		return [
			'suspicious_orders'      => count( $data['timestamps'] ),
			'unique_emails'          => count( $data['emails'] ),
			'unique_ips'             => count( $data['ips'] ),
			'blocked_attempts'       => (int) $data['blocked_attempts'],
			'store_api_attempts'     => (int) $data['store_api_attempts'],
			'rest_api_attempts'      => (int) $data['rest_api_attempts'],
			'payment_flow_attempts'  => (int) $data['payment_flow_attempts'],
			'card_payment_flow_attempts' => (int) $data['card_payment_flow_attempts'],
			'paypal_attempts'        => (int) $data['paypal_attempts'],
			'risk_engine_attempts'   => (int) $data['risk_engine_attempts'],
			'challenge_attempts'     => (int) $data['challenge_attempts'],
			'checkout_draft_count'   => (int) $data['checkout_draft_count'],
			'failed_order_count'     => (int) $data['failed_order_count'],
			'cancelled_order_count'  => (int) $data['cancelled_order_count'],
			'contextual_order_count' => (int) $data['contextual_order_count'],

			'top_ip'                 => $this->counter_top_key( $data['ips'] ),
			'top_ip_hits'            => $this->counter_top_value( $data['ips'] ),

			'top_phone'              => $this->counter_top_key( $data['phones'] ),
			'top_phone_hits'         => $this->counter_top_value( $data['phones'] ),

			'top_device'             => $this->counter_top_key( $data['devices'] ),
			'top_device_hits'        => $this->counter_top_value( $data['devices'] ),

			'top_session'            => $this->counter_top_key( $data['sessions'] ),
			'top_session_hits'       => $this->counter_top_value( $data['sessions'] ),

			'top_email_domain'       => $this->counter_top_key( $data['email_domains'] ),
			'top_email_domain_hits'  => $this->counter_top_value( $data['email_domains'] ),

			'top_gateway'            => $this->counter_top_key( $data['gateways'] ),
			'top_gateway_hits'       => $this->counter_top_value( $data['gateways'] ),

			'hot_minute_hits'        => $this->counter_top_value( $data['minute_buckets'] ),
			'burst_span_seconds'     => ( $oldest_ts && $newest_ts ) ? max( 0, $newest_ts - $oldest_ts ) : 0,
			'newest_ts'              => $newest_ts,
		];
	}

	protected function merge_bot_signal_data( array $a, array $b ): array {
		$merged = $a;

		// Only orders should define "suspicious_orders"
		$merged['suspicious_orders'] = (int) $a['suspicious_orders'];

		// These can be safely merged
		foreach ( [
			'unique_emails',
			'unique_ips',
			'blocked_attempts',
			'store_api_attempts',
			'rest_api_attempts',
			'payment_flow_attempts',
			'card_payment_flow_attempts',
			'paypal_attempts',
			'risk_engine_attempts',
			'challenge_attempts',
			'checkout_draft_count',
			'failed_order_count',
			'cancelled_order_count',
			'contextual_order_count',
		] as $key ) {
			$merged[ $key ] = (int) $a[ $key ] + (int) $b[ $key ];
		}

		foreach ( [
			'top_ip',
			'top_phone',
			'top_device',
			'top_session',
			'top_email_domain',
			'top_gateway',
		] as $key ) {
			if ( empty( $merged[ $key ] ) && ! empty( $b[ $key ] ) ) {
				$merged[ $key ] = $b[ $key ];
			}
		}

		foreach ( [
			'top_ip_hits',
			'top_phone_hits',
			'top_device_hits',
			'top_session_hits',
			'top_email_domain_hits',
			'top_gateway_hits',
			'hot_minute_hits',
		] as $key ) {
			$merged[ $key ] = max( (int) $a[ $key ], (int) $b[ $key ] );
		}

		$merged['burst_span_seconds'] = max( (int) $a['burst_span_seconds'], (int) $b['burst_span_seconds'] );
		$merged['newest_ts']          = max( (int) $a['newest_ts'], (int) $b['newest_ts'] );

		return $merged;
	}

	protected function normalize_bot_signal_email( $email ): string {
		$email = is_string( $email ) ? trim( $email ) : '';

		if ( '' === $email ) {
			return '';
		}

		if ( function_exists( 'yobm_normalize_email' ) ) {
			return (string) yobm_normalize_email( $email );
		}

		return strtolower( sanitize_email( $email ) );
	}

	protected function normalize_bot_signal_phone( $phone ): string {
		$phone = is_string( $phone ) ? trim( $phone ) : '';

		if ( '' === $phone ) {
			return '';
		}

		if ( function_exists( 'yobm_normalize_phone' ) ) {
			return (string) yobm_normalize_phone( $phone );
		}

		return preg_replace( '/\D+/', '', $phone );
	}

	protected function normalize_bot_signal_ip( $ip ): string {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		if ( '' === $ip ) {
			return '';
		}

		$validated = filter_var( $ip, FILTER_VALIDATE_IP );

		return $validated ? $validated : '';
	}

	protected function normalize_bot_signal_token( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	protected function normalize_bot_signal_gateway( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		return sanitize_key( $value );
	}

	protected function increment_counter( array &$counter, string $key ): void {
		if ( '' === $key ) {
			return;
		}

		$counter[ $key ] = isset( $counter[ $key ] ) ? (int) $counter[ $key ] + 1 : 1;
	}

	protected function counter_top_key( array $counter ): string {
		if ( empty( $counter ) ) {
			return '';
		}

		reset( $counter );
		return (string) key( $counter );
	}

	protected function counter_top_value( array $counter ): int {
		if ( empty( $counter ) ) {
			return 0;
		}

		return (int) reset( $counter );
	}

	protected function get_email_domain( string $email ): string {
		$pos = strpos( $email, '@' );

		if ( false === $pos ) {
			return '';
		}

		return strtolower( substr( $email, $pos + 1 ) );
	}

	protected function get_existing_column( string $table, array $candidates ): string {
		global $wpdb;

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( empty( $columns ) ) {
			return '';
		}

		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	protected function extract_log_value( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				return (string) $row[ $key ];
			}
		}

		foreach ( [ 'details', 'snapshot', 'payload', 'view' ] as $blob_key ) {
			if ( empty( $row[ $blob_key ] ) ) {
				continue;
			}

			$blob = (string) $row[ $blob_key ];
			$decoded = json_decode( $blob, true );

			if ( is_array( $decoded ) ) {
				foreach ( $keys as $key ) {
					$value = $this->find_log_value_in_array( $decoded, $key );

					if ( null !== $value && is_scalar( $value ) && '' !== (string) $value ) {
						return (string) $value;
					}
				}
			}

			foreach ( $keys as $key ) {
				if ( preg_match( '/"' . preg_quote( $key, '/' ) . '"\s*:\s*"([^"]+)"/', $blob, $m ) ) {
					return $m[1];
				}
			}
		}

		return '';
	}

	protected function extract_log_view( array $row ): array {
		foreach ( [ 'view', 'payload', 'snapshot', 'details' ] as $blob_key ) {
			if ( empty( $row[ $blob_key ] ) ) {
				continue;
			}

			$decoded = json_decode( (string) $row[ $blob_key ], true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return [];
	}

	protected function find_log_value_in_array( array $data, string $key ) {
		if ( false !== strpos( $key, '.' ) ) {
			$value = $this->find_log_value_by_path( $data, explode( '.', $key ) );

			if ( null !== $value ) {
				return $value;
			}
		}

		foreach ( $data as $item_key => $value ) {
			if ( (string) $item_key === $key && is_scalar( $value ) ) {
				return $value;
			}

			if ( is_array( $value ) ) {
				$nested = $this->find_log_value_in_array( $value, $key );

				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	protected function find_log_value_by_path( array $data, array $path ) {
		$current = $data;

		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}

			$current = $current[ $segment ];
		}

		return is_scalar( $current ) ? $current : null;
	}

	protected function log_looks_like_payment_flow( string $source, string $details, array $view ): bool {
		$source_details = strtolower( $source . ' ' . $details );
		$view_text      = strtolower( (string) wp_json_encode( $view ) );

		return (
			false !== strpos( $source_details, 'payment_flow' ) ||
			false !== strpos( $source_details, 'card_testing' ) ||
			false !== strpos( $source_details, 'carding' ) ||
			false !== strpos( $source_details, 'decline' ) ||
			false !== strpos( $source_details, 'avs' ) ||
			false !== strpos( $source_details, 'cvv' ) ||
			false !== strpos( $view_text, '"payment_flow"' ) ||
			false !== strpos( $view_text, '"decline_code"' ) ||
			false !== strpos( $view_text, '"avs' ) ||
			false !== strpos( $view_text, '"cvv' )
		);
	}

	protected function log_is_explicit_store_api_abuse( string $source, string $details, array $view ): bool {
		unset( $source );
		$haystack = strtolower( $details . ' ' . wp_json_encode( $view ) );

		return false !== strpos( $haystack, 'protection: store api rate limit, result: blocked' );
	}

	protected function log_is_explicit_rest_checkout_abuse( string $source, string $details, array $view ): bool {
		unset( $source );
		$haystack = strtolower( $details . ' ' . wp_json_encode( $view ) );

		return false !== strpos( $haystack, 'block_rest_api_attempt:' );
	}

	protected function log_looks_like_paypal_flow( string $source, string $details, array $view, string $gateway ): bool {
		$source_details = strtolower( $source . ' ' . $details );
		$gateway_text   = strtolower( $gateway );
		$view_text      = strtolower( (string) wp_json_encode( $view ) );
		$is_paypal      = (
			false !== strpos( $gateway_text, 'paypal' ) ||
			false !== strpos( $gateway_text, 'braintree' ) ||
			false !== strpos( $view_text, 'paypal' ) ||
			false !== strpos( $view_text, 'braintree' )
		);

		return (
			false !== strpos( $source_details, 'paypal_flow' ) ||
			false !== strpos( $source_details, 'paypal card' ) ||
			false !== strpos( $source_details, 'braintree' ) ||
			isset( $view['paypal'] ) ||
			( $is_paypal && (
				false !== strpos( $source_details, 'payment_flow' ) ||
				false !== strpos( $source_details, 'challenge' ) ||
				false !== strpos( $source_details, 'captcha' ) ||
				false !== strpos( $source_details, 'decline' ) ||
				isset( $view['payment_flow'] ) ||
				isset( $view['integration'] )
			) )
		);
	}

	protected function log_looks_like_risk_engine( string $source, string $details, array $view ): bool {
		$schema   = isset( $view['schema'] ) ? sanitize_key( (string) $view['schema'] ) : '';
		$haystack = strtolower( $source . ' ' . $details . ' ' . $schema );

		return false !== strpos( $haystack, 'risk' ) || false !== strpos( $haystack, 'antibot' );
	}

	protected function log_looks_like_challenge( string $source, string $details, array $view ): bool {
		$haystack = strtolower( $source . ' ' . $details . ' ' . wp_json_encode( $view ) );

		return (
			false !== strpos( $haystack, 'challenge' ) ||
			false !== strpos( $haystack, 'captcha' ) ||
			false !== strpos( $haystack, 'recaptcha' ) ||
			false !== strpos( $haystack, 'turnstile' ) ||
			false !== strpos( $haystack, 'proof_of_work' )
		);
	}

	/**
	 * BM-0095's Core-only retrospective classifier. The legacy shared analyzer
	 * above remains unchanged for Premium compatibility.
	 */
	protected function get_bm0095_security_summary_shared( bool $write_cache = true ): array {
		$cache_key = $this->get_bot_signal_cache_key();
		$cached    = $write_cache ? get_transient( $cache_key ) : $this->get_bot_signal_transient_read_only( $cache_key );
		if ( $this->bm0095_is_safe_summary( $cached ) ) {
			return $cached;
		}

		$started = microtime( true );
		$cutoff  = time() - ( 48 * HOUR_IN_SECONDS );
		$metrics = array( 'orders_read' => 0, 'logs_read' => 0, 'log_path' => 'none', 'queries' => 0 );

		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			$summary = $this->bm0095_empty_summary( $metrics, $started );
			if ( $write_cache ) {
				set_transient( $cache_key, $summary, self::CACHE_TTL );
			}
			return $summary;
		}

		global $wpdb;
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
		$order_events   = $this->bm0095_retrieve_order_events( $cutoff, $metrics );
		$log_events     = $this->bm0095_retrieve_log_events( $cutoff, $metrics );
		$metrics['queries'] = isset( $wpdb->num_queries ) ? max( 0, (int) $wpdb->num_queries - $queries_before ) : 0;

		$candidates = array_merge(
			$this->bm0095_paypal_flow_candidates( $log_events ),
			$this->bm0095_card_testing_candidates( $order_events ),
			$this->bm0095_store_api_candidates( $log_events ),
			$this->bm0095_challenge_candidates( $log_events ),
			$this->bm0095_repeat_identity_candidates( $log_events ),
			$this->bm0095_velocity_candidates( $order_events, $log_events )
		);
		$candidate = $this->bm0095_select_candidate( $candidates );
		$summary   = array(
			'schema'      => 1,
			'show'        => is_array( $candidate ),
			'candidate'   => is_array( $candidate ) ? $candidate : array(),
			'scanned_at'  => time(),
			'orders_read' => (int) $metrics['orders_read'],
			'logs_read'   => (int) $metrics['logs_read'],
			'log_path'    => (string) $metrics['log_path'],
			'query_count' => (int) $metrics['queries'],
			'elapsed_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);

		if ( $write_cache ) {
			set_transient( $cache_key, $summary, self::CACHE_TTL );
		}
		return $summary;
	}

	protected function bm0095_empty_summary( array $metrics = array(), float $started = 0.0 ): array {
		return array(
			'schema'      => 1,
			'show'        => false,
			'candidate'   => array(),
			'scanned_at'  => time(),
			'orders_read' => isset( $metrics['orders_read'] ) ? (int) $metrics['orders_read'] : 0,
			'logs_read'   => isset( $metrics['logs_read'] ) ? (int) $metrics['logs_read'] : 0,
			'log_path'    => isset( $metrics['log_path'] ) ? sanitize_key( (string) $metrics['log_path'] ) : 'none',
			'query_count' => isset( $metrics['queries'] ) ? (int) $metrics['queries'] : 0,
			'elapsed_ms'  => $started > 0 ? (int) round( ( microtime( true ) - $started ) * 1000 ) : 0,
		);
	}

	protected function bm0095_is_safe_summary( $summary ): bool {
		$allowed = array( 'schema', 'show', 'candidate', 'scanned_at', 'orders_read', 'logs_read', 'log_path', 'query_count', 'elapsed_ms' );
		return is_array( $summary )
			&& 1 === (int) ( $summary['schema'] ?? 0 )
			&& ! array_diff( array_keys( $summary ), $allowed )
			&& ! array_diff( $allowed, array_keys( $summary ) )
			&& is_bool( $summary['show'] )
			&& (int) $summary['scanned_at'] > 0
			&& (int) $summary['orders_read'] >= 0 && (int) $summary['orders_read'] <= 100
			&& (int) $summary['logs_read'] >= 0 && (int) $summary['logs_read'] <= 300
			&& in_array( (string) $summary['log_path'], array( 'none', 'timestamp_index', 'primary_key_fallback' ), true )
			&& (int) $summary['query_count'] >= 0
			&& (int) $summary['elapsed_ms'] >= 0
			&& ( (bool) $summary['show'] === ! empty( $summary['candidate'] ) )
			&& ( empty( $summary['candidate'] ) || $this->bm0095_is_safe_candidate( $summary['candidate'] ) );
	}

	protected function bm0095_is_safe_candidate( $candidate ): bool {
		$allowed = array( 'family', 'mode', 'origin', 'episode_start', 'last_seen', 'episode_hash', 'event_count', 'identity_dimension', 'identity_count', 'fanout_count', 'provider', 'gateway' );
		$modes = array(
			'paypal_flow_suspected' => 'PAYPAL_FLOW_TAMPERING',
			'card_testing_suspected' => 'PAYPAL_CARD_CRACKING_SEQUENCE',
			'store_api_abuse' => 'STORE_API_AUTOMATION_BURST',
			'challenge_abuse' => 'CHALLENGE_REPLAY_BURST',
			'repeat_blocked_identity' => 'MULTI_SOURCE_REPEAT_ACTOR',
			'checkout_velocity_spike' => 'LINKED_CHECKOUT_FANOUT',
		);
		$family = (string) ( $candidate['family'] ?? '' );
		return is_array( $candidate )
			&& ! array_diff( array_keys( $candidate ), $allowed )
			&& ! array_diff( $allowed, array_keys( $candidate ) )
			&& isset( $modes[ $family ] )
			&& $modes[ $family ] === (string) ( $candidate['mode'] ?? '' )
			&& 'LOCAL_STRUCTURAL' === (string) ( $candidate['origin'] ?? '' )
			&& in_array( (string) ( $candidate['identity_dimension'] ?? '' ), array( 'device', 'session', 'account', 'ip', 'phone' ), true )
			&& (int) ( $candidate['episode_start'] ?? 0 ) > 0
			&& (int) ( $candidate['last_seen'] ?? 0 ) >= (int) ( $candidate['episode_start'] ?? 0 )
			&& (int) ( $candidate['event_count'] ?? -1 ) >= 0 && (int) ( $candidate['event_count'] ?? 401 ) <= 400
			&& (int) ( $candidate['identity_count'] ?? -1 ) >= 0 && (int) ( $candidate['identity_count'] ?? 401 ) <= 400
			&& (int) ( $candidate['fanout_count'] ?? -1 ) >= 0 && (int) ( $candidate['fanout_count'] ?? 401 ) <= 400
			&& in_array( (string) ( $candidate['provider'] ?? '' ), array( '', 'paypal' ), true )
			&& in_array( (string) ( $candidate['gateway'] ?? '' ), array( '', 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-card-button-gateway' ), true )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $candidate['episode_hash'] ?? '' ) );
	}

	protected function bm0095_families(): array {
		return array( 'paypal_flow_suspected', 'card_testing_suspected', 'store_api_abuse', 'challenge_abuse', 'repeat_blocked_identity', 'checkout_velocity_spike' );
	}

	protected function bm0095_retrieve_order_events( int $cutoff, array &$metrics ): array {
		$orders = wc_get_orders(
			array(
				'limit' => 100,
				'return' => 'objects',
				'status' => array( 'failed', 'cancelled', 'pending', 'on-hold', 'checkout-draft' ),
				'orderby' => 'date',
				'order' => 'DESC',
				'date_created' => '>' . $cutoff,
			)
		);
		$orders = is_array( $orders ) ? array_slice( $orders, 0, 100 ) : array();
		$metrics['orders_read'] = count( $orders );
		$events = array();
		$seen   = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}
			$id = (int) $order->get_id();
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$date = $order->get_date_created();
			$ts   = $date && method_exists( $date, 'getTimestamp' ) ? (int) $date->getTimestamp() : 0;
			if ( $ts <= $cutoff || '' !== (string) $order->get_meta( '_subscription_renewal', true ) ) {
				continue;
			}
			$created_via = method_exists( $order, 'get_created_via' ) ? sanitize_key( (string) $order->get_created_via() ) : '';
			if ( 'admin' === $created_via ) {
				continue;
			}
			$events[] = $this->bm0095_build_order_event( $order, $id, $ts, $created_via );
		}
		return $events;
	}

	protected function bm0095_build_order_event( $order, int $id, int $timestamp, string $created_via ): array {
		$email       = $this->normalize_bot_signal_email( $order->get_billing_email() );
		$phone       = $this->normalize_bot_signal_phone( $order->get_billing_phone() );
		$customer_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
		$actors      = array_filter( array(
			'device' => $this->normalize_bot_signal_token( $order->get_meta( '_wc_bm_device_id', true ) ),
			'session' => $this->normalize_bot_signal_token( $order->get_meta( '_wc_bm_session_id', true ) ),
			'account' => $customer_id > 0 ? (string) $customer_id : '',
			'ip' => $this->normalize_bot_signal_ip( $order->get_customer_ip_address() ),
			'phone' => $phone,
		) );
		$total    = method_exists( $order, 'get_total' ) ? (string) $order->get_total() : '';
		$currency = method_exists( $order, 'get_currency' ) ? sanitize_key( (string) $order->get_currency() ) : '';
		return array(
			'event_type' => 'order', 'id' => $id, 'timestamp' => $timestamp,
			'status' => sanitize_key( (string) $order->get_status() ), 'actors' => $actors,
			'billing_identity' => '' !== $email ? 'email:' . $email : ( '' !== $phone ? 'phone:' . $phone : '' ),
			'billing_email' => $email, 'billing_phone' => $phone,
			'created_via' => $created_via, 'amount_signature' => $currency . '|' . $total,
			'gateway' => $this->normalize_bot_signal_gateway( $order->get_payment_method() ),
			'provider' => $this->bm0095_normalize_paypal_order_evidence( $order ),
		);
	}

	protected function bm0095_normalize_paypal_order_evidence( $order ): array {
		$empty = array( 'eligible' => false, 'suppressed' => false, 'provider' => '', 'classes' => array() );
		$gateway = method_exists( $order, 'get_payment_method' ) ? sanitize_key( (string) $order->get_payment_method() ) : '';
		if ( ! in_array( $gateway, array( 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-card-button-gateway' ), true ) ) {
			return $empty;
		}
		$source = sanitize_key( (string) $order->get_meta( '_ppcp_paypal_payment_source', true ) );
		$mode   = sanitize_key( (string) $order->get_meta( '_ppcp_paypal_payment_mode', true ) );
		if ( 'card' !== $source || 'live' !== $mode ) {
			return $empty;
		}
		if ( ! empty( $order->get_meta( '_ppcp_paypal_3DS_auth_result', true ) ) ) {
			$empty['suppressed'] = true;
			return $empty;
		}
		$fraud = $order->get_meta( '_ppcp_paypal_fraud_result', true );
		if ( ! is_array( $fraud ) ) {
			return $empty;
		}
		$avs      = isset( $fraud['avs_code'] ) && is_scalar( $fraud['avs_code'] ) ? strtoupper( trim( (string) $fraud['avs_code'] ) ) : '';
		$cvv      = isset( $fraud['cvv2_code'] ) && is_scalar( $fraud['cvv2_code'] ) ? strtoupper( trim( (string) $fraud['cvv2_code'] ) ) : '';
		$response = isset( $fraud['response_code'] ) && is_scalar( $fraud['response_code'] ) ? strtoupper( trim( (string) $fraud['response_code'] ) ) : '';
		if ( in_array( $response, array( '1300', '1335', 'PCNF' ), true ) ) {
			return $empty;
		}
		if ( in_array( $response, array( '0890', '0960', '1370', '5910', '5920', '9100', '10BR', 'PCNR' ), true ) ) {
			$empty['suppressed'] = true;
			return $empty;
		}
		$classes = array();
		if ( in_array( $cvv, array( 'N', '1' ), true ) || in_array( $response, array( '00N7', '1382', '5110' ), true ) ) {
			$classes[] = 'CVC_FAILURE';
		}
		if ( in_array( $avs, array( 'N', 'C', '1' ), true ) ) {
			$classes[] = 'AVS_MISMATCH';
		}
		if ( '1330' === $response ) {
			$classes[] = 'ACCOUNT_INVALID';
		}
		if ( '5400' === $response ) {
			$classes[] = 'EXPIRED';
		}
		$empty['eligible'] = ! empty( $classes );
		$empty['provider'] = 'paypal';
		$empty['classes']  = array_values( array_unique( $classes ) );
		return $empty;
	}

	protected function bm0095_retrieve_log_events( int $cutoff, array &$metrics ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_blacklist_detection_log';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$date_column = $this->get_existing_column( $table, array( 'timestamp', 'date_added', 'created_at', 'date', 'log_date' ) );
		if ( '' === $date_column ) {
			return array();
		}
		$indexed = 'timestamp' === $date_column
			&& class_exists( 'WC_Blacklist_Manager_Schema_Readiness' )
			&& WC_Blacklist_Manager_Schema_Readiness::index_matches( 'global_outcome' );
		if ( $indexed ) {
			$cutoff_sql = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 48 * HOUR_IN_SECONDS ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE `timestamp` >= %s ORDER BY `timestamp` DESC LIMIT 300", $cutoff_sql ),
				ARRAY_A
			);
			$metrics['log_path'] = 'timestamp_index';
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY `id` DESC LIMIT 300", ARRAY_A );
			$metrics['log_path'] = 'primary_key_fallback';
		}
		$metrics['logs_read'] = is_array( $rows ) ? count( $rows ) : 0;
		$events = array();
		$seen   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$ts = ! empty( $row[ $date_column ] ) ? $this->bm0095_database_time_to_timestamp( (string) $row[ $date_column ] ) : 0;
			if ( $ts <= $cutoff ) {
				continue;
			}
			$event = $this->bm0095_parse_log_event( $row, $id, $ts );
			if ( is_array( $event ) ) {
				$events[] = $event;
			}
		}
		return $this->bm0095_apply_exact_context_resets( $events );
	}

	protected function bm0095_database_time_to_timestamp( string $value ): int {
		if ( function_exists( 'get_gmt_from_date' ) ) {
			$value = get_gmt_from_date( $value );
		}
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? 0 : (int) $timestamp;
	}

	protected function bm0095_parse_log_event( array $row, int $id, int $timestamp ) {
		$type    = sanitize_key( (string) ( $row['type'] ?? '' ) );
		$source  = sanitize_key( (string) ( $row['source'] ?? '' ) );
		$action  = sanitize_key( (string) ( $row['action'] ?? '' ) );
		$details = (string) ( $row['details'] ?? '' );
		$view    = $this->extract_log_view( $row );
		if ( 'bot' !== $type || empty( $view ) ) {
			return null;
		}

		$event_name   = sanitize_key( (string) $this->bm0095_view_value( $view, array( array( 'event' ) ) ) );
		$kind         = '';
		$producer     = '';
		$tampering    = false;
		$terminal     = false;
		$success      = false;
		$flow_failure = array( 'provider_mismatch', 'invalid_challenge_context', 'token_validation_failed', 'missing_token_or_challenge', 'action_mismatch' );
		$tamper_event = array( 'provider_mismatch', 'invalid_challenge_context', 'action_mismatch', 'challenge_replay', 'invalid_proof', 'replayed_proof' );

		if ( 'paypal_payments_create_order' === $source && 0 === strpos( $details, 'paypal_flow_captcha:' ) ) {
			if ( ! in_array( $event_name, array_merge( $flow_failure, array( 'challenge_passed' ) ), true ) ) {
				return null;
			}
			$success = 'challenge_passed' === $event_name;
			if ( ( $success && 'allow' !== $action ) || ( ! $success && 'block' !== $action ) ) {
				return null;
			}
			$kind = 'paypal_flow';
			$producer = 'paypal_flow';
			$tampering = in_array( $event_name, $tamper_event, true );
			$terminal = ! $success;
		} elseif ( in_array( $source, array( 'stripe_express', 'payment_plugins_stripe', 'woopayments_express', 'payment_plugins_braintree' ), true ) && 0 === strpos( $details, 'payment_flow_captcha:' ) ) {
			$integration = sanitize_key( (string) $this->bm0095_view_value( $view, array( array( 'integration' ) ) ) );
			if ( $integration !== $source || ! in_array( $event_name, array_merge( $flow_failure, array( 'challenge_passed' ) ), true ) ) {
				return null;
			}
			$success = 'challenge_passed' === $event_name;
			if ( ( $success && 'allow' !== $action ) || ( ! $success && 'block' !== $action ) ) {
				return null;
			}
			$kind = 'challenge';
			$producer = 'payment_flow';
			$tampering = in_array( $event_name, $tamper_event, true );
			$terminal = ! $success;
		} elseif ( 'active_challenge' === $source && 0 === strpos( $details, 'active_challenge:' ) && 'bmp_active_challenge_v1' === (string) $this->bm0095_view_value( $view, array( array( 'schema' ) ) ) ) {
			$reason = sanitize_key( (string) $this->bm0095_view_value( $view, array( array( 'reason' ) ) ) );
			$failure_reasons = array(
				'missing_issued_challenge', 'challenge_id_mismatch', 'missing_answer', 'surface_mismatch',
				'challenge_expired', 'context_mismatch', 'delay_not_satisfied', 'invalid_pow_answer',
			);
			if ( ! in_array( $event_name, array( 'challenge_failed', 'challenge_passed' ), true )
				|| ( 'challenge_failed' === $event_name && ! in_array( $reason, $failure_reasons, true ) )
				|| ( 'challenge_passed' === $event_name && '' !== $reason ) ) {
				return null;
			}
			$success = 'challenge_passed' === $event_name;
			if ( ( $success && 'allow' !== $action ) || ( ! $success && 'block' !== $action ) ) {
				return null;
			}
			$kind = 'challenge';
			$producer = 'active_challenge';
			$tampering = in_array( $reason, array( 'missing_issued_challenge', 'challenge_id_mismatch', 'surface_mismatch', 'context_mismatch', 'invalid_pow_answer' ), true );
			$terminal = 'challenge_failed' === $event_name;
		} elseif ( 'block' === $action && 'woo_store_api_checkout' === $source && 0 === strpos( $details, 'block_rest_api_attempt:' ) ) {
			$kind = 'store_api';
			$producer = 'store_api';
			$event_name = 'store_api_block';
			$tampering = true;
			$terminal = true;
		} elseif ( in_array( $action, array( 'block', 'rate_limit' ), true ) && 'woo_store_api' === $source && 0 === strpos( $details, 'Protection: Store API rate limit, Result: Blocked' ) ) {
			$kind = 'store_api';
			$producer = 'store_api';
			$event_name = 'store_api_rate_block';
			$tampering = true;
			$terminal = true;
		} elseif ( 'block' === $action && in_array( $source, array( 'woo_api_checkout', 'woo_checkout', 'woo_login', 'woo_lost_password', 'woo_register', 'register', 'login', 'lost_password', 'comment_or_review' ), true ) && 0 === strpos( $details, 'block_captcha_attempt:' ) ) {
			$provider = sanitize_key( (string) $this->bm0095_view_value( $view, array( array( 'provider' ), array( 'captcha', 'provider' ) ) ) );
			$view_source = sanitize_key( (string) $this->bm0095_view_value( $view, array( array( 'source' ) ) ) );
			$reason = $this->bm0095_captcha_failure_reason( $view );
			$details_base = 'block_captcha_attempt: ' . $provider;
			if ( $view_source !== $source
				|| ! in_array( $provider, array( 'recaptcha_v3', 'recaptcha_v2', 'hcaptcha', 'cloudflare' ), true )
				|| ( $details !== $details_base && 0 !== strpos( $details, $details_base . ' | ' ) )
				|| ! in_array( $reason, array( 'missing_token', 'reused_token', 'hostname_mismatch', 'action_mismatch', 'score_below_threshold', 'token_validation_failed' ), true ) ) {
				return null;
			}
			$kind = 'challenge';
			$producer = 'captcha';
			$event_name = $reason;
			$tampering = in_array( $reason, array( 'reused_token', 'hostname_mismatch', 'action_mismatch' ), true );
			$terminal = true;
		} elseif ( 'block' === $action && $this->bm0095_is_automation_row( $source, $details, $view ) ) {
			$kind = 'automation';
			$producer = 'automation';
			$event_name = 'automation_block';
			$tampering = true;
			$terminal = true;
		} else {
			return null;
		}

		$actors = $this->bm0095_log_actors( $view );
		if ( empty( $actors ) ) {
			return null;
		}
		// Current producers persist only token hash prefixes or challenge-presence
		// booleans. Neither is an exact challenge/session/request correlation key,
		// so successes must fail closed instead of clearing unrelated failures.
		$context = '';
		return array(
			'event_type' => 'log', 'id' => $id, 'timestamp' => $timestamp, 'actors' => $actors,
			'kind' => $kind, 'producer' => $producer, 'event' => $event_name,
			'tampering' => $tampering, 'terminal' => $terminal, 'success' => $success,
			'context_key' => $context,
		);
	}

	protected function bm0095_captcha_failure_reason( array $view ): string {
		$diagnostics = $this->bm0095_view_value( $view, array( array( 'reason' ) ) );
		if ( 'token_validation_failed' === $diagnostics ) {
			return 'token_validation_failed';
		}
		$decoded = json_decode( $diagnostics, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['reason'] ) || ! is_scalar( $decoded['reason'] ) ) {
			return '';
		}
		return sanitize_key( (string) $decoded['reason'] );
	}

	protected function bm0095_is_automation_row( string $source, string $details, array $view ): bool {
		$view_source = sanitize_key( (string) $this->bm0095_view_value( $view, array( array( 'source' ) ) ) );
		if ( ! in_array( $source, array( 'woo_checkout', 'woo_api_checkout' ), true ) || $view_source !== $source ) {
			return false;
		}
		return 0 === strpos( $details, 'block_bot_js_proof_attempt:' )
			|| 0 === strpos( $details, 'block_fingerprint_anomalies_attempt:' )
			|| 0 === strpos( $details, 'block_session_continuity_attempt:' );
	}

	protected function bm0095_log_actors( array $view ): array {
		$account = $this->bm0095_view_value( $view, array( array( 'customer_id' ), array( 'account', 'customer_id' ) ) );
		$account = is_numeric( $account ) && (int) $account > 0 ? (string) (int) $account : '';
		$ip      = $this->normalize_bot_signal_ip( $this->bm0095_view_value( $view, array( array( 'ip_address' ), array( 'request', 'ip' ) ) ) );
		if ( '' === $ip ) {
			$ip_hash = $this->normalize_bot_signal_token( $this->bm0095_view_value( $view, array( array( 'ip_hash' ), array( 'request', 'ip_hash' ) ) ) );
			$ip      = '' !== $ip_hash ? 'hash:' . $ip_hash : '';
		}
		return array_filter( array(
			'device' => $this->normalize_bot_signal_token( $this->bm0095_view_value( $view, array( array( 'device_id' ), array( 'device', 'id' ), array( 'device', 'device_id' ) ) ) ),
			'session' => $this->normalize_bot_signal_token( $this->bm0095_view_value( $view, array( array( 'session_id' ), array( 'request', 'session_id' ), array( 'device', 'session_id' ) ) ) ),
			'account' => $account, 'ip' => $ip,
			'phone' => $this->normalize_bot_signal_phone( $this->bm0095_view_value( $view, array( array( 'phone' ), array( 'billing', 'phone' ) ) ) ),
		) );
	}

	protected function bm0095_view_value( array $view, array $paths ) {
		foreach ( $paths as $path ) {
			$value = $this->find_log_value_by_path( $view, $path );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	protected function bm0095_apply_exact_context_resets( array $events ): array {
		usort( $events, static function ( array $a, array $b ): int { return (int) $a['timestamp'] <=> (int) $b['timestamp']; } );
		$kept = array();
		foreach ( $events as $event ) {
			if ( ! empty( $event['success'] ) && '' !== (string) $event['context_key'] ) {
				foreach ( $kept as $index => $prior ) {
					if ( (string) $prior['context_key'] === (string) $event['context_key'] ) {
						unset( $kept[ $index ] );
					}
				}
				continue;
			}
			if ( empty( $event['success'] ) ) {
				$kept[] = $event;
			}
		}
		return array_values( $kept );
	}

	protected function bm0095_paypal_flow_candidates( array $logs ): array {
		$events = array_values( array_filter( $logs, static function ( array $event ): bool { return 'paypal_flow' === $event['kind']; } ) );
		return $this->bm0095_group_window_candidates(
			$events, array( 'device', 'session', 'account', 'ip', 'phone' ), 300,
			'paypal_flow_suspected', 'PAYPAL_FLOW_TAMPERING',
			static function ( array $window ): array {
				$tampering = count( array_filter( $window, static function ( array $event ): bool { return ! empty( $event['tampering'] ); } ) );
				return array( count( $window ) >= 3 && $tampering >= 2, count( $window ), 0, 'paypal', '' );
			}
		);
	}

	protected function bm0095_card_testing_candidates( array $orders ): array {
		$events = array_values( array_filter( $orders, static function ( array $event ): bool {
			return ! empty( $event['provider']['eligible'] ) && empty( $event['provider']['suppressed'] );
		} ) );
		return $this->bm0095_group_window_candidates(
			$events, array( 'device', 'session', 'account' ), 900,
			'card_testing_suspected', 'PAYPAL_CARD_CRACKING_SEQUENCE',
			function ( array $window ): array {
				$identities = array();
				$classes    = array();
				$validating = 0;
				foreach ( $window as $event ) {
					if ( '' !== $event['billing_identity'] ) {
						$identities[ $event['billing_identity'] ] = true;
					}
					if ( ! empty( $event['provider']['classes'] ) ) {
						$validating++;
						foreach ( $event['provider']['classes'] as $class ) {
							$classes[ $class ] = true;
						}
					}
				}
				$qualifies = count( $window ) >= 4 && count( $identities ) >= 3 && count( $classes ) >= 2 && $validating >= 2;
				return array( $qualifies, count( $window ), count( $identities ), 'paypal', $this->bm0095_top_gateway( $window ) );
			}
		);
	}

	protected function bm0095_store_api_candidates( array $logs ): array {
		$events = array_values( array_filter( $logs, static function ( array $event ): bool { return in_array( $event['kind'], array( 'store_api', 'automation' ), true ); } ) );
		return $this->bm0095_group_window_candidates(
			$events, array( 'device', 'session', 'account', 'ip', 'phone' ), 300,
			'store_api_abuse', 'STORE_API_AUTOMATION_BURST',
			static function ( array $window ): array {
				$store = count( array_filter( $window, static function ( array $event ): bool { return 'store_api' === $event['kind']; } ) );
				$auto  = count( array_filter( $window, static function ( array $event ): bool { return 'automation' === $event['kind']; } ) );
				return array( $store >= 5 || ( $store >= 3 && $auto >= 2 ), $store + $auto, 0, '', '' );
			}
		);
	}

	protected function bm0095_challenge_candidates( array $logs ): array {
		$events = array_values( array_filter( $logs, static function ( array $event ): bool { return 'challenge' === $event['kind']; } ) );
		return $this->bm0095_group_window_candidates(
			$events, array( 'device', 'session', 'account', 'ip', 'phone' ), 300,
			'challenge_abuse', 'CHALLENGE_REPLAY_BURST',
			static function ( array $window, string $dimension ): array {
				$tampering = count( array_filter( $window, static function ( array $event ): bool { return ! empty( $event['tampering'] ); } ) );
				$terminal  = count( array_filter( $window, static function ( array $event ): bool { return ! empty( $event['terminal'] ); } ) );
				$strong    = in_array( $dimension, array( 'device', 'session', 'account' ), true );
				return array( count( $window ) >= 4 && ( $tampering >= 2 || ( $strong && $terminal >= 2 ) ), count( $window ), 0, '', '' );
			}
		);
	}

	protected function bm0095_repeat_identity_candidates( array $logs ): array {
		return $this->bm0095_group_window_candidates(
			$logs, array( 'device', 'session', 'account', 'ip', 'phone' ), 900,
			'repeat_blocked_identity', 'MULTI_SOURCE_REPEAT_ACTOR',
			static function ( array $window, string $dimension ): array {
				$producers = array();
				foreach ( $window as $event ) {
					$producers[ $event['producer'] ] = true;
				}
				$strong = in_array( $dimension, array( 'device', 'session', 'account' ), true );
				return array( count( $window ) >= ( $strong ? 4 : 6 ) && count( $producers ) >= 2, count( $window ), 0, '', '' );
			}
		);
	}

	protected function bm0095_velocity_candidates( array $orders, array $logs ): array {
		$automation = array_values( array_filter( $logs, static function ( array $event ): bool { return 'automation' === $event['kind']; } ) );
		return $this->bm0095_group_window_candidates(
			array_merge( $orders, $automation ), array( 'device', 'session', 'account', 'ip' ), 900,
			'checkout_velocity_spike', 'LINKED_CHECKOUT_FANOUT',
			function ( array $window, string $dimension ): array {
				$order_rows = array_values( array_filter( $window, static function ( array $event ): bool { return 'order' === $event['event_type']; } ) );
				$auto_count = count( $window ) - count( $order_rows );
				$emails = array();
				$phones = array();
				$amounts = array();
				$origins = 0;
				foreach ( $order_rows as $event ) {
					$email = (string) ( $event['billing_email'] ?? '' );
					$phone = (string) ( $event['billing_phone'] ?? '' );
					if ( '' !== $email ) {
						$emails[ $email ] = true;
					}
					if ( '' !== $phone ) {
						$phones[ $phone ] = true;
					}
					if ( '' === $email && '' === $phone ) {
						$identity = (string) ( $event['billing_identity'] ?? '' );
						if ( 0 === strpos( $identity, 'email:' ) ) {
							$emails[ $identity ] = true;
						} elseif ( 0 === strpos( $identity, 'phone:' ) ) {
							$phones[ $identity ] = true;
						}
					}
					if ( '' !== $event['amount_signature'] ) {
						$this->increment_counter( $amounts, $event['amount_signature'] );
					}
					if ( in_array( $event['created_via'], array( 'store-api', 'rest-api' ), true ) ) {
						$origins++;
					}
				}
				$fanout = max( count( $emails ), count( $phones ) );
				$fanout_ok = count( $emails ) >= 4 || count( $phones ) >= 3;
				$strong = in_array( $dimension, array( 'device', 'session', 'account' ), true );
				$shape = $strong
					? ( $this->counter_top_value( $amounts ) >= 4 || $origins >= 4 || $auto_count >= 2 )
					: $auto_count >= 2;
				return array( count( $order_rows ) >= 6 && $fanout_ok && $shape, count( $order_rows ) + $auto_count, $fanout, '', $this->bm0095_top_gateway( $order_rows ) );
			}
		);
	}

	protected function bm0095_group_window_candidates( array $events, array $dimensions, int $window_seconds, string $family, string $mode, callable $qualifier ): array {
		$groups = array();
		foreach ( $events as $event ) {
			foreach ( $dimensions as $dimension ) {
				$value = isset( $event['actors'][ $dimension ] ) ? (string) $event['actors'][ $dimension ] : '';
				if ( '' === $value ) {
					continue;
				}
				$key = $dimension . ':' . $value;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array( 'dimension' => $dimension, 'value' => $value, 'events' => array() );
				}
				$groups[ $key ]['events'][] = $event;
			}
		}

		$candidates = array();
		foreach ( $groups as $group ) {
			usort( $group['events'], static function ( array $a, array $b ): int { return (int) $a['timestamp'] <=> (int) $b['timestamp']; } );
			foreach ( $this->bm0095_quiet_gap_segments( $group['events'] ) as $segment ) {
				$count = count( $segment );
				for ( $start = 0; $start < $count; $start++ ) {
					$window = array();
					$limit = (int) $segment[ $start ]['timestamp'] + $window_seconds;
					for ( $cursor = $start; $cursor < $count && (int) $segment[ $cursor ]['timestamp'] <= $limit; $cursor++ ) {
						$window[] = $segment[ $cursor ];
					}
					$result = call_user_func( $qualifier, $window, $group['dimension'] );
					if ( empty( $result[0] ) ) {
						continue;
					}
					$sources = array();
					foreach ( $window as $event ) {
						$sources[] = isset( $event['producer'] ) ? (string) $event['producer'] : 'order';
					}
					$sources = array_values( array_unique( $sources ) );
					sort( $sources );
					$last_seen = max( array_map( static function ( array $event ): int { return (int) $event['timestamp']; }, $window ) );
					$candidates[] = $this->bm0095_candidate(
						$family, $mode, (int) $segment[0]['timestamp'], $last_seen,
						$group['dimension'], $group['value'], (int) $result[1], (int) $result[2],
						(string) $result[3], (string) $result[4], $sources
					);
					break;
				}
			}
		}
		return $candidates;
	}

	protected function bm0095_quiet_gap_segments( array $events ): array {
		$segments = array();
		$current  = array();
		$previous = 0;
		foreach ( $events as $event ) {
			$timestamp = (int) $event['timestamp'];
			if ( ! empty( $current ) && $timestamp - $previous >= ( 30 * MINUTE_IN_SECONDS ) ) {
				$segments[] = $current;
				$current = array();
			}
			$current[] = $event;
			$previous = $timestamp;
		}
		if ( ! empty( $current ) ) {
			$segments[] = $current;
		}
		return $segments;
	}

	protected function bm0095_candidate( string $family, string $mode, int $episode_start, int $last_seen, string $dimension, string $actor, int $event_count, int $fanout_count, string $provider, string $gateway, array $sources ): array {
		unset( $sources );
		$cohort = $this->bm0095_hmac( $dimension . '|' . $actor );
		// Source/count growth inside the same quiet-gap segment must not churn the
		// episode identity after dismissal. The closed family/mode already encodes
		// the qualifying producer contract.
		$hash   = $this->bm0095_hmac( implode( '|', array( '1', $family, $mode, 'LOCAL_STRUCTURAL', $cohort, (string) $episode_start ) ) );
		return array(
			'family' => $family, 'mode' => $mode, 'origin' => 'LOCAL_STRUCTURAL',
			'episode_start' => $episode_start, 'last_seen' => $last_seen, 'episode_hash' => $hash,
			'event_count' => max( 0, $event_count ), 'identity_dimension' => $dimension,
			'identity_count' => max( 0, $event_count ), 'fanout_count' => max( 0, $fanout_count ),
			'provider' => sanitize_key( $provider ), 'gateway' => sanitize_key( $gateway ),
		);
	}

	protected function bm0095_hmac( string $value ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'bm0095-test-salt';
		return hash_hmac( 'sha256', $value, $salt );
	}

	protected function bm0095_top_gateway( array $events ): string {
		$allowed = array( 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-card-button-gateway' );
		$gateways = array();
		foreach ( $events as $event ) {
			if ( in_array( (string) ( $event['gateway'] ?? '' ), $allowed, true ) ) {
				$this->increment_counter( $gateways, (string) $event['gateway'] );
			}
		}
		return $this->counter_top_key( $gateways );
	}

	protected function bm0095_select_candidate( array $candidates ) {
		if ( empty( $candidates ) ) {
			return null;
		}
		$precedence = array_flip( $this->bm0095_families() );
		$actor_precedence = array_flip( array( 'device', 'session', 'account', 'ip', 'phone' ) );
		usort( $candidates, static function ( array $left, array $right ) use ( $precedence, $actor_precedence ): int {
			if ( (int) $left['last_seen'] !== (int) $right['last_seen'] ) {
				return (int) $right['last_seen'] <=> (int) $left['last_seen'];
			}
			if ( (int) $precedence[ $left['family'] ] !== (int) $precedence[ $right['family'] ] ) {
				return (int) $precedence[ $left['family'] ] <=> (int) $precedence[ $right['family'] ];
			}
			if ( (int) $actor_precedence[ $left['identity_dimension'] ] !== (int) $actor_precedence[ $right['identity_dimension'] ] ) {
				return (int) $actor_precedence[ $left['identity_dimension'] ] <=> (int) $actor_precedence[ $right['identity_dimension'] ];
			}
			return strcmp( (string) $left['episode_hash'], (string) $right['episode_hash'] );
		} );
		return $candidates[0];
	}

	protected function maybe_clear_shared_notice_state( array $summary, string $option_name, string $user_meta_key ): void {
		if ( empty( $summary['show'] ) || empty( $summary['fingerprint'] ) ) {
			return;
		}

		$current_fp = (string) $summary['fingerprint'];
		$stored_fp  = (string) get_option( $option_name, '' );

		if ( $current_fp && $current_fp !== $stored_fp ) {
			delete_metadata( 'user', 0, $user_meta_key, '', true );
			update_option( $option_name, $current_fp, false );
		}
	}
}
