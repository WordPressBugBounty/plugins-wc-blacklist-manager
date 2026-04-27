<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait YOBM_Bot_Signal_Analyzer {

	abstract protected function get_bot_signal_cache_key(): string;

	protected function get_bot_signal_summary_shared(): array {
		$cache_key = $this->get_bot_signal_cache_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$window_start_ts  = time() - self::ANALYSIS_WINDOW_SECONDS;
		$window_start_sql = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::ANALYSIS_WINDOW_SECONDS );

		$order_ids = wc_get_orders(
			[
				'limit'   => 100,
				'return'  => 'ids',
				'status'  => [ 'failed', 'cancelled', 'pending', 'on-hold', 'checkout-draft' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$order_data = $this->analyze_orders_shared( $order_ids, $window_start_ts );
		$log_data   = $this->analyze_detection_logs_shared( $window_start_sql );

		$data = $this->merge_bot_signal_data( $order_data, $log_data );

		$score   = 0;
		$reasons = [];

		if ( $data['suspicious_orders'] >= self::MIN_SUSPICIOUS_ORDERS ) {
			$score += 3;
			$reasons[] = 'many_suspicious_orders';
		}

		if ( $data['blocked_attempts'] >= 3 ) {
			$score += 3;
			$reasons[] = 'blocked_attempts';
		}

		if ( $data['unique_emails'] >= self::MIN_UNIQUE_EMAILS ) {
			$score += 2;
			$reasons[] = 'many_emails';
		}

		if ( $data['top_ip_hits'] >= self::MIN_TOP_IP_HITS ) {
			$score += 3;
			$reasons[] = 'same_ip';
		}

		if ( $data['top_device_hits'] >= 3 ) {
			$score += 4;
			$reasons[] = 'same_device';
		}

		if ( $data['top_session_hits'] >= 4 ) {
			$score += 3;
			$reasons[] = 'same_session';
		}

		if ( $data['top_phone_hits'] >= 3 ) {
			$score += 2;
			$reasons[] = 'same_phone';
		}

		if ( $data['top_email_domain_hits'] >= 5 ) {
			$score += 2;
			$reasons[] = 'same_email_domain';
		}

		if ( $data['hot_minute_hits'] >= self::MIN_HOT_MINUTE_HITS ) {
			$score += 2;
			$reasons[] = 'burst';
		}

		if ( $data['burst_span_seconds'] > 0 && $data['burst_span_seconds'] <= self::MAX_BURST_SPAN_SECONDS ) {
			$score += 2;
			$reasons[] = 'short_span';
		}

		if ( $data['store_api_attempts'] >= 3 ) {
			$score += 2;
			$reasons[] = 'store_api_attack';
		}

		if ( $data['rest_api_attempts'] >= 2 ) {
			$score += 2;
			$reasons[] = 'rest_api_attack';
		}

		$severity = 'none';

		if (
			$score >= 9 ||
			$data['blocked_attempts'] >= 5 ||
			$data['top_device_hits'] >= 5 ||
			$data['top_ip_hits'] >= 6 ||
			$data['hot_minute_hits'] >= 6
		) {
			$severity = 'critical';
		} elseif ( $score >= 5 ) {
			$severity = 'warning';
		}

		$fingerprint = md5(
			wp_json_encode(
				[
					'orders'  => $data['suspicious_orders'],
					'blocked' => $data['blocked_attempts'],
					'ip'      => $data['top_ip'],
					'device'  => $data['top_device'],
					'time'    => $data['newest_ts'],
				]
			)
		);

		$summary = array_merge(
			$data,
			[
				'score'       => $score,
				'severity'    => $severity,
				'show'        => ( 'none' !== $severity ),
				'reasons'     => $reasons,
				'fingerprint' => $fingerprint,
			]
		);

		set_transient( $cache_key, $summary, self::CACHE_TTL );

		return $summary;
	}

	protected function analyze_orders_shared( $order_ids, $window_start ): array {
		$data = $this->empty_bot_signal_data();

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

			$data['timestamps'][] = $ts;

			$email = $this->normalize_bot_signal_email( $order->get_billing_email() );
			if ( $email ) {
				$data['emails'][ $email ] = true;

				$domain = $this->get_email_domain( $email );
				if ( $domain ) {
					$this->increment_counter( $data['email_domains'], $domain );
				}
			}

			$phone = $this->normalize_bot_signal_phone( $order->get_billing_phone() );
			if ( $phone ) {
				$this->increment_counter( $data['phones'], $phone );
			}

			$ip = $this->normalize_bot_signal_ip( $order->get_customer_ip_address() );
			if ( $ip ) {
				$this->increment_counter( $data['ips'], $ip );
			}

			$device_id = $this->normalize_bot_signal_token( $order->get_meta( '_wc_bm_device_id', true ) );
			if ( $device_id ) {
				$this->increment_counter( $data['devices'], $device_id );
			}

			$session_id = $this->normalize_bot_signal_token( $order->get_meta( '_wc_bm_session_id', true ) );
			if ( $session_id ) {
				$this->increment_counter( $data['sessions'], $session_id );
			}

			$gateway = (string) $order->get_payment_method();
			if ( '' !== $gateway ) {
				$this->increment_counter( $data['gateways'], $gateway );
			}

			$status = $order->get_status();
			if ( 'checkout-draft' === $status ) {
				$data['checkout_draft_count']++;
			}

			$bucket = gmdate( 'Y-m-d H:i', $ts );
			$this->increment_counter( $data['minute_buckets'], $bucket );
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

		$date_column = $this->get_existing_column( $table, [ 'date_added', 'created_at', 'date', 'log_date' ] );
		if ( ! $date_column ) {
			return $this->finalize_bot_signal_data( $data );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$date_column} >= %s ORDER BY {$date_column} DESC LIMIT 300",
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

			$is_bot_or_block = (
				'bot' === $type ||
				'block' === $action ||
				false !== strpos( $details, 'block_' ) ||
				false !== strpos( $details, 'bot' )
			);

			if ( ! $is_bot_or_block ) {
				continue;
			}

			$data['blocked_attempts']++;

			if ( false !== strpos( $source, 'store_api' ) || false !== strpos( $source, 'woo_store_api' ) ) {
				$data['store_api_attempts']++;
			}

			if ( false !== strpos( $source, 'api' ) || false !== strpos( $source, 'rest' ) ) {
				$data['rest_api_attempts']++;
			}

			$ip = $this->extract_log_value( $row, [ 'ip_address', 'customer_ip', 'ip' ] );
			$ip = $this->normalize_bot_signal_ip( $ip );
			if ( $ip ) {
				$this->increment_counter( $data['ips'], $ip );
			}

			$device_id = $this->extract_log_value( $row, [ 'device_id', 'device' ] );
			$device_id = $this->normalize_bot_signal_token( $device_id );
			if ( $device_id ) {
				$this->increment_counter( $data['devices'], $device_id );
			}

			$session_id = $this->extract_log_value( $row, [ 'session_id', 'session' ] );
			$session_id = $this->normalize_bot_signal_token( $session_id );
			if ( $session_id ) {
				$this->increment_counter( $data['sessions'], $session_id );
			}

			$email = $this->normalize_bot_signal_email( $this->extract_log_value( $row, [ 'email', 'email_address' ] ) );
			if ( $email ) {
				$data['emails'][ $email ] = true;

				$domain = $this->get_email_domain( $email );
				if ( $domain ) {
					$this->increment_counter( $data['email_domains'], $domain );
				}
			}

			$phone = $this->normalize_bot_signal_phone( $this->extract_log_value( $row, [ 'phone', 'phone_number' ] ) );
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
			'checkout_draft_count'  => 0,
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
			'checkout_draft_count'   => (int) $data['checkout_draft_count'],

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
			'checkout_draft_count',
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

			foreach ( $keys as $key ) {
				if ( preg_match( '/"' . preg_quote( $key, '/' ) . '"\s*:\s*"([^"]+)"/', $blob, $m ) ) {
					return $m[1];
				}
			}
		}

		return '';
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