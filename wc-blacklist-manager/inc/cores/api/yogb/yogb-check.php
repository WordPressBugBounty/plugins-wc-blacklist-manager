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

		// 2) Build richer check payload from order.
		$payload = YOGB_BM_Report::build_check_payload_from_order( $order );

		if ( empty( $payload['identities'] ) || ! is_array( $payload['identities'] ) ) {
			return [
				'ok'   => false,
				'code' => 0,
				'body' => '',
				'json' => null,
				'tier' => $tier,
			];
		}

		// 3) Filter identities by tier (e.g. free does not include address)
		$payload['identities'] = self::filter_identities_for_tier( $payload['identities'], $tier );

		if ( empty( $payload['identities'] ) ) {
			return [
				'ok'   => false,
				'code' => 0,
				'body' => '',
				'json' => null,
				'tier' => $tier,
			];
		}

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
	 * Returns an array of human-readable reason strings from the server.
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

	/**
	 * Get normalized per-identity signal rows from a /check response.
	 *
	 * Supports both:
	 * - new server responses with nested "signals"
	 * - older responses with top-level score/risk/report_count fields only
	 *
	 * Returns:
	 * [
	 *   [
	 *     'type'                   => 'email',
	 *     'found'                  => true,
	 *     'report_count'           => 3,
	 *     'direct_score'           => 8.0,
	 *     'linked_boost'           => 1.4,
	 *     'effective_score'        => 9.4,
	 *     'linked_neighbors_count' => 2,
	 *     'risk_level'             => 'high',
	 *     'last_reported'          => '2026-03-17 08:10:00',
	 *   ],
	 * ]
	 */
	public static function get_signal_summary( array $resp ) : array {
		$payload = $resp['json'] ?? null;

		if ( ! is_array( $payload ) && ! empty( $resp['body'] ) && is_string( $resp['body'] ) ) {
			$decoded = json_decode( $resp['body'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		if ( ! is_array( $payload ) || empty( $payload['results'] ) || ! is_array( $payload['results'] ) ) {
			return [];
		}

		$out = [];

		foreach ( $payload['results'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$aggregate = ( isset( $row['aggregate'] ) && is_array( $row['aggregate'] ) ) ? $row['aggregate'] : [];
			$matches   = ( isset( $row['matches'] ) && is_array( $row['matches'] ) ) ? $row['matches'] : [];

			$report_count           = isset( $aggregate['report_count'] ) ? (int) $aggregate['report_count'] : 0;
			$direct_score           = isset( $aggregate['direct_score'] ) ? (float) $aggregate['direct_score'] : 0.0;
			$linked_boost           = isset( $aggregate['linked_boost'] ) ? (float) $aggregate['linked_boost'] : 0.0;
			$effective_score        = isset( $aggregate['score'] ) ? (float) $aggregate['score'] : 0.0;
			$linked_neighbors_count = isset( $aggregate['linked_neighbors_count'] ) ? (int) $aggregate['linked_neighbors_count'] : 0;
			$risk_level             = isset( $aggregate['risk_level'] ) ? (string) $aggregate['risk_level'] : 'low';
			$last_reported          = isset( $aggregate['last_reported'] ) ? $aggregate['last_reported'] : null;

			$match_mode            = isset( $row['match_mode'] ) ? (string) $row['match_mode'] : 'none';
			$matched_variant       = isset( $row['matched_variant'] ) ? (string) $row['matched_variant'] : '';
			$matched_identity_count = isset( $row['matched_identity_count'] ) ? (int) $row['matched_identity_count'] : 0;

			$found = (
				! empty( $matches ) ||
				$report_count > 0 ||
				$direct_score > 0 ||
				$linked_boost > 0 ||
				$effective_score > 0 ||
				$matched_identity_count > 0
			);

			$out[] = [
				'type'                    => isset( $row['type'] ) ? (string) $row['type'] : 'unknown',
				'found'                   => $found,
				'report_count'            => $report_count,
				'direct_score'            => $direct_score,
				'linked_boost'            => $linked_boost,
				'effective_score'         => $effective_score,
				'linked_neighbors_count'  => $linked_neighbors_count,
				'risk_level'              => $risk_level,
				'last_reported'           => $last_reported,
				'match_mode'              => $match_mode,
				'matched_variant'         => $matched_variant,
				'matched_identity_count'  => $matched_identity_count,
			];
		}

		return $out;
	}

	/**
	 * Render human-readable signal summary lines from a /check response.
	 */
	public static function get_signal_summary_lines( array $resp ) : array {
		$rows = self::get_signal_summary( $resp );
		if ( empty( $rows ) ) {
			return [];
		}

		$lines = [];

		foreach ( $rows as $row ) {
			if ( empty( $row['found'] ) ) {
				continue;
			}

			$line = sprintf(
				/* translators: 1: identity type, 2: direct score, 3: linked boost, 4: effective score, 5: linked neighbors count, 6: report count, 7: risk level */
				__( 'Signal (%1$s): direct %2$s, linked +%3$s, effective %4$s, neighbors %5$d, reports %6$d, risk %7$s', 'wc-blacklist-manager' ),
				(string) $row['type'],
				number_format_i18n( (float) $row['direct_score'], 2 ),
				number_format_i18n( (float) $row['linked_boost'], 2 ),
				number_format_i18n( (float) $row['effective_score'], 2 ),
				(int) $row['linked_neighbors_count'],
				(int) $row['report_count'],
				(string) $row['risk_level']
			);

			if ( ! empty( $row['last_reported'] ) ) {
				$line .= sprintf(
					/* translators: %s: last reported datetime */
					__( ', last reported %s', 'wc-blacklist-manager' ),
					(string) $row['last_reported']
				);
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Get a structured overall signal summary for the strongest matched identity.
	 *
	 * This is intended for order-level meta storage and compact admin display.
	 *
	 * Returns:
	 * [
	 *   'matched_identities'    => int,
	 *   'max_effective_score'   => float,
	 *   'max_direct_score'      => float,
	 *   'max_linked_boost'      => float,
	 *   'max_neighbors_count'   => int,
	 *   'max_risk_level'        => string,
	 *   'primary_signal_type'   => string,
	 *   'primary_last_reported' => string|null,
	 * ]
	 */
	public static function get_overall_signal_metrics( array $resp ) : array {
		$defaults = [
			'matched_identities'            => 0,
			'matched_identity_nodes'        => 0,
			'max_effective_score'           => 0.0,
			'max_direct_score'              => 0.0,
			'max_linked_boost'              => 0.0,
			'max_neighbors_count'           => 0,
			'max_risk_level'                => 'low',
			'primary_signal_type'           => '',
			'primary_last_reported'         => null,
			'primary_match_mode'            => '',
			'primary_matched_variant'       => '',
			'primary_matched_identity_count'=> 0,
		];

		$rows = self::get_signal_summary( $resp );
		if ( empty( $rows ) ) {
			return $defaults;
		}

		$matched = array_values(
			array_filter(
				$rows,
				static function( $row ) {
					return ! empty( $row['found'] );
				}
			)
		);

		if ( empty( $matched ) ) {
			return $defaults;
		}

		usort(
			$matched,
			static function( $a, $b ) {
				$ea = (float) ( $a['effective_score'] ?? 0 );
				$eb = (float) ( $b['effective_score'] ?? 0 );

				if ( $ea === $eb ) {
					$da = (float) ( $a['direct_score'] ?? 0 );
					$db = (float) ( $b['direct_score'] ?? 0 );

					if ( $da === $db ) {
						$na = (int) ( $a['linked_neighbors_count'] ?? 0 );
						$nb = (int) ( $b['linked_neighbors_count'] ?? 0 );

						if ( $na === $nb ) {
							$ma = (int) ( $a['matched_identity_count'] ?? 0 );
							$mb = (int) ( $b['matched_identity_count'] ?? 0 );
							return $mb <=> $ma;
						}

						return $nb <=> $na;
					}

					return $db <=> $da;
				}

				return $eb <=> $ea;
			}
		);

		$top = $matched[0];

		$matched_identity_nodes = 0;
		foreach ( $matched as $row ) {
			$matched_identity_nodes += max( 1, (int) ( $row['matched_identity_count'] ?? 0 ) );
		}

		return [
			'matched_identities'             => count( $matched ),
			'matched_identity_nodes'         => $matched_identity_nodes,
			'max_effective_score'            => (float) ( $top['effective_score'] ?? 0 ),
			'max_direct_score'               => (float) ( $top['direct_score'] ?? 0 ),
			'max_linked_boost'               => (float) ( $top['linked_boost'] ?? 0 ),
			'max_neighbors_count'            => (int) ( $top['linked_neighbors_count'] ?? 0 ),
			'max_risk_level'                 => (string) ( $top['risk_level'] ?? 'low' ),
			'primary_signal_type'            => (string) ( $top['type'] ?? '' ),
			'primary_last_reported'          => $top['last_reported'] ?? null,
			'primary_match_mode'             => (string) ( $top['match_mode'] ?? '' ),
			'primary_matched_variant'        => (string) ( $top['matched_variant'] ?? '' ),
			'primary_matched_identity_count' => (int) ( $top['matched_identity_count'] ?? 0 ),
		];
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

		if ( ! in_array( $tier, [ 'free', 'basic', 'pro', 'enterprise' ], true ) ) {
			$tier = 'free';
		}

		return $tier;
	}

	/**
	 * Per-tier identity types allowed to be sent to /check.
	 *
	 * All tiers → email + phone + ip + address
	 */
	private static function get_allowed_types_for_tier( string $tier ) : array {
		return [ 'email', 'phone', 'ip', 'address' ];
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
	 *   'reason'  => string|null,
	 * ]
	 */
	private static function enforce_rate_limit( string $tier ) : array {
		switch ( $tier ) {
			case 'basic':
				$limit = 150;
				break;

			case 'pro':
				$limit = 1000;
				break;

			case 'enterprise':
				$limit = 0;
				break;

			case 'free':
			default:
				$limit = 20;
				break;
		}

		if ( $limit <= 0 ) {
			return [
				'allowed' => true,
				'reason'  => null,
			];
		}

		$month_key = gmdate( 'Ym' );
		$opt_name  = 'yogb_bm_chk_month_' . $tier . '_' . $month_key;

		$used = (int) get_option( $opt_name, 0 );

		if ( $used >= $limit ) {
			return [
				'allowed' => false,
				'reason'  => 'rate_month',
			];
		}

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
	 * @param string   $old_tier Previous tier slug
	 * @param string   $new_tier New tier slug
	 * @param int|null $ts_utc   Optional unix timestamp (UTC)
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

		$month_key = gmdate( 'Ym', $ts_utc );

		$old_opt = 'yogb_bm_chk_month_' . $old_tier . '_' . $month_key;
		$new_opt = 'yogb_bm_chk_month_' . $new_tier . '_' . $month_key;

		$old_used = (int) get_option( $old_opt, 0 );
		$new_used = (int) get_option( $new_opt, 0 );

		$target = max( $new_used, $old_used );

		if ( get_option( $new_opt, null ) === null ) {
			add_option( $new_opt, $target, '', false );
		} else {
			update_option( $new_opt, $target, false );
		}
	}
}