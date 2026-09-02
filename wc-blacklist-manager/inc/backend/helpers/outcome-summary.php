<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private, site-local protection outcome summary.
 *
 * This class and its adapter filter are internal implementation details. They
 * are deliberately not a supported third-party extension API.
 */
final class WC_Blacklist_Manager_Outcome_Summary {
	const ADAPTER_VERSION = 1;
	const STATE_OPTION    = 'wc_blacklist_manager_outcome_state_v1';
	const CACHE_OPTION    = 'wc_blacklist_manager_outcome_cache_v1';
	const ADAPTER_FILTER  = 'wc_blacklist_manager_internal_outcome_v1';
	const REVIEW_URL      = 'https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post';
	const HARD_CAP        = 10001;
	const CACHE_TTL       = 300;
	const MIN_PREMIUM_ADAPTER_VERSION = '2.6.4.4';

	private static $pending_manual = array();
	private static $flush_hooked   = false;
	private static $passive_reserved = false;

	private static $manual_keys = array(
		'main_created',
		'main_updated',
		'main_deleted',
		'address_created',
		'address_updated',
		'address_deleted',
		'order_suspect',
		'order_block',
		'order_remove',
	);

	public static function init() {
		add_action( 'wc_blacklist_manager_dashboard_row_changed', array( __CLASS__, 'record_dashboard_action' ), 10, 4 );
		add_action( 'wc_blacklist_manager_order_suspected', array( __CLASS__, 'record_order_suspect' ) );
		add_action( 'wc_blacklist_manager_order_blocked', array( __CLASS__, 'record_order_block' ) );
		add_action( 'wc_blacklist_manager_order_blacklist_removed', array( __CLASS__, 'record_order_remove' ) );
		add_action( 'admin_post_wc_blacklist_manager_outcome_acknowledge', array( __CLASS__, 'handle_acknowledge' ) );
		add_action( 'admin_post_wc_blacklist_manager_outcome_dismiss_review', array( __CLASS__, 'handle_dismiss_review' ) );
		add_action( 'admin_post_wc_blacklist_manager_outcome_complete_review', array( __CLASS__, 'handle_complete_review' ) );
	}

	public static function record_dashboard_action( $event, $id, $row, $record_type ) {
		$event       = sanitize_key( (string) $event );
		$record_type = 'address' === $record_type ? 'address' : 'main';
		$key         = $record_type . '_' . $event;

		if ( absint( $id ) <= 0 || ! in_array( $key, self::$manual_keys, true ) ) {
			return;
		}

		self::queue_manual_action( $key );
	}

	public static function record_order_suspect() {
		self::queue_manual_action( 'order_suspect' );
	}

	public static function record_order_block() {
		self::queue_manual_action( 'order_block' );
	}

	public static function record_order_remove() {
		self::queue_manual_action( 'order_remove' );
	}

	private static function queue_manual_action( $key ) {
		if ( ! in_array( $key, self::$manual_keys, true ) || ! self::current_user_can_manage() ) {
			return;
		}

		self::$pending_manual[ $key ] = isset( self::$pending_manual[ $key ] )
			? self::clamp_count( self::$pending_manual[ $key ] + 1 )
			: 1;

		if ( ! self::$flush_hooked ) {
			self::$flush_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_manual_actions' ), 1 );
		}
	}

	public static function flush_manual_actions() {
		$pending = self::$pending_manual;
		self::$pending_manual = array();

		if ( empty( $pending ) ) {
			return;
		}

		$date = self::site_date_key();
		self::mutate_state(
			static function ( array $state ) use ( $pending, $date ) {
				foreach ( $pending as $key => $increment ) {
					if ( ! in_array( $key, self::$manual_keys, true ) ) {
						continue;
					}

					$current = isset( $state['daily'][ $date ][ $key ] ) ? (int) $state['daily'][ $date ][ $key ] : 0;
					$state['daily'][ $date ][ $key ] = self::clamp_count( $current + (int) $increment );
				}

				return $state;
			}
		);
		self::invalidate_cache();
	}

	public static function is_dashboard_passive_reserved() {
		return self::$passive_reserved;
	}

	/**
	 * Return a bounded, read-only commercial projection of recorded manual work.
	 *
	 * The projection intentionally reuses the private outcome state and does not
	 * reinterpret manual actions as attacks, prevented fraud, or Premium results.
	 */
	public static function get_commercial_opportunity_model() {
		return self::commercial_opportunity_model( self::get_state() );
	}

