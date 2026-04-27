<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Alert {

	use YOBM_Bot_Signal_Analyzer;

	const UMETA_DISMISS = 'yobm_notice_suggest_enable_anti_bots';

	const OPTION_SPIKE_ARMED               = 'yobm_failed_spike_armed_at';
	const OPTION_LAST_INCIDENT_FINGERPRINT = 'yobm_notice_last_bot_incident_fp_free';

	const TRANSIENT_BOT_SIGNAL_SUMMARY = 'yobm_notice_bot_signal_summary_free';

	const ANALYSIS_WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS;
	const CACHE_TTL               = 60;
	const SNOOZE_SECONDS          = DAY_IN_SECONDS;

	const MIN_SUSPICIOUS_ORDERS  = 5;
	const MIN_UNIQUE_EMAILS      = 4;
	const MIN_TOP_IP_HITS        = 3;
	const MIN_HOT_MINUTE_HITS    = 3;
	const MAX_BURST_SPAN_SECONDS = 10 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_action( 'admin_notices', [ $this, 'display_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'conditionally_enqueue_inline_scripts' ] );

		add_action( 'wp_ajax_notice_suggest_enable_anti_bots', [ $this, 'handle_notice_actions' ] );
	}

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

		$order_count      = isset( $summary['suspicious_orders'] ) ? (int) $summary['suspicious_orders'] : 0;
		$blocked_attempts = isset( $summary['blocked_attempts'] ) ? (int) $summary['blocked_attempts'] : 0;
		$activity_count   = $order_count + $blocked_attempts;

		$unique_emails  = isset( $summary['unique_emails'] ) ? (int) $summary['unique_emails'] : 0;
		$unique_ips     = isset( $summary['unique_ips'] ) ? (int) $summary['unique_ips'] : 0;
		$top_ip_hits    = isset( $summary['top_ip_hits'] ) ? (int) $summary['top_ip_hits'] : 0;
		$top_ip         = ! empty( $summary['top_ip'] ) ? $summary['top_ip'] : '';
		$top_gateway    = ! empty( $summary['top_gateway'] ) ? $summary['top_gateway'] : '';
		$window_minutes = (int) floor( self::ANALYSIS_WINDOW_SECONDS / MINUTE_IN_SECONDS );
		$severity       = isset( $summary['severity'] ) ? $summary['severity'] : 'warning';

		$detail_bits = [];

		if ( $order_count > 0 ) {
			$detail_bits[] = sprintf(
				_n( '%d suspicious order', '%d suspicious orders', $order_count, 'wc-blacklist-manager' ),
				$order_count
			);
		}

		if ( $blocked_attempts > 0 ) {
			$detail_bits[] = sprintf(
				_n( '%d blocked attempt', '%d blocked attempts', $blocked_attempts, 'wc-blacklist-manager' ),
				$blocked_attempts
			);
		}

		if ( $unique_emails > 0 ) {
			$detail_bits[] = sprintf(
				_n( '%d email', '%d emails', $unique_emails, 'wc-blacklist-manager' ),
				$unique_emails
			);
		}

		if ( $unique_ips > 0 ) {
			$detail_bits[] = sprintf(
				_n( '%d IP', '%d IPs', $unique_ips, 'wc-blacklist-manager' ),
				$unique_ips
			);
		}

		if ( $top_ip_hits >= self::MIN_TOP_IP_HITS && $top_ip ) {
			$detail_bits[] = sprintf(
				__( '%1$d attempts from the same IP (%2$s)', 'wc-blacklist-manager' ),
				$top_ip_hits,
				$top_ip
			);
		}

		if ( ! empty( $summary['top_device_hits'] ) && (int) $summary['top_device_hits'] >= 3 ) {
			$detail_bits[] = sprintf(
				__( '%d attempts from the same device', 'wc-blacklist-manager' ),
				(int) $summary['top_device_hits']
			);
		}

		if ( ! empty( $summary['top_email_domain_hits'] ) && ! empty( $summary['top_email_domain'] ) ) {
			$detail_bits[] = sprintf(
				__( '%1$d emails from %2$s', 'wc-blacklist-manager' ),
				(int) $summary['top_email_domain_hits'],
				esc_html( $summary['top_email_domain'] )
			);
		}

		if ( ! empty( $summary['store_api_attempts'] ) ) {
			$detail_bits[] = sprintf(
				__( '%d Store API attempts', 'wc-blacklist-manager' ),
				(int) $summary['store_api_attempts']
			);
		}

		if ( $top_gateway ) {
			$detail_bits[] = sprintf(
				__( 'mostly via %s', 'wc-blacklist-manager' ),
				esc_html( $top_gateway )
			);
		}

		$detail_text = ! empty( $detail_bits ) ? ' ' . implode( ' • ', $detail_bits ) . '.' : '';

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
								__( '%1$s We detected <b style="color:#d63638;">%2$d suspicious checkout activities</b> in the last %3$d minutes.%4$s Advanced anti-bot protection and fraud automation are available in Blacklist Manager Premium.', 'wc-blacklist-manager' ),
								esc_html( $message_intro ),
								$activity_count,
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
		.yobm-notice-anti-bots .yobmp-notice-inner{display:flex;gap:12px;align-items:flex-start;margin:10px 5px;}
		.yobm-notice-anti-bots .yobmp-title{margin:4px 0 6px;font-weight:600}
		.yobm-notice-anti-bots .yobmp-actions{margin-top:10px}
		.yobm-notice-anti-bots .yobmp-actions .button{margin-right:6px}
		';

		$js = "
		(function($){
			function hideNotice(){
				$('.yobm-notice-anti-bots').fadeOut(150, function(){
					$(this).remove();
				});
			}

			$(document).on('click', '.yobm-notice-anti-bots [data-yobm-action=\"later\"]', function(e){
				e.preventDefault();

				$.post(ajaxurl, {
					action: 'notice_suggest_enable_anti_bots',
					security: '{$nonce}',
					mode: 'snooze'
				}).always(hideNotice);
			});

			$(document).on('click', '.yobm-notice-anti-bots [data-yobm-action=\"resolve\"]', function(e){
				e.preventDefault();

				$.post(ajaxurl, {
					action: 'notice_suggest_enable_anti_bots',
					security: '{$nonce}',
					mode: 'resolve'
				}).always(hideNotice);
			});
		})(jQuery);
		";

		wp_register_style( 'yobm-alert-inline', false );
		wp_enqueue_style( 'yobm-alert-inline' );
		wp_add_inline_style( 'yobm-alert-inline', $css );

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $js, 'after' );
	}

	public function handle_notice_actions() {
		check_ajax_referer( 'yobm_notice_suggest_enable_anti_bots', 'security' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'snooze';

		if ( 'resolve' === $mode ) {
			delete_option( self::OPTION_SPIKE_ARMED );
			delete_transient( self::TRANSIENT_BOT_SIGNAL_SUMMARY );
			delete_user_meta( get_current_user_id(), self::UMETA_DISMISS );

			wp_die();
		}

		update_user_meta( get_current_user_id(), self::UMETA_DISMISS, time() + self::SNOOZE_SECONDS );

		wp_die();
	}

	private function base_notice_gates_pass() {
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

	private function get_bot_signal_summary() {
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

	protected function get_bot_signal_cache_key(): string {
		return self::TRANSIENT_BOT_SIGNAL_SUMMARY;
	}

	private function premium_is_activated() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$premium_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );

		if ( ! $premium_active ) {
			return false;
		}

		if ( ! class_exists( 'WC_Blacklist_Manager_Validator' ) ) {
			return false;
		}

		return (bool) WC_Blacklist_Manager_Validator::is_premium_active();
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