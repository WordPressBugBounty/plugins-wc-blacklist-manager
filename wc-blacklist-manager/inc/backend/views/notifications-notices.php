<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';
?>

<div class="wrap">
	<?php if (!empty($data['message'])): ?>
		<div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"> 
			<p><strong><?php echo esc_html($data['message']); ?></strong></p>
		</div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field('wc_blacklist_email_settings_action', 'wc_blacklist_email_settings_nonce'); ?>

		<h2><?php echo esc_html__( 'Alert notices', 'wc-blacklist-manager' ); ?></h2>

		<p class="description"><?php echo esc_html__('Trigger these notices when a user initiates rule-related actions.', 'wc-blacklist-manager'); ?></p>

		<table class="form-table">
			<tbody>
				<?php if ($woocommerce_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
							<label for="wc_blacklist_checkout_notice"><?php echo esc_html__('Checkout', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea id="wc_blacklist_checkout_notice" name="wc_blacklist_checkout_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['checkout_notice']); ?></textarea>
							<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when an order is blocked at checkout.', 'wc-blacklist-manager'); ?></p>
							<?php if ($premium_active): ?>
								<p><textarea id="wc_blacklist_vpn_proxy_checkout_notice" name="wc_blacklist_vpn_proxy_checkout_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['vpn_proxy_checkout_notice']); ?></textarea></p>
								<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when a customer uses Proxy or VPN to checkout.', 'wc-blacklist-manager'); ?></p>

								<p><textarea id="wc_blacklist_payment_method_notice" name="wc_blacklist_payment_method_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['payment_method_notice']); ?></textarea></p>
								<p class="description"><?php echo esc_html__('Enter the notice message to display when a payment method is not available for a suspected customer.', 'wc-blacklist-manager'); ?></p>
							<?php endif; ?>
							<?php if (!$premium_active): ?>
								<?php
								wc_blacklist_manager_render_premium_preview_cards(
									array(
										array(
											'icon'        => 'dashicons-shield',
											'title'       => __( 'Proxy/VPN checkout message', 'wc-blacklist-manager' ),
											'description' => __( 'Customize the message shown when checkout is stopped by network-risk rules.', 'wc-blacklist-manager' ),
										),
										array(
											'icon'        => 'dashicons-money-alt',
											'title'       => __( 'Payment restriction message', 'wc-blacklist-manager' ),
											'description' => __( 'Explain why a payment method is unavailable for a suspected customer.', 'wc-blacklist-manager' ),
										),
									),
									array( 'columns' => 2 )
								);
								wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'notifications' );
								?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="wc_blacklist_registration_notice"><?php echo esc_html__('Registration', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<p><textarea id="wc_blacklist_registration_notice" name="wc_blacklist_registration_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['registration_notice']); ?></textarea></p>
						<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when a blocked visitor tries to register an account.', 'wc-blacklist-manager'); ?></p>
						<?php if ($premium_active): ?>
							<p><textarea id="wc_blacklist_vpn_proxy_registration_notice" name="wc_blacklist_vpn_proxy_registration_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['vpn_proxy_registration_notice']); ?></textarea></p>
							<p class="description"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to register an account.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
						<?php if (!$premium_active): ?>
							<?php
							wc_blacklist_manager_render_premium_preview_cards(
								array(
									array(
										'icon'        => 'dashicons-shield',
										'title'       => __( 'Proxy/VPN registration message', 'wc-blacklist-manager' ),
										'description' => __( 'Customize the notice shown when network-risk rules stop account creation.', 'wc-blacklist-manager' ),
									),
								),
								array( 'columns' => 1 )
							);
							wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'notifications' );
							?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="wc_blacklist_comment_notice"><?php echo esc_html__('Comment / Review', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<textarea id="wc_blacklist_comment_notice" name="wc_blacklist_comment_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['comment_notice']); ?></textarea>
						<p class="description"><?php echo esc_html__('Enter the notice message to display when a blocked user tries to submit a comment or review.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<?php if (!$premium_active && !$woocommerce_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label><?php echo esc_html__('Blocked user', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<?php
						wc_blacklist_manager_render_premium_preview_cards(
							array(
								array(
									'icon'        => 'dashicons-lock',
									'title'       => __( 'Blocked user message', 'wc-blacklist-manager' ),
									'description' => __( 'Customize the message shown when a blocked account is stopped at login or forced out.', 'wc-blacklist-manager' ),
								),
							),
							array( 'columns' => 1 )
						);
						wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'notifications' );
						?>
					</td>
				</tr>
				<?php else: ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-admin-site"></span>
							<label for="wc_blacklist_blocked_user_notice"><?php echo esc_html__('Blocked user', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea id="wc_blacklist_blocked_user_notice" name="wc_blacklist_blocked_user_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['blocked_user_notice']); ?></textarea>
							<p class="description"><?php echo esc_html__('Enter the notice message to display when a blocked user tries to login or force out.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ($form_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-forms"></span>
							<label for="wc_blacklist_form_notice"><?php echo esc_html__('Form', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea id="wc_blacklist_form_notice" name="wc_blacklist_form_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['form_notice']); ?></textarea>
							<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when form submission is blocked.', 'wc-blacklist-manager'); ?></p>
							<?php if ($premium_active): ?>
								<p><textarea id="wc_blacklist_vpn_proxy_form_notice" name="wc_blacklist_vpn_proxy_form_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['vpn_proxy_form_notice']); ?></textarea></p>
								<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to submit a form.', 'wc-blacklist-manager'); ?></p>
							<?php endif; ?>
							<?php if (!$premium_active): ?>
								<?php
								wc_blacklist_manager_render_premium_preview_cards(
									array(
										array(
											'icon'        => 'dashicons-shield',
											'title'       => __( 'Proxy/VPN form message', 'wc-blacklist-manager' ),
											'description' => __( 'Customize the notice shown when network-risk rules stop supported form submissions.', 'wc-blacklist-manager' ),
										),
									),
									array( 'columns' => 1 )
								);
								wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'notifications' );
								?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-admin-site"></span>
							<label for="wc_blacklist_email_notice"><?php echo esc_html__('Disposable email', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea id="wc_blacklist_email_notice" name="wc_blacklist_email_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['email_notice']); ?></textarea>
							<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when disposable email is blocked.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
							<label for="wc_blacklist_phone_notice"><?php echo esc_html__('Disposable phone', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea id="wc_blacklist_phone_notice" name="wc_blacklist_phone_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['phone_notice']); ?></textarea>
							<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when disposable phone is blocked.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-admin-site"></span>
							<label><?php echo esc_html__('Disposable contact messages', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<?php
							wc_blacklist_manager_render_premium_preview_cards(
								array(
									array(
										'icon'        => 'dashicons-email-alt',
										'title'       => __( 'Disposable email notice', 'wc-blacklist-manager' ),
										'description' => __( 'Explain why temporary or risky email addresses are not accepted.', 'wc-blacklist-manager' ),
									),
									array(
										'icon'        => 'dashicons-phone',
										'title'       => __( 'Disposable phone notice', 'wc-blacklist-manager' ),
										'description' => __( 'Show a clear message when phone intelligence blocks a disposable number.', 'wc-blacklist-manager' ),
									),
								),
								array( 'columns' => 2 )
							);
							wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'notifications' );
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ($premium_active): ?>
			<h2><?php echo esc_html__( 'Access prevention messages', 'wc-blacklist-manager' ); ?></h2>

			<p class="description"><?php echo esc_html__('Messages display for prevented visitors and users.', 'wc-blacklist-manager'); ?> <a href="https://docs.yoohw.com/configure-access-prevention-messages/" target="_blank"><?php echo esc_html__('Learn more', 'wc-blacklist-manager'); ?></a></p>

			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="wc_blacklist_access_blocked_ip"><?php echo esc_html__('Blocked IP', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea id="wc_blacklist_access_blocked_ip" name="wc_blacklist_access_blocked_ip" rows="3" class="regular-text"><?php echo esc_textarea($data['access_blocked_ip']); ?></textarea>
							<p class="description"><?php echo esc_html__('Enter the message to display for the blocked IP address (on the blocklist).', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_blacklist_access_blocked_ip_country"><?php echo esc_html__('Blocked IP country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<p><textarea id="wc_blacklist_access_blocked_ip_country" name="wc_blacklist_access_blocked_ip_country" rows="3" class="regular-text"><?php echo esc_textarea($data['access_blocked_ip_country']); ?></textarea></p>
							<p class="description"><?php echo esc_html__('Enter the message to display for the blocked IP address based on country.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_blacklist_access_blocked_browser"><?php echo esc_html__('Blocked browser', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea id="wc_blacklist_access_blocked_browser" name="wc_blacklist_access_blocked_browser" rows="3" class="regular-text"><?php echo esc_textarea($data['access_blocked_browser']); ?></textarea>
							<p class="description"><?php echo esc_html__('Enter the message to display for the blocked browser.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if (!$premium_active): ?>
			<h2><?php echo esc_html__( 'Access prevention messages', 'wc-blacklist-manager' ); ?></h2>

			<p class="description"><?php echo esc_html__('Premium lets you customize messages for prevented visitors and users.', 'wc-blacklist-manager'); ?> <a href="https://docs.yoohw.com/configure-access-prevention-messages/" target="_blank"><?php echo esc_html__('Learn more', 'wc-blacklist-manager'); ?></a></p>

			<?php
			wc_blacklist_manager_render_premium_preview_cards(
				array(
					array(
						'icon'        => 'dashicons-shield',
						'title'       => __( 'Blocked IP message', 'wc-blacklist-manager' ),
						'description' => __( 'Customize what visitors see when their IP address is blocked.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'        => 'dashicons-location-alt',
						'title'       => __( 'Blocked country message', 'wc-blacklist-manager' ),
						'description' => __( 'Explain regional restrictions when country access rules prevent entry.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'        => 'dashicons-desktop',
						'title'       => __( 'Blocked browser message', 'wc-blacklist-manager' ),
						'description' => __( 'Show a clear reason when browser-based access rules prevent entry.', 'wc-blacklist-manager' ),
					),
				),
				array( 'columns' => 3 )
			);
			wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'notifications' );
			?>
		<?php endif; ?>

		<p class="submit">
			<input type="submit" class="button-primary" value="<?php echo esc_attr__( 'Save Changes', 'wc-blacklist-manager' ); ?>" />
		</p>
	</form>
</div>
