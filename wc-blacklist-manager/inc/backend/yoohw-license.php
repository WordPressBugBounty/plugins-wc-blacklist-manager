<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'YoOhw_License_Manager' ) ) {
	class YoOhw_License_Manager {

		public function __construct() {
			global $yo_ohw_license_manager_submenu_added;

			if ( ! isset( $yo_ohw_license_manager_submenu_added ) ) {
				if ( get_option( 'yoohw_settings_disable_menu' ) == 1 ) {
					add_action( 'admin_menu', [ $this, 'add_license_manager_wordpress_submenu' ] );
				} else {
					add_action( 'admin_menu', [ $this, 'add_license_manager_yoohw_submenu' ] );
				}
				$yo_ohw_license_manager_submenu_added = true;
			}
		}

		public function add_license_manager_yoohw_submenu() {
			add_submenu_page(
				'yo-ohw',
				'License Manager',
				'Licenses',
				'manage_options',
				'yoohw-license-manager',
				[ $this, 'license_manager_page' ]
			);
		}

		public function add_license_manager_wordpress_submenu() {
			add_submenu_page(
				'options-general.php',
				'YoOhw License Manager',
				'YoOhw Licenses',
				'manage_options',
				'yoohw-license-manager',
				[ $this, 'license_manager_page' ]
			);
		}

		public function license_manager_page() {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
			settings_errors( 'wc_blacklist_manager_premium_license_key' );
			do_action( 'yoohw_license_manager_content' );
			echo '</div>';
		}
	}

	new YoOhw_License_Manager();
}

