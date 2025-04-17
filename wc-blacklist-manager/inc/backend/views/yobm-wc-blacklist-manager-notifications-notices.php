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

		<h2><?php echo esc_html__( 'Alert notices', 'wc-blacklist-manager' ); ?></h2>

		<p class="description"><?php echo esc_html__('Trigger these notices when a user initiates rule-related actions.', 'wc-blacklist-manager'); ?></p>

		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label for="wc_blacklist_checkout_notice"><?php echo esc_html__('Checkout notice', 'wc-blacklist-manager'); ?></label>
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
							<p><textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['vpn_proxy_checkout_notice']); ?></textarea></p>
							<p class="premium-text"><?php echo esc_html__('Enter the notice message to display when a customer uses Proxy or VPN to checkout.', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></p>

							<p><textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['payment_method_notice']); ?></textarea></p>
							<p class="premium-text"><?php echo esc_html__('Enter the notice message to display when a payment method is not available for a suspected customer.', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="wc_blacklist_registration_notice"><?php echo esc_html__('Registration notice', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<p><textarea id="wc_blacklist_registration_notice" name="wc_blacklist_registration_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['registration_notice']); ?></textarea></p>
						<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when a blocked visitor tries to register an account.', 'wc-blacklist-manager'); ?></p>
						<?php if ($premium_active): ?>
							<p><textarea id="wc_blacklist_vpn_proxy_registration_notice" name="wc_blacklist_vpn_proxy_registration_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['vpn_proxy_registration_notice']); ?></textarea></p>
							<p class="description"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to register an account.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
						<?php if (!$premium_active): ?>
							<p><textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['vpn_proxy_registration_notice']); ?></textarea></p>
							<p class="premium-text"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to register an account.', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="wc_blacklist_blocked_user_notice"><?php echo esc_html__('Blocked user notice', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<textarea id="wc_blacklist_blocked_user_notice" name="wc_blacklist_blocked_user_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['blocked_user_notice']); ?></textarea>
						<p class="description"><?php echo esc_html__('Enter the notice message to display when a blocked user tries to login or force out.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-email"></span>
						<label for="wc_blacklist_form_notice"><?php echo esc_html__('Form notice', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<textarea id="wc_blacklist_form_notice" name="wc_blacklist_form_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['form_notice']); ?></textarea>
						<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when form submission is blocked.', 'wc-blacklist-manager'); ?></p>
						<?php if ($premium_active): ?>
							<p><textarea id="wc_blacklist_vpn_proxy_form_notice" name="wc_blacklist_vpn_proxy_form_notice" rows="3" class="regular-text"><?php echo esc_textarea($data['vpn_proxy_form_notice']); ?></textarea></p>
							<p class="description" style="margin-bottom:20px;"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to submit a form.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
						<?php if (!$premium_active): ?>
							<p><textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['vpn_proxy_form_notice']); ?></textarea></p>
							<p class="premium-text"><?php echo esc_html__('Enter the notice message to display when a visitor uses Proxy or VPN to submit a form.', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ($premium_active): ?>
			<h2><?php echo esc_html__( 'Access prevention messages', 'wc-blacklist-manager' ); ?></h2>

			<p class="description"><?php echo esc_html__('Messages display for prevented visitors and users.', 'wc-blacklist-manager'); ?></p>

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
			<h2><span class='premium-text'><?php echo esc_html__( 'Access prevention messages', 'wc-blacklist-manager' ); ?><span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></h2>

			<p class="premium-text"><?php echo esc_html__('Messages display for prevented visitors and users.', 'wc-blacklist-manager'); ?></p>

			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Blocked IP', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea rows="3" class="regular-text" disabled><?php echo esc_textarea($data['access_blocked_ip']); ?></textarea>
							<p class="premium-text"><?php echo esc_html__('Enter the message to display for the blocked IP address (on the blocklist).', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Blocked IP country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<p><textarea rows="3" class="regular-text"disabled><?php echo esc_textarea($data['access_blocked_ip_country']); ?></textarea></p>
							<p class="premium-text"><?php echo esc_html__('Enter the message to display for the blocked IP address based on country.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Blocked browser', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<textarea rows="3" class="regular-text"disabled><?php echo esc_textarea($data['access_blocked_browser']); ?></textarea>
							<p class="premium-text"><?php echo esc_html__('Enter the message to display for the blocked browser.', 'wc-blacklist-manager'); ?></p>
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