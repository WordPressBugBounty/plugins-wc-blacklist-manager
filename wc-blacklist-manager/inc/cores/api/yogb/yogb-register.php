<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'YOGB_BM_ALLOW_NONPROD' ) ) {
    define( 'YOGB_BM_ALLOW_NONPROD', false );
}

class WC_Blacklist_Manager_Registrar {
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
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_registration' ] );
		add_action( 'wc_bm_run_registration', [ __CLASS__, 'run_registration' ] ); // back-compat
	}

	/** Activation */
	public static function on_activate() {
		[ $nonprod, $why ] = self::detect_nonprod();
		if ( $nonprod && ! YOGB_BM_ALLOW_NONPROD ) {
			return;
		}
		self::schedule_registration();
	}

	/** Try to register when keys missing or version changed */
	private static function maybe_register_on_admin() {
		[ $nonprod, $why ] = self::detect_nonprod();
		if ( $nonprod && ! YOGB_BM_ALLOW_NONPROD ) {
			return;
		}

		$have_keys  = get_option( self::OPT_API_KEY ) && get_option( self::OPT_API_SECRET );
		$stored_ver = get_option( 'wc_blacklist_manager_version' );
		$need       = ( ! $have_keys || $stored_ver !== WC_BLACKLIST_MANAGER_VERSION );

		if ( $need ) {
			self::run_registration(true);
			self::schedule_registration();
		}
	}

	private static function schedule_registration() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
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
		if ( get_transient('yogb_bm_reg_lock') ) return;
		set_transient('yogb_bm_reg_lock', 1, 60);

		$unlock = function() { delete_transient('yogb_bm_reg_lock'); };

		try {
			// Already registered?
			if ( get_option( self::OPT_API_KEY ) && get_option( self::OPT_API_SECRET ) ) {
				// Clear any backoff on success
				delete_option('yogb_bm_reg_backoff');
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
				if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_missing', 'Registration skipped: missing site domain or admin email.' );
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
			if ( (int) wp_remote_retrieve_response_code( $start ) !== 201 ) {
				self::schedule_registration_with_backoff( $quiet, 'start_code', (string) wp_remote_retrieve_response_code( $start ) );
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
			if ( (int) wp_remote_retrieve_response_code( $verify ) !== 200 ) {
				self::schedule_registration_with_backoff( $quiet, 'verify_code', (string) wp_remote_retrieve_response_code( $verify ) );
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
			if ( ! empty( $res['reporter_id'] ) ) update_option( self::OPT_REPORTER_ID, (int) $res['reporter_id'], false );

			delete_transient( self::TRANSIENT_PREFIX . $challenge_id );
			delete_option( 'yogb_bm_reg_backoff' );

			if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_ok', 'Blacklist Manager registered with the global server successfully.' );

		} finally {
			$unlock();
		}
	}

	/** Exponential backoff + jitter when registration fails. */
	private static function schedule_registration_with_backoff( bool $quiet, string $code, string $msg = '' ) : void {
		if ( ! $quiet ) {
			self::admin_notice_once( 'yogb_bm_reg_retry_' . $code, 'Registration will retry soon (' . esc_html($code) . ').' );
		}
		$backoff = (int) get_option( 'yogb_bm_reg_backoff', 60 ); // start at 60s
		$backoff = max( 60, min( $backoff * 2, 6 * HOUR_IN_SECONDS ) ); // cap at 6h
		update_option( 'yogb_bm_reg_backoff', $backoff, false );

		$when = time() + $backoff + wp_rand( 0, 120 ); // jitter
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( $when, self::CRON_HOOK );
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

WC_Blacklist_Manager_Registrar::init();
