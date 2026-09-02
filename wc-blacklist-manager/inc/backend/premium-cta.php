<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Blacklist_Manager_Premium_CTA' ) ) {
	class WC_Blacklist_Manager_Premium_CTA {

		const PAGE_SLUG = 'wc-blacklist-manager-premium';

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'add_submenu' ), 20 );
		}

		private function is_premium_active() {
			return function_exists( 'wc_blacklist_manager_is_premium_available' )
				&& wc_blacklist_manager_is_premium_available();
		}

		private function is_premium_plugin_active() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			return is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		}

		public function add_submenu() {
			if ( $this->is_premium_active() || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			add_submenu_page(
				'wc-blacklist-manager',
				__( 'Advanced Protection', 'wc-blacklist-manager' ),
				__( 'Advanced Protection', 'wc-blacklist-manager' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);

		}

		private function render_check() {
			echo '<span class="dashicons dashicons-yes-alt yobm-check" aria-hidden="true"></span>';
		}

		private function render_dash() {
			echo '<span class="dashicons dashicons-minus yobm-muted-icon" aria-hidden="true"></span>';
		}

		/** Resolve the first-section precedence without treating focus as evidence. */
		private static function resolve_first_section( string $focus, array $evidence ): array {
			$focus_presentation = class_exists( 'WC_Blacklist_Manager_Alert' )
				? WC_Blacklist_Manager_Alert::get_security_focus_presentation( $focus )
				: array();

			if ( ! empty( $focus_presentation ) ) {
				return array(
					'mode'         => 'focus',
					'presentation' => $focus_presentation,
				);
			}

			return array(
				'mode'         => ! empty( $evidence['has_evidence'] ) ? 'evidence' : 'generic',
				'presentation' => array(),
			);
		}

		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
			}

			if ( $this->is_premium_active() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wc-blacklist-manager' ) );
				exit;
			}

			$premium_installed = $this->is_premium_plugin_active();
			$premium_action    = WC_Blacklist_Manager_Commercial_Router::premium_action();
			$docs_url          = WC_Blacklist_Manager_Commercial_Router::docs_url();
			$icon_url          = plugins_url( 'img/icon-256x256.png', WC_BLACKLIST_MANAGER_PLUGIN_FILE );
			$primary_url       = $premium_action['url'];
			$primary_label     = $premium_action['label'];
			$focus             = isset( $_GET['focus'] ) && is_string( $_GET['focus'] )
				? sanitize_key( wp_unslash( $_GET['focus'] ) )
				: '';
			$evidence          = class_exists( 'WC_Blacklist_Manager_Outcome_Summary' )
				? WC_Blacklist_Manager_Outcome_Summary::get_commercial_opportunity_model()
				: array( 'has_evidence' => false );
			$first_section     = self::resolve_first_section( $focus, $evidence );
			$has_evidence      = ! empty( $evidence['has_evidence'] );
			$has_focus         = 'focus' === $first_section['mode'];
			$hero_kicker       = __( 'Premium protection layer', 'wc-blacklist-manager' );
			$hero_title        = __( 'Move from blacklist maintenance to fraud decisioning.', 'wc-blacklist-manager' );
			$hero_body         = __( 'Free handles core local blocking. Premium adds the risk, identity, automation, and audit layers that help busy stores catch repeat abuse before it becomes manual cleanup.', 'wc-blacklist-manager' );
			$coverage_copy     = '';
			$secondary_evidence_copy = '';

			$highlight_cards = array(
				array(
					'icon'  => 'dashicons-shield-alt',
					'title' => __( 'Risk scoring', 'wc-blacklist-manager' ),
					'body'  => __( 'Combine order, identity, device, address, and payment signals into decisions your team can act on.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'  => 'dashicons-networking',
					'title' => __( 'Repeat-abuse detection', 'wc-blacklist-manager' ),
					'body'  => __( 'Catch customers who rotate emails, phones, IPs, or addresses but keep the same behavior patterns.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'  => 'dashicons-clipboard',
					'title' => __( 'Operational history', 'wc-blacklist-manager' ),
					'body'  => __( 'Keep blacklist changes, verification checks, automation events, and order decisions reviewable.', 'wc-blacklist-manager' ),
				),
			);

			if ( $has_evidence ) {
				$manual_actions  = (int) $evidence['manual_actions'];
				$list_actions    = (int) $evidence['list_actions'];
				$order_decisions = (int) $evidence['order_decisions'];
				$hero_kicker     = __( 'Based on recorded site-local work', 'wc-blacklist-manager' );
				$hero_title      = sprintf(
					_n( 'Your store recorded %s manual protection action in the last 30 days.', 'Your store recorded %s manual protection actions in the last 30 days.', $manual_actions, 'wc-blacklist-manager' ),
					number_format_i18n( $manual_actions )
				);
				$hero_body       = sprintf(
					/* translators: 1: manual list action count, 2: manual order decision count. */
					__( 'This includes %1$s recorded list actions and %2$s recorded order decisions. Premium may help reduce repeated manual work and add decision context; the counts do not represent attacks or prevented fraud.', 'wc-blacklist-manager' ),
					number_format_i18n( $list_actions ),
					number_format_i18n( $order_decisions )
				);
				$tracking_date   = wp_date( get_option( 'date_format' ), (int) $evidence['tracking_started_at'], wp_timezone() );
				$coverage_copy   = ! empty( $evidence['complete_30'] )
					? sprintf( __( 'Manual actions have been tracked since %s. Records can still be partial after deletion or retention.', 'wc-blacklist-manager' ), $tracking_date )
					: sprintf( __( 'Tracking started on %s, so this 30-day view has partial coverage. Records can also be partial after deletion or retention.', 'wc-blacklist-manager' ), $tracking_date );

				$recommendation_catalog = array(
					'manual_work_automation' => array(
						'icon'  => 'dashicons-controls-repeat',
						'title' => __( 'Reduce repeated manual work', 'wc-blacklist-manager' ),
						'body'  => __( 'Premium automation may reduce repetitive list and order actions. Review its settings before enabling automated decisions.', 'wc-blacklist-manager' ),
					),
					'order_risk_scoring' => array(
						'icon'  => 'dashicons-chart-line',
						'title' => __( 'Add context to order decisions', 'wc-blacklist-manager' ),
						'body'  => __( 'Recorded manual order decisions indicate a workflow that may benefit from risk scoring and consistent review thresholds.', 'wc-blacklist-manager' ),
					),
					'activity_history' => array(
						'icon'  => 'dashicons-clipboard',
						'title' => __( 'Keep decisions reviewable', 'wc-blacklist-manager' ),
						'body'  => __( 'Activity history can add investigation context around recurring manual list work without changing existing Core protection.', 'wc-blacklist-manager' ),
					),
				);
				$highlight_cards = array();
				foreach ( $evidence['recommendations'] as $recommendation_id ) {
					if ( isset( $recommendation_catalog[ $recommendation_id ] ) ) {
						$highlight_cards[] = $recommendation_catalog[ $recommendation_id ];
					}
				}
			}

			$focus_presentation = $first_section['presentation'];
			if ( $has_focus ) {
				$hero_kicker = __( 'Security protection focus', 'wc-blacklist-manager' );
				$hero_title  = $focus_presentation['title'];
				$hero_body   = $focus_presentation['body'];
				$highlight_cards = $focus_presentation['cards'];

				if ( $has_evidence ) {
					$secondary_evidence_copy = sprintf(
						/* translators: %s: number of recorded manual protection actions. */
						_n( 'Separately, your store recorded %s manual protection action in the last 30 days.', 'Separately, your store recorded %s manual protection actions in the last 30 days.', (int) $evidence['manual_actions'], 'wc-blacklist-manager' ),
						number_format_i18n( (int) $evidence['manual_actions'] )
					);
					$secondary_evidence_copy .= ' ' . $coverage_copy;
				}

				$coverage_copy = __( 'This focus is explanatory only. It does not confirm a live attack or show that Premium prevented or would have prevented any observed event.', 'wc-blacklist-manager' );
			}

			$workflow_steps = array(
				array(
					'step'  => '01',
					'title' => __( 'Detect', 'wc-blacklist-manager' ),
					'body'  => __( 'Identify suspicious checkout, form, device, address, and payment patterns earlier.', 'wc-blacklist-manager' ),
				),
				array(
					'step'  => '02',
					'title' => __( 'Score', 'wc-blacklist-manager' ),
					'body'  => __( 'Turn scattered risk signals into thresholds for review, suspect, or block actions.', 'wc-blacklist-manager' ),
				),
				array(
					'step'  => '03',
					'title' => __( 'Act', 'wc-blacklist-manager' ),
					'body'  => __( 'Automate repeat decisions and reduce the manual work of maintaining local lists.', 'wc-blacklist-manager' ),
				),
				array(
					'step'  => '04',
					'title' => __( 'Review', 'wc-blacklist-manager' ),
					'body'  => __( 'Use activity logs and order context to audit what happened and tune future rules.', 'wc-blacklist-manager' ),
				),
			);

			$comparison_rows = array(
				array(
					'capability' => __( 'Local email and phone blocklists', 'wc-blacklist-manager' ),
					'free'       => __( 'Included', 'wc-blacklist-manager' ),
					'premium'    => __( 'Included with automation and activity history', 'wc-blacklist-manager' ),
					'free_ok'    => true,
				),
				array(
					'capability' => __( 'IP and domain controls', 'wc-blacklist-manager' ),
					'free'       => __( 'Basic checkout, registration, comment, and form rules', 'wc-blacklist-manager' ),
					'premium'    => __( 'Adds proxy, VPN, TOR, country, hosting IP, and high-risk domain intelligence', 'wc-blacklist-manager' ),
					'free_ok'    => true,
				),
				array(
					'capability' => __( 'Customer identity matching', 'wc-blacklist-manager' ),
					'free'       => __( 'Email, phone, IP, and domain', 'wc-blacklist-manager' ),
					'premium'    => __( 'Adds name, device identity, billing address, and shipping address matching', 'wc-blacklist-manager' ),
					'free_ok'    => true,
				),
				array(
					'capability' => __( 'Risk scoring and automation', 'wc-blacklist-manager' ),
					'free'       => __( 'Manual review from local matches', 'wc-blacklist-manager' ),
					'premium'    => __( 'Scoring thresholds, payment intelligence, and automatic order actions', 'wc-blacklist-manager' ),
					'free_ok'    => false,
				),
				array(
					'capability' => __( 'Audit trail and activity logs', 'wc-blacklist-manager' ),
					'free'       => __( 'Limited', 'wc-blacklist-manager' ),
					'premium'    => __( 'Detailed event history for blacklist, verification, automation, and order decisions', 'wc-blacklist-manager' ),
					'free_ok'    => false,
				),
				array(
					'capability' => __( 'Advanced integrations', 'wc-blacklist-manager' ),
					'free'       => __( 'Core WooCommerce and supported form protections', 'wc-blacklist-manager' ),
					'premium'    => __( 'Captcha, SMS and phone providers, payment gateway intelligence, and multi-store connection tools', 'wc-blacklist-manager' ),
					'free_ok'    => false,
				),
			);
			?>
			<div class="wrap yobm-premium-cta">
				<style>
					.yobm-premium-cta {
						max-width: 1200px;
					}
					.yobm-premium-cta * {
						box-sizing: border-box;
					}
					.yobm-premium-shell {
						margin-top: 18px;
						color: #171820;
					}
					.yobm-premium-hero {
						position: relative;
						display: grid;
						grid-template-columns: minmax(0, 1.3fr) minmax(320px, .7fr);
						gap: 24px;
						padding: 30px;
						overflow: hidden;
						background: #171820;
						border: 1px solid #2a2c35;
						border-top: 5px solid #ef343d;
						border-radius: 8px;
						box-shadow: 0 18px 38px rgba(15, 17, 24, .18);
					}
					.yobm-premium-kicker {
						display: inline-flex;
						gap: 8px;
						align-items: center;
						margin-bottom: 14px;
						padding: 6px 10px;
						color: #ff6b72;
						background: rgba(239, 52, 61, .12);
						border: 1px solid rgba(239, 52, 61, .32);
						border-radius: 999px;
						font-size: 12px;
						font-weight: 700;
						text-transform: uppercase;
					}
					.yobm-premium-hero h1 {
						max-width: 760px;
						margin: 0 0 12px;
						color: #ffffff;
						font-size: 32px;
						line-height: 1.18;
					}
					.yobm-premium-hero p {
						max-width: 760px;
						margin: 0 0 20px;
						color: #c9ccd4;
						font-size: 15px;
						line-height: 1.65;
					}
					.yobm-premium-hero .yobm-premium-evidence-coverage {
						margin-top: -8px;
						font-size: 13px;
						color: #aeb3be;
					}
					.yobm-premium-actions {
						display: flex;
						flex-wrap: wrap;
						gap: 10px;
						align-items: center;
					}
					.yobm-premium-actions .button {
						display: inline-flex;
						gap: 7px;
						min-height: 42px;
						line-height: 1;
					}
					.yobm-premium-actions .button .dashicons {
						display: inline-block;
						width: 18px;
						height: 18px;
						flex: 0 0 18px;
						margin: 0;
						color: inherit;
						font-size: 18px;
						line-height: 18px;
						vertical-align: middle;
					}
					.yobm-premium-actions .button-primary,
					.yobm-premium-footer .button-primary {
						background: #ef343d;
						border-color: #ef343d;
						color: #fff;
					}
					.yobm-premium-actions .button-primary:hover,
					.yobm-premium-footer .button-primary:hover {
						background: #d92832;
						border-color: #d92832;
					}
					.yobm-premium-actions .button-secondary {
						color: #f4f5f7;
						background: rgba(255, 255, 255, .08);
						border-color: rgba(255, 255, 255, .42);
					}
					.yobm-premium-actions .button-secondary:hover,
					.yobm-premium-actions .button-secondary:focus {
						color: #ffffff;
						background: rgba(255, 255, 255, .14);
						border-color: rgba(255, 255, 255, .62);
					}
					.yobm-premium-status {
						position: relative;
						z-index: 1;
						display: grid;
						gap: 14px;
						align-content: start;
						padding: 18px;
						background: rgba(255, 255, 255, .06);
						border: 1px solid rgba(255, 255, 255, .14);
						border-radius: 8px;
					}
					.yobm-premium-brand {
						display: grid;
						grid-template-columns: 76px minmax(0, 1fr);
						gap: 14px;
						align-items: center;
						padding-bottom: 14px;
						border-bottom: 1px solid rgba(255, 255, 255, .12);
					}
					.yobm-premium-brand img {
						width: 76px;
						height: 76px;
						border-radius: 18px;
						box-shadow: 0 14px 26px rgba(0, 0, 0, .35);
					}
					.yobm-premium-brand strong {
						display: block;
						color: #fff;
						font-size: 16px;
						line-height: 1.3;
					}
					.yobm-premium-brand span {
						display: block;
						margin-top: 4px;
						color: #b7bac4;
						line-height: 1.45;
					}
					.yobm-premium-status-row {
						display: flex;
						justify-content: space-between;
						gap: 12px;
						align-items: center;
						padding-bottom: 12px;
						border-bottom: 1px solid rgba(255, 255, 255, .12);
					}
					.yobm-premium-status-row:last-child {
						padding-bottom: 0;
						border-bottom: 0;
					}
					.yobm-premium-status-label {
						color: #aeb3be;
						font-size: 12px;
						font-weight: 700;
						text-transform: uppercase;
					}
					.yobm-premium-badge {
						display: inline-flex;
						align-items: center;
						min-height: 26px;
						padding: 4px 9px;
						color: #ffd6d9;
						background: rgba(239, 52, 61, .14);
						border: 1px solid rgba(239, 52, 61, .34);
						border-radius: 999px;
						font-size: 12px;
						font-weight: 700;
					}
					.yobm-premium-badge.is-installed {
						color: #e8edf5;
						background: rgba(255, 255, 255, .1);
						border-color: rgba(255, 255, 255, .18);
					}
					.yobm-premium-status-row strong {
						color: #fff;
					}
					.yobm-premium-value-grid {
						display: grid;
						grid-template-columns: repeat(3, minmax(0, 1fr));
						gap: 16px;
						margin: 18px 0;
					}
					.yobm-premium-value-grid.is-evidence {
						grid-template-columns: repeat(2, minmax(0, 1fr));
					}
					.yobm-premium-card {
						display: flex;
						gap: 14px;
						min-height: 142px;
						padding: 18px;
						background: #fff;
						border: 1px solid #dedfe4;
						border-radius: 8px;
						box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
					}
					.yobm-premium-icon {
						display: inline-flex;
						width: 38px;
						height: 38px;
						flex: 0 0 38px;
						align-items: center;
						justify-content: center;
						color: var(--wp-admin-theme-color, #2271b1);
						background: #f6f7f7;
						border-radius: 8px;
					}
					.yobm-premium-icon .dashicons {
						width: 20px;
						height: 20px;
						font-size: 20px;
					}
					.yobm-premium-card h2,
					.yobm-premium-card h3 {
						margin: 0 0 8px;
						font-size: 15px;
						line-height: 1.35;
					}
					.yobm-premium-card p {
						margin: 0;
						color: #4b5563;
						line-height: 1.55;
					}
					.yobm-premium-section {
						margin-top: 24px;
					}
					.yobm-premium-section-head {
						display: flex;
						justify-content: space-between;
						gap: 16px;
						align-items: flex-end;
						margin-bottom: 12px;
					}
					.yobm-premium-section-head h2 {
						margin: 0;
						font-size: 20px;
					}
					.yobm-premium-section-head p {
						max-width: 620px;
						margin: 0;
						color: #646970;
						line-height: 1.5;
					}
					.yobm-premium-workflow {
						display: grid;
						grid-template-columns: repeat(4, minmax(0, 1fr));
						gap: 12px;
					}
					.yobm-premium-step {
						padding: 16px;
						background: #20222b;
						color: #d8dbe3;
						border-radius: 8px;
					}
					.yobm-premium-step-number {
						display: inline-flex;
						margin-bottom: 12px;
						color: #ff6b72;
						font-weight: 700;
					}
					.yobm-premium-step h3 {
						margin: 0 0 8px;
						color: #fff;
						font-size: 15px;
					}
					.yobm-premium-step p {
						margin: 0;
						line-height: 1.55;
					}
					.yobm-premium-table-wrap {
						overflow: hidden;
						background: #fff;
						border: 1px solid #dcdcde;
						border-radius: 8px;
						box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
					}
					.yobm-premium-table {
						width: 100%;
						border-collapse: collapse;
					}
					.yobm-premium-table th,
					.yobm-premium-table td {
						padding: 15px 16px;
						text-align: left;
						vertical-align: top;
						border-bottom: 1px solid #e5e7eb;
					}
					.yobm-premium-table th {
						color: #374151;
						background: #f8fafc;
						font-weight: 700;
					}
					.yobm-premium-table tr:last-child td {
						border-bottom: 0;
					}
					.yobm-premium-table td:first-child {
						width: 26%;
						font-weight: 700;
						color: #1f2937;
					}
					.yobm-premium-cell {
						display: grid;
						grid-template-columns: 22px minmax(0, 1fr);
						gap: 8px;
						align-items: start;
						color: #4b5563;
					}
					.yobm-premium-cell.is-strong {
						color: #c7252e;
						font-weight: 700;
					}
					.yobm-check {
						color: #ef343d;
					}
					.yobm-muted-icon {
						color: #8c8f94;
					}
					.yobm-premium-footer {
						margin-top: 24px;
						padding: 20px;
						background: #f8fafc;
						border: 1px solid #dcdcde;
						border-left: 5px solid #ef343d;
						border-radius: 8px;
					}
					.yobm-premium-footer h2 {
						margin: 0 0 6px;
						font-size: 17px;
					}
					.yobm-premium-footer p {
						margin: 0;
						color: #4b5563;
						line-height: 1.55;
					}
					@media (max-width: 1000px) {
						.yobm-premium-hero,
						.yobm-premium-value-grid,
						.yobm-premium-workflow,
						.yobm-premium-footer {
							grid-template-columns: 1fr;
						}
						.yobm-premium-section-head {
							align-items: flex-start;
							flex-direction: column;
						}
					}
					@media (max-width: 782px) {
						.yobm-premium-hero {
							padding: 22px;
						}
						.yobm-premium-hero h1 {
							font-size: 24px;
						}
						.yobm-premium-table {
							min-width: 760px;
						}
						.yobm-premium-table-wrap {
							overflow-x: auto;
						}
					}
				</style>

				<div class="yobm-premium-shell">
					<section class="yobm-premium-hero">
						<div>
							<span class="yobm-premium-kicker">
								<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
								<?php echo esc_html( $hero_kicker ); ?>
							</span>
							<h1><?php echo esc_html( $hero_title ); ?></h1>
							<p><?php echo esc_html( $hero_body ); ?></p>
							<?php if ( $coverage_copy ) : ?>
								<p class="yobm-premium-evidence-coverage"><?php echo esc_html( $coverage_copy ); ?></p>
							<?php endif; ?>
							<?php if ( $secondary_evidence_copy ) : ?>
								<p class="yobm-premium-secondary-evidence"><?php echo esc_html( $secondary_evidence_copy ); ?></p>
							<?php endif; ?>
							<div class="yobm-premium-actions">
								<a
									class="button button-primary button-hero"
									href="<?php echo esc_url( $primary_url ); ?>"
									<?php echo ! empty( $premium_action['external'] ) ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
								>
									<span class="dashicons <?php echo $premium_installed ? 'dashicons-admin-network' : 'dashicons-external'; ?>" aria-hidden="true"></span>
									<?php echo esc_html( $primary_label ); ?>
								</a>
								<a class="button button-secondary button-hero" href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer">
									<span class="dashicons dashicons-book" aria-hidden="true"></span>
									<?php esc_html_e( 'Read docs', 'wc-blacklist-manager' ); ?>
								</a>
							</div>
							<?php if ( ! empty( $premium_action['post_purchase'] ) ) : ?>
								<p><?php echo esc_html( $premium_action['post_purchase'] ); ?></p>
							<?php endif; ?>
						</div>

						<aside class="yobm-premium-status" aria-label="<?php esc_attr_e( 'Premium status', 'wc-blacklist-manager' ); ?>">
							<div class="yobm-premium-brand">
								<img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php esc_attr_e( 'Blacklist Manager', 'wc-blacklist-manager' ); ?>" />
								<div>
									<strong><?php esc_html_e( 'Blacklist Manager Premium', 'wc-blacklist-manager' ); ?></strong>
									<span><?php esc_html_e( 'Advanced protection for stores that need stronger fraud decisions.', 'wc-blacklist-manager' ); ?></span>
								</div>
							</div>
							<div class="yobm-premium-status-row">
								<span class="yobm-premium-status-label"><?php esc_html_e( 'Add-on', 'wc-blacklist-manager' ); ?></span>
								<span class="yobm-premium-badge <?php echo $premium_installed ? 'is-installed' : ''; ?>">
									<?php echo esc_html( $premium_installed ? __( 'Installed', 'wc-blacklist-manager' ) : __( 'Not installed', 'wc-blacklist-manager' ) ); ?>
								</span>
							</div>
							<div class="yobm-premium-status-row">
								<span class="yobm-premium-status-label"><?php esc_html_e( 'License', 'wc-blacklist-manager' ); ?></span>
								<span class="yobm-premium-badge"><?php esc_html_e( 'Needs activation', 'wc-blacklist-manager' ); ?></span>
							</div>
							<div class="yobm-premium-status-row">
								<span class="yobm-premium-status-label"><?php esc_html_e( 'Best next step', 'wc-blacklist-manager' ); ?></span>
								<strong><?php echo esc_html( $premium_installed ? __( 'Activate the license', 'wc-blacklist-manager' ) : __( 'Explore the Premium add-on', 'wc-blacklist-manager' ) ); ?></strong>
							</div>
						</aside>
					</section>

					<div class="yobm-premium-value-grid <?php echo ( $has_evidence || $has_focus ) ? 'is-evidence' : ''; ?>">
						<?php foreach ( $highlight_cards as $card ) : ?>
							<article class="yobm-premium-card">
								<span class="yobm-premium-icon">
									<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
								</span>
								<div>
									<h2><?php echo esc_html( $card['title'] ); ?></h2>
									<p><?php echo esc_html( $card['body'] ); ?></p>
								</div>
							</article>
						<?php endforeach; ?>
					</div>

					<section class="yobm-premium-section">
						<div class="yobm-premium-section-head">
							<div>
								<h2><?php esc_html_e( 'What Premium changes in the workflow', 'wc-blacklist-manager' ); ?></h2>
							</div>
							<p><?php esc_html_e( 'The value is not just more settings. Premium connects more signals into a repeatable process: detect, score, act, and review.', 'wc-blacklist-manager' ); ?></p>
						</div>
						<div class="yobm-premium-workflow">
							<?php foreach ( $workflow_steps as $step ) : ?>
								<article class="yobm-premium-step">
									<span class="yobm-premium-step-number"><?php echo esc_html( $step['step'] ); ?></span>
									<h3><?php echo esc_html( $step['title'] ); ?></h3>
									<p><?php echo esc_html( $step['body'] ); ?></p>
								</article>
							<?php endforeach; ?>
						</div>
					</section>

					<section class="yobm-premium-section">
						<div class="yobm-premium-section-head">
							<div>
								<h2><?php esc_html_e( 'Free vs Premium', 'wc-blacklist-manager' ); ?></h2>
							</div>
							<p><?php esc_html_e( 'Free remains useful for direct local blocking. Premium is for stores that need richer identity matching, automation, and investigation context.', 'wc-blacklist-manager' ); ?></p>
						</div>
						<div class="yobm-premium-table-wrap">
							<table class="yobm-premium-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Capability', 'wc-blacklist-manager' ); ?></th>
										<th><?php esc_html_e( 'Free', 'wc-blacklist-manager' ); ?></th>
										<th><?php esc_html_e( 'Premium', 'wc-blacklist-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $comparison_rows as $row ) : ?>
										<tr>
											<td><?php echo esc_html( $row['capability'] ); ?></td>
											<td>
												<span class="yobm-premium-cell">
													<?php $row['free_ok'] ? $this->render_check() : $this->render_dash(); ?>
													<span><?php echo esc_html( $row['free'] ); ?></span>
												</span>
											</td>
											<td>
												<span class="yobm-premium-cell is-strong">
													<?php $this->render_check(); ?>
													<span><?php echo esc_html( $row['premium'] ); ?></span>
												</span>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>

					<section class="yobm-premium-footer">
						<div>
							<h2><?php esc_html_e( 'Use Free for local lists. Use Premium when patterns keep changing.', 'wc-blacklist-manager' ); ?></h2>
							<p><?php esc_html_e( 'If blocked customers are rotating details, fraud review is taking too long, or you need clear history for decisions, Premium is the better operating mode.', 'wc-blacklist-manager' ); ?></p>
						</div>
					</section>
				</div>
			</div>
			<?php
		}
	}
}

new WC_Blacklist_Manager_Premium_CTA();
