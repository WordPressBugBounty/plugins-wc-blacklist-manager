<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'YoOhw_License_Runtime' ) ) {

	final class YoOhw_License_Runtime {

		const DEFAULT_GRACE_PERIOD = 7 * DAY_IN_SECONDS;

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
				'activated_at'     => 0,
				'updated_at'       => 0,
			];
		}

		public static function get_state( $state_option, $product_id = '' ) {
			$state   = get_option( $state_option, [] );
			$default = self::get_default_state( $product_id );

			if ( ! is_array( $state ) ) {
				$state = [];
			}

			return wp_parse_args( $state, $default );
		}

		public static function update_state( $state_option, array $new_state, $product_id = '' ) {
			$current = self::get_state( $state_option, $product_id );
			$merged  = array_merge( $current, $new_state );

			$merged['updated_at'] = time();

			update_option( $state_option, $merged );

			return $merged;
		}

		public static function mark_activated( $args ) {
			$args = wp_parse_args(
				$args,
				[
					'state_option'  => '',
					'status_option' => '',
					'product_id'    => '',
					'domain'        => self::normalize_domain( home_url() ),
					'expires_at'    => 0,
					'server_code'   => 'valid',
					'grace_period'  => self::DEFAULT_GRACE_PERIOD,
				]
			);

			$now           = time();
			$current_state = self::get_state( $args['state_option'], $args['product_id'] );

			$activated_at = ! empty( $current_state['activated_at'] ) ? (int) $current_state['activated_at'] : $now;

			$state = self::update_state(
				$args['state_option'],
				[
					'status'           => 'activated',
					'product_id'       => (string) $args['product_id'],
					'domain'           => (string) $args['domain'],
					'last_check_at'    => $now,
					'last_success_at'  => $now,
					'last_error_at'    => 0,
					'last_error_code'  => '',
					'last_server_code' => (string) $args['server_code'],
					'expires_at'       => (int) $args['expires_at'],
					'grace_until'      => $now + (int) $args['grace_period'],
					'activated_at'     => $activated_at,
				],
				$args['product_id']
			);

			if ( ! empty( $args['status_option'] ) ) {
				update_option( $args['status_option'], 'activated' );
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

			$grace_until = (int) $state['grace_until'];
			if ( $grace_until < $now ) {
				$grace_until = $now + (int) $args['grace_period'];
			}

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
					'state_option'  => '',
					'status_option' => '',
					'product_id'    => '',
					'status'        => 'deactivated',
					'error_code'    => '',
					'server_code'   => '',
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

			return $state;
		}

		public static function is_active( $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'license_key_option' => '',
					'status_option'      => '',
					'state_option'       => '',
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

			if ( ! empty( $args['check_domain'] ) && ! empty( $state['domain'] ) ) {
				if ( self::normalize_domain( $state['domain'] ) !== self::normalize_domain( $args['current_domain'] ) ) {
					return false;
				}
			}

			if ( 'activated' === $status ) {
				return true;
			}

			if ( 'grace' === $status && (int) $state['grace_until'] >= $now ) {
				return true;
			}

			// Legacy fallback.
			if ( ! empty( $args['status_option'] ) ) {
				$legacy_status = (string) get_option( $args['status_option'], 'deactivated' );
				if ( 'activated' === $legacy_status ) {
					return true;
				}
			}

			return false;
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

			if ( 'grace' === $status && (int) $state['grace_until'] >= time() ) {
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
				return;
			}

			$license_key   = (string) get_option( $args['license_key_option'], '' );
			$legacy_status = (string) get_option( $args['status_option'], 'deactivated' );

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
				return;
			}

			if ( 'activated' === $legacy_status ) {
				self::mark_grace(
					[
						'state_option'  => $args['state_option'],
						'status_option' => $args['status_option'],
						'product_id'    => $args['product_id'],
						'error_code'    => 'legacy_migration',
						'server_code'   => 'legacy_migration',
					]
				);
				return;
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
		}
	}
}