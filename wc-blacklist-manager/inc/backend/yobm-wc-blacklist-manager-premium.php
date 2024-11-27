<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Premium_Intro {
	private $main_product_id;

	public function __construct() {
		if (!$this->is_premium_active()) {
			add_action('admin_menu', [$this, 'add_premium_page']);
		}
	}

	public function is_premium_active() {
		include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		$is_plugin_active = is_plugin_active('wc-blacklist-manager-premium/wc-blacklist-manager-premium.php');
		$is_license_activated = (get_option('wc_blacklist_manager_premium_license_status') === 'activated');

		return $is_plugin_active && $is_license_activated;
	}

	public function add_premium_page() {
		add_submenu_page(
			'wc-blacklist-manager',
			__('Upgrade Premium', 'wc-blacklist-manager'),
			__('Upgrade Premium', 'wc-blacklist-manager'),
			'manage_options',
			'wc-blacklist-manager-premium',
			[$this, 'render_premium_page']
		);
	}

	public function render_premium_page() {
		ob_start();
		
		$main_product_data = $this->get_product_from_another_site();
		if ($main_product_data === false) {
			echo '<div class="error"><p>' . esc_html__('Failed to retrieve main product data.', 'wc-blacklist-manager') . '</p></div>';
			echo ob_get_clean();
			return;
		}

		$this->main_product_id = $main_product_data['id'];

		?>
		<div class="wrap" style="box-sizing: border-box; padding: 10px; border: 1px solid #ddd; border-radius: 5px; display: flex; gap: 20px; margin-bottom: 40px;">
			<div style="flex: 1;">
				<?php if (!empty($main_product_data['images'])) : ?>
					<img src="<?php echo esc_url($main_product_data['images'][0]['src']); ?>" alt="<?php echo esc_attr($main_product_data['name']); ?>" style="max-width: 100%; height: auto;">
				<?php endif; ?>
			</div>

			<div style="flex: 2; font-size: 16px; line-height: 1.6;">
				<h1 style="font-size: 24px; font-weight: bold; margin-bottom: 20px;"><?php echo esc_html($main_product_data['name']); ?></h1>
				
				<?php if (!empty($main_product_data['short_description'])) : ?>
					<div style="margin-bottom: 20px;"><?php echo wp_kses_post($main_product_data['short_description']); ?></div>
				<?php endif; ?>
				
				<p style="font-size: 18px; margin-bottom: 20px; font-weight: bold;">
					<?php echo esc_html__('Price:', 'wc-blacklist-manager'); ?> 
					<?php 
					$currency = '$';
					if ($main_product_data['type'] === 'variable-subscription') {
						$min_price = PHP_FLOAT_MAX;
						$max_price = 0;

						foreach ($main_product_data['variations'] as $variation) {
							$price = !empty($variation['subscription_price']) ? floatval($variation['subscription_price']) : 
							         (!empty($variation['price']) ? floatval($variation['price']) : 0);
							
							if ($price > 0) {
								$min_price = min($min_price, $price);
								$max_price = max($max_price, $price);
							}
						}

						if ($min_price === PHP_FLOAT_MAX || $max_price === 0) {
							echo esc_html__('Price unavailable', 'wc-blacklist-manager');
						} else {
							echo $currency . esc_html($min_price) . ' - ' . $currency . esc_html($max_price);
						}
					} else {
						if (empty($main_product_data['regular_price']) || $main_product_data['regular_price'] == 0) {
							echo esc_html__('Free', 'wc-blacklist-manager');
						} else {
							if (!empty($main_product_data['sale_price'])) {
								?>
								<span style="text-decoration: line-through; color: red;"><?php echo $currency . esc_html($main_product_data['regular_price']); ?></span> 
								<span style="color: green; font-size: 30px;"><?php echo $currency . esc_html($main_product_data['sale_price']); ?></span>
								<?php
							} else {
								echo $currency . esc_html($main_product_data['regular_price']);
							}
						}
					}
					?>
				</p>
				
				<a href="<?php echo esc_url($main_product_data['permalink']); ?>" target="_blank" style="display: inline-block; padding: 10px 20px; background-color: #0073aa; color: #fff; text-decoration: none; border-radius: 5px;"><?php echo esc_html__('View details', 'wc-blacklist-manager'); ?></a>
				
				<p style="padding-top: 10px;">
					<span class="dashicons dashicons-shield"></span> Blocked Customer First & Last name<br />
					<span class="dashicons dashicons-shield"></span> Prevent Disposable Email & Phone<br />
					<span class="dashicons dashicons-shield"></span> Customer Address Blocking<br />
					<span class="dashicons dashicons-shield"></span> Restrict Accessing for Users of Browsers<br />
					<span class="dashicons dashicons-shield"></span> Suspicious Payment Detection<br />
					<span class="dashicons dashicons-shield"></span> Automation Alerts, Suspect & Block<br />
					<span class="dashicons dashicons-shield"></span> User Roles Permission<br />
					<span class="dashicons dashicons-shield"></span> Full User Blocking Options<br />
					<span class="dashicons dashicons-shield"></span> Enhanced Protection<br />
					<span class="dashicons dashicons-shield"></span> And more...
				</p>
			</div>
		</div>

		<?php
		echo ob_get_clean();
	}

	private function get_product_from_another_site() {
		$remote_site_url = 'https://yoohw.com/wp-json/wc/v3/products/44';
		$consumer_key = 'ck_d544c4297f3ae3de314fc98e5f1482fc0fd1c687';
		$consumer_secret = 'cs_eaed0a60f8fb8effa6faa58d98604597b70abe67';

		$response = wp_remote_get($remote_site_url, [
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
			]
		]);

		if (is_wp_error($response)) {
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$product_data = json_decode($body, true);

		if (isset($product_data['id'])) {
			// Fetch variations if the product is a variable subscription
			if ($product_data['type'] === 'variable-subscription') {
				$variations_response = wp_remote_get($remote_site_url . '/variations', [
					'headers' => [
						'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
					]
				]);

				if (!is_wp_error($variations_response)) {
					$variations_body = wp_remote_retrieve_body($variations_response);
					$product_data['variations'] = json_decode($variations_body, true);
				} else {
					$product_data['variations'] = [];
				}
			}

			return $product_data;
		} else {
			return false;
		}
	}
}


new WC_Blacklist_Manager_Premium_Intro();
