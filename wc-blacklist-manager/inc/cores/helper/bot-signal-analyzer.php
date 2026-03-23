<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait YOBM_Bot_Signal_Analyzer {

	abstract protected function get_bot_signal_cache_key(): string;

	abstract protected function get_bot_signal_debug_label(): string;

	protected function get_bot_signal_summary_shared(): array {
		$cache_key = $this->get_bot_signal_cache_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$window_start  = time() - self::ANALYSIS_WINDOW_SECONDS;
		$base_statuses = [ 'failed', 'cancelled' ];

		$order_ids = wc_get_orders(
			[
				'limit'   => 50,
				'return'  => 'ids',
				'status'  => $base_statuses,
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$data = $this->analyze_orders_shared( $order_ids, $window_start );

		// Only include pending orders when the pattern already looks suspicious.
		if (
			$data['top_ip_hits'] >= self::MIN_TOP_IP_HITS ||
			$data['hot_minute_hits'] >= self::MIN_HOT_MINUTE_HITS ||
			$data['unique_emails'] >= self::MIN_UNIQUE_EMAILS
		) {
			$pending_ids = wc_get_orders(
				[
					'limit'   => 30,
					'return'  => 'ids',
					'status'  => [ 'pending' ],
					'orderby' => 'date',
					'order'   => 'DESC',
				]
			);

			if ( ! empty( $pending_ids ) ) {
				$order_ids = array_values( array_unique( array_merge( $order_ids, $pending_ids ) ) );
				$data      = $this->analyze_orders_shared( $order_ids, $window_start );
			}
		}

		$score   = 0;
		$reasons = [];

		if ( $data['suspicious_orders'] >= self::MIN_SUSPICIOUS_ORDERS ) {
			$score += 3;
			$reasons[] = 'order_count';
		}

		if ( $data['unique_emails'] >= self::MIN_UNIQUE_EMAILS ) {
			$score += 2;
			$reasons[] = 'many_emails';
		}

		if ( $data['top_ip_hits'] >= self::MIN_TOP_IP_HITS ) {
			$score += 3;
			$reasons[] = 'same_ip';
		}

		if ( $data['hot_minute_hits'] >= self::MIN_HOT_MINUTE_HITS ) {
			$score += 2;
			$reasons[] = 'burst';
		}

		if ( $data['burst_span_seconds'] > 0 && $data['burst_span_seconds'] <= self::MAX_BURST_SPAN_SECONDS ) {
			$score += 2;
			$reasons[] = 'short_span';
		}

		$severity = 'none';

		if ( $score >= 8 || $data['top_ip_hits'] >= 5 ) {
			$severity = 'critical';
		} elseif ( $score >= 5 ) {
			$severity = 'warning';
		}

		$show = ( 'none' !== $severity );

		$fingerprint = md5(
			wp_json_encode(
				[
					'orders' => $data['suspicious_orders'],
					'ip'     => $data['top_ip'],
					'time'   => $data['newest_ts'],
				]
			)
		);

		$summary = array_merge(
			$data,
			[
				'score'       => $score,
				'severity'    => $severity,
				'show'        => $show,
				'reasons'     => $reasons,
				'fingerprint' => $fingerprint,
			]
		);

		set_transient( $cache_key, $summary, self::CACHE_TTL );

		return $summary;
	}

	protected function analyze_orders_shared( $order_ids, $window_start ): array {
		$emails         = [];
		$ips            = [];
		$gateways       = [];
		$timestamps     = [];
		$minute_buckets = [];

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

			$email = $this->normalize_bot_signal_email( $order->get_billing_email() );
			if ( $email ) {
				$emails[ $email ] = true;
			}

			$ip = $this->normalize_bot_signal_ip( $order->get_customer_ip_address() );
			if ( $ip ) {
				$ips[ $ip ] = isset( $ips[ $ip ] ) ? $ips[ $ip ] + 1 : 1;
			}

			$gateway = (string) $order->get_payment_method();
			if ( '' !== $gateway ) {
				$gateways[ $gateway ] = isset( $gateways[ $gateway ] ) ? $gateways[ $gateway ] + 1 : 1;
			}

			$bucket = gmdate( 'Y-m-d H:i', $ts );
			$minute_buckets[ $bucket ] = isset( $minute_buckets[ $bucket ] ) ? $minute_buckets[ $bucket ] + 1 : 1;

			$timestamps[] = $ts;
		}

		arsort( $ips );
		arsort( $gateways );
		arsort( $minute_buckets );

		$oldest_ts = ! empty( $timestamps ) ? min( $timestamps ) : 0;
		$newest_ts = ! empty( $timestamps ) ? max( $timestamps ) : 0;

		return [
			'suspicious_orders'  => count( $timestamps ),
			'unique_emails'      => count( $emails ),
			'unique_ips'         => count( $ips ),
			'top_ip'             => ! empty( $ips ) ? (string) array_key_first( $ips ) : '',
			'top_ip_hits'        => ! empty( $ips ) ? (int) reset( $ips ) : 0,
			'top_gateway'        => ! empty( $gateways ) ? (string) array_key_first( $gateways ) : '',
			'top_gateway_hits'   => ! empty( $gateways ) ? (int) reset( $gateways ) : 0,
			'hot_minute_hits'    => ! empty( $minute_buckets ) ? (int) reset( $minute_buckets ) : 0,
			'burst_span_seconds' => ( $oldest_ts && $newest_ts ) ? max( 0, $newest_ts - $oldest_ts ) : 0,
			'newest_ts'          => $newest_ts,
		];
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

	protected function normalize_bot_signal_ip( $ip ): string {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		if ( '' === $ip ) {
			return '';
		}

		$validated = filter_var( $ip, FILTER_VALIDATE_IP );

		return $validated ? $validated : '';
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