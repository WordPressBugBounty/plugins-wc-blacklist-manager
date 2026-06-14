<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';
?>

<div class="wrap">
	<?php if (!$premium_active): ?>
		<p>Please support us by <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
	<?php endif; ?>
	
	<h1>
		<?php echo esc_html__('Blacklist manager settings', 'wc-blacklist-manager'); ?> 
		<a href="https://docs.yoohw.com/category/blacklist-manager/" target="_blank" class="button button-secondary yoohw-docs-btn" style="display: inline-flex;"><span class="dashicons dashicons-editor-help"></span> <?php echo esc_html__('Docs', 'wc-blacklist-manager'); ?></a>
		<?php if (!$premium_active): ?>
			<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php echo esc_html__('Support', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
	</h1>

	<nav class="nav-tab-wrapper">
		<a href="#tab-content-general" class="nav-tab nav-tab-active" id="tab-general"><?php echo esc_html__('General', 'wc-blacklist-manager'); ?></a>
		<?php if ($woocommerce_active): ?>
			<a href="#tab-content-anti_bots" class="nav-tab" id="tab-anti_bots"><?php echo esc_html__('Anti-bots', 'wc-blacklist-manager'); ?></a>
			<a href="#tab-content-automation" class="nav-tab" id="tab-automation"><?php echo esc_html__('Automation', 'wc-blacklist-manager'); ?></a>
			<a href="#tab-content-scoring" class="nav-tab" id="tab-scoring"><?php echo esc_html__('Scoring', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if ($woocommerce_active): ?>
			<a href="#tab-content-payments" class="nav-tab" id="tab-payments"><?php echo esc_html__('Payments', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<a href="#tab-content-permission" class="nav-tab" id="tab-permission"><?php echo esc_html__('Permission', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-integrations" class="nav-tab" id="tab-integrations"><?php echo esc_html__('Integrations', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-tools" class="nav-tab" id="tab-tools"><?php echo esc_html__('Tools', 'wc-blacklist-manager'); ?></a>
		<a href="#tab-content-connection" class="nav-tab" id="tab-connection"><?php echo esc_html__('Connection', 'wc-blacklist-manager'); ?></a>
	</nav>

	<div id="tab-content-general" class="tab-content">
		<form method="post" action="">
			<?php wp_nonce_field('wc_blacklist_settings_action', 'wc_blacklist_settings_nonce'); ?>

			<h2><?php echo esc_html__('General options', 'wc-blacklist-manager'); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="development_mode"><?php echo esc_html__('Development mode', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="development_mode" name="development_mode" value="1" <?php checked($settings['development_mode']); ?>>
						<label for="development_mode"><?php echo esc_html__('Enable development mode to test the blacklist on your site', 'wc-blacklist-manager'); ?></label>
					</td>
				</tr>
				<?php if ($woocommerce_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-rest-api"></span>
							<label for="woo_rest_api"><?php echo esc_html__( 'WooCommerce REST API', 'wc-blacklist-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="woo_rest_api" name="woo_rest_api" value="1" <?php checked( $settings['woo_rest_api'] ); ?>>
							<label for="woo_rest_api"><?php echo esc_html__( 'Enable blacklist protection for WooCommerce REST API requests', 'wc-blacklist-manager' ); ?></label>
							<p class="description"><?php echo esc_html__( 'If enabled, the plugin will check and block blacklisted customers when orders are created via the WooCommerce REST API, instead of only through the normal checkout flow.', 'wc-blacklist-manager' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-shield-alt"></span>
						<label><?php echo esc_html__( 'Premium identity signals', 'wc-blacklist-manager' ); ?></label>
					</th>
					<td>
						<?php
						wc_blacklist_manager_render_premium_preview_cards(
							array(
								array(
									'icon'        => 'dashicons-businessperson',
									'title'       => __( 'Customer name blocking', 'wc-blacklist-manager' ),
									'description' => __( 'Add first and last names to suspects or blocklist when email and phone alone are not enough.', 'wc-blacklist-manager' ),
								),
								array(
									'icon'        => 'dashicons-admin-site-alt3',
									'title'       => __( 'Device identity', 'wc-blacklist-manager' ),
									'description' => __( 'Recognize repeat abuse from the same device even when contact details change.', 'wc-blacklist-manager' ),
								),
							),
							array( 'columns' => 2 )
						);
						wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'premium', __( 'Unlock Premium Protection', 'wc-blacklist-manager' ) );
						?>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__('Email address & Phone number', 'wc-blacklist-manager'); ?></h2>
			<table class="form-table">
				<?php if ($woocommerce_active): ?>
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
				<?php endif; ?>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="block_user_registration"><?php echo esc_html__('Registration action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="block_user_registration" name="block_user_registration" value="1" <?php checked($settings['block_user_registration']); ?>>
						<label for="block_user_registration"><?php echo esc_html__('Prevent visitors from registering if their email is on the blocklist', 'wc-blacklist-manager'); ?></label>
						<?php if ($woocommerce_active): ?>
							<p class="description"><?php echo esc_html__('It will prevent blocked users from registering through both the WordPress and WooCommerce registration forms.', 'wc-blacklist-manager'); ?></p>
						<?php else: ?>
							<p class="description"><?php echo esc_html__('It will prevent blocked users from registering through the WordPress registration form.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label for="comment_blocking_enabled"><?php echo esc_html__('Comment action', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<input type="checkbox" id="comment_blocking_enabled" name="comment_blocking_enabled" value="1" <?php checked($settings['comment_blocking_enabled']); ?>>
						<?php if ($woocommerce_active): ?>
							<label for="comment_blocking_enabled"><?php echo esc_html__('Prevent users from submitting comment and review if their email is on the blocklist', 'wc-blacklist-manager'); ?></label>
							<p class="description"><?php echo esc_html__('It will prevent blocked users from submiting comment or product review on both WordPress and WooCommerce.', 'wc-blacklist-manager'); ?></p>
						<?php else: ?>
							<label for="comment_blocking_enabled"><?php echo esc_html__('Prevent users from submitting comment if their email is on the blocklist', 'wc-blacklist-manager'); ?></label>
							<p class="description"><?php echo esc_html__('It will prevent blocked users from submiting comment on your site.', 'wc-blacklist-manager'); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ($form_active): ?>
					<tr>
						<th scope="row">
							<span class="dashicons dashicons-forms"></span>
							<label for="form_blocking_enabled"><?php echo esc_html__('Form action', 'wc-blacklist-manager'); ?></label>
						</th>
						<td>
							<input type="checkbox" id="form_blocking_enabled" name="form_blocking_enabled" value="1" <?php checked($settings['form_blocking_enabled']); ?>>
							<label for="form_blocking_enabled"><?php echo esc_html__('Prevent visitors from submitting if their email or phone is on the blocklist for Contact Form 7, Gravity Forms, and WPForms', 'wc-blacklist-manager'); ?></label>
							<p class="description"><?php echo esc_html__('Notify the admin if a suspected phone or email is submitting, and prevent submitting if they were blocked.', 'wc-blacklist-manager'); ?></p>
						</td>
					</tr>
				<?php endif; ?>
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
				<?php if ($woocommerce_active): ?>
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
						</td>
					</tr>
				<?php endif; ?>
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
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-location-alt"></span>
						<label><?php echo esc_html__( 'Premium IP intelligence', 'wc-blacklist-manager' ); ?></label>
					</th>
					<td>
						<?php
						wc_blacklist_manager_render_premium_preview_cards(
							array(
								array(
									'icon'        => 'dashicons-shield',
									'title'       => __( 'Proxy, VPN, and TOR checks', 'wc-blacklist-manager' ),
									'description' => __( 'Block or score risky network traffic across checkout, registration, comments, and forms.', 'wc-blacklist-manager' ),
								),
								array(
									'icon'        => 'dashicons-admin-site',
									'title'       => __( 'Country access rules', 'wc-blacklist-manager' ),
									'description' => __( 'Use country allowlists or blocklists when your store must restrict regional access.', 'wc-blacklist-manager' ),
								),
								array(
									'icon'        => 'dashicons-search',
									'title'       => __( 'IP details on orders', 'wc-blacklist-manager' ),
									'description' => __( 'Review IP location and risk details from the order screen when investigating fraud.', 'wc-blacklist-manager' ),
								),
							),
							array( 'columns' => 3 )
						);
						wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'scoring' );
						?>
					</td>
				</tr>
			</table>

			<?php if ($woocommerce_active): ?>
				<h2><?php echo esc_html__( 'Address', 'wc-blacklist-manager' ); ?></h2>

				<?php
				wc_blacklist_manager_render_premium_preview_cards(
					array(
						array(
							'icon'        => 'dashicons-location',
							'title'       => __( 'Billing and shipping address blocking', 'wc-blacklist-manager' ),
							'description' => __( 'Stop repeat abuse that changes contact details but reuses the same physical address.', 'wc-blacklist-manager' ),
						),
						array(
							'icon'        => 'dashicons-admin-settings',
							'title'       => __( 'Advanced matching modes', 'wc-blacklist-manager' ),
							'description' => __( 'Detect address variations such as abbreviations, apartment formatting, and regional parts.', 'wc-blacklist-manager' ),
						),
						array(
							'icon'        => 'dashicons-cart',
							'title'       => __( 'Checkout order action', 'wc-blacklist-manager' ),
							'description' => __( 'Prevent risky orders when billing, shipping, state, or postcode matches your blocklist rules.', 'wc-blacklist-manager' ),
						),
					),
					array( 'columns' => 3 )
				);
				wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'premium', __( 'Unlock Address Protection', 'wc-blacklist-manager' ) );
				?>
			<?php endif; ?>

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
				<tr>
					<th scope="row">
						<span class="dashicons dashicons-admin-site"></span>
						<label><?php echo esc_html__( 'Premium domain controls', 'wc-blacklist-manager' ); ?></label>
					</th>
					<td>
						<?php
						wc_blacklist_manager_render_premium_preview_cards(
							array(
								array(
									'icon'        => 'dashicons-editor-ul',
									'title'       => __( 'Top-level domain rules', 'wc-blacklist-manager' ),
									'description' => __( 'Block risky TLDs such as disposable or low-trust domain endings in one rule.', 'wc-blacklist-manager' ),
								),
								array(
									'icon'        => 'dashicons-admin-comments',
									'title'       => __( 'Comment and review checks', 'wc-blacklist-manager' ),
									'description' => __( 'Apply domain rules beyond checkout and registration to comments and product reviews.', 'wc-blacklist-manager' ),
								),
								array(
									'icon'        => 'dashicons-forms',
									'title'       => __( 'Form submission checks', 'wc-blacklist-manager' ),
									'description' => __( 'Use the same domain rules on supported form plugins for cleaner lead quality.', 'wc-blacklist-manager' ),
								),
							),
							array( 'columns' => 3 )
						);
						wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'premium', __( 'Unlock Domain Controls', 'wc-blacklist-manager' ) );
						?>
					</td>
				</tr>
				<?php if ($woocommerce_active): ?>
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
				<?php endif; ?>
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
			</table>

			<?php if ($woocommerce_active): ?>
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
							<p class="description"><?php echo esc_html__('Premium adds manual block/unblock workflows and more user-level controls.', 'wc-blacklist-manager'); ?> <a href="https://docs.yoohw.com/block-and-unblock-wordpress-users/" target="_blank"><?php echo esc_html__('Learn more', 'wc-blacklist-manager'); ?></a></p>
						</td>
					</tr>
				</table>
			<?php else: ?>
				<h2><?php echo esc_html__('User', 'wc-blacklist-manager'); ?></h2>
				<?php
				wc_blacklist_manager_render_premium_preview_cards(
					array(
						array(
							'icon'        => 'dashicons-admin-users',
							'title'       => __( 'User blocking workflows', 'wc-blacklist-manager' ),
							'description' => __( 'Block, unblock, and review WordPress users through dedicated Premium controls.', 'wc-blacklist-manager' ),
						),
					),
					array( 'columns' => 1 )
				);
				wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'premium', __( 'Unlock User Controls', 'wc-blacklist-manager' ) );
				?>
			<?php endif; ?>

			<h2><?php echo esc_html__('Additional', 'wc-blacklist-manager'); ?></h2>
			<?php
			wc_blacklist_manager_render_premium_preview_cards(
				array(
					array(
						'icon'        => 'dashicons-desktop',
						'title'       => __( 'Browser access rules', 'wc-blacklist-manager' ),
						'description' => __( 'Prevent access from browser families that do not match your security or compatibility policy.', 'wc-blacklist-manager' ),
					),
				),
				array( 'columns' => 1 )
			);
			wc_blacklist_manager_render_premium_inline_cta( $unlock_url, 'premium', __( 'Unlock Browser Rules', 'wc-blacklist-manager' ) );
			?>

			<?php
			$reporter_id = get_option( 'yogb_bm_reporter_id', '' );
			$api_key     = get_option( 'yogb_bm_api_key', '' );
			$api_secret  = get_option( 'yogb_bm_api_secret', '' );

			// Default to "free" if not set.
			$tier        = get_option( 'yogb_bm_tier', 'free' );

			// Normalise tier to one of the 4 known levels.
			$allowed_tiers = array( 'free', 'basic', 'pro', 'enterprise' );
			if ( ! in_array( $tier, $allowed_tiers, true ) ) {
				$tier = 'free';
			}

			$has_global_creds = ( $reporter_id && $api_key && $api_secret );

			// Translate / prettify label.
			switch ( $tier ) {
				case 'basic':
					$tier_label = esc_html( 'Basic', 'wc-blacklist-manager' );
					break;
				case 'pro':
					$tier_label = esc_html( 'Pro', 'wc-blacklist-manager' );
					break;
				case 'enterprise':
					$tier_label = esc_html( 'Enterprise', 'wc-blacklist-manager' );
					break;
				case 'free':
				default:
					$tier_label = esc_html( 'Free', 'wc-blacklist-manager' );
					break;
			}

			// Mirror the limits from enforce_rate_limit(), adding "enterprise" as unlimited.
			switch ( $tier ) {
				case 'basic':
					$tier_limit = 150;
					break;
				case 'pro':
					$tier_limit = 1000;
					break;
				case 'enterprise':
					$tier_limit = 0; // unlimited
					break;
				case 'free':
				default:
					$tier_limit = 20;
					break;
			}

			// Read current month usage WITHOUT incrementing.
			// This mirrors: 'yogb_bm_chk_month_' . $tier . '_' . gmdate('Ym')
			$month_key  = gmdate( 'Ym' ); // e.g. 202511
			$usage_opt  = 'yogb_bm_chk_month_' . $tier . '_' . $month_key;
			$tier_used  = (int) get_option( $usage_opt, 0 );

			// Build the display text: "3/20 used"
			if ( $tier_limit > 0 ) {
				$tier_limit_text = sprintf(
					/* translators: 1: used checks, 2: monthly limit */
					__( 'This tier allows up to %2$d global checks per calendar month. (%1$d/%2$d used this month)', 'wc-blacklist-manager' ),
					$tier_used,
					$tier_limit
				);
			} else {
				// Unlimited: show used count only.
				$tier_limit_text = sprintf(
					/* translators: 1: used checks */
					__( 'This tier includes unlimited global checks per calendar month. (%1$d used this month)', 'wc-blacklist-manager' ),
					$tier_used
				);
			}

			// Upgrade button: all tiers except Enterprise.
			$can_upgrade   = ( 'enterprise' !== $tier );
			$upgrade_url   = 'https://yoohw.com/global-blacklist-plan/';

			$retry_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=yogb_bm_retry_registration' ),
				'yogb_bm_retry_registration',
				'yogb_bm_retry_nonce'
			);
			?>

			<h2 id="global_blacklist"><?php echo esc_html__( 'Global Blacklist', 'wc-blacklist-manager' ); ?></h2>

			<p class="description"><?php echo esc_html__( 'The Global Blacklist enhances your site’s local blacklist by identifying real customers already flagged as high-risk (not bots). It helps detect suspicious information during checkout, allowing you to block bad actors, reduce fraud, and minimize chargebacks more effectively.', 'wc-blacklist-manager' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="enable_global_blacklist"><?php echo esc_html__( 'Order checking', 'wc-blacklist-manager' ); ?></label>
					</th>
					<td>
						<?php
						$is_paid_tier = in_array( $tier, array( 'basic', 'pro', 'enterprise' ), true );
						?>

						<input
							type="checkbox"
							id="enable_global_blacklist"
							name="enable_global_blacklist"
							value="1"
							<?php checked( $is_paid_tier ? true : $settings['enable_global_blacklist'] ); ?>
							<?php disabled( $is_paid_tier ); ?>
						>

						<?php if ( $is_paid_tier ) : ?>
							<input type="hidden" name="enable_global_blacklist" value="1">
						<?php endif; ?>

						<label for="enable_global_blacklist">
							<?php echo esc_html__( 'Enable order checking for contact details on the global blacklist', 'wc-blacklist-manager' ); ?>
						</label>

						<?php if ( $is_paid_tier ) : ?>
							<p class="description">
								<?php esc_html_e( 'Order checking is automatically enabled for paid Global Blacklist tiers.', 'wc-blacklist-manager' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="global_blacklist_decision_mode"><?php echo esc_html__('Decision mode', 'wc-blacklist-manager'); ?></label>
					</th>
					<td>
						<select id="global_blacklist_decision_mode" name="global_blacklist_decision_mode">
							<option value="light" <?php selected($settings['global_blacklist_decision_mode'], 'light'); ?>><?php echo esc_html__('Light', 'wc-blacklist-manager'); ?></option>
							<option value="moderate" <?php selected($settings['global_blacklist_decision_mode'], 'moderate'); ?>><?php echo esc_html__('Moderate', 'wc-blacklist-manager'); ?></option>
							<option value="strict" <?php selected($settings['global_blacklist_decision_mode'], 'strict'); ?>><?php echo esc_html__('Strict', 'wc-blacklist-manager'); ?></option>
						</select>

						<p class="description">
							<?php echo esc_html__( 'Select a fit mode for your site.', 'wc-blacklist-manager' ); ?> <a href="https://docs.yoohw.com/global-blacklist-decisions-and-review-workflow/" target="_blank"><?php echo esc_html__('Learn more', 'wc-blacklist-manager'); ?></a>
						</p>
					</td>
				</tr>

				<?php if ( $has_global_creds ) : ?>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Site Global ID', 'wc-blacklist-manager' ); ?>
						</th>
						<td>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $reporter_id ); ?>" readonly>
							<p class="description">
								<?php esc_html_e( 'Use this ID when purchasing the non-free plan.', 'wc-blacklist-manager' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Site key', 'wc-blacklist-manager' ); ?>
						</th>
						<td>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" readonly>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Secret key', 'wc-blacklist-manager' ); ?>
						</th>
						<td>
							<div class="yogb-secret-wrapper">
								<input
									type="password"
									id="yogb_api_secret"
									class="regular-text"
									value="<?php echo esc_attr( $api_secret ); ?>"
									readonly
								/>

								<button
									type="button"
									class="yogb-secret-toggle"
									data-target="yogb_api_secret"
								>
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<span class="screen-reader-text">
										<?php esc_html_e( 'Show secret key', 'wc-blacklist-manager' ); ?>
									</span>
								</button>
							</div>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Tier', 'wc-blacklist-manager' ); ?>
						</th>
						<td>
							<div class="yogb-tier-row">
								<span class="yogb-tier-badge yogb-tier-<?php echo esc_attr( $tier ); ?>">
									<span class="yogb-tier-dot"></span>
									<span class="yogb-tier-text"><?php echo esc_html( $tier_label ); ?></span>
								</span>

								<?php if ( $can_upgrade ) : ?>
									<a
										href="<?php echo esc_url( $upgrade_url ); ?>"
										class="yogb-tier-cta"
										target="_blank"
										rel="noopener noreferrer"
									>
										<?php esc_html_e( 'Upgrade', 'wc-blacklist-manager' ); ?>
									</a>
								<?php endif; ?>
							</div>

							<?php if ( ! empty( $tier_limit_text ) ) : ?>
								<p class="yogb-tier-limit">
									<?php echo esc_html( $tier_limit_text ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php else : ?>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Connection status', 'wc-blacklist-manager' ); ?>
						</th>
						<td>
							<div class="notice inline notice-warning yogb-global-connection-warning">
								<p>
									<strong><?php esc_html_e( 'Not connected to Global Blacklist', 'wc-blacklist-manager' ); ?></strong>
								</p>
								<p>
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %1$s: opening <strong>, %2$s: closing </strong>, %3$s: contact URL */
											__(
												'This site has not completed registration with the Global Blacklist service. %1$sOrder checking%2$s will not work until a Site Global ID and API keys are issued. If you need help, please <a href="%3$s" target="_blank" rel="noopener noreferrer">contact us here</a>.',
												'wc-blacklist-manager'
											),
											'<strong>',
											'</strong>',
											esc_url( 'https://yoohw.com/contact-us' )
										),
										array(
											'strong' => array(),
											'a'      => array(
												'href'   => array(),
												'target' => array(),
												'rel'    => array(),
											),
										)
									);
									?>
								</p>

								<p>
									<a href="<?php echo esc_url( $retry_url ); ?>" class="button button-secondary">
										<?php esc_html_e( 'Retry registration now', 'wc-blacklist-manager' ); ?>
									</a>
								</p>
							</div>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function () {
					<?php if ($woocommerce_active): ?>
						var blacklistActionSelect = document.getElementById('blacklist_action');
						var timeDelayRow = document.getElementById('time_delay_row');

						blacklistActionSelect.addEventListener('change', function () {
							toggleDisplay(timeDelayRow, this.value === 'cancel');
						});
					<?php endif; ?>

					var ipBlacklistEnabledCheckbox = document.getElementById('ip_blacklist_enabled');
					<?php if ($woocommerce_active): ?>
						var ipBlacklistActionRow = document.getElementById('ip_blacklist_action_row');
					<?php endif; ?>
					var blockIpRegistrationRow = document.getElementById('block_ip_registration_row');
					var blockIpCommentRow = document.getElementById('block_ip_comment_row');
					var blockIpFormRow = document.getElementById('block_ip_form_row');

					ipBlacklistEnabledCheckbox.addEventListener('change', function () {
						<?php if ($woocommerce_active): ?>
							toggleDisplay(ipBlacklistActionRow, this.value === '1');
						<?php endif; ?>
						toggleDisplay(blockIpRegistrationRow, this.value === '1');
						toggleDisplay(blockIpCommentRow, this.value === '1');
						toggleDisplay(blockIpFormRow, this.value === '1');
					});

					var domainBlockingEnabledCheckbox = document.getElementById('domain_blocking_enabled');
					var domainTopLevelDomainsRow = document.getElementById('domain_top_level_domains_row');
					<?php if ($woocommerce_active): ?>
						var domainBlockingActionRow = document.getElementById('domain_blocking_action_row');
					<?php endif; ?>
					var domainRegistrationRow = document.getElementById('domain_registration_row');
					var domainCommentRow = document.getElementById('domain_comment_row');
					var domainFormRow = document.getElementById('domain_form_row');

					domainBlockingEnabledCheckbox.addEventListener('change', function () {
						var isChecked = this.checked;
						toggleDisplay(domainTopLevelDomainsRow, this.checked);
						<?php if ($woocommerce_active): ?>
							toggleDisplay(domainBlockingActionRow, this.checked);
						<?php endif; ?>
						toggleDisplay(domainRegistrationRow, this.checked);
						toggleDisplay(domainCommentRow, this.checked);
						toggleDisplay(domainFormRow, this.checked);
					});

					function toggleDisplay(element, display) {
						if (!element) {
							return;
						}
						element.style.display = display ? '' : 'none';
						syncNativeValidation(element, display);
					}

					function syncNativeValidation(element, enabled) {
						var attrs = ['required', 'min', 'max', 'pattern', 'step'];
						element.querySelectorAll('input, select, textarea').forEach(function (control) {
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

					<?php if ($woocommerce_active): ?>
						toggleDisplay(timeDelayRow, !!(blacklistActionSelect && blacklistActionSelect.value === 'cancel'));
						toggleDisplay(ipBlacklistActionRow, !!(ipBlacklistEnabledCheckbox && ipBlacklistEnabledCheckbox.value === '1'));
						toggleDisplay(domainBlockingActionRow, !!(domainBlockingEnabledCheckbox && domainBlockingEnabledCheckbox.checked));
					<?php endif; ?>
					toggleDisplay(blockIpRegistrationRow, !!(ipBlacklistEnabledCheckbox && ipBlacklistEnabledCheckbox.value === '1'));
					toggleDisplay(blockIpCommentRow, !!(ipBlacklistEnabledCheckbox && ipBlacklistEnabledCheckbox.value === '1'));
					toggleDisplay(blockIpFormRow, !!(ipBlacklistEnabledCheckbox && ipBlacklistEnabledCheckbox.value === '1'));
					toggleDisplay(domainTopLevelDomainsRow, !!(domainBlockingEnabledCheckbox && domainBlockingEnabledCheckbox.checked));
					toggleDisplay(domainRegistrationRow, !!(domainBlockingEnabledCheckbox && domainBlockingEnabledCheckbox.checked));
					toggleDisplay(domainCommentRow, !!(domainBlockingEnabledCheckbox && domainBlockingEnabledCheckbox.checked));
					toggleDisplay(domainFormRow, !!(domainBlockingEnabledCheckbox && domainBlockingEnabledCheckbox.checked));

					// Secret key field
					const toggles = document.querySelectorAll('.yogb-secret-toggle');

					toggles.forEach(function (btn) {
						btn.addEventListener('click', function () {
							const targetId = btn.getAttribute('data-target');
							const input    = document.getElementById(targetId);
							if (!input) {
								return;
							}

							const icon = btn.querySelector('.dashicons');
							const sr   = btn.querySelector('.screen-reader-text');

							if (input.type === 'password') {
								input.type = 'text';
								if (icon) {
									icon.classList.remove('dashicons-visibility');
									icon.classList.add('dashicons-hidden');
								}
								if (sr) {
									sr.textContent = '<?php echo esc_js( esc_html( 'Hide secret key', 'wc-blacklist-manager' ) ); ?>';
								}
							} else {
								input.type = 'password';
								if (icon) {
									icon.classList.remove('dashicons-hidden');
									icon.classList.add('dashicons-visibility');
								}
								if (sr) {
									sr.textContent = '<?php echo esc_js( esc_html( 'Show secret key', 'wc-blacklist-manager' ) ); ?>';
								}
							}
						});
					});
				});
			</script>

			<p class="submit">
				<input type="submit" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'wc-blacklist-manager'); ?>" />
			</p>
		</form>
	</div>

	<?php
	$premium_preview_template = plugin_dir_path( __FILE__ ) . 'premium-settings-preview.php';
	if ( file_exists( $premium_preview_template ) ) {
		include $premium_preview_template;
	}
	?>
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
