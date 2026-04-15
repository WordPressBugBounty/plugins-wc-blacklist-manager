<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'get_real_customer_ip' ) ) {
	/**
	 * Get the best trustworthy public client IP for the current request.
	 *
	 * Priority:
	 * 1. CF-Connecting-IP when REMOTE_ADDR is a trusted Cloudflare IP
	 * 2. First public IP from X-Forwarded-For when REMOTE_ADDR is a trusted proxy
	 * 3. REMOTE_ADDR
	 *
	 * Returns:
	 * - Public IP string when available
	 * - Empty string when no valid IP is available
	 */
	function get_real_customer_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
			? trim( explode( ',', wp_unslash( $_SERVER['REMOTE_ADDR'] ) )[0] )
			: '';

		$cloudflare_proxies = apply_filters(
			'yobmp_cloudflare_proxies',
			[
				'173.245.48.0/20',
				'103.21.244.0/22',
				'103.22.200.0/22',
				'103.31.4.0/22',
				'141.101.64.0/18',
				'108.162.192.0/18',
				'190.93.240.0/20',
				'188.114.96.0/20',
				'197.234.240.0/22',
				'198.41.128.0/17',
				'162.158.0.0/15',
				'104.16.0.0/13',
				'104.24.0.0/14',
				'172.64.0.0/13',
				'131.0.72.0/22',
				'2400:cb00::/32',
				'2606:4700::/32',
				'2803:f800::/32',
				'2405:b500::/32',
				'2405:8100::/32',
				'2a06:98c0::/29',
				'2c0f:f248::/32',
			]
		);

		$trusted_proxies = apply_filters( 'yobmp_trusted_proxies', [] );

		$is_valid_ip = static function ( string $ip ): bool {
			return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
		};

		$is_public_ip = static function ( string $ip ): bool {
			return (bool) filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		};

		$ip_in_cidr = static function ( string $ip, string $cidr ): bool {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return false;
			}

			$parts = explode( '/', $cidr, 2 );
			if ( 2 !== count( $parts ) ) {
				return false;
			}

			$subnet = $parts[0];
			$mask   = (int) $parts[1];

			if ( ! filter_var( $subnet, FILTER_VALIDATE_IP ) || $mask < 0 ) {
				return false;
			}

			$ip_bin     = inet_pton( $ip );
			$subnet_bin = inet_pton( $subnet );

			if ( false === $ip_bin || false === $subnet_bin ) {
				return false;
			}

			if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
				return false;
			}

			$max_bits = 8 * strlen( $ip_bin );
			if ( $mask > $max_bits ) {
				return false;
			}

			$full_bytes = intdiv( $mask, 8 );
			$remaining  = $mask % 8;

			for ( $i = 0; $i < $full_bytes; $i++ ) {
				if ( $ip_bin[ $i ] !== $subnet_bin[ $i ] ) {
					return false;
				}
			}

			if ( $remaining > 0 ) {
				$mask_byte = chr( 0xFF << ( 8 - $remaining ) );
				if ( ( ord( $ip_bin[ $full_bytes ] ) & ord( $mask_byte ) ) !== ( ord( $subnet_bin[ $full_bytes ] ) & ord( $mask_byte ) ) ) {
					return false;
				}
			}

			return true;
		};

		$is_from_cloudflare = false;
		$is_from_trusted_proxy = false;

		if ( $is_valid_ip( $remote_addr ) ) {
			foreach ( $cloudflare_proxies as $cidr ) {
				if ( $ip_in_cidr( $remote_addr, $cidr ) ) {
					$is_from_cloudflare = true;
					break;
				}
			}

			if ( ! $is_from_cloudflare ) {
				foreach ( $trusted_proxies as $cidr ) {
					if ( $ip_in_cidr( $remote_addr, $cidr ) ) {
						$is_from_trusted_proxy = true;
						break;
					}
				}
			}
		}

		$client_ip = '';

		// 1) Trust CF-Connecting-IP only if the request actually came from Cloudflare.
		if ( $is_from_cloudflare && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf_ip = is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] )
				? trim( explode( ',', wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )[0] )
				: '';

			if ( $is_public_ip( $cf_ip ) ) {
				$client_ip = $cf_ip;
			}
		}

		// 2) Trust X-Forwarded-For only if the request came from a trusted proxy.
		if ( '' === $client_ip && ( $is_from_cloudflare || $is_from_trusted_proxy ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff = is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] )
				? explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
				: [];

			foreach ( $xff as $candidate ) {
				$candidate = trim( $candidate );
				if ( $is_public_ip( $candidate ) ) {
					$client_ip = $candidate;
					break;
				}
			}
		}

		// 3) Fall back to REMOTE_ADDR.
		if ( '' === $client_ip && $is_valid_ip( $remote_addr ) ) {
			$client_ip = $remote_addr;
		}

		// 4) Optional non-production testing override.
		$env        = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$testing_ip = apply_filters( 'yobmp_testing_ip', '' );

		if ( 'production' !== $env && ! $is_public_ip( $client_ip ) && $is_public_ip( $testing_ip ) ) {
			$client_ip = $testing_ip;
		}

		return $is_valid_ip( $client_ip ) ? $client_ip : '';
	}
}

// WooCommerce Classic Checkout: set real client IP on order.
add_action( 'woocommerce_checkout_order_processed', 'correct_customer_ip_in_order', 10, 1 );

if ( ! function_exists( 'correct_customer_ip_in_order' ) ) {
	function correct_customer_ip_in_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$ip_address = get_real_customer_ip();

		if ( '' !== $ip_address ) {
			$order->set_customer_ip_address( $ip_address );
			$order->save();
		}
	}
}

// WooCommerce Blocks (Store API): set real client IP on order.
add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	'correct_customer_ip_in_order_blocks',
	10,
	2
);

if ( ! function_exists( 'correct_customer_ip_in_order_blocks' ) ) {
	/**
	 * Ensure the order stores the best trustworthy client IP for Block Checkout.
	 *
	 * @param WC_Order        $order   WooCommerce order object.
	 * @param WP_REST_Request $request REST request object.
	 */
	function correct_customer_ip_in_order_blocks( $order, $request ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$ip_address = get_real_customer_ip();

		if ( '' !== $ip_address ) {
			$order->set_customer_ip_address( $ip_address );
		}
	}
}