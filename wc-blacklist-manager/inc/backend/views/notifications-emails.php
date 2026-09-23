<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';
?>

<div class="wrap">
	<?php if (!empty($data['message'])): ?>
		<div id="setting-error-settings_updated" class="settings-error notice <?php echo ! empty( $data['message_error'] ) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
			<p><strong><?php echo esc_html($data['message']); ?></strong></p>
		</div>
	<?php endif; ?>

	<form method="post" id="wc-blacklist-test-email">
		<?php wp_nonce_field( 'wc_blacklist_test_email', 'wc_blacklist_test_email_nonce' ); ?>
	</form>
	<form method="post">
		<?php wp_nonce_field('wc_blacklist_email_settings_action', 'wc_blacklist_email_settings_nonce'); ?>

		<h2><?php echo esc_html__( 'Delivery & test', 'wc-blacklist-manager' ); ?></h2>

		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><label for="wc_blacklist_sender_name"><?php echo esc_html__( 'Sender name', 'wc-blacklist-manager' ); ?></label></th>
					<td>
						<input type="text" id="wc_blacklist_sender_name" name="wc_blacklist_sender_name" value="<?php echo esc_attr($data['sender_name']); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_blacklist_sender_address"><?php echo esc_html__( 'Sender address', 'wc-blacklist-manager' ); ?></label></th>
					<td>
						<input type="text" id="wc_blacklist_sender_address" name="wc_blacklist_sender_address" value="<?php echo esc_attr($data['sender_address']); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_blacklist_email_recipient"><?php echo esc_html__( 'Recipient(s)', 'wc-blacklist-manager' ); ?></label></th>
					<td>
						<input type="text" id="wc_blacklist_email_recipient" name="wc_blacklist_email_recipient" value="<?php echo esc_attr($data['email_recipient']); ?>" class="regular-text" />
						<p class="description"><?php echo esc_html__( 'Enter email address, separated by commas.', 'wc-blacklist-manager' ); ?></p>
					</td>
				</tr>
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row"><label for="wc_blacklist_email_footer_text"><?php echo esc_html__( 'Footer text', 'wc-blacklist-manager' ); ?></label></th>
						<td>
							<textarea id="wc_blacklist_email_footer_text" name="wc_blacklist_email_footer_text" rows="3" class="regular-text"><?php echo esc_textarea($data['email_footer_text']); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Display on the footer of the email template.', 'wc-blacklist-manager' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<tr>
						<th scope="row"><label><?php echo esc_html__( 'Footer text', 'wc-blacklist-manager' ); ?></label></th>
						<td>
							<?php
							wc_blacklist_manager_render_premium_preview_cards(
								array(
									array(
										'icon'        => 'dashicons-editor-paragraph',
										'title'       => __( 'Custom email footer', 'wc-blacklist-manager' ),
										'description' => __( 'Add branded footer text to blacklist notification emails without editing templates.', 'wc-blacklist-manager' ),
									),
								),
								array( 'compact' => true )
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<button type="submit" form="wc-blacklist-test-email" class="button button-secondary"><?php echo esc_html__( 'Send Test Email', 'wc-blacklist-manager' ); ?></button>
			<span class="description"><?php echo esc_html__( 'Uses your saved delivery settings. Save changes before testing.', 'wc-blacklist-manager' ); ?></span>
		</p>


		<h2><?php echo esc_html__( 'Orders & checkout', 'wc-blacklist-manager' ); ?></h2>
		<?php if ($woocommerce_active): ?>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Suspicious activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_notification]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_notification" name="wc_blacklist_email_notification" value="yes" <?php checked($data['email_notification_enabled'], 'yes'); ?> />
							<label for="wc_blacklist_email_notification"><?php echo esc_html__( 'Send email notification when an order is placed by a suspected customer', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Blocked activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_blocking_notification]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_blocking_notification" name="wc_blacklist_email_blocking_notification" value="yes" <?php checked($data['email_blocking_notification_enabled'], 'yes'); ?> />
							<label for="wc_blacklist_email_blocking_notification"><?php echo esc_html__( 'Send sampled notifications about blocked checkout activity', 'wc-blacklist-manager' ); ?></label>
							<p class="description"><?php echo esc_html__( 'At most one new sample every 15 minutes, delivered in the background. Samples may be delayed or missed; this is not a complete activity log.', 'wc-blacklist-manager' ); ?></p>
							<?php if ( ! empty( $data['blocked_diagnostic'] ) ) : ?><p role="status"><?php echo esc_html( $data['blocked_diagnostic'] ); ?></p><?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		<?php else: ?><p class="description"><?php echo esc_html__( 'Order alerts are available when WooCommerce is active.', 'wc-blacklist-manager' ); ?></p><?php endif; ?>

		<h2><?php echo esc_html__( 'Global Blacklist', 'wc-blacklist-manager' ); ?></h2>

		<?php if ( ! empty( $data['operational_ready'] ) ) : ?>
			<table class="form-table"><tbody><tr>
				<th scope="row"><?php echo esc_html__( 'Connection alerts', 'wc-blacklist-manager' ); ?></th>
				<td>
					<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_global_connection]" value="1" />
					<input type="checkbox" id="wc_blacklist_email_global_connection" name="wc_blacklist_email_global_connection" value="yes" <?php checked( $data['email_global_connection'], 'yes' ); ?> />
					<label for="wc_blacklist_email_global_connection"><?php echo esc_html__( 'Email when the authenticated connection needs attention and when it is restored after a notified episode.', 'wc-blacklist-manager' ); ?></label>
					<p class="description"><?php echo esc_html__( 'Requires an enabled, configured Global Blacklist connection. Checked in the background, with at most one attention email and one restoration email per day. Short episodes may be missed.', 'wc-blacklist-manager' ); ?></p>
					<?php if ( ! empty( $data['operational_diagnostic'] ) ) : ?><p role="status"><?php echo esc_html( $data['operational_diagnostic'] ); ?></p><?php endif; ?>
				</td>
			</tr></tbody></table>
		<?php endif; ?>

		<?php if ( ! empty( $data['usage_ready'] ) ) : ?>
			<table class="form-table"><tbody><tr><th scope="row"><?php echo esc_html__( 'Usage alerts', 'wc-blacklist-manager' ); ?></th><td>
				<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_global_usage]" value="1" />
				<input type="checkbox" id="wc_blacklist_email_global_usage" name="wc_blacklist_email_global_usage" value="yes" <?php checked( $data['email_global_usage'], 'yes' ); ?> />
				<label for="wc_blacklist_email_global_usage"><?php echo esc_html__( 'Email at 75%, 90%, the usage limit, and a new cycle after a notified threshold.', 'wc-blacklist-manager' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Uses verified Global Blacklist usage observed in the background. When enabled, thresholds already reached are skipped; later higher thresholds remain eligible. Notifications may be delayed or missed.', 'wc-blacklist-manager' ); ?></p>
				<?php if ( ! empty( $data['usage_diagnostic'] ) ) : ?><p role="status"><?php echo esc_html( $data['usage_diagnostic'] ); ?></p><?php endif; ?>
			</td></tr></tbody></table>
		<?php endif; ?>

		<?php if ($woocommerce_active): ?>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'New order decision details', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_global_blacklist_details]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_global_blacklist_details" name="wc_blacklist_email_global_blacklist_details" value="yes" <?php checked($data['email_global_blacklist_details'], 'yes'); ?> />
							<label for="wc_blacklist_email_global_blacklist_details"><?php echo esc_html__( 'Include Global Blacklist decision details in WooCommerce New Order emails sent to admins', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Accounts', 'wc-blacklist-manager' ); ?></h2>
		<?php if ( ! $premium_active ) : ?>
		<?php
			wc_blacklist_manager_render_premium_preview_banner(
				array(
					'title'       => __( 'Premium notification coverage', 'wc-blacklist-manager' ),
					'description' => __( 'Add alerts for account, comment, review, and form abuse without changing the checkout email alerts available in the free plugin.', 'wc-blacklist-manager' ),
					'unlock_url'  => $unlock_url,
					'context'     => 'notifications',
					'icon'        => 'dashicons-email-alt',
					'candidate_id' => 'premium.passive.notifications.emails.banner',
				)
			);
		?>
		<?php endif; ?>
		<?php if ( ! $premium_active ) : ?>
			<?php wc_blacklist_manager_render_premium_preview_cards( array( array( 'icon' => 'dashicons-lock', 'title' => __( 'Accounts — Premium', 'wc-blacklist-manager' ), 'description' => __( 'Registration alerts require Premium.', 'wc-blacklist-manager' ) ) ), array( 'compact' => true ) ); ?>
		<?php elseif ( empty( $data['provider_ready'] ) ) : ?><p role="status"><?php echo esc_html__( 'These alerts need a compatible Premium notification provider.', 'wc-blacklist-manager' ); ?></p><?php endif; ?>
		<?php if ( ! empty( $data['provider_diagnostic'] ) ) : ?><p role="status"><?php echo esc_html( $data['provider_diagnostic'] ); ?></p><?php endif; ?>

		<?php if ($premium_active && ! empty( $data['provider_ready'] )): ?>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Suspicious activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_register_suspect]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_register_suspect" name="wc_blacklist_email_register_suspect" value="yes" <?php checked($data['email_register_suspect'], 'yes'); ?> />
							<label for="wc_blacklist_email_register_suspect"><?php echo esc_html__( 'Send email notification when suspected visitor register an account', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Blocked activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_register_block]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_register_block" name="wc_blacklist_email_register_block" value="yes" <?php checked($data['email_register_block'], 'yes'); ?> />
							<label for="wc_blacklist_email_register_block"><?php echo esc_html__( 'Send email notification when blocked visitor try to register an account', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Comments & reviews', 'wc-blacklist-manager' ); ?></h2>
		<?php if ( ! $premium_active ) : ?>
			<?php wc_blacklist_manager_render_premium_preview_cards( array( array( 'icon' => 'dashicons-lock', 'title' => __( 'Comments & reviews — Premium', 'wc-blacklist-manager' ), 'description' => __( 'Comment and review alerts require Premium.', 'wc-blacklist-manager' ) ) ), array( 'compact' => true ) ); ?>
		<?php elseif ( empty( $data['provider_ready'] ) ) : ?><p role="status"><?php echo esc_html__( 'These alerts need a compatible Premium notification provider.', 'wc-blacklist-manager' ); ?></p><?php endif; ?>

		<?php if ($premium_active && ! empty( $data['provider_ready'] )): ?>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Suspicious activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_comment_suspect]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_comment_suspect" name="wc_blacklist_email_comment_suspect" value="yes" <?php checked($data['email_comment_suspect'], 'yes'); ?> />
							<label for="wc_blacklist_email_comment_suspect"><?php echo esc_html__( 'Send email notification when suspected user submit a comment or review', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Blocked activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_comment_block]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_comment_block" name="wc_blacklist_email_comment_block" value="yes" <?php checked($data['email_comment_block'], 'yes'); ?> />
							<label for="wc_blacklist_email_comment_block"><?php echo esc_html__( 'Send email notification when blocked user try to submit a comment or review', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Forms', 'wc-blacklist-manager' ); ?></h2>
		<?php if ( ! $premium_active ) : ?>
			<?php wc_blacklist_manager_render_premium_preview_cards( array( array( 'icon' => 'dashicons-lock', 'title' => __( 'Forms — Premium', 'wc-blacklist-manager' ), 'description' => __( 'Form alerts require Premium and a supported form integration.', 'wc-blacklist-manager' ) ) ), array( 'compact' => true ) ); ?>
		<?php elseif ( empty( $data['provider_ready'] ) ) : ?><p role="status"><?php echo esc_html__( 'These alerts need a compatible Premium notification provider.', 'wc-blacklist-manager' ); ?></p><?php endif; ?>

		<?php if ($premium_active && $form_active && ! empty( $data['provider_ready'] )): ?>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Suspicious activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_form_suspect]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_form_suspect" name="wc_blacklist_email_form_suspect" value="yes" <?php checked($data['email_form_suspect'], 'yes'); ?> />
							<label for="wc_blacklist_email_form_suspect"><?php echo esc_html__( 'Send email notification when suspected visitor submit a form', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Blocked activity', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_form_block]" value="1" />
							<input type="checkbox" id="wc_blacklist_email_form_block" name="wc_blacklist_email_form_block" value="yes" <?php checked($data['email_form_block'], 'yes'); ?> />
							<label for="wc_blacklist_email_form_block"><?php echo esc_html__( 'Send email notification when blocked visitor try to submit a form', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! $form_active ) : ?><p class="description"><?php echo esc_html__( 'Form alerts are available with Contact Form 7, Gravity Forms or WPForms.', 'wc-blacklist-manager' ); ?></p><?php endif; ?>

		<h2><?php echo esc_html__( 'Reports & digests', 'wc-blacklist-manager' ); ?></h2>
		<?php if ( ! $premium_active ) : ?>
			<?php wc_blacklist_manager_render_premium_preview_cards( array(
				array( 'icon' => 'dashicons-shield', 'title' => __( 'Weekly Security Digest', 'wc-blacklist-manager' ), 'description' => __( 'Summaries of retained protection and suspicious-activity records.', 'wc-blacklist-manager' ) ),
				array( 'icon' => 'dashicons-list-view', 'title' => __( 'Weekly Manual Blacklist Activity Digest', 'wc-blacklist-manager' ), 'description' => __( 'Summaries of retained manual block, suspect and removal records.', 'wc-blacklist-manager' ) ),
			), array( 'compact' => true ) ); ?>
		<?php endif; ?>
		<?php if ( $premium_active && ! empty( $data['digest_ready'] ) && ! empty( $data['initialized']['digest'] ) ) : ?>
			<table class="form-table"><tbody><tr><th scope="row"><?php echo esc_html__( 'Weekly security digest', 'wc-blacklist-manager' ); ?></th><td>
				<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_weekly_security_digest]" value="1" />
				<input type="checkbox" id="wc_blacklist_email_weekly_security_digest" name="wc_blacklist_email_weekly_security_digest" value="yes" <?php checked( $data['email_weekly_security_digest'], 'yes' ); ?> />
				<label for="wc_blacklist_email_weekly_security_digest"><?php echo esc_html__( 'Email a weekly summary of retained protection and suspicious-activity records.', 'wc-blacklist-manager' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Starts with the next full Monday-to-Monday site-calendar week. Counts may be incomplete and are not unique incidents. Empty or overly large weeks are skipped. Delivery may be delayed or missed.', 'wc-blacklist-manager' ); ?></p>
				<?php if ( ! empty( $data['digest_diagnostic'] ) ) : ?><p role="status"><?php echo esc_html( $data['digest_diagnostic'] ); ?></p><?php endif; ?>
			</td></tr></tbody></table>
		<?php endif; ?>

		<?php if ( $premium_active && ! empty( $data['audit_digest_ready'] ) && ! empty( $data['initialized']['audit_digest'] ) ) : ?>
			<table class="form-table"><tbody><tr><th scope="row"><?php echo esc_html__( 'Weekly manual blacklist activity digest', 'wc-blacklist-manager' ); ?></th><td>
				<input type="hidden" name="wc_blacklist_email_present[wc_blacklist_email_weekly_manual_blacklist_digest]" value="1" />
				<input type="checkbox" id="wc_blacklist_email_weekly_manual_blacklist_digest" name="wc_blacklist_email_weekly_manual_blacklist_digest" value="yes" <?php checked( $data['email_weekly_manual_blacklist_digest'], 'yes' ); ?> />
				<label for="wc_blacklist_email_weekly_manual_blacklist_digest"><?php echo esc_html__( 'Email retained manual block, suspect and removal record totals from Dashboard and Edit Order.', 'wc-blacklist-manager' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Starts with the next full Monday-to-Monday site-calendar week. Other screens are excluded; records may be missing and do not prove successful changes or unique actions. Empty or overly large weeks are skipped. Delivery may be delayed or missed.', 'wc-blacklist-manager' ); ?></p>
				<?php if ( ! empty( $data['audit_digest_diagnostic'] ) ) : ?><p role="status"><?php echo esc_html( $data['audit_digest_diagnostic'] ); ?></p><?php endif; ?>
			</td></tr></tbody></table>
		<?php endif; ?>

		<?php if ( $premium_active ) : ?>
			<?php foreach ( array( 'digest' => __( 'Weekly Security Digest', 'wc-blacklist-manager' ), 'audit_digest' => __( 'Weekly Manual Blacklist Activity Digest', 'wc-blacklist-manager' ) ) as $family => $label ) : ?>
				<?php if ( empty( $data[ $family . '_ready' ] ) || empty( $data['initialized'][ $family ] ) ) : ?>
					<table class="form-table"><tbody><tr><th scope="row"><?php echo esc_html( $label ); ?></th><td>
					<p role="status"><?php echo esc_html( empty( $data[ $family . '_ready' ] ) ? __( 'This summary needs a compatible Premium notification provider.', 'wc-blacklist-manager' ) : __( 'This preference could not be initialized safely. Existing choices are preserved. Check local storage and reload this page.', 'wc-blacklist-manager' ) ); ?></p>
					</td></tr></tbody></table>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>

		<p class="submit">
			<input type="submit" class="button-primary" value="<?php echo esc_attr__( 'Save Changes', 'wc-blacklist-manager' ); ?>" />
		</p>
	</form>
</div>
