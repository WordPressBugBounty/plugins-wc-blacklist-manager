<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded, capability-gated cleanup for legacy Global Blacklist order meta.
 *
 * Verbose client data is removed only when the authenticated server confirms
 * that it still owns and retains the full payload for the same decision.
 */
final class YOGB_BM_Compact_Meta_Migration {
	const HOOK             = 'yogb_bm_compact_meta_cleanup';
	const GROUP            = 'yogb-global-blacklist';
	const OPT_COMPLETE     = 'yogb_bm_compact_meta_cleanup_complete';
	const OPT_STATE        = 'yogb_bm_compact_meta_cleanup_state';
	const OPT_LOCK         = 'yogb_bm_compact_meta_cleanup_lock';
	const META_CHECKED     = '_yogb_gbl_compact_cleanup_checked';
	const MIGRATION        = 'compact_v1';
	const BATCH_SIZE       = 20;
	const QUEUE_REPAIR_LIMIT = 100;

	public static function init() : void {
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ], 30 );
		add_action( self::HOOK, [ __CLASS__, 'process_batch' ] );
	}

	public static function maybe_schedule() : void {
		$complete = self::MIGRATION === (string) get_option( self::OPT_COMPLETE, '' );
		self::repair_pending_queue( $complete );
		if ( $complete || ! self::supports_cleanup() ) {
			return;
		}
		self::schedule( 15 );
	}

	public static function process_batch() : void {
		if ( self::MIGRATION === (string) get_option( self::OPT_COMPLETE, '' ) ) {
			self::repair_pending_queue( true );
			return;
		}

		if ( ! function_exists( 'wc_get_orders' ) || ! self::supports_cleanup() || ! YOGB_BM_Report::is_ready() ) {
			self::schedule( HOUR_IN_SECONDS );
			return;
		}

		if ( ! self::acquire_lock() ) {
			self::schedule( 30 );
			return;
		}

		try {
			self::process_unlocked_batch();
		} finally {
			delete_option( self::OPT_LOCK );
		}
	}

	private static function process_unlocked_batch() : void {
		$orders = self::get_eligible_orders();
		if ( empty( $orders ) ) {
			update_option( self::OPT_COMPLETE, self::MIGRATION, false );
			self::repair_pending_queue( true );
			return;
		}

		$by_ref = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || ! self::is_unchecked_verbose_order( $order ) ) {
				continue;
			}
			$ref = (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_REF, true );
			if ( preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) ) {
				$by_ref[ $ref ][] = $order;
			} elseif ( '' !== trim( $ref ) ) {
				self::retain( $order, 'invalid_reference' );
			}
		}
		if ( empty( $by_ref ) ) {
			self::schedule( 30 );
			return;
		}

		$response = YOGB_BM_Report::post_json_signed(
			YOGB_BM_Report::REST_ROUTE . '/decision/details/availability',
			[ 'decision_refs' => array_keys( $by_ref ) ]
		);
		$payload  = json_decode( (string) ( $response['body'] ?? '' ), true );
		if ( empty( $response['ok'] ) || ! is_array( $payload ) || 'yogb_decision_availability_v1' !== (string) ( $payload['schema'] ?? '' ) ) {
			self::record_state( 0, 0, 'server_unavailable' );
			self::schedule( HOUR_IN_SECONDS );
			return;
		}

		$availability = [];
		foreach ( (array) ( $payload['decisions'] ?? [] ) as $row ) {
			if ( is_array( $row ) ) {
				$availability[ (string) ( $row['decision_ref'] ?? '' ) ] = $row;
			}
		}

		$cleaned = 0;
		$retained = 0;
		foreach ( $by_ref as $ref => $matching_orders ) {
			$row  = $availability[ $ref ] ?? [];
			$full = ! empty( $row['available'] ) && 'full' === (string) ( $row['detail_level'] ?? '' );
			foreach ( $matching_orders as $order ) {
				if ( ! self::is_unchecked_verbose_order( $order ) ) {
					continue;
				}
				if ( $full ) {
					self::prepare_compact_fallback( $order );
					YOGB_BM_Check_Orders::delete_legacy_verbose_meta( $order );
					$order->update_meta_data( YOGB_BM_Check_Orders::META_STORAGE_PROFILE, 'compact_v1_migrated' );
					$order->update_meta_data( self::META_CHECKED, 'cleaned_full_server_copy' );
					$order->save_meta_data();
					$cleaned++;
				} else {
					$reason = sanitize_key( (string) ( $row['detail_level'] ?? 'missing' ) );
					self::retain( $order, $reason ?: 'missing' );
					$retained++;
				}
			}
		}
		self::record_state( $cleaned, $retained, 'batch_complete' );
		self::schedule( 30 );
	}

	private static function get_eligible_orders() : array {
		$args = [
			'type'    => 'shop_order',
			'limit'   => self::BATCH_SIZE,
			'return'  => 'objects',
			'orderby' => 'ID',
			'order'   => 'ASC',
		];

		if ( self::hpos_is_enabled() ) {
			$args['meta_query'] = self::eligible_meta_query();
			return (array) wc_get_orders( $args );
		}

		$token = wp_generate_uuid4();
		$args['yogb_bm_compact_migration_query'] = $token;
		$filter = static function( array $wp_query_args, array $query_vars ) use ( $token ) : array {
			return self::add_legacy_meta_query( $wp_query_args, $query_vars, $token );
		};

		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $filter, 10, 2 );
		try {
			return (array) wc_get_orders( $args );
		} finally {
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $filter, 10 );
		}
	}

	private static function add_legacy_meta_query( array $wp_query_args, array $query_vars, string $token ) : array {
		if ( $token !== (string) ( $query_vars['yogb_bm_compact_migration_query'] ?? '' ) ) {
			return $wp_query_args;
		}

		unset( $wp_query_args['yogb_bm_compact_migration_query'] );
		$existing_meta_query = (array) ( $wp_query_args['meta_query'] ?? [] );
		$migration_query     = self::eligible_meta_query();
		$wp_query_args['meta_query'] = empty( $existing_meta_query )
			? $migration_query
			: [
				'relation' => 'AND',
				$existing_meta_query,
				$migration_query,
			];

		return $wp_query_args;
	}

	private static function eligible_meta_query() : array {
		return [
			'relation' => 'AND',
			[
				'key'     => YOGB_BM_Check_Orders::META_DECISION_REF,
				'value'   => '',
				'compare' => '!=',
			],
			[
				'key'     => self::META_CHECKED,
				'compare' => 'NOT EXISTS',
			],
			[
				'relation' => 'OR',
				[
					'key'     => YOGB_BM_Check_Orders::META_RAW,
					'compare' => 'EXISTS',
				],
				[
					'key'     => YOGB_BM_Check_Orders::META_SIGNAL_SUMMARIES,
					'compare' => 'EXISTS',
				],
				[
					'key'     => YOGB_BM_Check_Orders::META_EFFECTIVE_SCORE,
					'compare' => 'EXISTS',
				],
			],
		];
	}

	private static function hpos_is_enabled() : bool {
		return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	private static function is_unchecked_verbose_order( WC_Order $order ) : bool {
		$meta_keys = [];
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$meta_keys[ (string) ( $data['key'] ?? '' ) ] = true;
		}

		if ( isset( $meta_keys[ self::META_CHECKED ] ) ) {
			return false;
		}

		foreach ( YOGB_BM_Check_Orders::legacy_verbose_meta_keys() as $meta_key ) {
			if ( isset( $meta_keys[ $meta_key ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function prepare_compact_fallback( WC_Order $order ) : void {
		if ( '' === (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_SUMMARY, true ) ) {
			foreach ( [ YOGB_BM_Check_Orders::META_REASON_SUMMARIES, YOGB_BM_Check_Orders::META_SIGNAL_SUMMARIES ] as $meta_key ) {
				$value = $order->get_meta( $meta_key, true );
				$line  = is_array( $value ) ? reset( $value ) : $value;
				if ( is_scalar( $line ) && '' !== trim( (string) $line ) ) {
					$order->update_meta_data(
						YOGB_BM_Check_Orders::META_DECISION_SUMMARY,
						substr( sanitize_text_field( (string) $line ), 0, 240 )
					);
					break;
				}
			}
		}
		if ( '' === (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_REASON_CODE, true ) ) {
			$reasons = $order->get_meta( YOGB_BM_Check_Orders::META_REASONS, true );
			$reason  = is_array( $reasons ) ? reset( $reasons ) : $reasons;
			if ( is_scalar( $reason ) ) {
				$order->update_meta_data(
					YOGB_BM_Check_Orders::META_DECISION_REASON_CODE,
					sanitize_key( (string) $reason )
				);
			}
		}
		$order->update_meta_data( YOGB_BM_Check_Orders::META_DETAIL_AVAILABLE, 1 );
	}

	private static function retain( WC_Order $order, string $reason ) : bool {
		if ( ! self::is_unchecked_verbose_order( $order ) ) {
			return false;
		}
		$order->update_meta_data( self::META_CHECKED, 'retained_' . substr( sanitize_key( $reason ), 0, 40 ) );
		$order->save_meta_data();
		return true;
	}

	private static function acquire_lock() : bool {
		$now = time();
		if ( add_option( self::OPT_LOCK, $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::OPT_LOCK, 0 );
		if ( $now - $locked_at < 10 * MINUTE_IN_SECONDS ) {
			return false;
		}

		global $wpdb;
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $now,
				self::OPT_LOCK,
				(string) $locked_at
			)
		);
		if ( 1 === $claimed ) {
			wp_cache_delete( self::OPT_LOCK, 'options' );
			return true;
		}

		return false;
	}

	private static function record_state( int $cleaned, int $retained, string $status ) : void {
		$state = (array) get_option(
			self::OPT_STATE,
			[
				'cleaned'  => 0,
				'retained' => 0,
			]
		);
		$state['cleaned']   = max( 0, (int) ( $state['cleaned'] ?? 0 ) ) + max( 0, $cleaned );
		$state['retained']  = max( 0, (int) ( $state['retained'] ?? 0 ) ) + max( 0, $retained );
		$state['status']    = sanitize_key( $status );
		$state['last_run']  = time();
		update_option( self::OPT_STATE, $state, false );
	}

	private static function supports_cleanup() : bool {
		return in_array(
			'decision_detail_availability_v1',
			(array) get_option( 'yogb_bm_server_capabilities', [] ),
			true
		);
	}

	private static function repair_pending_queue( bool $complete ) : void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
			if ( $complete ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		$pending = as_get_scheduled_actions(
			[
				'hook'     => self::HOOK,
				'args'     => [],
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => self::QUEUE_REPAIR_LIMIT + 1,
			],
			'ids'
		);
		$pending_count = count( $pending );
		if ( 0 === $pending_count || ( ! $complete && 1 === $pending_count ) ) {
			return;
		}

		$cancel_count = min( self::QUEUE_REPAIR_LIMIT, $pending_count );
		for ( $i = 0; $i < $cancel_count; $i++ ) {
			if ( ! as_unschedule_action( self::HOOK, [], self::GROUP ) ) {
				break;
			}
		}
	}

	private static function schedule( int $delay ) : void {
		$delay = max( 5, $delay );
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_next_scheduled_action( self::HOOK, [], self::GROUP ) ) {
				as_schedule_single_action( time() + $delay, self::HOOK, [], self::GROUP, true );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}
}

YOGB_BM_Compact_Meta_Migration::init();
