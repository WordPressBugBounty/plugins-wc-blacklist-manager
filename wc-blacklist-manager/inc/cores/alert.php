<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy-safe retrospective Core security alert.
 *
 * The alert is admin-side only. It never participates in checkout enforcement.
 */
class WC_Blacklist_Manager_Alert {

	use YOBM_Bot_Signal_Analyzer;

	const UMETA_DISMISS = 'yobm_notice_suggest_enable_anti_bots';

	const OPTION_SPIKE_ARMED               = 'yobm_failed_spike_armed_at';
	const OPTION_LAST_INCIDENT_FINGERPRINT = 'yobm_notice_last_bot_incident_fp_free';
	const OPTION_ACTIVE_EPISODE             = 'yobm_security_alert_active_episode_free_v1';

	const TRANSIENT_BOT_SIGNAL_SUMMARY = 'yobm_notice_bot_signal_summary_free';

	const ANALYSIS_WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS; // Legacy Premium trait compatibility.
	const CACHE_TTL               = 5 * MINUTE_IN_SECONDS;
	const SNOOZE_SECONDS          = DAY_IN_SECONDS;
	const EPISODE_TTL             = 48 * HOUR_IN_SECONDS;

	// Legacy Premium trait compatibility constants.
	const MIN_SUSPICIOUS_ORDERS  = 5;
	const MIN_UNIQUE_EMAILS      = 4;
	const MIN_TOP_IP_HITS        = 3;
	const MIN_HOT_MINUTE_HITS    = 3;
	const MAX_BURST_SPAN_SECONDS = 10 * MINUTE_IN_SECONDS;

	private static $opportunity_instance = null;

	private $request_summary = null;
	private $request_summary_needs_cache_write = false;
	private $request_active_loaded = false;
	private $request_active = null;

