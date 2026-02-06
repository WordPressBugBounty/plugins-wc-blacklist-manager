<?php

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

class WC_Blacklist_Manager_Order_Risk_Score {

	public function __construct() {
		// Hook into the add_meta_boxes action for legacy storage and HPOS
		add_action('add_meta_boxes', array($this, 'add_order_risk_score_meta_box'), 1);
	}

	public function add_order_risk_score_meta_box() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ($premium_active) {
			return;
		}

		if (!current_user_can('manage_options')) {
			return;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				// Classic CPT new order screen
				if ( 'shop_order' === ( $screen->post_type ?? '' ) && 'add' === ( $screen->action ?? '' ) ) {
					return;
				}
				// HPOS new order screen (Orders page)
				if ( 'woocommerce_page_wc-orders' === $screen->id && isset( $_GET['action'] ) && 'new' === $_GET['action'] ) {
					return;
				}
			}
		}

		$screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id('shop-order')
			: 'shop_order';

		add_meta_box(
			'wc_blacklist_manager_order_risk_score',
			__('Order risk score', 'wc-blacklist-manager'),
			array($this, 'display_order_risk_score_meta_box'),
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

				$new_high = [];
				foreach ( $high as $id => $box ) {
					if ( 'woocommerce-order-actions' === $id ) {
						$new_high['wc_blacklist_manager_order_risk_score'] = $our_box;
					}
					$new_high[ $id ] = $box;
				}

				$wp_meta_boxes[ $screen ]['side']['high'] = $new_high;
			}
		}
	}

	public function display_order_risk_score_meta_box( $object ) {
		// Get the WC_Order object
		$order = is_a( $object, 'WP_Post' ) ? wc_get_order( $object->ID ) : $object;

		if ( ! $order ) {
			?><p><?php esc_html_e( 'Order not found.', 'wc-blacklist-manager' ); ?></p><?php
			return;
		}

		// Is Global Blacklist enabled?
		$is_global_enabled = ( '1' === get_option( 'wc_blacklist_enable_global_blacklist', '0' ) );

		// Build enable URL (admin-post handler with nonce) if needed.
		$enable_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=enable_global_blacklist' ),
			'enable_global_blacklist'
		);

		// Branch 1: not enabled yet => notice + [Enable] link.
		if ( ! $is_global_enabled ) : ?>
			<div class="bm-order-risk-meta bm-order-risk-meta--disabled">
				<p>
					<strong><?php esc_html_e( 'Global Blacklist is currently disabled.', 'wc-blacklist-manager' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'Enable the Global Blacklist to start checking orders against your site’s global reputation data.', 'wc-blacklist-manager' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $enable_url ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Enable Global Blacklist', 'wc-blacklist-manager' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		endif;

		// -----------------------------------------------------------------
		// Branch 1b: Global Blacklist is enabled, but site is not connected
		// (missing API key / secret / reporter ID).
		// -----------------------------------------------------------------

		// Use constants if class exists, otherwise fall back to raw option names.
		$opt_api_key     = class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::OPT_API_KEY     : 'yogb_bm_api_key';
		$opt_api_secret  = class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::OPT_API_SECRET  : 'yogb_bm_api_secret';
		$opt_reporter_id = class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::OPT_REPORTER_ID : 'yogb_bm_reporter_id';

		$api_key     = trim( (string) get_option( $opt_api_key, '' ) );
		$api_secret  = trim( (string) get_option( $opt_api_secret, '' ) );
		$reporter_id = trim( (string) get_option( $opt_reporter_id, '' ) );

		$missing_connection = ( '' === $api_key || '' === $api_secret || '' === $reporter_id );

		if ( $missing_connection ) : ?>
			<div class="bm-order-risk-meta bm-order-risk-meta--disabled">
				<p>
					<strong><?php esc_html_e( 'Global Blacklist is enabled, but your site is not connected to the Global Blacklist server yet.', 'wc-blacklist-manager' ); ?></strong>
				</p>
				<p>
					<?php
					$settings_url = admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' );

					echo wp_kses(
						sprintf(
							/* translators: 1: URL to Global Blacklist settings. */
							__(
								'To see Global Blacklist risk scores and decisions here, finish connecting your site to the server on the <a href="%1$s" target="_blank" rel="noopener noreferrer">Global Blacklist settings page</a>.',
								'wc-blacklist-manager'
							),
							esc_url( $settings_url )
						),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					);
					?>
				</p>
			</div>
			<?php
			return;
		endif;

		// ---------------------------------------------------------------------
		// Branch 2: Global Blacklist enabled -> show tier + usage + decision.
		// ---------------------------------------------------------------------

		// Tier FROM ORDER META, default to "free".
		$tier_meta = (string) $order->get_meta( '_yogb_gbl_tier', true );
		$tier      = $tier_meta !== '' ? strtolower( trim( $tier_meta ) ) : 'free';

		$allowed_tiers = array( 'free', 'basic', 'pro', 'enterprise' );
		if ( ! in_array( $tier, $allowed_tiers, true ) ) {
			$tier = 'free';
		}

		// Pretty tier label
		switch ( $tier ) {
			case 'basic':
				$tier_label = __( 'Basic', 'wc-blacklist-manager' );
				break;
			case 'pro':
				$tier_label = __( 'Pro', 'wc-blacklist-manager' );
				break;
			case 'enterprise':
				$tier_label = __( 'Enterprise', 'wc-blacklist-manager' );
				break;
			case 'free':
			default:
				$tier_label = __( 'Free', 'wc-blacklist-manager' );
				break;
		}

		// Monthly limit (mirror enforce_rate_limit(), enterprise unlimited).
		switch ( $tier ) {
			case 'basic':
				$tier_limit = 150;
				break;
			case 'pro':
				$tier_limit = 1000;
				break;
			case 'enterprise':
				$tier_limit = 0; // unlimited
				break;
			case 'free':
			default:
				$tier_limit = 20;
				break;
		}

		// Usage for current UTC calendar month (without incrementing), keyed by this tier.
		$month_key  = gmdate( 'Ym' ); // e.g. 202511
		$usage_opt  = 'yogb_bm_chk_month_' . $tier . '_' . $month_key;
		$tier_used  = (int) get_option( $usage_opt, 0 );

		// Usage text: "3 / 20 checks used this month" or "3 checks used this month (unlimited)".
		if ( $tier_limit > 0 ) {
			$has_reached_limit = ( $tier_used >= $tier_limit );

			if ( $has_reached_limit ) {
				$tier_usage_text = sprintf(
					/* translators: 1: used checks, 2: monthly limit */
					__(
						'%1$d / %2$d checks used this month. You have reached your monthly limit. Upgrade your tier to unlock more checks.',
						'wc-blacklist-manager'
					),
					$tier_used,
					$tier_limit
				);
			} else {
				$tier_usage_text = sprintf(
					/* translators: 1: used checks, 2: monthly limit */
					__( '%1$d / %2$d checks used this month.', 'wc-blacklist-manager' ),
					$tier_used,
					$tier_limit
				);
			}
		} else {
			$tier_usage_text = sprintf(
				/* translators: 1: used checks */
				__( '%1$d checks used this month (unlimited tier).', 'wc-blacklist-manager' ),
				$tier_used
			);
		}

		// Upgrade badge for free/basic/pro only.
		$can_upgrade   = in_array( $tier, array( 'free', 'basic', 'pro' ), true );
		$upgrade_url   = 'https://yoohw.com/global-blacklist-plan/';
		$upgrade_label = __( 'Upgrade', 'wc-blacklist-manager' );

		// ---------------------------------------------------------------------
		// Decision (allow / challenge / block).
		// ---------------------------------------------------------------------

		$decision_raw = strtolower( trim( (string) $order->get_meta( '_yogb_gbl_decision', true ) ) );
		$allowed_decisions = array( 'allow', 'challenge', 'block' );

		if ( ! in_array( $decision_raw, $allowed_decisions, true ) ) {
			$decision_slug  = 'none';
			$decision_label = __( 'No record', 'wc-blacklist-manager' );
		} else {
			$decision_slug = $decision_raw;
			switch ( $decision_raw ) {
				case 'allow':
					$decision_label = __( 'Allow', 'wc-blacklist-manager' );
					break;
				case 'challenge':
					$decision_label = __( 'Challenge', 'wc-blacklist-manager' );
					break;
				case 'block':
					$decision_label = __( 'Block', 'wc-blacklist-manager' );
					break;
			}
		}

		// Identity coverage per tier (what this order is checked against).
		$coverage_message = '';

		switch ( $tier ) {
			case 'free':
				$coverage_message = __(
					'This order is checked against email and IP only on the Free tier.',
					'wc-blacklist-manager'
				);
				break;

			case 'basic':
				$coverage_message = __(
					'This order is checked against email, phone and IP on the Basic tier.',
					'wc-blacklist-manager'
				);
				break;

			case 'pro':
			case 'enterprise':
			default:
				// Pro and Enterprise already include all checks (email, phone, IP, address).
				$coverage_message = '';
				break;
		}

		// Extra details from Global Blacklist metas.
		$reason_summaries = (array) $order->get_meta( '_yogb_gbl_reason_summaries', true );
		$report_summaries = (array) $order->get_meta( '_yogb_gbl_report_summaries', true );
		$has_details      = ! empty( $reason_summaries ) || ! empty( $report_summaries );
		
		?>
		<div class="bm-order-risk-meta bm-order-risk-meta--enabled">
			<p class="bm-order-risk-tier">
				<span class="yogb-tier-badge yogb-tier-<?php echo esc_attr( $tier ); ?>">
					<span class="yogb-tier-dot"></span>
					<span class="yogb-tier-text"><?php echo esc_html( $tier_label ); ?></span>
				</span>

				<?php if ( $can_upgrade ) : ?>
					<a
						href="<?php echo esc_url( $upgrade_url ); ?>"
						class="yogb-tier-cta yogb-tier-cta--small"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?php echo esc_html( $upgrade_label ); ?>
					</a>
				<?php endif; ?>

				<br />
				<small class="bm-order-risk-tier-usage<?php echo $has_reached_limit ? ' bm-order-risk-tier-usage--limit' : ''; ?>">
					<?php echo esc_html( $tier_usage_text ); ?>
				</small>
			</p>

			<p class="bm-order-risk-decision">
				<strong><?php esc_html_e( 'Global Blacklist decision', 'wc-blacklist-manager' ); ?>:</strong>
				<span class="bm-decision-badge bm-decision-<?php echo esc_attr( $decision_slug ); ?>">
					<?php echo esc_html( $decision_label ); ?>
				</span>

				<span
					class="woocommerce-help-tip"
					data-tip="<?php echo esc_attr__(
						'Allow: order passes Global Blacklist checks. Challenge: order is allowed but should be reviewed or additional checks applied. Block: order should be rejected based on Global Blacklist risk.',
						'wc-blacklist-manager'
					); ?>"
				></span>
			</p>

			<?php
			// -----------------------------------------------------------------
			// Raw Global Blacklist data (_yogb_gbl_raw)
			// -----------------------------------------------------------------
			$raw_json = (string) $order->get_meta( '_yogb_gbl_raw', true );

			if ( '' !== $raw_json ) {
				$raw_data = json_decode( $raw_json, true );

				if ( is_array( $raw_data ) ) {
					// Decision numeric risk, if present.
					$risk_numeric_display = null;
					if ( isset( $raw_data['decision']['risk_numeric'] ) ) {
						$risk_numeric = (float) $raw_data['decision']['risk_numeric'];

						// Map to a human-readable band
						$band = __( 'low', 'wc-blacklist-manager' );
						if ( $risk_numeric >= 2.5 ) {
							$band = __( 'very high', 'wc-blacklist-manager' );
						} elseif ( $risk_numeric >= 1.2 ) {
							$band = __( 'elevated', 'wc-blacklist-manager' );
						} elseif ( $risk_numeric >= 0.5 ) {
							$band = __( 'moderate', 'wc-blacklist-manager' );
						}

						$risk_numeric_display = sprintf(
							/* translators: 1: numeric score, 2: band label */
							__( 'Estimated risk score: %1$.1f (%2$s)', 'wc-blacklist-manager' ),
							$risk_numeric,
							$band
						);
					}
					$results = isset( $raw_data['results'] ) && is_array( $raw_data['results'] )
						? $raw_data['results']
						: array();
				?>

					<?php if ( $risk_numeric_display ) : ?>
						<p class="bm-order-risk-overall">
							<?php echo esc_html( $risk_numeric_display ); ?>

							<span
								class="woocommerce-help-tip"
								data-tip="<?php echo esc_attr__(
									'This numeric is the overall estimated Global Blacklist risk for this order, combining all identities. The cards below show per-identity details: risk level, total report count, score, and last reported time.',
									'wc-blacklist-manager'
								); ?>"
							></span>
						</p>
					<?php endif; ?>

					<?php if ( ! empty( $results ) ) : ?>
						<div class="bm-gbl-identities">
							<?php foreach ( $results as $item ) :
								$type   = isset( $item['type'] ) ? (string) $item['type'] : '';
								$agg    = isset( $item['aggregate'] ) && is_array( $item['aggregate'] ) ? $item['aggregate'] : array();
								$risk   = isset( $agg['risk_level'] ) ? (string) $agg['risk_level'] : '';
								$reports= isset( $agg['report_count'] ) ? (int) $agg['report_count'] : 0;
								$score  = isset( $agg['score'] ) ? (float) $agg['score'] : 0;
								$last   = isset( $agg['last_reported'] ) ? (string) $agg['last_reported'] : '';

								// Nicify type label.
								switch ( strtolower( $type ) ) {
									case 'email':
										$type_label = __( 'Email', 'wc-blacklist-manager' );
										break;
									case 'phone':
										$type_label = __( 'Phone', 'wc-blacklist-manager' );
										break;
									case 'ip':
										$type_label = __( 'IP address', 'wc-blacklist-manager' );
										break;
									case 'address':
										$type_label = __( 'Address', 'wc-blacklist-manager' );
										break;
									default:
										$type_label = ucfirst( $type );
										break;
								}

								// Risk label + slug.
								$risk_slug  = $risk !== '' ? strtolower( $risk ) : 'unknown';
								$risk_label = $risk !== '' ? ucfirst( $risk ) : __( 'Unknown', 'wc-blacklist-manager' );

								$reports_label = number_format_i18n( $reports );
								$score_label   = number_format_i18n( $score, 0 );
								$last_label    = $last ? $last : '—';

								// Flag card if this identity has any reports / score > 0.
								$is_flagged   = ( $reports > 0 || $score > 0 );
								$card_classes = 'bm-gbl-identity bm-gbl-identity-' . strtolower( $type );
								if ( $is_flagged ) {
									$card_classes .= ' bm-gbl-identity--flagged';
									if ( $has_details ) {
										$card_classes .= ' bm-gbl-identity--clickable';
									}
								}
							?>
								<div
									class="<?php echo esc_attr( $card_classes ); ?>"
									<?php echo ( $is_flagged && $has_details ) ? 'data-bm-gbl-open-modal="1"' : ''; ?>
								>
									<div class="bm-gbl-identity-header">
										<span class="bm-gbl-identity-type">
											<?php echo esc_html( $type_label ); ?>
										</span>
										<span class="bm-gbl-risk-badge bm-gbl-risk-<?php echo esc_attr( $risk_slug ); ?>">
											<?php echo esc_html( $risk_label ); ?>
										</span>
									</div>
									<div class="bm-gbl-identity-meta">
										<span><?php printf( esc_html__( 'Reports: %s', 'wc-blacklist-manager' ), esc_html( $reports_label ) ); ?></span>
										<span>·</span>
										<span><?php printf( esc_html__( 'Score: %s', 'wc-blacklist-manager' ), esc_html( $score_label ) ); ?></span>
										<span>·</span>
										<span><?php printf( esc_html__( 'Last: %s', 'wc-blacklist-manager' ), esc_html( $last_label ) ); ?></span>
									</div>

									<?php if ( $is_flagged && $has_details ) : ?>
										<div class="bm-gbl-identity-hint">
											<?php esc_html_e( 'Click for full details', 'wc-blacklist-manager' ); ?>
										</div>
									<?php endif; ?>
								</div>

							<?php endforeach; ?>
						</div>

						<?php if ( $has_details ) : ?>
							<div class="bm-gbl-details-modal-backdrop" id="bm-gbl-details-modal-backdrop" style="display:none;"></div>
							<div class="bm-gbl-details-modal" id="bm-gbl-details-modal" style="display:none;">
								<div class="bm-gbl-details-modal-inner">
									<button type="button" class="bm-gbl-details-close" id="bm-gbl-details-close" aria-label="<?php esc_attr_e( 'Close', 'wc-blacklist-manager' ); ?>">×</button>
									<h3><?php esc_html_e( 'Global Blacklist details', 'wc-blacklist-manager' ); ?></h3>

									<?php if ( ! empty( $reason_summaries ) ) : ?>
										<h4><?php esc_html_e( 'Identity risk summary', 'wc-blacklist-manager' ); ?></h4>
										<ul class="bm-gbl-details-list">
											<?php foreach ( $reason_summaries as $line ) : ?>
												<li><?php echo esc_html( $line ); ?></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>

									<?php if ( ! empty( $report_summaries ) ) : ?>
										<h4><?php esc_html_e( 'Individual reports', 'wc-blacklist-manager' ); ?></h4>
										<ul class="bm-gbl-details-list">
											<?php foreach ( $report_summaries as $line ) : ?>
												<li><?php echo esc_html( $line ); ?></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>

									<p class="bm-gbl-details-footer">
										<button type="button" class="button button-secondary" id="bm-gbl-details-close-btn">
											<?php esc_html_e( 'Close', 'wc-blacklist-manager' ); ?>
										</button>
									</p>
								</div>
							</div>
						<?php endif; ?>

					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'No identity-level details were returned for this order.', 'wc-blacklist-manager' ); ?>
						</p>
					<?php endif; ?>

					<?php
					// Coverage + upgrade hint at the bottom, for Free / Basic tiers only.
					if ( $coverage_message ) {
						// If this tier can upgrade, append an upgrade call-to-action.
						if ( $can_upgrade ) {
							echo '<p class="bm-gbl-coverage">';
							echo wp_kses(
								sprintf(
									/* translators: 1: coverage message, 2: upgrade URL */
									__(
										'%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">Upgrade for more coverage (add phone and/or address checks).</a>',
										'wc-blacklist-manager'
									),
									esc_html( $coverage_message ),
									esc_url( $upgrade_url )
								),
								array(
									'a' => array(
										'href'   => array(),
										'target' => array(),
										'rel'    => array(),
									),
								)
							);
							echo '</p>';
						} else {
							// Just show the coverage text, no link (pro/enterprise won’t hit this anyway).
							echo '<p class="bm-gbl-coverage">' . esc_html( $coverage_message ) . '</p>';
						}
					}
					?>

				<?php
				} // is_array( $raw_data )
			} // has raw_json
			?>
		</div>

		<?php if ( $has_details ) : ?>
			<style>
				.bm-gbl-identity--clickable {
					cursor: pointer;
				}
				.bm-gbl-identity-hint {
					margin-top: 4px;
					font-size: 11px;
					color: #666;
				}
				.bm-gbl-details-modal-backdrop {
					position: fixed;
					inset: 0;
					background: rgba(0, 0, 0, 0.4);
					z-index: 100001;
				}
				.bm-gbl-details-modal {
					position: fixed;
					inset: 0;
					display: flex;
					align-items: center;
					justify-content: center;
					z-index: 100002;
				}
				.bm-gbl-details-modal-inner {
					background: #fff;
					padding: 16px 18px;
					max-width: 640px;
					width: 100%;
					max-height: 80vh;
					overflow-y: auto;
					box-shadow: 0 4px 20px rgba(0,0,0,0.2);
					border-radius: 4px;
				}
				.bm-gbl-details-modal-inner h3 {
					margin-top: 0;
				}
				.bm-gbl-details-list {
					margin-left: 18px;
					list-style: disc;
				}
				.bm-gbl-details-footer {
					margin-top: 12px;
					text-align: right;
				}
				.bm-gbl-details-close {
					position: absolute;
					right: 10px;
					top: 8px;
					border: none;
					background: transparent;
					font-size: 20px;
					line-height: 1;
					cursor: pointer;
				}
			</style>
			<script>
				(function() {
					document.addEventListener('DOMContentLoaded', function() {
						var cards    = document.querySelectorAll('.bm-gbl-identity--clickable[data-bm-gbl-open-modal="1"]');
						var backdrop = document.getElementById('bm-gbl-details-modal-backdrop');
						var modal    = document.getElementById('bm-gbl-details-modal');
						var closeX   = document.getElementById('bm-gbl-details-close');
						var closeBtn = document.getElementById('bm-gbl-details-close-btn');

						if (!cards.length || !backdrop || !modal) {
							return;
						}

						function openModal() {
							backdrop.style.display = 'block';
							modal.style.display    = 'flex';
						}

						function closeModal() {
							backdrop.style.display = 'none';
							modal.style.display    = 'none';
						}

						cards.forEach(function(card) {
							card.addEventListener('click', function(e) {
								e.preventDefault();
								openModal();
							});
						});

						if (backdrop) {
							backdrop.addEventListener('click', closeModal);
						}
						if (closeX) {
							closeX.addEventListener('click', closeModal);
						}
						if (closeBtn) {
							closeBtn.addEventListener('click', closeModal);
						}

						document.addEventListener('keydown', function(e) {
							if (e.key === 'Escape') {
								closeModal();
							}
						});
					});
				})();
			</script>
		<?php endif; ?>

		<?php
	}
}

// Instantiate the class
if (class_exists('WC_Blacklist_Manager_Order_Risk_Score')) {
	new WC_Blacklist_Manager_Order_Risk_Score();
}
