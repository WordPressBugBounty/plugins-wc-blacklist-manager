<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'YOGB_BM_ALLOW_NONPROD' ) ) {
    define( 'YOGB_BM_ALLOW_NONPROD', false );
}

class YOGB_BM_Registrar {
	/* Storage keys */
	const OPT_API_KEY     = 'yogb_bm_api_key';
	const OPT_API_SECRET  = 'yogb_bm_api_secret';
	const OPT_REPORTER_ID = 'yogb_bm_reporter_id';

	/* Server base */
	const SERVER_BASE = 'https://bmc.yoohw.com/wp-json/yoohw-gbl';

	/* Transient + cron hook */
	const TRANSIENT_PREFIX = 'yogb_bm_challenge_';
	const CRON_HOOK        = 'yogb_bm_run_registration';

	public static function init() {
		// A) REST proof route (public)
		add_action( 'rest_api_init', function() {
			register_rest_route( 'blacklist/v1', '/challenge/(?P<id>[a-f0-9\-]{32,36})', [
				'methods'  => 'GET',
				'callback' => [ __CLASS__, 'rest_challenge_echo' ],
				'permission_callback' => '__return_true',
			] );
		} );

		// B) admin-ajax fallback (public)
		add_action('wp_ajax_nopriv_yogb_bm_challenge', function () {
			$id    = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';
			$token = get_transient( self::TRANSIENT_PREFIX . $id );
			if ( ! $token ) { status_header(404); wp_die(); }
			header('Content-Type: text/plain; charset=utf-8');
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Pragma: no-cache');
			echo $token;
			wp_die();
		});

		// C) front-door query fallback: https://example.com/?yogb_bm_challenge=<id>
		add_action('template_redirect', function () {
			if ( empty($_GET['yogb_bm_challenge']) ) return;
			$id    = sanitize_text_field( (string) $_GET['yogb_bm_challenge'] );
			$token = get_transient( self::TRANSIENT_PREFIX . $id );
			if ( ! $token ) { status_header(404); exit; }
			header('Content-Type: text/plain; charset=utf-8');
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Pragma: no-cache');
			echo $token;
			exit;
		});

		// D) If a security plugin blocks REST, allow ONLY our public route
		add_filter('rest_authentication_errors', function ($result) {
			if ( is_wp_error($result) ) {
				$prefix = '/' . rest_get_url_prefix() . '/blacklist/v1/challenge/';
				$uri = $_SERVER['REQUEST_URI'] ?? '';
				if ( strpos($uri, $prefix) !== false ) {
					return null; // clear the block for our route
				}
			}
			return $result;
		}, 999);

		// Activation → schedule registration (if prod)
		register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, [ __CLASS__, 'on_activate' ] );

		// Admin bootstrap: register if needed
		add_action( 'admin_init', function() {
			self::maybe_register_on_admin();
		} );

		// React to version change
		add_action( 'updated_option', function( $option, $old, $new ) {
			if ( $option === 'wc_blacklist_manager_version' && $old !== $new ) {
				self::schedule_registration();
			}
		}, 10, 3 );

		// Cron worker(s)
		add_action( self::CRON_HOOK, function() {
			YOGB_BM_Registrar::run_registration(true);
		});
		add_action( 'wc_bm_run_registration', [ __CLASS__, 'run_registration' ] ); // back-compat

		// Manual retry from settings page.
		add_action( 'admin_post_yogb_bm_retry_registration', [ __CLASS__, 'handle_manual_retry' ] );

