<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WC_Blacklist_Manager_Activity_Log_Table extends WP_List_Table {

	protected $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'wc_blacklist_detection_log';

		parent::__construct( [
			'singular' => 'activity_log',
			'plural'   => 'activity_logs',
			'ajax'     => false,
		] );
	}

	public function get_columns() {
		return [
			'cb'        => '<input type="checkbox" />',
			'timestamp' => __( 'Timestamp', 'wc-blacklist-manager' ),
			'type'      => __( 'Type', 'wc-blacklist-manager' ),
			'source'    => __( 'Source', 'wc-blacklist-manager' ),
			'action'    => __( 'Action', 'wc-blacklist-manager' ),
			'details'   => __( 'Details', 'wc-blacklist-manager' ),
			'view'      => __( 'View', 'wc-blacklist-manager' ),
		];
	}

	public function get_sortable_columns() {
		// We only sort by timestamp (DESC default).
		return [
			'timestamp' => [ 'timestamp', true ],
		];
	}

	public function get_bulk_actions() {
		// Keep the key as "delete" so your existing handler sees $_POST['action'] === 'delete'.
		return [
			'delete' => __( 'Delete', 'wc-blacklist-manager' ),
		];
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="bulk_ids[]" value="%d" />',
			absint( $item->id )
		);
	}

	public function no_items() {
		esc_html_e( 'No detection log entries found.', 'wc-blacklist-manager' );
	}

	public function column_timestamp( $item ) {
		return esc_html(
			date_i18n(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $item->timestamp )
			)
		);
	}

	public function column_type( $item ) {
		$type = $item->type;
		if ( 'human' === $type ) {
			$src = plugins_url( '../../../../img/spy.svg', __FILE__ );
			return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr__( 'Human', 'wc-blacklist-manager' ) . '" width="16">';
		}
		if ( 'bot' === $type ) {
			$src = plugins_url( '../../../../img/user-robot.svg', __FILE__ );
			return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr__( 'Bot', 'wc-blacklist-manager' ) . '" width="16">';
		}
		return esc_html( $type );
	}

	public function column_source( $item ) {
		$source   = sanitize_key( (string) $item->source );
		$img_html = '';
		$text     = $source;
		$link     = '';

		$checkout_sources = [
			'woo_checkout'            => __( 'Woo checkout', 'wc-blacklist-manager' ),
			'woo_api_checkout'        => __( 'Woo Store API checkout', 'wc-blacklist-manager' ),
			'woo_store_api'           => __( 'Woo Store API', 'wc-blacklist-manager' ),
			'woo_store_api_checkout'  => __( 'Woo Store API checkout', 'wc-blacklist-manager' ),
		];

		if ( isset( $checkout_sources[ $source ] ) ) {
			$img_url  = plugins_url( '../../../../img/woo.svg', __FILE__ );
			$img_html = '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr__( 'WooCommerce', 'wc-blacklist-manager' ) . '" width="16">';
			$text     = $checkout_sources[ $source ];
		} elseif ( preg_match( '/^(woo|cf7|gravity|wpforms)_(.+)$/', $source, $m ) ) {
			$prefix    = $m[1];
			$remainder = $m[2];
			$map       = [
				'woo'     => 'woo.svg',
				'cf7'     => 'cf7.svg',
				'gravity' => 'gravity.svg',
				'wpforms' => 'wpforms.svg',
			];
			if ( isset( $map[ $prefix ] ) ) {
				$img_url  = plugins_url( '../../../../img/' . $map[ $prefix ], __FILE__ );
				$img_html = '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( ucfirst( __( $prefix, 'wc-blacklist-manager' ) ) ) . '" width="16">';
			}

			if ( 'woo' === $prefix && preg_match( '/^order_(\d+)$/', $remainder, $idm ) ) {
				$order_id = absint( $idm[1] );
				$edit_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
				$text     = 'Order&nbsp;';
				$link     = '<a href="' . esc_url( $edit_url ) . '" target="_blank">#' . esc_html( $order_id ) . '</a>';
			} else {
				$text = ucfirst( str_replace( '_', ' ', $remainder ) );
			}

		} elseif ( in_array( $source, [ 'access', 'register', 'login', 'checkout', 'submit', 'order', 'comment' ], true ) ) {
			$img_url  = plugins_url( '../../../../img/site.svg', __FILE__ );
			$label    = ucfirst( __( $source, 'wc-blacklist-manager' ) );
			$img_html = '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $label ) . '" width="16">';
			$text     = $label;
		} else {
			$text = ucfirst( __( str_replace( '_', ' ', $source ), 'wc-blacklist-manager' ) );
		}

		return $img_html . ' ' . esc_html( $text ) . $link;
	}

	public function column_action( $item ) {
		$action = $item->action;
		if ( 'block' === $action ) {
			return '<span class="bm-status-block">' . esc_html__( 'Block', 'wc-blacklist-manager' ) . '</span>';
		}
		if ( 'suspect' === $action ) {
			return '<span class="bm-status-suspect">' . esc_html__( 'Suspect', 'wc-blacklist-manager' ) . '</span>';
		}
		if ( 'verify' === $action ) {
			return '<span class="bm-status-verify">' . esc_html__( 'Verify', 'wc-blacklist-manager' ) . '</span>';
		}
		if ( 'rate_limit' === $action ) {
			return '<span class="bm-status-block">' . esc_html__( 'Rate limit', 'wc-blacklist-manager' ) . '</span>';
		}
		if ( 'remove' === $action ) {
			return '<span class="bm-status-verify">' . esc_html__( 'Remove', 'wc-blacklist-manager' ) . '</span>';
		}
		if ( 'unblock' === $action ) {
			return '<span class="bm-status-verify">' . esc_html__( 'Unblock', 'wc-blacklist-manager' ) . '</span>';
		}
		return esc_html( $action );
	}

	public function column_details( $item ) {
		$structured = $this->structured_details_summary( $item );
		if ( '' !== $structured ) {
			return $structured;
		}

		$entries = preg_split( '/,\s(?=\w+:)/', (string) $item->details );
		$out     = [];

		foreach ( (array) $entries as $entry ) {
			list( $raw_key, $value ) = array_map( 'trim', explode( ':', $entry, 2 ) + [ '', '' ] );

			$key = str_replace( [ 'suspected_', 'blocked_', 'verified_', '_attempt' ], '', $raw_key );
			$label = ucfirst( str_replace( '_', ' ', $key ) );

			if ( 'user' === $key && is_numeric( $value ) ) {
				$user_id = absint( $value );
				$user    = get_userdata( $user_id );
				if ( $user ) {
					$edit_url = esc_url( admin_url( "user-edit.php?user_id={$user_id}" ) );
					$out[]    = "{$label}: <a href=\"{$edit_url}\" target=\"_blank\">" . esc_html( $user->user_login ) . '</a>';
					continue;
				}
			}

			$out[] = "{$label}: " . esc_html( $value );
		}

		return implode( ', ', $out );
	}

	private function structured_details_summary( $item ): string {
		$view = json_decode( (string) ( $item->view ?? '' ), true );

		if ( is_array( $view ) ) {
			$schema = sanitize_key( (string) ( $view['schema'] ?? '' ) );
			$mode   = sanitize_key( (string) ( $view['mode'] ?? '' ) );

			if ( 'bmp_antibot_risk_v1' === $schema ) {
				return $this->antibot_summary_from_view( $view );
			}

			if ( 'store_api_rate_limit' === $mode ) {
				return $this->rate_limit_summary_from_view( $view );
			}
		}

		return $this->legacy_details_summary( (string) ( $item->details ?? '' ) );
	}

	private function antibot_summary_from_view( array $view ): string {
		$score     = isset( $view['score'] ) ? (int) $view['score'] : 0;
		$threshold = isset( $view['threshold'] ) ? (int) $view['threshold'] : 0;
		$mode      = $this->checkout_mode_label( sanitize_key( (string) ( $view['mode'] ?? '' ) ) );
		$action    = ! empty( $view['block'] ) ? __( 'Blocked', 'wc-blacklist-manager' ) : ucfirst( sanitize_key( (string) ( $view['action'] ?? 'logged' ) ) );
		$signals   = $this->signal_labels_from_reasons( (array) ( $view['reasons'] ?? [] ) );

		return sprintf(
			'<strong>%s</strong> %s. %s: <strong>%d/%d</strong>. %s: %s. %s: %s.',
			esc_html__( 'Checkout anti-bot:', 'wc-blacklist-manager' ),
			esc_html( $action ),
			esc_html__( 'Risk', 'wc-blacklist-manager' ),
			$score,
			$threshold,
			esc_html__( 'Mode', 'wc-blacklist-manager' ),
			esc_html( $mode ),
			esc_html__( 'Main signals', 'wc-blacklist-manager' ),
			esc_html( $signals )
		);
	}

	private function rate_limit_summary_from_view( array $view ): string {
		$rate_limit = isset( $view['rate_limit'] ) && is_array( $view['rate_limit'] ) ? $view['rate_limit'] : [];
		$request    = isset( $view['request'] ) && is_array( $view['request'] ) ? $view['request'] : [];
		$limit      = isset( $rate_limit['limit'] ) ? (int) $rate_limit['limit'] : 0;
		$seconds    = isset( $rate_limit['seconds'] ) ? (int) $rate_limit['seconds'] : 0;
		$route      = sanitize_text_field( (string) ( $request['route'] ?? $request['path'] ?? '' ) );

		return sprintf(
			'<strong>%s</strong> %s. %s: <strong>%d/%ds</strong>. %s: %s.',
			esc_html__( 'Store API rate limit:', 'wc-blacklist-manager' ),
			esc_html__( 'Blocked excessive checkout/API traffic', 'wc-blacklist-manager' ),
			esc_html__( 'Limit', 'wc-blacklist-manager' ),
			$limit,
			$seconds,
			esc_html__( 'Route', 'wc-blacklist-manager' ),
			esc_html( '' !== $route ? $route : __( 'Store API', 'wc-blacklist-manager' ) )
		);
	}

	private function legacy_details_summary( string $details ): string {
		if ( 0 === strpos( $details, 'block_antibot_risk_attempt:' ) ) {
			$data = $this->parse_legacy_key_values( $details );
			$score = isset( $data['score'] ) ? (int) $data['score'] : 0;
			$threshold = isset( $data['threshold'] ) ? (int) $data['threshold'] : 0;
			$mode = $this->checkout_mode_label( sanitize_key( (string) ( $data['mode'] ?? '' ) ) );
			$signals = $this->signal_labels_from_reasons( ! empty( $data['reasons'] ) ? explode( ',', (string) $data['reasons'] ) : [] );

			return sprintf(
				'<strong>%s</strong> %s. %s: <strong>%d/%d</strong>. %s: %s. %s: %s.',
				esc_html__( 'Checkout anti-bot:', 'wc-blacklist-manager' ),
				esc_html__( 'Blocked', 'wc-blacklist-manager' ),
				esc_html__( 'Risk', 'wc-blacklist-manager' ),
				$score,
				$threshold,
				esc_html__( 'Mode', 'wc-blacklist-manager' ),
				esc_html( $mode ),
				esc_html__( 'Main signals', 'wc-blacklist-manager' ),
				esc_html( $signals )
			);
		}

		if ( 0 === strpos( $details, 'rate_limit_exceeded:' ) ) {
			return '<strong>' . esc_html__( 'Store API rate limit:', 'wc-blacklist-manager' ) . '</strong> ' . esc_html__( 'Blocked excessive checkout/API traffic.', 'wc-blacklist-manager' );
		}

		return '';
	}

	private function parse_legacy_key_values( string $details ): array {
		$data = [];

		if ( preg_match_all( '/([a-z_]+)=([^\\s]+)/', $details, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$data[ sanitize_key( $match[1] ) ] = sanitize_text_field( $match[2] );
			}
		}

		return $data;
	}

	private function checkout_mode_label( string $mode ): string {
		if ( 'blocks' === $mode ) {
			return __( 'Blocks', 'wc-blacklist-manager' );
		}

		if ( 'classic' === $mode ) {
			return __( 'Classic', 'wc-blacklist-manager' );
		}

		return '' !== $mode ? ucfirst( str_replace( '_', ' ', $mode ) ) : __( 'Checkout', 'wc-blacklist-manager' );
	}

	private function signal_labels_from_reasons( array $reasons ): string {
		$labels = [
			'js_proof'           => __( 'Browser proof', 'wc-blacklist-manager' ),
			'fingerprint'        => __( 'Browser fingerprint', 'wc-blacklist-manager' ),
			'session_continuity' => __( 'Session continuity', 'wc-blacklist-manager' ),
			'core_device'        => __( 'Device intelligence', 'wc-blacklist-manager' ),
			'velocity'           => __( 'Checkout velocity', 'wc-blacklist-manager' ),
			'payment_abuse'      => __( 'Payment abuse', 'wc-blacklist-manager' ),
			'risk_engine'        => __( 'Risk engine', 'wc-blacklist-manager' ),
		];
		$found = [];

		foreach ( $reasons as $reason ) {
			$source = strtok( sanitize_text_field( (string) $reason ), ':' );
			$source = sanitize_key( false === $source ? (string) $reason : $source );

			if ( isset( $labels[ $source ] ) ) {
				$found[ $source ] = $labels[ $source ];
			}
		}

		return ! empty( $found )
			? implode( ', ', array_slice( array_values( $found ), 0, 3 ) )
			: __( 'Risk signals', 'wc-blacklist-manager' );
	}

	public function column_view( $item ) {
		if ( ! empty( trim( (string) $item->view ) ) ) {
			return sprintf(
				'<button type="button" class="button show-view-data icon-button" data-view="%s"><span class="dashicons dashicons-info-outline"></span></button>',
				esc_attr( $item->view )
			);
		}
		return '';
	}

	public function column_default( $item, $column_name ) {
		// Fallback—shouldn’t be hit with the explicit column_* methods above.
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}

	public function prepare_items() {
		global $wpdb;

		$per_page = 20;
		$current_page = $this->get_pagenum();

		$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );

		$orderby = ( isset( $_GET['orderby'] ) && 'timestamp' === $_GET['orderby'] ) ? 'timestamp' : 'timestamp';
		$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';

		$offset = ( $current_page - 1 ) * $per_page;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} ORDER BY `$orderby` $order LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$this->items = $items;

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $total_items / $per_page ) ),
		] );
	}
}
