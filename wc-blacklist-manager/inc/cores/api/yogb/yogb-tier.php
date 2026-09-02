<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YOGB_BM_Tier_Webhook {
	const OPTION_TIER                 = 'yogb_bm_tier';
	const OPTION_TIER_VERSION         = 'yogb_bm_tier_version';
	const OPTION_TIER_UPDATED_AT      = 'yogb_bm_tier_updated_at';
	const OPTION_TIER_LAST_EVENT      = 'yogb_bm_tier_last_event_id';
	const OPTION_TIER_LAST_SOURCE     = 'yogb_bm_tier_last_source';
	const OPTION_PLAN_SUMMARY         = 'yogb_bm_plan_summary';
	const OPTION_REPORTER_STATUS      = 'yogb_bm_reporter_status';
	const OPTION_CONTRIBUTION_SUMMARY = 'yogb_bm_contribution_summary';
	const OPTION_EVENT_CACHE          = 'yogb_bm_tier_event_cache';
	const OPTION_APPLY_MUTEX          = 'yogb_bm_control_apply_mutex_v1';
	const APPLY_MUTEX_TTL             = 30;
	const APPLY_MUTEX_PULL_ATTEMPTS   = 5;
	const APPLY_MUTEX_RETRY_USEC      = 25000;
	const APPLY_MUTEX_RENEW_WINDOW    = 5;

	private static $active_apply_mutex = null;
	private static $apply_guard_filter = null;
	private static $apply_guard_action = null;
	private static $apply_guard_query  = null;

	public static function init() : void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
	}

	public static function register_route() : void {
		register_rest_route(
			'blacklist/v1',
			'/tier-webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function handle( WP_REST_Request $req ) {
		$credential_snapshot = class_exists( 'YOGB_BM_Registrar' )
			? YOGB_BM_Registrar::committed_credential_snapshot( true )
			: [];
		if ( class_exists( 'YOGB_BM_Registrar' ) && empty( $credential_snapshot['ok'] ) ) {
			$error = 'not_configured' === (string) ( $credential_snapshot['status'] ?? '' ) ? 'not_configured' : 'auth_paused';
			return new WP_REST_Response( [ 'error' => $error ], 503 );
		}
		$secret = ! empty( $credential_snapshot )
			? (string) $credential_snapshot['api_secret']
			: (string) get_option( YOGB_BM_Report::OPT_SECRET );
		if ( '' === $secret ) {
			return new WP_REST_Response( [ 'error' => 'not_configured' ], 503 );
		}

		$event_header = (string) $req->get_header( 'x-yogb-event' );
		$ts_header    = (string) $req->get_header( 'x-yogb-timestamp' );
		$sig_header   = (string) $req->get_header( 'x-yogb-signature' );
		$id_header    = (string) $req->get_header( 'x-yogb-id' );
		if ( '' === $event_header || '' === $ts_header || '' === $sig_header ) {
			return new WP_REST_Response( [ 'error' => 'missing_signature_headers' ], 401 );
		}
		$ts = (int) $ts_header;
		if ( $ts <= 0 || abs( time() - $ts ) > 900 ) {
			return new WP_REST_Response( [ 'error' => 'stale_request' ], 400 );
		}

		$body_raw = (string) $req->get_body();
		if ( '' === $body_raw ) {
			return new WP_REST_Response( [ 'error' => 'empty_body' ], 400 );
		}
		$expected = base64_encode( hash_hmac( 'sha256', $body_raw . "\n" . $ts_header, $secret, true ) );
		if ( ! hash_equals( $expected, $sig_header ) ) {
			return new WP_REST_Response( [ 'error' => 'bad_signature' ], 401 );
		}

		$payload = json_decode( $body_raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( [ 'error' => 'invalid_json' ], 400 );
		}
		$event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';
		if ( $event !== $event_header ) {
			return new WP_REST_Response( [ 'error' => 'event_mismatch' ], 422 );
		}
		if ( ! in_array( $event, [ 'tier.updated', 'tier.snapshot' ], true ) ) {
			return new WP_REST_Response( [ 'error' => 'unsupported_event' ], 422 );
		}
		if ( class_exists( 'YOGB_BM_Registrar' ) && ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) ) {
			return new WP_REST_Response( [ 'error' => 'credential_epoch_changed' ], 409 );
		}
		if ( class_exists( 'YOGB_BM_Registrar' ) && ! YOGB_BM_Registrar::validate_control_authority( $payload ) ) {
			return new WP_REST_Response( [ 'error' => 'reporter_mismatch' ], 409 );
		}

		$result = self::apply_tier_payload(
			$payload,
			[
				// Event names are compatible envelopes, not transport provenance.
				// Only the authenticated GET path may opt into pull repair semantics.
				'source'                    => 'webhook',
				'header_event_id'           => $id_header,
				'bind_verified_auth_control' => true,
				'credential_fingerprint'     => (string) ( $credential_snapshot['credential_fingerprint'] ?? '' ),
				'credential_snapshot'        => $credential_snapshot,
			]
		);
		if ( ! empty( $result['repair_required'] ) && class_exists( 'YOGB_BM_Report_V2' ) ) {
			YOGB_BM_Report_V2::schedule_capability_refresh();
		}
		do_action(
			'yogb_bm_control_sync_event',
			'webhook_processed',
			[
				'source' => 'webhook',
				'state'  => sanitize_key( (string) ( $result['status'] ?? $result['error'] ?? 'unknown' ) ),
			]
		);

		return new WP_REST_Response( $result, isset( $result['code'] ) ? (int) $result['code'] : 200 );
	}

	/** Validate every present component before any semantic write, then apply in generation order. */
	public static function apply_tier_payload( array $payload, array $context = [] ) : array {
		$source = sanitize_key( (string) ( $context['source'] ?? 'unknown' ) );
		$source = in_array( $source, [ 'pull', 'webhook' ], true ) ? $source : 'unknown';
		$bind_verified_auth_control = ! empty( $context['bind_verified_auth_control'] ) && class_exists( 'YOGB_BM_Registrar' );
		$credential_snapshot = isset( $context['credential_snapshot'] ) && is_array( $context['credential_snapshot'] )
			? $context['credential_snapshot']
			: [];
		if ( $bind_verified_auth_control && empty( $credential_snapshot ) ) {
			$credential_snapshot = YOGB_BM_Registrar::committed_credential_snapshot( true );
		}
		if ( $bind_verified_auth_control && ( empty( $credential_snapshot['ok'] ) || ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) ) ) {
			return [ 'ok' => false, 'error' => 'credential_epoch_changed', 'code' => 409, 'repair_required' => false ];
		}
		$normalized = self::normalize_payload( $payload, $context );
		if ( empty( $normalized['ok'] ) ) {
			$incoming_version = isset( $payload['tier_version'] ) && is_numeric( $payload['tier_version'] ) ? (int) $payload['tier_version'] : 0;
			$normalized['repair_required'] = $incoming_version > (int) get_option( self::OPTION_TIER_VERSION, 0 );
			return $normalized;
		}

		$their_domain = self::normalize_host( (string) ( $payload['site_domain'] ?? '' ) );
		$local_host   = self::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $their_domain && '' !== $local_host && $their_domain !== $local_host ) {
			return [
				'ok' => false, 'error' => 'domain_mismatch', 'their_domain' => $their_domain,
				'local_domain' => $local_host, 'code' => 409, 'repair_required' => false,
			];
		}

		$event_id       = (string) $normalized['event_id'];
		$tier           = (string) $normalized['tier'];
		$tier_version   = (int) $normalized['tier_version'];
		$components     = (array) $normalized['components'];
		$complete       = (bool) $normalized['complete'];
		$verified_at    = time();
		$state          = self::read_apply_state( true );
		$decision       = self::no_write_decision( $source, $event_id, $tier, $tier_version, $components, $complete, $state, $verified_at );
		if ( null !== $decision && ! $bind_verified_auth_control ) {
			return $decision;
		}

		$mutex = self::acquire_apply_mutex( $source );
		if ( empty( $mutex['ok'] ) ) {
			return self::apply_mutex_error( $source, (string) ( $mutex['state'] ?? 'storage_failed' ) );
		}

		$guard_started = false;
		$failure_state = '';
		$result        = [];
		try {
			// Pre-lock reads never grant write authority. Drop request-local and
			// persistent option caches, then repeat ordering while owning the mutex.
			self::invalidate_apply_state_cache();
			$state    = self::read_apply_state();
			$decision = self::no_write_decision( $source, $event_id, $tier, $tier_version, $components, $complete, $state, $verified_at );
			if ( null !== $decision ) {
				if ( $bind_verified_auth_control ) {
					if ( ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) ) {
						return [ 'ok' => false, 'error' => 'credential_epoch_changed', 'code' => 409, 'repair_required' => false ];
					}
					self::start_apply_mutex_guard( $mutex );
					$guard_started = true;
					self::require_active_apply_mutex();
					YOGB_BM_Registrar::handle_verified_control(
						$payload,
						$decision,
						(string) ( $context['credential_fingerprint'] ?? '' )
					);
				}
				return $decision;
			}

			self::start_apply_mutex_guard( $mutex );
			$guard_started = true;
			self::require_active_apply_mutex();
			if ( $bind_verified_auth_control && ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) ) {
				return [ 'ok' => false, 'error' => 'credential_epoch_changed', 'code' => 409, 'repair_required' => false ];
			}

			$local_version = (int) $state['version'];
			$local_tier    = (string) $state['tier'];
			$changes       = self::apply_components( $tier, $components, $local_tier, $payload, $verified_at );
			if ( $tier_version > $local_version ) {
				update_option( self::OPTION_TIER_VERSION, $tier_version, false );
				self::update_option_if_changed( self::OPTION_TIER_LAST_SOURCE, $source );
				if ( '' !== $event_id ) {
					self::update_option_if_changed( self::OPTION_TIER_LAST_EVENT, $event_id );
					self::mark_event_processed( $event_id );
				}
				$status = 'applied';
			} else {
				$status = empty( $changes ) ? 'same_version_same_state' : 'same_version_repaired_by_pull';
			}

			$result = [
				'ok'               => true,
				'status'           => $status,
				'previous_tier'    => $local_tier,
				'previous_version' => $local_version,
				'tier'             => $tier,
				'tier_version'     => $tier_version,
				'event_id'         => $event_id,
				'complete'         => $complete,
				'changed'          => array_values( $changes ),
				'repair_required'  => ! $complete,
				'code'             => 200,
			];
			if ( $bind_verified_auth_control ) {
				if ( ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) ) {
					return [ 'ok' => false, 'error' => 'credential_epoch_changed', 'code' => 409, 'repair_required' => false ];
				}
				YOGB_BM_Registrar::handle_verified_control(
					$payload,
					$result,
					(string) ( $context['credential_fingerprint'] ?? '' )
				);
			}
		} catch ( RuntimeException $error ) {
			$failure_state = self::apply_mutex_exception_state( $error );
			if ( '' === $failure_state ) {
				throw $error;
			}
		} finally {
			if ( $guard_started ) {
				$mutex = self::stop_apply_mutex_guard( $mutex );
			}
			self::release_apply_mutex( $mutex );
		}
		if ( '' !== $failure_state ) {
			return self::apply_mutex_error( $source, $failure_state );
		}
		return $result;
	}

	/** The auth transition may only consume the reporter state of this exact generation. */
	public static function verified_auth_control_is_current( array $payload, array $result ) : bool {
		$version = isset( $result['tier_version'] ) && is_numeric( $result['tier_version'] )
			? (int) $result['tier_version']
			: ( isset( $payload['tier_version'] ) && is_numeric( $payload['tier_version'] ) ? (int) $payload['tier_version'] : 0 );
		$status = sanitize_key( (string) ( $payload['reporter_status'] ?? '' ) );
		if ( $version <= 0 || ! in_array( $status, [ 'active', 'suspended', 'deleted' ], true ) ) {
			return false;
		}
		$state = self::read_apply_state( true );
		return $version === (int) $state['version'] && $status === (string) $state['reporter_status'];
	}

	public static function plan_summary() : array {
		$summary = get_option( self::OPTION_PLAN_SUMMARY, [] );
		return is_array( $summary ) ? $summary : [];
	}

	private static function read_apply_state( bool $authoritative = false ) : array {
		$names = [
			self::OPTION_TIER,
			self::OPTION_TIER_VERSION,
			self::OPTION_EVENT_CACHE,
			self::OPTION_REPORTER_STATUS,
			self::OPTION_CONTRIBUTION_SUMMARY,
			self::OPTION_PLAN_SUMMARY,
			'yogb_bm_server_capabilities',
			'yogb_bm_verified_capability_snapshot_v1',
		];
		$values = [];
		if ( $authoritative ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", $names ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$values[ (string) $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
			}
		} else {
			foreach ( $names as $name ) {
				$values[ $name ] = get_option( $name, null );
			}
		}
		$plan = isset( $values[ self::OPTION_PLAN_SUMMARY ] ) && is_array( $values[ self::OPTION_PLAN_SUMMARY ] ) ? $values[ self::OPTION_PLAN_SUMMARY ] : [];
		unset( $plan['updated_at'] );
		return [
			'version'             => (int) ( $values[ self::OPTION_TIER_VERSION ] ?? 0 ),
			'tier'                => strtolower( trim( (string) ( $values[ self::OPTION_TIER ] ?? 'free' ) ) ),
			'event_cache'         => isset( $values[ self::OPTION_EVENT_CACHE ] ) && is_array( $values[ self::OPTION_EVENT_CACHE ] ) ? $values[ self::OPTION_EVENT_CACHE ] : [],
			'reporter_status'     => sanitize_key( (string) ( $values[ self::OPTION_REPORTER_STATUS ] ?? '' ) ),
			'contribution'        => isset( $values[ self::OPTION_CONTRIBUTION_SUMMARY ] ) && is_array( $values[ self::OPTION_CONTRIBUTION_SUMMARY ] ) ? $values[ self::OPTION_CONTRIBUTION_SUMMARY ] : [],
			'plan'                => $plan,
			'capabilities'        => array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $values['yogb_bm_server_capabilities'] ?? [] ) ) ) ) ),
			'capability_snapshot' => isset( $values['yogb_bm_verified_capability_snapshot_v1'] ) && is_array( $values['yogb_bm_verified_capability_snapshot_v1'] ) ? $values['yogb_bm_verified_capability_snapshot_v1'] : [],
		];
	}

	private static function no_write_decision( string $source, string $event_id, string $tier, int $tier_version, array $components, bool $complete, array $state, int $verified_at ) : ?array {
		$local_version = (int) ( $state['version'] ?? 0 );
		$local_tier    = (string) ( $state['tier'] ?? 'free' );
		if ( 'webhook' === $source && '' !== $event_id && isset( $state['event_cache'][ $event_id ] ) ) {
			return self::ordered_no_write( 'duplicate_ignored', $local_tier, $local_version, $event_id );
		}
		if ( $tier_version < $local_version ) {
			return self::ordered_no_write( 'stale_version_ignored', $local_tier, $local_version, $event_id );
		}
		if ( $tier_version !== $local_version ) {
			return null;
		}

		$matches = self::present_state_matches( $tier, $components, $local_tier, $state );
		if ( 'pull' !== $source ) {
			if ( ! $matches ) {
				return [
					'ok' => false, 'error' => 'same_version_conflict', 'tier_version' => $tier_version,
					'code' => 409, 'repair_required' => true,
				];
			}
			return self::ordered_no_write( 'same_version_same_state', $local_tier, $local_version, $event_id, ! $complete );
		}

		if ( $matches && ! self::capability_refresh_needed( $components, $verified_at, $state ) ) {
			return self::ordered_no_write( 'same_version_same_state', $local_tier, $local_version, $event_id, ! $complete );
		}
		return null;
	}

	private static function capability_refresh_needed( array $components, int $verified_at, array $state ) : bool {
		if ( ! isset( $components['capabilities'] ) || ! class_exists( 'YOGB_BM_Report_V2' ) ) {
			return false;
		}
		$incoming = $components['capabilities'];
		sort( $incoming );
		$snapshot = (array) ( $state['capability_snapshot'] ?? [] );
		if ( ! is_array( $snapshot ) || (int) ( $snapshot['verified_at'] ?? 0 ) !== $verified_at ) {
			return true;
		}
		$current = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $snapshot['capabilities'] ?? [] ) ) ) ) );
		sort( $current );
		return $current !== $incoming;
	}

	private static function invalidate_apply_state_cache() : void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$options = [
			self::OPTION_TIER,
			self::OPTION_TIER_VERSION,
			self::OPTION_TIER_UPDATED_AT,
			self::OPTION_TIER_LAST_EVENT,
			self::OPTION_TIER_LAST_SOURCE,
			self::OPTION_PLAN_SUMMARY,
			self::OPTION_REPORTER_STATUS,
			self::OPTION_CONTRIBUTION_SUMMARY,
			self::OPTION_EVENT_CACHE,
			'yogb_bm_server_capabilities',
			'yogb_bm_verified_capability_snapshot_v1',
			'yogb_bm_subscription_activation_status',
			'yogb_bm_subscription_key_last4',
			'wc_blacklist_enable_global_blacklist',
		];
		foreach ( $options as $option ) {
			wp_cache_delete( $option, 'options' );
		}
	}

	private static function acquire_apply_mutex( string $source ) : array {
		global $wpdb;
		$token    = wp_generate_password( 32, false, false );
		$attempts = 'pull' === $source ? self::APPLY_MUTEX_PULL_ATTEMPTS : 1;
		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			$expires_at = time() + self::APPLY_MUTEX_TTL;
			$value      = wp_json_encode( [ 'token' => $token, 'expires_at' => $expires_at ] );
			if ( ! is_string( $value ) || '' === $value ) {
				return [ 'ok' => false, 'state' => 'storage_failed' ];
			}
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",
					self::OPTION_APPLY_MUTEX,
					$value
				)
			);
			if ( 1 === (int) $inserted ) {
				self::invalidate_apply_mutex_cache();
				do_action( 'yogb_bm_control_sync_event', 'apply_mutex_acquired', [ 'source' => $source, 'state' => 'new' ] );
				return [ 'ok' => true, 'token' => $token, 'value' => $value, 'expires_at' => $expires_at ];
			}
			if ( false === $inserted ) {
				return [ 'ok' => false, 'state' => 'storage_failed' ];
			}

			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", self::OPTION_APPLY_MUTEX ) );
			if ( null === $stored ) {
				if ( '' !== (string) $wpdb->last_error ) {
					return [ 'ok' => false, 'state' => 'storage_failed' ];
				}
			} else {
				$decoded = json_decode( (string) $stored, true );
				if ( ! is_array( $decoded ) || empty( $decoded['token'] ) || ! is_string( $decoded['token'] ) || ! isset( $decoded['expires_at'] ) || ! is_numeric( $decoded['expires_at'] ) ) {
					return [ 'ok' => false, 'state' => 'storage_failed' ];
				}
				if ( (int) $decoded['expires_at'] <= time() ) {
					$replaced = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->options} SET option_value=%s,autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s",
							$value,
							self::OPTION_APPLY_MUTEX,
							(string) $stored
						)
					);
					if ( 1 === (int) $replaced ) {
						self::invalidate_apply_mutex_cache();
						do_action( 'yogb_bm_control_sync_event', 'apply_mutex_acquired', [ 'source' => $source, 'state' => 'stale_takeover' ] );
						return [ 'ok' => true, 'token' => $token, 'value' => $value, 'expires_at' => $expires_at ];
					}
					if ( false === $replaced ) {
						return [ 'ok' => false, 'state' => 'storage_failed' ];
					}
				}
			}

			if ( $attempt + 1 < $attempts && 'pull' === $source ) {
				usleep( self::APPLY_MUTEX_RETRY_USEC );
			}
		}
		return [ 'ok' => false, 'state' => 'busy' ];
	}

	private static function start_apply_mutex_guard( array $mutex ) : void {
		self::$active_apply_mutex = $mutex;
		self::$apply_guard_filter = static function( $value ) {
			self::require_active_apply_mutex();
			return $value;
		};
		self::$apply_guard_action = static function() : void {
			self::require_active_apply_mutex();
		};
		self::$apply_guard_query = static function( $query ) {
			if ( is_string( $query ) && false === strpos( $query, self::OPTION_APPLY_MUTEX ) && preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) {
				self::require_active_apply_mutex();
			}
			return $query;
		};
		add_filter( 'pre_update_option', self::$apply_guard_filter, PHP_INT_MAX, 3 );
		add_filter( 'query', self::$apply_guard_query, PHP_INT_MAX, 1 );
		add_action( 'update_option', self::$apply_guard_action, PHP_INT_MAX, 3 );
		add_action( 'add_option', self::$apply_guard_action, PHP_INT_MAX, 2 );
		add_action( 'delete_option', self::$apply_guard_action, PHP_INT_MAX, 1 );
	}

	private static function stop_apply_mutex_guard( array $fallback ) : array {
		if ( null !== self::$apply_guard_filter ) {
			remove_filter( 'pre_update_option', self::$apply_guard_filter, PHP_INT_MAX );
		}
		if ( null !== self::$apply_guard_action ) {
			remove_action( 'update_option', self::$apply_guard_action, PHP_INT_MAX );
			remove_action( 'add_option', self::$apply_guard_action, PHP_INT_MAX );
			remove_action( 'delete_option', self::$apply_guard_action, PHP_INT_MAX );
		}
		if ( null !== self::$apply_guard_query ) {
			remove_filter( 'query', self::$apply_guard_query, PHP_INT_MAX );
		}
		$mutex = is_array( self::$active_apply_mutex ) ? self::$active_apply_mutex : $fallback;
		self::$active_apply_mutex = null;
		self::$apply_guard_filter = null;
		self::$apply_guard_action = null;
		self::$apply_guard_query  = null;
		return $mutex;
	}

	private static function require_active_apply_mutex() : void {
		global $wpdb;
		$mutex = self::$active_apply_mutex;
		if ( ! is_array( $mutex ) || empty( $mutex['value'] ) || empty( $mutex['token'] ) || ! isset( $mutex['expires_at'] ) ) {
			throw new RuntimeException( 'yogb_control_apply_mutex_storage_failed' );
		}
		if ( (int) $mutex['expires_at'] > time() + self::APPLY_MUTEX_RENEW_WINDOW ) {
			return;
		}

		$expires_at = time() + self::APPLY_MUTEX_TTL;
		$value      = wp_json_encode( [ 'token' => (string) $mutex['token'], 'expires_at' => $expires_at ] );
		if ( ! is_string( $value ) || '' === $value ) {
			throw new RuntimeException( 'yogb_control_apply_mutex_storage_failed' );
		}
		$renewed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value=%s,autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s",
				$value,
				self::OPTION_APPLY_MUTEX,
				(string) $mutex['value']
			)
		);
		if ( false === $renewed ) {
			throw new RuntimeException( 'yogb_control_apply_mutex_storage_failed' );
		}
		if ( 1 !== (int) $renewed ) {
			throw new RuntimeException( 'yogb_control_apply_mutex_busy' );
		}
		self::$active_apply_mutex['value']      = $value;
		self::$active_apply_mutex['expires_at'] = $expires_at;
		self::invalidate_apply_mutex_cache();
		do_action( 'yogb_bm_control_sync_event', 'apply_mutex_renewed', [ 'source' => 'apply', 'state' => 'owned' ] );
	}

	private static function apply_mutex_exception_state( RuntimeException $error ) : string {
		if ( 'yogb_control_apply_mutex_busy' === $error->getMessage() ) {
			return 'busy';
		}
		if ( 'yogb_control_apply_mutex_storage_failed' === $error->getMessage() ) {
			return 'storage_failed';
		}
		return '';
	}

	private static function release_apply_mutex( array $mutex ) : void {
		global $wpdb;
		$value = isset( $mutex['value'] ) && is_string( $mutex['value'] ) ? $mutex['value'] : '';
		if ( '' === $value ) {
			return;
		}
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s",
				self::OPTION_APPLY_MUTEX,
				$value
			)
		);
		self::invalidate_apply_mutex_cache();
		if ( false === $deleted ) {
			do_action( 'yogb_bm_control_sync_event', 'apply_mutex_release_failed', [ 'source' => 'apply', 'state' => 'storage_failed' ] );
		}
	}

	private static function invalidate_apply_mutex_cache() : void {
		wp_cache_delete( self::OPTION_APPLY_MUTEX, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function apply_mutex_error( string $source, string $state ) : array {
		$busy  = 'busy' === $state;
		$error = $busy ? 'control_apply_busy' : 'control_apply_storage_failed';
		do_action( 'yogb_bm_control_sync_event', $busy ? 'apply_mutex_busy' : 'apply_mutex_storage_failed', [ 'source' => $source, 'state' => $busy ? 'busy' : 'storage_failed' ] );
		return [
			'ok' => false, 'error' => $error, 'code' => 503,
			'repair_required' => 'pull' === $source || ! $busy,
		];
	}

	private static function normalize_payload( array $payload, array $context ) : array {
		if ( isset( $payload['event'] ) && ! is_scalar( $payload['event'] ) ) return [ 'ok' => false, 'error' => 'invalid_event', 'code' => 422 ];
		if ( array_key_exists( 'site_domain', $payload ) && ! is_string( $payload['site_domain'] ) ) return [ 'ok' => false, 'error' => 'invalid_site_domain', 'code' => 422 ];
		$tier = isset( $payload['tier'] ) && is_string( $payload['tier'] ) ? strtolower( sanitize_text_field( $payload['tier'] ) ) : '';
		if ( ! in_array( $tier, [ 'free', 'basic', 'pro', 'enterprise' ], true ) ) {
			return [ 'ok' => false, 'error' => 'invalid_tier', 'code' => 422 ];
		}
		$tier_version = isset( $payload['tier_version'] ) && is_int( $payload['tier_version'] ) ? $payload['tier_version'] : 0;
		if ( $tier_version <= 0 ) {
			return [ 'ok' => false, 'error' => 'missing_tier_version', 'code' => 422 ];
		}

		if ( array_key_exists( 'event_id', $payload ) && ! is_string( $payload['event_id'] ) ) return [ 'ok' => false, 'error' => 'invalid_event_id', 'code' => 422 ];
		$body_event_id   = isset( $payload['event_id'] ) ? sanitize_text_field( (string) $payload['event_id'] ) : '';
		$header_event_id = isset( $context['header_event_id'] ) && is_scalar( $context['header_event_id'] ) ? sanitize_text_field( (string) $context['header_event_id'] ) : '';
		if ( strlen( $body_event_id ) > 128 || strlen( $header_event_id ) > 128 ) {
			return [ 'ok' => false, 'error' => 'invalid_event_id', 'code' => 422 ];
		}
		if ( '' !== $body_event_id && '' !== $header_event_id && ! hash_equals( $body_event_id, $header_event_id ) ) {
			return [ 'ok' => false, 'error' => 'event_id_mismatch', 'code' => 422 ];
		}
		$event_id = '' !== $body_event_id ? $body_event_id : $header_event_id;
		$components = [];

		if ( array_key_exists( 'reporter_status', $payload ) ) {
			if ( ! is_string( $payload['reporter_status'] ) ) return [ 'ok' => false, 'error' => 'invalid_reporter_status', 'code' => 422 ];
			$status = sanitize_key( (string) $payload['reporter_status'] );
			if ( ! in_array( $status, [ 'active', 'suspended', 'deleted' ], true ) ) return [ 'ok' => false, 'error' => 'invalid_reporter_status', 'code' => 422 ];
			$components['reporter_status'] = $status;
		}

		if ( array_key_exists( 'contribution', $payload ) ) {
			if ( ! is_array( $payload['contribution'] ) || ! isset( $payload['contribution']['status'] ) || ! is_string( $payload['contribution']['status'] ) || ! array_key_exists( 'contributing', $payload['contribution'] ) || ! is_bool( $payload['contribution']['contributing'] ) ) {
				return [ 'ok' => false, 'error' => 'invalid_contribution', 'code' => 422 ];
			}
			$status = sanitize_key( (string) $payload['contribution']['status'] );
			if ( ! in_array( $status, [ 'trusted', 'probation', 'provisional', 'quarantined' ], true ) ) return [ 'ok' => false, 'error' => 'invalid_contribution', 'code' => 422 ];
			$components['contribution'] = [ 'status' => $status, 'contributing' => $payload['contribution']['contributing'] ];
		}

		if ( array_key_exists( 'capabilities', $payload ) ) {
			if ( ! is_array( $payload['capabilities'] ) || count( $payload['capabilities'] ) > 64 ) return [ 'ok' => false, 'error' => 'invalid_capabilities', 'code' => 422 ];
			$capabilities = [];
			foreach ( $payload['capabilities'] as $capability ) {
				if ( ! is_string( $capability ) || strlen( $capability ) > 64 ) return [ 'ok' => false, 'error' => 'invalid_capabilities', 'code' => 422 ];
				$capability = sanitize_key( $capability );
				if ( '' === $capability ) return [ 'ok' => false, 'error' => 'invalid_capabilities', 'code' => 422 ];
				$capabilities[ $capability ] = true;
			}
			$components['capabilities'] = array_keys( $capabilities );
		}

		if ( array_key_exists( 'plan', $payload ) ) {
			$plan = self::normalize_plan( $payload['plan'] );
			if ( empty( $plan['ok'] ) ) return $plan;
			$components['plan'] = $plan['plan'];
		}

		return [
			'ok' => true, 'tier' => $tier, 'tier_version' => $tier_version, 'event_id' => $event_id,
			'components' => $components,
			'complete' => ! array_diff( [ 'reporter_status', 'contribution', 'capabilities', 'plan' ], array_keys( $components ) ),
		];
	}

	private static function normalize_plan( $plan ) : array {
		if ( ! is_array( $plan ) ) return [ 'ok' => false, 'error' => 'invalid_plan', 'code' => 422 ];
		foreach ( [ 'status', 'type', 'tier' ] as $key ) {
			if ( ! isset( $plan[ $key ] ) || ! is_string( $plan[ $key ] ) ) return [ 'ok' => false, 'error' => 'invalid_plan', 'code' => 422 ];
		}
		$status = isset( $plan['status'] ) ? sanitize_key( (string) $plan['status'] ) : '';
		$type   = isset( $plan['type'] ) ? sanitize_key( (string) $plan['type'] ) : '';
		$tier   = isset( $plan['tier'] ) ? sanitize_key( (string) $plan['tier'] ) : '';
		if ( ! in_array( $status, [ 'active', 'inactive', 'none' ], true ) || ! in_array( $type, [ 'subscription', 'legacy', 'mixed', 'none' ], true ) || ! in_array( $tier, [ 'free', 'basic', 'pro', 'enterprise' ], true ) ) {
			return [ 'ok' => false, 'error' => 'invalid_plan', 'code' => 422 ];
		}
		$counts = [];
		foreach ( [ 'active_entitlements', 'active_subscriptions', 'active_legacy' ] as $key ) {
			$value = $plan[ $key ] ?? null;
			if ( is_bool( $value ) || ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) || (int) $value < 0 || (int) $value > 1000000 ) {
				return [ 'ok' => false, 'error' => 'invalid_plan', 'code' => 422 ];
			}
			$counts[ $key ] = (int) $value;
		}
		if ( ! isset( $plan['subscription_ids'] ) || ! is_array( $plan['subscription_ids'] ) || count( $plan['subscription_ids'] ) > 10 ) {
			return [ 'ok' => false, 'error' => 'invalid_plan', 'code' => 422 ];
		}
		$ids = [];
		foreach ( $plan['subscription_ids'] as $id ) {
			if ( ! is_string( $id ) || strlen( $id ) > 128 ) return [ 'ok' => false, 'error' => 'invalid_plan', 'code' => 422 ];
			$id = sanitize_text_field( $id );
			if ( '' !== $id ) $ids[ $id ] = true;
		}
		$ids = array_keys( $ids );
		sort( $ids );
		return [
			'ok' => true,
			'plan' => [
				'status' => $status, 'type' => $type, 'tier' => $tier,
				'active_entitlements' => $counts['active_entitlements'],
				'active_subscriptions' => $counts['active_subscriptions'],
				'active_legacy' => $counts['active_legacy'],
				'subscription_ids' => $ids,
			],
		];
	}

	private static function present_state_matches( string $tier, array $components, string $local_tier, array $state ) : bool {
		if ( $tier !== $local_tier ) return false;
		if ( isset( $components['reporter_status'] ) && $components['reporter_status'] !== (string) ( $state['reporter_status'] ?? '' ) ) return false;
		if ( isset( $components['contribution'] ) && $components['contribution'] !== (array) ( $state['contribution'] ?? [] ) ) return false;
		if ( isset( $components['plan'] ) && $components['plan'] !== (array) ( $state['plan'] ?? [] ) ) return false;
		if ( isset( $components['capabilities'] ) ) {
			$incoming = $components['capabilities'];
			$current  = (array) ( $state['capabilities'] ?? [] );
			sort( $incoming );
			sort( $current );
			if ( $incoming !== $current ) return false;
		}
		return true;
	}

	private static function apply_components( string $tier, array $components, string $local_tier, array $payload, int $verified_at ) : array {
		$changes = [];
		if ( $tier !== $local_tier ) {
			update_option( self::OPTION_TIER, $tier, false );
			update_option( self::OPTION_TIER_UPDATED_AT, current_time( 'mysql' ), false );
			if ( 'free' !== $tier ) update_option( 'wc_blacklist_enable_global_blacklist', '1', false );
			YOGB_BM_Check::migrate_monthly_counter_on_tier_change( $local_tier, $tier, isset( $payload['ts'] ) ? (int) $payload['ts'] : null );
			$changes[] = 'tier';
		}
		if ( isset( $components['reporter_status'] ) && self::update_option_if_changed( self::OPTION_REPORTER_STATUS, $components['reporter_status'] ) ) $changes[] = 'reporter_status';
		if ( isset( $components['contribution'] ) && self::update_option_if_changed( self::OPTION_CONTRIBUTION_SUMMARY, $components['contribution'] ) ) $changes[] = 'contribution';
		if ( isset( $components['plan'] ) && self::sync_plan_summary( $components['plan'] ) ) $changes[] = 'plan';
		if ( isset( $components['capabilities'] ) ) {
			$current = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_option( 'yogb_bm_server_capabilities', [] ) ) ) ) );
			$incoming = $components['capabilities'];
			sort( $current ); sort( $incoming );
			if ( class_exists( 'YOGB_BM_Report_V2' ) ) {
				YOGB_BM_Report_V2::record_verified_capabilities( $components['capabilities'], $verified_at );
			} else {
				self::update_option_if_changed( 'yogb_bm_server_capabilities', $incoming );
			}
			if ( $current !== $incoming ) $changes[] = 'capabilities';
		}
		return $changes;
	}

	private static function sync_plan_summary( array $plan ) : bool {
		$current = self::stored_plan();
		if ( $current === $plan ) return false;
		$stored = $plan;
		$stored['updated_at'] = time();
		update_option( self::OPTION_PLAN_SUMMARY, $stored, false );
		$has_key = '' !== (string) get_option( 'yogb_bm_subscription_key_last4', '' );
		if ( in_array( $plan['type'], [ 'subscription', 'mixed' ], true ) || $has_key ) {
			self::update_option_if_changed( 'yogb_bm_subscription_activation_status', $plan['active_subscriptions'] > 0 ? 'active' : 'inactive' );
		}
		return true;
	}

	private static function stored_plan() : array {
		$plan = get_option( self::OPTION_PLAN_SUMMARY, [] );
		if ( ! is_array( $plan ) ) return [];
		unset( $plan['updated_at'] );
		return $plan;
	}

	private static function ordered_no_write( string $status, string $tier, int $version, string $event_id, bool $repair_required = false ) : array {
		return [
			'ok' => true, 'status' => $status, 'tier' => $tier, 'tier_version' => $version,
			'event_id' => $event_id, 'repair_required' => $repair_required, 'code' => 200,
		];
	}

	private static function update_option_if_changed( string $name, $value ) : bool {
		if ( get_option( $name, null ) === $value ) return false;
		update_option( $name, $value, false );
		return true;
	}

	private static function normalize_host( string $host ) : string {
		$host = rtrim( strtolower( trim( $host ) ), '.' );
		$host = preg_replace( '/:\d+$/', '', $host );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	private static function mark_event_processed( string $event_id ) : void {
		$cache = get_option( self::OPTION_EVENT_CACHE, [] );
		$cache = is_array( $cache ) ? $cache : [];
		$cache[ $event_id ] = time();
		arsort( $cache, SORT_NUMERIC );
		update_option( self::OPTION_EVENT_CACHE, array_slice( $cache, 0, 50, true ), false );
	}
}

YOGB_BM_Tier_Webhook::init();