	public static function render_card() {
		if ( ! self::current_user_can_manage() ) {
			return;
		}

		$window  = isset( $_GET['bm_outcome_window'] ) && 30 === absint( wp_unslash( $_GET['bm_outcome_window'] ) ) ? 30 : 7;
		$summary = self::get_summary();
		$state   = self::observe_first_value( $summary );
		$review  = false;

		if ( self::review_is_eligible( $state, $summary ) ) {
			$claim  = self::claim_review_render();
			$state  = $claim['state'];
			$review = $claim['render'];
		}

		$dashboard_url = admin_url( 'admin.php?page=wc-blacklist-manager' );
		$url_7         = add_query_arg( 'bm_outcome_window', 7, $dashboard_url );
		$url_30        = add_query_arg( 'bm_outcome_window', 30, $dashboard_url );
		$core          = $summary['core'];
		$premium       = $summary['premium'];
		$is_capped     = ! empty( $core['capped'] ) || ! empty( $premium['capped'] );
		$period_label  = 7 === $window ? __( '7 calendar days', 'wc-blacklist-manager' ) : __( '30 calendar days', 'wc-blacklist-manager' );

		echo '<section class="yobm-outcome-summary" aria-labelledby="yobm-outcome-summary-title">';
		echo '<div class="yobm-outcome-summary__heading"><div><p class="yobm-outcome-summary__eyebrow">' . esc_html__( 'Site-local evidence', 'wc-blacklist-manager' ) . '</p><h2 id="yobm-outcome-summary-title">' . esc_html__( 'Protection overview', 'wc-blacklist-manager' ) . '</h2></div>';
		echo '<nav class="yobm-outcome-summary__windows" aria-label="' . esc_attr__( 'Summary period', 'wc-blacklist-manager' ) . '">';
		echo '<a class="button ' . ( 7 === $window ? 'button-primary' : 'button-secondary' ) . '" href="' . esc_url( $url_7 ) . '">' . esc_html__( '7 days', 'wc-blacklist-manager' ) . '</a>';
		echo '<a class="button ' . ( 30 === $window ? 'button-primary' : 'button-secondary' ) . '" href="' . esc_url( $url_30 ) . '">' . esc_html__( '30 days', 'wc-blacklist-manager' ) . '</a></nav></div>';

		if ( empty( $core['available'] ) ) {
			echo '<p class="notice inline notice-warning"><span>' . esc_html__( 'Retained list counts are temporarily unavailable until the required database indexes are installed.', 'wc-blacklist-manager' ) . '</span></p>';
		}
		echo '<div class="yobm-outcome-summary__overview">';
		echo '<section class="yobm-outcome-summary__column"><h3>' . esc_html__( 'Current state', 'wc-blacklist-manager' ) . '</h3><div class="yobm-outcome-summary__groups">';
		if ( ! empty( $core['available'] ) ) {
			self::render_group(
				__( 'Retained list records', 'wc-blacklist-manager' ),
				array(
					__( 'Currently blocked', 'wc-blacklist-manager' ) => $core['blocked'][ $window ],
					__( 'Currently suspect', 'wc-blacklist-manager' ) => $core['suspect'][ $window ],
					__( 'Address rules', 'wc-blacklist-manager' ) => $core['address'][ $window ],
				),
				$is_capped
			);
		}
		self::render_global_status();
		echo '</div></section>';
		echo '<section class="yobm-outcome-summary__column"><h3>' . esc_html__( 'Recent outcomes', 'wc-blacklist-manager' ) . '</h3><div class="yobm-outcome-summary__groups">';
		self::render_group(
			__( 'Manual protection actions', 'wc-blacklist-manager' ),
			array( __( 'Recorded actions', 'wc-blacklist-manager' ) => self::manual_count( $state, $window ) ),
			false
		);

		if ( ! empty( $premium['available'] ) ) {
			self::render_group(
				__( 'Premium retained events', 'wc-blacklist-manager' ),
				array(
					__( 'Automated protection', 'wc-blacklist-manager' ) => $premium['protection'][ $window ],
					__( 'Automated suspect', 'wc-blacklist-manager' ) => $premium['suspect'][ $window ],
					__( 'Completed verification', 'wc-blacklist-manager' ) => $premium['verification'][ $window ],
				),
				! empty( $premium['capped'] ) || empty( $premium[ 'complete_' . $window ] )
			);
		}
		echo '</div></section></div>';

		$tracking_date = wp_date( get_option( 'date_format' ), (int) $state['tracking_started_at'], wp_timezone() );
		echo '<p class="description yobm-outcome-summary__coverage">' . esc_html( sprintf( __( '%1$s. Manual actions tracked since %2$s; retained records and events can be partial after deletion or retention.', 'wc-blacklist-manager' ), $period_label, $tracking_date ) ) . '</p>';

		if ( ! empty( $summary['schema_ready'] ) && ! empty( $state['first_value'] ) && empty( $state['first_value']['acknowledged_at'] ) ) {
			self::render_first_value_acknowledgement( $state['first_value'] );
		} elseif ( ! empty( $summary['schema_ready'] ) && $review ) {
			self::$passive_reserved = true;
			self::render_review_request();
		}

		self::render_commercial_opportunity( self::commercial_opportunity_model( $state ) );

		echo '<p class="yobm-outcome-summary__links"><a href="' . esc_url( $dashboard_url . '#blacklisted' ) . '">' . esc_html__( 'Review local lists', 'wc-blacklist-manager' ) . '</a>';
		if ( function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available() && self::current_user_can_manage( true ) ) {
			echo ' <span aria-hidden="true">&middot;</span> <a href="' . esc_url( admin_url( 'admin.php?page=wc-blacklist-manager-activity-logs' ) ) . '">' . esc_html__( 'Review activity logs', 'wc-blacklist-manager' ) . '</a>';
		}
		echo '</p></section>';
	}

