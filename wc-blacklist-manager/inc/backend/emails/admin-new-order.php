<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YOGB_BM_Admin_Email_New_Order {

	public function __construct() {
		add_action( 'woocommerce_email_before_order_table', [ $this, 'render_gbl_email_summary' ], 15, 4 );
	}

	public function render_gbl_email_summary( $order, $sent_to_admin, $plain_text, $email ) {
		if (
			1 !== (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 )
			|| 'no' === get_option( 'wc_blacklist_email_global_blacklist_details', 'yes' )
			|| ! $sent_to_admin
			|| ! is_object( $email )
			|| 'new_order' !== ( $email->id ?? '' )
			|| ! $order instanceof WC_Order
		) {
			return;
		}

		$data = self::get_compact_summary( $order );
		if ( empty( $data ) ) {
			return;
		}

		$should_render = apply_filters(
			'wc_blacklist_manager_global_email_render_standalone',
			true,
			$order,
			(bool) $plain_text,
			$email
		);
		if ( ! $should_render ) {
			return;
		}

		$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $order->get_id() ) );
		if ( $plain_text ) {
			self::render_plain_text( $data, $edit_url );
			return;
		}

		self::render_html( $data, $edit_url );
	}

	public static function get_compact_summary( WC_Order $order ) : array {
		$decision = sanitize_key( (string) $order->get_meta( '_yogb_gbl_decision', true ) );
		if ( '' === $decision ) {
			return [];
		}

		$view             = self::get_decision_meta( $decision );
		$effective        = max( 0, (float) $order->get_meta( '_yogb_gbl_effective_score', true ) );
		$matched_checks   = max( 0, (int) $order->get_meta( '_yogb_gbl_matched_identities', true ) );
		$primary_type     = sanitize_key( (string) $order->get_meta( '_yogb_gbl_primary_signal_type', true ) );
		$primary_risk     = sanitize_key( (string) $order->get_meta( '_yogb_gbl_primary_risk_level', true ) );
		$main_signal_text = self::format_identity_type_label( $primary_type );

		if ( '' !== $main_signal_text && '' !== $primary_risk ) {
			$main_signal_text .= ' (' . ucfirst( str_replace( '_', ' ', $primary_risk ) ) . ')';
		}

		return [
			'decision'         => $decision,
			'decision_label'   => $view['label'],
			'badge_bg'         => $view['badge_bg'],
			'badge_text'       => $view['badge_text'],
			'accent'           => $view['accent'],
			'effective'        => $effective,
			'matched_checks'   => $matched_checks,
			'main_signal_text' => $main_signal_text,
		];
	}

	private static function render_plain_text( array $data, string $edit_url ) : void {
		echo "\n";
		echo strtoupper( __( 'Global · Blacklist check', 'wc-blacklist-manager' ) ) . "\n";
		printf(
			/* translators: %s: Global Blacklist decision label. */
			__( 'Result: %s', 'wc-blacklist-manager' ) . "\n",
			$data['decision_label']
		);

		if ( $data['effective'] > 0 ) {
			printf(
				/* translators: %s: effective Global Blacklist score. */
				__( 'Effective score: %s', 'wc-blacklist-manager' ) . "\n",
				number_format_i18n( $data['effective'], 2 )
			);
		}

		if ( $data['matched_checks'] > 0 ) {
			printf(
				/* translators: %s: count of matched checks. */
				_n( '%s matched check', '%s matched checks', $data['matched_checks'], 'wc-blacklist-manager' ) . "\n",
				number_format_i18n( $data['matched_checks'] )
			);
		}

		if ( '' !== $data['main_signal_text'] ) {
			printf(
				/* translators: %s: strongest matched signal. */
				__( 'Signal: %s', 'wc-blacklist-manager' ) . "\n",
				$data['main_signal_text']
			);
		}

		echo __( 'View order details:', 'wc-blacklist-manager' ) . ' ' . esc_url_raw( $edit_url ) . "\n\n";
	}

	private static function render_html( array $data, string $edit_url ) : void {
		$link_color = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '#720eec' ) );
		$link_color = $link_color ?: '#720eec';
		?>
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 24px;border-collapse:separate;">
			<tr>
				<td style="padding:0;border:1px solid #dcdcde;border-radius:8px;background:#ffffff;overflow:hidden;">
					<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
						<tr>
							<td style="width:4px;background:<?php echo esc_attr( $data['accent'] ); ?>;"></td>
							<td style="padding:13px 14px;vertical-align:middle;">
								<div style="margin-bottom:5px;color:#646970;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;"><?php esc_html_e( 'Global · Blacklist check', 'wc-blacklist-manager' ); ?></div>
								<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo esc_attr( $data['badge_bg'] ); ?>;color:<?php echo esc_attr( $data['badge_text'] ); ?>;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;"><?php echo esc_html( $data['decision_label'] ); ?></span>
							</td>
							<td style="padding:13px 14px;vertical-align:middle;text-align:right;color:#50575e;font-size:11px;line-height:1.5;">
								<?php if ( $data['effective'] > 0 ) : ?>
									<?php
									printf(
										/* translators: %s: effective Global Blacklist score. */
										esc_html__( 'Score %s', 'wc-blacklist-manager' ),
										esc_html( number_format_i18n( $data['effective'], 2 ) )
									);
									?>
								<?php endif; ?>
								<?php if ( $data['matched_checks'] > 0 ) : ?>
									<?php if ( $data['effective'] > 0 ) : ?>&nbsp;·&nbsp;<?php endif; ?>
									<?php
									printf(
										esc_html( _n( '%s match', '%s matches', $data['matched_checks'], 'wc-blacklist-manager' ) ),
										esc_html( number_format_i18n( $data['matched_checks'] ) )
									);
									?>
								<?php endif; ?>
								<?php if ( '' !== $data['main_signal_text'] ) : ?>
									<br><?php echo esc_html( $data['main_signal_text'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
					</table>
					<div style="padding:9px 14px;border-top:1px solid #dcdcde;text-align:right;">
						<a href="<?php echo esc_url( $edit_url ); ?>" style="color:<?php echo esc_attr( $link_color ); ?>;text-decoration:underline;font-size:12px;font-weight:600;"><?php esc_html_e( 'View order details', 'wc-blacklist-manager' ); ?></a>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function get_decision_meta( string $decision ) : array {
		switch ( $decision ) {
			case 'block':
				return [
					'label'      => __( 'High risk', 'wc-blacklist-manager' ),
					'badge_bg'   => '#d63638',
					'badge_text' => '#ffffff',
					'accent'     => '#d63638',
				];

			case 'challenge':
				return [
					'label'      => __( 'Needs review', 'wc-blacklist-manager' ),
					'badge_bg'   => '#dba617',
					'badge_text' => '#1d2327',
					'accent'     => '#b98900',
				];

			case 'allow':
				return [
					'label'      => __( 'Clear', 'wc-blacklist-manager' ),
					'badge_bg'   => '#00a32a',
					'badge_text' => '#ffffff',
					'accent'     => '#00a32a',
				];

			case 'skipped_rate_limit':
				return [
					'label'      => __( 'Check skipped', 'wc-blacklist-manager' ),
					'badge_bg'   => '#8c8f94',
					'badge_text' => '#ffffff',
					'accent'     => '#50575e',
				];

			case 'check_failed':
				return [
					'label'      => __( 'Check failed', 'wc-blacklist-manager' ),
					'badge_bg'   => '#d63638',
					'badge_text' => '#ffffff',
					'accent'     => '#d63638',
				];

			default:
				return [
					'label'      => __( 'Unknown', 'wc-blacklist-manager' ),
					'badge_bg'   => '#8c8f94',
					'badge_text' => '#ffffff',
					'accent'     => '#50575e',
				];
		}
	}

	private static function format_identity_type_label( string $type ) : string {
		switch ( $type ) {
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
				return '' !== $type ? ucfirst( str_replace( '_', ' ', $type ) ) : '';
		}
	}
}

new YOGB_BM_Admin_Email_New_Order();
