<?php
/** Policy cannot elevate disabled or unentitled events. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Policy {
	public static function premium() {
		return function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available();
	}

	public static function check( array $descriptor ) {
		if ( 'yes' !== get_option( $descriptor['option'], 'no' ) ) { return 'disabled'; }
		if ( 'premium' === $descriptor['entitlement'] && ! self::premium() ) { return 'not_entitled'; }
		// No context or recipient is exposed to this internal restriction seam.
		try {
			return true === apply_filters( 'wc_blacklist_manager_notification_policy_v1', true, $descriptor['id'] ) ? null : 'disabled';
		} catch ( Throwable $error ) {
			return 'disabled';
		}
	}

	public static function background() {
		// A producer cannot authorize itself with a caller-supplied context flag.
		return ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
	}
}
