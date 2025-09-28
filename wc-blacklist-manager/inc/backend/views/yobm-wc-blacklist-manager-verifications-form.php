<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap">
	<?php settings_errors('wc_blacklist_verifications_settings'); ?>

	<form method="post" action="">
		<?php wp_nonce_field('wc_blacklist_verifications_action', 'wc_blacklist_verifications_nonce'); ?>

		<h2><?php echo esc_html__('Email verification', 'wc-blacklist-manager'); ?></h2>

		<table class="form-table">
			<?php if ($woocommerce_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label for="email_verification_enabled"><?php echo esc_html__('Checkout email', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="email_verification_enabled" name="email_verification_enabled" value="1" <?php checked(!empty($data['email_verification_enabled'])); ?>>
						<label for="email_verification_enabled"><?php echo esc_html__('Enable email address verification during checkout', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Require the customer to verify their email address by code before checking out.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr id="email_verification_action_row" style="<?php echo (!empty($data['email_verification_enabled'])) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<label for="email_verification_action" class="label_child"><?php echo esc_html__('Request verify', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select id="email_verification_action" name="email_verification_action">
							<option value="all" <?php selected($data['email_verification_action'], 'all'); ?>><?php echo esc_html__('All', 'wc-blacklist-manager'); ?></option>
							<option value="suspect" <?php selected($data['email_verification_action'], 'suspect'); ?>><?php echo esc_html__('Suspect', 'wc-blacklist-manager'); ?></option>
						</select>
						<p class="description"><?php echo wp_kses_post(__('<b>All</b>: Require new customer to verify email address before checkout.<br><b>Suspect</b>: Require the suspected customer to verify email address before checkout.', 'wc-blacklist-manager')); ?></p>
					</td>
				</tr>
				<tr id="phone_verification_email_settings_row" style="<?php echo (!empty($data['email_verification_enabled'])) ? '' : 'display: none;'; ?>">
					<?php if ($premium_active): ?>
						<th scope="row">
							<label for="email_verification_email_settings" class="label_child"><?php echo esc_html__('Email options', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<p><?php echo esc_html__('Resend', 'wc-blacklist-manager'); ?></p>
							<input type="number" id="email_verification_resend" name="email_verification_resend" value="<?php echo esc_attr($data['email_verification_resend'] ?? 180); ?>" min="30" max="3600"> <?php echo esc_html__('seconds.', 'wc-blacklist-manager'); ?>
							<p><?php echo esc_html__('Subject', 'wc-blacklist-manager'); ?></p>
							<input type="text" id="email_verification_subject" name="email_verification_subject" class="regular-text" value="<?php echo esc_attr( $data['email_verification_subject'] ?? $this->default_email_subject ); ?>">
							<p><?php echo esc_html__('Heading', 'wc-blacklist-manager'); ?></p>
							<input type="text" id="email_verification_heading" name="email_verification_heading" class="regular-text" value="<?php echo esc_attr( $data['email_verification_heading'] ?? $this->default_email_heading ); ?>">
							<p><?php echo esc_html__('Content', 'wc-blacklist-manager'); ?></p>
							<textarea id="email_verification_message" name="email_verification_message" rows="6" class="regular-text"><?php echo esc_textarea(!empty($data['email_verification_message']) ? $data['email_verification_message'] : $this->default_email_message); ?></textarea>
							<p class="description"><?php echo esc_html__('Add {first_name}, {last_name}, {site_name}, and {code} where you want them to appear. HTML allowed.', 'wc-blacklist-manager'); ?></p>
						</td>
					<?php endif; ?>
					<?php if (!$premium_active): ?>
						<th scope="row">
							<label class="label_child premium-text"><?php echo esc_html__('Email options', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<p class="premium-text"><?php echo esc_html__('Resend', 'wc-blacklist-manager'); ?></p>
							<input type="number" value="<?php echo esc_attr($data['email_verification_resend'] ?? 180); ?>" disabled> <span class="premium-text"><?php echo esc_html__('seconds.', 'wc-blacklist-manager'); ?></span><a href='<?php echo esc_url( $unlock_url ); ?>' target='_blank' class='premium-label'>Unlock</a>
							<p class="premium-text"><?php echo esc_html__('Subject', 'wc-blacklist-manager'); ?></p>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $data['email_verification_subject'] ?? $this->default_email_subject ); ?>" disabled>
							<p class="premium-text"><?php echo esc_html__('Heading', 'wc-blacklist-manager'); ?></p>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $data['email_verification_heading'] ?? $this->default_email_heading ); ?>" disabled>
							<p class="premium-text"><?php echo esc_html__('Content', 'wc-blacklist-manager'); ?></p>
							<textarea rows="6" class="regular-text" disabled><?php echo esc_textarea(!empty($data['email_verification_message']) ? $data['email_verification_message'] : $this->default_email_message); ?></textarea>
							<p class="premium-text"><?php echo esc_html__('Add {first_name}, {last_name}, {site_name}, and {code} where you want them to appear.', 'wc-blacklist-manager'); ?></p>
						</td>
					<?php endif; ?>
				</tr>
			<?php endif; ?>  
			<?php if ($premium_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="email_verification_real_time_validate"><?php echo esc_html__('Real-time validation', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="email_verification_real_time_validate" name="email_verification_real_time_validate" value="1" <?php checked(!empty($data['email_verification_real_time_validate'])); ?>>
						<?php if ($woocommerce_active): ?>
							<label for="email_verification_real_time_validate"><?php echo esc_html__('Enable real-time automatic email address validation on the register and checkout pages', 'wc-blacklist-manager'); ?></label>
						<?php else: ?>
							<label for="email_verification_real_time_validate"><?php echo esc_html__('Enable real-time automatic email address validation on the register page', 'wc-blacklist-manager'); ?></label>
						<?php endif; ?>

						<p class="description"><?php echo esc_html__('Avoid bounces, spam complaints, spam traps, or wrong types in the email address field by mistake.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/email-verification/#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if (!$premium_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site premium-text"></span>
						<label class="premium-text"><?php echo esc_html__('Real-time validation', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" disabled>
						<label class="premium-text"><?php echo esc_html__('Enable real-time automatic email address validation on the register and checkout pages', 'wc-blacklist-manager'); ?></label> <a href='<?php echo esc_url( $unlock_url ); ?>' target='_blank' class='premium-label'>Unlock</a>
						<p class="premium-text"><?php echo esc_html__('Avoid bounces, spam complaints, spam traps, or wrong types in the email address field by mistake.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/email-verification/#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<?php if ($woocommerce_active): ?>
			<h2><?php echo esc_html__('Phone verification', 'wc-blacklist-manager'); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label for="phone_verification_enabled"><?php echo esc_html__('Checkout phone', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="phone_verification_enabled" name="phone_verification_enabled" value="1" <?php checked(!empty($data['phone_verification_enabled'])); ?>>
						<label for="phone_verification_enabled"><?php echo esc_html__('Enable phone number verification during checkout', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Require the customer to verify their phone number by code before checking out.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<tr id="phone_verification_action_row" style="<?php echo (!empty($data['phone_verification_enabled'])) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<label for="phone_verification_action" class="label_child"><?php echo esc_html__('Request verify', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select id="phone_verification_action" name="phone_verification_action">
							<option value="all" <?php selected($data['phone_verification_action'], 'all'); ?>><?php echo esc_html__('All', 'wc-blacklist-manager'); ?></option>
							<option value="suspect" <?php selected($data['phone_verification_action'], 'suspect'); ?>><?php echo esc_html__('Suspect', 'wc-blacklist-manager'); ?></option>
						</select>
						<p class="description"><?php echo wp_kses_post(__('<b>All</b>: Require new customer to verify phone number before checkout.<br><b>Suspect</b>: Require the suspected customer to verify phone number before checkout.', 'wc-blacklist-manager')); ?></p>
					</td>
				</tr>
				<tr id="phone_verification_sms_settings_row" style="<?php echo (!empty($data['phone_verification_enabled'])) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<label for="phone_verification_sms_settings" class="label_child"><?php echo esc_html__('SMS options', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<p><?php echo esc_html__('Code length', 'wc-blacklist-manager'); ?></p>
						<input type="number" id="code_length" name="code_length" value="<?php echo esc_attr($data['phone_verification_code_length'] ?? 4); ?>" min="4" max="10"> <?php echo esc_html__('digits.', 'wc-blacklist-manager'); ?>
						<p><?php echo esc_html__('Resend', 'wc-blacklist-manager'); ?></p>
						<input type="number" id="resend" name="resend" value="<?php echo esc_attr($data['phone_verification_resend'] ?? 180); ?>" min="30" max="3600"> <?php echo esc_html__('seconds.', 'wc-blacklist-manager'); ?>
						<p><?php echo esc_html__('Limit', 'wc-blacklist-manager'); ?></p>
						<input type="number" id="limit" name="limit" value="<?php echo esc_attr($data['phone_verification_limit'] ?? 5); ?>" min="1" max="10"> <?php echo esc_html__('times.', 'wc-blacklist-manager'); ?>
						<p><?php echo esc_html__('Message', 'wc-blacklist-manager'); ?></p>
						<textarea id="message" name="message" rows="2" class="regular-text"><?php echo esc_textarea(!empty($data['phone_verification_message']) ? $data['phone_verification_message'] : $this->default_sms_message); ?></textarea>
						<p class="description"><?php echo esc_html__('Add {site_name}, {code} where you want them to appear.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-admin-site"></span>
							<label for="sms_service"><?php echo esc_html__('SMS service', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select id="sms_service" name="sms_service">
								<option value="yo_credits" <?php selected($data['sms_service'], 'yo_credits'); ?>>Yo Credits</option>
								<option value="twilio" <?php selected($data['sms_service'], 'twilio'); ?>>Twilio</option>
								<option value="textmagic" <?php selected($data['sms_service'], 'textmagic'); ?>>Textmagic</option>
							</select>
							<p id="sms_service_description" style="<?php echo ($data['sms_service'] !== 'yo_credits') ? '' : 'display: none;'; ?>" class="description">
								<?php
								/* translators: %1$s: opening <a> tag to the integrations tab, %2$s: closing </a> tag */
								printf(
									wp_kses(
										__( 'Go to %1$sIntegrations%2$s to set up the service. If you do not see your favorite service here, please let us know.', 'wc-blacklist-manager' ),
										[
											'a' => [
												'href'   => [],
												'target' => [],
											],
										]
									),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wc-blacklist-manager-settings&tab=integrations' ) ) . '" target="_blank">',
									'</a>'
								);
								// Contact link
								echo ' ';
								?>
								<a href="<?php echo esc_url( 'https://yoohw.com/contact-us' ); ?>" target="_blank">
									<?php esc_html_e( 'Contact us', 'wc-blacklist-manager' ); ?>
								</a>
							</p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-admin-site"></span>
							<label for="sms_service"><?php echo esc_html__('SMS service', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<select id="sms_service" name="sms_service">
								<option value="" disabled <?php echo $data['sms_service'] !== 'yo_credits' ? 'selected' : ''; ?>>
									<?php esc_html_e( 'Unknown', 'wc-blacklist-manager' ); ?>
								</option>
								<option value="yo_credits" <?php selected( $data['sms_service'], 'yo_credits' ); ?>>
									<?php esc_html_e( 'Yo Credits', 'wc-blacklist-manager' ); ?>
								</option>
								<option disabled>Twilio</option>
								<option disabled>Textmagic</option>
							</select>
							<p class="description"><?php echo esc_html__('Go premium to unlock other popular SMS services', 'wc-blacklist-manager'); ?> <a href='<?php echo esc_url( $unlock_url ); ?>' target='_blank' class='premium-label'>Unlock</a>
						</td>
					</tr>
				<?php endif; ?>
				<tr id="phone_verification_sms_key_row" style="<?php echo ($data['sms_service'] === 'yo_credits') ? '' : 'display: none;'; ?>">
					<th scope="row">
						<label for="phone_verification_sms_key" class="label_child"><?php echo esc_html__('Yo Credits key', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="text" id="phone_verification_sms_key" name="phone_verification_sms_key" value="<?php echo esc_attr($data['phone_verification_sms_key'] ?? ''); ?>" readonly>
						<a href="#" id="generate_key_button" class="button button-secondary" style="<?php echo !empty($data['phone_verification_sms_key']) ? 'display:none;' : ''; ?>"><?php echo esc_html__('Generate a key', 'wc-blacklist-manager'); ?></a>
						<a href="#" id="copy_key_button" class="button button-secondary" style="<?php echo empty($data['phone_verification_sms_key']) ? 'display:none;' : ''; ?>"><?php echo esc_html__('Copy', 'wc-blacklist-manager'); ?></a>
						<p id="sms_key_description" class="description">
							<span id="sms_key_message">
								<?php 
									if (!empty($data['phone_verification_sms_key'])) {
										echo esc_html__('Use this key when you purchase Yo Credits.', 'wc-blacklist-manager');
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
				<tr id="phone_verification_sms_quota_row" style="<?php echo ($data['sms_service'] === 'yo_credits') ? '' : 'display: none;'; ?>">
					<th scope="row">
						<label for="phone_verification_sms_quota" class="label_child"><?php echo esc_html__('Quota', 'wc-blacklist-manager'); ?></label>
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
						<p><a href="https://yoohw.com/product/sms-credits/" target="_blank" class="button button-secondary"><?php echo esc_html__('Purchase Yo Credits', 'wc-blacklist-manager'); ?></a></p>
					</td>
				</tr>
				<tr id="phone_verification_failed_email_row" style="<?php echo ($data['sms_service'] === 'yo_credits') ? '' : 'display: none;'; ?>">
					<th scope="row">
						<label for="phone_verification_failed_email" class="label_child"><?php echo esc_html__('Failed verification notify', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="phone_verification_failed_email" name="phone_verification_failed_email" value="1" <?php checked(!empty($data['phone_verification_failed_email'])); ?>>
						<label for="phone_verification_failed_email"><?php echo esc_html__('Enable the email notification to admin if it has failed sending verification code', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('You can add the additional email in the "Recipient(s)" option in the "Notifications" menu.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
							<label for="phone_verification_real_time_validate"><?php echo esc_html__('Real-time validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="phone_verification_real_time_validate" name="phone_verification_real_time_validate" value="1" <?php checked(!empty($data['phone_verification_real_time_validate'])); ?>>
							<label for="phone_verification_real_time_validate"><?php echo esc_html__('Enable real-time automatic phone number format validation on the checkout page', 'wc-blacklist-manager'); ?></label>
							<p class="description"><?php echo esc_html__('Avoid wrong types by mistake, automatically corrected in the phone number field.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/phone-verification/#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr id="phone_verification_format_validate_row" style="<?php echo (!empty($data['phone_verification_real_time_validate'])) ? '' : 'display: none;'; ?>">
						<th scope="row">
							<label for="phone_verification_format_validate" class="label_child"><?php echo esc_html__('Format validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<button id="yobm-phone-number-format" type="button" class="button button-secondary">
								<?php echo esc_html__('Set number format', 'wc-blacklist-manager'); ?>
							</button>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart premium-text"></span>
							<label class="premium-text"><?php echo esc_html__('Real-time validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Enable real-time automatic phone number format validation on the checkout page', 'wc-blacklist-manager'); ?></label> <a href='<?php echo esc_url( $unlock_url ); ?>' target='_blank' class='premium-label'>Unlock</a>
							<p class="premium-text"><?php echo esc_html__('Avoid wrong types by mistake, automatically corrected in the phone number field.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/phone-verification/#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ($premium_active && !$skip_country_code): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
							<label for="phone_verification_country_code_disabled"><?php echo esc_html__('Country code', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="phone_verification_country_code_disabled" name="phone_verification_country_code_disabled" value="1" <?php checked(!empty($data['phone_verification_country_code_disabled'])); ?>>
							<label for="phone_verification_country_code_disabled"><?php echo esc_html__('Disable the country code dropdown on the checkout page', 'wc-blacklist-manager'); ?></label>
							<p class="description"><?php echo esc_html__('The country code will be excluded from the phone number for the blacklist, verified list, and orders.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<?php if ($premium_active): ?>
				<h2><?php echo esc_html__('Name verification', 'wc-blacklist-manager'); ?></h2>
			<?php endif; ?>

			<?php if (!$premium_active): ?>
				<h2 class="premium-text"><?php echo esc_html__('Name verification', 'wc-blacklist-manager'); ?> <a href='<?php echo esc_url( $unlock_url ); ?>' target='_blank' class='premium-label'>Unlock</a></h2>
			<?php endif; ?>

			<table class="form-table">
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
							<label for="name_verification_auto_capitalization"><?php echo esc_html__('Auto capitalization', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="name_verification_auto_capitalization" name="name_verification_auto_capitalization" value="1" <?php checked(!empty($data['name_verification_auto_capitalization'])); ?>>
							<label for="name_verification_auto_capitalization"><?php echo esc_html__('Enable automatic capitalization of the customer first and last name', 'wc-blacklist-manager'); ?></label>
							<p class="description"><?php echo esc_html__('It will be auto-capitalized on the customer name on the checkout and edit account pages.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/name-verification" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
							<label for="name_verification_real_time_validate"><?php echo esc_html__('Real-time validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="name_verification_real_time_validate" name="name_verification_real_time_validate" value="1" <?php checked(!empty($data['name_verification_real_time_validate'])); ?>>
							<label for="name_verification_real_time_validate"><?php echo esc_html__('Enable real-time automatic customer name format validation on the checkout page', 'wc-blacklist-manager'); ?></label>
							<p class="description"><?php echo esc_html__('Avoid meaningless, spammy names in the first and last name fields.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/name-verification#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr id="name_verification_format_validate_row" style="<?php echo (!empty($data['name_verification_real_time_validate'])) ? '' : 'display: none;'; ?>">
						<th scope="row">
							<label for="name_verification_format_validate" class="label_child"><?php echo esc_html__('Format validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<button id="yobm-customer-name-format" type="button" class="button button-secondary">
								<?php echo esc_html__('Set name format', 'wc-blacklist-manager'); ?>
							</button>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart premium-text"></span>
							<label class="premium-text"><?php echo esc_html__('Auto capitalization', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Enable automatic capitalization of the customer first and last name', 'wc-blacklist-manager'); ?></label> <a href='<?php echo esc_url( $unlock_url ); ?>' target='_blank' class='premium-label'>Unlock</a>
							<p class="premium-text"><?php echo esc_html__('It will be auto-capitalized on the customer name on the checkout and edit account pages.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/name-verification" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart premium-text"></span>
							<label class="premium-text"><?php echo esc_html__('Real-time validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" disabled>
							<label class="premium-text"><?php echo esc_html__('Enable real-time automatic customer name format validation on the checkout page', 'wc-blacklist-manager'); ?></label> <a href='<?php echo esc_url( $unlock_url ); ?>' target='_blank' class='premium-label'>Unlock</a>
							<p class="premium-text"><?php echo esc_html__('Avoid meaningless, spammy names in the first and last name fields.', 'wc-blacklist-manager'); ?> <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/verifications/name-verification/#real-time-validation" target="_blank"><?php echo esc_html__('Know more', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<h2><?php echo esc_html__('User verification', 'wc-blacklist-manager'); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label><?php echo esc_html__('Advanced accounts', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<?php
						$plugin_slug = 'wc-advanced-accounts';
						$install_url = wp_nonce_url(
							self_admin_url('update.php?action=install-plugin&plugin=' . $plugin_slug),
							'install-plugin_' . $plugin_slug
						);
						$plugin_info_url = self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . $plugin_slug . '&TB_iframe=true&width=772&height=900');
						$settings_url = esc_url(admin_url('admin.php?page=wc-settings&tab=account&section=advanced'));

						if (!is_plugin_active($plugin_slug . '/' . $plugin_slug . '.php')) {
							echo '<p>' . sprintf(
								esc_html__('Enhance your WooCommerce account system with our %s plugin.', 'wc-blacklist-manager'),
								'<a href="' . esc_url($plugin_info_url) . '" class="thickbox" title="' . esc_attr__('WooCommerce Advanced Accounts', 'wc-blacklist-manager') . '">' . esc_html__('Advanced Accounts', 'wc-blacklist-manager') . '</a>'
							) . '</p>';
							echo '<p><a href="' . esc_url($install_url) . '" class="button button-primary">' . esc_html__('Install now', 'wc-blacklist-manager') . '</a></p>';
						} else {
							echo '<p>' . sprintf(
								esc_html__('WooCommerce Advanced Accounts is already active. %s', 'wc-blacklist-manager'),
								'<a href="' . $settings_url . '">' . esc_html__('Go to settings page.', 'wc-blacklist-manager') . '</a>'
							) . '</p>';
						}
						?>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function () {
				var emailVerificationCheckbox = document.getElementById('email_verification_enabled');
				var phoneVerificationCheckbox = document.getElementById('phone_verification_enabled');
				var smsService = document.getElementById('sms_service');
				var phoneVerificationRealtimeValidateCheckbox = document.getElementById('phone_verification_real_time_validate');
				var nameVerificationRealtimeValidateCheckbox = document.getElementById('name_verification_real_time_validate');

				var phoneVerificationSmsKeyRow = document.getElementById('phone_verification_sms_key_row');
				var phoneVerificationSmsQuotaRow = document.getElementById('phone_verification_sms_quota_row');

				if (emailVerificationCheckbox) {
					emailVerificationCheckbox.addEventListener('change', function () {
						if (emailVerificationCheckbox.checked) {
							phoneVerificationCheckbox.checked = false;
						}
					});
				}

				if (phoneVerificationCheckbox) {
					phoneVerificationCheckbox.addEventListener('change', function () {
						if (phoneVerificationCheckbox.checked) {
							emailVerificationCheckbox.checked = false;
						}
					});
				}

				// Rows
				var emailVerificationActionRow = document.getElementById('email_verification_action_row');
				var emailVerificationEmailSettingsRow = document.getElementById('phone_verification_email_settings_row');
				var phoneVerificationActionRow = document.getElementById('phone_verification_action_row');
				var phoneVerificationSmsSettingsRow = document.getElementById('phone_verification_sms_settings_row');
				var smsServiceDescriptionRow = document.getElementById('sms_service_description');
				var phoneVerificationFailedEmailRow = document.getElementById('phone_verification_failed_email_row');
				var phoneVerificationFormatValidateRow = document.getElementById('phone_verification_format_validate_row');
				var nameVerificationFormatValidateRow = document.getElementById('name_verification_format_validate_row');

				function toggleDisplay(element, display) {
					element.style.display = display ? '' : 'none';
				}

				// Email verification checkbox changes
				emailVerificationCheckbox.addEventListener('change', function () {
					toggleDisplay(emailVerificationActionRow, this.checked);
					toggleDisplay(emailVerificationEmailSettingsRow, this.checked);
				});

				// Phone verification checkbox changes
				phoneVerificationCheckbox.addEventListener('change', function () {
					var isChecked = this.checked;
					toggleDisplay(phoneVerificationActionRow, isChecked);
					toggleDisplay(phoneVerificationSmsSettingsRow, isChecked);
				});

				smsService.addEventListener('change', function () {
					toggleDisplay(phoneVerificationSmsKeyRow, this.value === 'yo_credits');
					toggleDisplay(phoneVerificationSmsQuotaRow, this.value === 'yo_credits');
					toggleDisplay(phoneVerificationFailedEmailRow, this.value === 'yo_credits');
					toggleDisplay(smsServiceDescriptionRow, this.value !== 'yo_credits');
					
				});

				if (phoneVerificationRealtimeValidateCheckbox) {
					phoneVerificationRealtimeValidateCheckbox.addEventListener('change', function () {
						toggleDisplay(phoneVerificationFormatValidateRow, this.checked);
					});
				}

				if (nameVerificationRealtimeValidateCheckbox) {
					nameVerificationRealtimeValidateCheckbox.addEventListener('change', function () {
						toggleDisplay(nameVerificationFormatValidateRow, this.checked);
					});
				}

				// SMS key generate
				var smsKeyInput = document.getElementById('phone_verification_sms_key');
				var generateKeyButton = document.getElementById('generate_key_button');
				var copyKeyButton = document.getElementById('copy_key_button');
				var smsKeyMessage = document.getElementById('sms_key_message');

				// Check if key already exists
				if (smsKeyInput.value) {
					generateKeyButton.style.display = 'none';
					copyKeyButton.style.display = 'inline-block';
					smsKeyMessage.textContent = 'Use this key when you purchase Yo Credits.';
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
						var response;
						try {
							response = JSON.parse(xhr.responseText);
						} catch (error) {
							alert('<?php echo esc_js(__('Unexpected server response. Please try again.', 'wc-blacklist-manager')); ?>');
							return;
						}
						
						if (xhr.status === 200 && response.success) {
							// Set the generated key to the input field
							smsKeyInput.value = key;
							// Hide the "Generate a key" button and show the "Copy" button
							generateKeyButton.style.display = 'none';
							copyKeyButton.style.display = 'inline-block';
							// Update the description
							smsKeyMessage.textContent = '<?php echo esc_js(__('Use this key when you purchase Yo Credits.', 'wc-blacklist-manager')); ?>';
							alert(response.data.message);
						} else {
							var errorMsg = response && response.data && response.data.message 
								? response.data.message 
								: '<?php echo esc_js(__('Failed to generate the key. Please try again.', 'wc-blacklist-manager')); ?>';
							alert(errorMsg);
						}
					};
					xhr.send('action=generate_sms_key&sms_key=' + encodeURIComponent(key) + '&security=<?php echo esc_js(wp_create_nonce('generate_sms_key_nonce')); ?>');
				});

				// Copy functionality
				copyKeyButton.addEventListener('click', function (e) {
					e.preventDefault();
					smsKeyInput.select();
					document.execCommand('copy');
					copyKeyButton.textContent = '<?php echo esc_js(__('Copied!', 'wc-blacklist-manager')); ?>';
					setTimeout(function () {
						copyKeyButton.textContent = '<?php echo esc_js(__('Copy', 'wc-blacklist-manager')); ?>';
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
