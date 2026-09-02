<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications {
	private $verfications_advanced;

	private $default_email_subject;
	private $default_email_heading;
	private $default_email_message;
	private $default_sms_message;

	public function __construct() {
		add_action('init', [$this, 'set_verifications_strings']);
		add_action('admin_menu', [$this, 'add_verifications_submenu']);
		add_action('admin_post_refresh_merging', [$this, 'wc_blacklist_refresh_merging']);

		$this->includes();
	}

	private function get_posted_value( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		$value = wp_unslash( $_POST[ $key ] );

		if ( is_array( $value ) ) {
			$parts = array();

			array_walk_recursive(
				$value,
				function ( $item ) use ( &$parts ) {
					if ( is_scalar( $item ) || ( is_object( $item ) && method_exists( $item, '__toString' ) ) ) {
						$item = trim( (string) $item );

						if ( '' !== $item ) {
							$parts[] = $item;
						}
					}
				}
			);

			return trim( implode( ' ', $parts ) );
		}

		if ( is_scalar( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) ) ) {
			return trim( (string) $value );
		}

		return $default;
	}

	public function set_verifications_strings() {
		$this->default_email_subject = __('Verify your email address on {site_name}', 'wc-blacklist-manager');
		$this->default_email_heading = __('Verify your email address', 'wc-blacklist-manager');
		$this->default_email_message = __('Hi {first_name} {last_name},<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>{code}</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.', 'wc-blacklist-manager');
		$this->default_sms_message = __('{site_name}: Your verification code is {code}', 'wc-blacklist-manager');
	}

	public function add_verifications_submenu() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ($premium_active) {
			return;
		}

		if (current_user_can('manage_options')) {
			add_submenu_page(
				'wc-blacklist-manager',
				__('Verifications', 'wc-blacklist-manager'),
				__('Verifications', 'wc-blacklist-manager'),
				'read',
				'wc-blacklist-manager-verifications',
				[$this, 'verifications_page_content']
			);
		}
	}

	public function verifications_page_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'verify';
		?>
		<div class="wrap yobm-admin-page">
			<h1>
				<?php echo esc_html__('Verifications', 'wc-blacklist-manager'); ?>
				<?php if (get_option('yoohw_settings_disable_menu') != 1): ?>
					<a href="https://docs.yoohw.com/category/blacklist-manager/" target="_blank" class="button button-secondary yoohw-docs-btn" style="display: inline-flex;"><span class="dashicons dashicons-editor-help"></span> <?php echo esc_html__('Docs', 'wc-blacklist-manager'); ?></a>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php echo esc_html__('Support', 'wc-blacklist-manager'); ?></a>
				<?php endif; ?>
				<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
					<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary"><?php echo esc_html__('Support', 'wc-blacklist-manager'); ?></a>
				<?php endif; ?>
			</h1>

			<nav class="yobm-admin-tabs" aria-label="<?php echo esc_attr__( 'Verification sections', 'wc-blacklist-manager' ); ?>">
				<a href="?page=wc-blacklist-manager-verifications&tab=verify" class="yobm-admin-tab<?php echo $active_tab == 'verify' ? ' is-active' : ''; ?>"<?php echo $active_tab == 'verify' ? ' aria-current="page"' : ''; ?>><?php echo esc_html__('Verify', 'wc-blacklist-manager'); ?></a>
				<a href="?page=wc-blacklist-manager-verifications&tab=advanced" class="yobm-admin-tab<?php echo $active_tab == 'advanced' ? ' is-active' : ''; ?>"<?php echo $active_tab == 'advanced' ? ' aria-current="page"' : ''; ?>><?php echo esc_html__('Advanced', 'wc-blacklist-manager'); ?></a>
			</nav>

			<?php
			if ( ! $premium_active && function_exists( 'wc_blacklist_manager_render_action_upsell' ) ) {
				wc_blacklist_manager_render_action_upsell( 'verifications' );
			}
			?>

			<form method="post" enctype="multipart/form-data" action="">
				<?php
				wp_nonce_field('wc_blacklist_settings_action', 'wc_blacklist_settings_nonce');

				if ($active_tab == 'verify') {
					$this->render_verifications_settings();
				} elseif ($active_tab == 'advanced') {
					$this->render_verifications_advanced();
				}
				?>
			</form>
		</div>
		<?php
	}

	public function render_verifications_settings() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$woocommerce_active = class_exists( 'WooCommerce' );
		$unlock_url = WC_Blacklist_Manager_Commercial_Router::premium_destination_url();
			
		$allowed_countries_option = get_option('woocommerce_allowed_countries', 'all');
		$specific_countries = get_option('woocommerce_specific_allowed_countries', []);
		$skip_country_code = ($allowed_countries_option === 'specific' && count($specific_countries) === 1);
		$message = $this->handle_form_submission();
		$data = $this->get_verifications_settings();
		$data['message'] = $message;
		$template_path = plugin_dir_path(__FILE__) . 'views/verifications-form.php';

		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	public function render_verifications_advanced() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$woocommerce_active = class_exists( 'WooCommerce' );
		$unlock_url = WC_Blacklist_Manager_Commercial_Router::premium_destination_url();
			
		$template_path = plugin_dir_path(__FILE__) . 'views/verifications-advanced.php';

		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	private function handle_form_submission() {
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wc_blacklist_verifications_nonce'])) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}

			// Unslash and sanitize the nonce field
			$nonce = sanitize_text_field(wp_unslash($_POST['wc_blacklist_verifications_nonce']));
			
			// Verify nonce
			if (wp_verify_nonce($nonce, 'wc_blacklist_verifications_action')) {
				// Sanitize the 'message' field if it is present
				$message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
	
				// Store the sanitized 'message' field in the settings
				$this->save_settings($message);
	
				// Display success or error message
				if (!get_settings_errors('wc_blacklist_verifications_settings')) {
					add_settings_error('wc_blacklist_verifications_settings', 'settings_saved', __('Changes saved successfully.', 'wc-blacklist-manager'), 'updated');
				}
	
				// Return an empty string as the message will be handled by settings_errors()
				return '';
			}
		}
	
		return '';
	}
	
	private function get_verifications_settings() {
		// Get the combined verification settings
		$email_verification_settings = get_option('wc_blacklist_email_verification', [
			'resend' => 180,
			'subject' => $this->default_email_subject,
			'heading' => $this->default_email_heading,
			'message' => $this->default_email_message,
		]);

		$phone_verification_settings = get_option('wc_blacklist_phone_verification', [
			'code_length' => 6,
			'resend' => 180,
			'limit' => 5,
			'message' => $this->default_sms_message,
		]);

		return [
			'checkout_verification_interface' => WC_Blacklist_Manager_Checkout_Verification_Coordinator::get_interface(),
			'email_verification_enabled' => get_option('wc_blacklist_email_verification_enabled', '0'),
			'email_verification_action' => get_option('wc_blacklist_email_verification_action', 'all'),
			'email_verification_resend' => $email_verification_settings['resend'],
			'email_verification_subject' => !empty($email_verification_settings['subject']) ? $email_verification_settings['subject'] : $this->default_email_subject,
			'email_verification_heading' => !empty($email_verification_settings['heading']) ? $email_verification_settings['heading'] : $this->default_email_heading,
			'email_verification_message' => !empty($email_verification_settings['message']) ? $email_verification_settings['message'] : $this->default_email_message,
			'email_verification_real_time_validate' => get_option('wc_blacklist_email_verification_real_time_validate', '0'),
			'email_verification_disposable' => get_option('wc_blacklist_email_verification_disposable', '0'),
			'phone_verification_disposable' => get_option('wc_blacklist_manager_premium_enable_numcheckr', '0'),
			'phone_verification_enabled' => get_option('wc_blacklist_phone_verification_enabled', '0'),
			'phone_verification_action' => get_option('wc_blacklist_phone_verification_action', 'all'),
			'sms_service' => get_option('yoohw_sms_service', ''),
			'phone_verification_code_length' => $phone_verification_settings['code_length'],
			'phone_verification_resend' => $phone_verification_settings['resend'],
			'phone_verification_limit' => $phone_verification_settings['limit'],
			'phone_verification_message' => !empty($phone_verification_settings['message']) ? $phone_verification_settings['message'] : $this->default_sms_message,
			'phone_verification_real_time_validate' => get_option('wc_blacklist_phone_verification_real_time_validate', '0'),
			'name_verification_auto_capitalization' => get_option('wc_blacklist_name_verification_auto_capitalization', '0'),
			'name_verification_real_time_validate' => get_option('wc_blacklist_name_verification_real_time_validate', '0'),
			'phone_verification_country_code_disabled' => get_option('wc_blacklist_phone_verification_country_code_disabled', '0'),
		];
	}

	private function save_settings() {
		$premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();

		$email_verification_enabled = isset($_POST['email_verification_enabled']) ? '1' : '0';
		$checkout_verification_interface = isset( $_POST['checkout_verification_interface'] )
			? WC_Blacklist_Manager_Checkout_Verification_Coordinator::sanitize_interface( wp_unslash( $_POST['checkout_verification_interface'] ) )
			: 'inline';
		$email_verification_action = isset($_POST['email_verification_action'])
			? sanitize_text_field(wp_unslash($_POST['email_verification_action']))
			: 'all';
		$email_subject = isset($_POST['email_verification_subject'])
			? sanitize_text_field( $this->get_posted_value( 'email_verification_subject' ) )
			: '';
		$email_heading = isset($_POST['email_verification_heading'])
			? sanitize_text_field( $this->get_posted_value( 'email_verification_heading' ) )
			: '';
		if ( isset( $_POST['email_verification_message'] ) ) {
			$email_message = wp_kses_post( $this->get_posted_value( 'email_verification_message' ) );
		} else {
			$email_message = '';
		}

		$email_subject = !empty($email_subject) ? wp_kses_post($email_subject) : $this->default_email_subject;
		$email_heading = !empty($email_heading) ? wp_kses_post($email_heading) : $this->default_email_heading;
		$email_message = !empty($email_message) ? wp_kses_post($email_message) : $this->default_email_message;

		if (strpos($email_message, '{code}') === false) {
			add_settings_error('wc_blacklist_verifications_settings', 'invalid_message', __('The message must contain the {code} placeholder.', 'wc-blacklist-manager'), 'error');
			return;
		}

		$email_verification_settings = [
			'resend' => isset($_POST['email_verification_resend']) ? intval(wp_unslash($_POST['email_verification_resend'])) : 180,
			'subject' => $email_subject,
			'heading' => $email_heading,
			'message' => $email_message,
		];

		$email_verification_real_time_validate    = isset( $_POST['email_verification_real_time_validate'] ) ? '1' : '0';
		$email_verification_disposable            = isset( $_POST['email_verification_disposable'] ) ? '1' : '0';
		$phone_verification_disposable            = isset( $_POST['phone_verification_disposable'] ) ? '1' : '0';
		$phone_verification_real_time_validate    = isset( $_POST['phone_verification_real_time_validate'] ) ? '1' : '0';
		$name_verification_auto_capitalization    = isset( $_POST['name_verification_auto_capitalization'] ) ? '1' : '0';
		$name_verification_real_time_validate     = isset( $_POST['name_verification_real_time_validate'] ) ? '1' : '0';
		$phone_verification_country_code_disabled = isset( $_POST['phone_verification_country_code_disabled'] ) ? '1' : '0';

		if ( ! $premium_active ) {
			$email_verification_settings = get_option(
				'wc_blacklist_email_verification',
				array(
					'resend'  => 180,
					'subject' => $this->default_email_subject,
					'heading' => $this->default_email_heading,
					'message' => $this->default_email_message,
				)
			);
			$email_verification_real_time_validate    = '0';
			$email_verification_disposable            = '0';
			$phone_verification_disposable            = '0';
			$phone_verification_real_time_validate    = '0';
			$name_verification_auto_capitalization    = '0';
			$name_verification_real_time_validate     = '0';
			$phone_verification_country_code_disabled = '0';
		}

		update_option( 'wc_blacklist_email_verification_enabled', $email_verification_enabled );
		update_option( WC_Blacklist_Manager_Checkout_Verification_Coordinator::SETTING_OPTION, $checkout_verification_interface );
		update_option( 'wc_blacklist_email_verification_action', $email_verification_action );
		update_option( 'wc_blacklist_email_verification', $email_verification_settings );
		update_option( 'wc_blacklist_email_verification_real_time_validate', $email_verification_real_time_validate );
		update_option( 'wc_blacklist_email_verification_disposable', $email_verification_disposable );
		update_option( 'wc_blacklist_manager_premium_enable_numcheckr', $phone_verification_disposable );
		update_option( 'wc_blacklist_phone_verification_real_time_validate', $phone_verification_real_time_validate );
		update_option( 'wc_blacklist_name_verification_auto_capitalization', $name_verification_auto_capitalization );
		update_option( 'wc_blacklist_name_verification_real_time_validate', $name_verification_real_time_validate );
		update_option( 'wc_blacklist_phone_verification_country_code_disabled', $phone_verification_country_code_disabled );

		if ( has_action( 'wc_blacklist_manager_save_phone_verification_settings' ) ) {
			do_action( 'wc_blacklist_manager_save_phone_verification_settings' );
		} elseif ( $premium_active && ! defined( 'WC_BLACKLIST_MANAGER_PREMIUM_PHONE_CHANNEL_CONTRACT_VERSION' ) ) {
			$this->save_legacy_premium_phone_settings();
		}

		if ( ! get_settings_errors( 'wc_blacklist_verifications_settings' ) ) {
			add_settings_error( 'wc_blacklist_verifications_settings', 'settings_saved', __( 'Settings saved successfully.', 'wc-blacklist-manager' ), 'updated' );
		}
	}

	private function save_legacy_premium_phone_settings() {
		$provider = isset( $_POST['sms_service'] ) ? sanitize_key( wp_unslash( $_POST['sms_service'] ) ) : '';
		$enabled  = isset( $_POST['phone_verification_enabled'] ) ? '1' : '0';

		if ( ! WC_Blacklist_Manager_Phone_Verification_Boundary::is_supported_provider( $provider ) ) {
			$provider = '';
			$enabled  = '0';
		}

		$message = isset( $_POST['message'] ) ? sanitize_text_field( $this->get_posted_value( 'message' ) ) : '';
		$message = '' !== $message ? wp_kses_post( $message ) : $this->default_sms_message;

		if ( false === strpos( $message, '{code}' ) ) {
			add_settings_error( 'wc_blacklist_verifications_settings', 'invalid_phone_message', __( 'The SMS message must contain the {code} placeholder.', 'wc-blacklist-manager' ), 'error' );
			return;
		}

		$action = isset( $_POST['phone_verification_action'] ) ? sanitize_key( wp_unslash( $_POST['phone_verification_action'] ) ) : 'all';
		if ( ! in_array( $action, array( 'all', 'suspect' ), true ) ) {
			$action = 'all';
		}

		update_option( 'wc_blacklist_phone_verification_enabled', $enabled );
		update_option( 'wc_blacklist_phone_verification_action', $action );
		update_option( 'yoohw_sms_service', $provider );
		update_option(
			'wc_blacklist_phone_verification',
			array(
				'code_length' => isset( $_POST['code_length'] ) ? max( 6, min( 10, intval( wp_unslash( $_POST['code_length'] ) ) ) ) : 6,
				'resend'      => isset( $_POST['resend'] ) ? max( 30, min( 3600, intval( wp_unslash( $_POST['resend'] ) ) ) ) : 180,
				'limit'       => isset( $_POST['limit'] ) ? max( 1, min( 10, intval( wp_unslash( $_POST['limit'] ) ) ) ) : 5,
				'message'     => $message,
			)
		);
	}

	public function wc_blacklist_refresh_merging() {
		// Check for required capabilities (optional, based on your requirements)
		if (!current_user_can('manage_options')) {
			wp_die(esc_html('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}

		if ( function_exists( 'wc_blacklist_manager_require_premium' ) && ! wc_blacklist_manager_require_premium( 'admin' ) ) {
			return;
		}

		check_admin_referer( 'wc_blacklist_refresh_merging' );
	
		// Delete the option
		delete_option('wc_blacklist_whitelist_merged_success');
	
		// Redirect back to the referring page
		$referrer = wp_get_referer();
		if ($referrer) {
			wp_safe_redirect($referrer);
		} else {
			wp_safe_redirect(admin_url());
		}
		exit;
	}
	
	private function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/verifications-email.php';
	}
}

new WC_Blacklist_Manager_Verifications();

add_action('admin_enqueue_scripts', function ($hook) {
	if (isset($_GET['page']) && ($_GET['page'] === 'wc-blacklist-manager-verifications' || $_GET['page'] === 'wc-blacklist-manager-settings')) {
		wp_enqueue_script('thickbox');
		wp_enqueue_style('thickbox');
	}
});