	public function __construct() {
		self::$opportunity_instance = $this;

		add_action( 'admin_notices', array( $this, 'display_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'conditionally_enqueue_inline_scripts' ) );
		add_action( 'wp_ajax_notice_suggest_enable_anti_bots', array( $this, 'handle_notice_actions' ) );
	}

	public function display_notices() {
		if ( $this->is_dashboard_surface() ) {
			return;
		}

		if ( ! $this->base_notice_gates_pass() || $this->current_user_is_snoozed() ) {
			return;
		}

		$model = $this->get_security_view_model( true );
		if ( empty( $model ) ) {
			return;
		}

		$this->render_security_alert( $model, 'notice' );
	}

	/** Render the plugin Dashboard surface through the same instance/state path. */
	public static function render_dashboard_security_panel(): void {
		$instance = self::$opportunity_instance;
		if ( ! $instance instanceof self || ! $instance->base_notice_gates_pass() || $instance->current_user_is_snoozed() ) {
			return;
		}

		$model = $instance->get_security_view_model( true );
		if ( empty( $model ) ) {
			return;
		}

		$instance->render_security_alert( $model, 'dashboard' );
	}

	/** Read-only security candidate provider for Core's private resolver. */
	public static function get_opportunity_candidate() {
		$instance = self::$opportunity_instance;
		if ( ! $instance instanceof self || ! $instance->base_notice_gates_pass() || $instance->current_user_is_snoozed() ) {
			return array();
		}

		$model = $instance->get_security_view_model( false );
		if ( empty( $model ) ) {
			return array();
		}

		return array(
			'id'       => class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
				? WC_Blacklist_Manager_Opportunity_Engine::SECURITY_ID
				: 'premium.security.free_alert',
			'target'   => 'premium',
			'priority' => 300,
			'sort'     => 0,
		);
	}

	private function get_security_view_model( bool $persist ) {
		$active = $this->get_active_episode( $persist );
		if ( ! is_array( $active ) ) {
			$summary = $this->get_security_summary( $persist );
			if ( empty( $summary['show'] ) || empty( $summary['candidate'] ) ) {
				return array();
			}

			$candidate = $summary['candidate'];
			$acknowledged = (string) get_option( self::OPTION_LAST_INCIDENT_FINGERPRINT, '' );
			if ( '' !== $acknowledged && hash_equals( $acknowledged, (string) $candidate['episode_hash'] ) ) {
				return array();
			}

			if ( $persist ) {
				$active = $this->create_active_episode( $candidate );
			} else {
				$now = time();
				$active = array_merge(
					array( 'schema' => 1, 'detected_at' => $now, 'expires_at' => $now + self::EPISODE_TTL ),
					$candidate
				);
			}
		}

		if ( ! $this->is_valid_active_episode( $active ) ) {
			return array();
		}

		$copy = $this->get_security_copy( (string) $active['family'] );
		return array_merge(
			$active,
			$copy,
			array(
				'recency' => $this->format_recency( (int) $active['last_seen'] ),
				'detail'  => $this->format_family_detail( $active ),
			)
		);
	}

	private function get_security_summary( bool $write_cache ): array {
		if ( is_array( $this->request_summary ) ) {
			if ( $write_cache && $this->request_summary_needs_cache_write ) {
				set_transient( self::TRANSIENT_BOT_SIGNAL_SUMMARY, $this->request_summary, self::CACHE_TTL );
				$this->request_summary_needs_cache_write = false;
			}
			return $this->request_summary;
		}

		$cached = $write_cache
			? get_transient( self::TRANSIENT_BOT_SIGNAL_SUMMARY )
			: $this->get_bot_signal_transient_read_only( self::TRANSIENT_BOT_SIGNAL_SUMMARY );
		if ( $this->bm0095_is_safe_summary( $cached ) ) {
			$this->request_summary = $cached;
			return $this->request_summary;
		}

		$this->request_summary = $this->get_bm0095_security_summary_shared( false );
		if ( $write_cache ) {
			set_transient( self::TRANSIENT_BOT_SIGNAL_SUMMARY, $this->request_summary, self::CACHE_TTL );
		} else {
			$this->request_summary_needs_cache_write = true;
		}

		return $this->request_summary;
	}

	private function get_active_episode( bool $cleanup ) {
		if ( $this->request_active_loaded ) {
			return $this->request_active;
		}

		$active = get_option( self::OPTION_ACTIVE_EPISODE, array() );
		if ( ! $this->is_valid_active_episode( $active ) ) {
			if ( $cleanup && false !== $active && ! empty( $active ) ) {
				delete_option( self::OPTION_ACTIVE_EPISODE );
			}
			// A read-only Opportunity probe must not pin an invalid/missing value in
			// request memory before the later state-owning render can clean/create it.
			$this->request_active_loaded = $cleanup;
			$this->request_active = null;
			return null;
		}

		if ( (int) $active['expires_at'] <= time() ) {
			if ( $cleanup ) {
				delete_option( self::OPTION_ACTIVE_EPISODE );
				delete_option( self::OPTION_SPIKE_ARMED );
			}
			$this->request_active_loaded = $cleanup;
			$this->request_active = null;
			return null;
		}

		$this->request_active_loaded = true;
		$this->request_active = $active;
		return $active;
	}

	private function create_active_episode( array $candidate ) {
		if ( ! $this->bm0095_is_safe_candidate( $candidate ) ) {
			return null;
		}

		// PR-09: an unexpired local episode is stable and is never replaced here.
		$existing = $this->get_active_episode( true );
		if ( is_array( $existing ) ) {
			return $existing;
		}

		$now      = time();
		$snapshot = array_merge(
			array( 'schema' => 1 ),
			$candidate,
			array( 'detected_at' => $now, 'expires_at' => $now + self::EPISODE_TTL )
		);
		$snapshot = $this->sanitize_active_episode( $snapshot );
		if ( ! $this->is_valid_active_episode( $snapshot ) ) {
			return null;
		}

		$created = add_option( self::OPTION_ACTIVE_EPISODE, $snapshot, '', false );
		if ( ! $created ) {
			$snapshot = get_option( self::OPTION_ACTIVE_EPISODE, array() );
			if ( ! $this->is_valid_active_episode( $snapshot ) ) {
				return null;
			}
		}

		if ( ! get_option( self::OPTION_SPIKE_ARMED, 0 ) ) {
			update_option( self::OPTION_SPIKE_ARMED, $now, false );
		}

		$this->request_active_loaded = true;
		$this->request_active = $snapshot;
		return $snapshot;
	}

	private function sanitize_active_episode( array $episode ): array {
		return array(
			'schema'             => 1,
			'family'             => sanitize_key( (string) ( $episode['family'] ?? '' ) ),
			'mode'               => sanitize_key( (string) ( $episode['mode'] ?? '' ) ),
			'origin'             => sanitize_key( (string) ( $episode['origin'] ?? '' ) ),
			'episode_start'      => max( 0, (int) ( $episode['episode_start'] ?? 0 ) ),
			'last_seen'          => max( 0, (int) ( $episode['last_seen'] ?? 0 ) ),
			'detected_at'        => max( 0, (int) ( $episode['detected_at'] ?? 0 ) ),
			'expires_at'         => max( 0, (int) ( $episode['expires_at'] ?? 0 ) ),
			'episode_hash'       => strtolower( (string) ( $episode['episode_hash'] ?? '' ) ),
			'event_count'        => max( 0, (int) ( $episode['event_count'] ?? 0 ) ),
			'identity_dimension' => sanitize_key( (string) ( $episode['identity_dimension'] ?? '' ) ),
			'identity_count'     => max( 0, (int) ( $episode['identity_count'] ?? 0 ) ),
			'fanout_count'       => max( 0, (int) ( $episode['fanout_count'] ?? 0 ) ),
			'provider'           => sanitize_key( (string) ( $episode['provider'] ?? '' ) ),
			'gateway'            => sanitize_key( (string) ( $episode['gateway'] ?? '' ) ),
		);
	}

	private function is_valid_active_episode( $episode ): bool {
		if ( ! is_array( $episode ) ) {
			return false;
		}
		$allowed = array( 'schema', 'family', 'mode', 'origin', 'episode_start', 'last_seen', 'detected_at', 'expires_at', 'episode_hash', 'event_count', 'identity_dimension', 'identity_count', 'fanout_count', 'provider', 'gateway' );
		$dimensions = array( 'device', 'session', 'account', 'ip', 'phone' );
		$modes = array(
			'paypal_flow_suspected' => 'paypal_flow_tampering',
			'card_testing_suspected' => 'paypal_card_cracking_sequence',
			'store_api_abuse' => 'store_api_automation_burst',
			'challenge_abuse' => 'challenge_replay_burst',
			'repeat_blocked_identity' => 'multi_source_repeat_actor',
			'checkout_velocity_spike' => 'linked_checkout_fanout',
		);
		$family = (string) ( $episode['family'] ?? '' );
		return ! array_diff( array_keys( $episode ), $allowed )
			&& ! array_diff( $allowed, array_keys( $episode ) )
			&& 1 === (int) ( $episode['schema'] ?? 0 )
			&& isset( $modes[ $family ] )
			&& $modes[ $family ] === (string) ( $episode['mode'] ?? '' )
			&& 'local_structural' === (string) ( $episode['origin'] ?? '' )
			&& in_array( (string) ( $episode['identity_dimension'] ?? '' ), $dimensions, true )
			&& (int) ( $episode['episode_start'] ?? 0 ) > 0
			&& (int) ( $episode['last_seen'] ?? 0 ) >= (int) ( $episode['episode_start'] ?? 0 )
			&& (int) ( $episode['event_count'] ?? -1 ) >= 0 && (int) ( $episode['event_count'] ?? 401 ) <= 400
			&& (int) ( $episode['identity_count'] ?? -1 ) >= 0 && (int) ( $episode['identity_count'] ?? 401 ) <= 400
			&& (int) ( $episode['fanout_count'] ?? -1 ) >= 0 && (int) ( $episode['fanout_count'] ?? 401 ) <= 400
			&& in_array( (string) ( $episode['provider'] ?? '' ), array( '', 'paypal' ), true )
			&& in_array( (string) ( $episode['gateway'] ?? '' ), array( '', 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-card-button-gateway' ), true )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $episode['episode_hash'] ?? '' ) )
			&& (int) ( $episode['detected_at'] ?? 0 ) > 0
			&& (int) ( $episode['expires_at'] ?? 0 ) === (int) $episode['detected_at'] + self::EPISODE_TTL;
	}

	public function conditionally_enqueue_inline_scripts( $hook ) {
		unset( $hook );
		if ( ! $this->base_notice_gates_pass() || $this->current_user_is_snoozed() || empty( $this->get_security_view_model( true ) ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'yobm_notice_suggest_enable_anti_bots' );
		$css = '
		.yobm-security-alert .yobmp-notice-inner{display:flex;gap:12px;align-items:flex-start;margin:10px 5px}
		.yobm-security-alert .yobmp-title{margin:4px 0 6px;font-weight:600}
		.yobm-security-alert .yobmp-actions{margin-top:10px}
		.yobm-security-alert .yobmp-actions .button{margin-right:6px}
		.yobm-security-panel{border-left:4px solid #dba617;background:#fff;padding:12px 16px;margin:16px 0}
		';
		$js = "
		(function($){
			function hideAlert(){ $('.yobm-security-alert').fadeOut(150, function(){ $(this).remove(); }); }
			$(document).on('click', '.yobm-security-alert [data-yobm-action]', function(e){
				e.preventDefault();
				$.post(ajaxurl, {
					action: 'notice_suggest_enable_anti_bots',
					security: '" . esc_js( $nonce ) . "',
					mode: $(this).data('yobm-action')
				}).always(hideAlert);
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-blacklist-manager' ) ), 403 );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'snooze';
		if ( in_array( $mode, array( 'dismiss', 'resolve' ), true ) ) {
			$active = get_option( self::OPTION_ACTIVE_EPISODE, array() );
			if ( $this->is_valid_active_episode( $active ) ) {
				update_option( self::OPTION_LAST_INCIDENT_FINGERPRINT, (string) $active['episode_hash'], false );
			}
			delete_option( self::OPTION_ACTIVE_EPISODE );
			delete_option( self::OPTION_SPIKE_ARMED );
			delete_transient( self::TRANSIENT_BOT_SIGNAL_SUMMARY );
			delete_user_meta( get_current_user_id(), self::UMETA_DISMISS );
			wp_send_json_success();
		}

		update_user_meta( get_current_user_id(), self::UMETA_DISMISS, time() + self::SNOOZE_SECONDS );
		wp_send_json_success();
	}

	private function render_security_alert( array $model, string $surface ): void {
		$dashboard = 'dashboard' === $surface;
		$focus = self::get_security_focus( (string) ( $model['family'] ?? '' ) );
		$paid_selected = '' !== $focus
			&& class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
			&& WC_Blacklist_Manager_Opportunity_Engine::is_selected( WC_Blacklist_Manager_Opportunity_Engine::SECURITY_ID );
		$premium_url = add_query_arg(
			array(
				'page'  => 'wc-blacklist-manager-premium',
				'focus' => $focus,
			),
			admin_url( 'admin.php' )
		);

		$tag = $dashboard ? 'section' : 'div';
		$class = $dashboard ? 'yobm-security-alert yobm-security-panel' : 'notice notice-warning yobm-security-alert is-dismissible';
		?>
		<<?php echo esc_html( $tag ); ?> class="<?php echo esc_attr( $class ); ?>" aria-label="<?php echo esc_attr__( 'Security alert', 'wc-blacklist-manager' ); ?>">
			<div class="yobmp-notice-inner"><div class="yobmp-content">
				<h3 class="yobmp-title">⚠️ <?php echo esc_html( $model['title'] ); ?></h3>
				<p class="yobmp-msg">
					<?php echo esc_html( $model['intro'] ); ?>
					<?php echo esc_html( $model['detail'] ); ?>
					<?php echo esc_html( $model['recency'] ); ?>
					<?php echo esc_html__( 'This retrospective warning does not confirm a live attack and does not block checkout by itself.', 'wc-blacklist-manager' ); ?>
					<?php if ( $paid_selected ) : ?> <?php echo esc_html( $model['premium'] ); ?><?php endif; ?>
				</p>
				<p class="yobmp-actions">
					<?php if ( $paid_selected ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $premium_url ); ?>"><?php echo esc_html( $model['cta'] ); ?></a>
					<?php endif; ?>
					<button type="button" class="button button-secondary" data-yobm-action="snooze"><?php esc_html_e( 'Remind me tomorrow', 'wc-blacklist-manager' ); ?></button>
					<button type="button" class="button button-secondary" data-yobm-action="dismiss"><?php esc_html_e( 'Dismiss this alert', 'wc-blacklist-manager' ); ?></button>
				</p>
			</div></div>
		</<?php echo esc_html( $tag ); ?>>
		<?php
	}

	/**
	 * Return the finite internal Advanced Protection focus for one validated family.
	 *
	 * @internal Core presentation contract; not an extension API.
	 */
	public static function get_security_focus( string $family ): string {
		$family  = sanitize_key( $family );
		$catalog = self::get_security_focus_catalog();

		return isset( $catalog[ $family ] ) ? (string) $catalog[ $family ]['focus'] : '';
	}

	/**
	 * Resolve fixed, non-evidentiary presentation copy from an allowlisted focus.
	 *
	 * @internal Core presentation contract; not an extension API.
	 */
	public static function get_security_focus_presentation( string $focus ): array {
		$focus = sanitize_key( $focus );
		if ( '' === $focus ) {
			return array();
		}

		foreach ( self::get_security_focus_catalog() as $family => $presentation ) {
			if ( $focus === $presentation['focus'] ) {
				return array_merge( array( 'family' => $family ), $presentation );
			}
		}

		return array();
	}

	/** Return the complete closed family/focus presentation catalog. */
	private static function get_security_focus_catalog(): array {
		return array(
			'paypal_flow_suspected' => array(
				'focus' => 'paypal-flow',
				'title' => __( 'Review PayPal Flow Protection', 'wc-blacklist-manager' ),
				'body'  => __( 'This protection is relevant to repeated PayPal payment-flow patterns and gateway-aware challenge context.', 'wc-blacklist-manager' ),
				'cards' => array(
					array(
						'icon'  => 'dashicons-shield-alt',
						'title' => __( 'Gateway-aware flow checks', 'wc-blacklist-manager' ),
						'body'  => __( 'Premium can add payment-flow context for supported PayPal and Braintree checkout paths.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'  => 'dashicons-lock',
						'title' => __( 'Contextual challenges', 'wc-blacklist-manager' ),
						'body'  => __( 'Gateway-aware challenge controls can add friction when configured risk conditions require review.', 'wc-blacklist-manager' ),
					),
				),
			),
			'card_testing_suspected' => array(
				'focus' => 'card-testing',
				'title' => __( 'Review Card Testing Protection', 'wc-blacklist-manager' ),
				'body'  => __( 'This protection is relevant to repeated card-validation patterns across changing billing identities.', 'wc-blacklist-manager' ),
				'cards' => array(
					array(
						'icon'  => 'dashicons-chart-line',
						'title' => __( 'Card-testing context', 'wc-blacklist-manager' ),
						'body'  => __( 'Premium can combine supported payment and identity signals to add context to card-testing review.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'  => 'dashicons-shield',
						'title' => __( 'Risk-based challenges', 'wc-blacklist-manager' ),
						'body'  => __( 'Configured challenge controls can add friction to payment flows that meet reviewed risk conditions.', 'wc-blacklist-manager' ),
					),
				),
			),
			'store_api_abuse' => array(
				'focus' => 'store-api',
				'title' => __( 'Review Store API Protection', 'wc-blacklist-manager' ),
				'body'  => __( 'This protection is relevant to automated Store API and checkout traffic patterns.', 'wc-blacklist-manager' ),
				'cards' => array(
					array(
						'icon'  => 'dashicons-rest-api',
						'title' => __( 'Store API controls', 'wc-blacklist-manager' ),
						'body'  => __( 'Premium can add rate and request controls around supported Store API checkout paths.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'  => 'dashicons-shield-alt',
						'title' => __( 'Checkout anti-bot context', 'wc-blacklist-manager' ),
						'body'  => __( 'Checkout anti-bot signals can support configured review and challenge decisions.', 'wc-blacklist-manager' ),
					),
				),
			),
			'challenge_abuse' => array(
				'focus' => 'challenge-protection',
				'title' => __( 'Review Active Challenge Protection', 'wc-blacklist-manager' ),
				'body'  => __( 'This protection is relevant to repeated challenge, CAPTCHA, or proof-validation patterns.', 'wc-blacklist-manager' ),
				'cards' => array(
					array(
						'icon'  => 'dashicons-lock',
						'title' => __( 'Active Challenge', 'wc-blacklist-manager' ),
						'body'  => __( 'Premium can require an additional challenge when configured checkout risk conditions are met.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'  => 'dashicons-privacy',
						'title' => __( 'CAPTCHA and proof validation', 'wc-blacklist-manager' ),
						'body'  => __( 'Supported validation controls can help distinguish completed challenges from failed or incomplete proof.', 'wc-blacklist-manager' ),
					),
				),
			),
			'repeat_blocked_identity' => array(
				'focus' => 'repeat-abuse',
				'title' => __( 'Review Repeat-Abuse Protection', 'wc-blacklist-manager' ),
				'body'  => __( 'This protection is relevant when suspicious behavior repeats across related identity signals.', 'wc-blacklist-manager' ),
				'cards' => array(
					array(
						'icon'  => 'dashicons-networking',
						'title' => __( 'Identity risk scoring', 'wc-blacklist-manager' ),
						'body'  => __( 'Premium can add device, session, address, and payment context to supported identity review.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'  => 'dashicons-controls-repeat',
						'title' => __( 'Repeat-action automation', 'wc-blacklist-manager' ),
						'body'  => __( 'Reviewed automation settings can reduce repeated manual responses to related identity patterns.', 'wc-blacklist-manager' ),
					),
				),
			),
			'checkout_velocity_spike' => array(
				'focus' => 'checkout-velocity',
				'title' => __( 'Review Checkout Velocity Protection', 'wc-blacklist-manager' ),
				'body'  => __( 'This protection is relevant to concentrated checkout timing and identity fan-out patterns.', 'wc-blacklist-manager' ),
				'cards' => array(
					array(
						'icon'  => 'dashicons-performance',
						'title' => __( 'Velocity controls', 'wc-blacklist-manager' ),
						'body'  => __( 'Premium can add configured velocity thresholds to supported checkout review paths.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'  => 'dashicons-controls-repeat',
						'title' => __( 'Automated responses', 'wc-blacklist-manager' ),
						'body'  => __( 'Reviewed automation settings can apply consistent actions when configured thresholds are met.', 'wc-blacklist-manager' ),
					),
				),
			),
		);
	}

	private function get_security_copy( string $family ): array {
		$copy = array(
			'paypal_flow_suspected' => array(
				'title' => __( 'Potential PayPal payment-flow tampering observed', 'wc-blacklist-manager' ),
				'intro' => __( 'A short cluster of repeated PayPal challenge-context failures matched this alert.', 'wc-blacklist-manager' ),
				'premium' => __( 'Premium adds PayPal/Braintree payment-flow protection and gateway-aware challenge.', 'wc-blacklist-manager' ),
				'cta' => __( 'Unlock PayPal Flow Protection', 'wc-blacklist-manager' ),
			),
			'card_testing_suspected' => array(
				'title' => __( 'Potential card-testing sequence observed', 'wc-blacklist-manager' ),
				'intro' => __( 'A linked cluster of PayPal card-validation failures across billing identities matched this alert.', 'wc-blacklist-manager' ),
				'premium' => __( 'Premium adds card-testing protection and gateway-aware challenge.', 'wc-blacklist-manager' ),
				'cta' => __( 'Unlock Card Testing Protection', 'wc-blacklist-manager' ),
			),
			'store_api_abuse' => array(
				'title' => __( 'Potential Store API automation observed', 'wc-blacklist-manager' ),
				'intro' => __( 'A short cluster of exact Store API checkout abuse signals matched this alert.', 'wc-blacklist-manager' ),
				'premium' => __( 'Premium adds Store API rate limiting and checkout anti-bot protection.', 'wc-blacklist-manager' ),
				'cta' => __( 'Unlock Store API Protection', 'wc-blacklist-manager' ),
			),
			'challenge_abuse' => array(
				'title' => __( 'Potential challenge replay or evasion observed', 'wc-blacklist-manager' ),
				'intro' => __( 'Repeated correlated challenge, CAPTCHA, or proof failures matched this alert.', 'wc-blacklist-manager' ),
				'premium' => __( 'Premium adds Active Challenge and CAPTCHA/proof validation.', 'wc-blacklist-manager' ),
				'cta' => __( 'Unlock Active Challenge Protection', 'wc-blacklist-manager' ),
			),
			'repeat_blocked_identity' => array(
				'title' => __( 'Repeated abuse signals from one identity observed', 'wc-blacklist-manager' ),
				'intro' => __( 'Accepted abuse producers repeatedly matched one correlated identity.', 'wc-blacklist-manager' ),
				'premium' => __( 'Premium adds device, session, and identity risk scoring for suspect automation.', 'wc-blacklist-manager' ),
				'cta' => __( 'Unlock Risk Scoring', 'wc-blacklist-manager' ),
			),
			'checkout_velocity_spike' => array(
				'title' => __( 'Potential linked checkout fan-out observed', 'wc-blacklist-manager' ),
				'intro' => __( 'A short cluster of linked non-final orders and identity fan-out matched this alert.', 'wc-blacklist-manager' ),
				'premium' => __( 'Premium adds checkout velocity protection and automated responses.', 'wc-blacklist-manager' ),
				'cta' => __( 'Unlock Velocity Protection', 'wc-blacklist-manager' ),
			),
		);

		return isset( $copy[ $family ] ) ? $copy[ $family ] : array();
	}

	private function format_family_detail( array $episode ): string {
		$count  = (int) $episode['event_count'];
		$fanout = (int) $episode['fanout_count'];
		$dimension_labels = array(
			'device' => __( 'device', 'wc-blacklist-manager' ),
			'session' => __( 'session', 'wc-blacklist-manager' ),
			'account' => __( 'customer account', 'wc-blacklist-manager' ),
			'ip' => __( 'network address', 'wc-blacklist-manager' ),
			'phone' => __( 'phone identity', 'wc-blacklist-manager' ),
		);
		$dimension = isset( $dimension_labels[ $episode['identity_dimension'] ] ) ? $dimension_labels[ $episode['identity_dimension'] ] : __( 'identity', 'wc-blacklist-manager' );
		if ( in_array( $episode['family'], array( 'card_testing_suspected', 'checkout_velocity_spike' ), true ) ) {
			return sprintf(
				/* translators: 1: activity count, 2: billing identity count, 3: correlated identity dimension. */
				__( '%1$d correlated activities across %2$d billing identities shared one %3$s. ', 'wc-blacklist-manager' ),
				$count,
				$fanout,
				$dimension
			);
		}
		return sprintf(
			/* translators: 1: activity count, 2: correlated identity dimension. */
			__( '%1$d correlated activities shared one %2$s. ', 'wc-blacklist-manager' ),
			$count,
			$dimension
		);
	}

	private function format_recency( int $last_seen ): string {
		if ( $last_seen <= 0 ) {
			return '';
		}
		$ago = function_exists( 'human_time_diff' ) ? human_time_diff( $last_seen, time() ) : max( 0, time() - $last_seen ) . 's';
		return sprintf(
			/* translators: %s: human-readable elapsed time. */
			__( 'Observed %s ago. ', 'wc-blacklist-manager' ),
			$ago
		);
	}

	private function current_user_is_snoozed(): bool {
		$until = (int) get_user_meta( get_current_user_id(), self::UMETA_DISMISS, true );
		return $until > time();
	}

	private function is_dashboard_surface(): bool {
		return class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
			&& 'dashboard' === WC_Blacklist_Manager_Opportunity_Engine::current_surface();
	}

	private function base_notice_gates_pass(): bool {
		return ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) )
			&& current_user_can( 'manage_options' )
			&& ( ! function_exists( 'wp_get_environment_type' ) || 'production' === wp_get_environment_type() )
			&& ! $this->premium_is_activated();
	}

	protected function get_bot_signal_cache_key(): string {
		return self::TRANSIENT_BOT_SIGNAL_SUMMARY;
	}

	private function premium_is_activated(): bool {
		return function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available();
	}

}

add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
		new WC_Blacklist_Manager_Alert();
	}
} );
