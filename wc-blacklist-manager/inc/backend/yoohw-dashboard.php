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
			$current_user   = wp_get_current_user();
			$color_scheme   = get_user_option( 'admin_color', $current_user->ID );
			$color_schemes  = $this->get_color_schemes();
			$colors         = $color_schemes[ $color_scheme ] ?? $color_schemes['fresh'];
			?>
			<style>
				.yoohw-dashboard {
					--yoohw-primary: <?php echo esc_attr( $colors['background'] ); ?>;
					--yoohw-primary-text: <?php echo esc_attr( $colors['color'] ); ?>;
					--yoohw-bg: #f6f7fb;
					--yoohw-card: #ffffff;
					--yoohw-border: #e2e8f0;
					--yoohw-text: #1e293b;
					--yoohw-muted: #64748b;
					--yoohw-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
					--yoohw-radius: 16px;

					color: var(--yoohw-text);
					margin-top: 20px;
					padding-right: 20px;
				}

				.yoohw-dashboard * {
					box-sizing: border-box;
				}

				.yoohw-hero {
					background: linear-gradient(135deg, var(--yoohw-primary) 0%, #111827 100%);
					color: #fff;
					border-radius: 20px;
					padding: 32px;
					box-shadow: var(--yoohw-shadow);
					margin-bottom: 24px;
					position: relative;
					overflow: hidden;
				}

				.yoohw-hero:before {
					content: "";
					position: absolute;
					top: -80px;
					right: -80px;
					width: 220px;
					height: 220px;
					background: rgba(255, 255, 255, 0.08);
					border-radius: 50%;
				}

				.yoohw-hero:after {
					content: "";
					position: absolute;
					bottom: -60px;
					right: 80px;
					width: 140px;
					height: 140px;
					background: rgba(255, 255, 255, 0.06);
					border-radius: 50%;
				}

				.yoohw-hero__content {
					position: relative;
					z-index: 1;
					max-width: 760px;
				}

				.yoohw-hero h1 {
					margin: 0 0 10px;
					font-size: 30px;
					line-height: 1.2;
					color: #fff;
				}

				.yoohw-hero p {
					margin: 0;
					font-size: 15px;
					line-height: 1.7;
					color: rgba(255,255,255,0.88);
				}

				.yoohw-hero__actions {
					display: flex;
					flex-wrap: wrap;
					gap: 12px;
					margin-top: 22px;
				}

				.yoohw-btn {
					display: inline-flex;
					align-items: center;
					gap: 8px;
					padding: 12px 16px;
					border-radius: 12px;
					text-decoration: none;
					font-weight: 600;
					font-size: 14px;
					transition: all 0.2s ease;
				}

				.yoohw-btn:hover {
					transform: translateY(-1px);
				}

				.yoohw-btn--light {
					background: #fff;
					color: #111827;
				}

				.yoohw-btn--ghost {
					background: rgba(255,255,255,0.12);
					color: #fff;
					border: 1px solid rgba(255,255,255,0.18);
				}

				.yoohw-quick-links {
					display: grid;
					grid-template-columns: repeat(4, minmax(0, 1fr));
					gap: 18px;
					margin-bottom: 28px;
				}

				.yoohw-link-card {
					background: var(--yoohw-card);
					border: 1px solid var(--yoohw-border);
					border-radius: var(--yoohw-radius);
					padding: 22px 18px;
					box-shadow: var(--yoohw-shadow);
					text-align: left;
					transition: transform 0.2s ease, box-shadow 0.2s ease;
				}

				.yoohw-link-card:hover {
					transform: translateY(-2px);
					box-shadow: 0 14px 34px rgba(15, 23, 42, 0.12);
				}

				.yoohw-link-card__icon {
					width: 46px;
					height: 46px;
					border-radius: 12px;
					display: inline-flex;
					align-items: center;
					justify-content: center;
					background: color-mix(in srgb, var(--yoohw-primary) 14%, white);
					color: var(--yoohw-primary);
					margin-bottom: 14px;
				}

				.yoohw-link-card__icon .dashicons {
					font-size: 22px;
					width: 22px;
					height: 22px;
				}

				.yoohw-link-card h3 {
					margin: 0 0 8px;
					font-size: 16px;
					line-height: 1.3;
				}

				.yoohw-link-card p {
					margin: 0 0 14px;
					color: var(--yoohw-muted);
					font-size: 13px;
					line-height: 1.6;
				}

				.yoohw-link-card a {
					color: var(--yoohw-primary);
					text-decoration: none;
					font-weight: 600;
				}

				.yoohw-section {
					margin-bottom: 32px;
				}

				.yoohw-section__header {
					margin-bottom: 16px;
				}

				.yoohw-section__eyebrow {
					display: inline-block;
					margin-bottom: 8px;
					padding: 5px 10px;
					background: #eef2ff;
					color: #4338ca;
					border-radius: 999px;
					font-size: 12px;
					font-weight: 700;
					letter-spacing: 0.02em;
					text-transform: uppercase;
				}

				.yoohw-section h2 {
					margin: 0 0 6px;
					font-size: 24px;
					line-height: 1.25;
				}

				.yoohw-section p {
					margin: 0;
					color: var(--yoohw-muted);
					font-size: 14px;
				}

				.yoohw-products-grid {
					display: grid;
					grid-template-columns: repeat(4, minmax(0, 1fr));
					gap: 20px;
				}

				.yoohw-product-card {
					background: var(--yoohw-card);
					border: 1px solid var(--yoohw-border);
					border-radius: 18px;
					box-shadow: var(--yoohw-shadow);
					overflow: hidden;
					display: flex;
					flex-direction: column;
					min-height: 100%;
					transition: transform 0.2s ease, box-shadow 0.2s ease;
				}

				.yoohw-product-card:hover {
					transform: translateY(-3px);
					box-shadow: 0 16px 36px rgba(15, 23, 42, 0.12);
				}

				.yoohw-product-card__media {
					aspect-ratio: 16 / 10;
					background: #f8fafc;
					display: flex;
					align-items: center;
					justify-content: center;
					padding: 16px;
					border-bottom: 1px solid var(--yoohw-border);
				}

				.yoohw-product-card__media img {
					max-width: 100%;
					max-height: 100%;
					object-fit: contain;
				}

				.yoohw-product-card__body {
					padding: 18px;
					display: flex;
					flex-direction: column;
					gap: 10px;
					flex: 1;
				}

				.yoohw-product-card__title {
					margin: 0;
					font-size: 16px;
					line-height: 1.4;
					min-height: 44px;
				}

				.yoohw-product-card__price {
					margin: 0;
					font-size: 14px;
					color: var(--yoohw-text);
				}

				.yoohw-product-card__footer {
					margin-top: auto;
				}

				.yoohw-price-old {
					text-decoration: line-through;
					color: #dc2626;
					margin-right: 6px;
				}

				.yoohw-price-new {
					color: #16a34a;
					font-weight: 700;
				}

				.yoohw-product-card__btn {
					display: inline-block;
					width: 100%;
					text-align: center;
					padding: 11px 14px;
					border-radius: 12px;
					background: var(--yoohw-primary);
					color: var(--yoohw-primary-text);
					text-decoration: none;
					font-weight: 600;
				}

				.yoohw-loading,
				.yoohw-empty {
					background: #fff;
					border: 1px dashed var(--yoohw-border);
					border-radius: 16px;
					padding: 28px;
					text-align: center;
					color: var(--yoohw-muted);
				}

				@media (max-width: 1280px) {
					.yoohw-quick-links,
					.yoohw-products-grid {
						grid-template-columns: repeat(2, minmax(0, 1fr));
					}
				}

				@media (max-width: 782px) {
					.yoohw-dashboard {
						padding-right: 10px;
					}

					.yoohw-hero {
						padding: 24px 20px;
					}

					.yoohw-hero h1 {
						font-size: 24px;
					}

					.yoohw-quick-links,
					.yoohw-products-grid {
						grid-template-columns: 1fr;
					}
				}
			</style>

			<div class="wrap yoohw-dashboard">
				<section class="yoohw-hero">
					<div class="yoohw-hero__content">
						<h1>Welcome to YoOhw Studio</h1>
						<p>
							Manage your tools, explore our products, access documentation, and get support from one clean dashboard.
						</p>

						<div class="yoohw-hero__actions">
							<a class="yoohw-btn yoohw-btn--light" href="https://yoohw.com" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-admin-site-alt3"></span>
								Visit homepage
							</a>
							<a class="yoohw-btn yoohw-btn--ghost" href="https://yoohw.com/docs" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-portfolio"></span>
								View documentation
							</a>
						</div>
					</div>
				</section>

				<div class="yoohw-quick-links">
					<?php echo wp_kses_post( $this->generate_dashboard_links() ); ?>
				</div>

				<section class="yoohw-section">
					<div class="yoohw-section__header">
						<span class="yoohw-section__eyebrow">Products</span>
						<h2>For WooCommerce</h2>
						<p>Powerful tools designed to work smoothly together for WooCommerce stores.</p>
					</div>
					<div class="yoohw-products-grid" id="products-grid-woocommerce">
						<div class="yoohw-loading">Loading products...</div>
					</div>
				</section>

				<section class="yoohw-section">
					<div class="yoohw-section__header">
						<span class="yoohw-section__eyebrow">Products</span>
						<h2>For WordPress</h2>
						<p>Useful extensions and utilities built for WordPress-powered websites.</p>
					</div>
					<div class="yoohw-products-grid" id="products-grid-wordpress">
						<div class="yoohw-loading">Loading products...</div>
					</div>
				</section>
			</div>

			<script>
				document.addEventListener('DOMContentLoaded', function () {
					function loadProducts(categoryId, gridSelector) {
						var grid = document.querySelector(gridSelector);

						if (!grid) {
							return;
						}

						grid.innerHTML = '<div class="yoohw-loading">Loading products...</div>';

						fetch('https://yoohw.com/wp-json/yoohw/v1/products?category=' + encodeURIComponent(categoryId))
							.then(function (response) {
								if (!response.ok) {
									throw new Error('Failed to load');
								}
								return response.json();
							})
							.then(function (products) {
								if (!Array.isArray(products) || !products.length) {
									grid.innerHTML = '<div class="yoohw-empty">No products found.</div>';
									return;
								}

								grid.innerHTML = products.map(function (product) {
									return generateProductCard(product);
								}).join('');
							})
							.catch(function () {
								grid.innerHTML = '<div class="yoohw-empty">Unable to load products right now.</div>';
							});
					}

					function generateProductCard(product) {
						var image = '';
						if (product.images && product.images.length > 0 && product.images[0].src) {
							image =
								'<a class="yoohw-product-card__media" href="' + escapeHtmlAttr(product.permalink) + '" target="_blank" rel="noopener noreferrer">' +
									'<img src="' + escapeHtmlAttr(product.images[0].src) + '" alt="' + escapeHtmlAttr(product.name) + '">' +
								'</a>';
						} else {
							image = '<div class="yoohw-product-card__media"></div>';
						}

						return '' +
							'<article class="yoohw-product-card">' +
								image +
								'<div class="yoohw-product-card__body">' +
									'<h3 class="yoohw-product-card__title">' + escapeHtml(product.name || '') + '</h3>' +
									'<p class="yoohw-product-card__price">' + getProductPrice(product) + '</p>' +
									'<div class="yoohw-product-card__footer">' +
										'<a class="yoohw-product-card__btn" href="' + escapeHtmlAttr(product.permalink) + '" target="_blank" rel="noopener noreferrer">View details</a>' +
									'</div>' +
								'</div>' +
							'</article>';
					}

					function getProductPrice(product) {
						var currency = '$';

						// Handle variable / subscription products
						if (
							product.type === 'variable' ||
							product.type === 'variable-subscription' ||
							product.type === 'subscription'
						) {
							if (product.price_data) {
								var prices = extractPrices(product.price_data);

								if (prices.length) {
									var minPrice = Math.min.apply(null, prices);
									return 'From ' + currency + minPrice + ' / year';
								}
							}

							return 'Price unavailable';
						}

						// Simple products
						if (!product.regular_price || product.regular_price == 0) {
							return 'Free';
						}

						if (product.sale_price) {
							return '<span class="yoohw-price-old">' + currency + product.regular_price + '</span>' +
								'<span class="yoohw-price-new">' + currency + product.sale_price + '</span>';
						}

						return currency + product.regular_price;
					}

					function extractPrices(priceData) {
						// Extract all numbers from string like "$149 - $1499"
						var matches = priceData.match(/[\d,.]+/g);

						if (!matches) return [];

						return matches.map(function (price) {
							return parseFloat(price.replace(/,/g, ''));
						});
					}

					function escapeHtml(value) {
						return String(value)
							.replace(/&/g, '&amp;')
							.replace(/</g, '&lt;')
							.replace(/>/g, '&gt;')
							.replace(/"/g, '&quot;')
							.replace(/'/g, '&#039;');
					}

					function escapeHtmlAttr(value) {
						return escapeHtml(value || '');
					}

					loadProducts(17, '#products-grid-woocommerce');
					loadProducts(70, '#products-grid-wordpress');
				});
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
			
		private function generate_dashboard_links() {
			$cards = [
				[
					'icon'        => 'dashicons-admin-site-alt3',
					'title'       => 'Our Homepage',
					'description' => 'Visit YoOhw Studio and explore our latest products and updates.',
					'url'         => 'https://yoohw.com',
					'label'       => 'Visit website',
				],
				[
					'icon'        => 'dashicons-sos',
					'title'       => 'Support Center',
					'description' => 'Get help, open a ticket, and find answers faster.',
					'url'         => 'https://yoohw.com/support',
					'label'       => 'Get support',
				],
				[
					'icon'        => 'dashicons-admin-tools',
					'title'       => 'Custom Work',
					'description' => 'Need something specific? Hire us for custom features, integrations, or tailored solutions.',
					'url'         => 'https://yoohw.com/custom-works/',
					'label'       => 'Hire us',
				],
				[
					'icon'        => 'dashicons-email-alt',
					'title'       => 'Contact Us',
					'description' => 'Reach out to our team for questions, feedback, or partnerships.',
					'url'         => 'https://yoohw.com/contact-us',
					'label'       => 'Contact team',
				],
			];

			$output = '';

			foreach ( $cards as $card ) {
				$output .= '<div class="yoohw-link-card">';
				$output .= '<div class="yoohw-link-card__icon"><span class="dashicons ' . esc_attr( $card['icon'] ) . '"></span></div>';
				$output .= '<h3>' . esc_html( $card['title'] ) . '</h3>';
				$output .= '<p>' . esc_html( $card['description'] ) . '</p>';
				$output .= '<a href="' . esc_url( $card['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $card['label'] ) . '</a>';
				$output .= '</div>';
			}

			return $output;
		}
	}

	// Instantiate the class
	new Yo_Ohw_Menu();
}