	private static function render_group( $title, array $rows, $partial ) {
		echo '<section class="yobm-outcome-summary__group"><h4>' . esc_html( $title ) . '</h4><dl>';
		foreach ( $rows as $label => $count ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . ( $partial ? esc_html__( 'at least', 'wc-blacklist-manager' ) . ' ' : '' ) . esc_html( number_format_i18n( self::clamp_count( $count ) ) ) . '</dd></div>';
		}
		echo '</dl></section>';
	}

	private static function render_global_status() {
		if ( ! class_exists( 'WC_Blacklist_Manager_Dashboard_Presentation' ) ) {
			return;
		}

		$model       = WC_Blacklist_Manager_Dashboard_Presentation::current_global_model();
		$state       = $model['state'];
		$tier        = $model['tier'];
		$action_url  = '';
		$action_text = '';

		if ( WC_Blacklist_Manager_Dashboard_Presentation::GLOBAL_INACTIVE === $state ) {
			$message     = __( 'Global Blacklist Decisions is inactive.', 'wc-blacklist-manager' );
			$status_text = __( 'Inactive', 'wc-blacklist-manager' );
			$icon        = 'shield-slash.svg';
			$icon_alt    = __( 'Global Blacklist Decisions disabled', 'wc-blacklist-manager' );
			$action_url  = wp_nonce_url( admin_url( 'admin-post.php?action=enable_global_blacklist' ), 'enable_global_blacklist' );
			$action_text = __( 'Enable', 'wc-blacklist-manager' );
		} elseif ( WC_Blacklist_Manager_Dashboard_Presentation::GLOBAL_DISCONNECTED === $state ) {
			$message     = __( 'Your site is disconnected from Global Blacklist Decisions.', 'wc-blacklist-manager' );
			$status_text = __( 'Needs attention', 'wc-blacklist-manager' );
			$icon        = 'shield-security-risk.svg';
			$icon_alt    = __( 'Global Blacklist Decisions disconnected', 'wc-blacklist-manager' );
			$action_url  = admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' );
			$action_text = __( 'Review', 'wc-blacklist-manager' );
		} else {
			$message     = __( 'Your site is protected by Global Blacklist Decisions.', 'wc-blacklist-manager' );
			$status_text = __( 'Protected', 'wc-blacklist-manager' );
			$icon        = 'globe-shield.svg';
			$icon_alt    = __( 'Global Blacklist Decisions enabled', 'wc-blacklist-manager' );
		}

		$tier_labels = array(
			'free'       => __( 'Free', 'wc-blacklist-manager' ),
			'basic'      => __( 'Basic', 'wc-blacklist-manager' ),
			'pro'        => __( 'Pro', 'wc-blacklist-manager' ),
			'enterprise' => __( 'Enterprise', 'wc-blacklist-manager' ),
		);

		echo '<section class="yobm-outcome-summary__group yobm-outcome-summary__global yobm-outcome-summary__global--' . esc_attr( $state ) . '"><div class="yobm-outcome-summary__global-heading"><h4>' . esc_html__( 'Global Blacklist Decisions', 'wc-blacklist-manager' ) . '</h4><div class="yobm-outcome-summary__global-status"><span class="yobm-status-badge">' . esc_html( $status_text ) . '</span>';
		if ( '' === $action_url ) {
			echo '<span class="yogb-tier-badge yogb-tier-' . esc_attr( $tier ) . '"><span class="yogb-tier-dot" aria-hidden="true"></span><span class="yogb-tier-text">' . esc_html( $tier_labels[ $tier ] ) . '</span></span>';
		}
		echo '</div></div>';
		echo '<p><img src="' . esc_url( plugins_url( 'img/' . $icon, WC_BLACKLIST_MANAGER_PLUGIN_FILE ) ) . '" width="16" height="16" alt="' . esc_attr( $icon_alt ) . '"> <span>' . esc_html( $message ) . '</span></p>';
		if ( '' !== $action_url ) {
			echo '<p class="yobm-outcome-summary__global-action"><a href="' . esc_url( $action_url ) . '">' . esc_html( $action_text ) . '</a></p>';
		}
		echo '</section>';
	}

	private static function render_first_value_acknowledgement( array $first_value ) {
		$copy = 'premium_verification_completed' === $first_value['source']
			? __( 'A completed verification has been recorded on this site.', 'wc-blacklist-manager' )
			: __( 'Protection activity has been recorded on this site.', 'wc-blacklist-manager' );

		echo '<div class="yobm-outcome-summary__prompt"><p><strong>' . esc_html__( 'First value recorded', 'wc-blacklist-manager' ) . '</strong> ' . esc_html( $copy ) . '</p>';
		self::render_action_form( 'wc_blacklist_manager_outcome_acknowledge', 'wc_blacklist_manager_outcome_acknowledge', __( 'Got it', 'wc-blacklist-manager' ), 'button button-secondary' );
		echo '</div>';
	}

