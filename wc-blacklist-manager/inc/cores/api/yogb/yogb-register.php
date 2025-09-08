<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'YOGB_BM_ALLOW_NONPROD' ) ) {
    define( 'YOGB_BM_ALLOW_NONPROD', false );
}

class WC_Blacklist_Manager_Registrar {
	/* New prefixed storage keys */
	const OPT_API_KEY     = 'yogb_bm_api_key';
	const OPT_API_SECRET  = 'yogb_bm_api_secret';
	const OPT_REPORTER_ID = 'yogb_bm_reporter_id';

	/* Server base (unchanged) */
	const SERVER_BASE = 'https://bmc.yoohw.com/wp-json/yoohw-gbl';

	/* New prefixed transient + cron hook */
	const TRANSIENT_PREFIX = 'yogb_bm_challenge_';
	const CRON_HOOK        = 'yogb_bm_run_registration';

	public static function init() {
		// Public proof endpoint
		add_action( 'rest_api_init', function() {
			register_rest_route( 'blacklist/v1', '/challenge/(?P<id>[a-f0-9\-]{32,36})', [
				'methods'  => 'GET',
				'callback' => [ __CLASS__, 'rest_challenge_echo' ],
				'permission_callback' => '__return_true',
			] );
		} );

		// Activation → schedule registration
		register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, [ __CLASS__, 'on_activate' ] );

		// Admin bootstrap: migrate keys and register if needed
		add_action( 'admin_init', function() {
			self::maybe_register_on_admin();
		} );

		// React to your version option changing
		add_action( 'updated_option', function( $option, $old, $new ) {
			if ( $option === 'wc_blacklist_manager_version' && $old !== $new ) {
				self::schedule_registration();
			}
		}, 10, 3 );

		// New cron hook
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_registration' ] );