if ( ! class_exists( 'WC_Blacklist_Manager_Validator_Form' ) ) {
	class WC_Blacklist_Manager_Validator_Form {

		public function __construct() {
			add_action( 'yoohw_license_manager_content', [ $this, 'wc_blacklist_manager_premium_license_manager_content' ] );
			add_action( 'admin_init', [ $this, 'settings_init' ] );
		}

		private function get_license_args() {
			return [
				'license_key_option' => 'wc_blacklist_manager_premium_license_key',
				'status_option'      => 'wc_blacklist_manager_premium_license_status',
				'state_option'       => 'wc_blacklist_manager_premium_license_state',
				'product_id'         => '44',
			];
		}

		private function get_runtime_status() {
			$args = $this->get_license_args();

			return YoOhw_License_Runtime::get_runtime_status(
				[
					'state_option' => $args['state_option'],
					'product_id'   => $args['product_id'],
				]
			);
		}

		private function is_runtime_active() {
			return YoOhw_License_Runtime::is_active( $this->get_license_args() );
		}

		private function get_display_status() {
			$runtime_status = $this->get_runtime_status();

			if ( in_array( $runtime_status, [ 'activated', 'grace' ], true ) ) {
				return [
					'label' => __( 'Active', 'wc-blacklist-manager' ),
					'class' => 'is-active',
				];
			}

			if ( 'expired' === $runtime_status ) {
				return [
					'label' => __( 'Expired', 'wc-blacklist-manager' ),
					'class' => 'is-expired',
				];
			}

			return [
				'label' => __( 'Inactive', 'wc-blacklist-manager' ),
				'class' => 'is-inactive',
			];
		}

		public function wc_blacklist_manager_premium_license_manager_content() {
			$is_active      = $this->is_runtime_active();
			$display_status = $this->get_display_status();
			$status_label   = $display_status['label'];
			$status_class   = $display_status['class'];
			?>
			<div class="wrap">

				<style>
					.yoohw-license-card {
						background: #fff;
						border: 1px solid #dcdcde;
						border-radius: 12px;
						padding: 24px;
						max-width: 760px;
						box-shadow: 0 1px 2px rgba(0,0,0,.04);
					}
					.yoohw-license-card h2 {
						margin-top: 0;
					}
					.yoohw-license-header {
						float: right;
					}
					.yoohw-license-badge {
						display: inline-flex;
						align-items: center;
						padding: 7px 12px;
						border-radius: 999px;
						font-size: 13px;
						font-weight: 600;
					}
					.yoohw-license-badge.is-active {
						background: #edfaef;
						color: #0f6b28;
					}
					.yoohw-license-badge.is-expired,
					.yoohw-license-badge.is-inactive {
						background: #fbeaea;
						color: #b32d2e;
					}
					.yoohw-license-meta {
						color: #50575e;
						font-size: 13px;
					}
					.yoohw-license-actions {
						margin-top: 22px;
					}
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
						<p class="hint"><?php echo esc_html__( 'Please keep this tab open. The page will refresh when finished.', 'wc-blacklist-manager' ); ?></p>
					</div>
				</div>

				<div class="yoohw-license-card">
					<div class="yoohw-license-header">
						<span class="yoohw-license-badge <?php echo esc_attr( $status_class ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
					</div>

					<form method="post" action="options.php" id="yoohw-bmp-license-form">
						<?php
						settings_fields( 'wc_blacklist_manager_premium_options' );
						do_settings_sections( 'wc_blacklist_manager_premium' );
						?>

						<div class="yoohw-license-actions">
							<?php
							if ( $is_active ) {
								submit_button( __( 'Remove License', 'wc-blacklist-manager' ), 'secondary', 'remove_license', false, [ 'id' => 'yoohw-bmp-remove' ] );
							} else {
								submit_button( __( 'Activate License', 'wc-blacklist-manager' ), 'primary', 'submit', false, [ 'id' => 'yoohw-bmp-activate' ] );
								echo ' <a href="https://yoohw.com/product/blacklist-manager-premium/" class="button button-secondary" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Purchase a license', 'wc-blacklist-manager' ) . '</a>';
							}
							?>
						</div>
					</form>
				</div>

				<script>
				(function() {
					var form = document.getElementById('yoohw-bmp-license-form');
					if (!form) return;

					var btnActivate = document.getElementById('yoohw-bmp-activate');
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

					form.addEventListener('submit', function(event) {
						if (!event.submitter || event.submitter.id !== 'yoohw-bmp-activate') {
							return;
						}

						if (!overlay || !stepEl) return;

						overlay.style.display = 'flex';
						overlay.setAttribute('aria-hidden', 'false');
						btnActivate.disabled = true;

						stepEl.textContent = steps[0];
						t1 = setTimeout(function(){ stepEl.textContent = steps[1]; }, 900);
						t2 = setTimeout(function(){ stepEl.textContent = steps[2]; }, 2000);
						t3 = setTimeout(function(){ stepEl.textContent = steps[3]; }, 3500);
					});

					window.addEventListener('beforeunload', function() {
						clearTimeout(t1);
						clearTimeout(t2);
						clearTimeout(t3);
					});
				})();
				</script>

			</div>
			<?php
		}

		public function settings_init() {
			add_settings_section(
				'wc_blacklist_manager_premium_section',
				__( 'Blacklist Manager Premium', 'wc-blacklist-manager' ),
				[ $this, 'section_callback' ],
				'wc_blacklist_manager_premium'
			);

			add_settings_field(
				'wc_blacklist_manager_premium_license_key_field',
				__( 'License key', 'wc-blacklist-manager' ),
				[ $this, 'license_key_field_callback' ],
				'wc_blacklist_manager_premium',
				'wc_blacklist_manager_premium_section'
			);

			add_settings_field(
				'wc_blacklist_manager_premium_license_expired_field',
				__( 'Expiration date', 'wc-blacklist-manager' ),
				[ $this, 'license_expired_field_callback' ],
				'wc_blacklist_manager_premium',
				'wc_blacklist_manager_premium_section'
			);
		}

		public function section_callback() {
			echo '<p>' . esc_html__( 'Enter your license key to activate the Blacklist Manager Premium plugin.', 'wc-blacklist-manager' ) . '</p>';
		}

		public function license_key_field_callback() {
			$setting        = (string) get_option( 'wc_blacklist_manager_premium_license_key', '' );
			$runtime_status = $this->get_runtime_status();
			$is_active      = $this->is_runtime_active();

			$display_value = $setting;

			if ( '' !== $setting && $is_active ) {
				$len = strlen( $setting );
				if ( $len > 8 ) {
					$display_value = str_repeat( '•', max( 0, $len - 8 ) ) . substr( $setting, -8 );
				}
			}
			?>
			<input
				type="text"
				name="wc_blacklist_manager_premium_license_key"
				value="<?php echo esc_attr( $display_value ); ?>"
				<?php echo $is_active ? 'readonly' : ''; ?>
				class="regular-text"
				autocomplete="off"
			/>
			<?php
			if ( in_array( $runtime_status, [ 'activated', 'grace' ], true ) ) {
				echo '<p class="description" style="color:#00a32a;">' . esc_html__( 'Your license is active.', 'wc-blacklist-manager' ) . '</p>';
			} elseif ( 'expired' === $runtime_status ) {
				echo '<p class="description" style="color:#d63638;">' . esc_html__( 'Your license has expired.', 'wc-blacklist-manager' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Enter a valid license key to activate Premium on this site.', 'wc-blacklist-manager' ) . '</p>';
			}
		}

		public function license_expired_field_callback() {
			$runtime_status = $this->get_runtime_status();
			$expired        = get_option( 'wc_blacklist_manager_premium_license_expired' );

			if ( empty( $expired ) ) {
				echo '<span>—</span>';
				return;
			}

			$utc_ts = strtotime( $expired . ' UTC' );
			if ( false === $utc_ts ) {
				echo '<span>—</span>';
				return;
			}

			$local_date = date_i18n( get_option( 'date_format' ), $utc_ts, true );

			$style = ( 'expired' === $runtime_status )
				? 'background:#d63638;color:#fff;padding:5px 8px;border-radius:999px;font-size:12px;'
				: 'background:#00a32a;color:#fff;padding:5px 8px;border-radius:999px;font-size:12px;';

			echo '<span style="' . esc_attr( $style ) . '">' . esc_html( $local_date ) . '</span>';
		}
	}
}

new WC_Blacklist_Manager_Validator_Form();