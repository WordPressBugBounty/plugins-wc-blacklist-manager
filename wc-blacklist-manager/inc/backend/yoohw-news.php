<?php

if (!defined('ABSPATH')) {
	exit;
}

if ( ! class_exists( 'YoOhw_News' ) ) {
	class YoOhw_News {

		const OPTION_NOTICES           = 'yoohw_admin_notices'; // now an array of up to 5
		const OPTION_SITE_TOKEN        = 'yoohw_site_token';
		const OPTION_LAST_SYNC         = 'yoohw_site_last_synced';
		const OPTION_REGISTERED        = 'yoohw_site_registered';
		const OPTION_REGISTER_FAILURES = 'yoohw_site_register_failures';

		protected $plugin_file;
		protected $plugin_slug;
		protected $plugin_name  = 'YoOhw Plugin';
		protected $plugin_version = '1.0.0';

		public function __construct( $plugin_file ) {
			$this->plugin_file = $plugin_file;
			$this->plugin_slug = plugin_basename( $plugin_file );

			add_action( 'admin_menu', [ $this, 'add_submenu_news' ] );
			add_action( 'rest_api_init', [ $this, 'register_rest_api' ] );
			add_action( 'admin_notices', [ $this, 'maybe_show_latest_notice' ] );
			add_action( 'admin_init', [ $this, 'maybe_dismiss_notice' ] );
			add_action( 'admin_init', [ $this, 'maybe_register_with_hub' ] );
		}

		/* ------------------------------------------------------------
		* Setup
		* ------------------------------------------------------------ */
		public function add_submenu_news() {
			$count    = $this->get_notice_count();
			$label    = 'News';

			if ( $count > 0 ) {
				$label .= sprintf(
					' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>',
					$count
				);
			}

			add_submenu_page(
				'yo-ohw',
				'News',
				$label,
				'manage_options',
				'yoohw-news',
				[ $this, 'news_page' ]
			);
		}

		/**
		 * Count current notices (max 5).
		 *
		 * @return int
		 */
		protected function get_notice_count() {
			$notices = $this->get_notices();
			return is_array( $notices ) ? count( $notices ) : 0;
		}

		/* ------------------------------------------------------------
		* Admin page
		* ------------------------------------------------------------ */
		public function news_page() {
			$notices = $this->get_notices();
			?>
			<div class="wrap">
				<h1>YoOhw News</h1>
				<p>These are the latest notices pushed from YoOhw Studio.</p>

				<?php if ( ! empty( $notices ) ) : ?>
					<?php foreach ( $notices as $index => $notice ) : ?>
						<?php
						$content     = isset( $notice['content'] ) ? wp_kses_post( $notice['content'] ) : '';
						$type        = isset( $notice['type'] ) ? sanitize_html_class( $notice['type'] ) : 'info';
						$button_text = isset( $notice['button_text'] ) ? wp_strip_all_tags( $notice['button_text'] ) : '';
						$button_url  = isset( $notice['button_url'] ) ? $notice['button_url'] : '';

						$dismiss_url = add_query_arg(
							array( 'yoohw_dismiss_notice' => (int) $index ),
							admin_url( 'admin.php?page=yoohw-news' )
						);
						?>
						<div class="notice notice-<?php echo esc_attr( $type ); ?> yoohw-notice is-dismissible">
							<p>
								<strong><?php echo esc_html( '#' . ( (int) $index + 1 ) ); ?></strong>
								<?php echo wp_kses_post( $content ); ?>
							</p>
							<p>
								<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-secondary">
									Dismiss
								</a>

								<?php if ( '' !== $button_text && '' !== $button_url ) : ?>
									<a href="<?php echo esc_url( $button_url ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $button_text ); ?>
									</a>
								<?php endif; ?>
							</p>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p><em>You are all caught up.</em></p>
				<?php endif; ?>
			</div>
			<?php
		}

		/* ------------------------------------------------------------
		* REST API to receive notice
		* ------------------------------------------------------------ */
		public function register_rest_api() {
			register_rest_route(
				'yoohw/v1',
				'/notice',
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handle_notice_request' ],
					'permission_callback' => [ $this, 'notice_permission_check' ],
				]
			);
		}

		public function notice_permission_check( $request ) {
			$incoming_token = $request->get_param( 'token' );
			$stored_token   = get_option( self::OPTION_SITE_TOKEN );
			if ( ! $stored_token ) {
				return true; // first time
			}
			return hash_equals( (string) $stored_token, (string) $incoming_token );
		}

		public function handle_notice_request( WP_REST_Request $request ) {
			$content     = wp_kses_post( $request->get_param( 'content' ) );
			$type        = sanitize_text_field( $request->get_param( 'type' ) ?: 'info' );
			$button_text = sanitize_text_field( $request->get_param( 'button_text' ) ?: '' );
			$button_url  = esc_url_raw( $request->get_param( 'button_url' ) ?: '' );

			// create new notice array
			$new_notice = [
				'content'     => $content,
				'type'        => $type,
				'button_text' => $button_text,
				'button_url'  => $button_url,
				'time'        => time(),
			];

			$notices = $this->get_notices();

			// prepend new notice
			array_unshift( $notices, $new_notice );

			// keep only 5 latest
			$notices = array_slice( $notices, 0, 5 );

			$this->save_notices( $notices );

			return new WP_REST_Response( [ 'success' => true, 'message' => 'Notice saved.' ], 200 );
		}

		/* ------------------------------------------------------------
		* Show only latest notice in admin_notices
		* ------------------------------------------------------------ */
		public function maybe_show_latest_notice() {
			if ( ! current_user_can( 'administrator' ) ) {
				return;
			}

			$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$page = is_string( $page ) ? sanitize_key( $page ) : '';
			if ( 'yoohw-news' === $page ) {
				return;
			}

			$notices = $this->get_notices();
			if ( empty( $notices ) ) {
				return;
			}

			$latest = $notices[0];

			$type        = isset( $latest['type'] ) ? sanitize_html_class( $latest['type'] ) : 'info';
			$button_text = isset( $latest['button_text'] ) ? wp_strip_all_tags( $latest['button_text'] ) : '';
			$button_url  = isset( $latest['button_url'] ) ? $latest['button_url'] : '';

			// Dismiss only the latest (index 0) + nonce.
			$dismiss_url = wp_nonce_url(
				add_query_arg(
					array( 'yoohw_dismiss_notice' => 0 ),
					admin_url( 'admin.php' )
				),
				'yoohw_dismiss_notice',
				'yoohw_notice_nonce'
			);

			echo '<div class="notice notice-' . esc_attr( $type ) . ' yoohw-notice is-dismissible">';
			echo '<p>' . wp_kses_post( $latest['content'] ?? '' ) . '</p>';

			echo '<p><a href="' . esc_url( $dismiss_url ) . '" class="button button-secondary">Dismiss</a> ';

			if ( '' !== $button_text && '' !== $button_url ) {
				echo '<a href="' . esc_url( $button_url ) . '" class="button button-primary" target="_blank" rel="noopener noreferrer">' . esc_html( $button_text ) . '</a>';
			}

			echo '</p>';
			echo '</div>';
		}

		/* ------------------------------------------------------------
		* Dismiss: remove one notice by index
		* ------------------------------------------------------------ */
		public function maybe_dismiss_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$index_raw = filter_input( INPUT_GET, 'yoohw_dismiss_notice', FILTER_SANITIZE_NUMBER_INT );
			$index     = is_scalar( $index_raw ) ? absint( $index_raw ) : 0;

			if ( null === $index_raw ) {
				return;
			}

			$nonce = filter_input( INPUT_GET, 'yoohw_notice_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$nonce = is_string( $nonce ) ? sanitize_text_field( $nonce ) : '';

			if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'yoohw_dismiss_notice' ) ) {
				return;
			}

			$notices = $this->get_notices();
			if ( isset( $notices[ $index ] ) ) {
				unset( $notices[ $index ] );
				$notices = array_values( $notices );
				$this->save_notices( $notices );
			}

			wp_safe_redirect( esc_url_raw( remove_query_arg( array( 'yoohw_dismiss_notice', 'yoohw_notice_nonce' ) ) ) );
			exit;
		}

		/* ------------------------------------------------------------
		* Registration with hub (unchanged logic)
		* ------------------------------------------------------------ */
		public function maybe_register_with_hub() {
			if ( ! $this->is_live_site() ) {
				return;
			}

			if ( (int) get_option( self::OPTION_REGISTERED, 0 ) === 1 ) {
				return;
			}

			$failures = (int) get_option( self::OPTION_REGISTER_FAILURES, 0 );
			if ( $failures >= 5 ) {
				return;
			}

			$token = get_option( self::OPTION_SITE_TOKEN );
			if ( ! $token ) {
				$token = wp_generate_password( 32, false, false );
				update_option( self::OPTION_SITE_TOKEN, $token, false );
			}

			$last = (int) get_option( self::OPTION_LAST_SYNC, 0 );
			if ( $last && time() - $last < 12 * HOUR_IN_SECONDS ) {
				return;
			}

			$this->send_token_to_hub( $token );
		}

		protected function send_token_to_hub( $token ) {
			// load headers right before sending
			$this->load_plugin_header_data();

			$payload = [
				'token'          => $token,
				'site_url'       => site_url(),
				'home_url'       => home_url(),
				'admin_email'    => get_option( 'admin_email' ),
				'site_name'      => get_option( 'blogname' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'plugin_slug'    => $this->plugin_slug,
				'plugin_name'    => $this->plugin_name,
				'plugin_version' => $this->plugin_version,
			];

			$response = wp_remote_post(
				'https://express.yoohw.com/wp-json/yoohw/v1/register-site',
				[
					'timeout' => 10,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( $payload ),
				]
			);

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				update_option( self::OPTION_LAST_SYNC, time(), false );
				update_option( self::OPTION_REGISTERED, 1, false );
				update_option( self::OPTION_REGISTER_FAILURES, 0, false );
				return;
			}

			$current_failures = (int) get_option( self::OPTION_REGISTER_FAILURES, 0 );
			$current_failures++;
			update_option( self::OPTION_REGISTER_FAILURES, $current_failures, false );
			update_option( self::OPTION_LAST_SYNC, time(), false );
		}

		/**
		 * Only used during register.
		 * Tries current file first, then plugin root.
		 */
		protected function load_plugin_header_data() {
			if ( ! function_exists( 'get_file_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$headers = get_file_data(
				$this->plugin_file,
				[
					'Name'    => 'Plugin Name',
					'Version' => 'Version',
				],
				'plugin'
			);

			// if current file has no headers (because it's in /inc/...), try to guess the root plugin file
			if ( empty( $headers['Name'] ) || empty( $headers['Version'] ) ) {
				$root_file = $this->guess_root_plugin_file();
				if ( $root_file && file_exists( $root_file ) ) {
					$headers = get_file_data(
						$root_file,
						[
							'Name'    => 'Plugin Name',
							'Version' => 'Version',
						],
						'plugin'
					);
				}
			}

			$this->plugin_name    = ! empty( $headers['Name'] ) ? $headers['Name'] : $this->plugin_name;
			$this->plugin_version = ! empty( $headers['Version'] ) ? $headers['Version'] : $this->plugin_version;
		}

		/**
		 * Guess /wp-content/plugins/{folder}/{folder}.php from a deeper path.
		 */
		protected function guess_root_plugin_file() {
			$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
			$given_dir   = wp_normalize_path( dirname( $this->plugin_file ) );
			$plugin_root_dir = $given_dir;

			while ( $plugin_root_dir && $plugin_root_dir !== $plugins_dir ) {
				$parent = wp_normalize_path( dirname( $plugin_root_dir ) );
				if ( $parent === $plugins_dir ) {
					break;
				}
				$plugin_root_dir = $parent;
			}

			$plugin_folder = basename( $plugin_root_dir );
			return trailingslashit( $plugin_root_dir ) . $plugin_folder . '.php';
		}

		/* ------------------------------------------------------------
		* Helpers: get/save notices
		* ------------------------------------------------------------ */
		protected function get_notices() {
			$notices = get_option( self::OPTION_NOTICES, [] );
			return is_array( $notices ) ? $notices : [];
		}

		protected function save_notices( array $notices ) {
			update_option( self::OPTION_NOTICES, $notices, false );
		}

		/* ------------------------------------------------------------
		* Live site check
		* ------------------------------------------------------------ */
		protected function is_live_site() {
			$home = home_url();
			$host = wp_parse_url( $home, PHP_URL_HOST );
			if ( ! $host ) {
				// if we can't detect it, assume live
				return true;
			}

			$host = strtolower( $host );

			// 1. Obvious local hosts
			if ( $host === 'localhost' || $host === '127.0.0.1' ) {
				return false;
			}

			// 2. Local/dev TLDs
			$blocked_tlds = [ '.local', '.test', '.example', '.invalid' ];
			foreach ( $blocked_tlds as $tld ) {
				if ( str_ends_with( $host, $tld ) ) {
					return false;
				}
			}

			// 3. Non-standard ports = likely local
			$port = wp_parse_url( $home, PHP_URL_PORT );
			if ( ! empty( $port ) && ! in_array( (int) $port, [ 80, 443 ], true ) ) {
				return false;
			}

			// everything else (including staging.example.com) is allowed
			return true;
		}
	}

	new YoOhw_News( __FILE__ );
}
