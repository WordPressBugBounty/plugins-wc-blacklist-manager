<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Core-owned Report Admission v2 client contract. */
final class YOGB_BM_Report_V2 {
	const CAPABILITY                 = 'report_admission_v2';
	const CAPABILITY_OPTION          = 'yogb_bm_verified_capability_snapshot_v1';
	const CAPABILITY_REFRESH_HOOK    = 'yogb_bm_report_v2_capability_refresh';
	const CAPABILITY_FRESH_SECONDS   = 7200;
	const CANDIDATE_RETENTION_SECONDS = 86400;
	const SNAPSHOT_VERSION           = 2;
	const ADAPTER_VERSION            = 1;
	const MAX_REPORT_ID              = '9000000000000000';
	const SURFACE_OPTION             = 'yogb_bm_report_v2_surface_refresh_v1';
	const SURFACE_HOOK               = 'yogb_bm_report_v2_surface_refresh';
	const SURFACE_WINDOW             = 7200;
	const SURFACE_COOLDOWN           = 900;

	public static function init() : void {
		add_action( self::CAPABILITY_REFRESH_HOOK, [ __CLASS__, 'refresh_capabilities' ] );
		add_action( self::SURFACE_HOOK, [ __CLASS__, 'refresh_surface' ] );
	}

	public static function refresh_capabilities() : void {
		if ( class_exists( 'YOGB_BM_Tier_Sync' ) ) {
			YOGB_BM_Tier_Sync::run_repair();
		}
	}

	public static function schedule_capability_refresh() : void {
		if ( ! wp_next_scheduled( self::CAPABILITY_REFRESH_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CAPABILITY_REFRESH_HOOK );
		}
	}

	/** Persist a capability set only after its signed tier payload has validated. */
	public static function record_verified_capabilities( array $capabilities, int $verified_at = 0 ) : void {
		$bounded = [];
		foreach ( array_slice( $capabilities, 0, 64 ) as $capability ) {
			if ( ! is_scalar( $capability ) ) continue;
			$capability = sanitize_key( substr( (string) $capability, 0, 64 ) );
			if ( '' !== $capability ) $bounded[ $capability ] = true;
		}
		$capabilities = array_keys( $bounded );
		sort( $capabilities );
		$verified_at = $verified_at > 0 ? $verified_at : time();
		if ( get_option( 'yogb_bm_server_capabilities', null ) !== $capabilities ) {
			update_option( 'yogb_bm_server_capabilities', $capabilities, false );
		}
		$snapshot = [ 'capabilities' => $capabilities, 'verified_at' => $verified_at ];
		if ( get_option( self::CAPABILITY_OPTION, null ) !== $snapshot ) {
			update_option( self::CAPABILITY_OPTION, $snapshot, false );
		}
	}

	public static function invalidate_capability() : void {
		$snapshot = get_option( self::CAPABILITY_OPTION, [] );
		$snapshot = is_array( $snapshot ) ? $snapshot : [];
		$snapshot['verified_at'] = 0;
		update_option( self::CAPABILITY_OPTION, $snapshot, false );
		self::schedule_capability_refresh();
	}

	public static function capability_state( ?int $now = null ) : array {
		$now      = null === $now ? time() : $now;
		$snapshot = get_option( self::CAPABILITY_OPTION, [] );
		return self::state_from_snapshot( $snapshot, $now );
	}

	private static function state_from_snapshot( $snapshot, int $now ) : array {
		if ( ! is_array( $snapshot ) ) {
			$snapshot = [];
		}
		$verified_at = isset( $snapshot['verified_at'] ) ? (int) $snapshot['verified_at'] : 0;
		$capabilities = array_values(
			array_unique(
				array_filter( array_map( 'sanitize_key', (array) ( $snapshot['capabilities'] ?? [] ) ) )
			)
		);
		$fresh = $verified_at > 0 && $now >= $verified_at && ( $now - $verified_at ) <= self::CAPABILITY_FRESH_SECONDS;
		return [
			'fresh'        => $fresh,
			'supported'    => $fresh && in_array( self::CAPABILITY, $capabilities, true ),
			'verified_at'  => $verified_at,
			'capabilities' => $capabilities,
		];
	}

