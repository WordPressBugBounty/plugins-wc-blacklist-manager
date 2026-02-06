<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global blacklist check client.
 *
 * Uses the same identities (email, phone, IP, billing & shipping address)
 * as YOGB_BM_Report uses when reporting, but applies tier-based limits.
 */
final class YOGB_BM_Check {

	/**
	 * Main entry: call global server /check for this order’s identities,
	 * applying package limits (which identity types + how many calls).
	 *
	 * Returns:
	 * [
	 *   'ok'   => bool,
	 *   'code' => int HTTP status,
	 *   'body' => string raw response body,
	 *   'json' => array|null decoded JSON,
	 *   'tier' => string package tier used,
	 * ]
	 */
	public static function check_order( WC_Order $order ) : array {
		if ( ! YOGB_BM_Report::is_ready() ) {
			return [
				'ok'   => false,
				'code' => 0,
				'body' => '',
				'json' => null,
				'tier' => self::get_tier(),
			];
		}

		$tier = self::get_tier();

		// 1) Rate limit per tier (monthly)
		$rl = self::enforce_rate_limit( $tier );
		if ( ! $rl['allowed'] ) {
			return [
				'ok'   => false,
				'code' => 429,
				'body' => '',
				'json' => null,
				'tier' => $tier,
			];
		}

		// 2) Build identities from order (email, phone, ip, billing & shipping address)
		$idents = YOGB_BM_Report::build_identities_from_order( $order );
		if ( empty( $idents ) ) {
			return [
				'ok'   => false,
				'code' => 0,
				'body' => '',
				'json' => null,
				'tier' => $tier,
			];
		}

		// 3) Filter identities by tier (e.g. free does not include address)
		$idents = self::filter_identities_for_tier( $idents, $tier );
		if ( empty( $idents ) ) {
			// Nothing allowed to send for this tier → treat as "no check"
			return [
				'ok'   => false,
				'code' => 0,
				'body' => '',
				'json' => null,
				'tier' => $tier,
			];
		}

		$payload = [ 'identities' => $idents ];

		$res = YOGB_BM_Report::post_json_signed(
			YOGB_BM_Report::REST_ROUTE . '/check',
			$payload
		);

		$json = null;
		if ( ! empty( $res['body'] ) ) {
			$decoded = json_decode( $res['body'], true );
			if ( is_array( $decoded ) ) {
				$json = $decoded;
			}
		}

		return [
			'ok'   => ! empty( $res['ok'] ),
			'code' => $res['code'] ?? 0,
			'body' => $res['body'] ?? '',
			'json' => $json,
			'tier' => $tier,
		];
	}

	/**
	 * Convenience helper: extract overall decision from a /check response.
	 *
	 * Returns 'allow' | 'challenge' | 'block'
	 */
	public static function get_overall_decision( array $resp ) : string {
		if ( empty( $resp['ok'] ) || ! isset( $resp['json'] ) || ! is_array( $resp['json'] ) ) {
			return 'allow';
		}

		$json = $resp['json'];

		if ( isset( $json['decision']['overall'] ) && is_string( $json['decision']['overall'] ) ) {
			return $json['decision']['overall'];
		}

		return 'allow';
	}

	/**
	 * Optional: helper to log or render reasons for a decision.
	 *
	 * Returns an array of human-readable reason strings from the server:
	 * e.g. ["email: high (3 reports, score 25)", "address: medium (1 reports, score 12)"]
	 */
	public static function get_reasons( array $resp ) : array {
		if ( empty( $resp['ok'] ) || ! isset( $resp['json'] ) || ! is_array( $resp['json'] ) ) {
			return [];
		}
		$json = $resp['json'];

		if ( ! empty( $json['decision']['reasons'] ) && is_array( $json['decision']['reasons'] ) ) {
			return array_map( 'strval', $json['decision']['reasons'] );
		}

		return [];
	}

	// ---------------------------------------------------------------------
	// Package tier helpers
	// ---------------------------------------------------------------------

	/**
	 * Get current package tier for this site.
	 *
	 * Source: 'yogb_bm_tier' (synced from server via webhook)
	 *
	 * Default: 'free'
	 */
	public static function get_tier() : string {
		$tier = get_option( 'yogb_bm_tier', 'free' );

		$tier = is_string( $tier ) ? strtolower( trim( $tier ) ) : 'free';

		// Allow: free, basic, pro, enterprise
		if ( ! in_array( $tier, [ 'free', 'basic', 'pro', 'enterprise' ], true ) ) {
			$tier = 'free';
		}

		return $tier;
	}