	private static function render_review_request() {
		echo '<div class="yobm-outcome-summary__prompt yobm-outcome-summary__prompt--review"><p><strong>' . esc_html__( 'Has Blacklist Manager been useful?', 'wc-blacklist-manager' ) . '</strong> ' . esc_html__( 'A WordPress.org review helps others evaluate the plugin.', 'wc-blacklist-manager' ) . '</p><div class="yobm-outcome-summary__actions">';
		self::render_action_form( 'wc_blacklist_manager_outcome_complete_review', 'wc_blacklist_manager_outcome_complete_review', __( 'Review on WordPress.org', 'wc-blacklist-manager' ), 'button button-primary' );
		self::render_action_form( 'wc_blacklist_manager_outcome_dismiss_review', 'wc_blacklist_manager_outcome_dismiss_review', __( 'Don’t ask again', 'wc-blacklist-manager' ), 'button button-secondary' );
		echo '</div></div>';
	}

	private static function render_commercial_opportunity( array $model ) {
		if (
			self::$passive_reserved
			|| empty( $model['has_evidence'] )
			|| ( function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available() )
			|| ! class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
			|| ! WC_Blacklist_Manager_Opportunity_Engine::is_selected( 'premium.passive.dashboard.activity_logs' )
		) {
			return;
		}

		self::$passive_reserved = true;
		$url = admin_url( 'admin.php?page=wc-blacklist-manager-premium' );
		echo '<aside class="yobm-outcome-summary__opportunity" aria-label="' . esc_attr__( 'Advanced protection opportunity', 'wc-blacklist-manager' ) . '">';
		echo '<p><strong>' . esc_html( sprintf( _n( '%s manual protection action was recorded in the last 30 days.', '%s manual protection actions were recorded in the last 30 days.', $model['manual_actions'], 'wc-blacklist-manager' ), number_format_i18n( $model['manual_actions'] ) ) ) . '</strong> ';
		echo esc_html__( 'Advanced Protection can show recommendations based on this recorded site-local work.', 'wc-blacklist-manager' ) . '</p>';
		echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'See recommended advanced protection', 'wc-blacklist-manager' ) . '</a>';
		echo '</aside>';
	}

	private static function render_action_form( $action, $nonce_action, $label, $class ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce_action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function handle_acknowledge() {
		self::require_action( 'wc_blacklist_manager_outcome_acknowledge' );
		self::mutate_state(
			static function ( array $state ) {
				if ( ! empty( $state['first_value'] ) && empty( $state['first_value']['acknowledged_at'] ) ) {
					$state['first_value']['acknowledged_at'] = time();
				}
				return $state;
			}
		);
		self::redirect_dashboard();
	}

	public static function handle_dismiss_review() {
		self::require_action( 'wc_blacklist_manager_outcome_dismiss_review' );
		self::finish_review( 'dismissed' );
		self::redirect_dashboard();
	}

	public static function handle_complete_review() {
		self::require_action( 'wc_blacklist_manager_outcome_complete_review' );
		self::finish_review( 'completed' );
		wp_redirect( self::REVIEW_URL );
		exit;
	}

	private static function finish_review( $status ) {
		self::mutate_state(
			static function ( array $state ) use ( $status ) {
				if ( 'shown' === $state['review']['status'] ) {
					$state['review'] = array(
						'status'            => $status,
						$status . '_at'     => time(),
					);
				}
				return $state;
			}
		);
	}

	private static function require_action( $nonce_action ) {
		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'wc-blacklist-manager' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect_dashboard() {
		wp_safe_redirect( admin_url( 'admin.php?page=wc-blacklist-manager#yobm-outcome-summary-title' ) );
		exit;
	}

	public static function get_summary() {
		$cached = get_option( self::CACHE_OPTION, array() );
		if ( ! self::schema_is_ready() ) {
			if ( ! empty( $cached ) ) {
				self::invalidate_cache();
			}
			return self::normalize_summary(
				array(
					'schema_ready' => false,
					'core'         => self::empty_core_result(),
					'premium'      => self::empty_premium_result(),
				)
			);
		}

		$context = self::adapter_context();

		if ( is_array( $cached ) && ! empty( $cached['expires_at'] ) && (int) $cached['expires_at'] >= time() && ! empty( $cached['summary'] ) ) {
			$summary = self::normalize_summary( $cached['summary'] );
			$summary['schema_ready'] = true;
			// Re-run the adapter against sanitized cached metrics so volatile incident
			// gates are fresh without repeating its bounded log query.
			$summary['premium'] = self::apply_premium_adapter( $summary['premium'], $context );
			return $summary;
		}

		$summary = array(
			'schema_ready' => true,
			'core'         => self::read_core_counts( $context ),
			'premium'      => self::apply_premium_adapter( self::empty_premium_result(), $context ),
		);
		$summary = self::normalize_summary( $summary );

		update_option(
			self::CACHE_OPTION,
			array(
				'expires_at' => time() + self::CACHE_TTL,
				'summary'    => $summary,
			),
			false
		);

		return $summary;
	}

	/**
	 * Return the internal seven-day evidence snapshot used by the WordPress
	 * Dashboard widget. This deliberately bypasses all first-value, review, and
	 * commercial presentation lifecycle paths.
	 */
	public static function get_widget_snapshot() {
		$summary = self::get_summary();
		$state   = self::get_state();
		$context = self::calendar_context();
		$cutoff  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $context['cutoff_7'], wp_timezone() );

		return array(
			'schema_ready' => ! empty( $summary['schema_ready'] ),
			'core'         => $summary['core'],
			'premium'      => $summary['premium'],
			'manual'       => array(
				'count'               => self::manual_count( $state, 7 ),
				'tracking_started_at' => (int) $state['tracking_started_at'],
				'complete'            => $cutoff instanceof DateTimeImmutable
					&& (int) $state['tracking_started_at'] <= $cutoff->getTimestamp(),
			),
		);
	}

	private static function read_core_counts( array $context ) {
		$empty = self::empty_core_result();

		$main = self::read_indexed_rows( 'wc_blacklist', $context );
		$addresses = self::read_indexed_rows( 'wc_blacklist_addresses', $context );
		if ( empty( $main['available'] ) || empty( $addresses['available'] ) ) {
			return $empty;
		}

		$empty['available'] = true;
		foreach ( array_slice( $main['rows'], 0, self::effective_cap() - 1 ) as $row ) {
			$bucket = 1 === (int) $row['is_blocked'] ? 'blocked' : 'suspect';
			if ( $row['date_added'] >= $context['cutoff_30'] ) {
				$empty[ $bucket ][30]++;
			}
			if ( $row['date_added'] >= $context['cutoff_7'] ) {
				$empty[ $bucket ][7]++;
			}
		}
		foreach ( array_slice( $addresses['rows'], 0, self::effective_cap() - 1 ) as $row ) {
			if ( $row['date_added'] >= $context['cutoff_30'] ) {
				$empty['address'][30]++;
			}
			if ( $row['date_added'] >= $context['cutoff_7'] ) {
				$empty['address'][7]++;
			}
		}

		$empty['capped'] = ! empty( $main['capped'] ) || ! empty( $addresses['capped'] );
		$dates = array_merge( wp_list_pluck( $main['rows'], 'date_added' ), wp_list_pluck( $addresses['rows'], 'date_added' ) );
		if ( ! empty( $dates ) ) {
			sort( $dates );
			$empty['coverage_start'] = reset( $dates );
			$empty['coverage_end']   = end( $dates );
		}

		return $empty;
	}

	private static function empty_core_result() {
		return array(
			'available' => false,
			'blocked'   => array( 7 => 0, 30 => 0 ),
			'suspect'   => array( 7 => 0, 30 => 0 ),
			'address'   => array( 7 => 0, 30 => 0 ),
			'capped'    => false,
			'coverage_start' => '',
			'coverage_end'   => '',
		);
	}

	private static function schema_is_ready() {
		return class_exists( 'WC_Blacklist_Manager_Schema_Readiness' )
			&& WC_Blacklist_Manager_Schema_Readiness::is_ready();
	}

	private static function read_indexed_rows( $suffix, array $context ) {
		global $wpdb;
		$contract_id = 'wc_blacklist_addresses' === $suffix ? 'address_outcome' : 'blacklist_outcome';
		if (
			! class_exists( 'WC_Blacklist_Manager_Schema_Readiness' )
			|| ! WC_Blacklist_Manager_Schema_Readiness::is_ready()
			|| ! WC_Blacklist_Manager_Schema_Readiness::index_matches( $contract_id )
		) {
			return array( 'available' => false, 'rows' => array(), 'capped' => false );
		}

		$table = $wpdb->prefix . $suffix;

		$limit = self::effective_cap();
		$sql   = $wpdb->prepare(
			"SELECT date_added,is_blocked FROM `{$table}` FORCE INDEX (outcome_date_status) WHERE date_added >= %s AND date_added < %s ORDER BY date_added DESC LIMIT %d",
			$context['cutoff_30'],
			$context['end'],
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array(
			'available' => is_array( $rows ),
			'rows'      => is_array( $rows ) ? $rows : array(),
			'capped'    => is_array( $rows ) && count( $rows ) === $limit,
		);
	}

	private static function effective_cap() {
		if ( defined( 'WC_BLACKLIST_MANAGER_OUTCOME_TEST_CAP' ) && WC_BLACKLIST_MANAGER_OUTCOME_TEST_CAP > 1 ) {
			return min( self::HARD_CAP, (int) WC_BLACKLIST_MANAGER_OUTCOME_TEST_CAP );
		}
		return self::HARD_CAP;
	}

	private static function adapter_context() {
		$context = self::calendar_context();
		$context['version']             = self::ADAPTER_VERSION;
		$context['hard_cap']            = self::effective_cap();
		$context['tracking_started_at'] = (int) self::get_state()['tracking_started_at'];
		return $context;
	}

	/** Pure site-calendar boundary helper used by focused compatibility tests. */
	public static function calendar_context( $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$today     = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );
		$end       = $today->modify( '+1 day' );

		return array(
			'cutoff_7'  => $today->modify( '-6 days' )->format( 'Y-m-d H:i:s' ),
			'cutoff_30' => $today->modify( '-29 days' )->format( 'Y-m-d H:i:s' ),
			'end'       => $end->format( 'Y-m-d H:i:s' ),
		);
	}

	private static function apply_premium_adapter( array $result, array $context ) {
		$filtered = apply_filters( self::ADAPTER_FILTER, $result, $context );
		$filtered = self::normalize_premium_result( is_array( $filtered ) ? $filtered : array() );
		if ( ! function_exists( 'wc_blacklist_manager_is_premium_available' ) || ! wc_blacklist_manager_is_premium_available() ) {
			$incidents = $filtered['incidents'];
			$filtered  = self::empty_premium_result();
			$filtered['incidents'] = $incidents;
		}
		return $filtered;
	}

	public static function empty_premium_result() {
		return array(
			'version'       => self::ADAPTER_VERSION,
			'available'     => false,
			'metrics_ready' => false,
			'protection'    => array( 7 => 0, 30 => 0 ),
			'suspect'       => array( 7 => 0, 30 => 0 ),
			'verification'  => array( 7 => 0, 30 => 0 ),
			'coverage_start'=> '',
			'coverage_end'  => '',
			'complete_7'    => false,
			'complete_30'   => false,
			'capped'        => false,
			'incidents'     => array(),
		);
	}

	private static function normalize_summary( $summary ) {
		$core = isset( $summary['core'] ) && is_array( $summary['core'] ) ? $summary['core'] : array();
		foreach ( array( 'blocked', 'suspect', 'address' ) as $key ) {
			$core[ $key ] = array(
				7  => self::clamp_count( isset( $core[ $key ][7] ) ? $core[ $key ][7] : 0 ),
				30 => self::clamp_count( isset( $core[ $key ][30] ) ? $core[ $key ][30] : 0 ),
			);
		}
		$core['available'] = ! empty( $core['available'] );
		$core['capped']    = ! empty( $core['capped'] );
		$core['coverage_start'] = self::sanitize_mysql_date( isset( $core['coverage_start'] ) ? $core['coverage_start'] : '' );
		$core['coverage_end']   = self::sanitize_mysql_date( isset( $core['coverage_end'] ) ? $core['coverage_end'] : '' );

		return array(
			'schema_ready' => ! empty( $summary['schema_ready'] ),
			'core'         => $core,
			'premium'      => self::normalize_premium_result( isset( $summary['premium'] ) ? $summary['premium'] : array() ),
		);
	}

	private static function normalize_premium_result( $result ) {
		$normalized = self::empty_premium_result();
		if ( ! is_array( $result ) || self::ADAPTER_VERSION !== (int) ( isset( $result['version'] ) ? $result['version'] : 0 ) ) {
			return $normalized;
		}

		$normalized['available']     = ! empty( $result['available'] );
		$normalized['metrics_ready'] = ! empty( $result['metrics_ready'] );
		foreach ( array( 'protection', 'suspect', 'verification' ) as $key ) {
			$normalized[ $key ][7]  = self::clamp_count( isset( $result[ $key ][7] ) ? $result[ $key ][7] : 0 );
			$normalized[ $key ][30] = self::clamp_count( isset( $result[ $key ][30] ) ? $result[ $key ][30] : 0 );
		}
		$normalized['coverage_start'] = self::sanitize_mysql_date( isset( $result['coverage_start'] ) ? $result['coverage_start'] : '' );
		$normalized['coverage_end']   = self::sanitize_mysql_date( isset( $result['coverage_end'] ) ? $result['coverage_end'] : '' );
		$normalized['complete_7']     = ! empty( $result['complete_7'] );
		$normalized['complete_30']    = ! empty( $result['complete_30'] );
		$normalized['capped']         = ! empty( $result['capped'] );
		$allowed_incidents = array( 'premium_unlicensed', 'premium_setup_incomplete', 'premium_security_incident' );
		foreach ( isset( $result['incidents'] ) && is_array( $result['incidents'] ) ? $result['incidents'] : array() as $incident ) {
			$incident = sanitize_key( (string) $incident );
			if ( in_array( $incident, $allowed_incidents, true ) ) {
				$normalized['incidents'][] = $incident;
			}
		}
		$normalized['incidents'] = array_values( array_unique( $normalized['incidents'] ) );
		return $normalized;
	}

	private static function observe_first_value( array $summary ) {
		if ( empty( $summary['schema_ready'] ) ) {
			return self::get_state();
		}

		return self::mutate_state(
			static function ( array $state ) use ( $summary ) {
				if ( ! empty( $state['first_value'] ) ) {
					return $state;
				}

				$source = '';
				if ( self::clamp_count( get_option( 'wc_blacklist_sum_block_total', 0 ) ) > 0 ) {
					$source = 'core_enforcement_match_recorded';
				} elseif ( ! empty( $summary['premium']['available'] ) && ( $summary['premium']['protection'][7] > 0 || $summary['premium']['protection'][30] > 0 ) ) {
					$source = 'premium_enforcement_event_recorded';
				} elseif ( ! empty( $summary['premium']['available'] ) && ( $summary['premium']['verification'][7] > 0 || $summary['premium']['verification'][30] > 0 ) ) {
					$source = 'premium_verification_completed';
				}

				if ( $source ) {
					$state['first_value'] = array(
						'source'      => $source,
						'observed_at' => time(),
					);
				}

				return $state;
			}
		);
	}

	private static function review_is_eligible( array $state, array $summary ) {
		if ( empty( $summary['schema_ready'] ) || empty( $state['first_value']['acknowledged_at'] ) || 'unseen' !== $state['review']['status'] || ! self::current_user_can_manage() ) {
			return false;
		}
		if ( 'yes' !== get_user_meta( get_current_user_id(), 'wc_blacklist_manager_first_time_notice_dismissed', true ) ) {
			return false;
		}
		if ( ! empty( $summary['premium']['incidents'] ) || self::premium_recovery_gate_active() || self::global_disconnected() || self::global_quota_incident_active() ) {
			return false;
		}

		$winner = class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' ) ? WC_Blacklist_Manager_Opportunity_Engine::winner() : null;
		$id     = is_array( $winner ) && isset( $winner['id'] ) ? (string) $winner['id'] : '';
		if ( '' === $id ) {
			return true;
		}
		if ( 'premium.passive.dashboard.activity_logs' === $id ) {
			return true;
		}

		return false;
	}

	private static function claim_review_render() {
		$committed = false;
		$state     = self::mutate_state(
			static function ( array $candidate ) {
				if ( 'unseen' === $candidate['review']['status'] && ! empty( $candidate['first_value']['acknowledged_at'] ) ) {
					$candidate['review'] = array(
						'status'   => 'shown',
						'shown_at' => time(),
					);
				}

				return $candidate;
			},
			$committed
		);

		return array(
			'state'  => $state,
			'render' => $committed && 'shown' === $state['review']['status'] && ! empty( $state['review']['shown_at'] ),
		);
	}

	private static function global_quota_incident_active() {
		if ( ! class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' ) ) {
			return false;
		}

		return WC_Blacklist_Manager_Opportunity_Engine::is_global_quota_source_active();
	}

	private static function premium_recovery_gate_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' ) ) {
			return false;
		}
		if ( ! function_exists( 'wc_blacklist_manager_is_premium_available' ) || ! wc_blacklist_manager_is_premium_available() ) {
			return true;
		}
		$current = defined( 'WC_BLACKLIST_MANAGER_PREMIUM_VERSION' ) ? WC_BLACKLIST_MANAGER_PREMIUM_VERSION : '0';
		return version_compare( $current, self::MIN_PREMIUM_ADAPTER_VERSION, '<' ) || ! get_option( 'wc_blacklist_manager_premium_setup_completed_at' );
	}

	private static function global_disconnected() {
		if ( 1 !== (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 ) ) {
			return false;
		}
		return ! get_option( 'yogb_bm_api_key' ) || ! get_option( 'yogb_bm_api_secret' ) || ! get_option( 'yogb_bm_reporter_id' );
	}

	private static function manual_count( array $state, $window ) {
		$allowed_dates = self::allowed_date_keys();
		$take          = 7 === (int) $window ? 7 : 30;
		$total         = 0;
		foreach ( array_slice( $allowed_dates, 0, $take ) as $date ) {
			foreach ( isset( $state['daily'][ $date ] ) ? $state['daily'][ $date ] : array() as $key => $value ) {
				if ( in_array( $key, self::$manual_keys, true ) ) {
					$total = self::clamp_count( $total + (int) $value );
				}
			}
		}
		return $total;
	}

	/** Pure finite projection used by the internal Dashboard and Premium page. */
	private static function commercial_opportunity_model( array $state ) {
		$state       = self::normalize_state( $state );
		$allowed     = array_slice( self::allowed_date_keys(), 0, 30 );
		$list_keys   = array( 'main_created', 'main_updated', 'main_deleted', 'address_created', 'address_updated', 'address_deleted' );
		$order_keys  = array( 'order_suspect', 'order_block', 'order_remove' );
		$list_total  = 0;
		$order_total = 0;

		foreach ( $allowed as $date ) {
			$counts = isset( $state['daily'][ $date ] ) ? $state['daily'][ $date ] : array();
			foreach ( $list_keys as $key ) {
				$list_total = self::clamp_count( $list_total + ( isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0 ) );
			}
			foreach ( $order_keys as $key ) {
				$order_total = self::clamp_count( $order_total + ( isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0 ) );
			}
		}

		$total           = self::clamp_count( $list_total + $order_total );
		$recommendations = array();
		if ( $total > 0 ) {
			$recommendations[] = 'manual_work_automation';
			$recommendations[] = $order_total > 0 ? 'order_risk_scoring' : 'activity_history';
		}

		$context = self::calendar_context();
		$cutoff  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $context['cutoff_30'], wp_timezone() );

		return array(
			'has_evidence'        => $total > 0,
			'manual_actions'      => $total,
			'list_actions'        => $list_total,
			'order_decisions'     => $order_total,
			'tracking_started_at' => (int) $state['tracking_started_at'],
			'complete_30'         => $cutoff instanceof DateTimeImmutable
				&& (int) $state['tracking_started_at'] <= $cutoff->getTimestamp(),
			'recommendations'     => array_slice( $recommendations, 0, 2 ),
		);
	}

	private static function get_state() {
		return self::normalize_state( get_option( self::STATE_OPTION, array() ) );
	}

	private static function mutate_state( callable $mutation, &$committed = null ) {
		global $wpdb;
		$committed = false;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE_OPTION ) );
			if ( null === $raw ) {
				$initial = self::normalize_state( array() );
				$state   = self::normalize_state( $mutation( $initial ) );
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						self::STATE_OPTION,
						maybe_serialize( $state )
					)
				);
				if ( 1 === (int) $inserted ) {
					$committed = $state !== $initial;
					self::invalidate_option_cache( self::STATE_OPTION );
					return $state;
				}
				continue;
			}

			$state = self::normalize_state( maybe_unserialize( $raw ) );
			$next  = self::normalize_state( $mutation( $state ) );
			if ( $next === $state ) {
				return $state;
			}
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $next ),
					self::STATE_OPTION,
					$raw
				)
			);
			if ( 1 === (int) $updated ) {
				$committed = true;
				self::invalidate_option_cache( self::STATE_OPTION );
				return $next;
			}
		}

		if ( function_exists( 'yoohw_diagnostic_log' ) ) {
			yoohw_diagnostic_log( 'outcome_summary', 'state_cas_exhausted', array( 'attempts' => 5 ), 'warning' );
		}
		return self::get_state();
	}

	private static function normalize_state( $state ) {
		$state = is_array( $state ) ? $state : array();
		$now   = time();
		$out   = array(
			'version'             => 1,
			'tracking_started_at' => self::clamp_timestamp( isset( $state['tracking_started_at'] ) ? $state['tracking_started_at'] : $now ),
			'daily'               => array(),
			'first_value'         => array(),
			'review'              => array( 'status' => 'unseen' ),
		);

		$allowed_dates = self::allowed_date_keys();
		foreach ( isset( $state['daily'] ) && is_array( $state['daily'] ) ? $state['daily'] : array() as $date => $counts ) {
			if ( ! in_array( $date, $allowed_dates, true ) || ! is_array( $counts ) ) {
				continue;
			}
			foreach ( $counts as $key => $value ) {
				if ( in_array( $key, self::$manual_keys, true ) ) {
					$out['daily'][ $date ][ $key ] = self::clamp_count( $value );
				}
			}
		}

		$first_sources = array( 'core_enforcement_match_recorded', 'premium_enforcement_event_recorded', 'premium_verification_completed' );
		if ( isset( $state['first_value']['source'] ) && in_array( $state['first_value']['source'], $first_sources, true ) ) {
			$out['first_value'] = array(
				'source'      => $state['first_value']['source'],
				'observed_at' => self::clamp_timestamp( isset( $state['first_value']['observed_at'] ) ? $state['first_value']['observed_at'] : $now ),
			);
			foreach ( array( 'occurred_at', 'acknowledged_at' ) as $key ) {
				if ( ! empty( $state['first_value'][ $key ] ) ) {
					$out['first_value'][ $key ] = self::clamp_timestamp( $state['first_value'][ $key ] );
				}
			}
		}

		$review_status = isset( $state['review']['status'] ) ? $state['review']['status'] : 'unseen';
		if ( in_array( $review_status, array( 'unseen', 'shown', 'dismissed', 'completed' ), true ) ) {
			$out['review']['status'] = $review_status;
		}
		foreach ( array( 'shown_at', 'dismissed_at', 'completed_at' ) as $key ) {
			if ( ! empty( $state['review'][ $key ] ) ) {
				$out['review'][ $key ] = self::clamp_timestamp( $state['review'][ $key ] );
			}
		}

		return $out;
	}

	private static function allowed_date_keys() {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$dates = array();
		for ( $day = 0; $day <= 31; $day++ ) {
			$dates[] = $today->modify( '-' . $day . ' days' )->format( 'Y-m-d' );
		}
		return $dates;
	}

	private static function site_date_key() {
		return wp_date( 'Y-m-d', time(), wp_timezone() );
	}

	private static function clamp_count( $value ) {
		return min( PHP_INT_MAX, max( 0, (int) $value ) );
	}

	private static function clamp_timestamp( $value ) {
		return min( time() + DAY_IN_SECONDS, max( 1, (int) $value ) );
	}

	private static function sanitize_mysql_date( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
	}

	private static function current_user_can_manage( $require_premium = false ) {
		return function_exists( 'wc_blacklist_manager_user_can_manage_area' )
			? wc_blacklist_manager_user_can_manage_area( 'wc_blacklist_dashboard_permission', $require_premium )
			: current_user_can( 'manage_options' );
	}

	public static function invalidate_cache() {
		delete_option( self::CACHE_OPTION );
	}

	private static function invalidate_option_cache( $option ) {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}

WC_Blacklist_Manager_Outcome_Summary::init();
