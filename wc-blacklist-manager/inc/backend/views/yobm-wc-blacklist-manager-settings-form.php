<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap">
	<?php if (!$premium_active): ?>
		<p>Please support us by <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
	<?php endif; ?>
	
	<h1>
		<?php echo esc_html__('Blacklist Manager Settings', 'wc-blacklist-manager'); ?> 
		<a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/settings/" target="_blank" class="button button-secondary" style="display: inline-flex; align-items: center;"><span class="dashicons dashicons-editor-help"></span> Documents</a>
		<?php if (!$premium_active): ?>
			<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary">Support / Suggestion</a>
		<?php endif; ?>
	</h1>

	<nav class="nav-tab-wrapper">
		<a href="#tab-content-general" class="nav-tab nav-tab-active" id="tab-general"><?php echo esc_html__('General', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-automation" class="nav-tab" id="tab-automation"><?php echo esc_html__('Automation', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-scoring" class="nav-tab" id="tab-scoring"><?php echo esc_html__('Scoring', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-integrations" class="nav-tab" id="tab-integrations"><?php echo esc_html__('Integrations', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-payments" class="nav-tab" id="tab-payments"><?php echo esc_html__('Payments', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-permission" class="nav-tab" id="tab-permission"><?php echo esc_html__('Permission', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-tools" class="nav-tab" id="tab-tools"><?php echo esc_html__('Tools', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-connection" class="nav-tab" id="tab-connection"><?php echo esc_html__('Connection', 'wc-blacklist-manager'); ?></a>
	</nav>

	<div id="tab-content-general" class="tab-content">
		<form method="post" action="">
			<?php wp_nonce_field('wc_blacklist_settings_action', 'wc_blacklist_settings_nonce'); ?>

			<h2><?php echo esc_html__('Blacklist', 'wc-blacklist-manager'); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label for="blacklist_action"><?php echo esc_html__('Order action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select id="blacklist_action" name="blacklist_action">
							<option value="none" <?php selected($settings['blacklist_action'], 'none'); ?>><?php echo esc_html__('None', 'wc-blacklist-manager'); ?></option>
							<option value="cancel" <?php selected($settings['blacklist_action'], 'cancel'); ?>><?php echo esc_html__('Cancel order', 'wc-blacklist-manager'); ?></option>
							<option value="prevent" <?php selected($settings['blacklist_action'], 'prevent'); ?>><?php echo esc_html__('Prevent order', 'wc-blacklist-manager'); ?></option>
						</select>
						<p class="description"><?php echo esc_html__('Cancel the order / Prevent customers from checking out if they use the phone number or email address is on the blocklist.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr id="time_delay_row" style="<?php echo ($settings['blacklist_action'] === 'cancel') ? '' : 'display: none;'; ?>">
					<th scope="row"><label for="order_delay"><?php echo esc_html__('Time delay', 'wc-blacklist-manager'); ?></label></th>
					<td>
						<input type="number" id="order_delay" name="order_delay" value="<?php echo esc_attr($settings['order_delay']); ?>" class="small-text" min="0">
						<?php echo esc_html__('minute(s)', 'wc-blacklist-manager'); ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart premium-text"></span>
						<label class="premium-text"><?php echo esc_html__('Customer name', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Enable the customer first and last name on the blacklist (suspects and blocklist)', 'wc-blacklist-manager'); ?></label><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
						<p class="premium-text"><?php echo esc_html__('If checked, it will cancel the order or prevent customers from checking out if their first and last name is in the blocklist.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="block_user_registration"><?php echo esc_html__('Registration action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="block_user_registration" name="block_user_registration" value="1" <?php checked($settings['block_user_registration']); ?>>
						<label for="block_user_registration"><?php echo esc_html__('Prevent visitors from registering if their email is on the blocklist', 'wc-blacklist-manager'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-email"></span>
						<label for="form_blocking_enabled"><?php echo esc_html__('Form action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="form_blocking_enabled" name="form_blocking_enabled" value="1" <?php checked($settings['form_blocking_enabled']); ?>>
						<label for="form_blocking_enabled"><?php echo esc_html__('Enable the blacklist (suspects and blocklist) for Contact Forms 7, Gravity Forms, and WPForms', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Notify the admin if a suspected phone or email is submitting, and prevent submitting if they were blocked.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__('IP address', 'wc-blacklist-manager'); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="ip_blacklist_enabled"><?php echo esc_html__('IP blocking', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select id="ip_blacklist_enabled" name="ip_blacklist_enabled">
							<option value="0" <?php selected($settings['ip_blacklist_enabled'], '0'); ?>><?php echo esc_html__('Disabled', 'wc-blacklist-manager'); ?></option>
							<option value="1" <?php selected($settings['ip_blacklist_enabled'], '1'); ?>><?php echo esc_html__('Limited blocking', 'wc-blacklist-manager'); ?></option>
							<option value="2" <?php selected($settings['ip_blacklist_enabled'], '2'); ?> disabled><?php echo esc_html__('Prevent access', 'wc-blacklist-manager'); ?></option>
						</select>
						<p class="description"><?php echo __('<b>Limited blocking</b>: Still allow the blocked visitors to access your site but prevent their actions.', 'wc-blacklist-manager'); ?><br><?php echo __('<b>Prevent access</b>: Fully prevent the blocked visitors from accessing your site.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr id="ip_blacklist_action_row" style="<?php echo ($settings['ip_blacklist_enabled']) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<span class="dashicons dashicons-cart label_child"></span>
						<label for="ip_blacklist_action"><?php echo esc_html__('Order action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<p>
							<select id="ip_blacklist_action" name="ip_blacklist_action">
								<option value="none" <?php selected($settings['ip_blacklist_action'], 'none'); ?>><?php echo esc_html__('None', 'wc-blacklist-manager'); ?></option>
								<option value="prevent" <?php selected($settings['ip_blacklist_action'], 'prevent'); ?>><?php echo esc_html__('Prevent order', 'wc-blacklist-manager'); ?></option>
							</select>
							<p class="description"><?php echo esc_html__('Prevent customers from checking out if their IP address is on the blocklist.', 'wc-blacklist-manager'); ?></p>
						</p>
						<p>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Prevent customers from checking out if they use Proxy server or VPN', 'wc-blacklist-manager'); ?></label><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
						</p>
					</td>
				</tr>
				<tr id="block_ip_registration_row" style="<?php echo ($settings['ip_blacklist_enabled']) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<span class="dashicons dashicons-admin-site label_child"></span>
						<label for="block_ip_registration"><?php echo esc_html__('Registration action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<p>
							<input type="checkbox" id="block_ip_registration" name="block_ip_registration" value="1" <?php checked($settings['block_ip_registration']); ?>>
							<label for="block_ip_registration"><?php echo esc_html__('Prevent visitors from registering if their ip address is on the blocklist', 'wc-blacklist-manager'); ?></label>
						</p>
						<p>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Prevent visitors from registering if they use Proxy server or VPN', 'wc-blacklist-manager'); ?></label><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
						</p>
					</td>
				</tr>
				<tr id="block_ip_form_row" style="<?php echo ($settings['ip_blacklist_enabled'] === '1') ? '' : 'display: none;'; ?>">
					<th scope="row">
						<span class="dashicons dashicons-email label_child premium-text"></span>
						<label for="block_ip_form" class="premium-text"><?php echo esc_html__('Form action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<p>
							<input type="checkbox" disabled>
							<label for="block_ip_form" class="premium-text"><?php echo esc_html__('Prevent visitors from submitting if their IP address is on the blocklist', 'wc-blacklist-manager'); ?></label><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
						</p>
						<p>
							<input type="checkbox" disabled>
							<label for="block_proxy_vpn_form" class="premium-text"><?php echo esc_html__('Prevent visitors from submitting if they use Proxy server or VPN', 'wc-blacklist-manager'); ?></label>
						</p>
					</td>
				</tr>

				<!-- Prevent Access -->
				<tr>
					<th scope="row">
						<label class="premium-text"><?php echo esc_html__('Country prevention', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Enable preventing visitors from accessing your site based on IP country', 'wc-blacklist-manager'); ?></label><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site label_child premium-text"></span>
						<label class="premium-text"><?php esc_html_e('Select countries', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="text" class="regular-text" disabled/>
						<p class="premium-text"><?php echo esc_html__('Select to list the countries list.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site label_child premium-text"></span>
						<label class="premium-text"><?php echo esc_html__('Access action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select disabled>
							<option><?php echo esc_html__('Prevent this list', 'wc-blacklist-manager'); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart premium-text"></span>
						<label class="premium-text"><?php echo esc_html__('IP details popup', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Enable the ability to check the IP address details on the edit order page', 'wc-blacklist-manager'); ?></label><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
						<p class="premium-text"><?php echo esc_html__('You will be able to click on customer IP on the edit order page and see the IP details in the popup.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/blacklist-management/orders/#ip-details" target="_blank"><?php echo esc_html__('Learn more', 'wc-blacklist-manager'); ?></a></p>
					</td>
				</tr>
			</table>

			<h2><span class="premium-text"><?php echo esc_html__('Address', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label class="premium-text"><?php echo esc_html__('Address blocking', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Enable the customer address blocking', 'wc-blacklist-manager'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart label_child premium-text"></span>
						<label class="premium-text"><?php echo esc_html__('Order action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select disabled>
							<option><?php echo esc_html__('Prevent order', 'wc-blacklist-manager'); ?></option>
						</select>
						<p class="premium-text"><?php echo esc_html__('Prevent customers from checking out if their billing address is on the blocklist.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart label_child premium-text"></span>
						<label class="premium-text"><?php echo esc_html__('Shipping address', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Enable including the shipping address to the blocklist', 'wc-blacklist-manager'); ?></label>
						<p class="premium-text"><?php echo esc_html__('If checked, the shipping address will be added/blocked if it is different from the billing address in the order.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__('Email domain', 'wc-blacklist-manager'); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="domain_blocking_enabled"><?php echo esc_html__('Domain blocking', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="domain_blocking_enabled" name="domain_blocking_enabled" value="1" <?php checked($settings['domain_blocking_enabled']); ?>>
						<label for="domain_blocking_enabled"><?php echo esc_html__('Enable email domain blocking', 'wc-blacklist-manager'); ?></label>
					</td>
				</tr>
				<tr id="domain_blocking_action_row" style="<?php echo ($settings['domain_blocking_enabled']) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<span class="dashicons dashicons-cart label_child"></span>
						<label for="domain_blocking_action"><?php echo esc_html__('Order action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select id="domain_blocking_action" name="domain_blocking_action">
							<option value="none" <?php selected($settings['domain_blocking_action'], 'none'); ?>><?php echo esc_html__('None', 'wc-blacklist-manager'); ?></option>
							<option value="prevent" <?php selected($settings['domain_blocking_action'], 'prevent'); ?>><?php echo esc_html__('Prevent order', 'wc-blacklist-manager'); ?></option>
						</select>
					</td>
				</tr>
				<tr id="domain_registration_row" style="<?php echo ($settings['domain_blocking_enabled']) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<span class="dashicons dashicons-admin-site label_child"></span>
						<label for="domain_registration"><?php echo esc_html__('Registration action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="domain_registration" name="domain_registration" value="1" <?php checked($settings['domain_registration']); ?>>
						<label for="domain_registration"><?php echo esc_html__('Prevent visitors from registering if their email domain is on the blocklist', 'wc-blacklist-manager'); ?></label>
					</td>
				</tr>
				<tr id="domain_form_row" style="<?php echo ($settings['domain_blocking_enabled']) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<span class="dashicons dashicons-email label_child premium-text"></span>
						<label class="premium-text"><?php echo esc_html__('Form action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Prevent visitors from submitting if their email domain is on the blocklist', 'wc-blacklist-manager'); ?></label><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__('User', 'wc-blacklist-manager'); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label for="enable_user_blocking"><?php echo esc_html__('User blocking', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="enable_user_blocking" name="enable_user_blocking" value="1" <?php checked($settings['enable_user_blocking']); ?>>
						<label for="enable_user_blocking"><?php echo esc_html__('Block user when add to blocklist the order placed by that user', 'wc-blacklist-manager'); ?></label>
					</td>
				</tr>
			</table>

			<h2><span class="premium-text"><?php echo esc_html__('Additional', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label class="premium-text"><?php esc_html_e('Browser blocking', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="text" class="regular-text" disabled/> <a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
						<p class="premium-text"><?php echo esc_html__('The users cannot access your site if using these browsers.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
			</table>

			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function () {
					var blacklistActionSelect = document.getElementById('blacklist_action');
					var ipBlacklistEnabledCheckbox = document.getElementById('ip_blacklist_enabled');
					var domainBlockingEnabledCheckbox = document.getElementById('domain_blocking_enabled');
					var timeDelayRow = document.getElementById('time_delay_row');
					var ipBlacklistActionRow = document.getElementById('ip_blacklist_action_row');
					var blockIpRegistrationRow = document.getElementById('block_ip_registration_row');
					var blockIpFormRow = document.getElementById('block_ip_form_row');
					var domainBlockingActionRow = document.getElementById('domain_blocking_action_row');
					var domainRegistrationRow = document.getElementById('domain_registration_row');
					var domainFormRow = document.getElementById('domain_form_row');

					function toggleDisplay(element, display) {
						element.style.display = display ? '' : 'none';
					}

					// Handle blacklist action select changes
					blacklistActionSelect.addEventListener('change', function () {
						toggleDisplay(timeDelayRow, this.value === 'cancel');
					});

					// Handle IP blacklist enabled checkbox changes
					ipBlacklistEnabledCheckbox.addEventListener('change', function () {
					toggleDisplay(ipBlacklistActionRow, this.value === '1');
					toggleDisplay(blockIpRegistrationRow, this.value === '1');
					toggleDisplay(blockIpFormRow, this.value === '1');
				});

					// Handle domain blocking enabled checkbox changes
					domainBlockingEnabledCheckbox.addEventListener('change', function () {
						var isChecked = this.checked;
						toggleDisplay(domainBlockingActionRow, this.checked);
						toggleDisplay(domainRegistrationRow, this.checked);
						toggleDisplay(domainFormRow, this.checked);
					});

					console.log('Blacklist Manager Settings JavaScript initialized');
				});
			</script>

			<p class="submit">
				<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" />
			</p>
		</form>
	</div>

	<div id="tab-content-automation" class="tab-content" style="display:none;">
		<div class="wrap">
			<form method="post" action="">

				<span class="yo-premium"><i class="dashicons dashicons-lock"></i> Fully Automated-Protecting against fraud and unauthorized transactions <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" class="premium-label">Unlock</a></span>

				<p class="premium-text"><?php echo esc_html__('This feature is automatically doing the rules actions if the customer details or action match with their rules. Know about the actions:', 'wc-blacklist-manager'); ?></p>
		
				<p class="premium-text" style="margin-left: 15px;">
					1. <?php echo __('<strong>Email alert:</strong> Sending the emails to admin to let you know a new order was placed that matched the rule.', 'wc-blacklist-manager'); ?><br>
					2. <?php echo __('<strong>Add to suspects:</strong> Sending the emails and auto-adding the customers to the <strong>suspects list</strong> if they placed a new order that matched the rule.', 'wc-blacklist-manager'); ?><br>
					3. <?php echo __('<strong>Add to blocklist:</strong> Sending the emails and auto-adding the customers to the <strong>blocklist</strong> if they placed a new order that matched the rule.', 'wc-blacklist-manager'); ?><br>
					4. <?php echo __('<strong>Treat as score:</strong> Do no action, but treat the rule as a score that you can manage in the "Scoring" tab.', 'wc-blacklist-manager'); ?><br>
				</p>

				<p class="premium-text"><?php echo esc_html__('Note that the automated actions only trigger after an order was placed.', 'wc-blacklist-manager'); ?></p>

				<h2><span class="premium-text"><?php echo esc_html__('Orders', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<table class="form-table">
					<!-- Phone/Email vs Address -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Phone/Email vs Address', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the same phone or email, but different address', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('The customer places a new order using the same phone number or email address but a different billing address than their previous order.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- Phone/Email vs IP -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Phone/Email vs IP', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the same phone or email, but different IP address', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('The customer places a new order using the same phone number or email address but a different IP address than their previous order.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- Check orders in -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Check orders in', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" disabled>
							<label class="premium-text"><?php echo esc_html__('day(s). Maximum is 90 days.', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('This option is in addition to the two options above.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- Billing vs Shipping -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Billing vs Shipping', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the billing address and shipping address are not the same', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('The customer places a new order using the billing and shipping addresses that are different.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- Order value -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Order value', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the order value is higher X times than the average', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('The customer places a new order with a total value is higher by X times than your store order average.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- Order attempts -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Order attempts', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if a customer placed too many orders within the time period', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('The customer places a new order with the same phone or email or IP address or billing address (optional) more than X times during Y hours.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Add as suspect', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled/>
							<p class="premium-text"><?php echo esc_html__('Auto-adding the customer to the suspect list for these order statuses.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Add to blocklist', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled/>
							<p class="premium-text"><?php echo esc_html__('Auto-adding the customer to the blocklist for these order statuses.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('IP addresses', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<table class="form-table">
					<!-- Using proxy or VPN -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Using proxy or VPN', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the order placed through proxy server or VPN', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('The customer places a new order with the IP address using a VPN or proxy.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- IP vs Country -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('IP vs Country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the IP does not match the billing country', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('Verifying if the customer IP address country, as determined through a trusted third-party API, matches the billing country.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- IP vs Address -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('IP vs Address', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the IP coordinates radius does not match address coordinates radius about X mile/km', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('Verify if the customer IP address coordinates fall within the same radius as the billing coordinates using the Google Maps API.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('Payment gateways', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<table class="form-table">
					<!-- Card vs Billing country -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Card vs Billing country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the card country does not match the billing country', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('Verify if the payment card country is the same with the billing country of the new order.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<!-- Card vs Billing name -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('AVS checks', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the Address Verification Service checks are not pass', 'wc-blacklist-manager'); ?> <a href="https://stripe.com/resources/more/what-is-address-verification-service" target="_blank"><?php esc_html_e('Know more about AVS?', 'wc-blacklist-manager'); ?></a></label>
								<p class="premium-text"><?php echo esc_html__('Take appropriate action if the Address Verification Service (AVS) checks fail, such as flagging the order for review.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				
					<!-- High Risk Card Country -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('High risk country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Action if the payment using the card from High risk countries', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('Flag or block transactions made with cards issued from high-risk countries to prevent potential fraud.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('Additional options', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Auto cancel order', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Enable the automatic cancel order for the "Add to blocklist" action', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('If the action has set as "Add to blocklist", then also cancel the order if it matches the action rule.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Cancel delay timer', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" disabled> <label class="premium-text"> minute(s).</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" disabled/>
				</p>
			</form>
		</div>
	</div>

	<div id="tab-content-scoring" class="tab-content" style="display:none;">
		<div class="wrap">
			<form method="post" action="">

				<span class="yo-premium"><i class="dashicons dashicons-lock"></i> This feature is able to let you focus hands-free on growing your business <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" class="premium-label">Unlock</a></span>

				<h2><span class="premium-text"><?php echo esc_html__('Risk order scoring', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Enable order risk', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Enable scoring the risk for the orders.', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php echo esc_html__('You will also see the risk score column in orders page, and risk score metabox in edit order page.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('Score rule', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<p class="premium-text"><?php echo esc_html__('Each score rule will be displayed based on the automation option set as "Treat as score".', 'wc-blacklist-manager'); ?></p>

				<table class="form-table">

					<!-- First Time Order -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('First time order', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Phone/Email vs Address -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Phone/Email vs Address', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Phone/Email vs IP -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Phone/Email vs IP', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Billing vs Shipping -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Billing vs Shipping', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Order value -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Order value', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Order attempts -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Order attempts', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Using proxy or VPN -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Using proxy or VPN', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- IP vs Country -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('IP vs Country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- IP vs Address -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('IP vs Address', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Card vs Billing country -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Card vs Billing country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Card vs Billing name -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('AVS checks', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>
				
					<!-- High Risk Card Country -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('High risk country', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option>5</option>
							</select>
						</td>
					</tr>

					<!-- Total score -->
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Total score', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
						<label class="premium-text">60</label>
						</td>
					</tr>					
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('Risk score thresholds', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<p class="premium-text"><?php echo esc_html__('When a new order has been placed, the risk score will be calculated and trigger the action that follows these thresholds.', 'wc-blacklist-manager'); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<div style="display: inline-flex;align-items: center;justify-content: center;">
								<label style="margin-right: 5px;">
									<svg width="24px" height="24px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path opacity="0.5" d="M3.37752 5.08241C3 5.62028 3 7.21907 3 10.4167V11.9914C3 17.6294 7.23896 20.3655 9.89856 21.5273C10.62 21.8424 10.9807 22 12 22C13.0193 22 13.38 21.8424 14.1014 21.5273C16.761 20.3655 21 17.6294 21 11.9914V10.4167C21 7.21907 21 5.62028 20.6225 5.08241C20.245 4.54454 18.7417 4.02996 15.7351 3.00079L15.1623 2.80472C13.595 2.26824 12.8114 2 12 2C11.1886 2 10.405 2.26824 8.83772 2.80472L8.26491 3.00079C5.25832 4.02996 3.75503 4.54454 3.37752 5.08241Z" fill="#00a32a"></path> <path d="M15.0595 10.4995C15.3353 10.1905 15.3085 9.71643 14.9995 9.44055C14.6905 9.16468 14.2164 9.19152 13.9406 9.5005L10.9286 12.8739L10.0595 11.9005C9.78359 11.5915 9.30947 11.5647 9.0005 11.8406C8.69152 12.1164 8.66468 12.5905 8.94055 12.8995L10.3691 14.4995C10.5114 14.6589 10.7149 14.75 10.9286 14.75C11.1422 14.75 11.3457 14.6589 11.488 14.4995L15.0595 10.4995Z" fill="#00a32a"></path> </g></svg>								
								</label>
								<span style="color:rgba(0, 163, 41, 0.39);"><?php echo esc_html__('Safe order', 'wc-blacklist-manager-premium'); ?></span>
							</div>
						</th>
						<td>
							<label class="premium-text">0 / 60</label>
							<p class="premium-text"><?php echo __('You will see this score badge in the order page and new order email notification.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<div style="display: inline-flex;align-items: center;justify-content: center;">
								<label style="margin-right: 5px;">
									<svg width="24px" height="24px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path opacity="0.5" d="M3 10.4167C3 7.21907 3 5.62028 3.37752 5.08241C3.75503 4.54454 5.25832 4.02996 8.26491 3.00079L8.83772 2.80472C10.405 2.26824 11.1886 2 12 2C12.8114 2 13.595 2.26824 15.1623 2.80472L15.7351 3.00079C18.7417 4.02996 20.245 4.54454 20.6225 5.08241C21 5.62028 21 7.21907 21 10.4167C21 10.8996 21 11.4234 21 11.9914C21 17.6294 16.761 20.3655 14.1014 21.5273C13.38 21.8424 13.0193 22 12 22C10.9807 22 10.62 21.8424 9.89856 21.5273C7.23896 20.3655 3 17.6294 3 11.9914C3 11.4234 3 10.8996 3 10.4167Z" fill="#72aee6"></path> <path fill-rule="evenodd" clip-rule="evenodd" d="M10.95 8.40029C11.5723 7.93363 12.4278 7.93363 13.05 8.40029L16.45 10.9503C16.7814 11.1988 16.8486 11.6689 16.6 12.0003C16.3515 12.3317 15.8814 12.3988 15.55 12.1503L12.15 9.60029C12.0612 9.53363 11.9389 9.53363 11.85 9.60029L8.45004 12.1503C8.11867 12.3988 7.64857 12.3317 7.40004 12.0003C7.15152 11.6689 7.21867 11.1988 7.55004 10.9503L10.95 8.40029ZM11.55 11.9503C11.8167 11.7503 12.1834 11.7503 12.45 11.9503L14.45 13.4503C14.7814 13.6988 14.8486 14.1689 14.6 14.5003C14.3515 14.8317 13.8814 14.8988 13.55 14.6503L12 13.4878L10.45 14.6503C10.1187 14.8988 9.64857 14.8317 9.40004 14.5003C9.15152 14.1689 9.21867 13.6988 9.55004 13.4503L11.55 11.9503Z" fill="#72aee6"></path> </g></svg>
								</label>
								<span style="color:rgba(114, 174, 230, 0.38);"><?php echo esc_html__('Moderately risky', 'wc-blacklist-manager-premium'); ?></span>
							</div>
						</th>
						<td>
							<input type="number" class="small-text" disabled> <label class="premium-text">/ 60</label>
							<p class="premium-text"><?php echo __('You will see this score badge in the order page and new order email notification.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<div style="display: inline-flex;align-items: center;justify-content: center;">
								<label style="margin-right: 5px;">
									<svg width="24px" height="24px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path opacity="0.5" d="M3 10.4167C3 7.21907 3 5.62028 3.37752 5.08241C3.75503 4.54454 5.25832 4.02996 8.26491 3.00079L8.83772 2.80472C10.405 2.26824 11.1886 2 12 2C12.8114 2 13.595 2.26824 15.1623 2.80472L15.7351 3.00079C18.7417 4.02996 20.245 4.54454 20.6225 5.08241C21 5.62028 21 7.21907 21 10.4167V11.9914C21 17.6294 16.761 20.3655 14.1014 21.5273C13.38 21.8424 13.0193 22 12 22C10.9807 22 10.62 21.8424 9.89856 21.5273C7.23896 20.3655 3 17.6294 3 11.9914V10.4167Z" fill="#dba617"></path> <path d="M12 7.25C12.4142 7.25 12.75 7.58579 12.75 8V12C12.75 12.4142 12.4142 12.75 12 12.75C11.5858 12.75 11.25 12.4142 11.25 12V8C11.25 7.58579 11.5858 7.25 12 7.25Z" fill="#dba617"></path> <path d="M12 16C12.5523 16 13 15.5523 13 15C13 14.4477 12.5523 14 12 14C11.4477 14 11 14.4477 11 15C11 15.5523 11.4477 16 12 16Z" fill="#dba617"></path> </g></svg>
								</label>
								<span style="color:rgba(219, 167, 23, 0.36);"><?php echo esc_html__('Highly risky', 'wc-blacklist-manager-premium'); ?></span>
							</div>
						</th>
						<td>
							<input type="number" class="small-text" disabled> <label class="premium-text">/ 60</label>
							<p class="premium-text"><?php echo __('Auto-adding the customers to the <strong>suspects list</strong> if they placed a new order that matched this score.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<div style="display: inline-flex;align-items: center;justify-content: center;">
								<label style="margin-right: 5px;">
									<svg width="24px" height="24px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path opacity="0.5" d="M3 10.4167C3 7.21907 3 5.62028 3.37752 5.08241C3.75503 4.54454 5.25832 4.02996 8.26491 3.00079L8.83772 2.80472C10.405 2.26824 11.1886 2 12 2C12.8114 2 13.595 2.26824 15.1623 2.80472L15.7351 3.00079C18.7417 4.02996 20.245 4.54454 20.6225 5.08241C21 5.62028 21 7.21907 21 10.4167V11.9914C21 17.6294 16.761 20.3655 14.1014 21.5273C13.38 21.8424 13.0193 22 12 22C10.9807 22 10.62 21.8424 9.89856 21.5273C7.23896 20.3655 3 17.6294 3 11.9914V10.4167Z" fill="#d63638"></path> <path d="M10.0303 8.96967C9.73744 8.67678 9.26256 8.67678 8.96967 8.96967C8.67678 9.26256 8.67678 9.73744 8.96967 10.0303L10.9394 12L8.96969 13.9697C8.6768 14.2626 8.6768 14.7374 8.96969 15.0303C9.26258 15.3232 9.73746 15.3232 10.0304 15.0303L12 13.0607L13.9696 15.0303C14.2625 15.3232 14.7374 15.3232 15.0303 15.0303C15.3232 14.7374 15.3232 14.2625 15.0303 13.9696L13.0607 12L15.0303 10.0303C15.3232 9.73746 15.3232 9.26258 15.0303 8.96969C14.7374 8.6768 14.2626 8.6768 13.9697 8.96969L12 10.9394L10.0303 8.96967Z" fill="#d63638"></path> </g></svg>
								</label>
								<span style="color:rgba(214, 54, 57, 0.36);"><?php echo esc_html__('Severe risky', 'wc-blacklist-manager-premium'); ?></span>
							</div>
						</th>
						<td>
							<input type="number" class="small-text" disabled> <label class="premium-text">/ 60</label>
							<p class="premium-text"><?php echo __('Auto-adding the customers to the <strong>blocklist</strong> if they placed a new order that matched this score and auto-cancelling if the "Auto cancel order" (Automation) option is enabled.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>					
				</table>				

				<p class="submit">
					<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" disabled/>
				</p>
			</form>
		</div>
	</div>

	<div id="tab-content-integrations" class="tab-content" style="display:none;">
		<div class="wrap">
			<form method="post" action="">

				<span class="yo-premium"><i class="dashicons dashicons-lock"></i> Power up with the finest third-party services to deliver the highest level of protection for your business <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" class="premium-label">Unlock</a></span>

				<h2 class="premium-text"><?php esc_html_e('Anti-bots by CAPTCHA', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
				<p class="premium-text"><?php esc_html_e('Prevent the bots from spamming orders on your site.', 'wc-blacklist-manager'); ?></p>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label class="premium-text"><?php echo esc_html__('Select Captcha', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select disabled>
								<option><?php echo esc_html__('reCaptcha v3', 'wc-blacklist-manager'); ?></option>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Site key (v3)', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Secret key (v3)', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled>
							<p class="premium-text"><?php esc_html_e('Enter Google reCAPTCHA site key and secret key.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/settings/integrations/6/" target="_blank"><?php esc_html_e('How to get the keys?', 'wc-blacklist-manager'); ?></a><br>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Checkout API', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php esc_html_e('Enable requiring CAPTCHA for checkout through API.', 'wc-blacklist-manager'); ?></label>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('Google API', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
				
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Google Maps Geocoding', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php esc_html_e('Enable to coordinates conversion using Google Maps Geocoding API.', 'wc-blacklist-manager'); ?></label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Google Maps API key', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled/>
							<p class="premium-text"><?php esc_html_e('Enter your Google Maps Geocoding API key.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/settings/integrations/2/" target="_blank"><?php esc_html_e('How to get the API?', 'wc-blacklist-manager'); ?></a></p>
							<p class="premium-text" style="display: inline-flex; align-items: center;"><span style="margin-right: 5px; padding: 3px 8px; color: white; background-color: #aaaaaa; font-size: 11px; border-radius: 5px;"><?php esc_html_e('Free', 'wc-blacklist-manager'); ?></span><?php esc_html_e('28,500 maploads per month for no charge.', 'wc-blacklist-manager'); ?><a href="https://mapsplatform.google.com/pricing/" target="_blank" style="margin-left: 5px;"><?php esc_html_e('Click here for more info.', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Address autocomplete', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php esc_html_e('Enable address autocomplete using Google Maps API in checkout page.', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php esc_html_e('We are highly recommended to use this to ensure accuracy and clarity.', 'wc-blacklist-manager'); ?><br></p>
						</td>
					</tr>
				</table>

				<h2 class="premium-text"><?php esc_html_e('Usercheck', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
		
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Usercheck', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php esc_html_e('Enable blocking disposable email address using usercheck.com.', 'wc-blacklist-manager'); ?></label>
							<p class="premium-text"><?php esc_html_e('You do not need to add a key to make it work, but it is limited to 60 requests per hour.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Usercheck API key', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled>
							<p class="premium-text"><?php esc_html_e('Enter your usercheck.com API key.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/settings/integrations/5/" target="_blank"><?php esc_html_e('How to get the API?', 'wc-blacklist-manager'); ?></a><br>
							<p class="premium-text" style="display: inline-flex; align-items: center;"><span style="margin-right: 5px; padding: 3px 8px; color: white; background-color: #aaaaaa; font-size: 11px; border-radius: 5px;"><?php esc_html_e('Free', 'wc-blacklist-manager'); ?></span><?php esc_html_e('1000 requests per month for no charge.', 'wc-blacklist-manager'); ?> <a href="https://www.usercheck.com/#pricing" target="_blank" style="margin-left: 5px;"><?php esc_html_e('Click here for more info.', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
				</table>

				<h2 class="premium-text"><?php esc_html_e('ZeroBounce', 'wc-blacklist-manager'); ?><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
				
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('ZeroBounce API key', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled>
							<p class="premium-text"><?php esc_html_e('Enter your zerobounce.net API key.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/settings/integrations/9/" target="_blank"><?php esc_html_e('How to get the API?', 'wc-blacklist-manager'); ?></a><br>
							<p class="premium-text" style="display: inline-flex; align-items: center;"><span style="margin-right: 5px; padding: 3px 8px; color: white; background-color: #aaaaaa; font-size: 11px; border-radius: 5px;"><?php esc_html_e('Free', 'wc-blacklist-manager'); ?></span><?php esc_html_e('100 requests per month for no charge.', 'wc-blacklist-manager'); ?> <a href="https://www.zerobounce.net?ref=owqwzgy" target="_blank" style="margin-left: 5px;"><?php esc_html_e('Click here for more info.', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('NumCheckr', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
				
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('NumCheckr', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php esc_html_e('Enable blocking disposable phone numbers using numcheckr.com.', 'wc-blacklist-manager'); ?></label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('NumCheckr API Token', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled/>
							<p class="premium-text"><?php esc_html_e('Enter your numcheckr.com API token.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/settings/integrations/4/" target="_blank">How to get the API?</a><br>
							<p class="premium-text" style="display: inline-flex; align-items: center;"><span style="margin-right: 5px; padding: 3px 8px; color: white; background-color: #aaaaaa; font-size: 11px; border-radius: 5px;"><?php esc_html_e('Free', 'wc-blacklist-manager'); ?></span><?php esc_html_e('100 requests per month for no charge.', 'wc-blacklist-manager'); ?> <a href="https://numcheckr.com/" target="_blank" style="margin-left: 5px;"><?php esc_html_e('Click here for more info.', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
				</table>			
				
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" disabled/>
				</p>
			</form>
		</div>
	</div>

	<div id="tab-content-payments" class="tab-content" style="display:none;">
		<div class="wrap">
			<form method="post" action="">

				<span class="yo-premium"><i class="dashicons dashicons-lock"></i> Disable payment gateways for Suspects list, integrates with the Stripe gateways <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" class="premium-label">Unlock</a></span>

				<h2><span class="premium-text"><?php echo esc_html__('Payment methods', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Disable for suspects', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
						<?php 
							// Get all enabled payment methods
							$available_gateways = WC()->payment_gateways->get_available_payment_gateways(); 
							$disabled_gateways = isset($settings['disabled_payment_methods']) ? $settings['disabled_payment_methods'] : [];

							foreach ( $available_gateways as $gateway ) : 
								// Create a unique name for each checkbox
								$checkbox_name = 'disable_payment_method_for_suspects_' . esc_attr($gateway->id);
								?>
								<input type="checkbox" 
									<?php checked(in_array($gateway->id, $disabled_gateways), true); ?> 
								disabled/>
								<label class="premium-text">
									<?php echo esc_html($gateway->get_title()); ?>
								</label><br/>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('Stripe gateway', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
				
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Stripe detection', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled/>
							<label class="premium-text"><?php esc_html_e('Enable to check customer card info through Stripe payment gateway.', 'wc-blacklist-manager'); ?></label>
						</td>
					</tr>
				</table>

				<h2><span class="premium-text"><?php echo esc_html__('Country settings', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('High risk countries', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" disabled/>
							<p class="premium-text"><?php esc_html_e('Choose high risk payment countries.', 'wc-blacklist-manager'); ?>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" disabled/>
				</p>
			</form>
		</div>
	</div>

	<div id="tab-content-permission" class="tab-content" style="display:none;">
		<div class="wrap">
			<form method="post" action="">

				<span class="yo-premium"><i class="dashicons dashicons-lock"></i> Opening to set the permission to help manage and easy teamwork <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" class="premium-label">Unlock</a></span>

				<h2><span class="premium-text"><?php echo esc_html__('Blacklist Manager Permission', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
				
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Dashboard', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<?php
							// Assuming $settings['roles'] is passed from the WC_Blacklist_Manager_Settings class
							foreach ($settings['roles'] as $role_key => $role_name): ?>
								<label>
									<input type="checkbox" value="<?php echo esc_attr($role_key); ?>" disabled>
									<span class="premium-text"><?php echo esc_html($role_name['name']); ?></span>
								</label>
								<br>
							<?php endforeach; ?>
							<p class="description" style="max-width: 500px; color: #aaaaaa;"><?php echo wp_kses_post( __( '<b>Note</b>: User roles have permission to control the dashboard will have permission to control the Suspect/Block buttons in Order page either.', 'wc-blacklist-manager' ) ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Notifications', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<?php foreach ($settings['roles'] as $role_key => $role_name): ?>
								<label>
									<input type="checkbox" value="<?php echo esc_attr($role_key); ?>" disabled>
									<span class="premium-text"><?php echo esc_html($role_name['name']); ?></span>
								</label>
								<br>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Verifications & Settings', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<?php foreach ($settings['roles'] as $role_key => $role_name): ?>
								<label>
									<input type="checkbox" value="<?php echo esc_attr($role_key); ?>" disabled>
									<span class="premium-text"><?php echo esc_html($role_name['name']); ?></span>
								</label>
								<br>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" disabled/>
				</p>
			</form>
		</div>
	</div>
	<div id="tab-content-tools" class="tab-content" style="display:none;">
		<div class="wrap">
			<span class="yo-premium"><i class="dashicons dashicons-lock"></i> Easily manage your blacklist data with our Import/Export feature <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" class="premium-label">Unlock</a></span>

			<h2><span class="premium-text"><?php echo esc_html__('Import / Export', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
			
			<p class="premium-text"><?php esc_html_e('Import or export blacklist entries from the data.', 'wc-blacklist-manager'); ?></p>

			<table class="form-table">
				<tr>
					<th>
						<label class="premium-text"><?php esc_html_e('Import CSV', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<form method="post" enctype="multipart/form-data">
							<span class="yobm-upload-form">
								<input type="file" disabled>
								<input type="submit" class="button-primary" value="<?php esc_attr_e('Import', 'wc-blacklist-manager'); ?>" disabled>
							</span>
						</form>
						<p class="premium-text" style="margin-top: 20px;">
							<?php 
							printf(
								__('Download the CSV sample file and add the entries to the correct column.', 'wc-blacklist-manager'), 
							); 
							?><br>
							<?php 
							printf(
								/* translators: %1$s: documentation link */
								__('Read <a href="%1$s" target="_blank">this article</a> for more details.', 'wc-blacklist-manager'), 
								esc_url('https://yoohw.com/docs/woocommerce-blacklist-manager/settings/tools/')
							); 
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th>
						<label class="premium-text"><?php esc_html_e('Export CSV', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<form method="post">
							<?php wp_nonce_field('wc_blacklist_export_action', 'wc_blacklist_export_nonce'); ?>
							<input type="submit" class="button-primary" value="<?php esc_attr_e('Export', 'wc-blacklist-manager'); ?>" disabled>
						</form>
					</td>
				</tr>
			</table>

			<h2><span class="premium-text"><?php esc_html_e('Logs settings', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
			<p class="premium-text"><?php esc_html_e('Tracks system events for monitoring and troubleshooting.', 'wc-blacklist-manager'); ?></p>

			<!-- Logging Settings Form -->
			<form method="post">
				<?php wp_nonce_field('wc_blacklist_manager_premium_settings_nonce', 'wc_blacklist_manager_premium_settings_nonce_field'); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label class="premium-text"><?php esc_html_e('Logger', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php esc_html_e('Enable logging', 'wc-blacklist-manager'); ?></label>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e('Save Settings', 'wc-blacklist-manager'); ?>" disabled/>
				</p>
			</form>
		</div>
	</div>
	<div id="tab-content-connection" class="tab-content" style="display:none;">
		<div class="wrap">
			<span class="yo-premium"><i class="dashicons dashicons-lock"></i> Connect multiple stores' blacklists together for a more robust and effective solution <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" class="premium-label">Unlock</a></span>

			<h2><span class="premium-text"><?php echo esc_html__('Blacklist connection', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>
	
			<table class="form-table">
				<tr>
					<th scope="row">
						<label class="premium-text"><?php echo esc_html__('Connection mode	', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select disabled>
							<option><?php echo esc_html__('Host site', 'wc-blacklist-manager'); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label class="premium-text"><?php echo esc_html__('Host site URL', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="text" value="https://yoursite.com" disabled>
						<a href="#" class="button button-secondary" disabled><?php echo esc_html__('Copy', 'wc-blacklist-manager'); ?></a>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label class="premium-text"><?php echo esc_html__('Host site key', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="text" disabled>
						<a href="#" class="button button-secondary" disabled><?php echo esc_html__('Generate a key', 'wc-blacklist-manager'); ?></a>
						<p class="description">
							<span class="premium-text">
								<?php echo esc_html__('Generate a new key to start using blacklist connection.', 'wc-blacklist-manager'); ?>
							</span>
							<a href="https://yoohw.com/docs/woocommerce-blacklist-manager/settings/connection/" target="_blank">
								<?php echo esc_html__('How it works?', 'wc-blacklist-manager'); ?>
							</a>
						</p>
					</td>
				</tr>
			</table>

			<h2><span class="premium-text"><?php echo esc_html__('Sub site connection', 'wc-blacklist-manager'); ?></span><a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label class="premium-text"><?php echo esc_html__('Auto approval', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Enable to automatically set the sub site connection as \'Connected\'', 'wc-blacklist-manager'); ?></label>
						<p class="premium-text"><?php echo esc_html__('If checked, your host site will be connected with the subsites without needing your permission.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label class="premium-text"><?php esc_html_e('Sub site list', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<table class="wp-list-table widefat fixed striped subsite-list-demo">
							<thead>
								<tr>
									<th class="column-id"><?php esc_html_e('ID', 'wc-blacklist-manager'); ?></th>
									<th><?php esc_html_e('Domain', 'wc-blacklist-manager'); ?></th>
									<th><?php esc_html_e('Key', 'wc-blacklist-manager'); ?></th>
									<th><?php esc_html_e('Connected date', 'wc-blacklist-manager'); ?></th>
									<th class="column-status"><?php esc_html_e('Status', 'wc-blacklist-manager'); ?></th>
									<th class="column-actions"><?php esc_html_e('Actions', 'wc-blacklist-manager'); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td colspan="6"><?php esc_html_e('No subsites found.', 'wc-blacklist-manager'); ?></td>
								</tr>
							</tbody>
						</table>
					</td>
				</tr>
			</table>
		</div>
	</div>
</div>

<script type="text/javascript">
	document.addEventListener('DOMContentLoaded', function () {
		var tabs = document.querySelectorAll('.nav-tab');
		var tabContents = document.querySelectorAll('.tab-content');

		function hideAllTabContents() {
			tabContents.forEach(function(content) {
				content.style.display = 'none';
			});
		}

		function showTabContent(tabId) {
			hideAllTabContents();
			var tabContent = document.querySelector(tabId);
			if (tabContent) {
				tabContent.style.display = 'block';
			} else {
				console.error('Tab content not found:', tabId);
			}
		}

		tabs.forEach(function(tab) {
			tab.addEventListener('click', function(event) {
				event.preventDefault();
				tabs.forEach(function(item) {
					item.classList.remove('nav-tab-active');
				});
				tab.classList.add('nav-tab-active');
				showTabContent(tab.getAttribute('href'));
				console.log('Switched to tab:', tab.getAttribute('href'));
			});
		});

		// Initially show the first tab content
		showTabContent('#tab-content-general');
	});
</script>

<style type="text/css">
	.nav-tab-wrapper {
		margin-bottom: 20px;
	}

	.tab-content {
		display: none;
	}

	.tab-content:first-child {
		display: block;
	}
</style>