	/**
	 * Per-tier identity types allowed to be sent to /check.
	 *
	 * free       → email + ip
	 * basic      → email + phone + ip
	 * pro        → email + phone + ip + address
	 * enterprise → email + phone + ip + address (unlimited checks)
	 */
	private static function get_allowed_types_for_tier( string $tier ) : array {
		switch ( $tier ) {
			case 'basic':
				return [ 'email', 'phone', 'ip' ];

			case 'pro':
			case 'enterprise':
				return [ 'email', 'phone', 'ip', 'address' ];

			case 'free':
			default:
				return [ 'email', 'ip' ];
		}
	}

	/**
	 * Filter the identities list according to the current tier.
	 */
	private static function filter_identities_for_tier( array $idents, string $tier ) : array {
		$allowed = self::get_allowed_types_for_tier( $tier );
		$out     = [];

		foreach ( $idents as $ident ) {
			if ( ! is_array( $ident ) ) {
				continue;
			}
			$type = isset( $ident['type'] ) ? strtolower( (string) $ident['type'] ) : '';
			if ( $type && in_array( $type, $allowed, true ) ) {
				$out[] = $ident;
			}
		}

		return $out;
	}

	// ---------------------------------------------------------------------
	// Rate limiting per tier (local client-side)
	// ---------------------------------------------------------------------

	/**
	 * Enforce simple monthly check limits per tier.
	 *
	 * Returns:
	 * [
	 *   'allowed' => bool,
	 *   'reason'  => string|null, // 'rate_month' if exceeded
	 * ]
	 */
	private static function enforce_rate_limit( string $tier ) : array {
		// Monthly limits per tier
		switch ( $tier ) {
			case 'basic':
				$limit = 150;
				break;

			case 'pro':
				$limit = 1000;
				break;

			case 'enterprise':
				// Unlimited checks for Enterprise.
				$limit = 0;
				break;

			case 'free':
			default:
				$limit = 20;
				break;
		}

		// If limit <= 0, treat as unlimited (defensive)
		if ( $limit <= 0 ) {
			return [
				'allowed' => true,
				'reason'  => null,
			];
		}

		// Use UTC calendar month as bucket (YYYYMM)
		$month_key = gmdate( 'Ym' ); // e.g. 202511
		$opt_name  = 'yogb_bm_chk_month_' . $tier . '_' . $month_key;

		$used = (int) get_option( $opt_name, 0 );

		// Already exceeded this month
		if ( $used >= $limit ) {
			return [
				'allowed' => false,
				'reason'  => 'rate_month',
			];
		}

		// Increment and persist (not autoloaded)
		$used++;
		if ( get_option( $opt_name, null ) === null ) {
			add_option( $opt_name, $used, '', false );
		} else {
			update_option( $opt_name, $used, false );
		}

		return [
			'allowed' => true,
			'reason'  => null,
		];
	}

	/**
	 * When tier changes mid-month, migrate the current month counter from old tier to new tier.
	 *
	 * Rules:
	 * - Uses the same UTC month bucket (YYYYMM).
	 * - Ensures the new tier option exists (creates it if missing).
	 * - Never decreases usage: new_used = max(existing_new_used, old_used).
	 *
	 * @param string   $old_tier Previous tier slug (free/basic/pro/enterprise)
	 * @param string   $new_tier New tier slug (free/basic/pro/enterprise)
	 * @param int|null $ts_utc   Optional unix timestamp (UTC) to compute month bucket; defaults to now.
	 */
	public static function migrate_monthly_counter_on_tier_change( string $old_tier, string $new_tier, ?int $ts_utc = null ) : void {
		$old_tier = strtolower( trim( $old_tier ) );
		$new_tier = strtolower( trim( $new_tier ) );

		if ( ! $old_tier || ! $new_tier || $old_tier === $new_tier ) {
			return;
		}

		$allowed = [ 'free', 'basic', 'pro', 'enterprise' ];
		if ( ! in_array( $old_tier, $allowed, true ) || ! in_array( $new_tier, $allowed, true ) ) {
			return;
		}

		$ts_utc = is_int( $ts_utc ) && $ts_utc > 0 ? $ts_utc : time();

		// Current UTC calendar month bucket
		$month_key = gmdate( 'Ym', $ts_utc );

		$old_opt = 'yogb_bm_chk_month_' . $old_tier . '_' . $month_key;
		$new_opt = 'yogb_bm_chk_month_' . $new_tier . '_' . $month_key;

		$old_used = (int) get_option( $old_opt, 0 );
		$new_used = (int) get_option( $new_opt, 0 );

		// Carry over, but never reduce if already higher.
		$target = max( $new_used, $old_used );

		// Ensure the new option exists and is not autoloaded.
		if ( get_option( $new_opt, null ) === null ) {
			add_option( $new_opt, $target, '', false );
		} else {
			update_option( $new_opt, $target, false );
		}
	}
}