		// Back-compat: if an old cron is pending, run our worker too
		add_action( 'wc_bm_run_registration', [ __CLASS__, 'run_registration' ] );
	}

	/** Activation */
	public static function on_activate() {
		[ $nonprod, $why ] = self::detect_nonprod();
		if ( $nonprod && ! YOGB_BM_ALLOW_NONPROD ) {
			self::wclog('activate:skip-nonprod', ['why'=>$why]);
			return;
		}
		self::wclog('activate:schedule');
		self::schedule_registration();
	}

	/** Try to register when keys missing or version changed */
	private static function maybe_register_on_admin() {
		[ $nonprod, $why ] = self::detect_nonprod();
		if ( $nonprod && ! YOGB_BM_ALLOW_NONPROD ) {
			self::wclog('admin_init:skip-nonprod', ['why'=>$why]);
			return;
		}

		$have_keys  = get_option( self::OPT_API_KEY ) && get_option( self::OPT_API_SECRET );
		$stored_ver = get_option( 'wc_blacklist_manager_version' );
		$need       = ( ! $have_keys || $stored_ver !== WC_BLACKLIST_MANAGER_VERSION );

		self::wclog('admin_init:check', [
			'have_keys'=>$have_keys?1:0,
			'stored_ver'=>$stored_ver,
			'code_ver'=>WC_BLACKLIST_MANAGER_VERSION,
			'need'=>$need?1:0
		]);

		if ( $need ) {
			self::wclog('admin_init:run+schedule');
			self::run_registration(true);
			self::schedule_registration();
		}
	}

	private static function schedule_registration() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/** Public REST: echo the token as plain text */
	public static function rest_challenge_echo( WP_REST_Request $req ) {
		$id    = sanitize_text_field( (string) $req->get_param( 'id' ) );
		$token = get_transient( self::TRANSIENT_PREFIX . $id );
		self::wclog('rest:challenge', ['id'=>$id, 'has_token'=>$token ? 1 : 0]);
		if ( ! $token ) {
			return new WP_REST_Response( [ 'error' => 'unknown_challenge' ], 404 );
		}
		return new WP_REST_Response( [ 'token' => $token ], 200 );
	}

	/** Main register flow */
	public static function run_registration( $quiet = false ) {
		$rid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('yogb_', true);

		if ( get_option( self::OPT_API_KEY ) && get_option( self::OPT_API_SECRET ) ) {
			self::wclog('run:skip-have-keys', compact('rid'));
			return;
		}

		$server_pub = trailingslashit( self::SERVER_BASE ) . 'public/v1';
		$site_url   = home_url( '/' );
		$host       = wp_parse_url( $site_url, PHP_URL_HOST );
		$domain     = is_string( $host ) ? strtolower( $host ) : '';
		$email      = get_option( 'admin_email' );

		self::wclog('run:begin', compact('rid','domain','email','site_url','server_pub'));

		if ( empty( $domain ) || empty( $email ) ) {
			self::wclog('run:abort-missing', compact('rid','domain','email'));
			if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_missing', 'Registration skipped: missing site domain or admin email.' );
			return;
		}

		// STEP 1: /register/start
		$t0 = microtime(true);
		$start = wp_remote_post( $server_pub . '/register/start', [
			'timeout' => 12,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'site_domain' => $domain,
				'site_url'    => $site_url,
				'owner_email' => $email,
			] ),
		] );
		$dt0 = round((microtime(true)-$t0)*1000);
		self::wclog('http:start', ['rid'=>$rid,'ms'=>$dt0] + self::http_summary($start));

		if ( is_wp_error( $start ) ) { if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_err1', 'Registration failed: ' . esc_html( $start->get_error_message() ) ); return; }
		if ( (int) wp_remote_retrieve_response_code( $start ) !== 201 ) { if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_err1c', 'Registration failed: server rejected start.' ); return; }

		$data = json_decode( wp_remote_retrieve_body( $start ), true );
		self::wclog('start:parsed', ['rid'=>$rid,'keys'=>array_keys((array)$data)]);
		if ( ! is_array($data) || empty($data['challenge_id']) || empty($data['challenge_token']) ) { if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_err1p', 'Registration failed: malformed start response.' ); return; }

		$challenge_id = sanitize_text_field( $data['challenge_id'] );
		$token        = sanitize_text_field( $data['challenge_token'] );

		set_transient( self::TRANSIENT_PREFIX . $challenge_id, $token, 15 * MINUTE_IN_SECONDS );
		self::wclog('transient:set', ['rid'=>$rid,'challenge_id'=>$challenge_id,'ttl_min'=>15]);

		// FIX: use the route you registered above (blacklist/v1)
		$proof_url = trailingslashit( $site_url ) . 'wp-json/blacklist/v1/challenge/' . rawurlencode( $challenge_id );
		self::wclog('proof:url', ['rid'=>$rid,'proof_url'=>$proof_url]);

		// STEP 2: /register/verify
		$t1 = microtime(true);
		$verify = wp_remote_post( $server_pub . '/register/verify', [
			'timeout' => 12,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'challenge_id' => $challenge_id,
				'proof_url'    => $proof_url,
			] ),
		] );
		$dt1 = round((microtime(true)-$t1)*1000);
		self::wclog('http:verify', ['rid'=>$rid,'ms'=>$dt1] + self::http_summary($verify));

		if ( is_wp_error( $verify ) ) { if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_err2', 'Registration verify failed: ' . esc_html( $verify->get_error_message() ) ); return; }
		if ( (int) wp_remote_retrieve_response_code( $verify ) !== 200 ) { if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_err2c', 'Registration verify failed: server rejected proof.' ); return; }

		$res = json_decode( wp_remote_retrieve_body( $verify ), true );
		self::wclog('verify:parsed', ['rid'=>$rid,'keys'=>array_keys((array)$res)]);
		if ( ! is_array($res) || empty($res['api_key']) || empty($res['api_secret']) ) { if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_err2p', 'Registration verify failed: malformed response.' ); return; }

		update_option( self::OPT_API_KEY,    sanitize_text_field( $res['api_key'] ) );
		update_option( self::OPT_API_SECRET, sanitize_text_field( $res['api_secret'] ) );
		if ( ! empty( $res['reporter_id'] ) ) update_option( self::OPT_REPORTER_ID, (int) $res['reporter_id'] );
		self::wclog('saved:credentials', ['rid'=>$rid,'reporter_id'=>$res['reporter_id'] ?? null]);

		delete_transient( self::TRANSIENT_PREFIX . $challenge_id );
		self::wclog('cleanup:transient-deleted', ['rid'=>$rid,'challenge_id'=>$challenge_id]);

		if ( ! $quiet ) self::admin_notice_once( 'yogb_bm_reg_ok', 'Blacklist Manager registered with the global server successfully.' );
	}

	/* ---------- migration + helpers ---------- */

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
		// Fast explicit overrides (admins/devops can set these in wp-config.php)
		if ( defined('YOGB_BM_FORCE_PROD') && YOGB_BM_FORCE_PROD ) {
			self::wclog('nonprod:decision', ['forced'=>'prod']);
			return [ false, 'forced_prod' ];
		}
		if ( defined('YOGB_BM_FORCE_NONPROD') && YOGB_BM_FORCE_NONPROD ) {
			self::wclog('nonprod:decision', ['forced'=>'nonprod']);
			return [ true, 'forced_nonprod' ];
		}

		// (Optional) small cache to avoid recompute on every admin request (5 min)
		$cache_key = 'yogb_bm_nonprod_decision';
		$cached = get_transient( $cache_key );
		if ( is_array($cached) && isset($cached['is_nonprod']) ) {
			self::wclog('nonprod:cache_hit', $cached );
			return [ (bool)$cached['is_nonprod'], (string)$cached['reasons'] ];
		}

		$reasons  = [];
		$signals  = [];     // detailed components for debugging
		$score    = 0.0;    // cumulative
		$strong   = false;  // saw any strong indicator?

		$site_url = home_url( '/' );
		$host     = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$path     = strtolower( (string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?? '/' ) );

		self::wclog('nonprod:begin', [ 'host'=>$host, 'path'=>$path, 'site_url'=>$site_url ]);

		// ------------ weights (can be tuned via filter) ------------
		$weights = [
			'env_nonprod'      => 2.5,  // wp_get_environment_type() != 'production'
			'localhost_or_ip'  => 3.0,  // localhost or bare IP host
			'reserved_tld'     => 3.0,  // .test .local .example .invalid .internal
			'provider_host'    => 2.0,  // wpenginepowered.com, kinsta.cloud, etc.
			'host_keyword'     => 1.5,  // dev/staging/etc in a label or as -dev suffix
			'path_keyword'     => 1.2,  // /staging, /preview, /sandbox ...
			'disallow_index'   => 0.6,  // DISALLOW_INDEXING
			'blog_public_0'    => 0.6,  // Settings → discourage search engines
			'wp_debug'         => 0.4,  // WP_DEBUG = true
			// threshold and strong gate
			'threshold'        => 2.5,  // final decision threshold
		];

		$weights = apply_filters( 'yogb_bm_nonprod_weights', $weights );

		// ------------ signals ------------
		// 1) WP env
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$env = wp_get_environment_type();
			self::wclog('nonprod:env', [ 'env'=>$env ]);
			if ( $env && $env !== 'production' ) {
				$score   += $weights['env_nonprod'];
				$strong   = true; // strong
				$reasons[] = 'env='.$env;
				$signals[] = ['env_nonprod'=>$weights['env_nonprod']];
			}
		}

		// 2) Indexing flags (weak)
		$dis_idx  = ( defined('DISALLOW_INDEXING') && DISALLOW_INDEXING );
		$blog_pub = (string) get_option('blog_public','1');
		self::wclog('nonprod:index', [ 'DISALLOW_INDEXING'=>$dis_idx?1:0, 'blog_public'=>$blog_pub ]);
		if ( $dis_idx ) {
			$score += $weights['disallow_index'];
			$reasons[] = 'DISALLOW_INDEXING';
			$signals[] = ['disallow_index'=>$weights['disallow_index']];
		}
		if ( $blog_pub === '0' ) {
			$score += $weights['blog_public_0'];
			$reasons[] = 'blog_public=0';
			$signals[] = ['blog_public_0'=>$weights['blog_public_0']];
		}

		// 3) Host: localhost or IP (strong)
		$is_ip = (bool) filter_var($host, FILTER_VALIDATE_IP);
		if ( $is_ip || $host === 'localhost' ) {
			$score += $weights['localhost_or_ip'];
			$strong = true;
			$reasons[] = 'host='.$host;
			$signals[] = ['localhost_or_ip'=>$weights['localhost_or_ip']];
		}

		// 4) Reserved-ish TLDs (strong). NOTE: we intentionally do NOT flag .dev TLD (it’s widely used in prod).
		foreach ( ['.test','.local','.example','.invalid','.internal'] as $tld ) {
			if ( str_ends_with($host, $tld) ) {
				$score += $weights['reserved_tld'];
				$strong = true;
				$reasons[] = 'tld='.$tld;
				$signals[] = ['reserved_tld'=>$weights['reserved_tld']];
				break;
			}
		}

		// 5) Managed host staging domains (moderately strong)
		foreach ( ['kinsta.cloud','wpenginepowered.com','flywheelsites.com','pantheonsite.io','cloudwaysapps.com'] as $pat ) {
			if ( str_ends_with($host, $pat) ) {
				$score += $weights['provider_host'];
				$reasons[] = 'provider='.$pat;
				$signals[] = ['provider_host'=>$weights['provider_host']];
				break;
			}
		}

		// 6) Host keywords (balanced). Don’t ding legit .dev TLDs:
		//    flag "dev" only when it’s a subdomain label or a "-dev" suffix in some label.
		$kw_regex = '/(^|\.)(staging|stage|dev|develop|development|test|testing|preview|sandbox|qa|demo|beta|alpha|preprod|pre-prod)(\.|-)/i';
		if ( preg_match($kw_regex, $host) || str_contains($host, '-dev') ) {
			$score += $weights['host_keyword'];
			$reasons[] = 'host_kw';
			$signals[] = ['host_keyword'=>$weights['host_keyword']];
		}

		// 7) Path keywords (helpful for subdirectory-based staging)
		$path_kws = ['staging','stage','preview','sandbox','qa','demo','beta','alpha','preprod','pre-prod','testing'];
		foreach ( $path_kws as $kw ) {
			if ( preg_match('#/(?:'.$kw.')(?:/|$)#i', $path) ) {
				$score += $weights['path_keyword'];
				$reasons[] = 'path_kw='.$kw;
				$signals[] = ['path_keyword'=>$weights['path_keyword']];
				break;
			}
		}

		// 8) Debug (weak)
		if ( defined('WP_DEBUG') && WP_DEBUG ) {
			$score += $weights['wp_debug'];
			$reasons[] = 'WP_DEBUG=1';
			$signals[] = ['wp_debug'=>$weights['wp_debug']];
		}

		// ------------ decision ------------
		$is_nonprod = ( $strong || $score >= (float) $weights['threshold'] );

		$ctx = [
			'host'    => $host,
			'path'    => $path,
			'site_url'=> $site_url,
			'score'   => $score,
			'strong'  => $strong ? 1 : 0,
			'reasons' => $reasons,
			'signals' => $signals,
			'weights' => $weights,
		];

		$is_nonprod_filtered = (bool) apply_filters( 'yogb_bm_is_nonprod', $is_nonprod, $ctx );

		self::wclog('nonprod:decision', [
			'score'    => $score,
			'strong'   => $strong ? 1 : 0,
			'threshold'=> $weights['threshold'],
			'raw'      => $is_nonprod ? 1 : 0,
			'filtered' => $is_nonprod_filtered ? 1 : 0,
			'reasons'  => $reasons,
		]);

		$out = [ $is_nonprod_filtered, $is_nonprod_filtered ? implode(',', $reasons) : '' ];

		// Cache briefly
		set_transient( $cache_key, [ 'is_nonprod'=>$out[0], 'reasons'=>$out[1], 'score'=>$score ], 5 * MINUTE_IN_SECONDS );

		return $out;
	}

	/** ---------- WooCommerce-only logging helpers ---------- */
	private static function scrub($v) {
		if (is_array($v)) {
			$o = [];
			foreach ($v as $k=>$val) {
				$lk = strtolower((string)$k);
				if (in_array($lk, ['challenge_token','api_secret','api_key','authorization','x-signature'], true)) {
					$o[$k] = '(redacted)';
				} else {
					$o[$k] = self::scrub($val);
				}
			}
			return $o;
		}
		if (is_string($v)) {
			$v = preg_replace('/\s+/', ' ', $v);
			return strlen($v) > 400 ? substr($v,0,400).'…' : $v;
		}
		return $v;
	}

	private static function http_summary($resp): array {
		if ( is_wp_error($resp) ) return ['wp_error' => $resp->get_error_message()];
		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$try  = json_decode($body, true);
		return ['code'=>$code, 'body'=> is_array($try) ? self::scrub($try) : self::scrub($body)];
	}

	/** Log to WooCommerce logs only (source: yogb-bm). Enable by defining YOGB_BM_DEBUG=true in wp-config.php */
	private static function wclog(string $msg, array $ctx = []): void {
		if ( ! defined('YOGB_BM_DEBUG') || ! YOGB_BM_DEBUG ) return;
		if ( ! function_exists('wc_get_logger') ) return; // WC not active → no log
		$line = '[YOGB_REG] ' . $msg . ' ' . wp_json_encode( self::scrub($ctx), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE );
		wc_get_logger()->info( $line, [ 'source' => 'yogb-bm' ] );
	}
}

WC_Blacklist_Manager_Registrar::init();
