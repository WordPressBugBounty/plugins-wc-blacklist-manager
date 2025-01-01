<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap">
	<?php if (!$premium_active): ?>
		<p><?php esc_html_e('Please support us by', 'wc-blacklist-manager'); ?> <a href="https://wordpress.org/plugins/wc-blacklist-manager/#reviews" target="_blank"><?php esc_html_e('leaving a review', 'wc-blacklist-manager'); ?></a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> <?php esc_html_e('to keep updating & improving.', 'wc-blacklist-manager'); ?></p>
	<?php endif; ?>

	<h1>
		<?php echo esc_html__('Verifications', 'wc-blacklist-manager'); ?>
		<?php if (get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/verifications/" target="_blank" class="button button-secondary" style="display: inline-flex; align-items: center;"><span class="dashicons dashicons-editor-help"></span> Documents</a>
		<?php endif; ?>
		<?php if (!$premium_active): ?>
			<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php esc_html_e('Support / Suggestion', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary">Premium support</a>
		<?php endif; ?>
	</h1>

	<p><?php echo esc_html__('Each email address or phone number will only need to be verified once.', 'wc-blacklist-manager'); ?></p>

	<?php settings_errors('wc_blacklist_verifications_settings'); ?>

	<form method="post" action="">
		<?php wp_nonce_field('wc_blacklist_verifications_action', 'wc_blacklist_verifications_nonce'); ?>

		<h2><?php echo esc_html__('Email verification', 'wc-blacklist-manager'); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="email_verification_enabled"><?php echo esc_html__('Checkout email:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<input type="checkbox" id="email_verification_enabled" name="email_verification_enabled" value="1" <?php checked(!empty($data['email_verification_enabled'])); ?>>
					<label for="email_verification_enabled"><?php echo esc_html__('Enable email address verification during checkout.', 'wc-blacklist-manager'); ?></label>
				</td>
			</tr>
			<tr id="email_verification_action_row" style="<?php echo (!empty($data['email_verification_enabled'])) ? '' : 'display: none;'; ?>">
				<th scope="row">
					<label for="email_verification_action"><?php echo esc_html__('Request verify:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<select id="email_verification_action" name="email_verification_action">
						<option value="all" <?php selected($data['email_verification_action'], 'all'); ?>><?php echo esc_html__('All', 'wc-blacklist-manager'); ?></option>
						<option value="suspect" <?php selected($data['email_verification_action'], 'suspect'); ?>><?php echo esc_html__('Suspect', 'wc-blacklist-manager'); ?></option>
					</select>
					<p class="description"><?php echo wp_kses_post(__('<b>All</b>: Require new customer to verify email address before checkout.<br><b>Suspect</b>: Require the suspected customer to verify email address before checkout.', 'wc-blacklist-manager')); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php echo esc_html__('Phone verification', 'wc-blacklist-manager'); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="phone_verification_enabled"><?php echo esc_html__('Checkout phone:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<input type="checkbox" id="phone_verification_enabled" name="phone_verification_enabled" value="1" <?php checked(!empty($data['phone_verification_enabled'])); ?>>
					<label for="phone_verification_enabled"><?php echo esc_html__('Enable phone number verification during checkout.', 'wc-blacklist-manager'); ?></label>
				</td>
			</tr>
			<tr id="phone_verification_action_row" style="<?php echo (!empty($data['phone_verification_enabled'])) ? '' : 'display: none;'; ?>">
				<th scope="row">
					<label for="phone_verification_action"><?php echo esc_html__('Request verify:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<select id="phone_verification_action" name="phone_verification_action">
						<option value="all" <?php selected($data['phone_verification_action'], 'all'); ?>><?php echo esc_html__('All', 'wc-blacklist-manager'); ?></option>
						<option value="suspect" <?php selected($data['phone_verification_action'], 'suspect'); ?>><?php echo esc_html__('Suspect', 'wc-blacklist-manager'); ?></option>
					</select>
					<p class="description"><?php echo wp_kses_post(__('<b>All</b>: Require new customer to verify phone number before checkout.<br><b>Suspect</b>: Require the suspected customer to verify phone number before checkout.', 'wc-blacklist-manager')); ?></p>
				</td>
			</tr>
			<tr id="phone_verification_sms_key_row" style="<?php echo (!empty($data['phone_verification_enabled'])) ? '' : 'display: none;'; ?>">
				<th scope="row">
					<label for="phone_verification_sms_key"><?php echo esc_html__('SMS verification key:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<input type="text" id="phone_verification_sms_key" name="phone_verification_sms_key" value="<?php echo esc_attr($data['phone_verification_sms_key'] ?? ''); ?>" readonly>
					<a href="#" id="generate_key_button" class="button button-secondary" style="<?php echo !empty($data['phone_verification_sms_key']) ? 'display:none;' : ''; ?>"><?php echo esc_html__('Generate a key', 'wc-blacklist-manager'); ?></a>
					<a href="#" id="copy_key_button" class="button button-secondary" style="<?php echo empty($data['phone_verification_sms_key']) ? 'display:none;' : ''; ?>"><?php echo esc_html__('Copy', 'wc-blacklist-manager'); ?></a>
					<p id="sms_key_description" class="description">
						<span id="sms_key_message">
							<?php 
								if (!empty($data['phone_verification_sms_key'])) {
									echo esc_html__('Use this key when you purchase SMS credits.', 'wc-blacklist-manager');
								} else {
									echo esc_html__('Generate a new key to start using SMS Verification.', 'wc-blacklist-manager');
								}
							?>
						</span>
						<a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/phone-verification/" target="_blank">
							<?php echo esc_html__('How it works?', 'wc-blacklist-manager'); ?>
						</a>
					</p>
				</td>
			</tr>
			<tr id="phone_verification_sms_quota_row" style="<?php echo (!empty($data['phone_verification_enabled'])) ? '' : 'display: none;'; ?>">
				<th scope="row">
					<label for="phone_verification_sms_quota"><?php echo esc_html__('SMS quota:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<?php
					$remaining_sms = floatval(get_option('yoohw_phone_verification_sms_quota', 0));

					if ($remaining_sms > 15) {
						$text_color = '#00a32a';
					} elseif ($remaining_sms > 5) {
						$text_color = '#dba617';
					} else {
						$text_color = '#d63638';
					}

					$sms_key = get_option('yoohw_phone_verification_sms_key', '');

					$remaining_text = sprintf(esc_html__('%s USD credits remaining.', 'wc-blacklist-manager'), number_format($remaining_sms, 2));
					?>

					<p style="color: <?php echo esc_attr($text_color); ?>;">
						<?php echo esc_html($remaining_text); ?> 
						<?php if ( ! empty( $sms_key ) ) : ?>
							<a href="https://bmc.yoohw.com/sms/smslog/<?php echo esc_attr($sms_key); ?>" target="_blank">
								<?php echo esc_html__('[Credits history]', 'wc-blacklist-manager'); ?>
							</a>
						<?php endif; ?>
					</p>
					<p><a href="https://yoohw.com/product/sms-credits/" target="_blank" class="button button-secondary"><?php echo esc_html__('Purchase SMS credits', 'wc-blacklist-manager'); ?></a></p>
				</td>
			</tr>
			<tr id="phone_verification_sms_settings_row" style="<?php echo (!empty($data['phone_verification_enabled'])) ? '' : 'display: none;'; ?>">
				<th scope="row">
					<label for="phone_verification_sms_settings"><?php echo esc_html__('SMS options:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<p><?php echo esc_html__('Code length', 'wc-blacklist-manager'); ?></p>
					<input type="number" id="code_length" name="code_length" value="<?php echo esc_attr($data['phone_verification_code_length'] ?? 4); ?>" min="4" max="10"> <?php echo esc_html__('digits.', 'wc-blacklist-manager'); ?>
					<p><?php echo esc_html__('Resend', 'wc-blacklist-manager'); ?></p>
					<input type="number" id="resend" name="resend" value="<?php echo esc_attr($data['phone_verification_resend'] ?? 180); ?>" min="60" max="3600"> <?php echo esc_html__('seconds.', 'wc-blacklist-manager'); ?>
					<p><?php echo esc_html__('Limit', 'wc-blacklist-manager'); ?></p>
					<input type="number" id="limit" name="limit" value="<?php echo esc_attr($data['phone_verification_limit'] ?? 5); ?>" min="1" max="10"> <?php echo esc_html__('times.', 'wc-blacklist-manager'); ?>
					<p><?php echo esc_html__('Message', 'wc-blacklist-manager'); ?></p>
					<textarea id="message" name="message" rows="2" class="regular-text"><?php echo esc_textarea(!empty($data['phone_verification_message']) ? $data['phone_verification_message'] : $this->default_sms_message); ?></textarea>
					<p class="description"><?php echo esc_html__('Add {site_name}, {code} where you want them to appear.', 'wc-blacklist-manager'); ?></p>
				</td>
			</tr>
		</table>

		<?php if (!$premium_active): ?>
			<h2 class='premium-text'><?php echo esc_html__('Advanced', 'wc-blacklist-manager'); ?> <a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></h2>
		<?php endif; ?>

		<?php if ($premium_active): ?>
			<h2><?php echo esc_html__('Advanced', 'wc-blacklist-manager'); ?></h2>
		<?php endif; ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="merge_completed_order_whitelist" class="<?php echo !$premium_active ? 'premium-text' : ''; ?>">
						<?php echo esc_html__('Verifications merge:', 'wc-blacklist-manager'); ?>
					</label>
				</th>
				<td>
					<?php if (!$premium_active): ?>
						<button id="merge_button" class="button button-secondary" disabled>
							<?php echo esc_html__('Start to merge', 'wc-blacklist-manager'); ?>
						</button>
						<p class="description" style="max-width: 500px; color: #aaaaaa;">
							<?php echo esc_html__('This will set all of the emails and phones from the completed orders to verified. So the return customers will not need to verify their emails or phone numbers anymore.', 'wc-blacklist-manager'); ?>
						</p>
					<?php else: ?>
						<?php if (get_option('wc_blacklist_whitelist_merged_success') != 1) : ?>
							<a href="<?php echo esc_url(admin_url('admin-post.php?action=merge_completed_orders_to_whitelist')); ?>" id="merge_button" class="button button-secondary">
								<?php echo esc_html__('Start to merge', 'wc-blacklist-manager'); ?>
							</a>
							<span id="loading_indicator" class="loading-indicator" style="display: none;">
								<img src="<?php echo esc_url(admin_url('images/spinner.gif')); ?>" alt="Loading...">
								<?php echo esc_html__('Merging... Please wait, DO NOT leave the page until finished.', 'wc-blacklist-manager'); ?>
							</span>
							<span id="finished_message" class="finished-message" style="display: none; color: green;"></span>
							<p class="description" style="max-width: 500px;">
								<?php echo esc_html__('This will set all of the emails and phones from the completed orders to verified. So the return customers will not need to verify their emails or phone numbers anymore.', 'wc-blacklist-manager'); ?>
							</p>
						<?php else : ?>
							<span style="color: green;">
								<?php echo esc_html__('Merged successfully.', 'wc-blacklist-manager'); ?>
							</span>
							<p>
								<a href="<?php echo esc_url(admin_url('admin-post.php?action=refresh_merging')); ?>" id="refresh_button" class="button button-secondary">
									<?php echo esc_html__('Refresh merging', 'wc-blacklist-manager'); ?>
								</a>
							</p>
							<p class="description" style="max-width: 500px;">
								<?php echo esc_html__('Refresh to re-merge the emails and phones from the completed orders to be verified again.', 'wc-blacklist-manager'); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function () {
				var emailVerificationCheckbox = document.getElementById('email_verification_enabled');
				var phoneVerificationCheckbox = document.getElementById('phone_verification_enabled');

				emailVerificationCheckbox.addEventListener('change', function () {
					if (emailVerificationCheckbox.checked) {
						phoneVerificationCheckbox.checked = false;
					}
				});

				phoneVerificationCheckbox.addEventListener('change', function () {
					if (phoneVerificationCheckbox.checked) {
						emailVerificationCheckbox.checked = false;
					}
				});

				var generateKeyButton = document.getElementById('generate_key_button');
				var smsKeyInput = document.getElementById('phone_verification_sms_key');

				// Rows
				var emailVerificationActionRow = document.getElementById('email_verification_action_row');
				var phoneVerificationActionRow = document.getElementById('phone_verification_action_row');
				var phoneVerificationSmsKeyRow = document.getElementById('phone_verification_sms_key_row');
				var phoneVerificationSmsQuotaRow = document.getElementById('phone_verification_sms_quota_row');
				var phoneVerificationSmsSettingsRow = document.getElementById('phone_verification_sms_settings_row');

				function toggleDisplay(element, display) {
					element.style.display = display ? '' : 'none';
				}

				// Email verification checkbox changes
				emailVerificationCheckbox.addEventListener('change', function () {
					toggleDisplay(emailVerificationActionRow, this.checked);
				});

				// Phone verification checkbox changes
				phoneVerificationCheckbox.addEventListener('change', function () {
					var isChecked = this.checked;
					toggleDisplay(phoneVerificationActionRow, isChecked);
					toggleDisplay(phoneVerificationSmsKeyRow, isChecked);
					toggleDisplay(phoneVerificationSmsQuotaRow, isChecked);
					toggleDisplay(phoneVerificationSmsSettingsRow, isChecked);
				});

				// SMS key generate
				var smsKeyInput = document.getElementById('phone_verification_sms_key');
				var generateKeyButton = document.getElementById('generate_key_button');
				var copyKeyButton = document.getElementById('copy_key_button');
				var smsKeyMessage = document.getElementById('sms_key_message');

				// Check if key already exists
				if (smsKeyInput.value) {
					generateKeyButton.style.display = 'none';
					copyKeyButton.style.display = 'inline-block';
					smsKeyMessage.textContent = 'Use this key when you purchase SMS credits.';
				} else {
					generateKeyButton.style.display = 'inline-block';
					copyKeyButton.style.display = 'none';
					smsKeyMessage.textContent = 'Generate a new key to start using SMS Verification.';
				}

				// Generate key functionality
				generateKeyButton.addEventListener('click', function (e) {
					e.preventDefault();

					// Generate a unique key of length 20
					var key = generateRandomKey(20);

					// Save the key via AJAX
					var xhr = new XMLHttpRequest();
					xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>', true);
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.onload = function () {
						if (xhr.status === 200) {
							// Set the generated key to the input field
							smsKeyInput.value = key;
							// Hide the "Generate a key" button and show the "Copy" button
							generateKeyButton.style.display = 'none';
							copyKeyButton.style.display = 'inline-block';
							// Update the description
							smsKeyMessage.textContent = '<?php echo esc_js(__('Use this key when you purchase SMS credits.', 'wc-blacklist-manager')); ?>';
							alert('<?php echo esc_js(__('Key generated and saved successfully.', 'wc-blacklist-manager')); ?>');
						} else {
							alert('<?php echo esc_js(__('Failed to generate the key. Please try again.', 'wc-blacklist-manager')); ?>');
						}
					};
					xhr.send('action=generate_sms_key&sms_key=' + encodeURIComponent(key) + '&security=<?php echo esc_js(wp_create_nonce('generate_sms_key_nonce')); ?>');
				});

				// Copy functionality
				copyKeyButton.addEventListener('click', function (e) {
					e.preventDefault();
					smsKeyInput.select();
					document.execCommand('copy');
					copyKeyButton.textContent = 'Copied!';
					setTimeout(function () {
						copyKeyButton.textContent = 'Copy';
					}, 2000); // Reset button text after 2 seconds
				});

				function generateRandomKey(length) {
					var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
					var result = '';
					var charactersLength = characters.length;
					for (var i = 0; i < length; i++) {
						result += characters.charAt(Math.floor(Math.random() * charactersLength));
					}
					return result;
				}

				// Merge the complepleted order to whitelist
				var mergeButton = document.getElementById('merge_button');
				var loadingIndicator = document.getElementById('loading_indicator');
				var finishedMessage = document.getElementById('finished_message');

				if (mergeButton) {
					mergeButton.addEventListener('click', function (e) {
						loadingIndicator.style.display = 'inline-block';
						finishedMessage.style.display = 'none';
					});
				}

				window.updateMergeProgress = function (processed, total) {
					if (processed === total) {
						loadingIndicator.style.display = 'none';
						finishedMessage.textContent = 'All done! Finished ' + total + '/' + total + '.';
						finishedMessage.style.display = 'inline-block';
					} else {
						loadingIndicator.innerHTML = 'Completed orders found: ' + total + '. Merging... ' + processed + '/' + total;
					}
				};
			});
		</script>

		<p class="submit">
			<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" />
		</p>
	</form>
</div>
