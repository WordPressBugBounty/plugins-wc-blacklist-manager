<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap">
	<?php if (!empty($data['message'])): ?>
		<div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"> 
			<p><strong><?php echo esc_html($data['message']); ?></strong></p>
		</div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field('wc_blacklist_email_settings_action', 'wc_blacklist_email_settings_nonce'); ?>

		<h2><?php echo esc_html__( 'Email options', 'wc-blacklist-manager' ); ?></h2>

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
						<th scope="row"><label class="premium-text"><?php echo esc_html__( 'Footer text', 'wc-blacklist-manager' ); ?></label></th>
						<td>
							<textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['email_footer_text']); ?></textarea>
							<p class="premium-text"><?php echo esc_html__( 'Display on the footer of the email template.', 'wc-blacklist-manager' ); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></p>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<h2><span class="dashicons dashicons-cart"></span> <?php echo esc_html__( 'Checkout', 'wc-blacklist-manager' ); ?></h2>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label class="label_child"><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></label>
					</th>
					<td>
						<input type="checkbox" id="wc_blacklist_email_notification" name="wc_blacklist_email_notification" value="yes" <?php checked($data['email_notification_enabled'], 'yes'); ?> />
						<label for="wc_blacklist_email_notification"><?php echo esc_html__( 'Send email notification when an order is placed by a suspected customer', 'wc-blacklist-manager' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label class="label_child"><?php echo esc_html__( 'Block email', 'wc-blacklist-manager' ); ?></label>
					</th>
					<td>
						<input type="checkbox" id="wc_blacklist_email_blocking_notification" name="wc_blacklist_email_blocking_notification" value="yes" <?php checked($data['email_blocking_notification_enabled'], 'yes'); ?> />
						<label for="wc_blacklist_email_blocking_notification"><?php echo esc_html__( 'Send email notification when blocked customer try to place an order', 'wc-blacklist-manager' ); ?></label>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ($premium_active): ?>
			<h2><span class="dashicons dashicons-admin-site"></span> <?php echo esc_html__( 'Register', 'wc-blacklist-manager' ); ?></h2>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label class="label_child"><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="wc_blacklist_email_register_suspect" name="wc_blacklist_email_register_suspect" value="yes" <?php checked($data['email_register_suspect'], 'yes'); ?> />
							<label for="wc_blacklist_email_register_suspect"><?php echo esc_html__( 'Send email notification when suspected visitor register an account', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="label_child"><?php echo esc_html__( 'Block email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="wc_blacklist_email_register_block" name="wc_blacklist_email_register_block" value="yes" <?php checked($data['email_register_block'], 'yes'); ?> />
							<label for="wc_blacklist_email_register_block"><?php echo esc_html__( 'Send email notification when blocked visitor try to register an account', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if (!$premium_active): ?>
			<h2><span class="premium-text"><span class="dashicons dashicons-email"></span> <?php echo esc_html__( 'Register', 'wc-blacklist-manager' ); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label class="premium-text label_child"><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php echo esc_html__( 'Send email notification when suspected visitor register an account', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="premium-text label_child"><?php echo esc_html__( 'Block email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php echo esc_html__( 'Send email notification when blocked visitor try to register an account', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ($premium_active): ?>
			<h2><span class="dashicons dashicons-admin-site"></span> <?php echo esc_html__( 'Comment', 'wc-blacklist-manager' ); ?></h2>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label class="label_child"><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="wc_blacklist_email_comment_suspect" name="wc_blacklist_email_comment_suspect" value="yes" <?php checked($data['email_comment_suspect'], 'yes'); ?> />
							<label for="wc_blacklist_email_comment_suspect"><?php echo esc_html__( 'Send email notification when suspected user submit a comment or review', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="label_child"><?php echo esc_html__( 'Block email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="wc_blacklist_email_comment_block" name="wc_blacklist_email_comment_block" value="yes" <?php checked($data['email_comment_block'], 'yes'); ?> />
							<label for="wc_blacklist_email_comment_block"><?php echo esc_html__( 'Send email notification when blocked user try to submit a comment or review', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if (!$premium_active): ?>
			<h2><span class="premium-text"><span class="dashicons dashicons-email"></span> <?php echo esc_html__( 'Comment', 'wc-blacklist-manager' ); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label class="premium-text label_child"><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php echo esc_html__( 'Send email notification when suspected user submit a comment or review', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="premium-text label_child"><?php echo esc_html__( 'Block email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php echo esc_html__( 'Send email notification when blocked user try to submit a comment or review', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ($premium_active): ?>
			<h2><span class="dashicons dashicons-email"></span> <?php echo esc_html__( 'Form', 'wc-blacklist-manager' ); ?></h2>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label class="label_child"><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="wc_blacklist_email_form_suspect" name="wc_blacklist_email_form_suspect" value="yes" <?php checked($data['email_form_suspect'], 'yes'); ?> />
							<label for="wc_blacklist_email_form_suspect"><?php echo esc_html__( 'Send email notification when suspected visitor submit a form', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="label_child"><?php echo esc_html__( 'Block email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="wc_blacklist_email_form_block" name="wc_blacklist_email_form_block" value="yes" <?php checked($data['email_form_block'], 'yes'); ?> />
							<label for="wc_blacklist_email_form_block"><?php echo esc_html__( 'Send email notification when blocked visitor try to submit a form', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if (!$premium_active): ?>
			<h2><span class="premium-text"><span class="dashicons dashicons-email"></span> <?php echo esc_html__( 'Form', 'wc-blacklist-manager' ); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label class="premium-text label_child"><?php echo esc_html__( 'Suspect email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php echo esc_html__( 'Send email notification when suspected visitor submit a form', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="premium-text label_child"><?php echo esc_html__( 'Block email', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php echo esc_html__( 'Send email notification when blocked visitor try to submit a form', 'wc-blacklist-manager' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<p class="submit">
			<input type="submit" class="button-primary" value="<?php echo esc_attr__( 'Save Changes', 'wc-blacklist-manager' ); ?>" />
		</p>
	</form>
</div>