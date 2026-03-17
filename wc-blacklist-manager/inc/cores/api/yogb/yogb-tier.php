<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YOGB_BM_Tier_Webhook {

	const OPTION_TIER              = 'yogb_bm_tier';
	const OPTION_TIER_VERSION      = 'yogb_bm_tier_version';
	const OPTION_TIER_UPDATED_AT   = 'yogb_bm_tier_updated_at';
	const OPTION_TIER_LAST_EVENT   = 'yogb_bm_tier_last_event_id';
	const OPTION_TIER_LAST_SOURCE  = 'yogb_bm_tier_last_source';

	/**
	 * Small rolling map of processed event IDs:
	 * [
	 *   'event_id_1' => 1732600000,
	 *   'event_id_2' => 1732600100,
	 * ]
	 */
	const OPTION_EVENT_CACHE       = 'yogb_bm_tier_event_cache';

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
		$secret = (string) get_option( YOGB_BM_Report::OPT_SECRET );
		if ( '' === $secret ) {
			return new WP_REST_Response(
				[ 'error' => 'not_configured' ],
				503
			);
		}

		$event_header = (string) $req->get_header( 'x-yogb-event' );
		$ts_header    = (string) $req->get_header( 'x-yogb-timestamp' );
		$sig_header   = (string) $req->get_header( 'x-yogb-signature' );
		$id_header    = (string) $req->get_header( 'x-yogb-id' );

		if ( '' === $event_header || '' === $ts_header || '' === $sig_header ) {
			return new WP_REST_Response(
				[ 'error' => 'missing_signature_headers' ],
				401
			);
		}

		// Keep a replay window, but now server retries must re-sign with a fresh timestamp.
		$now = time();
		$ts  = (int) $ts_header;
		if ( $ts <= 0 || abs( $now - $ts ) > 900 ) {
			return new WP_REST_Response(
				[ 'error' => 'stale_request' ],
				400
			);
		}

		$body_raw = (string) $req->get_body();
		if ( '' === $body_raw ) {
			return new WP_REST_Response(
				[ 'error' => 'empty_body' ],
				400
			);
		}

		$expected = base64_encode(
			hash_hmac( 'sha256', $body_raw . "\n" . $ts_header, $secret, true )
		);

		if ( ! hash_equals( $expected, $sig_header ) ) {
			return new WP_REST_Response(
				[ 'error' => 'bad_signature' ],
				401
			);
		}

		$payload = json_decode( $body_raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				[ 'error' => 'invalid_json' ],
				400
			);
		}

		$event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';
		if ( $event !== $event_header ) {
			return new WP_REST_Response(
				[ 'error' => 'event_mismatch' ],
				422
			);
		}

		if ( ! in_array( $event, [ 'tier.updated', 'tier.snapshot' ], true ) ) {
			return new WP_REST_Response(
				[ 'error' => 'unsupported_event' ],
				422
			);
		}

		$result = self::apply_tier_payload(
			$payload,
			[
				'source'          => ( 'tier.snapshot' === $event ) ? 'pull' : 'webhook',
				'header_event_id' => $id_header,
			]
		);

		$code = isset( $result['code'] ) ? (int) $result['code'] : 200;

		return new WP_REST_Response( $result, $code );
	}

	/**
	 * Shared applier used by webhook and pull fallback.
	 */
	public static function apply_tier_payload( array $payload, array $context = [] ) : array {
		$event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';
		$tier  = isset( $payload['tier'] ) ? strtolower( sanitize_text_field( (string) $payload['tier'] ) ) : '';

		$allowed_tiers = [ 'free', 'basic', 'pro', 'enterprise' ];
		if ( ! in_array( $tier, $allowed_tiers, true ) ) {
			return [
				'ok'    => false,
				'error' => 'invalid_tier',
				'code'  => 422,
			];
		}

		$event_id = '';
		if ( ! empty( $payload['event_id'] ) ) {
			$event_id = sanitize_text_field( (string) $payload['event_id'] );
		} elseif ( ! empty( $context['header_event_id'] ) ) {
			$event_id = sanitize_text_field( (string) $context['header_event_id'] );
		}

		$tier_version = isset( $payload['tier_version'] ) ? (int) $payload['tier_version'] : 0;
		if ( $tier_version <= 0 ) {
			return [
				'ok'    => false,
				'error' => 'missing_tier_version',
				'code'  => 422,
			];
		}

		// Soft domain sanity check with normalization.
		$their_domain = isset( $payload['site_domain'] ) ? self::normalize_host( (string) $payload['site_domain'] ) : '';
		$local_host   = self::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' !== $their_domain && '' !== $local_host && $their_domain !== $local_host ) {
			// Soft reject is often safer here. If you want hard reject, change this block.
			return [
				'ok'           => false,
				'error'        => 'domain_mismatch',
				'their_domain' => $their_domain,
				'local_domain' => $local_host,
				'code'         => 409,
			];
		}

		$local_version = (int) get_option( self::OPTION_TIER_VERSION, 0 );
		$local_tier    = (string) get_option( self::OPTION_TIER, 'free' );
		$local_tier    = strtolower( trim( $local_tier ) );

		// Idempotency: exact event already processed.
		if ( '' !== $event_id && self::has_processed_event( $event_id ) ) {
			return [
				'ok'           => true,
				'status'       => 'duplicate_ignored',
				'tier'         => $local_tier,
				'tier_version' => $local_version,
				'event_id'     => $event_id,
				'code'         => 200,
			];
		}

		// Ordering: never let an older event overwrite a newer one.
		if ( $tier_version < $local_version ) {
			return [
				'ok'                  => true,
				'status'              => 'stale_version_ignored',
				'incoming_tier'       => $tier,
				'incoming_version'    => $tier_version,
				'current_tier'        => $local_tier,
				'current_version'     => $local_version,
				'event_id'            => $event_id,
				'code'                => 200,
			];
		}

		// Same version, but different event replay / retry: accept only if state matches.
		if ( $tier_version === $local_version ) {
			if ( $tier === $local_tier ) {
				if ( '' !== $event_id ) {
					self::mark_event_processed( $event_id );
				}

				return [
					'ok'           => true,
					'status'       => 'same_version_same_state',
					'tier'         => $local_tier,
					'tier_version' => $local_version,
					'event_id'     => $event_id,
					'code'         => 200,
				];
			}

			return [
				'ok'               => false,
				'error'            => 'same_version_conflict',
				'incoming_tier'    => $tier,
				'current_tier'     => $local_tier,
				'tier_version'     => $tier_version,
				'code'             => 409,
			];
		}

		// Newer version: apply it.
		update_option( self::OPTION_TIER, $tier, false );
		update_option( self::OPTION_TIER_VERSION, $tier_version, false );
		update_option( self::OPTION_TIER_UPDATED_AT, current_time( 'mysql' ), false );
		update_option( self::OPTION_TIER_LAST_SOURCE, sanitize_text_field( (string) ( $context['source'] ?? 'unknown' ) ), false );

		if ( '' !== $event_id ) {
			update_option( self::OPTION_TIER_LAST_EVENT, $event_id, false );
			self::mark_event_processed( $event_id );
		}

		$ts_payload = isset( $payload['ts'] ) ? (int) $payload['ts'] : 0;

		if ( $local_tier !== $tier ) {
			YOGB_BM_Check::migrate_monthly_counter_on_tier_change( $local_tier, $tier, $ts_payload );
		}

		return [
			'ok'              => true,
			'status'          => 'applied',
			'previous_tier'   => $local_tier,
			'previous_version'=> $local_version,
			'tier'            => $tier,
			'tier_version'    => $tier_version,
			'event_id'        => $event_id,
			'code'            => 200,
		];
	}

	private static function normalize_host( string $host ) : string {
		$host = strtolower( trim( $host ) );
		$host = preg_replace( '/:\d+$/', '', $host ); // strip port
		$host = rtrim( $host, '.' );

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	private static function has_processed_event( string $event_id ) : bool {
		$cache = get_option( self::OPTION_EVENT_CACHE, [] );
		if ( ! is_array( $cache ) ) {
			return false;
		}

		return isset( $cache[ $event_id ] );
	}

	private static function mark_event_processed( string $event_id ) : void {
		$cache = get_option( self::OPTION_EVENT_CACHE, [] );
		if ( ! is_array( $cache ) ) {
			$cache = [];
		}

		$cache[ $event_id ] = time();

		// Keep only latest 50 processed event IDs.
		arsort( $cache, SORT_NUMERIC );
		$cache = array_slice( $cache, 0, 50, true );

		update_option( self::OPTION_EVENT_CACHE, $cache, false );
	}
}

YOGB_BM_Tier_Webhook::init();