	/** One uncached snapshot supplies both presentation metadata and modal config. */
	public static function surface_observation( ?int $now = null ) : array {
		$now = null === $now ? time() : $now;
		$raw = self::read_surface_option( self::CAPABILITY_OPTION );
		$snapshot = null === $raw ? [] : ( is_string( $raw ) && strlen( $raw ) <= 16384 && is_serialized( $raw ) ? unserialize( $raw, [ 'allowed_classes' => false ] ) : false );
		$valid = null === $raw || ( is_array( $snapshot ) && isset( $snapshot['verified_at'], $snapshot['capabilities'] )
			&& is_int( $snapshot['verified_at'] ) && $snapshot['verified_at'] >= 0
			&& is_array( $snapshot['capabilities'] ) && count( $snapshot['capabilities'] ) <= 64 );
		if ( $valid && null !== $raw ) {
			foreach ( $snapshot['capabilities'] as $member ) {
				if ( ! is_string( $member ) || strlen( $member ) > 64 ) $valid = false;
			}
		}
		$capability = self::state_from_snapshot( $valid ? $snapshot : [], $now );
		$advertised = in_array( self::CAPABILITY, $capability['capabilities'], true );
		$verified_at = $capability['verified_at'];
		$identity = untrailingslashit( strtolower( (string) home_url( '/' ) ) ) . '|blog:' . (int) get_current_blog_id();
		$jitter = (int) ( hexdec( substr( hash( 'sha256', $identity ), 0, 8 ) ) % 301 );
		$renew_at = $verified_at > 0 ? $verified_at + self::CAPABILITY_FRESH_SECONDS - 1200 - $jitter : 0;
		$connected = $valid && $verified_at <= $now && class_exists( 'YOGB_BM_Registrar' )
			&& YOGB_BM_Registrar::authenticated_requests_allowed();
		return [
			'capability' => $capability, // Internal only; never serialize the capability list to the order endpoint.
			'server_now' => $now,
			'state' => [
				'snapshot_id' => hash( 'sha256', $verified_at . '|' . (int) $advertised ),
				'verified_at' => $verified_at,
				'advertised' => $advertised,
				'fresh' => $capability['fresh'],
				'fresh_until' => $verified_at > 0 ? $verified_at + self::CAPABILITY_FRESH_SECONDS : 0,
			],
			'renewal' => [
				'eligible' => $connected && $advertised,
				'discovery_allowed' => $connected && ! $advertised,
				'renew_at' => $renew_at,
				'status' => $connected ? 'observed' : 'unavailable',
				'retry_at' => 0,
			],
		];
	}

