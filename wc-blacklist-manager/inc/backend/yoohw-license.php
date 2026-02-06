<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path(__FILE__) . '../cores/activation.php';

if (!class_exists('YoOhw_License_Manager')) {
	class YoOhw_License_Manager {

		public function __construct() {
			global $yo_ohw_license_manager_submenu_added;

			if (!isset($yo_ohw_license_manager_submenu_added)) {
				if (get_option('yoohw_settings_disable_menu') == 1) {
					add_action('admin_menu', [$this, 'add_license_manager_wordpress_submenu']);
				} else {
					add_action('admin_menu', [$this, 'add_license_manager_yoohw_submenu']);
				}
				$yo_ohw_license_manager_submenu_added = true;
			}
		}

		public function add_license_manager_yoohw_submenu() {
			$parent_slug = 'yo-ohw';
			$page_title = 'License Manager';
			$menu_title = 'Licenses';
			$capability = 'manage_options';
			$menu_slug = 'yoohw-license-manager';
			$function = [$this, 'license_manager_page'];
		
			add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function);
		}

		public function add_license_manager_wordpress_submenu() {
			$parent_slug = 'options-general.php';
			$page_title = 'YoOhw License Manager';
			$menu_title = 'YoOhw Licenses';
			$capability = 'manage_options';
			$menu_slug = 'yoohw-license-manager';
			$function = [$this, 'license_manager_page'];
		
			add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function);
		}

		public function license_manager_page() {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
			settings_errors( 'wc_blacklist_manager_premium_license_key' );
			do_action('yoohw_license_manager_content');
			echo '</div>';
		}
	}

	// Instantiate the class
	new YoOhw_License_Manager();
}

