<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository-local finite routing for commercial actions.
 *
 * This class deliberately exposes no filters, persistence, or provider hooks.
 * Callers supply only already-trusted local state and receive a fixed action.
 */
final class WC_Blacklist_Manager_Commercial_Router {
	const PREMIUM_ABSENT   = 'premium_absent';
	const PREMIUM_SETUP    = 'premium_setup';
	const PREMIUM_ACTIVE   = 'premium_active';
	const GLOBAL_FREE      = 'free_unowned';
	const GLOBAL_PAID      = 'paid_active';
	const GLOBAL_INACTIVE  = 'owned_inactive';
	const GLOBAL_OFFLINE   = 'disconnected';
	const GLOBAL_UNKNOWN   = 'unknown';

	private const PREMIUM_PRODUCT_URL = 'https://yoohw.com/product/blacklist-manager-premium/';
	private const GLOBAL_PLANS_URL    = 'https://yoohw.com/global-blacklist-plan/';
	private const ACCOUNT_URL         = 'https://yoohw.com/my-account/';
	private const DOWNLOADS_URL       = 'https://yoohw.com/my-account/downloads/';
	private const DOCS_URL            = 'https://docs.yoohw.com/category/blacklist-manager/';

	public static function premium_product_url() {
		return self::PREMIUM_PRODUCT_URL;
	}

	public static function global_plans_url() {
		return self::GLOBAL_PLANS_URL;
	}

	public static function account_url() {
		return self::ACCOUNT_URL;
	}

	public static function downloads_url() {
		return self::DOWNLOADS_URL;
	}

	public static function docs_url() {
		return self::DOCS_URL;
	}

	public static function premium_setup_url() {
		return admin_url( 'admin.php?page=wc-blacklist-manager-setup&step=license' );
	}

	public static function global_settings_url() {
		return admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' );
	}

	public static function premium_state( $plugin_active = null, $premium_available = null ) {
		if ( null === $premium_available ) {
			$premium_available = function_exists( 'wc_blacklist_manager_is_premium_available' )
				&& wc_blacklist_manager_is_premium_available();
		}

		if ( $premium_available ) {
			return self::PREMIUM_ACTIVE;
		}

		if ( null === $plugin_active ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_active = function_exists( 'is_plugin_active' )
				&& is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		}

		return $plugin_active ? self::PREMIUM_SETUP : self::PREMIUM_ABSENT;
	}

	public static function premium_action( $state = null ) {
		$state = null === $state ? self::premium_state() : (string) $state;

		if ( self::PREMIUM_ABSENT === $state ) {
			return array(
				'label'         => __( 'Explore Premium add-on', 'wc-blacklist-manager' ),
				'url'           => self::premium_product_url(),
				'external'      => true,
				'post_purchase' => __( 'After purchase, install and activate Blacklist Manager Premium, then return to Premium Setup to enter the license key issued for your purchase.', 'wc-blacklist-manager' ),
			);
		}

		if ( self::PREMIUM_SETUP === $state ) {
			return array(
				'label'         => __( 'Activate Premium license', 'wc-blacklist-manager' ),
				'url'           => self::premium_setup_url(),
				'external'      => false,
				'post_purchase' => '',
			);
		}

		return array();
	}

	public static function premium_destination_url() {
		$action = self::premium_action();
		return ! empty( $action['url'] ) ? $action['url'] : '';
	}

	public static function global_context( $connected, $tier, $plan_status, $plan_type ) {
		$tier        = sanitize_key( (string) $tier );
		$plan_status = sanitize_key( (string) $plan_status );
		$plan_type   = sanitize_key( (string) $plan_type );
		$paid_tier   = in_array( $tier, array( 'basic', 'pro', 'enterprise' ), true );
		$owned_type  = in_array( $plan_type, array( 'subscription', 'legacy', 'mixed' ), true );
		$known_status = in_array( $plan_status, array( '', 'active', 'inactive', 'none' ), true );
		$known_type   = in_array( $plan_type, array( '', 'subscription', 'legacy', 'mixed', 'none' ), true );

		$context = array(
			'state'       => self::GLOBAL_UNKNOWN,
			'tier'        => $tier,
			'plan_status' => $plan_status,
			'plan_type'   => $plan_type,
		);

		if ( ! $connected ) {
			$context['state'] = self::GLOBAL_OFFLINE;
			return $context;
		}

		if ( 'free' === $tier && 'none' === $plan_status && 'none' === $plan_type ) {
			$context['state'] = self::GLOBAL_FREE;
			return $context;
		}

		if ( 'inactive' === $plan_status && $owned_type ) {
			$context['state'] = self::GLOBAL_INACTIVE;
			return $context;
		}

		if ( ( 'active' === $plan_status && $owned_type ) || ( $paid_tier && $known_status && $known_type && 'inactive' !== $plan_status ) ) {
			$context['state'] = self::GLOBAL_PAID;
			return $context;
		}

		return $context;
	}

	public static function global_card_action( array $context, $supports_activation_key ) {
		$state = isset( $context['state'] ) ? (string) $context['state'] : self::GLOBAL_UNKNOWN;

		if ( self::GLOBAL_FREE === $state ) {
			return array(
				'label'         => __( 'View Global Blacklist plans', 'wc-blacklist-manager' ),
				'url'           => self::global_plans_url(),
				'external'      => true,
				'post_purchase' => $supports_activation_key
					? __( 'After purchase, return to this screen and activate the subscription key issued for your plan.', 'wc-blacklist-manager' )
					: '',
			);
		}

		if ( self::GLOBAL_PAID === $state ) {
			return array(
				'label'         => __( 'View Global Blacklist plan options', 'wc-blacklist-manager' ),
				'url'           => self::global_plans_url(),
				'external'      => true,
				'post_purchase' => '',
			);
		}

		if (
			self::GLOBAL_INACTIVE === $state
			&& $supports_activation_key
			&& in_array( (string) ( $context['plan_type'] ?? '' ), array( 'subscription', 'mixed' ), true )
		) {
			return array(
				'label'         => __( 'Review subscription activation', 'wc-blacklist-manager' ),
				'url'           => '#yogb-subscription-activation',
				'external'      => false,
				'post_purchase' => '',
			);
		}

		return array();
	}

	public static function global_quota_action( array $context ) {
		$state = isset( $context['state'] ) ? (string) $context['state'] : self::GLOBAL_UNKNOWN;
		$tier  = isset( $context['tier'] ) ? (string) $context['tier'] : '';

		if ( self::GLOBAL_FREE === $state ) {
			return array(
				'label'    => __( 'View Global Blacklist plans', 'wc-blacklist-manager' ),
				'url'      => self::global_plans_url(),
				'external' => true,
			);
		}

		if ( self::GLOBAL_PAID === $state && in_array( $tier, array( 'basic', 'pro' ), true ) ) {
			return array(
				'label'    => __( 'Review Global Blacklist plan options', 'wc-blacklist-manager' ),
				'url'      => self::global_plans_url(),
				'external' => true,
			);
		}

		return array(
			'label'    => __( 'Review Global Blacklist status', 'wc-blacklist-manager' ),
			'url'      => self::global_settings_url(),
			'external' => false,
		);
	}
}
