<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Alert {

	/* ====== Options & Meta Keys ====== */
	const UMETA_DISMISS            = 'yobm_notice_suggest_enable_captcha'; // per-user snooze: UNIX timestamp until hidden
	const OPTION_FAIL_STREAK       = 'yobm_failed_orders_streak';
	const OPTION_FAIL_STREAK_TIME  = 'yobm_failed_orders_streak_times';     // json[int,...] of recent failed timestamps
	const OPTION_SPIKE_ARMED       = 'yobm_failed_spike_armed_at';          // int timestamp (site-wide) when a spike episode began

	/* ====== Behavior ====== */
	const FAIL_THRESHOLD           = 5;                  // consecutive failed orders to consider a spike
	const MAX_TIMESTAMPS_STORED    = 10;                 // how many fail times to keep (for “between A and B” window)
	const SNOOZE_SECONDS           = DAY_IN_SECONDS;     // per-user “I’ll do it later”
	const SPIKE_TTL                = WEEK_IN_SECONDS;    // 0 = never auto-expire
	const SPIKE_WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_action( 'woocommerce_order_status_failed', [ $this, 'track_order_streak' ], 10, 1 );

		add_action( 'admin_notices', [ $this, 'display_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'conditionally_enqueue_inline_scripts' ] );

		add_action( 'wp_ajax_notice_suggest_enable_captcha', [ $this, 'handle_notice_actions' ] );
	}

	/* =========================================================
	 * Order Tracking
	 * ======================================================= */

	public function track_order_streak( $order_id ) {
		$streak = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		$times  = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times  = is_array( $times ) ? $times : [];

		$now      = time();
		$streak++;
		$times[]  = $now;

		// Keep only the latest MAX_TIMESTAMPS_STORED timestamps
		$times = array_slice( $times, - self::MAX_TIMESTAMPS_STORED );

		// Count failures in the recent window
		$recent_count = $this->count_recent_failures( $times, self::SPIKE_WINDOW_SECONDS );

		// Arm ONCE when the spike episode starts (based on recent window, not just raw streak)
		if ( $recent_count >= self::FAIL_THRESHOLD && ! get_option( self::OPTION_SPIKE_ARMED, 0 ) ) {
			update_option( self::OPTION_SPIKE_ARMED, $now, false );
		}

		update_option( self::OPTION_FAIL_STREAK, $streak, false );
		update_option( self::OPTION_FAIL_STREAK_TIME, wp_json_encode( $times ), false );
	}

	private function count_recent_failures( array $timestamps, int $window_seconds ): int {
		$cutoff = time() - $window_seconds;
		$count  = 0;

		foreach ( $timestamps as $ts ) {
			$ts = (int) $ts;
			if ( $ts >= $cutoff ) {
				$count++;
			}
		}

		return $count;
	}

	/* =========================================================
	 * Notices
	 * ======================================================= */

	public function display_notices() {
		$this->notice_suggest_enable_captcha();
	}

	public function notice_suggest_enable_captcha() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Hide for Premium users whose license is activated.
		if ( $this->premium_is_activated() ) {
			return;
		}

		$user_id = get_current_user_id();

		// Respect per-user snooze
		$snooze_until = (int) get_user_meta( $user_id, self::UMETA_DISMISS, true );
		if ( $snooze_until && time() < $snooze_until ) {
			return;
		}

		$streak   = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		$armed_at = (int) get_option( self::OPTION_SPIKE_ARMED, 0 );

		$times = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times = is_array( $times ) ? $times : [];

		// Failures in the recent window (e.g. last 15 minutes)
		$recent_count = $this->count_recent_failures( $times, self::SPIKE_WINDOW_SECONDS );

		// TTL cleanup
		if ( $armed_at && self::SPIKE_TTL > 0 && ( time() - $armed_at ) > self::SPIKE_TTL ) {
			delete_option( self::OPTION_SPIKE_ARMED );
			$armed_at = 0;
		}

		// Gate: show if either the recent window is hot OR spike is armed
		if ( $recent_count < self::FAIL_THRESHOLD && ! $armed_at ) {
			return;
		}

		$first_time = ! empty( $times ) ? (int) reset( $times ) : 0;
		$last_time  = ! empty( $times ) ? (int) end( $times )   : 0;

		$window_str = '';
		if ( $first_time && $last_time ) {
			$window_str = sprintf(
				/* translators: 1: first time, 2: last time */
				__( 'between %1$s and %2$s', 'wc-blacklist-manager' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $first_time ) ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_time ) )
			);
		}

		$headline = __( '⚠️ Unusual spike of failed payments detected', 'wc-blacklist-manager' );

		$details  = sprintf(
			/* translators: 1: number of failed orders, 2: time window string */
			__( '%1$d failed orders in a short period %2$s.', 'wc-blacklist-manager' ),
			max( $recent_count, self::FAIL_THRESHOLD ),
			$window_str ? $window_str : ''
		);

		$para_text = __( 'Bots may be testing stolen cards (CVC/CVV).', 'wc-blacklist-manager' ) . ' '
			. __( 'Blacklist Manager Premium blocks card-testing attacks, reduces failed payments, and automatically flags risky activity—keeping your checkout and gateway clean.', 'wc-blacklist-manager' ) . ' '
			. __( 'Upgrade now to turn on advanced bot-defense and fraud automation.', 'wc-blacklist-manager' );

		$cta_label   = __( 'Upgrade to Premium — protect checkout', 'wc-blacklist-manager' );
		$premium_url = $this->get_premium_buy_url();

		echo '<div class="notice notice-error yobmp-notice-captcha is-dismissible" style="border-left-color:#d63638;">'
			. '<p style="margin-top:8px;margin-bottom:8px;"><strong>' . esc_html( $headline ) . ':</strong> ' . esc_html( $details ) . '</p>'
			. '<p style="margin:0 0 10px;">' . esc_html( $para_text ) . '</p>'
			. '<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px;">'
				. '<a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $premium_url ) . '">' . esc_html( $cta_label ) . '</a> '
				. '<a href="#" onclick="YOBM_Admin_Notice.doItLater();return false;">' . esc_html__( 'I’ll do it later', 'wc-blacklist-manager' ) . '</a>'
				. '<span aria-hidden="true">·</span> '
				. '<a href="#" onclick="YOBM_Admin_Notice.resolveSpike();return false;">' . esc_html__( 'Mark as resolved', 'wc-blacklist-manager' ) . '</a>'
			. '</p>'
		. '</div>';
	}

	public function conditionally_enqueue_inline_scripts( $hook ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->premium_is_activated() ) {
			return;
		}

		$user_id      = get_current_user_id();
		$snooze_until = (int) get_user_meta( $user_id, self::UMETA_DISMISS, true );
		if ( $snooze_until && time() < $snooze_until ) {
			return;
		}

		$armed_at = (int) get_option( self::OPTION_SPIKE_ARMED, 0 );
		$times    = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times    = is_array( $times ) ? $times : [];

		$recent_count = $this->count_recent_failures( $times, self::SPIKE_WINDOW_SECONDS );

		// TTL cleanup
		if ( $armed_at && self::SPIKE_TTL > 0 && ( time() - $armed_at ) > self::SPIKE_TTL ) {
			delete_option( self::OPTION_SPIKE_ARMED );
			$armed_at = 0;
		}

		// Only enqueue if we would show the notice (same gate as notice_suggest_enable_captcha)
		if ( $recent_count < self::FAIL_THRESHOLD && ! $armed_at ) {
			return;
		}

		$nonce = wp_create_nonce( 'yobm_notice_suggest_enable_captcha' );

		$script = "
			window.YOBM_Admin_Notice = {
				doItLater: function() {
					jQuery.post( ajaxurl, {
						action: 'notice_suggest_enable_captcha',
						security: '{$nonce}',
						mode: 'snooze'
					}).always(function(){
						jQuery('.notice.yobmp-notice-captcha').slideUp(150);
					});
				},
				resolveSpike: function() {
					jQuery.post( ajaxurl, {
						action: 'notice_suggest_enable_captcha',
						security: '{$nonce}',
						mode: 'resolve'
					}).always(function(){
						jQuery('.notice.yobmp-notice-captcha').slideUp(150);
					});
				}
			};
		";

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $script, 'after' );
	}

	/* =========================================================
	 * AJAX: Snooze / Resolve
	 * ======================================================= */

	public function handle_notice_actions() {
		check_ajax_referer( 'yobm_notice_suggest_enable_captcha', 'security' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'snooze';

		if ( 'resolve' === $mode ) {
			// Clear the spike flag globally so a NEW spike can re-arm and show again.
			delete_option( self::OPTION_SPIKE_ARMED );
			wp_die();
		}

		// Default: snooze this user only (does NOT block future spikes from reappearing after snooze ends).
		update_user_meta( get_current_user_id(), self::UMETA_DISMISS, time() + self::SNOOZE_SECONDS );
		wp_die();
	}

	/* =========================================================
	 * Helpers
	 * ======================================================= */

	private function premium_is_activated() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$premium_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		$license_ok     = WC_Blacklist_Manager_Validator::is_premium_active();
		return ( $premium_active && $license_ok );
	}

	private function get_premium_buy_url() {
		// Update to your pricing/checkout URL.
		return 'https://yoohw.com/product/blacklist-manager-premium/';
	}
}

add_action( 'plugins_loaded', function () {
	// Boot only if WooCommerce is active/loaded
	if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
		new WC_Blacklist_Manager_Alert();
	}
});