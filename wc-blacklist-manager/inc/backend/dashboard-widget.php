<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core-owned executive summary for the standard WordPress Dashboard.
 *
 * This class is an internal presentation surface, not a supported extension
 * API. Premium enrichment arrives only through the existing outcome adapter.
 */
final class WC_Blacklist_Manager_Dashboard_Widget {
	const WIDGET_ID = 'wc_blacklist_manager_protection_status';
	const WOOCOMMERCE_WIDGET_ID = 'woocommerce_dashboard_status';
	const STATUS_PROTECTED = 'protected';
	const STATUS_ATTENTION = 'needs-attention';
	const STATUS_LIMITED   = 'reporting-limited';

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'get_user_option_meta-box-order_dashboard', array( __CLASS__, 'order_after_woocommerce' ), 10, 3 );
	}

	public static function register() {
		if ( ! self::current_user_can_view() || ! function_exists( 'wp_add_dashboard_widget' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			esc_html__( 'Blacklist Manager', 'wc-blacklist-manager' ),
			array( __CLASS__, 'render' ),
			null,
			null,
			'normal',
			'high'
		);

		self::place_registered_widget_after_woocommerce();
	}

	/** Keep personalized Dashboard layouts stable while placing this widget after WooCommerce Status. */
	public static function order_after_woocommerce( $order, $option, $user ) {
		if ( ! is_array( $order ) || ! self::current_user_can_view() ) {
			return $order;
		}

		$target_context = null;
		foreach ( $order as $context => $widget_ids ) {
			$ids = array_filter( array_map( 'trim', explode( ',', (string) $widget_ids ) ) );
			if ( in_array( self::WOOCOMMERCE_WIDGET_ID, $ids, true ) ) {
				$target_context = $context;
				break;
			}
		}

		if ( null === $target_context ) {
			return $order;
		}

		foreach ( $order as $context => $widget_ids ) {
			$ids = array_values( array_diff( array_filter( array_map( 'trim', explode( ',', (string) $widget_ids ) ) ), array( self::WIDGET_ID ) ) );
			if ( $context === $target_context ) {
				$woocommerce_position = array_search( self::WOOCOMMERCE_WIDGET_ID, $ids, true );
				array_splice( $ids, $woocommerce_position + 1, 0, array( self::WIDGET_ID ) );
			}
			$order[ $context ] = implode( ',', $ids );
		}

		return $order;
	}

	private static function place_registered_widget_after_woocommerce() {
		global $wp_meta_boxes;

		if (
			empty( $wp_meta_boxes['dashboard']['normal']['high'][ self::WOOCOMMERCE_WIDGET_ID ] )
			|| empty( $wp_meta_boxes['dashboard']['normal']['high'][ self::WIDGET_ID ] )
		) {
			return;
		}

		$boxes   = $wp_meta_boxes['dashboard']['normal']['high'];
		$widget  = $boxes[ self::WIDGET_ID ];
		$ordered = array();
		unset( $boxes[ self::WIDGET_ID ] );

		foreach ( $boxes as $id => $box ) {
			$ordered[ $id ] = $box;
			if ( self::WOOCOMMERCE_WIDGET_ID === $id ) {
				$ordered[ self::WIDGET_ID ] = $widget;
			}
		}

		$wp_meta_boxes['dashboard']['normal']['high'] = $ordered;
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix || ! self::current_user_can_view() ) {
			return;
		}

		wp_enqueue_style(
			'wc-blacklist-manager-dashboard-widget',
			plugins_url( 'css/dashboard-widget.css', WC_BLACKLIST_MANAGER_PLUGIN_FILE ),
			array(),
			WC_BLACKLIST_MANAGER_VERSION
		);
	}

	public static function render() {
		if ( ! self::current_user_can_view() ) {
			return;
		}

		$model = self::build_model(
			WC_Blacklist_Manager_Outcome_Summary::get_widget_snapshot(),
			self::current_context()
		);
		self::render_model( $model );
	}

	/** Render a normalized internal model without performing any data reads. */
	public static function render_model( array $model ) {
		echo '<div class="yobm-dashboard-widget yobm-dashboard-widget--' . esc_attr( $model['status'] ) . '">';
		echo '<section class="yobm-dashboard-widget__status" aria-labelledby="yobm-dashboard-widget-status">';
		echo '<p class="yobm-dashboard-widget__eyebrow">' . esc_html__( 'Protection status', 'wc-blacklist-manager' ) . '</p>';
		echo '<h3 id="yobm-dashboard-widget-status">' . esc_html( $model['status_label'] ) . '</h3>';
		echo '<p>' . esc_html( $model['status_copy'] ) . '</p></section>';

		echo '<section class="yobm-dashboard-widget__evidence" aria-labelledby="yobm-dashboard-widget-period">';
		echo '<h3 id="yobm-dashboard-widget-period">' . esc_html__( 'Last 7 days', 'wc-blacklist-manager' ) . '</h3>';
		echo '<dl class="yobm-dashboard-widget__metrics">';
		foreach ( $model['core_metrics'] as $metric ) {
			self::render_metric( $metric );
		}
		echo '</dl>';

		if ( ! empty( $model['premium_metrics'] ) ) {
			echo '<div class="yobm-dashboard-widget__premium">';
			echo '<p class="yobm-dashboard-widget__premium-label">' . esc_html__( 'Premium evidence', 'wc-blacklist-manager' ) . '</p><dl>';
			foreach ( $model['premium_metrics'] as $metric ) {
				self::render_metric( $metric );
			}
			echo '</dl></div>';
		}
		echo '</section>';

		if ( ! empty( $model['attention'] ) ) {
			$attention = $model['attention'];
			echo '<section class="yobm-dashboard-widget__attention" aria-labelledby="yobm-dashboard-widget-attention">';
			echo '<h3 id="yobm-dashboard-widget-attention">' . esc_html( $attention['title'] ) . '</h3>';
			echo '<p>' . esc_html( $attention['copy'] ) . '</p>';
			if ( ! empty( $attention['url'] ) ) {
				echo '<p><a href="' . esc_url( $attention['url'] ) . '">' . esc_html( $attention['action'] ) . '</a></p>';
			}
			echo '</section>';
		} else {
			echo '<p class="yobm-dashboard-widget__no-action">' . esc_html__( 'No action required.', 'wc-blacklist-manager' ) . '</p>';
		}

		echo '<p class="yobm-dashboard-widget__footer">';
		foreach ( $model['links'] as $index => $link ) {
			if ( $index > 0 ) {
				echo ' <span aria-hidden="true">&middot;</span> ';
			}
			echo '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Build a finite presentation model from already-normalized internal facts.
	 */
	public static function build_model( array $snapshot, array $context ) {
		$core             = isset( $snapshot['core'] ) && is_array( $snapshot['core'] ) ? $snapshot['core'] : array();
		$premium          = isset( $snapshot['premium'] ) && is_array( $snapshot['premium'] ) ? $snapshot['premium'] : array();
		$manual           = isset( $snapshot['manual'] ) && is_array( $snapshot['manual'] ) ? $snapshot['manual'] : array();
		$premium_active   = ! empty( $context['premium_active'] );
		$core_available   = ! empty( $snapshot['schema_ready'] ) && ! empty( $core['available'] );
		$premium_available = ! $premium_active || ( ! empty( $premium['available'] ) && ! empty( $premium['metrics_ready'] ) );
		$dashboard_url    = isset( $context['dashboard_url'] ) ? (string) $context['dashboard_url'] : '';
		$attention        = null;

		if ( ! $core_available || ! $premium_available ) {
			$attention = array(
				'id'     => 'reporting_unavailable',
				'title'  => __( 'Protection reporting is limited', 'wc-blacklist-manager' ),
				'copy'   => __( 'Recent site-local evidence is not fully available. This reporting state does not mean protection is disabled.', 'wc-blacklist-manager' ),
				'action' => __( 'Review status', 'wc-blacklist-manager' ),
				'url'    => $dashboard_url,
			);
		}

		$incidents = isset( $premium['incidents'] ) && is_array( $premium['incidents'] ) ? $premium['incidents'] : array();
		if ( null === $attention && $premium_active && ! empty( $context['can_admin'] ) && in_array( 'premium_security_incident', $incidents, true ) ) {
			$attention = array(
				'id'     => 'premium_security_incident',
				'title'  => __( 'Suspicious activity needs review', 'wc-blacklist-manager' ),
				'copy'   => __( 'Premium recorded a current security incident that needs administrator review.', 'wc-blacklist-manager' ),
				'action' => __( 'Review activity', 'wc-blacklist-manager' ),
				'url'    => ! empty( $context['can_activity'] ) ? (string) $context['activity_url'] : $dashboard_url,
			);
		}

		if ( null === $attention && ! empty( $context['premium_installed'] ) && ! empty( $context['can_admin'] ) && in_array( 'premium_unlicensed', $incidents, true ) ) {
			$attention = array(
				'id'     => 'premium_unlicensed',
				'title'  => __( 'Premium license needs review', 'wc-blacklist-manager' ),
				'copy'   => __( 'The active Premium add-on is unavailable until its license condition is resolved.', 'wc-blacklist-manager' ),
				'action' => __( 'Review license', 'wc-blacklist-manager' ),
				'url'    => (string) $context['premium_setup_url'],
			);
		}

		$global = isset( $context['global'] ) && is_array( $context['global'] ) ? $context['global'] : array();
		if ( null === $attention && isset( $global['state'] ) && WC_Blacklist_Manager_Dashboard_Presentation::GLOBAL_DISCONNECTED === $global['state'] ) {
			$attention = array(
				'id'     => 'global_disconnected',
				'title'  => __( 'Global decisions need connection review', 'wc-blacklist-manager' ),
				'copy'   => ! empty( $context['can_settings'] )
					? __( 'Global Blacklist Decisions is enabled but its connection is incomplete.', 'wc-blacklist-manager' )
					: __( 'Global Blacklist Decisions is enabled but needs administrator review.', 'wc-blacklist-manager' ),
				'action' => __( 'Review connection', 'wc-blacklist-manager' ),
				'url'    => ! empty( $context['can_settings'] ) ? (string) $context['global_settings_url'] : $dashboard_url,
			);
		}

		if ( null === $attention && $premium_active && ! empty( $context['can_settings'] ) && in_array( 'premium_setup_incomplete', $incidents, true ) ) {
			$attention = array(
				'id'     => 'premium_setup_incomplete',
				'title'  => __( 'Premium setup is incomplete', 'wc-blacklist-manager' ),
				'copy'   => __( 'Complete the Premium setup workflow to finish configuring the installed add-on.', 'wc-blacklist-manager' ),
				'action' => __( 'Review setup', 'wc-blacklist-manager' ),
				'url'    => (string) $context['premium_setup_url'],
			);
		}

		$status = null !== $attention ? self::STATUS_ATTENTION : self::STATUS_PROTECTED;
		if ( is_array( $attention ) && 'reporting_unavailable' === $attention['id'] ) {
			$status = self::STATUS_LIMITED;
		}

		$status_labels = array(
			self::STATUS_PROTECTED => __( 'Protected', 'wc-blacklist-manager' ),
			self::STATUS_ATTENTION => __( 'Needs attention', 'wc-blacklist-manager' ),
			self::STATUS_LIMITED   => __( 'Reporting limited', 'wc-blacklist-manager' ),
		);
		$status_copies = array(
			self::STATUS_PROTECTED => __( 'No supported Blacklist Manager condition currently needs review.', 'wc-blacklist-manager' ),
			self::STATUS_ATTENTION => __( 'One supported condition needs administrator review.', 'wc-blacklist-manager' ),
			self::STATUS_LIMITED   => __( 'Recent evidence is incomplete; this does not indicate that enforcement is disabled.', 'wc-blacklist-manager' ),
		);

		$core_qualifier = ! $core_available ? 'unavailable' : ( ! empty( $core['capped'] ) ? 'at-least' : 'exact' );
		$core_metrics = array(
			self::metric( 'manual', __( 'Protection actions', 'wc-blacklist-manager' ), isset( $manual['count'] ) ? $manual['count'] : 0, ! empty( $manual['complete'] ) ? 'exact' : 'partial' ),
			self::metric( 'suspects', __( 'Suspects', 'wc-blacklist-manager' ), isset( $core['suspect'][7] ) ? $core['suspect'][7] : 0, $core_qualifier ),
			self::metric( 'blocked', __( 'Blocked', 'wc-blacklist-manager' ), isset( $core['blocked'][7] ) ? $core['blocked'][7] : 0, $core_qualifier ),
		);

		$premium_metrics = array();
		if ( $premium_active ) {
			$premium_qualifier = ! $premium_available
				? 'unavailable'
				: ( ! empty( $premium['capped'] ) ? 'at-least' : ( ! empty( $premium['complete_7'] ) ? 'exact' : 'partial' ) );
			$premium_metrics = array(
				self::metric( 'automated-protection', __( 'Automated protection', 'wc-blacklist-manager' ), isset( $premium['protection'][7] ) ? $premium['protection'][7] : 0, $premium_qualifier ),
				self::metric( 'verified', __( 'Verified', 'wc-blacklist-manager' ), isset( $premium['verification'][7] ) ? $premium['verification'][7] : 0, $premium_qualifier ),
			);
		}

		$links = array(
			array( 'label' => __( 'View Blacklist Manager', 'wc-blacklist-manager' ), 'url' => $dashboard_url ),
		);
		if ( $premium_active && ! empty( $context['can_activity'] ) ) {
			$links[] = array( 'label' => __( 'Activity Logs', 'wc-blacklist-manager' ), 'url' => (string) $context['activity_url'] );
		}

		return array(
			'status'          => $status,
			'status_label'    => $status_labels[ $status ],
			'status_copy'     => $status_copies[ $status ],
			'core_metrics'    => $core_metrics,
			'premium_metrics' => $premium_metrics,
			'attention'       => $attention,
			'links'           => $links,
		);
	}

	private static function render_metric( array $metric ) {
		echo '<div class="yobm-dashboard-widget__metric yobm-dashboard-widget__metric--' . esc_attr( $metric['key'] ) . '"><dt>' . esc_html( $metric['label'] ) . '</dt><dd>';
		if ( 'unavailable' === $metric['qualifier'] ) {
			echo '<span class="yobm-dashboard-widget__value" aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Unavailable', 'wc-blacklist-manager' ) . '</span>';
		} else {
			echo '<span class="yobm-dashboard-widget__value">' . ( 'at-least' === $metric['qualifier'] ? '<span aria-hidden="true">&ge;</span><span class="screen-reader-text">' . esc_html__( 'At least', 'wc-blacklist-manager' ) . ' </span>' : '' ) . esc_html( number_format_i18n( $metric['value'] ) ) . '</span>';
			if ( 'partial' === $metric['qualifier'] ) {
				echo '<span class="yobm-dashboard-widget__qualifier">' . esc_html__( 'Partial', 'wc-blacklist-manager' ) . '</span>';
			}
		}
		echo '</dd></div>';
	}

	private static function metric( $key, $label, $value, $qualifier ) {
		return array(
			'key'       => sanitize_key( (string) $key ),
			'label'     => (string) $label,
			'value'     => min( PHP_INT_MAX, max( 0, (int) $value ) ),
			'qualifier' => in_array( $qualifier, array( 'exact', 'at-least', 'partial', 'unavailable' ), true ) ? $qualifier : 'unavailable',
		);
	}

	private static function current_context() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();

		return array(
			'premium_active'     => $premium_active,
			'premium_installed'  => function_exists( 'is_plugin_active' ) && is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' ),
			'can_admin'          => current_user_can( 'manage_options' ),
			'can_settings'       => self::current_user_can_manage_area( 'wc_blacklist_settings_permission' ),
			'can_activity'       => $premium_active && self::current_user_can_manage_area( 'wc_blacklist_settings_permission', true ),
			'global'             => WC_Blacklist_Manager_Dashboard_Presentation::current_global_model(),
			'dashboard_url'      => admin_url( 'admin.php?page=wc-blacklist-manager' ),
			'activity_url'       => admin_url( 'admin.php?page=wc-blacklist-manager-activity-logs' ),
			'global_settings_url'=> admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' ),
			'premium_setup_url'  => admin_url( 'admin.php?page=wc-blacklist-manager-setup&step=license' ),
		);
	}

	private static function current_user_can_manage_area( $option, $require_premium = false ) {
		return function_exists( 'wc_blacklist_manager_user_can_manage_area' )
			? wc_blacklist_manager_user_can_manage_area( $option, $require_premium )
			: current_user_can( 'manage_options' );
	}

	private static function current_user_can_view() {
		return self::current_user_can_manage_area( 'wc_blacklist_dashboard_permission' );
	}
}

WC_Blacklist_Manager_Dashboard_Widget::init();
