<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 1 ownership boundary for phone OTP.
 *
 * Normal phone verification belongs to Premium. Core retains only a versioned
 * migration and a temporary bridge for an old Premium release that does not
 * yet advertise the Premium phone-channel contract.
 */
final class WC_Blacklist_Manager_Phone_Verification_Boundary {

	private const MIGRATION_VERSION = '1';
	private const MIGRATION_OPTION  = 'wc_blacklist_manager_phone_boundary_migration_version';
	private const NOTICE_OPTION     = 'wc_blacklist_manager_phone_boundary_notice';

	public static function init() {
		add_filter( 'pre_update_option_yoohw_sms_service', array( __CLASS__, 'sanitize_provider_option' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate' ), 20 );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_boot_legacy_premium_bridge' ), 100 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_migration_notice' ) );
	}

	public static function is_supported_provider( $provider ) {
		return in_array( (string) $provider, array( 'twilio', 'textmagic' ), true );
	}

	public static function sanitize_provider_option( $provider ) {
		if ( self::is_supported_provider( $provider ) ) {
			return (string) $provider;
		}

		update_option( 'wc_blacklist_phone_verification_enabled', '0', false );
		return '';
	}

	public static function maybe_migrate() {
		if ( self::MIGRATION_VERSION === (string) get_option( self::MIGRATION_OPTION, '' ) ) {
			return;
		}

		$missing       = '__wc_blacklist_manager_missing_phone_provider__';
		$provider      = get_option( 'yoohw_sms_service', $missing );
		$enabled_value = (string) get_option( 'wc_blacklist_phone_verification_enabled', '0' );
		$enabled       = '1' === $enabled_value;
		$invalid       = $missing === $provider || ! self::is_supported_provider( $provider );
		$notice_needed = $invalid && ( $enabled || ( $missing !== $provider && '' !== (string) $provider ) );

		if ( $invalid ) {
			if ( $notice_needed ) {
				update_option( self::NOTICE_OPTION, '1', false );

				if ( '1' !== (string) get_option( self::NOTICE_OPTION, '0' ) ) {
					return;
				}
			}

			update_option( 'yoohw_sms_service', '', false );
			update_option( 'wc_blacklist_phone_verification_enabled', '0', false );
		}

		delete_option( 'yoohw_phone_verification_sms_key' );
		delete_option( 'yoohw_phone_verification_sms_quota' );
		delete_option( 'wc_blacklist_phone_verification_failed_email' );
		delete_transient( 'yoohw_sms_verification_failed' );

		if ( ! self::migration_postconditions_met( $provider, $enabled_value, $invalid, $notice_needed ) ) {
			return;
		}

		update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION, false );
	}

	private static function migration_postconditions_met( $provider, $enabled_value, $invalid, $notice_needed ) {
		$current_provider = get_option( 'yoohw_sms_service', '__wc_blacklist_manager_missing_phone_provider_after_migration__' );
		$current_enabled  = (string) get_option( 'wc_blacklist_phone_verification_enabled', '0' );

		if ( $invalid ) {
			if ( '' !== $current_provider || '0' !== $current_enabled ) {
				return false;
			}
		} elseif ( $provider !== $current_provider || $enabled_value !== $current_enabled ) {
			return false;
		}

		if ( $notice_needed && '1' !== (string) get_option( self::NOTICE_OPTION, '0' ) ) {
			return false;
		}

		foreach ( array( 'yoohw_phone_verification_sms_key', 'yoohw_phone_verification_sms_quota', 'wc_blacklist_phone_verification_failed_email' ) as $retired_option ) {
			if ( ! self::option_is_absent( $retired_option ) ) {
				return false;
			}
		}

		return false === get_transient( 'yoohw_sms_verification_failed' );
	}

	private static function option_is_absent( $option_name ) {
		$missing = new stdClass();
		return $missing === get_option( $option_name, $missing );
	}

	public static function maybe_boot_legacy_premium_bridge() {
		if ( defined( 'WC_BLACKLIST_MANAGER_PREMIUM_PHONE_CHANNEL_CONTRACT_VERSION' ) ) {
			return;
		}

		if ( '1' !== (string) get_option( 'wc_blacklist_phone_verification_enabled', '0' ) ) {
			return;
		}

		if ( ! self::is_supported_provider( get_option( 'yoohw_sms_service', '' ) ) ) {
			return;
		}

		if (
			! class_exists( 'WC_Blacklist_Manager_Premium_Verifications_Service' ) ||
			! function_exists( 'wc_blacklist_manager_is_premium_available' ) ||
			! wc_blacklist_manager_is_premium_available()
		) {
			return;
		}

		include_once WC_BLACKLIST_MANAGER_PLUGIN_DIR . 'inc/backend/actions/verifications-phone.php';

		if ( class_exists( 'WC_Blacklist_Manager_Verifications_Verify_Phone' ) ) {
			new WC_Blacklist_Manager_Verifications_Verify_Phone();
		}
	}

	public static function maybe_render_migration_notice() {
		if ( ! current_user_can( 'manage_options' ) || '1' !== (string) get_option( self::NOTICE_OPTION, '0' ) ) {
			return;
		}

		delete_option( self::NOTICE_OPTION );

		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Phone verification was disabled because Yo Credits or an unsupported SMS provider was configured. Activate Blacklist Manager Premium, then select and configure Twilio or TextMagic before enabling phone verification again.', 'wc-blacklist-manager' ) . '</p></div>';
	}
}

WC_Blacklist_Manager_Phone_Verification_Boundary::init();
