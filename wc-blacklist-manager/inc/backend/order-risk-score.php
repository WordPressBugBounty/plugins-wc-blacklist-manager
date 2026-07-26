<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

class WC_Blacklist_Manager_Order_Risk_Score {

	public function __construct() {
		if ( '1' !== get_option( 'wc_blacklist_enable_global_blacklist', '0' ) ) {
			return;
		}
		add_action( 'add_meta_boxes', [ $this, 'add_order_risk_score_meta_box' ], 1 );
	}

	public function add_order_risk_score_meta_box() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		if ( $settings_instance->is_premium_active() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$current = get_current_screen();
			if ( $current ) {
				if ( 'shop_order' === ( $current->post_type ?? '' ) && 'add' === ( $current->action ?? '' ) ) {
					return;
				}
				if ( 'woocommerce_page_wc-orders' === $current->id && isset( $_GET['action'] ) && 'new' === $_GET['action'] ) {
					return;
				}
			}
		}

		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';

		add_meta_box(
			'wc_blacklist_manager_order_risk_score',
			__( 'Global blacklist check', 'wc-blacklist-manager' ),
			[ $this, 'display_order_risk_score_meta_box' ],
			$screen,
			'side',
			'high'
		);

		global $wp_meta_boxes;
		if ( isset( $wp_meta_boxes[ $screen ]['side']['high'] ) ) {
			$high = $wp_meta_boxes[ $screen ]['side']['high'];
			if ( isset( $high['wc_blacklist_manager_order_risk_score'], $high['woocommerce-order-actions'] ) ) {
				$our_box = $high['wc_blacklist_manager_order_risk_score'];
				unset( $high['wc_blacklist_manager_order_risk_score'] );
				$sorted = [];
				foreach ( $high as $id => $box ) {
					if ( 'woocommerce-order-actions' === $id ) {
						$sorted['wc_blacklist_manager_order_risk_score'] = $our_box;
					}
					$sorted[ $id ] = $box;
				}
				$wp_meta_boxes[ $screen ]['side']['high'] = $sorted;
			}
		}
	}

	public function display_order_risk_score_meta_box( $object ) {
		$order = is_a( $object, 'WP_Post' ) ? wc_get_order( $object->ID ) : $object;
		self::render_global_blacklist_panel( $order );
	}

	/**
	 * Shared compact renderer used by both Core and Premium metabox containers.
	 *
	 * @param WC_Order|false $order WooCommerce order.
	 */
	public static function render_global_blacklist_panel( $order ) {
		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'wc-blacklist-manager' ) . '</p>';
			return;
		}

		if ( ! self::is_connected() ) {
			self::render_disconnected();
			return;
		}

		$order_id = (int) $order->get_id();
		$decision = sanitize_key( (string) $order->get_meta( '_yogb_gbl_decision', true ) );
		if ( '' === $decision ) {
			self::render_pending( $order );
			return;
		}

		$view            = self::decision_view( $decision );
		$summary         = self::summary_for_order( $order, $decision );
		$tier            = sanitize_key( (string) $order->get_meta( '_yogb_gbl_tier', true ) );
		$tier            = in_array( $tier, [ 'free', 'basic', 'pro', 'enterprise' ], true ) ? $tier : 'free';
		$decision_at     = max( 0, (int) $order->get_meta( '_yogb_gbl_decision_at', true ) );
		$checked_at      = self::site_datetime( $decision_at );
		$decision_ref    = (string) $order->get_meta( '_yogb_gbl_decision_ref', true );
		$has_ref         = (bool) preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $decision_ref );
		$detail_url      = class_exists( 'YOGB_BM_Decision_Actions' )
			? YOGB_BM_Decision_Actions::detail_url( $order )
			: '';
		$notice          = isset( $_GET['yogb_gbl_notice'] ) ? sanitize_key( wp_unslash( $_GET['yogb_gbl_notice'] ) ) : '';
		$can_recheck     = in_array( $decision, [ 'skipped_rate_limit', 'check_failed' ], true )
			&& $order->has_status( [ 'pending', 'processing', 'on-hold', 'failed' ] );
		$recheck_url     = $can_recheck ? self::recheck_url( $order_id ) : '';
		$show_outcomes   = $has_ref
			&& class_exists( 'YOGB_BM_Outcomes' )
			&& YOGB_BM_Outcomes::supports()
			&& class_exists( 'YOGB_BM_Decision_Actions' )
			&& YOGB_BM_Decision_Actions::can_manage_order( $order );
		?>
		<div class="bm-order-risk-meta bm-gbl-compact-panel">
			<?php self::render_notice( $notice ); ?>

			<div class="bm-gbl-result bm-gbl-result--<?php echo esc_attr( $view['slug'] ); ?>">
				<div class="bm-gbl-result__header">
					<span class="bm-gbl-result__icon" aria-hidden="true"></span>
					<div>
						<span class="bm-gbl-result__eyebrow"><?php esc_html_e( 'Global Blacklist result', 'wc-blacklist-manager' ); ?></span>
						<strong class="bm-gbl-result__title"><?php echo esc_html( $view['label'] ); ?></strong>
					</div>
				</div>
				<p class="bm-gbl-result__summary"><?php echo esc_html( $summary ); ?></p>
				<p class="bm-gbl-result__action">
					<strong><?php esc_html_e( 'Recommended:', 'wc-blacklist-manager' ); ?></strong>
					<?php echo esc_html( $view['action'] ); ?>
				</p>
				<?php if ( '' !== $recheck_url || '' !== $detail_url ) : ?>
					<div class="bm-gbl-result__footer">
						<?php if ( '' !== $recheck_url ) : ?>
							<a class="button button-secondary bm-gbl-result__button bm-gbl-result__recheck" href="<?php echo esc_url( $recheck_url ); ?>">
								<span class="dashicons dashicons-update" aria-hidden="true"></span>
								<span class="bm-gbl-result__button-label"><?php esc_html_e( 'Recheck now', 'wc-blacklist-manager' ); ?></span>
							</a>
						<?php endif; ?>
						<?php if ( '' !== $detail_url ) : ?>
							<a class="button button-secondary bm-gbl-result__button bm-gbl-result__analysis" href="<?php echo esc_url( $detail_url ); ?>" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
								<span class="bm-gbl-result__button-label"><?php esc_html_e( 'View full analysis', 'wc-blacklist-manager' ); ?></span>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="bm-gbl-compact-meta">
				<span class="bm-gbl-compact-meta__item bm-gbl-compact-meta__plan">
					<span class="yogb-tier-badge yogb-tier-<?php echo esc_attr( $tier ); ?>">
						<span class="yogb-tier-dot" aria-hidden="true"></span>
						<span class="yogb-tier-text"><?php echo esc_html( self::tier_label( $tier ) ); ?></span>
					</span>
				</span>
				<?php if ( '' !== $checked_at['display'] ) : ?>
					<span class="bm-gbl-compact-meta__item bm-gbl-compact-meta__checked">
						<span class="bm-gbl-compact-meta__label"><?php esc_html_e( 'Checked:', 'wc-blacklist-manager' ); ?></span>
						<time datetime="<?php echo esc_attr( $checked_at['datetime'] ); ?>"><?php echo esc_html( $checked_at['display'] ); ?></time>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( '' === $detail_url && $has_ref ) : ?>
				<p class="bm-gbl-compact-hint"><?php esc_html_e( 'Full analysis will be available after the server capability refresh.', 'wc-blacklist-manager' ); ?></p>
			<?php endif; ?>

			<?php if ( $show_outcomes ) : ?>
				<?php self::render_feedback_control( $order ); ?>
			<?php elseif ( ! $has_ref && in_array( $decision, [ 'allow', 'challenge', 'block' ], true ) ) : ?>
				<p class="bm-gbl-compact-hint">
					<?php esc_html_e( 'This result was recorded by an older client and has no decision reference, so server details and result feedback are unavailable for this order.', 'wc-blacklist-manager' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function is_connected() : bool {
		$key      = trim( (string) get_option( 'yogb_bm_api_key', '' ) );
		$secret   = trim( (string) get_option( 'yogb_bm_api_secret', '' ) );
		$reporter = trim( (string) get_option( 'yogb_bm_reporter_id', '' ) );
		return '' !== $key && '' !== $secret && '' !== $reporter;
	}

	private static function render_disconnected() : void {
		$url = admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' );
		?>
		<div class="bm-order-risk-meta bm-gbl-empty-state">
			<strong><?php esc_html_e( 'Connection required', 'wc-blacklist-manager' ); ?></strong>
			<p><?php esc_html_e( 'Connect this site before Global Blacklist can check orders.', 'wc-blacklist-manager' ); ?></p>
			<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open settings', 'wc-blacklist-manager' ); ?></a>
		</div>
		<?php
	}

	private static function render_pending( WC_Order $order ) : void {
		$created          = $order->get_date_created();
		$can_show_recheck = ! $created || time() - $created->getTimestamp() >= 2 * MINUTE_IN_SECONDS;
		?>
		<div class="bm-order-risk-meta bm-gbl-compact-panel">
			<div class="bm-gbl-result bm-gbl-result--pending">
				<div class="bm-gbl-result__header">
					<span class="bm-gbl-result__icon" aria-hidden="true"></span>
					<div>
						<span class="bm-gbl-result__eyebrow"><?php esc_html_e( 'Global Blacklist result', 'wc-blacklist-manager' ); ?></span>
						<strong class="bm-gbl-result__title"><?php esc_html_e( 'Checking order', 'wc-blacklist-manager' ); ?></strong>
					</div>
				</div>
				<p class="bm-gbl-result__summary"><?php esc_html_e( 'The background check has not completed yet.', 'wc-blacklist-manager' ); ?></p>
				<?php if ( $can_show_recheck ) : ?>
					<div class="bm-gbl-result__footer">
						<a class="button button-secondary bm-gbl-result__button bm-gbl-result__recheck" href="<?php echo esc_url( self::recheck_url( (int) $order->get_id() ) ); ?>">
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
							<span class="bm-gbl-result__button-label"><?php esc_html_e( 'Recheck now', 'wc-blacklist-manager' ); ?></span>
						</a>
					</div>
				<?php else : ?>
					<p class="bm-gbl-result__hint"><?php esc_html_e( 'This usually completes within a moment.', 'wc-blacklist-manager' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function decision_view( string $decision ) : array {
		switch ( $decision ) {
			case 'block':
				return [
					'slug'   => 'block',
					'label'  => __( 'High risk', 'wc-blacklist-manager' ),
					'action' => __( 'Do not fulfill until the order is manually verified.', 'wc-blacklist-manager' ),
				];
			case 'challenge':
				return [
					'slug'   => 'challenge',
					'label'  => __( 'Needs review', 'wc-blacklist-manager' ),
					'action' => __( 'Review the customer and payment details before fulfillment.', 'wc-blacklist-manager' ),
				];
			case 'allow':
				return [
					'slug'   => 'allow',
					'label'  => __( 'Clear', 'wc-blacklist-manager' ),
					'action' => __( 'Proceed with the normal order workflow.', 'wc-blacklist-manager' ),
				];
			case 'skipped_rate_limit':
				return [
					'slug'   => 'pending',
					'label'  => __( 'Check skipped', 'wc-blacklist-manager' ),
					'action' => __( 'Recheck after quota becomes available or update the plan.', 'wc-blacklist-manager' ),
				];
			case 'check_failed':
				return [
					'slug'   => 'error',
					'label'  => __( 'Check unavailable', 'wc-blacklist-manager' ),
					'action' => __( 'Keep the order under review and retry the check.', 'wc-blacklist-manager' ),
				];
			default:
				return [
					'slug'   => 'pending',
					'label'  => __( 'No result', 'wc-blacklist-manager' ),
					'action' => __( 'Run the check again if this order still requires review.', 'wc-blacklist-manager' ),
				];
		}
	}

	private static function summary_for_order( WC_Order $order, string $decision ) : string {
		$summary = sanitize_text_field( (string) $order->get_meta( '_yogb_gbl_decision_summary', true ) );
		if ( '' === $summary ) {
			// Smooth upgrade path: use one existing human-readable line without
			// continuing to render the old verbose diagnostic panel.
			foreach ( [ '_yogb_gbl_reason_summaries', '_yogb_gbl_signal_summaries' ] as $meta_key ) {
				$legacy = $order->get_meta( $meta_key, true );
				$line   = is_array( $legacy ) ? reset( $legacy ) : $legacy;
				if ( is_scalar( $line ) && '' !== trim( (string) $line ) ) {
					$summary = sanitize_text_field( (string) $line );
					break;
				}
			}
		}
		if ( '' !== $summary ) {
			return $summary;
		}

		$fallback = [
			'block'              => __( 'Strong risk signals were found in the Global Blacklist network.', 'wc-blacklist-manager' ),
			'challenge'          => __( 'Some order details matched previous risk reports.', 'wc-blacklist-manager' ),
			'allow'              => __( 'No blocking decision was returned for this order.', 'wc-blacklist-manager' ),
			'skipped_rate_limit' => __( 'The monthly Global Blacklist check limit was reached.', 'wc-blacklist-manager' ),
			'check_failed'       => __( 'The server could not complete this order check.', 'wc-blacklist-manager' ),
		];
		return $fallback[ $decision ] ?? __( 'No additional result summary is available.', 'wc-blacklist-manager' );
	}

	private static function render_feedback_control( WC_Order $order ) : void {
		$current_type = (string) $order->get_meta( YOGB_BM_Decision_Actions::META_OUTCOME_TYPE, true );
		$current_at   = (string) $order->get_meta( YOGB_BM_Decision_Actions::META_OUTCOME_AT, true );
		$updated_at   = self::site_datetime( $current_at );
		$all_labels   = YOGB_BM_Decision_Actions::outcome_labels();
		$labels       = YOGB_BM_Decision_Actions::feedback_labels();
		$form_id      = 'yogb-gbl-outcome-form-' . (int) $order->get_id();
		self::register_outcome_form( $order, $form_id );
		?>
		<details class="bm-gbl-feedback">
			<summary>
				<span class="bm-gbl-feedback__summary-label">
					<span class="dashicons dashicons-flag" aria-hidden="true"></span>
					<?php esc_html_e( 'Report incorrect result', 'wc-blacklist-manager' ); ?>
				</span>
				<?php if ( isset( $all_labels[ $current_type ] ) ) : ?>
					<span class="bm-gbl-feedback__saved"><?php esc_html_e( 'Reported', 'wc-blacklist-manager' ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="bm-gbl-feedback__body">
				<p><?php esc_html_e( 'Routine order events are monitored automatically. Use this only when you know the result was wrong or can confirm what happened.', 'wc-blacklist-manager' ); ?></p>
				<p class="bm-gbl-feedback__safety">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<?php esc_html_e( 'Sending feedback only improves Global Blacklist. It does not change the order, customer, payment, or automation settings.', 'wc-blacklist-manager' ); ?>
				</p>
				<fieldset class="bm-gbl-feedback__choices">
					<legend class="screen-reader-text"><?php esc_html_e( 'Result feedback', 'wc-blacklist-manager' ); ?></legend>
					<?php foreach ( $labels as $value => $label ) : ?>
						<label>
							<input form="<?php echo esc_attr( $form_id ); ?>" type="radio" name="outcome_type" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_type, $value ); ?> required>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<div class="bm-gbl-feedback__actions">
					<button form="<?php echo esc_attr( $form_id ); ?>" type="submit" class="button button-secondary"><?php esc_html_e( 'Send feedback', 'wc-blacklist-manager' ); ?></button>
				</div>
				<?php if ( isset( $all_labels[ $current_type ] ) ) : ?>
					<small class="bm-gbl-feedback__status">
						<?php
						printf(
							/* translators: %s: previously reported result feedback. */
							esc_html__( 'Current feedback: %s.', 'wc-blacklist-manager' ),
							esc_html( $all_labels[ $current_type ] )
						);
						?>
					</small>
				<?php endif; ?>
				<?php if ( '' !== $updated_at['display'] ) : ?>
					<small>
						<?php esc_html_e( 'Last updated:', 'wc-blacklist-manager' ); ?>
						<time datetime="<?php echo esc_attr( $updated_at['datetime'] ); ?>"><?php echo esc_html( $updated_at['display'] ); ?></time>
					</small>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private static function register_outcome_form( WC_Order $order, string $form_id ) : void {
		$order_id = (int) $order->get_id();
		add_action(
			'admin_footer',
			static function() use ( $order_id, $form_id ) {
				?>
				<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
					<input type="hidden" name="action" value="yogb_gbl_record_outcome">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">
					<?php wp_nonce_field( 'yogb_gbl_record_outcome_' . $order_id ); ?>
				</form>
				<?php
			},
			20
		);
	}

	private static function render_notice( string $notice ) : void {
		$messages = [
			'feedback_saved'        => [ 'success', __( 'Result feedback saved and queued securely. No order or customer settings were changed.', 'wc-blacklist-manager' ) ],
			'feedback_queue_failed' => [ 'error', __( 'The feedback was saved locally but could not be queued. Send it again to retry.', 'wc-blacklist-manager' ) ],
			'feedback_unsupported'  => [ 'error', __( 'The connected server does not support this feedback option yet.', 'wc-blacklist-manager' ) ],
			'feedback_invalid'      => [ 'error', __( 'Select a valid feedback option.', 'wc-blacklist-manager' ) ],
			'feedback_missing'      => [ 'error', __( 'This older result has no decision reference.', 'wc-blacklist-manager' ) ],
			'outcome_saved'         => [ 'success', __( 'Result feedback saved and queued securely. No order or customer settings were changed.', 'wc-blacklist-manager' ) ],
			'outcome_queue_failed'  => [ 'error', __( 'The feedback was saved locally but could not be queued. Send it again to retry.', 'wc-blacklist-manager' ) ],
			'outcome_unsupported'   => [ 'error', __( 'The connected server does not support result feedback yet.', 'wc-blacklist-manager' ) ],
			'outcome_invalid'       => [ 'error', __( 'Select a valid feedback option.', 'wc-blacklist-manager' ) ],
			'outcome_missing'       => [ 'error', __( 'This older result has no decision reference.', 'wc-blacklist-manager' ) ],
			'detail_unsupported'   => [ 'error', __( 'The connected server does not support the detailed decision view yet.', 'wc-blacklist-manager' ) ],
			'detail_missing'       => [ 'error', __( 'This older result has no server decision reference.', 'wc-blacklist-manager' ) ],
			'detail_error'         => [ 'error', __( 'The secure analysis link could not be created. Please try again.', 'wc-blacklist-manager' ) ],
		];
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		?>
		<div class="bm-gbl-inline-notice bm-gbl-inline-notice--<?php echo esc_attr( $messages[ $notice ][0] ); ?>">
			<?php echo esc_html( $messages[ $notice ][1] ); ?>
		</div>
		<?php
	}

	private static function recheck_url( int $order_id ) : string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'yogb_gbl_manual_order_check',
					'order_id' => $order_id,
				],
				admin_url( 'admin-post.php' )
			),
			'yogb_gbl_manual_order_check_' . $order_id
		);
	}

	private static function tier_label( string $tier ) : string {
		$labels = [
			'free'       => __( 'Free', 'wc-blacklist-manager' ),
			'basic'      => __( 'Basic', 'wc-blacklist-manager' ),
			'pro'        => __( 'Pro', 'wc-blacklist-manager' ),
			'enterprise' => __( 'Enterprise', 'wc-blacklist-manager' ),
		];
		return $labels[ $tier ] ?? $labels['free'];
	}

	/**
	 * Format a stored UTC timestamp using this WordPress site's date, time,
	 * locale, and configured timezone.
	 *
	 * @param int|string $value Unix timestamp or ISO-8601 date.
	 * @return array{display:string,datetime:string}
	 */
	private static function site_datetime( $value ) : array {
		$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		if ( $timestamp <= 0 ) {
			return [ 'display' => '', 'datetime' => '' ];
		}

		$date_format = trim( (string) get_option( 'date_format', 'F j, Y' ) );
		$time_format = trim( (string) get_option( 'time_format', 'g:i a' ) );
		$format      = trim( $date_format . ' ' . $time_format );
		if ( '' === $format ) {
			$format = 'F j, Y g:i a';
		}

		return [
			'display'  => wp_date( $format, $timestamp, wp_timezone() ),
			'datetime' => gmdate( 'c', $timestamp ),
		];
	}
}

if ( class_exists( 'WC_Blacklist_Manager_Order_Risk_Score' ) ) {
	new WC_Blacklist_Manager_Order_Risk_Score();
}
