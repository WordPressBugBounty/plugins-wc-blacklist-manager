<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap">
	<p class="description"><?php echo esc_html__('Each email address or phone number will only need to be verified once.', 'wc-blacklist-manager'); ?></p>

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
					<label for="email_verification_enabled"><?php echo esc_html__('Enable email address verification during checkout', 'wc-blacklist-manager'); ?></label>
					<p class="description"><?php echo esc_html__('Require the customer to verify their email address by code before checking out.', 'wc-blacklist-manager'); ?></p>
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
			<?php if ($premium_active): ?>
				<tr>
					<th scope="row">
						<label for="email_verification_real_time_validate"><?php echo esc_html__('Real-time validation:', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="email_verification_real_time_validate" name="email_verification_real_time_validate" value="1" <?php checked(!empty($data['email_verification_real_time_validate'])); ?>>
						<label for="email_verification_real_time_validate"><?php echo esc_html__('Enable real-time automatic email address validation on the checkout page', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Avoid bounces, spam complaints, spam traps, or wrong types in the email address field by mistake.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/email-verification/#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if (!$premium_active): ?>
				<tr>
					<th scope="row">
						<label class='premium-text'><?php echo esc_html__('Real-time validation:', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class='premium-text'><?php echo esc_html__('Enable real-time automatic email address validation on the checkout page', 'wc-blacklist-manager'); ?></label> <a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a>
						<p class="premium-text"><?php echo esc_html__('Avoid bounces, spam complaints, spam traps, or wrong types in the email address field by mistake.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/email-verification/#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<h2><?php echo esc_html__('Phone verification', 'wc-blacklist-manager'); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="phone_verification_enabled"><?php echo esc_html__('Checkout phone:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<input type="checkbox" id="phone_verification_enabled" name="phone_verification_enabled" value="1" <?php checked(!empty($data['phone_verification_enabled'])); ?>>
					<label for="phone_verification_enabled"><?php echo esc_html__('Enable phone number verification during checkout', 'wc-blacklist-manager'); ?></label>
					<p class="description"><?php echo esc_html__('Require the customer to verify their phone number by code before checking out.', 'wc-blacklist-manager'); ?></p>
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
			<tr id="phone_verification_failed_email_row" style="<?php echo (!empty($data['phone_verification_enabled'])) ? '' : 'display: none;'; ?>">
				<th scope="row">
					<label for="phone_verification_failed_email"><?php echo esc_html__('Failed verification notify:', 'wc-blacklist-manager'); ?></label>
				</th>
				<td>
					<input type="checkbox" id="phone_verification_failed_email" name="phone_verification_failed_email" value="1" <?php checked(!empty($data['phone_verification_failed_email'])); ?>>
					<label for="phone_verification_failed_email"><?php echo esc_html__('Enable the email notification to admin when the customer has failed verification', 'wc-blacklist-manager'); ?></label>
					<p class="description"><?php echo esc_html__('You can add the additional recipient(s) in the "Additional email(s)" option in the "Notifications" menu.', 'wc-blacklist-manager'); ?></p>
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
				var emailVerificationContentRow = document.getElementById('email_verification_email_content_row');
				var phoneVerificationActionRow = document.getElementById('phone_verification_action_row');
				var phoneVerificationSmsKeyRow = document.getElementById('phone_verification_sms_key_row');
				var phoneVerificationSmsQuotaRow = document.getElementById('phone_verification_sms_quota_row');
				var phoneVerificationSmsSettingsRow = document.getElementById('phone_verification_sms_settings_row');
				var phoneVerificationFailedEmailRow = document.getElementById('phone_verification_failed_email_row');

				function toggleDisplay(element, display) {
					element.style.display = display ? '' : 'none';
				}

				// Email verification checkbox changes
				emailVerificationCheckbox.addEventListener('change', function () {
					toggleDisplay(emailVerificationActionRow, this.checked);
					toggleDisplay(emailVerificationContentRow, this.checked);
				});

				// Phone verification checkbox changes
				phoneVerificationCheckbox.addEventListener('change', function () {
					var isChecked = this.checked;
					toggleDisplay(phoneVerificationActionRow, isChecked);
					toggleDisplay(phoneVerificationSmsKeyRow, isChecked);
					toggleDisplay(phoneVerificationSmsQuotaRow, isChecked);
					toggleDisplay(phoneVerificationSmsSettingsRow, isChecked);
					toggleDisplay(phoneVerificationFailedEmailRow, isChecked);
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
			});
		</script>

		<?php if ($premium_active): ?>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function () {
				function handleRealtimeValidateCheckbox() {
					var realtimeValidateCheckbox = document.getElementById('email_verification_real_time_validate');

					realtimeValidateCheckbox.addEventListener('click', function (event) {
						var zeroBounceApiKey = <?php echo json_encode(!empty(get_option('wc_blacklist_manager_premium_zerobounce_api_key'))); ?>;

						if (!zeroBounceApiKey) {
							event.preventDefault();
							alert("<?php echo esc_html__('Please set the ZeroBounce API key to allow this option to work.', 'wc-blacklist-manager'); ?>");
							window.location.href = 'admin.php?page=wc-blacklist-manager-settings&tab=integrations';
						}
					});
				}
				handleRealtimeValidateCheckbox();
			});
		</script>
		<?php endif; ?>

		<p class="submit">
			<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" />
		</p>
	</form>
</div>