		// Show result notice on settings pages.
		add_action( 'admin_notices', [ __CLASS__, 'maybe_show_retry_notice' ] );
	}

	/** Activation */
	public static function on_activate() {
		[ $nonprod, $why ] = self::detect_nonprod();
		if ( $nonprod && ! YOGB_BM_ALLOW_NONPROD ) {
			return;
		}
		self::schedule_registration( 60 );
	}

	/** Try to register when keys missing or version changed (admin context helper) */
	private static function maybe_register_on_admin() {
		[ $nonprod, $why ] = self::detect_nonprod();
		if ( $nonprod && ! YOGB_BM_ALLOW_NONPROD ) {
			return;
		}

		$api_key     = get_option( self::OPT_API_KEY );
		$api_secret  = get_option( self::OPT_API_SECRET );
		$reporter_id = get_option( self::OPT_REPORTER_ID );

		$have_full_creds = ( $api_key && $api_secret && $reporter_id );
		$stored_ver = get_option( 'wc_blacklist_manager_version' );
		$need = ( ! $have_full_creds || $stored_ver !== WC_BLACKLIST_MANAGER_VERSION );

		if ( ! $need ) {
			return;
		}

		// Respect cooldown: if we’re in a backoff window, do not trigger anything now.
		$cooldown_until = (int) get_option( 'yogb_bm_reg_cooldown_until', 0 );
		if ( time() < $cooldown_until ) {
			return;
		}

		// Only schedule a single event; do NOT run immediately on admin_init.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_registration();
		}
	}

	private static function schedule_registration( int $delay = 60 ) {
		// Don’t schedule if we are in a cooldown window
		$cooldown_until = (int) get_option( 'yogb_bm_reg_cooldown_until', 0 );
		if ( time() < $cooldown_until ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 10, $delay ), self::CRON_HOOK );
		}
	}

	/** Public REST: echo the token as plain text + no-cache */
	public static function rest_challenge_echo( WP_REST_Request $req ) {
		$id    = sanitize_text_field( (string) $req->get_param( 'id' ) );
		$token = get_transient( self::TRANSIENT_PREFIX . $id );
		if ( ! $token ) {
			return new WP_REST_Response( [ 'error' => 'unknown_challenge' ], 404 );
		}
		return new WP_REST_Response( $token, 200, [
			'Content-Type'  => 'text/plain; charset=utf-8',
			'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'        => 'no-cache',
		] );
	}

	/** Main register flow */
	public static function run_registration( $quiet = false ) {
		// Single-flight lock (prevents repeated runs within 60s)
		if ( get_transient('yogb_bm_reg_lock') ) {
			return;
		}
		set_transient('yogb_bm_reg_lock', 1, 60);

		$unlock = function() { delete_transient('yogb_bm_reg_lock'); };

		// Global cooldown window (e.g. after server 429)
		$cooldown_until = (int) get_option( 'yogb_bm_reg_cooldown_until', 0 );
		if ( time() < $cooldown_until ) {
			$unlock();
			return;
		}

		// Hard cap on number of attempts to avoid infinite retries
		$attempts = (int) get_option( 'yogb_bm_reg_attempts', 0 );
		if ( $attempts >= 10 ) { // adjust threshold as you like
			if ( ! $quiet ) {
				self::admin_notice_once(
					'yogb_bm_reg_max_attempts',
					'Blacklist Manager registration has been paused after multiple failed attempts. Please check your site configuration or contact support.'
				);
			}
			$unlock();
			return;
		}
		update_option( 'yogb_bm_reg_attempts', $attempts + 1, false );

		try {
			// Already registered?
			$api_key     = get_option( self::OPT_API_KEY );
			$api_secret  = get_option( self::OPT_API_SECRET );
			$reporter_id = get_option( self::OPT_REPORTER_ID );

			// Only treat as fully registered if reporter_id is present.
			if ( $api_key && $api_secret && $reporter_id ) {
				// Clear backoff & attempts & cooldown on success
				delete_option( 'yogb_bm_reg_backoff' );
				delete_option( 'yogb_bm_reg_attempts' );
				delete_option( 'yogb_bm_reg_cooldown_until' );
				$unlock();
				return;
			}

			// Non-prod sites: skip unless explicitly allowed
			[ $nonprod, $why ] = method_exists( __CLASS__, 'detect_nonprod' ) ? self::detect_nonprod() : [ false, '' ];
			if ( $nonprod && ! YOGB_BM_ALLOW_NONPROD ) {
				$unlock();
				return;
			}

			$server_pub = trailingslashit( self::SERVER_BASE ) . 'public/v1';
			$site_url   = home_url( '/' );
			$host       = wp_parse_url( $site_url, PHP_URL_HOST );
			$domain     = is_string( $host ) ? strtolower( $host ) : '';
			$email      = get_option( 'admin_email' );

			if ( empty( $domain ) || empty( $email ) ) {
				if ( ! $quiet ) {
					self::admin_notice_once(
						'yogb_bm_reg_missing',
						'Registration skipped: missing site domain or admin email.'
					);
				}
				$unlock();
				return;
			}

			// STEP 1: /register/start
			$start = wp_safe_remote_post( $server_pub . '/register/start', [
				'timeout' => 10,
				'headers' => [
					'Content-Type' => 'application/json',
					'User-Agent'   => 'YOGB-BM-Client/registrar',
					'Connection'   => 'close',
				],
				'body'    => wp_json_encode( [
					'site_domain' => $domain,
					'site_url'    => $site_url,
					'owner_email' => $email,
				] ),
				'reject_unsafe_urls' => true,
			] );

			if ( is_wp_error( $start ) ) {
				self::schedule_registration_with_backoff( $quiet, 'start_err', $start->get_error_message() );
				$unlock();
				return;
			}

			$start_code       = (int) wp_remote_retrieve_response_code( $start );
			$start_retry_after = (int) wp_remote_retrieve_header( $start, 'retry-after' );

			if ( $start_code !== 201 ) {
				// If server is rate-limiting us, honor Retry-After and set a cooldown.
				if ( $start_code === 429 ) {
					self::schedule_registration_with_backoff(
						$quiet,
						'start_429',
						'rate_limited',
						$start_retry_after
					);
				} else {
					self::schedule_registration_with_backoff(
						$quiet,
						'start_code_' . $start_code,
						'http_' . $start_code
					);
				}
				$unlock();
				return;
			}

			$data = json_decode( wp_remote_retrieve_body( $start ), true );
			if ( ! is_array($data) || empty($data['challenge_id']) || empty($data['challenge_token']) ) {
				self::schedule_registration_with_backoff( $quiet, 'start_bad_payload', 'missing challenge' );
				$unlock();
				return;
			}

			$challenge_id = sanitize_text_field( $data['challenge_id'] );
			$token        = sanitize_text_field( $data['challenge_token'] );

			// Expose token locally (server will fetch it from the canonical REST route)
			set_transient( self::TRANSIENT_PREFIX . $challenge_id, $token, 15 * MINUTE_IN_SECONDS );

			// STEP 2: /register/verify  — send ONLY the challenge_id
			$verify = wp_safe_remote_post( $server_pub . '/register/verify', [
				'timeout' => 10,
				'headers' => [
					'Content-Type' => 'application/json',
					'User-Agent'   => 'YOGB-BM-Client/registrar',
					'Connection'   => 'close',
				],
				'body'    => wp_json_encode( [
					'challenge_id' => $challenge_id,
				] ),
				'reject_unsafe_urls' => true,
			] );

			if ( is_wp_error( $verify ) ) {
				self::schedule_registration_with_backoff( $quiet, 'verify_err', $verify->get_error_message() );
				$unlock();
				return;
			}

			$verify_code        = (int) wp_remote_retrieve_response_code( $verify );
			$verify_retry_after = (int) wp_remote_retrieve_header( $verify, 'retry-after' );

			if ( $verify_code !== 200 ) {
				if ( $verify_code === 429 ) {
					self::schedule_registration_with_backoff(
						$quiet,
						'verify_429',
						'rate_limited',
						$verify_retry_after
					);
				} else {
					self::schedule_registration_with_backoff(
						$quiet,
						'verify_code_' . $verify_code,
						'http_' . $verify_code
					);
				}
				$unlock();
				return;
			}

			$res = json_decode( wp_remote_retrieve_body( $verify ), true );
			if ( ! is_array($res) || empty($res['api_key']) || empty($res['api_secret']) ) {
				self::schedule_registration_with_backoff( $quiet, 'verify_bad_payload', 'missing keys' );
				$unlock();
				return;
			}

			// Success — store keys and clear challenge token
			update_option( self::OPT_API_KEY,    sanitize_text_field( $res['api_key'] ),   false );
			update_option( self::OPT_API_SECRET, sanitize_text_field( $res['api_secret'] ), false );
			if ( ! empty( $res['reporter_id'] ) ) {
				update_option( self::OPT_REPORTER_ID, (int) $res['reporter_id'], false );
			}

			delete_transient( self::TRANSIENT_PREFIX . $challenge_id );
			delete_option( 'yogb_bm_reg_backoff' );
			delete_option( 'yogb_bm_reg_cooldown_until' );
			delete_option( 'yogb_bm_reg_attempts' );

			if ( ! $quiet ) {
				self::admin_notice_once(
					'yogb_bm_reg_ok',
					'Blacklist Manager registered with the global server successfully.'
				);
			}

		} finally {
			$unlock();
		}
	}

	/** Exponential backoff + jitter when registration fails. */
	private static function schedule_registration_with_backoff(
		bool $quiet,
		string $code,
		string $msg = '',
		int $retry_after = 0
	) : void {
		if ( ! $quiet ) {
			self::admin_notice_once(
				'yogb_bm_reg_retry_' . $code,
				'Registration will retry later (' . esc_html( $code ) . ').'
			);
		}

		// If server told us exactly when to back off, honor that.
		if ( $retry_after > 0 ) {
			$backoff = max( 60, $retry_after );
		} else {
			$backoff = (int) get_option( 'yogb_bm_reg_backoff', 60 ); // start at 60s
			$backoff = max( 60, min( $backoff * 2, 6 * HOUR_IN_SECONDS ) ); // cap at 6h
		}

		update_option( 'yogb_bm_reg_backoff', $backoff, false );

		// Set a hard cooldown until this time – both cron and admin_init respect it.
		$cooldown_until = time() + $backoff;
		update_option( 'yogb_bm_reg_cooldown_until', $cooldown_until, false );

		$when = $cooldown_until + wp_rand( 0, 120 ); // small jitter
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( $when, self::CRON_HOOK );
		}
	}

	public static function handle_manual_retry() {
		// Capability check – adjust if you prefer a different capability.
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'wc-blacklist-manager' ) );
		}

		// Nonce check.
		if ( ! isset( $_GET['yogb_bm_retry_nonce'] ) ) {
			wp_die( esc_html__( 'Missing security token.', 'wc-blacklist-manager' ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['yogb_bm_retry_nonce'] ) ), 'yogb_bm_retry_registration' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wc-blacklist-manager' ) );
		}

		// Clear any cooldown / backoff / attempts / lock so manual retry always runs.
		delete_option( 'yogb_bm_reg_cooldown_until' );
		delete_option( 'yogb_bm_reg_backoff' );
		delete_option( 'yogb_bm_reg_attempts' );
		delete_transient( 'yogb_bm_reg_lock' );

		// Run registration in quiet mode – we’ll show our own notice after redirect.
		self::run_registration( true );

		// Decide if it was successful: check for full credentials.
		$api_key     = get_option( self::OPT_API_KEY );
		$api_secret  = get_option( self::OPT_API_SECRET );
		$reporter_id = get_option( self::OPT_REPORTER_ID );

		$status = ( $api_key && $api_secret && $reporter_id ) ? 'success' : 'error';

		// Redirect back to the settings screen with a result flag.
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			// Fallback – adjust to your exact tab/section if needed.
			$redirect = admin_url( 'admin.php?page=wc-settings' );
		}

		$redirect = add_query_arg( 'yogb_bm_retry', $status, $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function maybe_show_retry_notice() {
		if ( empty( $_GET['yogb_bm_retry'] ) ) {
			return;
		}

		$status = sanitize_text_field( wp_unslash( $_GET['yogb_bm_retry'] ) );

		if ( 'success' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Global Blacklist registration completed successfully.', 'wc-blacklist-manager' )
				. '</p></div>';
		} elseif ( 'error' === $status ) {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Global Blacklist registration did not complete. Please check your site domain, HTTPS configuration, and firewall/REST access, or contact support.', 'wc-blacklist-manager' )
				. '</p></div>';
		}
	}

	/* ---------- helpers ---------- */

	private static function admin_notice_once( $key, $msg ) {
		$flag = 'yogb_bm_notice_' . $key;
		if ( get_transient( $flag ) ) return;
		set_transient( $flag, 1, 60 );
		add_action( 'admin_notices', function() use ( $msg ) {
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $msg ) );
		} );
	}

	/**
	 * Decide if this site is non-production using a weighted heuristic.
	 * Returns [bool $is_nonprod, string $reason_csv].
	 */
	private static function detect_nonprod() : array {
		if ( defined('YOGB_BM_FORCE_PROD') && YOGB_BM_FORCE_PROD ) return [ false, 'forced_prod' ];
		if ( defined('YOGB_BM_FORCE_NONPROD') && YOGB_BM_FORCE_NONPROD ) return [ true, 'forced_nonprod' ];

		$cache_key = 'yogb_bm_nonprod_decision';
		$cached = get_transient( $cache_key );
		if ( is_array($cached) && isset($cached['is_nonprod']) ) {
			return [ (bool)$cached['is_nonprod'], (string)$cached['reasons'] ];
		}

		$reasons  = [];
		$score    = 0.0;
		$strong   = false;

		$site_url = home_url( '/' );
		$host     = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$path     = strtolower( (string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?? '/' ) );

		$weights = apply_filters( 'yogb_bm_nonprod_weights', [
			'env_nonprod'      => 2.5,
			'localhost_or_ip'  => 3.0,
			'reserved_tld'     => 3.0,
			'provider_host'    => 2.0,
			'host_keyword'     => 1.5,
			'path_keyword'     => 1.2,
			'disallow_index'   => 0.6,
			'blog_public_0'    => 0.6,
			'wp_debug'         => 0.4,
			'threshold'        => 2.5,
		] );

		if ( function_exists( 'wp_get_environment_type' ) ) {
			$env = wp_get_environment_type();
			if ( $env && $env !== 'production' ) { $score += $weights['env_nonprod']; $strong = true; $reasons[] = 'env='.$env; }
		}

		$dis_idx  = ( defined('DISALLOW_INDEXING') && DISALLOW_INDEXING );
		$blog_pub = (string) get_option('blog_public','1');
		if ( $dis_idx ) { $score += $weights['disallow_index']; $reasons[] = 'DISALLOW_INDEXING'; }
		if ( $blog_pub === '0' ) { $score += $weights['blog_public_0']; $reasons[] = 'blog_public=0'; }

		$is_ip = (bool) filter_var($host, FILTER_VALIDATE_IP);
		if ( $is_ip || $host === 'localhost' ) { $score += $weights['localhost_or_ip']; $strong = true; $reasons[] = 'host='.$host; }

		foreach ( ['.test','.local','.example','.invalid','.internal'] as $tld ) {
			if ( str_ends_with($host, $tld) ) { $score += $weights['reserved_tld']; $strong = true; $reasons[] = 'tld='.$tld; break; }
		}

		foreach ( ['kinsta.cloud','wpenginepowered.com','flywheelsites.com','pantheonsite.io','cloudwaysapps.com'] as $pat ) {
			if ( str_ends_with($host, $pat) ) { $score += $weights['provider_host']; $reasons[] = 'provider='.$pat; break; }
		}

		$kw_regex = '/(^|\.)(staging|stage|dev|develop|development|test|testing|preview|sandbox|qa|demo|beta|alpha|preprod|pre-prod)(\.|-)/i';
		if ( preg_match($kw_regex, $host) || str_contains($host, '-dev') ) { $score += $weights['host_keyword']; $reasons[] = 'host_kw'; }

		$path_kws = ['staging','stage','preview','sandbox','qa','demo','beta','alpha','preprod','pre-prod','testing'];
		foreach ( $path_kws as $kw ) {
			if ( preg_match('#/(?:'.$kw.')(?:/|$)#i', $path) ) { $score += $weights['path_keyword']; $reasons[] = 'path_kw='.$kw; break; }
		}

		if ( defined('WP_DEBUG') && WP_DEBUG ) { $score += $weights['wp_debug']; $reasons[] = 'WP_DEBUG=1'; }

		$is_nonprod = ( $strong || $score >= (float) $weights['threshold'] );
		$is_nonprod_filtered = (bool) apply_filters( 'yogb_bm_is_nonprod', $is_nonprod, [
			'host'    => $host,
			'path'    => $path,
			'site_url'=> $site_url,
			'score'   => $score,
			'strong'  => $strong ? 1 : 0,
			'reasons' => $reasons,
			'weights' => $weights,
		] );

		$out = [ $is_nonprod_filtered, $is_nonprod_filtered ? implode(',', $reasons) : '' ];
		set_transient( $cache_key, [ 'is_nonprod'=>$out[0], 'reasons'=>$out[1], 'score'=>$score ], 5 * MINUTE_IN_SECONDS );
		return $out;
	}
}

YOGB_BM_Registrar::init();
