<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Alert {

	use YOBM_Bot_Signal_Analyzer;

	/* ===== Backward-compatible per-user meta ===== */
	const UMETA_DISMISS = 'yobm_notice_suggest_enable_anti_bots'; // stores snooze-until timestamp

	/* ===== Existing legacy options kept for compatibility ===== */
	const OPTION_FAIL_STREAK      = 'yobm_failed_orders_streak';
	const OPTION_FAIL_STREAK_TIME = 'yobm_failed_orders_streak_times';
	const OPTION_SPIKE_ARMED      = 'yobm_failed_spike_armed_at';

	/* ===== New options ===== */
	const OPTION_LAST_INCIDENT_FINGERPRINT = 'yobm_notice_last_bot_incident_fp_free';
	const TRANSIENT_BOT_SIGNAL_SUMMARY     = 'yobm_notice_bot_signal_summary_free';

	/* ===== Timing / thresholds ===== */
	const ANALYSIS_WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS;
	const CACHE_TTL               = 60;
	const SNOOZE_SECONDS          = DAY_IN_SECONDS;
	const SPIKE_TTL               = WEEK_IN_SECONDS;

	const MIN_SUSPICIOUS_ORDERS  = 5;
	const MIN_UNIQUE_EMAILS      = 4;
	const MIN_UNIQUE_IPS         = 2;
	const MIN_TOP_IP_HITS        = 3;
	const MIN_HOT_MINUTE_HITS    = 3;
	const MAX_BURST_SPAN_SECONDS = 10 * MINUTE_IN_SECONDS;

	public function __construct() {
		// Keep legacy tracking for backward compatibility with existing stored options.
		add_action( 'woocommerce_order_status_failed', [ $this, 'track_order_streak' ], 10, 1 );

		add_action( 'admin_notices', [ $this, 'display_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'conditionally_enqueue_inline_scripts' ] );

		// Keep existing AJAX action for compatibility.
		add_action( 'wp_ajax_notice_suggest_enable_anti_bots', [ $this, 'handle_notice_actions' ] );
	}

	/* =========================================================
	 * Legacy order tracking kept for compatibility
	 * ======================================================= */

	public function track_order_streak( $order_id ) {
		$streak = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		$times  = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times  = is_array( $times ) ? $times : [];

		$now     = time();
		$streak++;
		$times[] = $now;

		$times = array_slice( $times, -10 );

		$recent_count = $this->count_recent_failures( $times, self::ANALYSIS_WINDOW_SECONDS );

		if ( $recent_count >= self::MIN_SUSPICIOUS_ORDERS && ! get_option( self::OPTION_SPIKE_ARMED, 0 ) ) {
			update_option( self::OPTION_SPIKE_ARMED, $now, false );
		}

		update_option( self::OPTION_FAIL_STREAK, $streak, false );
		update_option( self::OPTION_FAIL_STREAK_TIME, wp_json_encode( $times ), false );

		// Clear cached summary so the next admin page load reflects the new order immediately.
		delete_transient( self::TRANSIENT_BOT_SIGNAL_SUMMARY );
	}

	private function count_recent_failures( array $timestamps, int $window_seconds ): int {
		$cutoff = time() - $window_seconds;
		$count  = 0;

		foreach ( $timestamps as $ts ) {
			if ( (int) $ts >= $cutoff ) {
				$count++;
			}
		}

		return $count;
	}

	/* =========================================================
	 * Notices
	 * ======================================================= */

	public function display_notices() {
		if ( ! $this->base_notice_gates_pass() ) {
			return;
		}

		$summary = $this->get_bot_signal_summary();
		if ( empty( $summary['show'] ) ) {
			return;
		}

		$this->maybe_clear_snooze_on_new_incident( $summary );

		$user_id      = get_current_user_id();
		$snooze_until = (int) get_user_meta( $user_id, self::UMETA_DISMISS, true );

		if ( $snooze_until && time() < $snooze_until ) {
			return;
		}

		$order_count    = isset( $summary['suspicious_orders'] ) ? (int) $summary['suspicious_orders'] : 0;
		$unique_emails  = isset( $summary['unique_emails'] ) ? (int) $summary['unique_emails'] : 0;
		$unique_ips     = isset( $summary['unique_ips'] ) ? (int) $summary['unique_ips'] : 0;
		$top_ip_hits    = isset( $summary['top_ip_hits'] ) ? (int) $summary['top_ip_hits'] : 0;
		$top_ip         = ! empty( $summary['top_ip'] ) ? $summary['top_ip'] : '';
		$top_gateway    = ! empty( $summary['top_gateway'] ) ? $summary['top_gateway'] : '';
		$window_minutes = (int) floor( self::ANALYSIS_WINDOW_SECONDS / MINUTE_IN_SECONDS );
		$severity       = isset( $summary['severity'] ) ? $summary['severity'] : 'warning';

		$detail_bits = [];

		if ( $unique_emails > 0 ) {
			$detail_bits[] = sprintf(
				/* translators: %d = number of emails */
				_n( '%d email', '%d emails', $unique_emails, 'wc-blacklist-manager' ),
				$unique_emails
			);
		}

		if ( $unique_ips > 0 ) {
			$detail_bits[] = sprintf(
				/* translators: %d = number of IPs */
				_n( '%d IP', '%d IPs', $unique_ips, 'wc-blacklist-manager' ),
				$unique_ips
			);
		}

		if ( $top_ip_hits >= self::MIN_TOP_IP_HITS && $top_ip ) {
			$detail_bits[] = sprintf(
				/* translators: 1: count, 2: IP */
				__( '%1$d attempts from the same IP (%2$s)', 'wc-blacklist-manager' ),
				$top_ip_hits,
				$top_ip
			);
		}

		if ( $top_gateway ) {
			$detail_bits[] = sprintf(
				/* translators: %s = payment gateway */
				__( 'mostly via %s', 'wc-blacklist-manager' ),
				$top_gateway
			);
		}

		$detail_text = '';
		if ( ! empty( $detail_bits ) ) {
			$detail_text = ' ' . implode( ' • ', $detail_bits ) . '.';
		}

		$severity_class = ( 'critical' === $severity ) ? 'notice-error' : 'notice-warning';

		$title = ( 'critical' === $severity )
			? __( 'High-risk bot / card testing activity detected', 'wc-blacklist-manager' )
			: __( 'Suspicious checkout activity detected', 'wc-blacklist-manager' );

		$message_intro = ( 'critical' === $severity )
			? __( 'Your store may be under active bot or card-testing attack.', 'wc-blacklist-manager' )
			: __( 'We detected unusual checkout behavior that may indicate bot activity.', 'wc-blacklist-manager' );

		$premium_url = $this->get_premium_buy_url();
		?>
		<div class="notice <?php echo esc_attr( $severity_class ); ?> yobm-notice-anti-bots is-dismissible">
			<div class="yobmp-notice-inner">
				<div class="yobmp-content">
					<h3 class="yobmp-title">⚠️ <?php echo esc_html( $title ); ?></h3>
					<p class="yobmp-msg">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: intro text, 2: suspicious order count, 3: minutes, 4: detail text */
								__( '%1$s We detected <b style="color:#d63638;">%2$d suspicious orders</b> in the last %3$d minutes.%4$s Advanced anti-bot protection and fraud automation are available in Blacklist Manager Premium.', 'wc-blacklist-manager' ),
								esc_html( $message_intro ),
								$order_count,
								$window_minutes,
								$detail_text
							),
							[
								'b' => [
									'style' => [],
								],
							]
						);
						?>
					</p>
					<p class="yobmp-actions">
						<a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $premium_url ); ?>">
							<?php esc_html_e( 'Upgrade to Premium — protect checkout', 'wc-blacklist-manager' ); ?>
						</a>

						<button type="button" class="button-secondary yobmp-link" data-yobm-action="later">
							<?php esc_html_e( 'I’ll do it later', 'wc-blacklist-manager' ); ?>
						</button>

						<button type="button" class="button-secondary yobmp-link" data-yobm-action="resolve">
							<?php esc_html_e( 'Mark as resolved', 'wc-blacklist-manager' ); ?>
						</button>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	public function conditionally_enqueue_inline_scripts( $hook ) {
		if ( ! $this->base_notice_gates_pass() ) {
			return;
		}

		$summary = $this->get_bot_signal_summary();
		if ( empty( $summary['show'] ) ) {
			return;
		}

		$user_id      = get_current_user_id();
		$snooze_until = (int) get_user_meta( $user_id, self::UMETA_DISMISS, true );

		if ( $snooze_until && time() < $snooze_until ) {
			return;
		}

		$nonce = wp_create_nonce( 'yobm_notice_suggest_enable_anti_bots' );

		$css = '
		.yobmp-notice-anti-bots .yobmp-notice-inner{display:flex;gap:12px;align-items:flex-start;margin:10px 5px;}
		.yobmp-notice-anti-bots .yobmp-icon{font-size:24px;line-height:1;margin-top:2px}
		.yobmp-notice-anti-bots .yobmp-title{margin:4px 0 6px;font-weight:600}
		.yobmp-notice-anti-bots .yobmp-actions{margin-top:10px}
		.yobmp-notice-anti-bots .yobmp-actions .button{margin-right:6px}
		.yobmp-notice-anti-bots .yobmp-actions .sep{margin:0 6px;color:#777}
		';

		$js = "
		(function($){
			if (!window.YOBM_Admin_Notice) { window.YOBM_Admin_Notice = {}; }

			function hideNotice(){
				$('.yobmp-notice-anti-bots').fadeOut(150, function(){ $(this).remove(); });
			}

			YOBM_Admin_Notice.doItLater = function(){
				$.post(ajaxurl, {
					action: 'notice_suggest_enable_anti_bots',
					security: '{$nonce}',
					mode: 'snooze'
				}).always(hideNotice);
			};

			YOBM_Admin_Notice.resolveSpike = function(){
				$.post(ajaxurl, {
					action: 'notice_suggest_enable_anti_bots',
					security: '{$nonce}',
					mode: 'resolve'
				}).always(hideNotice);
			};

			$(document).on('click', '.yobmp-notice-anti-bots [data-yobm-action=\"later\"]', function(e){
				e.preventDefault();
				YOBM_Admin_Notice.doItLater();
			});

			$(document).on('click', '.yobmp-notice-anti-bots [data-yobm-action=\"resolve\"]', function(e){
				e.preventDefault();
				YOBM_Admin_Notice.resolveSpike();
			});
		})(jQuery);
		";

		wp_register_style( 'yobm-alert-inline', false );
		wp_enqueue_style( 'yobm-alert-inline' );
		wp_add_inline_style( 'yobm-alert-inline', $css );

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $js, 'after' );
	}

	/* =========================================================
	 * AJAX: Snooze / Resolve
	 * ======================================================= */

	public function handle_notice_actions() {
		check_ajax_referer( 'yobm_notice_suggest_enable_anti_bots', 'security' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'snooze';

		if ( 'resolve' === $mode ) {
			delete_option( self::OPTION_SPIKE_ARMED );
			delete_transient( self::TRANSIENT_BOT_SIGNAL_SUMMARY );
			delete_user_meta( get_current_user_id(), self::UMETA_DISMISS );

			// Reset old streak counter too, so the same stale wave does not keep re-triggering immediately.
			update_option( self::OPTION_FAIL_STREAK, 0, false );
			update_option( self::OPTION_FAIL_STREAK_TIME, wp_json_encode( [] ), false );

			wp_die();
		}

		update_user_meta( get_current_user_id(), self::UMETA_DISMISS, time() + self::SNOOZE_SECONDS );
		wp_die();
	}

	/* =========================================================
	 * Core gating
	 * ======================================================= */

private function base_notice_gates_pass(): bool {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return false;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	if ( $this->premium_is_activated() ) {
		return false;
	}

	return true;
}

	/* =========================================================
	 * Bot signal analysis
	 * ======================================================= */

	private function get_bot_signal_summary(): array {
		$summary = $this->get_bot_signal_summary_shared();

		if ( ! empty( $summary['show'] ) && ! get_option( self::OPTION_SPIKE_ARMED, 0 ) ) {
			update_option( self::OPTION_SPIKE_ARMED, time(), false );
		}

		return $summary;
	}

	private function maybe_clear_snooze_on_new_incident( array $summary ) {
		$this->maybe_clear_shared_notice_state(
			$summary,
			self::OPTION_LAST_INCIDENT_FINGERPRINT,
			self::UMETA_DISMISS
		);
	}

	/* =========================================================
	 * Helpers
	 * ======================================================= */

	protected function get_bot_signal_cache_key(): string {
		return self::TRANSIENT_BOT_SIGNAL_SUMMARY;
	}

	protected function get_bot_signal_debug_label(): string {
		return 'YOBM Free Alert';
	}

	private function premium_is_activated() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$premium_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		$license_ok     = WC_Blacklist_Manager_Validator::is_premium_active();

		return ( $premium_active && $license_ok );
	}

	private function get_premium_buy_url() {
		return 'https://yoohw.com/product/blacklist-manager-premium/';
	}
}

add_action( 'plugins_loaded', function () {
	// Boot only if WooCommerce is active/loaded
	if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
		new WC_Blacklist_Manager_Alert();
	}
});