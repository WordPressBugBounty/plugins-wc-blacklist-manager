<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

class WC_Blacklist_Manager_Order_Risk_Score {

	public function __construct() {
		if (get_option( 'wc_blacklist_enable_global_blacklist', '0' ) !== '1' ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'add_order_risk_score_meta_box' ), 1 );
	}

	public function add_order_risk_score_meta_box() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		if ( $premium_active ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				if ( 'shop_order' === ( $screen->post_type ?? '' ) && 'add' === ( $screen->action ?? '' ) ) {
					return;
				}

				if ( 'woocommerce_page_wc-orders' === $screen->id && isset( $_GET['action'] ) && 'new' === $_GET['action'] ) {
					return;
				}
			}
		}

		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'wc_blacklist_manager_order_risk_score',
			__( 'Order risk score', 'wc-blacklist-manager' ),
			array( $this, 'display_order_risk_score_meta_box' ),
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
		$order = is_a( $object, 'WP_Post' ) ? wc_get_order( $object->ID ) : $object;

		if ( ! $order ) {
			?><p><?php esc_html_e( 'Order not found.', 'wc-blacklist-manager' ); ?></p><?php
			return;
		}

		$enable_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=enable_global_blacklist' ),
			'enable_global_blacklist'
		);

		$opt_api_key     = class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::OPT_API_KEY : 'yogb_bm_api_key';
		$opt_api_secret  = class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::OPT_API_SECRET : 'yogb_bm_api_secret';
		$opt_reporter_id = class_exists( 'YOGB_BM_Registrar' ) ? YOGB_BM_Registrar::OPT_REPORTER_ID : 'yogb_bm_reporter_id';

		$api_key     = trim( (string) get_option( $opt_api_key, '' ) );
		$api_secret  = trim( (string) get_option( $opt_api_secret, '' ) );
		$reporter_id = trim( (string) get_option( $opt_reporter_id, '' ) );

		$missing_connection = ( '' === $api_key || '' === $api_secret || '' === $reporter_id );

		if ( $missing_connection ) : ?>
			<div class="bm-order-risk-meta bm-order-risk-meta--disabled">
				<p><strong><?php esc_html_e( 'Global Blacklist is enabled, but your site is not connected to the Global Blacklist server yet.', 'wc-blacklist-manager' ); ?></strong></p>
				<p>
					<?php
					$settings_url = admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' );

					echo wp_kses(
						sprintf(
							__(
								'To see Global Blacklist results here, finish connecting your site on the <a href="%1$s" target="_blank" rel="noopener noreferrer">Global Blacklist settings page</a>.',
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

		$order_id = $order->get_id();

		$has_gbl_result = false;

		$decision_probe = trim( (string) $order->get_meta( '_yogb_gbl_decision', true ) );
		$raw_probe      = trim( (string) $order->get_meta( '_yogb_gbl_raw', true ) );

		if ( '' !== $decision_probe ) {
			$has_gbl_result = true;
		} elseif ( '' !== $raw_probe && '{}' !== $raw_probe ) {
			$has_gbl_result = true;
		}

		// The Global Blacklist order check may run by scheduled action/cron.
		// On newly created orders, the result meta may not exist yet.
		if ( ! $has_gbl_result ) : ?>
			<div class="bm-order-risk-meta bm-order-risk-meta--pending">
				<div class="bm-gbl-summary-card bm-gbl-summary-card--pending">
					<div class="bm-gbl-summary-card__label">
						<?php esc_html_e( 'Fraud check result', 'wc-blacklist-manager' ); ?>
					</div>

					<div class="bm-gbl-summary-card__value">
						<span class="bm-decision-badge bm-decision-none">
							<?php esc_html_e( 'Pending check', 'wc-blacklist-manager' ); ?>
						</span>
					</div>

					<div class="bm-gbl-summary-card__text">
						<?php esc_html_e( 'The Global Blacklist check has not completed for this order yet. This can happen shortly after the order is created because the check runs in the background by schedule.', 'wc-blacklist-manager' ); ?>
					</div>
				</div>

				<?php
				$created = $order->get_date_created();
				$can_show_recheck = true;

				if ( $created ) {
					$order_age = time() - $created->getTimestamp();

					$min_age = 2 * MINUTE_IN_SECONDS; // or 3 min if you want stricter

					if ( $order_age < $min_age ) {
						$can_show_recheck = false;
					}
				}

				$recheck_url = wp_nonce_url(
					add_query_arg(
						[
							'action'   => 'yogb_gbl_manual_order_check',
							'order_id' => $order_id,
						],
						admin_url( 'admin-post.php' )
					),
					'yogb_gbl_manual_order_check_' . $order_id
				);
				?>

				<p>
					<?php if ( $can_show_recheck ) : ?>
						<p>
							<a href="<?php echo esc_url( $recheck_url ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Recheck now', 'wc-blacklist-manager' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p>
							<small>
								<?php esc_html_e( 'The check is being processed. You can manually recheck in a moment if needed.', 'wc-blacklist-manager' ); ?>
							</small>
						</p>
					<?php endif; ?>
				</p>

				<style>
					.bm-gbl-summary-card--pending {
						margin: 12px 0;
						padding: 12px;
						border: 1px solid #dcdcde;
						border-left: 4px solid #72aee6;
						border-radius: 6px;
						background: #f6f7f7;
					}
					.bm-gbl-summary-card__label {
						font-size: 12px;
						font-weight: 600;
						color: #50575e;
						margin-bottom: 6px;
					}
					.bm-gbl-summary-card__value {
						float: right;
					}
					.bm-gbl-summary-card__text {
						font-size: 13px;
						line-height: 1.5;
					}
				</style>
			</div>
			<?php
			return;
		endif;

		$tier_meta = (string) $order->get_meta( '_yogb_gbl_tier', true );
		$tier      = '' !== $tier_meta ? strtolower( trim( $tier_meta ) ) : 'free';

		$allowed_tiers = array( 'free', 'basic', 'pro', 'enterprise' );
		if ( ! in_array( $tier, $allowed_tiers, true ) ) {
			$tier = 'free';
		}

		switch ( $tier ) {
			case 'basic':
				$tier_label = __( 'Basic', 'wc-blacklist-manager' );
				$tier_limit = 150;
				break;
			case 'pro':
				$tier_label = __( 'Pro', 'wc-blacklist-manager' );
				$tier_limit = 1000;
				break;
			case 'enterprise':
				$tier_label = __( 'Enterprise', 'wc-blacklist-manager' );
				$tier_limit = 0;
				break;
			case 'free':
			default:
				$tier_label = __( 'Free', 'wc-blacklist-manager' );
				$tier_limit = 20;
				break;
		}

		$month_key         = gmdate( 'Ym' );
		$usage_opt         = 'yogb_bm_chk_month_' . $tier . '_' . $month_key;
		$tier_used         = (int) get_option( $usage_opt, 0 );
		$has_reached_limit = ( $tier_limit > 0 && $tier_used >= $tier_limit );

		if ( $tier_limit > 0 ) {
			$tier_usage_text = $has_reached_limit
				? sprintf(
					__( '%1$d / %2$d checks used this month. Your monthly limit has been reached.', 'wc-blacklist-manager' ),
					$tier_used,
					$tier_limit
				)
				: sprintf(
					__( '%1$d / %2$d checks used this month.', 'wc-blacklist-manager' ),
					$tier_used,
					$tier_limit
				);
		} else {
			$tier_usage_text = sprintf(
				__( '%1$d checks used this month (unlimited tier).', 'wc-blacklist-manager' ),
				$tier_used
			);
		}

		$can_upgrade = in_array( $tier, array( 'free', 'basic', 'pro' ), true );
		$upgrade_url = 'https://yoohw.com/global-blacklist-plan/';

		$decision_raw = strtolower( trim( (string) $order->get_meta( '_yogb_gbl_decision', true ) ) );

		$meta_effective_score    = (float) $order->get_meta( '_yogb_gbl_effective_score', true );
		$meta_direct_score       = (float) $order->get_meta( '_yogb_gbl_direct_score', true );
		$meta_linked_boost       = (float) $order->get_meta( '_yogb_gbl_linked_boost', true );
		$meta_neighbors_count    = (int) $order->get_meta( '_yogb_gbl_linked_neighbors_count', true );
		$meta_matched_identities = (int) $order->get_meta( '_yogb_gbl_matched_identities', true );
		$meta_primary_type       = (string) $order->get_meta( '_yogb_gbl_primary_signal_type', true );
		$meta_primary_risk       = (string) $order->get_meta( '_yogb_gbl_primary_risk_level', true );
		$meta_primary_last       = (string) $order->get_meta( '_yogb_gbl_primary_last_reported', true );

		$signal_summaries = $this->normalize_meta_lines( $order->get_meta( '_yogb_gbl_signal_summaries', true ) );
		$reason_summaries = $this->normalize_meta_lines( $order->get_meta( '_yogb_gbl_reason_summaries', true ) );
		$report_summaries = $this->normalize_meta_lines( $order->get_meta( '_yogb_gbl_report_summaries', true ) );

		$raw_json = (string) $order->get_meta( '_yogb_gbl_raw', true );
		$raw_data = array();

		if ( '' !== $raw_json ) {
			$decoded = json_decode( $raw_json, true );
			if ( is_array( $decoded ) ) {
				$raw_data = $decoded;
			}
		}

		$results = isset( $raw_data['results'] ) && is_array( $raw_data['results'] ) ? $raw_data['results'] : array();

		$matched_types       = array();
		$matched_modes       = array();
		$matched_variants    = array();
		$total_matched_nodes = 0;

		foreach ( $results as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type                 = isset( $item['type'] ) ? strtolower( (string) $item['type'] ) : '';
			$agg                  = isset( $item['aggregate'] ) && is_array( $item['aggregate'] ) ? $item['aggregate'] : array();
			$matches              = isset( $item['matches'] ) && is_array( $item['matches'] ) ? $item['matches'] : array();
			$match_mode           = isset( $item['match_mode'] ) ? strtolower( (string) $item['match_mode'] ) : '';
			$matched_variant      = isset( $item['matched_variant'] ) ? strtolower( (string) $item['matched_variant'] ) : '';
			$matched_identity_cnt = isset( $item['matched_identity_count'] ) ? (int) $item['matched_identity_count'] : 0;

			$reports   = isset( $agg['report_count'] ) ? (int) $agg['report_count'] : 0;
			$direct    = isset( $agg['direct_score'] ) ? (float) $agg['direct_score'] : 0.0;
			$linked    = isset( $agg['linked_boost'] ) ? (float) $agg['linked_boost'] : 0.0;
			$effective = isset( $agg['score'] ) ? (float) $agg['score'] : 0.0;

			if ( ! empty( $matches ) || $reports > 0 || $direct > 0 || $linked > 0 || $effective > 0 ) {
				$matched_types[]    = $type;
				$total_matched_nodes += max( 1, $matched_identity_cnt );

				if ( '' !== $match_mode && 'none' !== $match_mode ) {
					$matched_modes[] = $match_mode;
				}

				if ( '' !== $matched_variant && 'submitted' !== $matched_variant ) {
					$matched_variants[] = $matched_variant;
				}
			}
		}

		$matched_types    = array_values( array_unique( array_filter( $matched_types ) ) );
		$matched_modes    = array_values( array_unique( array_filter( $matched_modes ) ) );
		$matched_variants = array_values( array_unique( array_filter( $matched_variants ) ) );

		switch ( $decision_raw ) {
			case 'block':
				$decision_slug   = 'block';
				$decision_label  = __( 'High risk', 'wc-blacklist-manager' );
				$summary_text    = __( 'This order matched strong fraud-related signals in the Global Blacklist network.', 'wc-blacklist-manager' );
				$action_text     = __( 'Do not fulfill this order until it has been manually verified.', 'wc-blacklist-manager' );
				break;

			case 'challenge':
				$decision_slug   = 'challenge';
				$decision_label  = __( 'Needs review', 'wc-blacklist-manager' );
				$summary_text    = __( 'Some order details matched previous fraud-related reports. Manual review is recommended.', 'wc-blacklist-manager' );
				$action_text     = __( 'Review the order details before shipping or completing the order.', 'wc-blacklist-manager' );
				break;

			case 'allow':
				$decision_slug   = 'allow';
				$decision_label  = __( 'Clear', 'wc-blacklist-manager' );
				$summary_text    = __( 'No blocking decision was returned for this order.', 'wc-blacklist-manager' );
				$action_text     = __( 'Proceed as normal.', 'wc-blacklist-manager' );
				break;

			case 'skipped_rate_limit':
				$decision_slug   = 'none';
				$decision_label  = __( 'Check skipped', 'wc-blacklist-manager' );
				$summary_text    = __( 'This order was not checked because your monthly Global Blacklist limit was reached.', 'wc-blacklist-manager' );
				$action_text     = __( 'Upgrade your plan if you need more checks this month.', 'wc-blacklist-manager' );
				break;

			default:
				$decision_slug   = 'none';
				$decision_label  = __( 'No record', 'wc-blacklist-manager' );
				$summary_text    = __( 'No Global Blacklist result has been stored for this order yet.', 'wc-blacklist-manager' );
				$action_text     = __( 'No action is required unless you want to run a check manually.', 'wc-blacklist-manager' );
				break;
		}

		$main_reason_text = '';
		if ( ! empty( $reason_summaries ) ) {
			$main_reason_text = $reason_summaries[0];
		} elseif ( ! empty( $signal_summaries ) ) {
			$main_reason_text = $signal_summaries[0];
		} elseif ( $meta_primary_type ) {
			$main_reason_text = sprintf(
				__( 'The strongest match was %1$s with %2$s risk.', 'wc-blacklist-manager' ),
				ucfirst( $meta_primary_type ),
				$meta_primary_risk ? strtolower( $meta_primary_risk ) : __( 'low', 'wc-blacklist-manager' )
			);
		}

		$smart_match_text = '';
		if ( ! empty( $matched_modes ) || ! empty( $matched_variants ) || $total_matched_nodes > 0 ) {
			$parts = array();

			if ( ! empty( $matched_modes ) ) {
				$parts[] = sprintf(
					__( 'Match type: %s', 'wc-blacklist-manager' ),
					implode( ', ', array_map( array( $this, 'format_match_mode_label' ), $matched_modes ) )
				);
			}

			if ( ! empty( $matched_variants ) ) {
				$parts[] = sprintf(
					__( 'Matched detail: %s', 'wc-blacklist-manager' ),
					implode( ', ', array_map( array( $this, 'format_matched_variant_label' ), $matched_variants ) )
				);
			}

			if ( $total_matched_nodes > 0 ) {
				$parts[] = sprintf(
					__( 'Related identity records: %s', 'wc-blacklist-manager' ),
					number_format_i18n( $total_matched_nodes )
				);
			}

			$smart_match_text = implode( ' · ', $parts );
		}
		?>
		<div class="bm-order-risk-meta bm-order-risk-meta--enabled bm-order-risk-meta--simple">
			<p class="bm-order-risk-tier">
				<span class="yogb-tier-badge yogb-tier-<?php echo esc_attr( $tier ); ?>">
					<span class="yogb-tier-dot"></span>
					<span class="yogb-tier-text"><?php echo esc_html( $tier_label ); ?></span>
				</span>
				<?php if ( $can_upgrade ) : ?>
					<a href="<?php echo esc_url( $upgrade_url ); ?>" class="yogb-tier-cta yogb-tier-cta--small" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Upgrade', 'wc-blacklist-manager' ); ?>
					</a>
				<?php endif; ?>
				<span 
					class="woocommerce-help-tip" 
					tabindex="0" 
					data-tip="<?php esc_attr_e('Global Blacklist works alongside your site’s local blacklist to add extra protection by identifying real customers already flagged on our global blacklist (not bots), helping you block known high-risk users more accurately.', 'wc-blacklist-manager'); ?>">
				</span>
				<br />
				<small class="bm-order-risk-tier-usage<?php echo $has_reached_limit ? ' bm-order-risk-tier-usage--limit' : ''; ?>">
					<?php echo esc_html( $tier_usage_text ); ?>
				</small>
			</p>

			<div class="bm-gbl-summary-card bm-gbl-summary-card--<?php echo esc_attr( $decision_slug ); ?>">
				<div class="bm-gbl-summary-card__label">
					<?php esc_html_e( 'Fraud check result', 'wc-blacklist-manager' ); ?>
				</div>
				<div class="bm-gbl-summary-card__value">
					<span class="bm-decision-badge bm-decision-<?php echo esc_attr( $decision_slug ); ?>">
						<?php echo esc_html( $decision_label ); ?>
					</span>
				</div>
				<div class="bm-gbl-summary-card__text">
					<?php echo esc_html( $summary_text ); ?>
				</div>
			</div>

			<div class="bm-gbl-merchant-section">
				<div class="bm-gbl-merchant-section__title"><?php esc_html_e( 'What this means', 'wc-blacklist-manager' ); ?></div>
				<?php if ( $main_reason_text ) : ?>
					<p><?php echo esc_html( $main_reason_text ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'No additional match details are available for this order.', 'wc-blacklist-manager' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $smart_match_text ) : ?>
				<div class="bm-gbl-merchant-section">
					<div class="bm-gbl-merchant-section__title"><?php esc_html_e( 'How this was matched', 'wc-blacklist-manager' ); ?></div>
					<p><?php echo esc_html( $smart_match_text ); ?></p>
				</div>
			<?php endif; ?>

			<div class="bm-gbl-merchant-section">
				<div class="bm-gbl-merchant-section__title"><?php esc_html_e( 'Recommended action', 'wc-blacklist-manager' ); ?></div>
				<p><?php echo esc_html( $action_text ); ?></p>
			</div>

			<?php if ( ! empty( $matched_types ) ) : ?>
				<div class="bm-gbl-merchant-section">
					<div class="bm-gbl-merchant-section__title"><?php esc_html_e( 'Matched checks', 'wc-blacklist-manager' ); ?></div>
					<p><?php echo esc_html( implode( ', ', array_map( array( $this, 'format_identity_type_label' ), $matched_types ) ) ); ?></p>
				</div>
			<?php endif; ?>

			<details class="bm-gbl-advanced-details">
				<summary><?php esc_html_e( 'View advanced details', 'wc-blacklist-manager' ); ?></summary>

				<?php if ( $meta_effective_score > 0 || $meta_direct_score > 0 || $meta_linked_boost > 0 ) : ?>
					<div class="bm-gbl-primary-signal">
						<div class="bm-gbl-primary-signal__title">
							<?php esc_html_e( 'Strongest match', 'wc-blacklist-manager' ); ?>
						</div>
						<div class="bm-gbl-primary-signal__meta">
							<span><?php printf( esc_html__( 'Type: %s', 'wc-blacklist-manager' ), esc_html( $meta_primary_type ? ucfirst( $meta_primary_type ) : __( 'Unknown', 'wc-blacklist-manager' ) ) ); ?></span>
							<span>·</span>
							<span><?php printf( esc_html__( 'Risk: %s', 'wc-blacklist-manager' ), esc_html( $meta_primary_risk ? ucfirst( $meta_primary_risk ) : __( 'Low', 'wc-blacklist-manager' ) ) ); ?></span>
							<span>·</span>
							<span><?php printf( esc_html__( 'Matched checks: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $meta_matched_identities ) ) ); ?></span>
						</div>
						<div class="bm-gbl-primary-signal__meta">
							<span><?php printf( esc_html__( 'Direct: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $meta_direct_score, 2 ) ) ); ?></span>
							<span>·</span>
							<span><?php printf( esc_html__( 'Linked: +%s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $meta_linked_boost, 2 ) ) ); ?></span>
							<span>·</span>
							<span><?php printf( esc_html__( 'Effective: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $meta_effective_score, 2 ) ) ); ?></span>
						</div>
						<div class="bm-gbl-primary-signal__meta">
							<span><?php printf( esc_html__( 'Neighbors: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $meta_neighbors_count ) ) ); ?></span>
							<span>·</span>
							<span><?php printf( esc_html__( 'Last reported: %s', 'wc-blacklist-manager' ), esc_html( $meta_primary_last ? $meta_primary_last : '—' ) ); ?></span>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $results ) ) : ?>
					<div class="bm-gbl-identities">
						<?php foreach ( $results as $item ) :
							if ( ! is_array( $item ) ) {
								continue;
							}

							$type                  = isset( $item['type'] ) ? (string) $item['type'] : '';
							$agg                   = isset( $item['aggregate'] ) && is_array( $item['aggregate'] ) ? $item['aggregate'] : array();
							$matches               = isset( $item['matches'] ) && is_array( $item['matches'] ) ? $item['matches'] : array();
							$match_mode            = isset( $item['match_mode'] ) ? strtolower( (string) $item['match_mode'] ) : 'none';
							$matched_variant       = isset( $item['matched_variant'] ) ? strtolower( (string) $item['matched_variant'] ) : '';
							$matched_identity_count = isset( $item['matched_identity_count'] ) ? (int) $item['matched_identity_count'] : 0;

							$risk      = isset( $agg['risk_level'] ) ? (string) $agg['risk_level'] : '';
							$reports   = isset( $agg['report_count'] ) ? (int) $agg['report_count'] : 0;
							$direct    = isset( $agg['direct_score'] ) ? (float) $agg['direct_score'] : 0.0;
							$linked    = isset( $agg['linked_boost'] ) ? (float) $agg['linked_boost'] : 0.0;
							$effective = isset( $agg['score'] ) ? (float) $agg['score'] : 0.0;
							$neighbors = isset( $agg['linked_neighbors_count'] ) ? (int) $agg['linked_neighbors_count'] : 0;
							$last      = isset( $agg['last_reported'] ) ? (string) $agg['last_reported'] : '';

							$type_label = $this->format_identity_type_label( $type );
							$risk_slug  = '' !== $risk ? strtolower( $risk ) : 'unknown';
							$risk_label = '' !== $risk ? ucfirst( $risk ) : __( 'Unknown', 'wc-blacklist-manager' );

							$is_flagged = ! empty( $matches ) || $reports > 0 || $effective > 0 || $direct > 0 || $linked > 0;

							$match_mode_label      = $this->format_match_mode_label( $match_mode );
							$matched_variant_label = $this->format_matched_variant_label( $matched_variant );
							?>
							<div class="bm-gbl-identity<?php echo $is_flagged ? ' bm-gbl-identity--flagged' : ''; ?>">
								<div class="bm-gbl-identity-header">
									<span class="bm-gbl-identity-type"><?php echo esc_html( $type_label ); ?></span>
									<span class="bm-gbl-risk-badge bm-gbl-risk-<?php echo esc_attr( $risk_slug ); ?>">
										<?php echo esc_html( $risk_label ); ?>
									</span>
								</div>

								<div class="bm-gbl-identity-meta">
									<span><?php printf( esc_html__( 'Reports: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $reports ) ) ); ?></span>
									<span>·</span>
									<span><?php printf( esc_html__( 'Direct: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $direct, 2 ) ) ); ?></span>
									<span>·</span>
									<span><?php printf( esc_html__( 'Linked: +%s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $linked, 2 ) ) ); ?></span>
									<span>·</span>
									<span><?php printf( esc_html__( 'Effective: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $effective, 2 ) ) ); ?></span>
								</div>

								<div class="bm-gbl-identity-meta">
									<span><?php printf( esc_html__( 'Neighbors: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $neighbors ) ) ); ?></span>
									<span>·</span>
									<span><?php printf( esc_html__( 'Last: %s', 'wc-blacklist-manager' ), esc_html( $last ? $last : '—' ) ); ?></span>
								</div>

								<?php if ( 'none' !== $match_mode || $matched_identity_count > 0 ) : ?>
									<div class="bm-gbl-identity-meta bm-gbl-identity-meta--smart">
										<?php if ( 'none' !== $match_mode ) : ?>
											<span><?php printf( esc_html__( 'Match type: %s', 'wc-blacklist-manager' ), esc_html( $match_mode_label ) ); ?></span>
										<?php endif; ?>

										<?php if ( $matched_variant_label ) : ?>
											<span>·</span>
											<span><?php printf( esc_html__( 'Matched detail: %s', 'wc-blacklist-manager' ), esc_html( $matched_variant_label ) ); ?></span>
										<?php endif; ?>

										<?php if ( $matched_identity_count > 0 ) : ?>
											<span>·</span>
											<span><?php printf( esc_html__( 'Related records: %s', 'wc-blacklist-manager' ), esc_html( number_format_i18n( $matched_identity_count ) ) ); ?></span>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $signal_summaries ) ) : ?>
					<h4><?php esc_html_e( 'Why this order was flagged', 'wc-blacklist-manager' ); ?></h4>
					<ul class="bm-gbl-details-list">
						<?php foreach ( $signal_summaries as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $reason_summaries ) ) : ?>
					<h4><?php esc_html_e( 'Match history', 'wc-blacklist-manager' ); ?></h4>
					<ul class="bm-gbl-details-list">
						<?php foreach ( $reason_summaries as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $report_summaries ) ) : ?>
					<h4><?php esc_html_e( 'Past related reports', 'wc-blacklist-manager' ); ?></h4>
					<ul class="bm-gbl-details-list">
						<?php foreach ( $report_summaries as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</details>

			<style>
				.bm-gbl-summary-card {
					margin: 12px 0;
					padding: 12px;
					border: 1px solid #dcdcde;
					border-radius: 6px;
					background: #fff;
				}
				.bm-gbl-summary-card__label {
					font-size: 12px;
					font-weight: 600;
					color: #50575e;
					margin-bottom: 6px;
				}
				.bm-gbl-summary-card__value {
					float: right;
				}
				.bm-gbl-summary-card__text {
					font-size: 13px;
					line-height: 1.5;
				}
				.bm-gbl-merchant-section {
					margin: 12px 0;
				}
				.bm-gbl-merchant-section__title {
					font-weight: 600;
					margin-bottom: 4px;
				}
				.bm-gbl-advanced-details {
					margin-top: 14px;
					padding-top: 10px;
					border-top: 1px solid #e0e0e0;
				}
				.bm-gbl-advanced-details summary {
					cursor: pointer;
					font-weight: 600;
				}
				.bm-gbl-primary-signal {
					margin: 12px 0;
					padding: 10px 12px;
					border: 1px solid #dcdcde;
					border-radius: 4px;
					background: #f6f7f7;
				}
				.bm-gbl-primary-signal__title {
					font-weight: 600;
					margin-bottom: 6px;
				}
				.bm-gbl-primary-signal__meta,
				.bm-gbl-identity-meta {
					font-size: 12px;
					line-height: 1.55;
					color: #50575e;
				}
				.bm-gbl-identity-meta--smart {
					margin-top: 4px;
					padding-top: 4px;
					border-top: 1px dashed #dcdcde;
				}
				.bm-gbl-details-list {
					margin-left: 18px;
					list-style: disc;
				}
			</style>
		</div>
		<?php
	}

	private function map_score_band_label( float $score ) : string {
		if ( $score >= 2.5 ) {
			return __( 'very high', 'wc-blacklist-manager' );
		}

		if ( $score >= 1.2 ) {
			return __( 'elevated', 'wc-blacklist-manager' );
		}

		if ( $score >= 0.5 ) {
			return __( 'moderate', 'wc-blacklist-manager' );
		}

		return __( 'low', 'wc-blacklist-manager' );
	}

	private function normalize_meta_lines( $value ) : array {
		if ( is_string( $value ) ) {
			$value = array( $value );
		} elseif ( ! is_array( $value ) ) {
			return array();
		}

		$value = array_map(
			static function( $line ) {
				return is_scalar( $line ) ? trim( (string) $line ) : '';
			},
			$value
		);

		return array_values(
			array_filter(
				$value,
				static function( $line ) {
					return '' !== $line;
				}
			)
		);
	}

	private function format_identity_type_label( string $type ) : string {
		switch ( strtolower( $type ) ) {
			case 'email':
				return __( 'Email', 'wc-blacklist-manager' );
			case 'phone':
				return __( 'Phone', 'wc-blacklist-manager' );
			case 'ip':
				return __( 'IP address', 'wc-blacklist-manager' );
			case 'address':
				return __( 'Address', 'wc-blacklist-manager' );
			default:
				return ucfirst( $type );
		}
	}

	public function format_match_mode_label( string $mode ) : string {
		switch ( strtolower( $mode ) ) {
			case 'exact':
				return __( 'Exact match', 'wc-blacklist-manager' );

			case 'variant_core':
				return __( 'Main address match', 'wc-blacklist-manager' );

			case 'variant_premise':
				return __( 'Unit / apartment match', 'wc-blacklist-manager' );

			case 'linked':
				return __( 'Related match', 'wc-blacklist-manager' );

			case 'none':
			default:
				return __( 'No match', 'wc-blacklist-manager' );
		}
	}

	public function format_matched_variant_label( string $variant ) : string {
		switch ( strtolower( $variant ) ) {
			case 'submitted':
				return __( 'Submitted details', 'wc-blacklist-manager' );

			case 'core':
				return __( 'Main address', 'wc-blacklist-manager' );

			case 'premise':
				return __( 'Unit / apartment', 'wc-blacklist-manager' );

			case 'full':
				return __( 'Full address', 'wc-blacklist-manager' );

			default:
				return '' !== $variant ? ucfirst( str_replace( '_', ' ', $variant ) ) : '';
		}
	}
}

if ( class_exists( 'WC_Blacklist_Manager_Order_Risk_Score' ) ) {
	new WC_Blacklist_Manager_Order_Risk_Score();
}