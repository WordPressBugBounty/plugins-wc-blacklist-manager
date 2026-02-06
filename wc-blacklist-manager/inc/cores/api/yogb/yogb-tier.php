<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YOGB_BM_Tier_Webhook {

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

	/**
	 * Handle server webhook:
	 * Body: {
	 *   "event": "tier.updated",
	 *   "tier": "basic",
	 *   "site_domain": "clientdomain.com",
	 *   "reporter_id": 123,
	 *   "ts": 1732600000
	 * }
	 * Headers:
	 *   X-YOGB-Event: tier.updated
	 *   X-YOGB-Timestamp: <unix>
	 *   X-YOGB-Signature: base64(hmac_sha256(body + "\n" + ts, api_secret))
	 */
	public static function handle( WP_REST_Request $req ) {
		$secret = (string) get_option( YOGB_BM_Report::OPT_SECRET );
		if ( ! $secret ) {
			return new WP_REST_Response(
				[ 'error' => 'not_configured' ],
				503
			);
		}

		$event_header = (string) $req->get_header( 'x-yogb-event' );
		$ts_header    = (string) $req->get_header( 'x-yogb-timestamp' );
		$sig_header   = (string) $req->get_header( 'x-yogb-signature' );

		if ( ! $event_header || ! $ts_header || ! $sig_header ) {
			return new WP_REST_Response(
				[ 'error' => 'missing_signature_headers' ],
				401
			);
		}

		// Basic replay window: ±10 minutes
		$now = time();
		$ts  = (int) $ts_header;
		if ( abs( $now - $ts ) > 600 ) {
			return new WP_REST_Response(
				[ 'error' => 'stale_request' ],
				400
			);
		}

		$body_raw = $req->get_body() ?: '';

		$expected = base64_encode(
			hash_hmac( 'sha256', $body_raw . "\n" . $ts_header, $secret, true )
		);

		if ( ! hash_equals( $expected, $sig_header ) ) {
			//if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			//	error_log( '[YOGB-Client] tier-webhook bad signature' );
			//}
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

		if ( ( $payload['event'] ?? '' ) !== 'tier.updated' || $event_header !== 'tier.updated' ) {
			return new WP_REST_Response(
				[ 'error' => 'unsupported_event' ],
				422
			);
		}

		$tier = isset( $payload['tier'] ) ? strtolower( sanitize_text_field( (string) $payload['tier'] ) ) : '';
		$allowed_tiers = [ 'free', 'basic', 'pro', 'enterprise' ];
		if ( ! in_array( $tier, $allowed_tiers, true ) ) {
			return new WP_REST_Response(
				[ 'error' => 'invalid_tier' ],
				422
			);
		}

		// Optional: sanity-check domain
		$their_domain = strtolower( (string) ( $payload['site_domain'] ?? '' ) );
		$local_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $their_domain && $local_host && $their_domain !== $local_host ) {
			// If you prefer "soft" behavior you can log and still accept.
			//if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			//	error_log(
			//		sprintf(
			//			'[YOGB-Client] tier-webhook domain mismatch: server=%s local=%s',
			//			$their_domain,
			//			$local_host
			//		)
			//	);
			//}
			return new WP_REST_Response( [ 'error' => 'domain_mismatch' ], 409 );
		}

		// Persist tier locally
		$old_tier = (string) get_option( 'yogb_bm_tier', 'free' );
		$old_tier = is_string( $old_tier ) ? strtolower( trim( $old_tier ) ) : 'free';

		update_option( 'yogb_bm_tier', $tier, false );

		// Migrate this month usage counter from old tier bucket to new tier bucket.
		// Use the webhook timestamp to compute month bucket consistently.
		$ts_payload = isset( $payload['ts'] ) ? (int) $payload['ts'] : 0;
		YOGB_BM_Check::migrate_monthly_counter_on_tier_change( $old_tier, $tier, $ts_payload );

		//if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		//	error_log( '[YOGB-Client] tier updated to ' . $tier );
		//}

		return new WP_REST_Response(
			[ 'ok' => true, 'tier' => $tier ],
			200
		);
	}
}

// bootstrap
YOGB_BM_Tier_Webhook::init();