if (!class_exists('WC_Blacklist_Manager_Validator_Form')) {
	class WC_Blacklist_Manager_Validator_Form {

		public function __construct() {
			add_action('yoohw_license_manager_content', [$this, 'wc_blacklist_manager_premium_license_manager_content']);
			add_action('admin_init', [$this, 'settings_init']);
		}

		public function wc_blacklist_manager_premium_license_manager_content() {
			$is_activated = get_option('wc_blacklist_manager_premium_license_status') === 'activated';
			?>
			<div class="wrap">

				<style>
					#yoohw-bmp-loading {
						position: fixed;
						inset: 0;
						background: rgba(0,0,0,.35);
						z-index: 999999;
						display: none;
						align-items: center;
						justify-content: center;
					}
					#yoohw-bmp-loading .box {
						background: #fff;
						border-radius: 10px;
						padding: 18px 20px;
						width: 420px;
						max-width: calc(100vw - 40px);
						box-shadow: 0 10px 30px rgba(0,0,0,.25);
					}
					#yoohw-bmp-loading .title {
						font-size: 14px;
						font-weight: 600;
						margin: 0 0 10px 0;
					}
					#yoohw-bmp-loading .row {
						display: flex;
						align-items: center;
						gap: 10px;
					}
					#yoohw-bmp-loading .spinner {
						width: 18px;
						height: 18px;
						border: 2px solid #dcdcde;
						border-top-color: #2271b1;
						border-radius: 50%;
						animation: yoohwSpin .8s linear infinite;
						flex: 0 0 auto;
					}
					#yoohw-bmp-loading .step {
						font-size: 13px;
						margin: 0;
						color: #1d2327;
					}
					#yoohw-bmp-loading .hint {
						margin-top: 10px;
						font-size: 12px;
						color: #646970;
					}
					@keyframes yoohwSpin { to { transform: rotate(360deg); } }
				</style>

				<div id="yoohw-bmp-loading" aria-hidden="true">
					<div class="box" role="dialog" aria-live="polite" aria-busy="true">
						<p class="title"><?php echo esc_html__( 'Processing your license…', 'wc-blacklist-manager' ); ?></p>
						<div class="row">
							<span class="spinner" aria-hidden="true"></span>
							<p class="step" id="yoohw-bmp-loading-step"><?php echo esc_html__( 'Activating license…', 'wc-blacklist-manager' ); ?></p>
						</div>
						<p class="hint">
							<?php echo esc_html__( 'Please keep this tab open. The page will refresh when finished.', 'wc-blacklist-manager' ); ?>
						</p>
					</div>
				</div>

				<form method="post" action="options.php" id="yoohw-bmp-license-form">
					<?php
					settings_fields('wc_blacklist_manager_premium_options');
					do_settings_sections('wc_blacklist_manager_premium');
					if ($is_activated) {
						submit_button('Remove License', 'secondary', 'remove_license', true, ['id' => 'yoohw-bmp-remove']);
					} else {
						submit_button('Activate License', 'primary', 'submit', true, ['id' => 'yoohw-bmp-activate']);
					}
					?>
				</form>

				<script>
				(function() {
					var form = document.getElementById('yoohw-bmp-license-form');
					if (!form) return;

					var btnActivate = document.getElementById('yoohw-bmp-activate');
					var btnRemove   = document.getElementById('yoohw-bmp-remove');

					// Only show loading for Activate (not Remove)
					if (btnRemove) return;
					if (!btnActivate) return;

					var overlay = document.getElementById('yoohw-bmp-loading');
					var stepEl  = document.getElementById('yoohw-bmp-loading-step');

					var steps = [
						'<?php echo esc_js( __( 'Activating license…', 'wc-blacklist-manager' ) ); ?>',
						'<?php echo esc_js( __( 'Downloading Premium package…', 'wc-blacklist-manager' ) ); ?>',
						'<?php echo esc_js( __( 'Installing Premium plugin…', 'wc-blacklist-manager' ) ); ?>',
						'<?php echo esc_js( __( 'Activating Premium plugin…', 'wc-blacklist-manager' ) ); ?>'
					];

					var t1, t2, t3;

					form.addEventListener('submit', function() {
						if (!overlay || !stepEl) return;

						overlay.style.display = 'flex';
						overlay.setAttribute('aria-hidden', 'false');

						// Disable the button to prevent double submit
						btnActivate.disabled = true;

						// Simple timed progress messages (server-side will do the real work)
						stepEl.textContent = steps[0];
						t1 = setTimeout(function(){ stepEl.textContent = steps[1]; }, 900);
						t2 = setTimeout(function(){ stepEl.textContent = steps[2]; }, 2000);
						t3 = setTimeout(function(){ stepEl.textContent = steps[3]; }, 3500);
					});

					// If the browser navigates away / reloads, cleanup timers
					window.addEventListener('beforeunload', function() {
						clearTimeout(t1); clearTimeout(t2); clearTimeout(t3);
					});
				})();
				</script>

			</div>
			<?php
		}

		public function settings_init() {
			register_setting(
				'wc_blacklist_manager_premium_options', 
				'wc_blacklist_manager_premium_license_key', 
				[$this, 'wc_blacklist_manager_premium_validate_license_key']
			);

			add_settings_section(
				'wc_blacklist_manager_premium_section',
				__('Blacklist Manager Premium', 'wc-blacklist-manager'),
				[$this, 'section_callback'],
				'wc_blacklist_manager_premium'
			);

			add_settings_field(
				'wc_blacklist_manager_premium_license_key_field',
				__('License key', 'wc-blacklist-manager'),
				[$this, 'license_key_field_callback'],
				'wc_blacklist_manager_premium',
				'wc_blacklist_manager_premium_section'
			);

			add_settings_field(
				'wc_blacklist_manager_premium_license_expired_field',
				__('Expired date', 'wc-blacklist-manager'),
				[$this, 'license_expired_field_callback'],
				'wc_blacklist_manager_premium',
				'wc_blacklist_manager_premium_section'
			);
		}

		public function section_callback() {
			echo '<p>' . esc_html__('Enter your license key to activate the Blacklist Manager Premium plugin.', 'wc-blacklist-manager') . '</p>';
		}

		public function license_key_field_callback() {
			$setting = get_option('wc_blacklist_manager_premium_license_key');
			$is_activated = get_option('wc_blacklist_manager_premium_license_status') === 'activated';
			?>
			<input type="password" name="wc_blacklist_manager_premium_license_key"
				<?php if ( $is_activated ) : ?>
					value="****************************************" disabled
				<?php else : ?>
					value="<?php echo esc_attr( $setting ); ?>"
				<?php endif; ?> 
				>
			<?php if ($is_activated): ?>
				<span class="dashicons dashicons-yes-alt" style="color: #00a32a; vertical-align: text-top;"></span>
				<span style="color: #00a32a;">activated</span>
			<?php else: ?>
				<span><a href="https://yoohw.com/product/blacklist-manager-premium/" class="button-secondary" target="_blank">Purchase a license</a></span>
			<?php endif; ?>
			<?php
		}

		public function license_expired_field_callback() {
			$expired = get_option( 'wc_blacklist_manager_premium_license_expired' );

			if ( empty( $expired ) ) {
				echo '<span>N/A</span>';
				return;
			}

			$utc_ts = strtotime( $expired . ' UTC' );
			if ( false === $utc_ts ) {
				$local_date = date_i18n( get_option( 'date_format' ) );
				$is_future  = false;
			} else {
				$local_date     = date_i18n( get_option( 'date_format' ), $utc_ts, true );
				$current_gmt_ts = current_time( 'timestamp', true );
				$is_future      = ( $utc_ts >= $current_gmt_ts );
			}

			$style = $is_future
				? 'background: #00a32a; color: #fff; padding: 5px; border-radius: 5px; font-size: 0.85em;'
				: 'background: #d63638; color: #fff; padding: 5px; border-radius: 5px; font-size: 0.85em;';

			echo '<span style="' . esc_attr( $style ) . '">' . esc_html( $local_date ) . '</span>';
		}
	}
}

new WC_Blacklist_Manager_Validator_Form();

