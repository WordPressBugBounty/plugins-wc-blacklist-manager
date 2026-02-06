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
		$source   = $item->source;
		$img_html = '';
		$text     = $source;
		$link     = '';

		if ( preg_match( '/^(woo|cf7|gravity|wpforms)_(.+)$/', $source, $m ) ) {
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
		if ( 'remove' === $action ) {
			return '<span class="bm-status-verify">' . esc_html__( 'Remove', 'wc-blacklist-manager' ) . '</span>';
		}
		if ( 'unblock' === $action ) {
			return '<span class="bm-status-verify">' . esc_html__( 'Unblock', 'wc-blacklist-manager' ) . '</span>';
		}
		return esc_html( $action );
	}

	public function column_details( $item ) {
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
