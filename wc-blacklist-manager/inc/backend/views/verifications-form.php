<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';
?>

	<div class="wrap">
		<?php settings_errors('wc_blacklist_verifications_settings'); ?>

		<?php wp_nonce_field('wc_blacklist_verifications_action', 'wc_blacklist_verifications_nonce'); ?>

		<?php if ( $woocommerce_active ) : ?>
			<h2><?php echo esc_html__( 'Checkout verification', 'wc-blacklist-manager' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="checkout_verification_interface"><?php echo esc_html__( 'Verification interface', 'wc-blacklist-manager' ); ?></label>
					</th>
					<td>
						<select id="checkout_verification_interface" name="checkout_verification_interface">
							<option value="inline" <?php selected( $data['checkout_verification_interface'], 'inline' ); ?>><?php echo esc_html__( 'Inline', 'wc-blacklist-manager' ); ?></option>
							<option value="popup_modal" <?php selected( $data['checkout_verification_interface'], 'popup_modal' ); ?>><?php echo esc_html__( 'Popup modal', 'wc-blacklist-manager' ); ?></option>
						</select>
						<p class="description"><?php echo esc_html__( 'Choose how enabled email and phone verification steps appear during checkout. This setting changes presentation only; it does not change who must verify. When both channels are enabled, email verification is completed before phone verification.', 'wc-blacklist-manager' ); ?></p>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<?php if ( $woocommerce_active ) : ?>
			<h2><?php echo esc_html__( 'Email verification', 'wc-blacklist-manager' ); ?></h2>
		<?php endif; ?>

		<table class="form-table">
				<?php if ($woocommerce_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label for="email_verification_enabled"><?php echo esc_html__('Email verification at checkout', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="email_verification_enabled" name="email_verification_enabled" value="1" <?php checked(!empty($data['email_verification_enabled'])); ?>>
						<label for="email_verification_enabled"><?php echo esc_html__('Enable email verification at checkout', 'wc-blacklist-manager'); ?></label>
						<p class="description">
							<?php echo esc_html__('When required by the policy below, customers must prove control of the submitted email address with a one-time code.', 'wc-blacklist-manager'); ?><br />
							<span style="color:#b32d2e;">
								<?php echo esc_html__('Verification codes use WooCommerce email delivery. For more reliable delivery, consider configuring a trusted SMTP service instead of the hosting provider\'s default mail function.', 'wc-blacklist-manager'); ?>
							</span>
						</p>
					</td>
				</tr>
				<tr id="email_verification_action_row" style="<?php echo (!empty($data['email_verification_enabled'])) ? '' : 'display: none;'; ?>">
					<th scope="row">
						<label for="email_verification_action" class="label_child"><?php echo esc_html__('When verification is required', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select id="email_verification_action" name="email_verification_action">
							<option value="all" <?php selected($data['email_verification_action'], 'all'); ?>><?php echo esc_html__('All applicable customers', 'wc-blacklist-manager'); ?></option>
							<option value="suspect" <?php selected($data['email_verification_action'], 'suspect'); ?>><?php echo esc_html__('Suspected customers only', 'wc-blacklist-manager'); ?></option>
						</select>
						<p class="description"><?php echo wp_kses_post(__('<b>All applicable customers:</b> Require verification unless this exact email address is already allowed to skip repeat verification.<br><b>Suspected customers only:</b> Require verification only while this exact email address is currently flagged as suspect and has not already been resolved.', 'wc-blacklist-manager')); ?></p>
					</td>
				</tr>
				<tr id="phone_verification_email_settings_row" style="<?php echo (!empty($data['email_verification_enabled'])) ? '' : 'display: none;'; ?>">
					<?php if ($premium_active): ?>
						<th scope="row">
							<label for="email_verification_email_settings" class="label_child"><?php echo esc_html__('Verification email', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<p><?php echo esc_html__('Resend delay', 'wc-blacklist-manager'); ?></p>
							<input type="number" id="email_verification_resend" name="email_verification_resend" value="<?php echo esc_attr($data['email_verification_resend'] ?? 180); ?>" min="30" max="3600"> <?php echo esc_html__('seconds.', 'wc-blacklist-manager'); ?>
							<p><?php echo esc_html__('Email subject', 'wc-blacklist-manager'); ?></p>
							<input type="text" id="email_verification_subject" name="email_verification_subject" class="regular-text" value="<?php echo esc_attr( $data['email_verification_subject'] ?? $this->default_email_subject ); ?>">
							<p><?php echo esc_html__('Email heading', 'wc-blacklist-manager'); ?></p>
							<input type="text" id="email_verification_heading" name="email_verification_heading" class="regular-text" value="<?php echo esc_attr( $data['email_verification_heading'] ?? $this->default_email_heading ); ?>">
							<p><?php echo esc_html__('Email message', 'wc-blacklist-manager'); ?></p>
							<textarea id="email_verification_message" name="email_verification_message" rows="6" class="regular-text"><?php echo esc_textarea(!empty($data['email_verification_message']) ? $data['email_verification_message'] : $this->default_email_message); ?></textarea>
							<p class="description"><?php echo esc_html__('Add {first_name}, {last_name}, {site_name}, and {code} where you want them to appear. HTML allowed.', 'wc-blacklist-manager'); ?></p>
						</td>
					<?php endif; ?>
					<?php if (!$premium_active): ?>
						<th scope="row">
							<label class="label_child"><?php echo esc_html__('Verification email', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<?php
							wc_blacklist_manager_render_premium_preview_cards(
								array(
									array(
										'icon'        => 'dashicons-edit-page',
										'title'       => __( 'Custom email template', 'wc-blacklist-manager' ),
										'description' => __( 'Edit the verification subject, heading, and message to match your store voice.', 'wc-blacklist-manager' ),
									),
									array(
										'icon'        => 'dashicons-clock',
										'title'       => __( 'Resend timing', 'wc-blacklist-manager' ),
										'description' => __( 'Tune resend delays so customers can recover quickly without encouraging code spam.', 'wc-blacklist-manager' ),
									),
									array(
										'icon'        => 'dashicons-tag',
										'title'       => __( 'Personal placeholders', 'wc-blacklist-manager' ),
										'description' => __( 'Use first name, last name, site name, and code placeholders in the message.', 'wc-blacklist-manager' ),
									),
								),
								array( 'compact' => true )
							);
							?>
						</td>
					<?php endif; ?>
				</tr>
			<?php endif; ?>
		</table>

		<h2><?php echo esc_html__( 'Email validation', 'wc-blacklist-manager' ); ?></h2>

		<table class="form-table">
			<?php if ($premium_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="email_verification_real_time_validate"><?php echo esc_html__('Email address validation', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="email_verification_real_time_validate" name="email_verification_real_time_validate" value="1" <?php checked(!empty($data['email_verification_real_time_validate'])); ?>>
						<?php if ($woocommerce_active): ?>
							<label for="email_verification_real_time_validate"><?php echo esc_html__('Enable email address validation on the registration and checkout pages', 'wc-blacklist-manager'); ?></label>
						<?php else: ?>
							<label for="email_verification_real_time_validate"><?php echo esc_html__('Enable email address validation on the registration page', 'wc-blacklist-manager'); ?></label>
						<?php endif; ?>

						<p class="description"><?php echo esc_html__('Check submitted email addresses using the configured validation service on supported flows. This validates the address; it does not prove ownership or create email OTP proof.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ($premium_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="email_verification_disposable"><?php echo esc_html__('Disposable email blocking', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="email_verification_disposable" name="email_verification_disposable" value="1" <?php checked(!empty($data['email_verification_disposable'])); ?>>
						<label for="email_verification_disposable"><?php echo esc_html__('Detect and block disposable email addresses', 'wc-blacklist-manager'); ?></label>
						<?php if ($woocommerce_active): ?>
							<p class="description"><?php echo esc_html__('Detect and block email addresses confirmed as disposable on checkout, registration, comment, and review flows where this protection applies.', 'wc-blacklist-manager'); ?></p>
						<?php else: ?>
							<p class="description"><?php echo esc_html__('Detect and block email addresses confirmed as disposable on registration and comment flows where this protection applies.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<?php if (!$premium_active): ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label><?php echo esc_html__('Email intelligence', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<?php
						wc_blacklist_manager_render_premium_preview_cards(
							array(
								array(
									'icon'        => 'dashicons-email-alt',
								'title'       => __( 'Email address validation', 'wc-blacklist-manager' ),
								'description' => __( 'Check address quality before supported checkout or registration flows continue. Validation does not prove ownership or create email OTP proof.', 'wc-blacklist-manager' ),
								),
								array(
									'icon'        => 'dashicons-dismiss',
								'title'       => __( 'Disposable email blocking', 'wc-blacklist-manager' ),
									'description' => __( 'Reduce abuse from temporary inboxes across checkout, registration, comments, and reviews.', 'wc-blacklist-manager' ),
								),
							),
							array( 'compact' => true )
						);
						wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'integrations', '', 'premium.passive.verifications.verify.email_provider' );
						?>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<?php if ($woocommerce_active): ?>
			<?php if ( $premium_active && has_action( 'wc_blacklist_manager_render_phone_verification_settings' ) ) : ?>
				<?php do_action( 'wc_blacklist_manager_render_phone_verification_settings', $data ); ?>
			<?php elseif ( $premium_active && ! defined( 'WC_BLACKLIST_MANAGER_PREMIUM_PHONE_CHANNEL_CONTRACT_VERSION' ) ) : ?>
				<?php
				$phone_code_length = max( 6, min( 10, absint( $data['phone_verification_code_length'] ?? 6 ) ) );
				$phone_resend      = max( 30, min( 3600, absint( $data['phone_verification_resend'] ?? 180 ) ) );
				$phone_limit       = max( 1, min( 10, absint( $data['phone_verification_limit'] ?? 5 ) ) );
				?>
				<h2><?php echo esc_html__( 'Phone verification (legacy Premium compatibility)', 'wc-blacklist-manager' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="sms_service"><?php echo esc_html__( 'SMS provider', 'wc-blacklist-manager' ); ?></label></th>
						<td>
							<select id="sms_service" name="sms_service">
								<option value=""><?php echo esc_html__( 'Not configured', 'wc-blacklist-manager' ); ?></option>
								<option value="twilio" <?php selected( $data['sms_service'], 'twilio' ); ?>>Twilio</option>
								<option value="textmagic" <?php selected( $data['sms_service'], 'textmagic' ); ?>>TextMagic</option>
							</select>
							<p class="description"><?php echo esc_html__( 'Select the service used to send phone verification codes. Provider credentials are configured in Integrations. Update Premium to use the current Premium-owned phone settings controller.', 'wc-blacklist-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="phone_verification_enabled"><?php echo esc_html__( 'Phone verification at checkout', 'wc-blacklist-manager' ); ?></label></th>
						<td>
							<input type="checkbox" id="phone_verification_enabled" name="phone_verification_enabled" value="1" <?php checked( ! empty( $data['phone_verification_enabled'] ) ); ?>>
							<label for="phone_verification_enabled"><?php echo esc_html__( 'Enable phone verification at checkout through the active legacy Premium integration', 'wc-blacklist-manager' ); ?></label>
							<p class="description"><?php echo esc_html__( 'When required by the policy below, customers must prove control of the submitted phone number with a one-time SMS code.', 'wc-blacklist-manager' ); ?></p>
						</td>
					</tr>
					<tr id="phone_verification_action_row" style="<?php echo ! empty( $data['phone_verification_enabled'] ) ? '' : 'display: none;'; ?>">
						<th scope="row"><label for="phone_verification_action"><?php echo esc_html__( 'When verification is required', 'wc-blacklist-manager' ); ?></label></th>
						<td>
							<select id="phone_verification_action" name="phone_verification_action">
								<option value="all" <?php selected( $data['phone_verification_action'], 'all' ); ?>><?php echo esc_html__( 'All applicable customers', 'wc-blacklist-manager' ); ?></option>
								<option value="suspect" <?php selected( $data['phone_verification_action'], 'suspect' ); ?>><?php echo esc_html__( 'Suspected customers only', 'wc-blacklist-manager' ); ?></option>
							</select>
							<p class="description"><?php echo wp_kses_post( __( '<b>All applicable customers:</b> Require verification unless this exact phone identity is already allowed to skip repeat verification.<br><b>Suspected customers only:</b> Require verification only while this exact phone identity is currently flagged as suspect and has not already been resolved.', 'wc-blacklist-manager' ) ); ?></p>
						</td>
					</tr>
					<tr id="phone_verification_sms_settings_row" style="<?php echo ! empty( $data['phone_verification_enabled'] ) ? '' : 'display: none;'; ?>">
						<th scope="row"><label for="message"><?php echo esc_html__( 'Verification SMS', 'wc-blacklist-manager' ); ?></label></th>
						<td>
							<p><label><?php echo esc_html__( 'Code length', 'wc-blacklist-manager' ); ?> <input type="number" id="code_length" name="code_length" value="<?php echo esc_attr( $phone_code_length ); ?>" min="6" max="10"></label></p>
							<p><label><?php echo esc_html__( 'Resend delay', 'wc-blacklist-manager' ); ?> <input type="number" id="resend" name="resend" value="<?php echo esc_attr( $phone_resend ); ?>" min="30" max="3600"> <?php echo esc_html__( 'seconds', 'wc-blacklist-manager' ); ?></label></p>
							<p><label><?php echo esc_html__( 'Resend limit', 'wc-blacklist-manager' ); ?> <input type="number" id="limit" name="limit" value="<?php echo esc_attr( $phone_limit ); ?>" min="1" max="10"></label></p>
							<p><label for="message"><?php echo esc_html__( 'SMS message', 'wc-blacklist-manager' ); ?></label></p>
							<textarea id="message" name="message" rows="2" class="regular-text"><?php echo esc_textarea( ! empty( $data['phone_verification_message'] ) ? $data['phone_verification_message'] : $this->default_sms_message ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'The message must contain {code}.', 'wc-blacklist-manager' ); ?></p>
						</td>
					</tr>
				</table>
			<?php else : ?>
				<h2><?php echo esc_html__( 'Phone verification', 'wc-blacklist-manager' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><span class="dashicons dashicons-phone"></span> <?php echo esc_html__( 'Premium phone OTP', 'wc-blacklist-manager' ); ?></th>
						<td>
							<p><?php echo esc_html__( 'Phone OTP verification and its SMS providers are available through Blacklist Manager Premium. Core continues to provide email OTP verification.', 'wc-blacklist-manager' ); ?></p>
						</td>
					</tr>
				</table>
			<?php endif; ?>

		<?php if ($premium_active && !$skip_country_code): ?>
			<h2><?php echo esc_html__( 'Phone number handling', 'wc-blacklist-manager' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
						<label for="phone_verification_country_code_disabled"><?php echo esc_html__('Country code selector', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="phone_verification_country_code_disabled" name="phone_verification_country_code_disabled" value="1" <?php checked(!empty($data['phone_verification_country_code_disabled'])); ?>>
						<label for="phone_verification_country_code_disabled"><?php echo esc_html__('Disable the country code selector at checkout', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('When disabled, phone identities are stored and compared without a country dial-code prefix where the existing phone-number handling rules apply.', 'wc-blacklist-manager'); ?></p>
					</td>
				</tr>
		</table>
		<?php endif; ?>

			<h2><?php echo esc_html__( 'Phone validation', 'wc-blacklist-manager' ); ?></h2>

			<table class="form-table">
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
						<label for="phone_verification_real_time_validate"><?php echo esc_html__('Phone number format validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="phone_verification_real_time_validate" name="phone_verification_real_time_validate" value="1" <?php checked(!empty($data['phone_verification_real_time_validate'])); ?>>
						<label for="phone_verification_real_time_validate"><?php echo esc_html__('Enable phone number format validation at checkout', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Validate supported billing and shipping phone-number fields using the configured format rules. This validates format; it does not prove control of the phone number.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr id="phone_verification_format_validate_row" style="<?php echo (!empty($data['phone_verification_real_time_validate'])) ? '' : 'display: none;'; ?>">
						<th scope="row">
						<label for="phone_verification_format_validate" class="label_child"><?php echo esc_html__('Phone format rules', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<button id="yobm-phone-number-format" type="button" class="button button-secondary">
								<?php echo esc_html__('Set number format', 'wc-blacklist-manager'); ?>
							</button>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
						<label for="phone_verification_disposable"><?php echo esc_html__('Disposable phone blocking', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="phone_verification_disposable" name="phone_verification_disposable" value="1" <?php checked(!empty($data['phone_verification_disposable'])); ?>>
						<label for="phone_verification_disposable"><?php echo esc_html__('Detect and block disposable phone numbers', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Detect and block phone numbers confirmed as disposable on supported protected flows.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-phone"></span>
							<label><?php echo esc_html__('Phone intelligence', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<?php
							wc_blacklist_manager_render_premium_preview_cards(
								array(
									array(
										'icon'        => 'dashicons-smartphone',
								'title'       => __( 'Phone number format validation', 'wc-blacklist-manager' ),
								'description' => __( 'Validate supported checkout phone-number formats. Format validation does not prove control of a phone number.', 'wc-blacklist-manager' ),
									),
									array(
										'icon'        => 'dashicons-dismiss',
								'title'       => __( 'Disposable phone blocking', 'wc-blacklist-manager' ),
								'description' => __( 'Detect and block disposable phone numbers on supported protected flows.', 'wc-blacklist-manager' ),
									),
								),
								array( 'compact' => true )
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
		</table>

		<h2><?php echo esc_html__('Name validation', 'wc-blacklist-manager'); ?></h2>

			<table class="form-table">
				<?php if ($premium_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
						<label for="name_verification_auto_capitalization"><?php echo esc_html__('Name capitalization', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="name_verification_auto_capitalization" name="name_verification_auto_capitalization" value="1" <?php checked(!empty($data['name_verification_auto_capitalization'])); ?>>
						<label for="name_verification_auto_capitalization"><?php echo esc_html__('Capitalize customer first and last names on supported forms', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Capitalize the first character of each space-separated name word on supported checkout and account fields without lowercasing or otherwise changing the remaining characters.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-cart"></span>
						<label for="name_verification_real_time_validate"><?php echo esc_html__('Name format validation', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="name_verification_real_time_validate" name="name_verification_real_time_validate" value="1" <?php checked(!empty($data['name_verification_real_time_validate'])); ?>>
						<label for="name_verification_real_time_validate"><?php echo esc_html__('Enable customer name format validation at checkout', 'wc-blacklist-manager'); ?></label>
						<p class="description"><?php echo esc_html__('Validate supported billing and shipping first/last-name fields using the configured name rules. This validates format; it does not verify customer identity.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
					<tr id="name_verification_format_validate_row" style="<?php echo (!empty($data['name_verification_real_time_validate'])) ? '' : 'display: none;'; ?>">
						<th scope="row">
						<label for="name_verification_format_validate" class="label_child"><?php echo esc_html__('Name rules', 'wc-blacklist-manager'); ?></label>
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
							<span class="dashicons dashicons-id"></span>
							<label><?php echo esc_html__('Premium name cleanup', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<?php
							wc_blacklist_manager_render_premium_preview_cards(
								array(
									array(
										'icon'        => 'dashicons-editor-textcolor',
								'title'       => __( 'Name capitalization', 'wc-blacklist-manager' ),
								'description' => __( 'Capitalize the first character of each space-separated name word while preserving the remaining characters.', 'wc-blacklist-manager' ),
									),
									array(
										'icon'        => 'dashicons-search',
										'title'       => __( 'Name format validation', 'wc-blacklist-manager' ),
										'description' => __( 'Reduce meaningless or spammy names before they enter order and customer data.', 'wc-blacklist-manager' ),
									),
								),
								array( 'compact' => true )
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</table>

		<h2><?php echo esc_html__('Related account tools', 'wc-blacklist-manager'); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-cart"></span>
					<label><?php echo esc_html__('Advanced Accounts integration', 'wc-blacklist-manager'); ?></label>
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
							$message = sprintf(
								esc_html__( 'WooCommerce Advanced Accounts is already active. %s', 'wc-blacklist-manager' ),
								sprintf(
									'<a href="%s">%s</a>',
									esc_url( $settings_url ),
									esc_html__( 'Go to settings page.', 'wc-blacklist-manager' )
								)
							);

							echo '<p>' . wp_kses_post( $message ) . '</p>';
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
				var phoneVerificationRealtimeValidateCheckbox = document.getElementById('phone_verification_real_time_validate');
				var nameVerificationRealtimeValidateCheckbox = document.getElementById('name_verification_real_time_validate');

			// Rows
				var emailVerificationActionRow = document.getElementById('email_verification_action_row');
				var emailVerificationEmailSettingsRow = document.getElementById('phone_verification_email_settings_row');
				var phoneVerificationActionRow = document.getElementById('phone_verification_action_row');
				var phoneVerificationSmsSettingsRow = document.getElementById('phone_verification_sms_settings_row');
				var phoneVerificationFormatValidateRow = document.getElementById('phone_verification_format_validate_row');
				var nameVerificationFormatValidateRow = document.getElementById('name_verification_format_validate_row');

				function toggleDisplay(element, display) {
					if (!element) {
						return;
					}

					element.style.display = display ? '' : 'none';
					syncNativeValidation(element, display);
				}

				function syncNativeValidation(element, enabled) {
					var attrs = ['required', 'min', 'max', 'pattern', 'step'];
					element.querySelectorAll('input, select, textarea, button').forEach(function (control) {
						attrs.forEach(function (attr) {
							var storedAttr = 'data-yobm-' + attr;

							if (!enabled) {
								if (control.hasAttribute(attr) && !control.hasAttribute(storedAttr)) {
									control.setAttribute(storedAttr, control.getAttribute(attr));
								}
								control.removeAttribute(attr);
							} else if (control.hasAttribute(storedAttr)) {
								control.setAttribute(attr, control.getAttribute(storedAttr));
								control.removeAttribute(storedAttr);
							}
						});

						var type = control.getAttribute('type');
						if (!enabled && (type === 'email' || type === 'url')) {
							if (!control.hasAttribute('data-yobm-type')) {
								control.setAttribute('data-yobm-type', type);
							}
							control.setAttribute('type', 'text');
						} else if (enabled && control.hasAttribute('data-yobm-type')) {
							control.setAttribute('type', control.getAttribute('data-yobm-type'));
							control.removeAttribute('data-yobm-type');
						}
					});
				}

				toggleDisplay(emailVerificationActionRow, !!(emailVerificationCheckbox && emailVerificationCheckbox.checked));
				toggleDisplay(emailVerificationEmailSettingsRow, !!(emailVerificationCheckbox && emailVerificationCheckbox.checked));
				toggleDisplay(phoneVerificationActionRow, !!(phoneVerificationCheckbox && phoneVerificationCheckbox.checked));
				toggleDisplay(phoneVerificationSmsSettingsRow, !!(phoneVerificationCheckbox && phoneVerificationCheckbox.checked));
				toggleDisplay(phoneVerificationFormatValidateRow, !!(phoneVerificationRealtimeValidateCheckbox && phoneVerificationRealtimeValidateCheckbox.checked));
				toggleDisplay(nameVerificationFormatValidateRow, !!(nameVerificationRealtimeValidateCheckbox && nameVerificationRealtimeValidateCheckbox.checked));

				// Email verification checkbox changes
			if (emailVerificationCheckbox) {
				emailVerificationCheckbox.addEventListener('change', function () {
					toggleDisplay(emailVerificationActionRow, this.checked);
					toggleDisplay(emailVerificationEmailSettingsRow, this.checked);
				});
			}

				// Phone verification checkbox changes
			if (phoneVerificationCheckbox) {
				phoneVerificationCheckbox.addEventListener('change', function () {
					toggleDisplay(phoneVerificationActionRow, this.checked);
					toggleDisplay(phoneVerificationSmsSettingsRow, this.checked);
				});
			}


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

			});
		</script>

		<?php if ($premium_active): ?>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function () {
				function handleRealtimeValidateCheckbox() {
					var realtimeValidateCheckbox = document.getElementById('email_verification_real_time_validate');
					var disposableEmailCheckbox = document.getElementById('email_verification_disposable');
					var disposablePhoneCheckbox = document.getElementById('phone_verification_disposable');

					realtimeValidateCheckbox.addEventListener('click', function (event) {
						var zeroBounceApiKey = <?php echo json_encode(!empty(get_option('wc_blacklist_manager_premium_zerobounce_api_key'))); ?>;

						if (!zeroBounceApiKey) {
							event.preventDefault();
							alert("<?php echo esc_html__('Please set the ZeroBounce API key to allow this option to work.', 'wc-blacklist-manager'); ?>");
							window.location.href = 'admin.php?page=wc-blacklist-manager-settings&tab=integrations';
						}
					});

					disposableEmailCheckbox.addEventListener('click', function (event) {
						var bigDataCloudApiKey = <?php echo json_encode(!empty(get_option('wc_blacklist_manager_premium_bigdatacloud_api_key'))); ?>;

						if (!bigDataCloudApiKey) {
							event.preventDefault();
							alert("<?php echo esc_html__('Please set the BigDataCloud API key to allow this option to work.', 'wc-blacklist-manager'); ?>");
							window.location.href = 'admin.php?page=wc-blacklist-manager-settings&tab=integrations';
						}
					});

					disposablePhoneCheckbox.addEventListener('click', function (event) {
						var numCheckRApiKey = <?php echo json_encode(!empty(get_option('wc_blacklist_manager_premium_numcheckr_api_key'))); ?>;

						if (!numCheckRApiKey) {
							event.preventDefault();
							alert("<?php echo esc_html__('Please set the NumCheckr API token to allow this option to work.', 'wc-blacklist-manager'); ?>");
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
</div>
