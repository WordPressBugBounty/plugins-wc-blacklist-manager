<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Yo_Ohw_Menu')) {
	class Yo_Ohw_Menu {	
		public function __construct() {
			global $yo_ohw_menu_added;
	
			// Early return if menu is disabled
			if (get_option('yoohw_settings_disable_menu') == 1) {
				return;
			}
	
			// Prevent duplicate menu addition
			if (!isset($yo_ohw_menu_added)) {
				add_action('admin_menu', [$this, 'add_menu']);
				$yo_ohw_menu_added = true;
			}
			
		}
	
		public function add_menu() {
			$page_title = 'YoOhw';
			$menu_title = 'YoOhw Studio';
			$capability = 'manage_options';
			$menu_slug = 'yo-ohw';
			$icon_svg = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1200" zoomAndPan="magnify" viewBox="0 0 900 899.99999" height="1200" preserveAspectRatio="xMidYMid meet" version="1.0"><defs><g/></defs><g fill="#a7aaad" fill-opacity="1"><g transform="translate(35.472337, 736.499981)"><g><path d="M 385.296875 -577.546875 L 272.265625 -360.546875 L 159.234375 -577.546875 L -0.828125 -577.546875 L 201.3125 -234.3125 L 201.3125 0 L 343.21875 0 L 343.21875 -235.140625 L 545.359375 -577.546875 Z M 385.296875 -577.546875 "/></g></g></g><g fill="#a7aaad" fill-opacity="1"><g transform="translate(415.002437, 736.499981)"><g><path d="M 26.40625 -189.765625 C 26.40625 -150.160156 35.753906 -115.503906 54.453125 -85.796875 C 73.148438 -56.097656 99 -33 132 -16.5 C 165.007812 0 202.140625 8.25 243.390625 8.25 C 284.640625 8.25 321.628906 0 354.359375 -16.5 C 387.085938 -33 412.941406 -56.097656 431.921875 -85.796875 C 450.898438 -115.503906 460.390625 -150.160156 460.390625 -189.765625 C 460.390625 -229.921875 450.898438 -264.847656 431.921875 -294.546875 C 412.941406 -324.242188 387.085938 -347.34375 354.359375 -363.84375 C 321.628906 -380.351562 284.640625 -388.609375 243.390625 -388.609375 C 202.140625 -388.609375 165.007812 -380.351562 132 -363.84375 C 99 -347.34375 73.148438 -324.242188 54.453125 -294.546875 C 35.753906 -264.847656 26.40625 -229.921875 26.40625 -189.765625 Z M 154.28125 -189.765625 C 154.28125 -209.566406 158.40625 -226.617188 166.65625 -240.921875 C 174.90625 -255.222656 185.628906 -266.222656 198.828125 -273.921875 C 212.035156 -281.617188 226.890625 -285.46875 243.390625 -285.46875 C 259.335938 -285.46875 274.050781 -281.617188 287.53125 -273.921875 C 301.007812 -266.222656 311.734375 -255.222656 319.703125 -240.921875 C 327.679688 -226.617188 331.671875 -209.566406 331.671875 -189.765625 C 331.671875 -169.960938 327.679688 -153.046875 319.703125 -139.015625 C 311.734375 -124.992188 301.007812 -114.128906 287.53125 -106.421875 C 274.050781 -98.722656 259.335938 -94.875 243.390625 -94.875 C 226.890625 -94.875 212.035156 -98.722656 198.828125 -106.421875 C 185.628906 -114.128906 174.90625 -124.992188 166.65625 -139.015625 C 158.40625 -153.046875 154.28125 -169.960938 154.28125 -189.765625 Z M 154.28125 -189.765625 "/></g></g></g></svg>');
	
			add_menu_page($page_title, $menu_title, $capability, $menu_slug, [$this, 'main_page'], $icon_svg, 999);
			add_submenu_page(
				'yo-ohw',
				'Homepage',
				'Homepage',
				'manage_options',
				'yo-ohw',
				array($this, 'main_page')
			);
		}
	
		public function main_page() {
			$current_user = wp_get_current_user();
			$color_scheme = get_user_option('admin_color', $current_user->ID);
			$color_schemes = $this->get_color_schemes();
			$colors = $color_schemes[$color_scheme] ?? $color_schemes['fresh'];
			?>
			<style>
				.dashboard-container {
					display: flex;
					flex-wrap: wrap;
					margin-bottom: 20px;
				}
				.dashboard, .posts-list {
					margin-bottom: 20px;
				}
				.dashboard {
					flex: 3;
					display: flex;
					padding: 10px 10px 40px;
					border: 1px solid #ddd;
					border-radius: 5px;
					margin-right: 10px;
				}
				.dashboard .column {
					flex: 1;
					text-align: center;
					margin-right: 10px;
				}
				.dashboard .column:last-child {
					margin-right: 0;
				}
				.dashboard .column .dashicons {
					font-size: 100px;
					display: contents;
				}
				.posts-list {
					flex: 1;
					border: 1px solid #ddd;
					border-radius: 5px;
					padding: 10px;
				}
				.posts-list h2 {
					font-size: 20px;
					font-weight: bold;
					margin-bottom: 20px;
				}
				.posts-list ul {
					list-style-type: none;
					padding: 0;
				}
				.posts-list li {
					margin-bottom: 20px;
					display: flex;
					align-items: center;
				}
				.posts-list img {
					width: 70px;
					height: 70px;
					margin-right: 10px;
					border-radius: 5px;
					object-fit: cover;
				}
				.products-grid {
					display: grid;
					grid-template-columns: repeat(4, 1fr);
					gap: 20px;
					margin-right: 20px;
				}
				@media (max-width: 768px) {
					.dashboard, .posts-list {
						flex: 1 0 100%;
						margin-right: 0;
					}
					.dashboard .column {
						margin-right: 0;
						margin-bottom: 10px;
					}
					.products-grid {
						grid-template-columns: repeat(2, 1fr);
					}
				}
			</style>
		
			<div class="wrap">
				<h1>Welcome to YoOhw Studio Dashboard!</h1>
				<p>Keep updated and explore our works from here.</p>
				<div class="dashboard-container">
					<div class="dashboard">
						<?php echo $this->generate_dashboard_links($colors); ?>
					</div>
				</div>
		
				<h2 style="font-size: 20px; font-weight: bold; margin-bottom: 20px;">Our products</h2>
				<p>All products come with a free version and work together effortlessly.</p>

				<h3>For WooCommerce</h3>
				<div class="products-grid" id="products-grid-woocommerce">
					<p>Loading products...</p>
				</div>

				<h3>For WordPress</h3>
				<div class="products-grid" id="products-grid-wordpress">
					<p>Loading products...</p>
				</div>
			</div>
		
			<script>
			// Pass PHP colors to JavaScript
			var colors = {
				background: '<?php echo esc_attr($colors['background']); ?>',
				color: '<?php echo esc_attr($colors['color']); ?>'
			};

			// Wait for the DOM to load
			document.addEventListener('DOMContentLoaded', function(){

				// Helper to fetch and render a category into a given grid
				function loadProducts(categoryId, gridSelector) {
					var grid = document.querySelector(gridSelector);
					grid.innerHTML = '<p>Loading products...</p>';

					fetch("https://yoohw.com/wp-json/yoohw/v1/products?category=" + categoryId)
					.then(function(response) {
						return response.json();
					})
					.then(function(products) {
						grid.innerHTML = '';
						products.forEach(function(product) {
							grid.innerHTML += generateProductCard(product, colors);
						});
						// Ensure always 4 columns
						var additionalCount = 4 - products.length;
						for (var i = 0; i < additionalCount; i++) {
							grid.innerHTML += '<div style="box-sizing:border-box; padding:10px; border:1px solid #ddd; border-radius:5px; visibility:hidden;"></div>';
						}
					})
					.catch(function() {
						grid.innerHTML = '<div class="error"><p>No additional products found.</p></div>';
					});
				}

				// Load WooCommerce (category 17)
				loadProducts(17, '#products-grid-woocommerce');

				// Load WordPress (category 70)
				loadProducts(70, '#products-grid-wordpress');

			});

			function generateProductCard(product, colors) {
				var image = '';
				if (product.images && product.images.length > 0) {
					image = '<a href="' + product.permalink + '" target="_blank">' +
								'<img src="' + product.images[0].src + '" alt="' + product.name + '" style="max-width:100%; height:auto; margin-bottom:10px;">' +
							'</a>';
				}

				var price_info = getProductPrice(product);

				return '<div style="box-sizing:border-box; padding:10px 10px 20px; background-color: #fff; border:1px solid #ddd; border-radius:5px; text-align:center;">' +
						image +
						'<h3 style="font-size:16px; margin-bottom:10px;">' + product.name + '</h3>' +
						'<p style="font-size:14px; margin-bottom:10px;">Price: ' + price_info + '</p>' +
						'<a href="' + product.permalink + '" target="_blank" style="display:inline-block; padding:5px 10px; background-color:' + colors.background + '; color:' + colors.color + '; text-decoration:none; border-radius:5px;">View details</a>' +
					'</div>';
			}

			function getProductPrice(product) {
				var currency = '$';
				if (product.type === 'variable' || product.type === 'variable-subscription' || product.type === 'subscription') {
					return product.price_data ? product.price_data : 'Price unavailable';
				} else {
					if (!product.regular_price || product.regular_price == 0) {
						return 'Free';
					}
					if (product.sale_price) {
						return '<span style="text-decoration:line-through; color:red;">' + currency + product.regular_price + '</span> ' +
							'<span style="color:green;">' + currency + product.sale_price + '</span>';
					} else {
						return currency + product.regular_price;
					}
				}
			}
			</script>

			<?php
		}		
	
		private function get_color_schemes() {
			return [
				'fresh' => ['background' => '#0073aa', 'color' => '#fff'],
				'light' => ['background' => '#e5e5e5', 'color' => '#444'],
				'blue' => ['background' => '#52accc', 'color' => '#fff'],
				'coffee' => ['background' => '#59524c', 'color' => '#fff'],
				'ectoplasm' => ['background' => '#523f6d', 'color' => '#fff'],
				'midnight' => ['background' => '#363b3f', 'color' => '#fff'],
				'ocean' => ['background' => '#738e96', 'color' => '#fff'],
				'sunrise' => ['background' => '#dd823b', 'color' => '#fff'],
			];
		}
	
		private function generate_dashboard_links($colors) {
			return '
				<div class="column"><p><span class="dashicons dashicons-superhero"></span></p>
				<a href="https://yoohw.com" target="_blank" style="display: inline-block; padding: 10px; background-color: ' . esc_attr($colors['background']) . '; color: ' . esc_attr($colors['color']) . '; text-decoration: none; border-radius: 5px;">Our homepage</a></div>
				<div class="column"><p><span class="dashicons dashicons-sos"></span></p>
				<a href="https://yoohw.com/support" target="_blank" style="display: inline-block; padding: 10px; background-color: ' . esc_attr($colors['background']) . '; color: ' . esc_attr($colors['color']) . '; text-decoration: none; border-radius: 5px;">Support center</a></div>
				<div class="column"><p><span class="dashicons dashicons-portfolio"></span></p>
				<a href="https://yoohw.com/docs" target="_blank" style="display: inline-block; padding: 10px; background-color: ' . esc_attr($colors['background']) . '; color: ' . esc_attr($colors['color']) . '; text-decoration: none; border-radius: 5px;">Documentation</a></div>
				<div class="column"><p><span class="dashicons dashicons-email"></span></p>
				<a href="https://yoohw.com/contact-us" target="_blank" style="display: inline-block; padding: 10px; background-color: ' . esc_attr($colors['background']) . '; color: ' . esc_attr($colors['color']) . '; text-decoration: none; border-radius: 5px;">Contact us</a></div>';
				
		}
	}

	// Instantiate the class
	new Yo_Ohw_Menu();
}
