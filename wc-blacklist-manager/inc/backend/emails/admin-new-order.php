<?php

if (!defined('ABSPATH')) {
	exit;
}

class YOGB_BM_Admin_Email_New_Order {

	public function __construct() {
		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled ) {
			return;
		}
		
		add_action( 'woocommerce_email_before_order_table', [ $this, 'render_gbl_email_summary' ], 15, 4 );
	}

	public function render_gbl_email_summary( $order, $sent_to_admin, $plain_text, $email ) {
		// Only show in admin emails.
		if ( ! $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		// Only for admin new order email.
		if ( isset( $email->id ) && 'new_order' !== $email->id ) {
			return;
		}

		$decision = (string) $order->get_meta( '_yogb_gbl_decision', true );
		if ( '' === $decision ) {
			return;
		}

		$tier = (string) $order->get_meta( '_yogb_gbl_tier', true );
		$tier = '' !== $tier ? strtolower( $tier ) : 'free';

		$upgrade_url = 'https://yoohw.com/global-blacklist-plan/';

		$coverage_cta = '';
		if ( in_array( $tier, array( 'free', 'basic' ), true ) ) {
			$coverage_cta = __( 'Upgrade your plan to include more identity checks.', 'wc-blacklist-manager' );
		}

		$limit_cta = '';
		if ( 'skipped_rate_limit' === strtolower( $decision ) ) {
			$limit_cta = __( 'Your monthly Global Blacklist check limit has been reached. Upgrade to a higher plan for more checks.', 'wc-blacklist-manager' );
		}

		$effective       = (float) $order->get_meta( '_yogb_gbl_effective_score', true );
		$direct          = (float) $order->get_meta( '_yogb_gbl_direct_score', true );
		$linked          = (float) $order->get_meta( '_yogb_gbl_linked_boost', true );
		$neighbors       = (int) $order->get_meta( '_yogb_gbl_linked_neighbors_count', true );
		$primary_type    = (string) $order->get_meta( '_yogb_gbl_primary_signal_type', true );
		$primary_risk    = (string) $order->get_meta( '_yogb_gbl_primary_risk_level', true );
		$match_mode      = (string) $order->get_meta( '_yogb_gbl_primary_match_mode', true );
		$match_variant   = (string) $order->get_meta( '_yogb_gbl_primary_matched_variant', true );
		$match_nodes     = (int) $order->get_meta( '_yogb_gbl_primary_matched_identity_count', true );
		$matched_checks  = (int) $order->get_meta( '_yogb_gbl_matched_identities', true );
		$last_reported   = (string) $order->get_meta( '_yogb_gbl_primary_last_reported', true );
		$reason_summaries = $this->normalize_meta_lines( $order->get_meta( '_yogb_gbl_reason_summaries', true ) );
		$signal_summaries = $this->normalize_meta_lines( $order->get_meta( '_yogb_gbl_signal_summaries', true ) );

		$decision_meta = $this->get_decision_meta( $decision );
		$decision_label = $decision_meta['label'];
		$badge_bg       = $decision_meta['badge_bg'];
		$badge_text     = $decision_meta['badge_text'];
		$panel_border   = $decision_meta['panel_border'];
		$panel_bg       = $decision_meta['panel_bg'];
		$accent         = $decision_meta['accent'];
		$notice_text    = $decision_meta['notice'];

		$type_label    = $this->format_identity_type_label( $primary_type );
		$risk_label    = $primary_risk ? ucfirst( $primary_risk ) : '';
		$mode_label    = $this->format_match_mode_label( $match_mode );
		$variant_label = $this->format_matched_variant_label( $match_variant );

		$main_signal_text = '';
		if ( $primary_type ) {
			$main_signal_text = $type_label;
			if ( $risk_label ) {
				$main_signal_text .= ' (' . $risk_label . ')';
			}
		}

		$summary_line = '';
		if ( ! empty( $reason_summaries ) ) {
			$summary_line = $reason_summaries[0];
		} elseif ( ! empty( $signal_summaries ) ) {
			$summary_line = $signal_summaries[0];
		} elseif ( $main_signal_text ) {
			$summary_line = sprintf(
				__( 'The strongest signal for this order is %s.', 'wc-blacklist-manager' ),
				$main_signal_text
			);
		}

		if ( $plain_text ) {
			$this->render_plain_text_summary(
				$decision_label,
				$notice_text,
				$main_signal_text,
				$mode_label,
				$variant_label,
				$match_nodes,
				$matched_checks,
				$direct,
				$linked,
				$effective,
				$neighbors,
				$last_reported,
				$summary_line,
				$limit_cta,
				$coverage_cta,
				$upgrade_url
			);
			return;
		}

		$this->render_html_summary(
			$decision_label,
			$badge_bg,
			$badge_text,
			$panel_border,
			$panel_bg,
			$accent,
			$notice_text,
			$main_signal_text,
			$mode_label,
			$variant_label,
			$match_nodes,
			$matched_checks,
			$direct,
			$linked,
			$effective,
			$neighbors,
			$last_reported,
			$summary_line,
			$limit_cta,
			$coverage_cta,
			$upgrade_url
		);
	}

	private function render_plain_text_summary(
		string $decision_label,
		string $notice_text,
		string $main_signal_text,
		string $mode_label,
		string $variant_label,
		int $match_nodes,
		int $matched_checks,
		float $direct,
		float $linked,
		float $effective,
		int $neighbors,
		string $last_reported,
		string $summary_line,
		string $limit_cta,
		string $coverage_cta,
		string $upgrade_url
	) : void {
		echo "\n";
		echo "====================================\n";
		echo strtoupper( __( 'Global Blacklist Check', 'wc-blacklist-manager' ) ) . "\n";
		echo "====================================\n";
		echo sprintf( __( 'Result: %s', 'wc-blacklist-manager' ), $decision_label ) . "\n";

		if ( '' !== $notice_text ) {
			echo $notice_text . "\n";
		}

		if ( '' !== $main_signal_text ) {
			echo sprintf( __( 'Main signal: %s', 'wc-blacklist-manager' ), $main_signal_text ) . "\n";
		}

		if ( '' !== $mode_label && __( 'No match', 'wc-blacklist-manager' ) !== $mode_label ) {
			echo sprintf( __( 'Match type: %s', 'wc-blacklist-manager' ), $mode_label ) . "\n";
		}

		if ( '' !== $variant_label ) {
			echo sprintf( __( 'Matched detail: %s', 'wc-blacklist-manager' ), $variant_label ) . "\n";
		}

		if ( $match_nodes > 0 ) {
			echo sprintf( __( 'Related records: %d', 'wc-blacklist-manager' ), $match_nodes ) . "\n";
		}

		if ( $matched_checks > 0 ) {
			echo sprintf( __( 'Matched checks: %d', 'wc-blacklist-manager' ), $matched_checks ) . "\n";
		}

		if ( $effective > 0 || $direct > 0 || $linked > 0 ) {
			echo sprintf(
				__( 'Scores: Direct %1$s | Related +%2$s | Effective %3$s', 'wc-blacklist-manager' ),
				number_format_i18n( $direct, 2 ),
				number_format_i18n( $linked, 2 ),
				number_format_i18n( $effective, 2 )
			) . "\n";
		}

		if ( $neighbors > 0 ) {
			echo sprintf( __( 'Neighbors: %d', 'wc-blacklist-manager' ), $neighbors ) . "\n";
		}

		if ( '' !== $last_reported ) {
			echo sprintf( __( 'Last reported: %s', 'wc-blacklist-manager' ), $last_reported ) . "\n";
		}

		if ( '' !== $summary_line ) {
			echo sprintf( __( 'Summary: %s', 'wc-blacklist-manager' ), wp_strip_all_tags( $summary_line ) ) . "\n";
		}

		if ( '' !== $limit_cta ) {
			echo "\n";
			echo sprintf( __( 'Plan notice: %s', 'wc-blacklist-manager' ), $limit_cta ) . "\n";
			echo sprintf( __( 'Upgrade: %s', 'wc-blacklist-manager' ), $upgrade_url ) . "\n";
		}

		if ( '' !== $coverage_cta ) {
			echo "\n";
			echo sprintf( __( 'Coverage notice: %s', 'wc-blacklist-manager' ), $coverage_cta ) . "\n";
			echo sprintf( __( 'Upgrade: %s', 'wc-blacklist-manager' ), $upgrade_url ) . "\n";
		}

		echo "\n";
	}

	private function render_html_summary(
		string $decision_label,
		string $badge_bg,
		string $badge_text,
		string $panel_border,
		string $panel_bg,
		string $accent,
		string $notice_text,
		string $main_signal_text,
		string $mode_label,
		string $variant_label,
		int $match_nodes,
		int $matched_checks,
		float $direct,
		float $linked,
		float $effective,
		int $neighbors,
		string $last_reported,
		string $summary_line,
		string $limit_cta,
		string $coverage_cta,
		string $upgrade_url
	) : void {
		?>
		<div style="margin:20px 0 24px 0; border:1px solid <?php echo esc_attr( $panel_border ); ?>; background:<?php echo esc_attr( $panel_bg ); ?>; border-radius:10px; overflow:hidden;">
			<div style="padding:14px 16px; border-bottom:1px solid <?php echo esc_attr( $panel_border ); ?>; background:#ffffff;">
				<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
					<tr>
						<td style="text-align:left; vertical-align:middle;">
							<div style="font-size:16px; font-weight:700; color:#1d2327;">
								<?php esc_html_e( 'Global Blacklist Check', 'wc-blacklist-manager' ); ?>
							</div>
							<?php if ( '' !== $notice_text ) : ?>
								<div style="margin-top:4px; font-size:13px; line-height:1.5; color:#50575e;">
									<?php echo esc_html( $notice_text ); ?>
								</div>
							<?php endif; ?>
						</td>
						<td style="text-align:right; vertical-align:middle;">
							<span style="display:inline-block; width: max-content; padding:7px 12px; border-radius:999px; background:<?php echo esc_attr( $badge_bg ); ?>; color:<?php echo esc_attr( $badge_text ); ?>; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">
								<?php echo esc_html( $decision_label ); ?>
							</span>
						</td>
					</tr>
				</table>
			</div>

			<div style="padding:16px;">
				<?php if ( '' !== $summary_line ) : ?>
					<div style="margin:0 0 14px 0; padding:12px 14px; border-left:4px solid <?php echo esc_attr( $accent ); ?>; background:#ffffff; color:#1d2327; font-size:14px; line-height:1.6;">
						<?php echo esc_html( wp_strip_all_tags( $summary_line ) ); ?>
					</div>
				<?php endif; ?>

				<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
					<?php if ( '' !== $main_signal_text ) : ?>
						<tr>
							<td style="padding:0 0 10px 0; font-size:13px; color:#50575e; width:150px;">
								<?php esc_html_e( 'Main signal', 'wc-blacklist-manager' ); ?>
							</td>
							<td style="padding:0 0 10px 0; font-size:14px; color:#1d2327; font-weight:600;">
								<?php echo esc_html( $main_signal_text ); ?>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( '' !== $mode_label && __( 'No match', 'wc-blacklist-manager' ) !== $mode_label ) : ?>
						<tr>
							<td style="padding:0 0 10px 0; font-size:13px; color:#50575e;">
								<?php esc_html_e( 'Match type', 'wc-blacklist-manager' ); ?>
							</td>
							<td style="padding:0 0 10px 0; font-size:14px; color:#1d2327;">
								<?php echo esc_html( $mode_label ); ?>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( '' !== $variant_label ) : ?>
						<tr>
							<td style="padding:0 0 10px 0; font-size:13px; color:#50575e;">
								<?php esc_html_e( 'Matched detail', 'wc-blacklist-manager' ); ?>
							</td>
							<td style="padding:0 0 10px 0; font-size:14px; color:#1d2327;">
								<?php echo esc_html( $variant_label ); ?>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( $last_reported ) : ?>
						<tr>
							<td style="padding:0 0 10px 0; font-size:13px; color:#50575e;">
								<?php esc_html_e( 'Last reported', 'wc-blacklist-manager' ); ?>
							</td>
							<td style="padding:0 0 10px 0; font-size:14px; color:#1d2327;">
								<?php echo esc_html( $last_reported ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php
				$chips = [];

				if ( $match_nodes > 0 ) {
					$chips[] = [
						'label' => __( 'Related records', 'wc-blacklist-manager' ),
						'value' => number_format_i18n( $match_nodes ),
					];
				}

				if ( $matched_checks > 0 ) {
					$chips[] = [
						'label' => __( 'Matched checks', 'wc-blacklist-manager' ),
						'value' => number_format_i18n( $matched_checks ),
					];
				}

				if ( $neighbors > 0 ) {
					$chips[] = [
						'label' => __( 'Neighbors', 'wc-blacklist-manager' ),
						'value' => number_format_i18n( $neighbors ),
					];
				}
				?>

				<?php if ( ! empty( $chips ) ) : ?>
					<div style="margin:6px 0 14px 0;">
						<?php foreach ( $chips as $chip ) : ?>
							<span style="display:inline-block; margin:0 8px 8px 0; padding:8px 10px; background:#ffffff; border:1px solid #dcdcde; border-radius:7px; font-size:12px; line-height:1.4; color:#50575e;">
								<span style="display:block; font-weight:600; color:#1d2327;"><?php echo esc_html( $chip['value'] ); ?></span>
								<span><?php echo esc_html( $chip['label'] ); ?></span>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $effective > 0 || $direct > 0 || $linked > 0 ) : ?>
					<div style="margin-top:4px; padding:12px 14px; background:#ffffff; border:1px solid #dcdcde; border-radius:8px;">
						<div style="margin:0 0 8px 0; font-size:13px; font-weight:700; color:#1d2327;">
							<?php esc_html_e( 'Score summary', 'wc-blacklist-manager' ); ?>
						</div>

						<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
							<tr>
								<td style="padding:0 10px 0 0; font-size:13px; color:#50575e;">
									<?php esc_html_e( 'Direct', 'wc-blacklist-manager' ); ?>
								</td>
								<td style="padding:0 18px 0 0; font-size:14px; font-weight:600; color:#1d2327;">
									<?php echo esc_html( number_format_i18n( $direct, 2 ) ); ?>
								</td>

								<td style="padding:0 10px 0 0; font-size:13px; color:#50575e;">
									<?php esc_html_e( 'Related', 'wc-blacklist-manager' ); ?>
								</td>
								<td style="padding:0 18px 0 0; font-size:14px; font-weight:600; color:#1d2327;">
									+<?php echo esc_html( number_format_i18n( $linked, 2 ) ); ?>
								</td>

								<td style="padding:0 10px 0 0; font-size:13px; color:#50575e;">
									<?php esc_html_e( 'Effective', 'wc-blacklist-manager' ); ?>
								</td>
								<td style="padding:0; font-size:14px; font-weight:700; color:<?php echo esc_attr( $accent ); ?>;">
									<?php echo esc_html( number_format_i18n( $effective, 2 ) ); ?>
								</td>
							</tr>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $limit_cta ) : ?>
					<div style="margin:0 0 14px 0; padding:12px 14px; border:1px solid #f0c1c2; background:#fff5f5; border-radius:8px;">
						<div style="margin:0 0 6px 0; font-size:13px; font-weight:700; color:#8a2424;">
							<?php esc_html_e( 'Plan limit reached', 'wc-blacklist-manager' ); ?>
						</div>
						<div style="margin:0 0 10px 0; font-size:13px; line-height:1.6; color:#50575e;">
							<?php echo esc_html( $limit_cta ); ?>
						</div>
						<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block; padding:9px 14px; background:#d63638; color:#ffffff; text-decoration:none; border-radius:6px; font-size:13px; font-weight:700;">
							<?php esc_html_e( 'Upgrade plan', 'wc-blacklist-manager' ); ?>
						</a>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $coverage_cta ) : ?>
					<div style="margin:0 0 14px 0; padding:12px 14px; border:1px solid #dcdcde; background:#ffffff; border-radius:8px;">
						<div style="margin:0 0 6px 0; font-size:13px; font-weight:700; color:#1d2327;">
							<?php esc_html_e( 'Need broader coverage?', 'wc-blacklist-manager' ); ?>
						</div>
						<div style="margin:0 0 10px 0; font-size:13px; line-height:1.6; color:#50575e;">
							<?php echo esc_html( $coverage_cta ); ?>
							<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'See higher plans', 'wc-blacklist-manager' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function get_decision_meta( string $decision ) : array {
		switch ( strtolower( $decision ) ) {
			case 'block':
				return [
					'label'       => __( 'High risk', 'wc-blacklist-manager' ),
					'badge_bg'    => '#d63638',
					'badge_text'  => '#ffffff',
					'panel_border'=> '#f0c1c2',
					'panel_bg'    => '#fcf0f1',
					'accent'      => '#d63638',
					'notice'      => __( 'Review this order carefully before fulfilling it.', 'wc-blacklist-manager' ),
				];

			case 'challenge':
				return [
					'label'       => __( 'Needs review', 'wc-blacklist-manager' ),
					'badge_bg'    => '#dba617',
					'badge_text'  => '#1d2327',
					'panel_border'=> '#f1dfaa',
					'panel_bg'    => '#fff8e5',
					'accent'      => '#b98900',
					'notice'      => __( 'Some details matched previous suspicious activity and should be reviewed.', 'wc-blacklist-manager' ),
				];

			case 'allow':
				return [
					'label'       => __( 'Clear', 'wc-blacklist-manager' ),
					'badge_bg'    => '#00a32a',
					'badge_text'  => '#ffffff',
					'panel_border'=> '#b8dfc3',
					'panel_bg'    => '#f0f9f2',
					'accent'      => '#00a32a',
					'notice'      => __( 'No blocking result was returned for this order.', 'wc-blacklist-manager' ),
				];

			case 'skipped_rate_limit':
				return [
					'label'       => __( 'Check skipped', 'wc-blacklist-manager' ),
					'badge_bg'    => '#8c8f94',
					'badge_text'  => '#ffffff',
					'panel_border'=> '#dcdcde',
					'panel_bg'    => '#f6f7f7',
					'accent'      => '#50575e',
					'notice'      => __( 'The monthly check limit was reached, so no decision was made for this order.', 'wc-blacklist-manager' ),
				];

			default:
				return [
					'label'       => __( 'Unknown', 'wc-blacklist-manager' ),
					'badge_bg'    => '#8c8f94',
					'badge_text'  => '#ffffff',
					'panel_border'=> '#dcdcde',
					'panel_bg'    => '#f6f7f7',
					'accent'      => '#50575e',
					'notice'      => '',
				];
		}
	}

	private function normalize_meta_lines( $value ) : array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', $value ) ) );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $value );
			if ( is_array( $lines ) ) {
				return array_values( array_filter( array_map( 'trim', $lines ) ) );
			}
		}

		return [];
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

			case 'domain':
				return __( 'Domain', 'wc-blacklist-manager' );

			default:
				return '' !== $type ? ucfirst( str_replace( '_', ' ', $type ) ) : __( 'Unknown', 'wc-blacklist-manager' );
		}
	}

	private function format_match_mode_label( string $mode ) : string {
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

	private function format_matched_variant_label( string $variant ) : string {
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

new YOGB_BM_Admin_Email_New_Order();