<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private request-local arbitration for automatic Premium/Global paid actions.
 *
 * This is deliberately not an extension API. Core owns the complete finite
 * candidate set and Premium does not register providers.
 */
final class WC_Blacklist_Manager_Opportunity_Engine {

	const GLOBAL_QUOTA_ID  = 'global.quota.notice';
	const SECURITY_ID      = 'premium.security.free_alert';
	const ACTION_PREFIX    = 'premium.action.';
	const PASSIVE_PREFIX   = 'premium.passive.';

	private static $primed     = false;
	private static $candidates = array();
	private static $winner     = null;

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'prime' ), -1000 );
	}

	/**
	 * Collect every source-known candidate before normal admin notices render.
	 * Collection and resolution perform no writes.
	 */
	public static function prime() {
		if ( self::$primed ) {
			return;
		}

		self::$primed = true;

		if ( ! self::is_admin_request() ) {
			return;
		}

		$candidates = array();
		$quota      = self::get_global_quota_context();

		if ( ! empty( $quota['eligible'] ) ) {
			$candidates[] = array(
				'id'       => self::GLOBAL_QUOTA_ID,
				'target'   => 'global',
				'priority' => 400,
				'sort'     => 0,
			);
		}

		if ( class_exists( 'WC_Blacklist_Manager_Alert' ) ) {
			$security = WC_Blacklist_Manager_Alert::get_opportunity_candidate();
			if ( is_array( $security ) && ! empty( $security['id'] ) ) {
				$candidates[] = $security;
			}
		}

		$surface        = self::current_surface();
		$action_surface = 0 === strpos( $surface, 'verifications_' ) ? 'verifications' : $surface;
		if ( '' !== $action_surface && function_exists( 'wc_blacklist_manager_get_action_upsell_candidates' ) ) {
			$candidates = array_merge( $candidates, wc_blacklist_manager_get_action_upsell_candidates( $action_surface ) );
		}

		$passive = self::get_passive_candidate( $surface );
		if ( ! empty( $passive ) ) {
			$candidates[] = $passive;
		}

		self::add_candidates( $candidates );
	}

	/**
	 * Return the exact request winner, optionally considering source-defined
	 * late candidates such as the two order static fallbacks.
	 */
	public static function winner( array $additional = array() ) {
		self::prime();
		self::add_candidates( $additional );

		return self::$winner;
	}

	public static function is_selected( $candidate_id, array $additional = array() ) {
		$candidate_id = trim( (string) $candidate_id );
		$winner       = self::winner( $additional );

		if ( ! is_array( $winner ) || empty( $winner['id'] ) ) {
			return false;
		}

		return '' !== $candidate_id && $candidate_id === (string) $winner['id'];
	}

	/**
	 * Pure deterministic resolver used by production and focused fixtures.
	 */
	public static function resolve_candidates( array $candidates, $suppress_premium = false ) {
		$normalized = array();

		foreach ( $candidates as $candidate ) {
			$candidate = self::normalize_candidate( $candidate );
			if ( empty( $candidate ) ) {
				continue;
			}

			if ( $suppress_premium && 'premium' === $candidate['target'] ) {
				continue;
			}

			$normalized[ $candidate['id'] ] = $candidate;
		}

		if ( empty( $normalized ) ) {
			return null;
		}

		$normalized = array_values( $normalized );
		usort(
			$normalized,
			static function ( array $left, array $right ) {
				if ( $left['priority'] !== $right['priority'] ) {
					return $left['priority'] > $right['priority'] ? -1 : 1;
				}

				if ( $left['sort'] !== $right['sort'] ) {
					return $left['sort'] < $right['sort'] ? -1 : 1;
				}

				if ( $left['recency'] !== $right['recency'] ) {
					return $left['recency'] > $right['recency'] ? -1 : 1;
				}

				return strcmp( $left['id'], $right['id'] );
			}
		);

		return $normalized[0];
	}

	public static function action_candidate_id( $event, $surface, $kind = 'pending' ) {
		return self::ACTION_PREFIX . sanitize_key( (string) $kind ) . '.' . sanitize_key( (string) $surface ) . '.' . sanitize_key( (string) $event );
	}

	/**
	 * Read-only source state shared with the operational quota renderer.
	 */
	public static function get_global_quota_context() {
		$context = array(
			'eligible'      => false,
			'source_active' => false,
			'tier'          => 'free',
			'month_key'     => gmdate( 'Ym' ),
			'transient_key' => '',
			'dismiss_key'   => '',
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			return $context;
		}

		$source  = self::get_global_quota_source_context();
		$context = array_merge( $context, $source );
		if ( empty( $context['transient_key'] ) ) {
			return $context;
		}

		$dismiss_key = 'yogb_gbd_limit_notice_dismissed_' . $context['tier'] . '_' . $context['month_key'];
		$context['dismiss_key'] = $dismiss_key;
		$context['eligible']    = $context['source_active']
			&& 'yes' !== get_user_meta( get_current_user_id(), $dismiss_key, true );

		return $context;
	}

	/**
	 * Capability-neutral site incident observation for internal P2 gating.
	 */
	public static function is_global_quota_source_active() {
		$source = self::get_global_quota_source_context();
		return ! empty( $source['source_active'] );
	}

	private static function get_global_quota_source_context() {
		$context = array(
			'source_active' => false,
			'tier'          => 'free',
			'month_key'     => gmdate( 'Ym' ),
			'transient_key' => '',
		);

		if ( 1 !== (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 ) ) {
			return $context;
		}

		$tier = strtolower( trim( (string) get_option( 'yogb_bm_tier', 'free' ) ) );
		if ( ! in_array( $tier, array( 'free', 'basic', 'pro', 'enterprise' ), true ) ) {
			$tier = 'free';
		}

		$transient_key = class_exists( 'YOGB_BM_Check' )
			? YOGB_BM_Check::get_monthly_limit_transient_key( $tier )
			: 'yogb_gbd_limit_reached_' . $tier . '_' . $context['month_key'];

		$context['tier']          = $tier;
		$context['transient_key'] = $transient_key;
		$context['source_active'] = ! empty( self::get_transient_read_only( $transient_key ) );

		return $context;
	}

	/**
	 * One source-defined passive callsite per current surface.
	 */
	public static function get_passive_candidate( $surface ) {
		$surface = sanitize_key( (string) $surface );
		$map     = array(
			'dashboard'              => 'premium.passive.dashboard.activity_logs',
			'settings'               => 'premium.passive.settings.advanced_protection',
			'verifications_verify'    => 'premium.passive.verifications.verify.email_provider',
			'verifications_advanced'  => 'premium.passive.verifications.advanced.banner',
			'notifications_emails'    => 'premium.passive.notifications.emails.banner',
			'notifications_notices'   => class_exists( 'WooCommerce' )
				? 'premium.passive.notifications.notices.checkout'
				: 'premium.passive.notifications.notices.registration',
			'activity'                => 'premium.passive.activity.banner',
		);

		if ( empty( $map[ $surface ] ) ) {
			return array();
		}

		return array(
			'id'       => $map[ $surface ],
			'target'   => 'premium',
			'priority' => 100,
			'sort'     => 0,
		);
	}

	public static function current_surface() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'wc-blacklist-manager' === $page ) {
			return 'dashboard';
		}

		if ( 'wc-blacklist-manager-settings' === $page ) {
			return 'settings';
		}

		if ( 'wc-blacklist-manager-verifications' === $page ) {
			return 'advanced' === $tab ? 'verifications_advanced' : 'verifications_verify';
		}

		if ( 'wc-blacklist-manager-notifications' === $page ) {
			return 'notices' === $tab ? 'notifications_notices' : 'notifications_emails';
		}

		if ( 'wc-blacklist-manager-activity-logs' === $page ) {
			return 'activity';
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if (
			$screen &&
			(
				'shop_order' === (string) $screen->post_type ||
				in_array( (string) $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true )
			)
		) {
			return 'order';
		}

		return '';
	}

	private static function add_candidates( array $candidates ) {
		foreach ( $candidates as $candidate ) {
			$candidate = self::normalize_candidate( $candidate );
			if ( empty( $candidate ) ) {
				continue;
			}

			if ( 'premium' === $candidate['target'] && self::premium_acquisition_suppressed() ) {
				continue;
			}

			self::$candidates[ $candidate['id'] ] = $candidate;
		}

		self::$winner = self::resolve_candidates( array_values( self::$candidates ) );
	}

	/**
	 * Read a transient without WordPress's expired-row deletion side effect.
	 */
	private static function get_transient_read_only( $transient ) {
		$transient = (string) $transient;
		$pre       = apply_filters( "pre_transient_{$transient}", false, $transient );

		if ( false !== $pre ) {
			return $pre;
		}

		if ( wp_using_ext_object_cache() || wp_installing() ) {
			$value = wp_cache_get( $transient, 'transient' );
		} else {
			$transient_option = '_transient_' . $transient;
			$alloptions       = wp_load_alloptions();

			if ( ! isset( $alloptions[ $transient_option ] ) ) {
				$timeout = get_option( '_transient_timeout_' . $transient );
				if ( false !== $timeout && (int) $timeout < time() ) {
					$value = false;
				}
			}

			if ( ! isset( $value ) ) {
				$value = get_option( $transient_option );
			}
		}

		return apply_filters( "transient_{$transient}", $value, $transient );
	}

	private static function normalize_candidate( $candidate ) {
		if ( ! is_array( $candidate ) ) {
			return array();
		}

		$id     = isset( $candidate['id'] ) ? trim( (string) $candidate['id'] ) : '';
		$target = isset( $candidate['target'] ) ? sanitize_key( (string) $candidate['target'] ) : '';

		if ( '' === $id || ! in_array( $target, array( 'premium', 'global' ), true ) ) {
			return array();
		}

		return array(
			'id'       => $id,
			'target'   => $target,
			'priority' => isset( $candidate['priority'] ) ? (int) $candidate['priority'] : 0,
			'sort'     => isset( $candidate['sort'] ) ? (int) $candidate['sort'] : 0,
			'recency'  => isset( $candidate['recency'] ) ? (int) $candidate['recency'] : 0,
		);
	}

	private static function premium_acquisition_suppressed() {
		if ( function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available() ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' )
			&& is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
	}

	private static function is_admin_request() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		return ! ( defined( 'DOING_CRON' ) && DOING_CRON );
	}
}

WC_Blacklist_Manager_Opportunity_Engine::init();