	/** null means absent; false means storage failure. Neither read uses option caches. */
	private static function read_surface_option( string $name ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $name ) );
		return '' !== (string) $wpdb->last_error ? false : $raw;
	}

	/** Fixed-size, versioned admission state. Bad clocks/records never replenish the budget. */
	private static function surface_record( $raw, int $now ) : ?array {
		if ( null === $raw ) return [ 'v' => 1, 'window' => (int) floor( $now / self::SURFACE_WINDOW ), 'count' => 0, 'last' => 0, 'pending' => null ];
		if ( ! is_string( $raw ) || strlen( $raw ) > 1024 ) return null;
		$r = json_decode( $raw, true );
		if ( ! is_array( $r ) || 1 !== ( $r['v'] ?? null ) || ! isset( $r['window'], $r['count'], $r['last'] ) || ! array_key_exists( 'pending', $r ) ) return null;
		if ( ! is_int( $r['window'] ) || ! is_int( $r['count'] ) || ! is_int( $r['last'] )
			|| $r['last'] <= 0 || $r['last'] > $now || $r['count'] < 1 || $r['count'] > 2
			|| $r['window'] !== (int) floor( $r['last'] / self::SURFACE_WINDOW ) ) return null;
		$t = $r['pending'];
		if ( null !== $t && ( ! is_array( $t ) || ! isset( $t['token'], $t['not_before'], $t['deadline'], $t['snapshot'], $t['discovery'] )
			|| ! is_string( $t['token'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $t['token'] )
			|| ! is_string( $t['snapshot'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $t['snapshot'] )
			|| ! is_bool( $t['discovery'] ) || ! is_int( $t['not_before'] ) || ! is_int( $t['deadline'] )
			|| $t['not_before'] !== $r['last'] + 5 || $t['deadline'] < $t['not_before']
			|| $t['deadline'] !== min( $r['last'] + 120, ( $r['window'] + 1 ) * self::SURFACE_WINDOW - 1 ) ) ) return null;
		return $r;
	}

	/** One attempt; an insert/CAS loser does not schedule, retry or refund. */
	private static function cas_surface_record( $raw, array $record ) : bool {
		global $wpdb;
		$value = wp_json_encode( $record );
		if ( ! is_string( $value ) || strlen( $value ) > 1024 ) return false;
		if ( null === $raw ) {
			$sql = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::SURFACE_OPTION, $value );
		} else {
			$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s,autoload='no' WHERE option_name=%s AND BINARY option_value=%s", $value, self::SURFACE_OPTION, $raw );
		}
		if ( 1 !== $wpdb->query( $sql ) ) return false;
		wp_cache_delete( self::SURFACE_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return true;
	}

	/** Admission changes only local scheduling state, never signed capability authority. */
	public static function request_surface_refresh( array $observation, string $intent ) : array {
		$renewal = $observation['renewal'];
		$now = $observation['server_now'];
		if ( 'observe' === $intent ) return $renewal;
		$discovery = 'discover' === $intent && ! empty( $renewal['discovery_allowed'] );
		if ( ! $discovery && ( empty( $renewal['eligible'] ) || $renewal['renew_at'] > $now ) ) return $renewal;
		$renewal['status'] = 'unavailable';
		$raw = self::read_surface_option( self::SURFACE_OPTION );
		$r = self::surface_record( $raw, $now );
		if ( null === $r ) return $renewal;
		$window = (int) floor( $now / self::SURFACE_WINDOW );
		$retry_at = $r['last'] + self::SURFACE_COOLDOWN;
		if ( $window === $r['window'] && $r['count'] >= 2 ) $retry_at = max( $retry_at, ( $window + 1 ) * self::SURFACE_WINDOW );
		if ( $retry_at > $now ) {
			$renewal['status'] = $window === $r['window'] && $r['count'] >= 2 ? 'budget_exhausted' : 'cooldown';
			$renewal['retry_at'] = $retry_at;
			return $renewal;
		}
		$deadline = min( $now + 120, ( $window + 1 ) * self::SURFACE_WINDOW - 1 );
		if ( $deadline < $now + 5 ) {
			$renewal['status'] = 'cooldown';
			$renewal['retry_at'] = ( $window + 1 ) * self::SURFACE_WINDOW;
			return $renewal;
		}
		$r = [
			'v' => 1, 'window' => $window, 'count' => ( $window === $r['window'] ? $r['count'] : 0 ) + 1, 'last' => $now,
			'pending' => [ 'token' => bin2hex( random_bytes( 16 ) ), 'not_before' => $now + 5, 'deadline' => $deadline,
				'snapshot' => $observation['state']['snapshot_id'], 'discovery' => $discovery ],
		];
		$renewal['retry_at'] = $now + self::SURFACE_COOLDOWN;
		if ( ! self::cas_surface_record( $raw, $r ) ) return $renewal;
		$scheduled = wp_next_scheduled( self::SURFACE_HOOK ) || wp_schedule_single_event( $now + 5, self::SURFACE_HOOK );
		$renewal['status'] = $scheduled ? 'scheduled' : 'unavailable';
		return $renewal;
	}

	/** A stale cron wakeup can consume only the currently admitted, unexpired ticket. */
	public static function refresh_surface( ?int $now = null ) : void {
		$live_clock = null === $now;
		$now = null === $now ? time() : $now;
		$raw = self::read_surface_option( self::SURFACE_OPTION );
		$r = self::surface_record( $raw, $now );
		if ( null === $r || empty( $r['pending'] ) || $now < $r['pending']['not_before'] ) return;
		$t = $r['pending'];
		$r['pending'] = null;
		if ( ! self::cas_surface_record( $raw, $r ) || $now > $t['deadline'] ) return;
		$observation = self::surface_observation( $now );
		$renewal = $observation['renewal'];
		if ( $t['discovery'] ) {
			if ( empty( $renewal['discovery_allowed'] ) || $t['snapshot'] !== $observation['state']['snapshot_id'] ) return;
		} elseif ( empty( $renewal['eligible'] ) || $renewal['renew_at'] > $now ) {
			return;
		}
		// Database/credential reads can be slow; do not dispatch an expired ticket after them.
		if ( $live_clock && ( time() > $t['deadline'] || time() < $t['not_before'] ) ) return;
		if ( class_exists( 'YOGB_BM_Tier_Sync' ) ) YOGB_BM_Tier_Sync::run_repair( true );
	}

	public static function supports_v2( ?int $now = null ) : bool {
		$state = self::capability_state( $now );
		return ! empty( $state['supported'] );
	}

	public static function contract_nonce_action( int $order_id ) : string {
		return 'yogb_bm_report_v2_' . max( 0, $order_id );
	}

	public static function verify_contract_nonce( int $order_id, string $nonce ) : bool {
		return $order_id > 0 && '' !== $nonce && (bool) wp_verify_nonce( $nonce, self::contract_nonce_action( $order_id ) );
	}

	public static function reasons() : array {
		return [
			'unauthorized_payment',
			'payment_dispute_abuse',
			'payment_credential_testing',
			'fake_payment_proof',
			'fake_order',
			'identity_misrepresentation',
			'delivery_refusal_abuse',
			'return_refund_abuse',
			'coordinated_fraud',
			'store_policy_abuse',
			'unclassified',
		];
	}

	public static function payment_families() : array {
		return [ 'cod', 'bank_transfer', 'manual_payment', 'card', 'wallet', 'direct_debit', 'bnpl', 'other_online', 'unknown' ];
	}

	/** Build the v2-only modal configuration; callers retain their existing v1 config otherwise. */
	public static function modal_config( WC_Order $order, ?array $capability = null ) : ?array {
		$capability = null === $capability ? self::capability_state() : $capability;
		if ( empty( $capability['supported'] ) ) {
			return null;
		}

		$payment      = self::classify_payment( $order );
		$recommended  = self::recommended_reasons( (string) $payment['family'] );
		$labels       = self::reason_labels();
		$descriptions = self::reason_descriptions();
		$ordered      = [];
		$meta         = [];

		foreach ( array_merge( $recommended, self::reasons() ) as $reason ) {
			if ( isset( $ordered[ $reason ] ) || ! isset( $labels[ $reason ] ) ) {
				continue;
			}
			$is_recommended = in_array( $reason, $recommended, true );
			$is_impossible  = 'authoritative' === (string) $payment['classification']
				&& 'cod' === (string) $payment['family']
				&& 'payment_credential_testing' === $reason;
			$ordered[ $reason ] = $labels[ $reason ];
			$meta[ $reason ] = [
				'presentation' => $is_recommended ? 'recommended' : ( $is_impossible ? 'impossible' : 'applicable' ),
				'disabled'     => $is_impossible,
			];
			if ( $is_impossible ) {
				$descriptions[ $reason ] = __( 'Unavailable for an authoritatively verified cash-on-delivery gateway because no payment credential is used.', 'wc-blacklist-manager' );
			}
		}

		return [
			'contractMode'         => 'v2',
			'contractNonce'        => wp_create_nonce( self::contract_nonce_action( (int) $order->get_id() ) ),
			'capabilityFreshUntil' => (int) $capability['verified_at'] + self::CAPABILITY_FRESH_SECONDS,
			'payment'              => $payment,
			'reasons'              => $ordered,
			'reasonMeta'           => $meta,
			'descriptions'         => $descriptions,
			'labels'               => [
				'modal_title'       => __( 'Block customer', 'wc-blacklist-manager' ),
				'reason_label'      => __( 'Reason', 'wc-blacklist-manager' ),
				'select_reason'     => __( 'Select a reason...', 'wc-blacklist-manager' ),
				'description_label' => __( 'Details', 'wc-blacklist-manager' ),
				'disclosure'        => __( 'Details are saved with this block and may be sent to the Global Blacklist service when a Global report is submitted. Do not include payment card details, passwords, OTPs, API keys, or other secrets.', 'wc-blacklist-manager' ),
				'recommended'       => __( 'Recommended', 'wc-blacklist-manager' ),
				'applicable'        => __( 'Applicable', 'wc-blacklist-manager' ),
				'impossible'        => __( 'Unavailable', 'wc-blacklist-manager' ),
				'required_reason'   => __( 'Please select a reason.', 'wc-blacklist-manager' ),
				'required_desc'     => __( 'Please enter Details for Other / unclassified.', 'wc-blacklist-manager' ),
				'cancel'            => __( 'Cancel', 'wc-blacklist-manager' ),
				'confirm'           => __( 'Confirm block', 'wc-blacklist-manager' ),
				'processingText'    => __( 'Processing...', 'wc-blacklist-manager' ),
			],
		];
	}

	private static function reason_labels() : array {
		return [
			'unauthorized_payment'       => __( 'Suspected unauthorized payment', 'wc-blacklist-manager' ),
			'payment_dispute_abuse'      => __( 'Payment dispute abuse', 'wc-blacklist-manager' ),
			'payment_credential_testing' => __( 'Payment credential testing', 'wc-blacklist-manager' ),
			'fake_payment_proof'         => __( 'Fake payment proof', 'wc-blacklist-manager' ),
			'fake_order'                 => __( 'Fake / no-intent order', 'wc-blacklist-manager' ),
			'identity_misrepresentation' => __( 'Identity misrepresentation', 'wc-blacklist-manager' ),
			'delivery_refusal_abuse'     => __( 'Delivery refusal / no-show abuse', 'wc-blacklist-manager' ),
			'return_refund_abuse'        => __( 'Return / refund abuse', 'wc-blacklist-manager' ),
			'coordinated_fraud'          => __( 'Coordinated / linked fraud activity', 'wc-blacklist-manager' ),
			'store_policy_abuse'         => __( 'Store policy abuse — local block only', 'wc-blacklist-manager' ),
			'unclassified'               => __( 'Other / unclassified — Global shadow only', 'wc-blacklist-manager' ),
		];
	}

	private static function reason_descriptions() : array {
		return [
			'unauthorized_payment'       => __( 'Merchant selection alone or an ordinary decline is not proof of unauthorized payment.', 'wc-blacklist-manager' ),
			'payment_dispute_abuse'      => __( 'A dispute or chargeback by itself is not proof of abuse.', 'wc-blacklist-manager' ),
			'payment_credential_testing' => __( 'A single decline or gateway error is not proof of credential testing.', 'wc-blacklist-manager' ),
			'fake_payment_proof'         => __( 'A pending payment or unreviewed document is not proof of fake payment evidence.', 'wc-blacklist-manager' ),
			'fake_order'                 => __( 'A single cancellation, mistake, decline, or change of mind is not proof of a fake order.', 'wc-blacklist-manager' ),
			'identity_misrepresentation' => __( 'A single typo or formatting difference is not proof of identity misrepresentation.', 'wc-blacklist-manager' ),
			'delivery_refusal_abuse'     => __( 'A single refusal or missed delivery is not proof of repeated abuse.', 'wc-blacklist-manager' ),
			'return_refund_abuse'        => __( 'A normal return, refund, or policy use is not proof of abuse.', 'wc-blacklist-manager' ),
			'coordinated_fraud'          => __( 'Use only with corroborating linked-incident evidence; selecting this reason does not prove coordination.', 'wc-blacklist-manager' ),
			'store_policy_abuse'         => __( 'Saved locally only and never sent as a Global v2 candidate.', 'wc-blacklist-manager' ),
			'unclassified'               => __( 'May be retained by Global Blacklist for shadow review only and has no live authority.', 'wc-blacklist-manager' ),
		];
	}

	private static function recommended_reasons( string $family ) : array {
		$map = [
			'cod'            => [ 'delivery_refusal_abuse', 'fake_order' ],
			'bank_transfer'  => [ 'fake_payment_proof', 'fake_order' ],
			'manual_payment' => [ 'fake_payment_proof', 'fake_order' ],
			'card'           => [ 'unauthorized_payment', 'payment_credential_testing', 'payment_dispute_abuse' ],
			'wallet'         => [ 'unauthorized_payment', 'payment_dispute_abuse' ],
			'direct_debit'   => [ 'unauthorized_payment', 'payment_dispute_abuse' ],
			'bnpl'           => [ 'unauthorized_payment', 'payment_dispute_abuse', 'fake_order' ],
		];
		return $map[ $family ] ?? [];
	}

	/** Core assigns final authority after validating native or third-party declarations. */
	public static function classify_payment( WC_Order $order ) : array {
		$method_id = strtolower( trim( (string) $order->get_payment_method() ) );
		$bounded_method_id = preg_match( '/^[a-z0-9._-]{1,64}$/', $method_id ) ? $method_id : '';
		$gateway = self::gateway_for_method( $method_id );

		$native = [
			'cod'    => [ 'class' => 'WC_Gateway_COD', 'family' => 'cod' ],
			'bacs'   => [ 'class' => 'WC_Gateway_BACS', 'family' => 'bank_transfer' ],
			'cheque' => [ 'class' => 'WC_Gateway_Cheque', 'family' => 'manual_payment' ],
		];
		if ( isset( $native[ $method_id ] ) && is_object( $gateway ) && is_a( $gateway, $native[ $method_id ]['class'] ) ) {
			return self::payment_result( $native[ $method_id ]['family'], 'authoritative', $bounded_method_id );
		}

		$declarations = apply_filters( 'yogb_bm_report_v2_payment_adapters', [], $order, $method_id, $gateway, self::ADAPTER_VERSION );
		if ( is_array( $declarations ) && isset( $declarations['version'] ) ) {
			$declarations = [ $declarations ];
		}
		$families     = [];
		foreach ( is_array( $declarations ) ? $declarations : [] as $declaration ) {
			if ( ! is_array( $declaration ) ) {
				continue;
			}
			$version = isset( $declaration['version'] ) ? (int) $declaration['version'] : 0;
			$declared_method = strtolower( trim( (string) ( $declaration['method_id'] ?? '' ) ) );
			$family = sanitize_key( (string) ( $declaration['family'] ?? '' ) );
			if ( self::ADAPTER_VERSION !== $version || '' === $bounded_method_id || $declared_method !== $method_id || ! in_array( $family, self::payment_families(), true ) || 'unknown' === $family ) {
				continue;
			}
			$families[ $family ] = true;
		}
		if ( 1 === count( $families ) ) {
			return self::payment_result( (string) key( $families ), 'authoritative', $bounded_method_id );
		}
		if ( count( $families ) > 1 ) {
			return self::payment_result( 'unknown', 'unknown', $bounded_method_id );
		}

		$inferred = self::infer_family( $method_id );
		if ( 'unknown' !== $inferred ) {
			return self::payment_result( $inferred, 'inferred', $bounded_method_id );
		}
		return self::payment_result( 'unknown', 'unknown', $bounded_method_id );
	}

	private static function payment_result( string $family, string $classification, string $method_id ) : array {
		$result = [ 'family' => $family, 'classification' => $classification ];
		if ( '' !== $method_id ) {
			$result['method_id'] = $method_id;
		}
		return $result;
	}

	private static function gateway_for_method( string $method_id ) {
		if ( '' === $method_id || ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
			return null;
		}
		$registry = WC()->payment_gateways();
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'payment_gateways' ) ) {
			return null;
		}
		$gateways = $registry->payment_gateways();
		return is_array( $gateways ) && isset( $gateways[ $method_id ] ) ? $gateways[ $method_id ] : null;
	}

	private static function infer_family( string $method_id ) : string {
		$rules = [
			'card'         => '/(?:^|[._-])(stripe|card|creditcard|authorize_net)(?:$|[._-])/',
			'wallet'       => '/(?:^|[._-])(paypal|apple_pay|google_pay)(?:$|[._-])/',
			'direct_debit' => '/(?:^|[._-])(sepa|direct_debit)(?:$|[._-])/',
			'bnpl'         => '/(?:^|[._-])(klarna|afterpay|affirm)(?:$|[._-])/',
		];
		foreach ( $rules as $family => $pattern ) {
			if ( preg_match( $pattern, $method_id ) ) {
				return $family;
			}
		}
		return 'unknown';
	}

	/** Queue either unchanged legacy v1 or a manual immutable v2 intent. */
	public static function queue_intent_from_order( WC_Order $order, array $intent ) : array {
		$contract = sanitize_key( (string) ( $intent['contract'] ?? 'v1' ) );
		$source   = sanitize_key( (string) ( $intent['source_kind'] ?? 'merchant_manual_block' ) );
		if ( 'v2' !== $contract ) {
			$legacy_reason = sanitize_key( (string) ( $intent['legacy_reason'] ?? $intent['reason_code'] ?? '' ) );
			$legacy_details = (string) ( $intent['legacy_details'] ?? $intent['details'] ?? '' );
			YOGB_BM_Report::queue_report_from_order( $order, $legacy_reason, $legacy_details );
			return self::selection_result( $contract, [ 'state' => 'legacy_queued', 'source_kind' => $source ] );
		}

		if ( 'merchant_manual_block' !== $source || empty( $intent['selection_authorized'] ) ) {
			return self::selection_result( $contract, [ 'state' => 'not_queued_invalid', 'error' => 'invalid_v2_selection' ] );
		}
		$reason = sanitize_key( (string) ( $intent['reason_code'] ?? '' ) );
		if ( ! in_array( $reason, self::reasons(), true ) ) {
			return self::selection_result( $contract, [ 'state' => 'not_queued_invalid', 'error' => 'invalid_reason' ] );
		}
		if ( 'store_policy_abuse' === $reason ) {
			return self::selection_result( $contract, [ 'state' => 'local_only' ] );
		}

		$details = self::normalize_details( (string) ( $intent['details'] ?? '' ) );
		if ( empty( $details['ok'] ) ) {
			return self::selection_result( $contract, [ 'state' => 'not_queued_invalid', 'error' => (string) $details['error'] ] );
		}

		$captured_at = time();
		$payment     = self::classify_payment( $order );
		$payment['paid_at_present'] = null !== $order->get_date_paid();
		$payment['transaction_reference_present'] = '' !== trim( (string) $order->get_transaction_id() );
		if ( 'authoritative' === $payment['classification'] && 'cod' === $payment['family'] && 'payment_credential_testing' === $reason ) {
			return self::selection_result( $contract, [ 'state' => 'not_queued_invalid', 'error' => 'impossible_payment_reason' ] );
		}

		$candidate_id = self::candidate_id();
		$order_context = self::order_context( $order, $captured_at );
		$snapshot_material = [
			'candidate_id' => $candidate_id,
			'source_kind'  => $source,
			'reason_code'  => $reason,
			'payment'      => $payment,
			'order'        => $order_context,
			'evidence'     => [ 'merchant_observation' ],
		];
		$report_v2 = [
			'schema_version' => 2,
			'source'         => [ 'kind' => $source ],
			'reason_code'    => $reason,
			'payment'        => $payment,
			'order'          => $order_context,
			'evidence'       => [
				'snapshot_version' => self::SNAPSHOT_VERSION,
				'snapshot_hash'    => hash( 'sha256', self::json( $snapshot_material ) ),
				'types'            => [ 'merchant_observation' ],
			],
		];
		if ( '' !== (string) $details['value'] ) {
			$report_v2['description'] = (string) $details['value'];
		}

		$payloads = self::payloads_from_order( $order, $report_v2 );
		if ( empty( $payloads ) || ! class_exists( 'YOGB_BM_Outbox' ) ) {
			return self::selection_result( $contract, [ 'state' => 'not_queued_invalid', 'error' => 'no_report_identity' ] );
		}

		$queued = 0;
		foreach ( $payloads as $payload ) {
			$body = self::json( $payload );
			if ( '' === $body ) {
				continue;
			}
			$identity = (array) ( $payload['identity'] ?? [] );
			$identity_scope = hash( 'sha256', (string) ( $identity['type'] ?? '' ) . '|' . (string) ( $identity['value'] ?? '' ) );
			$idempotency = hash_hmac( 'sha256', $candidate_id . '|' . $identity_scope, wp_salt( 'auth' ) );
			$id = YOGB_BM_Outbox::enqueue_report_v2( $body, $idempotency, (int) $order->get_id(), $candidate_id, hash( 'sha256', $body ), $captured_at );
			if ( $id > 0 ) {
				$queued++;
			}
		}
		if ( 0 === $queued ) {
			return self::selection_result( $contract, [ 'state' => 'not_queued_invalid', 'error' => 'outbox_unavailable' ] );
		}
		return self::selection_result( $contract, [ 'state' => self::supports_v2() ? 'queued' : 'deferred', 'candidate_count' => $queued ] );
	}

	private static function selection_result( string $contract, array $result ) : array {
		do_action(
			'yogb_bm_report_v2_selection_event',
			sanitize_key( (string) ( $result['state'] ?? 'unknown' ) ),
			[ 'contract' => 'v2' === $contract ? 'v2' : 'v1' ]
		);
		return $result;
	}

	public static function normalize_details( string $value ) : array {
		if ( 1 !== preg_match( '//u', $value ) ) {
			return [ 'ok' => false, 'value' => '', 'error' => 'invalid_utf8' ];
		}
		$value = str_replace( [ "\r\n", "\r" ], "\n", $value );
		$value = trim( $value );
		$sanitized = sanitize_textarea_field( $value );
		if ( $sanitized !== $value ) {
			return [ 'ok' => false, 'value' => '', 'error' => 'invalid_plain_text' ];
		}
		$code_points = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value, $matches );
		if ( false === $code_points || $code_points > 256 ) {
			return [ 'ok' => false, 'value' => '', 'error' => 'details_codepoints_exceeded' ];
		}
		if ( strlen( $value ) > 1024 ) {
			return [ 'ok' => false, 'value' => '', 'error' => 'details_bytes_exceeded' ];
		}
		return [ 'ok' => true, 'value' => $value, 'error' => '' ];
	}

	public static function normalize_report_reference( $value ) : string {
		if ( is_int( $value ) ) {
			if ( $value <= 0 ) {
				return '';
			}
			$digits = (string) $value;
		} elseif ( is_string( $value ) ) {
			$raw = trim( $value );
			if ( 0 === strpos( $raw, 'rpt_' ) ) {
				$raw = substr( $raw, 4 );
			}
			if ( '' === $raw || ! ctype_digit( $raw ) ) {
				return '';
			}
			$digits = ltrim( $raw, '0' );
			if ( '' === $digits ) {
				return '';
			}
		} else {
			return '';
		}
		if ( strlen( $digits ) > strlen( self::MAX_REPORT_ID ) || ( strlen( $digits ) === strlen( self::MAX_REPORT_ID ) && strcmp( $digits, self::MAX_REPORT_ID ) > 0 ) ) {
			return '';
		}
		return 'rpt_' . $digits;
	}

	public static function report_reference_digits( $value ) : string {
		$public = self::normalize_report_reference( $value );
		return '' === $public ? '' : substr( $public, 4 );
	}

	private static function order_context( WC_Order $order, int $captured_at ) : array {
		$created = $order->get_date_created();
		$created_ts = is_object( $created ) && method_exists( $created, 'getTimestamp' ) ? (int) $created->getTimestamp() : $captured_at;
		$age = max( 0, min( 315576000, $captured_at - $created_ts ) );
		$status = sanitize_key( (string) $order->get_status() );
		if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', $status ) ) {
			$status = 'unknown';
		}
		$currency = strtoupper( (string) $order->get_currency() );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = 'XXX';
		}
		return [
			'status'               => $status,
			'age_seconds'          => $age,
			'is_paid'              => (bool) $order->is_paid(),
			'physical_fulfilment'  => self::has_physical_fulfilment( $order ),
			'amount'               => self::amount_string( (string) $order->get_total() ),
			'currency'             => $currency,
		];
	}

	private static function amount_string( string $amount ) : string {
		$amount = trim( $amount );
		if ( ! preg_match( '/^(0|[1-9][0-9]{0,17})(\.[0-9]{1,8})?$/', $amount ) ) {
			$amount = number_format( max( 0, (float) $amount ), 2, '.', '' );
		}
		return preg_match( '/^(0|[1-9][0-9]{0,17})(\.[0-9]{1,8})?$/', $amount ) ? $amount : '0';
	}

	private static function has_physical_fulfilment( WC_Order $order ) : bool {
		$items = method_exists( $order, 'get_items' ) ? (array) $order->get_items( 'line_item' ) : [];
		foreach ( $items as $item ) {
			$product = is_object( $item ) && method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			if ( ! is_object( $product ) || ! method_exists( $product, 'is_virtual' ) || ! $product->is_virtual() ) {
				return true;
			}
		}
		$shipping = method_exists( $order, 'get_items' ) ? (array) $order->get_items( 'shipping' ) : [];
		return ! empty( $shipping );
	}

	private static function payloads_from_order( WC_Order $order, array $report_v2 ) : array {
		$identities = YOGB_BM_Report::build_identities_from_order( $order );
		$ttl_days   = max( 1, min( 1095, (int) get_option( 'yogb_bm_default_ttl_days', 365 ) ) );
		$payloads   = [];
		foreach ( $identities as $identity ) {
			if ( ! is_array( $identity ) || 'domain' === (string) ( $identity['type'] ?? '' ) ) {
				continue;
			}
			$related = [];
			foreach ( $identities as $candidate ) {
				if ( ! is_array( $candidate ) || $candidate === $identity ) {
					continue;
				}
				$is_domain = 'domain' === (string) ( $candidate['type'] ?? '' );
				$related[] = [
					'type'        => (string) ( $candidate['type'] ?? '' ),
					'value'       => (string) ( $candidate['value'] ?? '' ),
					'role'        => $is_domain ? 'derived_domain' : 'secondary',
					'link_source' => $is_domain ? 'derived' : 'report',
				];
			}
			$payloads[] = [
				'identity'           => $identity,
				'context'            => [ 'report_v2' => $report_v2 ],
				'related_identities' => $related,
				'ttl_days'           => $ttl_days,
			];
		}
		return $payloads;
	}

	private static function candidate_id() : string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return hash( 'sha256', microtime( true ) . '|' . wp_rand() );
		}
	}

	private static function json( array $value ) : string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}
}

YOGB_BM_Report_V2::init();
