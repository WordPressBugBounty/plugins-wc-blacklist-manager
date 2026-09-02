<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Activates a purchased subscription key for the reporter authenticated by this site. */
final class YOGB_BM_Subscription_Activation {
	const ACTION = 'yogb_bm_activate_subscription';

	public static function init() : void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'yogb_bm_global_blacklist_settings_rows', [ __CLASS__, 'render_settings_row' ], 10, 1 );
		add_action( 'yogb_bm_global_blacklist_diagnostics_rows', [ __CLASS__, 'render_diagnostics_row' ], 10, 1 );
	}

	public static function handle() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wc-blacklist-manager' ) );
		}
		check_admin_referer( self::ACTION );
		$redirect = admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' );

		$key = isset( $_POST['yogb_activation_key'] )
			? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['yogb_activation_key'] ) ) ) )
			: '';
		if ( ! preg_match( '/^YOGB-SUB-[A-F0-9]{8}(?:-[A-F0-9]{8}){3}$/', $key ) ) {
			self::store( [ 'error' => __( 'Enter a valid Global Blacklist subscription activation key.', 'wc-blacklist-manager' ) ] );
			wp_safe_redirect( $redirect ); exit;
		}
		if ( ! class_exists( 'YOGB_BM_Report' ) || ! YOGB_BM_Report::is_ready() ) {
			self::store( [ 'error' => __( 'Connect this site to Global Blacklist before activating a plan.', 'wc-blacklist-manager' ) ] );
			wp_safe_redirect( $redirect ); exit;
		}

		$result = YOGB_BM_Report::post_json_signed(
			YOGB_BM_Report::REST_ROUTE . '/client/subscriptions/activate',
			[ 'activation_key' => $key ]
		);
		$body = json_decode( (string) ( $result['body'] ?? '' ), true );
		if ( empty( $result['ok'] ) || ! is_array( $body ) || empty( $body['ok'] ) ) {
			self::store( [ 'error' => self::error_message( (string) ( $result['error_code'] ?? '' ) ) ] );
			wp_safe_redirect( $redirect ); exit;
		}
		update_option( 'yogb_bm_subscription_key_last4', substr( str_replace( '-', '', $key ), -4 ), false );
		update_option( 'yogb_bm_subscription_id', sanitize_text_field( (string) ( $body['subscription_id'] ?? '' ) ), false );
		update_option( 'yogb_bm_subscription_activation_status', 'active', false );
		if ( ! get_option( 'yogb_bm_subscription_activated_at' ) ) update_option( 'yogb_bm_subscription_activated_at', time(), false );

		// The activation response confirms the command, but tier and plan state are
		// only trusted after the normal signed snapshot pull verifies its HMAC.
		if ( class_exists( 'YOGB_BM_Tier_Sync' ) ) {
			YOGB_BM_Tier_Sync::run();
		}
		$plan = class_exists( 'YOGB_BM_Tier_Webhook' ) ? YOGB_BM_Tier_Webhook::plan_summary() : [];
		$plan_type = sanitize_key( (string) ( $plan['type'] ?? '' ) );
		$plan_active = 'active' === (string) ( $plan['status'] ?? '' )
			&& in_array( $plan_type, [ 'subscription', 'mixed' ], true );
		if ( ! $plan_active ) {
			self::store( [ 'error' => __( 'The plan was activated, but the local tier could not be refreshed. It will retry automatically.', 'wc-blacklist-manager' ) ] );
		} else {
			self::store( [ 'success' => sprintf( __( 'Global Blacklist plan activated. Current tier: %s.', 'wc-blacklist-manager' ), sanitize_key( (string) get_option( 'yogb_bm_tier', 'free' ) ) ) ] );
		}
		wp_safe_redirect( $redirect ); exit;
	}

	/** Canonical connection, plan and activation UI shared by Core and Premium. */
	public static function render_settings_row( bool $connected ) : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tier = sanitize_key( (string) get_option( 'yogb_bm_tier', 'free' ) );

		$last_success        = (int) get_option( 'yogb_bm_last_server_success_at', 0 );
		$last_error_at       = (int) get_option( 'yogb_bm_last_server_error_at', 0 );
		$last_error          = sanitize_key( (string) get_option( 'yogb_bm_last_server_error_code', '' ) );
		$capabilities_option = get_option( 'yogb_bm_server_capabilities', false );
		$capabilities_known  = false !== $capabilities_option;
		$capabilities        = array_values( array_filter( array_map( 'sanitize_key', (array) $capabilities_option ) ) );
		$supports_key        = in_array( 'subscription_activation_v1', $capabilities, true );
		$plan                = class_exists( 'YOGB_BM_Tier_Webhook' ) ? YOGB_BM_Tier_Webhook::plan_summary() : [];
		$plan_status         = sanitize_key( (string) ( $plan['status'] ?? '' ) );
		$plan_type           = sanitize_key( (string) ( $plan['type'] ?? '' ) );
		$last4               = sanitize_text_field( (string) get_option( 'yogb_bm_subscription_key_last4', '' ) );
		$subscription        = sanitize_text_field( (string) get_option( 'yogb_bm_subscription_id', '' ) );
		$subscriptions       = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $plan['subscription_ids'] ?? [] ) ) ) );
		$notice_key          = 'yogb_activation_notice_' . get_current_user_id();
		$notice              = get_transient( $notice_key );

		if ( false !== $notice ) {
			delete_transient( $notice_key );
		}
		if ( $subscription && ! in_array( $subscription, $subscriptions, true ) ) {
			array_unshift( $subscriptions, $subscription );
		}
		$commercial_context = WC_Blacklist_Manager_Commercial_Router::global_context( $connected, $tier, $plan_status, $plan_type );
		$commercial_action  = WC_Blacklist_Manager_Commercial_Router::global_card_action( $commercial_context, $supports_key );

		echo '<tr class="yogb-gbl-overview-row"><th scope="row">' . esc_html__( 'Plan & connection', 'wc-blacklist-manager' ) . '</th><td>';
		if ( ! $connected ) {
			$retry = wp_nonce_url( admin_url( 'admin-post.php?action=yogb_bm_retry_registration' ), 'yogb_bm_retry_registration', 'yogb_bm_retry_nonce' );
			echo '<section class="yogb-gbl-card yogb-gbl-card--warning">';
			echo '<div class="yogb-gbl-card__body"><div class="yogb-gbl-state"><span class="yogb-gbl-state__icon" aria-hidden="true">!</span><div><h3>' . esc_html__( 'Connection needs attention', 'wc-blacklist-manager' ) . '</h3><p>' . esc_html__( 'Secure site registration must complete before order checking or plan activation can be used.', 'wc-blacklist-manager' ) . '</p></div></div>';
			echo '<div class="yogb-gbl-actions"><a class="button button-secondary" href="' . esc_url( $retry ) . '">' . esc_html__( 'Retry connection', 'wc-blacklist-manager' ) . '</a></div></div></section></td></tr>';
			return;
		}

		$healthy       = 0 === $last_error_at || $last_success >= $last_error_at;
		$labels        = [ 'free' => 'Free', 'basic' => 'Basic', 'pro' => 'Pro', 'enterprise' => 'Enterprise', 'unknown' => __( 'Unknown', 'wc-blacklist-manager' ) ];
		$type_labels   = [
			'subscription' => __( 'Subscription key', 'wc-blacklist-manager' ),
			'legacy'       => __( 'Legacy Site ID', 'wc-blacklist-manager' ),
			'mixed'        => __( 'Subscription key + legacy', 'wc-blacklist-manager' ),
			'none'         => __( 'No paid plan', 'wc-blacklist-manager' ),
		];
		$status_label  = WC_Blacklist_Manager_Commercial_Router::GLOBAL_PAID === $commercial_context['state']
			? __( 'Active', 'wc-blacklist-manager' )
			: ( WC_Blacklist_Manager_Commercial_Router::GLOBAL_INACTIVE === $commercial_context['state']
				? __( 'Inactive', 'wc-blacklist-manager' )
				: ( WC_Blacklist_Manager_Commercial_Router::GLOBAL_FREE === $commercial_context['state'] ? __( 'Free', 'wc-blacklist-manager' ) : __( 'Review required', 'wc-blacklist-manager' ) ) );
		$known_tier    = in_array( $tier, [ 'free', 'basic', 'pro', 'enterprise' ], true );
		$display_tier  = $known_tier ? $tier : 'unknown';
		$limit         = $known_tier && class_exists( 'YOGB_BM_Check' ) ? YOGB_BM_Check::get_monthly_limit_for_tier( $tier ) : 0;
		$used          = $known_tier && class_exists( 'YOGB_BM_Check' ) ? YOGB_BM_Check::get_monthly_usage( $tier ) : 0;
		$usage_percent = $known_tier && $limit <= 0 ? 100 : ( $limit > 0 ? min( 100, round( ( $used / $limit ) * 100, 2 ) ) : 0 );
		$usage_state   = ! $known_tier ? 'unavailable' : ( $limit <= 0 ? 'unlimited' : ( $used >= $limit ? 'error' : ( $usage_percent >= 80 ? 'warning' : 'normal' ) ) );
		echo '<section class="yogb-gbl-card">';
		echo '<header class="yogb-gbl-card__header"><div class="yogb-gbl-plan-heading"><span class="yogb-tier-badge yogb-tier-' . esc_attr( $display_tier ) . '"><span class="yogb-tier-dot"></span><span class="yogb-tier-text">' . esc_html( $labels[ $display_tier ] ) . '</span></span><span class="yogb-gbl-plan-status">' . esc_html( $status_label ) . '</span></div>';
		echo '<div class="yogb-gbl-connection"><span class="yogb-gbl-connection__status"><span class="yogb-gbl-connection__dot yogb-gbl-connection__dot--' . esc_attr( $healthy ? 'connected' : 'warning' ) . '" aria-hidden="true"></span>' . esc_html( $healthy ? __( 'Connected', 'wc-blacklist-manager' ) : __( 'Needs attention', 'wc-blacklist-manager' ) ) . '</span>';
		if ( $last_success > 0 ) {
			echo '<span class="yogb-gbl-connection__time">' . esc_html( sprintf( __( 'Synced %s ago', 'wc-blacklist-manager' ), human_time_diff( $last_success, time() ) ) ) . '</span>';
		}
		echo '</div></header><div class="yogb-gbl-card__body">';

		if ( is_array( $notice ) && ! empty( $notice['error'] ) ) {
			echo '<div class="notice inline notice-error"><p>' . esc_html( $notice['error'] ) . '</p></div>';
		}
		if ( is_array( $notice ) && ! empty( $notice['success'] ) ) {
			echo '<div class="notice inline notice-success"><p>' . esc_html( $notice['success'] ) . '</p></div>';
		}
		if ( ! $healthy && $last_error ) {
			echo '<div class="yogb-gbl-inline-warning"><strong>' . esc_html__( 'Automatic recovery is enabled.', 'wc-blacklist-manager' ) . '</strong> ' . esc_html( sprintf( __( 'Last error: %s', 'wc-blacklist-manager' ), $last_error ) ) . '</div>';
		}

		echo '<div class="yogb-gbl-usage"><div class="yogb-gbl-usage__row"><span>' . esc_html__( 'Monthly checks', 'wc-blacklist-manager' ) . '</span><strong>';
		if ( ! $known_tier ) {
			echo esc_html__( 'Review required', 'wc-blacklist-manager' );
			echo '</strong></div></div>';
		} else {
			echo esc_html( $limit > 0 ? sprintf( __( '%1$d of %2$d', 'wc-blacklist-manager' ), $used, $limit ) : sprintf( __( '%d used · Unlimited', 'wc-blacklist-manager' ), $used ) );
			echo '</strong></div><div class="yogb-gbl-progress yogb-gbl-progress--' . esc_attr( $usage_state ) . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $usage_percent ) . '"><span style="width:' . esc_attr( (string) $usage_percent ) . '%"></span></div></div>';
		}
		if ( class_exists( 'YOGB_BM_Global_Value_Summary' ) ) {
			YOGB_BM_Global_Value_Summary::render();
		}

		echo '<div class="yogb-gbl-meta"><span><strong>' . esc_html__( 'Activation', 'wc-blacklist-manager' ) . ':</strong> ' . esc_html( $type_labels[ $plan_type ] ?? __( 'Review required', 'wc-blacklist-manager' ) ) . ( $last4 ? ' · ' . esc_html( sprintf( __( 'key ••••%s', 'wc-blacklist-manager' ), $last4 ) ) : '' ) . '</span>';
		if ( $subscriptions ) {
			$ids = implode( ', ', array_map( static function ( string $id ) : string { return '#' . ltrim( $id, '#' ); }, $subscriptions ) );
			echo '<span><strong>' . esc_html( _n( 'Subscription', 'Subscriptions', count( $subscriptions ), 'wc-blacklist-manager' ) ) . ':</strong> ' . esc_html( $ids ) . '</span>';
		}
		echo '</div>';

		echo '<div class="yogb-gbl-actions">';
		if ( ! empty( $commercial_action ) ) {
			echo '<a class="button button-secondary" href="' . esc_url( $commercial_action['url'] ) . '"' . ( ! empty( $commercial_action['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $commercial_action['label'] ) . '</a>';
			if ( ! empty( $commercial_action['post_purchase'] ) ) {
				echo '<p class="yogb-gbl-benefit">' . esc_html( $commercial_action['post_purchase'] ) . '</p>';
			}
		} elseif ( WC_Blacklist_Manager_Commercial_Router::GLOBAL_INACTIVE === $commercial_context['state'] ) {
			echo '<p class="yogb-gbl-benefit">' . esc_html__( 'This existing Global Blacklist plan needs local review or recovery before any new purchase.', 'wc-blacklist-manager' ) . '</p>';
		} elseif ( WC_Blacklist_Manager_Commercial_Router::GLOBAL_UNKNOWN === $commercial_context['state'] ) {
			echo '<p class="yogb-gbl-benefit">' . esc_html__( 'The signed plan state is inconsistent or incomplete. Review the local status before taking a purchase action.', 'wc-blacklist-manager' ) . '</p>';
		}
		echo '</div></div>';

		if ( $supports_key ) {
			echo '<details id="yogb-subscription-activation" class="yogb-gbl-activation"><summary>' . esc_html( 'active' === $plan_status || $last4 ? __( 'Activate another subscription', 'wc-blacklist-manager' ) : __( 'Activate a subscription', 'wc-blacklist-manager' ) ) . '</summary><div class="yogb-gbl-activation__body">';
			wp_nonce_field( self::ACTION );
			echo '<div class="yogb-gbl-activation__fields"><input type="text" id="yogb_activation_key" name="yogb_activation_key" class="regular-text" placeholder="YOGB-SUB-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX" autocomplete="off"><button type="submit" name="action" value="' . esc_attr( self::ACTION ) . '" class="button button-primary" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formmethod="post">' . esc_html__( 'Activate plan', 'wc-blacklist-manager' ) . '</button></div><p class="description">' . esc_html__( 'The key is sent through this site’s signed connection and is never stored here in full.', 'wc-blacklist-manager' ) . '</p></div></details>';
		} elseif ( $capabilities_known ) {
			echo '<div class="yogb-gbl-legacy-note">' . esc_html__( 'This server does not support subscription-key activation yet. Existing Site ID plans continue to synchronize normally.', 'wc-blacklist-manager' ) . '</div>';
		} else {
			echo '<div class="yogb-gbl-legacy-note">' . esc_html__( 'Synchronizing server features… Activation options will be updated after this check finishes.', 'wc-blacklist-manager' ) . '</div>';
		}
		echo '</section></td></tr>';
	}

	/** Technical connection data rendered after the user-facing controls. */
	public static function render_diagnostics_row( bool $connected ) : void {
		if ( ! current_user_can( 'manage_options' ) || ! $connected ) {
			return;
		}

		$reporter_id  = (int) get_option( 'yogb_bm_reporter_id', 0 );
		$masked       = self::mask_key( (string) get_option( 'yogb_bm_api_key', '' ) );
		$tier_version = (int) get_option( 'yogb_bm_tier_version', 0 );
		$last_source  = sanitize_key( (string) get_option( 'yogb_bm_tier_last_source', '' ) );
		$last_success = (int) get_option( 'yogb_bm_last_server_success_at', 0 );
		$plan         = class_exists( 'YOGB_BM_Tier_Webhook' ) ? YOGB_BM_Tier_Webhook::plan_summary() : [];
		$active_keys  = max( 0, (int) ( $plan['active_subscriptions'] ?? 0 ) );
		$active_old   = max( 0, (int) ( $plan['active_legacy'] ?? 0 ) );

		echo '<tr class="yogb-gbl-diagnostics-row"><th scope="row">' . esc_html__( 'Diagnostics', 'wc-blacklist-manager' ) . '</th><td><details class="yogb-gbl-diagnostics"><summary>' . esc_html__( 'View technical connection details', 'wc-blacklist-manager' ) . '</summary><div class="yogb-gbl-diagnostics__grid">';
		self::render_diagnostic( __( 'Site Global ID', 'wc-blacklist-manager' ), (string) $reporter_id, __( 'Support ID; legacy purchases may still reference it.', 'wc-blacklist-manager' ), true );
		self::render_diagnostic( __( 'Connection key', 'wc-blacklist-manager' ), $masked, '', true );
		self::render_diagnostic( __( 'Connection secret', 'wc-blacklist-manager' ), __( 'Protected and never displayed', 'wc-blacklist-manager' ) );
		self::render_diagnostic( __( 'Tier snapshot', 'wc-blacklist-manager' ), $last_source ? sprintf( __( 'Version %1$d · %2$s', 'wc-blacklist-manager' ), $tier_version, $last_source ) : sprintf( __( 'Version %d', 'wc-blacklist-manager' ), $tier_version ) );
		self::render_diagnostic( __( 'Entitlements', 'wc-blacklist-manager' ), sprintf( __( '%1$d subscription · %2$d legacy', 'wc-blacklist-manager' ), $active_keys, $active_old ) );
		self::render_diagnostic( __( 'Last successful contact', 'wc-blacklist-manager' ), $last_success > 0 ? sprintf( __( '%s ago', 'wc-blacklist-manager' ), human_time_diff( $last_success, time() ) ) : __( 'Not recorded', 'wc-blacklist-manager' ) );
		echo '</div></details></td></tr>';
	}

	private static function render_diagnostic( string $label, string $value, string $description = '', bool $code = false ) : void {
		echo '<div class="yogb-gbl-diagnostic"><span class="yogb-gbl-diagnostic__label">' . esc_html( $label ) . '</span>';
		echo $code ? '<code class="yogb-gbl-diagnostic__value">' . esc_html( $value ) . '</code>' : '<span class="yogb-gbl-diagnostic__value">' . esc_html( $value ) . '</span>';
		if ( $description ) {
			echo '<span class="yogb-gbl-diagnostic__description">' . esc_html( $description ) . '</span>';
		}
		echo '</div>';
	}

	private static function mask_key( string $key ) : string {
		$key=trim($key);$length=strlen($key);if($length<=8)return str_repeat('•',max(4,$length));return substr($key,0,min(8,$length-4)).'••••'.substr($key,-4);
	}

	private static function store( array $data ) : void {
		set_transient( 'yogb_activation_notice_' . get_current_user_id(), $data, 10 * MINUTE_IN_SECONDS );
	}

	private static function error_message( string $code ): string {
		$messages = [
			'activation_key_not_found'      => __( 'The activation key was not found.', 'wc-blacklist-manager' ),
			'activation_key_already_bound'  => __( 'This activation key is already assigned to another site.', 'wc-blacklist-manager' ),
			'activation_key_revoked'        => __( 'This activation key has been revoked.', 'wc-blacklist-manager' ),
			'subscription_not_entitled'     => __( 'The subscription is not currently eligible for activation.', 'wc-blacklist-manager' ),
			'invalid_activation_key'        => __( 'The activation key format is invalid.', 'wc-blacklist-manager' ),
		];
		return $messages[ $code ] ?? __( 'The plan could not be activated. Please try again or contact support.', 'wc-blacklist-manager' );
	}
}

YOGB_BM_Subscription_Activation::init();
