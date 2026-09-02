<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal finite presentation model for the Dashboard.
 *
 * This is a Core-owned rendering helper, not a supported extension API.
 */
final class WC_Blacklist_Manager_Dashboard_Presentation {
	const GLOBAL_INACTIVE     = 'inactive';
	const GLOBAL_DISCONNECTED = 'disconnected';
	const GLOBAL_PROTECTED    = 'protected';

	public static function global_model( array $values ) {
		$enabled = ! empty( $values['enabled'] );
		$tier    = isset( $values['tier'] ) ? sanitize_key( (string) $values['tier'] ) : 'free';

		if ( ! in_array( $tier, array( 'free', 'basic', 'pro', 'enterprise' ), true ) ) {
			$tier = 'free';
		}

		if ( ! $enabled ) {
			$state = self::GLOBAL_INACTIVE;
		} elseif ( empty( $values['api_key'] ) || empty( $values['api_secret'] ) || empty( $values['reporter_id'] ) ) {
			$state = self::GLOBAL_DISCONNECTED;
		} else {
			$state = self::GLOBAL_PROTECTED;
		}

		return array(
			'state' => $state,
			'tier'  => $tier,
		);
	}

	public static function current_global_model() {
		return self::global_model(
			array(
				'enabled'     => '1' === get_option( 'wc_blacklist_enable_global_blacklist', '0' ),
				'api_key'     => trim( (string) get_option( 'yogb_bm_api_key', '' ) ),
				'api_secret'  => trim( (string) get_option( 'yogb_bm_api_secret', '' ) ),
				'reporter_id' => trim( (string) get_option( 'yogb_bm_reporter_id', '' ) ),
				'tier'        => get_option( 'yogb_bm_tier', 'free' ),
			)
		);
	}

	public static function activity_model( $premium_active, array $values ) {
		$premium_active = (bool) $premium_active;
		$features       = isset( $values['features'] ) && is_array( $values['features'] ) ? $values['features'] : array();
		$entries        = isset( $values['entries'] ) && is_array( $values['entries'] ) ? $values['entries'] : array();
		$attempts       = isset( $values['attempts'] ) && is_array( $values['attempts'] ) ? $values['attempts'] : array();

		$definitions = array(
			'name'    => array( 'premium' => true,  'enabled' => ! empty( $features['name'] ) ),
			'phone'   => array( 'premium' => false, 'enabled' => true ),
			'email'   => array( 'premium' => false, 'enabled' => true ),
			'device'  => array( 'premium' => true,  'enabled' => ! empty( $features['device'] ) ),
			'ip'      => array( 'premium' => false, 'enabled' => ! empty( $features['ip'] ) ),
			'address' => array( 'premium' => true,  'enabled' => ! empty( $features['address'] ) ),
			'domain'  => array( 'premium' => false, 'enabled' => ! empty( $features['domain'] ) ),
		);

		$rows = array();
		foreach ( $definitions as $key => $definition ) {
			if ( $definition['premium'] && ! $premium_active ) {
				continue;
			}

			$rows[] = array(
				'key'      => $key,
				'enabled'  => (bool) $definition['enabled'],
				'entries'  => self::count( isset( $entries[ $key ] ) ? $entries[ $key ] : 0 ),
				'attempts' => self::count( isset( $attempts[ $key ] ) ? $attempts[ $key ] : 0 ),
			);
		}

		return array(
			'rows'           => $rows,
			'entries_total'  => self::count( isset( $values['entries_total'] ) ? $values['entries_total'] : 0 ),
			'attempts_total' => self::count( isset( $values['attempts_total'] ) ? $values['attempts_total'] : 0 ),
		);
	}

	public static function current_activity_model( $premium_active ) {
		return self::activity_model(
			$premium_active,
			array(
				'features' => array(
					'name'    => '1' === get_option( 'wc_blacklist_customer_name_blocking_enabled', '0' ),
					'device'  => '1' === get_option( 'wc_blacklist_enable_device_identity', '0' ),
					'ip'      => in_array( (string) get_option( 'wc_blacklist_ip_enabled', '0' ), array( '1', '2' ), true ),
					'address' => '1' === get_option( 'wc_blacklist_enable_customer_address_blocking', '0' ),
					'domain'  => '1' === get_option( 'wc_blacklist_domain_enabled', '0' ),
				),
				'entries' => array(
					'name'    => get_option( 'wc_blacklist_sum_name', 0 ),
					'phone'   => get_option( 'wc_blacklist_sum_phone', 0 ),
					'email'   => get_option( 'wc_blacklist_sum_email', 0 ),
					'device'  => get_option( 'wc_blacklist_sum_device', 0 ),
					'ip'      => get_option( 'wc_blacklist_sum_ip', 0 ),
					'address' => get_option( 'wc_blacklist_sum_address', 0 ),
					'domain'  => get_option( 'wc_blacklist_sum_domain', 0 ),
				),
				'attempts' => array(
					'name'    => get_option( 'wc_blacklist_sum_block_name', 0 ),
					'phone'   => get_option( 'wc_blacklist_sum_block_phone', 0 ),
					'email'   => get_option( 'wc_blacklist_sum_block_email', 0 ),
					'device'  => get_option( 'wc_blacklist_sum_block_device', 0 ),
					'ip'      => get_option( 'wc_blacklist_sum_block_ip', 0 ),
					'address' => get_option( 'wc_blacklist_sum_block_address', 0 ),
					'domain'  => get_option( 'wc_blacklist_sum_block_domain', 0 ),
				),
				'entries_total'  => get_option( 'wc_blacklist_sum_total', 0 ),
				'attempts_total' => get_option( 'wc_blacklist_sum_block_total', 0 ),
			)
		);
	}

	private static function count( $value ) {
		return max( 0, (int) $value );
	}
}
