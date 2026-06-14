<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'YOBM_License_Runtime' ) ) {

	final class YOBM_License_Runtime {

		const DEFAULT_GRACE_PERIOD = 7 * DAY_IN_SECONDS;
		const ENTITLEMENT_ISSUER   = 'yoohw-license-v5';
		const ENTITLEMENT_AUDIENCE = 'wc-blacklist-manager-premium';
		const ENTITLEMENT_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA4e7JsLA4SG9TdF4YJxVF\nEtYShuBV0PijDJsk7wlxe3P7sHeeQBt528S+YzBEagopIeOCYznL6GWXHwIP7oCz\nWEf1y/hRKljIfZnBRkOVUh/l1Pnl5XeqOvGX/0/g4P8m3AOEeO1vqH7h8AOOx/kL\nH8raEUFcxVdKJaw3n81+a691G+0gMR2z6oXw1Cr2k3GMP9heKAvj90anbXxkroYG\nKlL8J2F8Gw3+FTl+AQKqIRIf/PXrKINRlg/JfCtEeq70W4eWzC3VU2LBDW96iYXi\n8mvU0ZyYCGFpzO2HoSpSXePSu20SoUgA9MAXP6FFEonNtXjpsFzt/G4lVb7cfjOQ\nVQIDAQAB\n-----END PUBLIC KEY-----";

		public static function normalize_domain( $url_or_domain ) {
			$raw  = (string) $url_or_domain;
			$host = wp_parse_url( $raw, PHP_URL_HOST );

			if ( empty( $host ) ) {
				$host = $raw;
			}

			$host = strtolower( trim( $host ) );
			$host = preg_replace( '/^www\./i', '', $host );
			$host = rtrim( $host, '.' );

			return (string) $host;
		}

		public static function get_default_state( $product_id = '' ) {
			return [
				'status'           => 'deactivated', // activated|grace|deactivated|invalid|expired
				'product_id'       => (string) $product_id,
				'domain'           => '',
				'last_check_at'    => 0,
				'last_success_at'  => 0,
				'last_error_at'    => 0,
				'last_error_code'  => '',
				'last_server_code' => '',
					'expires_at'       => 0,
					'grace_until'      => 0,
					'entitlement_expires_at' => 0,
					'entitlement_checked_at' => 0,
					'activated_at'     => 0,
					'updated_at'       => 0,
					'checksum'         => '',
				];
			}

		public static function get_state( $state_option, $product_id = '' ) {
			$state   = get_option( $state_option, [] );
			$default = self::get_default_state( $product_id );

			if ( ! is_array( $state ) ) {
				$state = [];
			}

			$state = wp_parse_args( $state, $default );

			if ( empty( $state_option ) ) {
				return $state;
			}

			if ( ! empty( $state['checksum'] ) ) {
				update_option( self::checksum_required_option( $state_option ), 1, false );

				if ( ! self::state_checksum_valid( $state ) ) {
					$state['status']          = 'deactivated';
					$state['last_error_code'] = 'state_checksum_mismatch';
					$state['checksum_valid']  = false;
				}

				return $state;
			}

			if ( get_option( self::checksum_required_option( $state_option ), false ) ) {
				$state['status']          = 'deactivated';
				$state['last_error_code'] = 'state_checksum_missing';
				$state['checksum_valid']  = false;

				return $state;
			}

			$state['checksum'] = self::state_checksum( $state );
			update_option( $state_option, $state, false );
			update_option( self::checksum_required_option( $state_option ), 1, false );

			return $state;
		}

		public static function update_state( $state_option, array $new_state, $product_id = '' ) {
			$current = self::get_state( $state_option, $product_id );
			$merged  = array_merge( $current, $new_state );

			$merged['updated_at'] = time();
			$merged['checksum']   = self::state_checksum( $merged );

			update_option( $state_option, $merged );
			update_option( self::checksum_required_option( $state_option ), 1, false );

			return $merged;
		}

		private static function checksum_required_option( $state_option ) {
			return (string) $state_option . '_checksum_required';
		}

		public static function refresh_required_option( $state_option ) {
			return (string) $state_option . '_refresh_required';
		}

		public static function needs_refresh( $state_option ) {
			return (bool) get_option( self::refresh_required_option( $state_option ), false );
		}

		public static function mark_refresh_required( $state_option ) {
			update_option( self::refresh_required_option( $state_option ), 1, false );
		}

		public static function clear_refresh_required( $state_option ) {
			delete_option( self::refresh_required_option( $state_option ) );
		}

		private static function checksum_secret() {
			$parts = [];
			foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ] as $constant ) {
				if ( defined( $constant ) ) {
					$parts[] = (string) constant( $constant );
				}
			}

			$secret = implode( '|', array_filter( $parts ) );
			if ( '' === $secret && function_exists( 'wp_salt' ) ) {
				$secret = wp_salt( 'auth' );
			}

			return '' !== $secret ? $secret : 'yoohw-license-runtime';
		}

		private static function state_checksum_payload( array $state ) {
			$keys    = [
				'status',
				'product_id',
				'domain',
				'last_check_at',
				'last_success_at',
				'last_error_at',
				'last_error_code',
				'last_server_code',
				'expires_at',
				'grace_until',
				'entitlement_expires_at',
				'entitlement_checked_at',
				'activated_at',
				'updated_at',
			];
			$payload = [];

			foreach ( $keys as $key ) {
				$payload[ $key ] = isset( $state[ $key ] ) ? $state[ $key ] : '';
			}

			return wp_json_encode( $payload );
		}

		private static function state_checksum( array $state ) {
			return hash_hmac( 'sha256', self::state_checksum_payload( $state ), self::checksum_secret() );
		}

		private static function state_checksum_valid( array $state ) {
			if ( empty( $state['checksum'] ) ) {
				return false;
			}

			return hash_equals( self::state_checksum( $state ), (string) $state['checksum'] );
		}

		public static function mark_activated( $args ) {
			$args = wp_parse_args(
				$args,
				[
					'license_key_option' => '',
					'license_key'        => '',
					'state_option'       => '',
					'status_option'      => '',
					'entitlement_option' => '',
					'entitlement_token'  => '',
					'product_id'         => '',
					'domain'             => self::normalize_domain( home_url() ),
					'expires_at'         => 0,
					'server_code'        => 'valid',
					'grace_period'       => self::DEFAULT_GRACE_PERIOD,
				]
			);

			$now           = time();
			$current_state = self::get_state( $args['state_option'], $args['product_id'] );

			$activated_at = ! empty( $current_state['activated_at'] ) ? (int) $current_state['activated_at'] : $now;
			$license_key  = '' !== trim( (string) $args['license_key'] ) ? (string) $args['license_key'] : ( ! empty( $args['license_key_option'] ) ? (string) get_option( $args['license_key_option'], '' ) : '' );
			$entitlement  = self::verify_entitlement(
				(string) $args['entitlement_token'],
				[
					'license_key' => $license_key,
					'product_id'  => $args['product_id'],
					'domain'      => $args['domain'],
				]
			);
			$has_entitlement_gate = ! empty( $args['entitlement_option'] );
			$entitlement_valid    = is_array( $entitlement );
			$can_grace            = self::can_use_entitlement_grace( $current_state, $now );
			$next_status          = 'activated';
			$next_grace_until     = $now + (int) $args['grace_period'];
			$last_success_at      = $now;

			if ( $has_entitlement_gate && ! $entitlement_valid ) {
				$next_status      = $can_grace ? 'grace' : 'deactivated';
				$next_grace_until = self::capped_grace_until( $current_state, $now, (int) $args['grace_period'] );
				$last_success_at  = (int) $current_state['last_success_at'];
			}

			$entitlement_expires = $entitlement_valid && ! empty( $entitlement['exp'] ) ? (int) $entitlement['exp'] : (int) $current_state['entitlement_expires_at'];

			if ( $entitlement_valid && ! empty( $args['entitlement_option'] ) ) {
				update_option( $args['entitlement_option'], (string) $args['entitlement_token'], false );
			}

			$state = self::update_state(
				$args['state_option'],
				[
					'status'           => $next_status,
					'product_id'       => (string) $args['product_id'],
					'domain'           => (string) $args['domain'],
					'last_check_at'    => $now,
					'last_success_at'  => $last_success_at,
					'last_error_at'    => $entitlement_valid || ! $has_entitlement_gate ? 0 : $now,
					'last_error_code'  => $entitlement_valid || ! $has_entitlement_gate ? '' : 'entitlement_missing',
					'last_server_code' => (string) $args['server_code'],
					'expires_at'       => (int) $args['expires_at'],
					'grace_until'      => $next_grace_until,
					'entitlement_expires_at' => $entitlement_expires,
					'entitlement_checked_at' => $entitlement_valid ? $now : (int) $current_state['entitlement_checked_at'],
					'activated_at'     => $activated_at,
				],
				$args['product_id']
			);

			if ( ! empty( $args['status_option'] ) ) {
				update_option( $args['status_option'], in_array( (string) $state['status'], [ 'activated', 'grace' ], true ) ? 'activated' : 'deactivated' );
			}

			return $state;
		}

		public static function mark_grace( $args ) {
			$args = wp_parse_args(
				$args,
				[
					'state_option'  => '',
					'status_option' => '',
					'product_id'    => '',
					'error_code'    => 'transient_error',
					'server_code'   => '',
					'grace_period'  => self::DEFAULT_GRACE_PERIOD,
				]
			);

			$now   = time();
			$state = self::get_state( $args['state_option'], $args['product_id'] );

			$grace_until = self::capped_grace_until( $state, $now, (int) $args['grace_period'] );

			$state = self::update_state(
				$args['state_option'],
				[
					'status'           => 'grace',
					'last_check_at'    => $now,
					'last_error_at'    => $now,
					'last_error_code'  => (string) $args['error_code'],
					'last_server_code' => (string) $args['server_code'],
					'grace_until'      => $grace_until,
				],
				$args['product_id']
			);

			// Keep legacy option activated during grace.
			if ( ! empty( $args['status_option'] ) ) {
				update_option( $args['status_option'], 'activated' );
			}

			return $state;
		}

		public static function mark_inactive( $args ) {
			$args = wp_parse_args(
				$args,
				[
					'state_option'       => '',
					'status_option'      => '',
					'entitlement_option' => '',
					'product_id'         => '',
					'status'             => 'deactivated',
					'error_code'         => '',
					'server_code'        => '',
				]
			);

			$now = time();

			$state = self::update_state(
				$args['state_option'],
				[
					'status'           => (string) $args['status'],
					'last_check_at'    => $now,
					'last_error_at'    => $now,
					'last_error_code'  => (string) $args['error_code'],
					'last_server_code' => (string) $args['server_code'],
					'grace_until'      => 0,
				],
				$args['product_id']
			);

			if ( ! empty( $args['status_option'] ) ) {
				update_option( $args['status_option'], 'deactivated' );
			}

			if ( ! empty( $args['entitlement_option'] ) ) {
				delete_option( $args['entitlement_option'] );
			}

			return $state;
		}

		public static function is_active( $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'license_key_option' => '',
					'status_option'      => '',
					'state_option'       => '',
					'entitlement_option' => '',
					'product_id'         => '',
					'check_domain'       => true,
					'current_domain'     => self::normalize_domain( home_url() ),
				]
			);

			$license_key = '';
			if ( ! empty( $args['license_key_option'] ) ) {
				$license_key = (string) get_option( $args['license_key_option'], '' );
			}

			if ( '' === trim( $license_key ) ) {
				return false;
			}

			$state  = self::get_state( $args['state_option'], $args['product_id'] );
			$status = (string) $state['status'];
			$now    = time();

			if ( ! empty( $state['product_id'] ) && '' !== (string) $args['product_id'] && (string) $state['product_id'] !== (string) $args['product_id'] ) {
				return false;
			}

			if ( ! empty( $state['expires_at'] ) && (int) $state['expires_at'] < $now ) {
				return false;
			}

			if ( ! empty( $args['check_domain'] ) && ! empty( $state['domain'] ) ) {
				if ( self::normalize_domain( $state['domain'] ) !== self::normalize_domain( $args['current_domain'] ) ) {
					return false;
				}
			}

			if ( 'activated' === $status ) {
				if ( empty( $args['entitlement_option'] ) ) {
					return true;
				}

				$entitlement = self::verify_entitlement(
					(string) get_option( $args['entitlement_option'], '' ),
					[
						'license_key' => $license_key,
						'product_id'  => $args['product_id'],
						'domain'      => $args['current_domain'],
					]
				);

				if ( is_array( $entitlement ) ) {
					return true;
				}

				return self::can_use_entitlement_grace( $state, $now );
			}

			if ( 'grace' === $status && self::can_use_entitlement_grace( $state, $now ) ) {
				return true;
			}

			// Legacy fallback.
			if ( empty( $args['entitlement_option'] ) && ! empty( $args['status_option'] ) ) {
				$legacy_status = (string) get_option( $args['status_option'], 'deactivated' );
				if ( 'activated' === $legacy_status ) {
					return true;
				}
			}

			return false;
		}

		private static function can_use_entitlement_grace( array $state, $now ) {
			$grace_until  = self::capped_grace_until( $state, $now, self::DEFAULT_GRACE_PERIOD );
			$last_success = (int) $state['last_success_at'];

			if (
				$last_success > 0
				&& $last_success + self::DEFAULT_GRACE_PERIOD >= $now
				&& $grace_until >= $now
			) {
				return true;
			}

			return false;
		}

		private static function capped_grace_until( array $state, $now, $grace_period ) {
			$last_success = (int) $state['last_success_at'];
			if ( $last_success <= 0 ) {
				return 0;
			}

			$max_grace_until = $last_success + min( (int) $grace_period, self::DEFAULT_GRACE_PERIOD );
			$current_until   = ! empty( $state['grace_until'] ) ? (int) $state['grace_until'] : 0;
			$target_until    = $current_until >= $now ? $current_until : $now + min( (int) $grace_period, self::DEFAULT_GRACE_PERIOD );

			return min( $target_until, $max_grace_until );
		}

		private static function entitlement_public_key() {
			if ( defined( 'YOOHW_LICENSE_ENTITLEMENT_PUBLIC_KEY' ) && YOOHW_LICENSE_ENTITLEMENT_PUBLIC_KEY ) {
				return (string) YOOHW_LICENSE_ENTITLEMENT_PUBLIC_KEY;
			}

			return self::ENTITLEMENT_PUBLIC_KEY;
		}

		private static function base64url_decode( $value ) {
			$value = (string) $value;
			$pad   = strlen( $value ) % 4;

			if ( $pad ) {
				$value .= str_repeat( '=', 4 - $pad );
			}

			return base64_decode( strtr( $value, '-_', '+/' ), true );
		}

		public static function verify_entitlement( $token, $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'license_key' => '',
					'product_id'  => '',
					'domain'      => self::normalize_domain( home_url() ),
					'audience'    => self::ENTITLEMENT_AUDIENCE,
				]
			);

			$token = trim( (string) $token );
			if ( '' === $token || ! function_exists( 'openssl_verify' ) ) {
				return false;
			}

			$parts = explode( '.', $token );
			if ( 2 !== count( $parts ) ) {
				return false;
			}

			$payload_json = self::base64url_decode( $parts[0] );
			$signature    = self::base64url_decode( $parts[1] );

			if ( false === $payload_json || false === $signature ) {
				return false;
			}

			$verified = openssl_verify( $parts[0], $signature, self::entitlement_public_key(), OPENSSL_ALGO_SHA256 );
			if ( 1 !== $verified ) {
				return false;
			}

			$payload = json_decode( $payload_json, true );
			if ( ! is_array( $payload ) ) {
				return false;
			}

			$now = time();
			if ( (string) ( $payload['iss'] ?? '' ) !== self::ENTITLEMENT_ISSUER ) {
				return false;
			}

			if ( (string) ( $payload['aud'] ?? '' ) !== (string) $args['audience'] ) {
				return false;
			}

			if ( (string) ( $payload['status'] ?? '' ) !== 'valid' ) {
				return false;
			}

			if ( empty( $payload['exp'] ) || (int) $payload['exp'] < $now ) {
				return false;
			}

			if ( ! empty( $payload['iat'] ) && (int) $payload['iat'] > $now + 5 * MINUTE_IN_SECONDS ) {
				return false;
			}

			if ( '' !== (string) $args['product_id'] && (string) ( $payload['product_id'] ?? '' ) !== (string) $args['product_id'] ) {
				return false;
			}

			if ( self::normalize_domain( (string) ( $payload['domain'] ?? '' ) ) !== self::normalize_domain( $args['domain'] ) ) {
				return false;
			}

			$license_key = trim( (string) $args['license_key'] );
			if ( '' !== $license_key ) {
				$expected_hash = hash( 'sha256', $license_key );
				if ( empty( $payload['license_hash'] ) || ! hash_equals( $expected_hash, (string) $payload['license_hash'] ) ) {
					return false;
				}
			}

			return $payload;
		}

		public static function get_runtime_status( $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'state_option' => '',
					'product_id'   => '',
				]
			);

			$state  = self::get_state( $args['state_option'], $args['product_id'] );
			$status = (string) $state['status'];

			if ( 'grace' === $status && self::can_use_entitlement_grace( $state, time() ) ) {
				return 'grace';
			}

			return $status;
		}

		public static function maybe_migrate_legacy_state( $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'license_key_option' => '',
					'status_option'      => '',
					'state_option'       => '',
					'product_id'         => '',
				]
			);

			$existing = get_option( $args['state_option'], null );
			if ( is_array( $existing ) ) {
				return false;
			}

			$license_key   = (string) get_option( $args['license_key_option'], '' );
			$legacy_status = strtolower( (string) get_option( $args['status_option'], 'deactivated' ) );

			if ( '' === trim( $license_key ) ) {
				self::mark_inactive(
					[
						'state_option'  => $args['state_option'],
						'status_option' => $args['status_option'],
						'product_id'    => $args['product_id'],
						'status'        => 'deactivated',
						'error_code'    => 'legacy_migration_no_key',
						'server_code'   => 'legacy_migration_no_key',
					]
				);
				self::clear_refresh_required( $args['state_option'] );
				return 'inactive';
			}

			if ( in_array( $legacy_status, [ 'activated', 'active', 'valid' ], true ) ) {
				self::mark_grace(
					[
						'state_option'  => $args['state_option'],
						'status_option' => $args['status_option'],
						'product_id'    => $args['product_id'],
						'error_code'    => 'legacy_migration',
						'server_code'   => 'legacy_migration',
					]
				);
				self::mark_refresh_required( $args['state_option'] );
				delete_transient( 'wc_blacklist_manager_premium_license_last_check' );
				return 'needs_refresh';
			}

			self::mark_inactive(
				[
					'state_option'  => $args['state_option'],
					'status_option' => $args['status_option'],
					'product_id'    => $args['product_id'],
					'status'        => 'deactivated',
					'error_code'    => 'legacy_migration_inactive',
					'server_code'   => 'legacy_migration_inactive',
				]
			);
			self::clear_refresh_required( $args['state_option'] );
			return 'inactive';
		}
	}
}

if ( ! class_exists( 'YoOhw_License_Runtime', false ) && class_exists( 'YOBM_License_Runtime', false ) ) {
	class_alias( 'YOBM_License_Runtime', 'YoOhw_License_Runtime' );
}
