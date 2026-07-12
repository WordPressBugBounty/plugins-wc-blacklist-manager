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
		$action = sanitize_key( (string) $item->action );
		$map    = [
			'block'       => [ 'bm-status-block', __( 'Block', 'wc-blacklist-manager' ) ],
			'rate_limit'  => [ 'bm-status-block', __( 'Rate limit', 'wc-blacklist-manager' ) ],
			'cancel'      => [ 'bm-status-block', __( 'Cancel', 'wc-blacklist-manager' ) ],
			'suspect'     => [ 'bm-status-suspect', __( 'Suspect', 'wc-blacklist-manager' ) ],
			'would_block' => [ 'bm-status-suspect', __( 'Would block', 'wc-blacklist-manager' ) ],
			'challenge'   => [ 'bm-status-challenge', __( 'Challenge', 'wc-blacklist-manager' ) ],
			'warning'     => [ 'bm-status-warning', __( 'Warning', 'wc-blacklist-manager' ) ],
			'verify'      => [ 'bm-status-verify', __( 'Verify', 'wc-blacklist-manager' ) ],
			'remove'      => [ 'bm-status-verify', __( 'Remove', 'wc-blacklist-manager' ) ],
			'unblock'     => [ 'bm-status-verify', __( 'Unblock', 'wc-blacklist-manager' ) ],
			'allow'       => [ 'bm-status-allow', __( 'Allow', 'wc-blacklist-manager' ) ],
			'notice'      => [ 'bm-status-notice', __( 'Notice', 'wc-blacklist-manager' ) ],
		];

		if ( isset( $map[ $action ] ) ) {
			return '<span class="' . esc_attr( $map[ $action ][0] ) . '">' . esc_html( $map[ $action ][1] ) . '</span>';
		}

		return esc_html( '' !== $action ? ucfirst( str_replace( '_', ' ', $action ) ) : __( 'Logged', 'wc-blacklist-manager' ) );
	}

	public function column_details( $item ) {
		$summary = $this->structured_details_summary( $item );
		$chips   = $this->details_chips( $item );

		if ( '' === $summary ) {
			$summary = $this->fallback_details_summary( $item );
		}

		$html = '<div class="bm-log-summary">' . $summary . '</div>';

		if ( '' !== $chips ) {
			$html .= '<div class="bm-log-chips">' . $chips . '</div>';
		}

		return $html;
	}

	private function structured_details_summary( $item ): string {
		$view = json_decode( (string) ( $item->view ?? '' ), true );
		$details = (string) ( $item->details ?? '' );

		if ( is_array( $view ) ) {
			$schema = sanitize_key( (string) ( $view['schema'] ?? '' ) );
			$mode   = sanitize_key( (string) ( $view['mode'] ?? '' ) );

			if ( 'bmp_antibot_risk_v1' === $schema ) {
				return $this->antibot_summary_from_view( $view );
			}

			if ( 'bmp_active_challenge_v1' === $schema ) {
				return $this->active_challenge_summary_from_view( $view );
			}

			if ( 'yogb_gbl_decision_v1' === $schema ) {
				return $this->yogb_decision_summary_from_view( $view );
			}

			if ( 'order_status_automation_v1' === $schema ) {
				return $this->order_status_automation_summary_from_view( $item, $view );
			}

			if ( 'connection_sync_v1' === $schema ) {
				return $this->connection_sync_summary_from_view( $item, $view );
			}

			if ( 'country_access_block_v1' === $schema ) {
				return $this->country_access_summary_from_view( $view );
			}

			if ( 'store_api_rate_limit' === $mode ) {
				return $this->rate_limit_summary_from_view( $view );
			}

			if ( $this->is_payment_flow_view( $view ) ) {
				return $this->payment_flow_summary_from_view( $view, $details );
			}

			if ( $this->is_captcha_view( $view ) ) {
				return $this->captcha_summary_from_view( $view );
			}

			if ( $this->is_checkout_snapshot_view( $view ) ) {
				return $this->checkout_snapshot_summary_from_view( $item, $view );
			}

			if ( $this->is_manual_action_view( $view ) ) {
				return $this->manual_action_summary_from_view( $item, $view );
			}
		}

		return $this->legacy_details_summary( $details, $item );
	}

	private function yogb_decision_summary_from_view( array $view ): string {
		$decision = sanitize_key( (string) ( $view['decision'] ?? '' ) );
		$mode     = sanitize_key( (string) ( $view['mode'] ?? '' ) );
		$context  = sanitize_key( (string) ( $view['context'] ?? '' ) );
		$score    = isset( $view['score'] ) ? (float) $view['score'] : 0;
		$primary  = sanitize_key( (string) ( $view['primary_signal_type'] ?? '' ) );
		$risk     = sanitize_key( (string) ( $view['primary_risk_level'] ?? '' ) );

		return sprintf(
			'<strong>%s</strong> %s. %s: <strong>%s</strong>. %s: %s. %s: %s%s',
			esc_html__( 'Global Blacklist:', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $decision ?: 'decision' ) ),
			esc_html__( 'Risk', 'wc-blacklist-manager' ),
			esc_html( number_format_i18n( $score, 2 ) ),
			esc_html__( 'Mode', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $mode ?: 'standard' ) ),
			esc_html__( 'Primary signal', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $primary ?: 'unknown' ) ),
			'' !== $risk || '' !== $context ? '. ' . esc_html__( 'Risk level', 'wc-blacklist-manager' ) . ': ' . esc_html( $this->humanize_key( $risk ?: 'unknown' ) ) . '. ' . esc_html__( 'Context', 'wc-blacklist-manager' ) . ': ' . esc_html( $this->humanize_key( $context ?: 'order check' ) ) . '.' : '.'
		);
	}

	private function order_status_automation_summary_from_view( $item, array $view ): string {
		$action = sanitize_key( (string) ( $item->action ?? '' ) );
		$status = sanitize_key( (string) ( $view['order_status'] ?? '' ) );
		$entry  = isset( $view['blacklist_id'] ) ? absint( $view['blacklist_id'] ) : 0;

		return sprintf(
			'<strong>%s</strong> %s. %s: %s%s',
			esc_html__( 'Order status automation:', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $action ?: 'updated' ) ),
			esc_html__( 'Status', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $status ?: 'matched' ) ),
			$entry > 0 ? '. ' . esc_html__( 'Entry', 'wc-blacklist-manager' ) . ': #' . esc_html( (string) $entry ) . '.' : '.'
		);
	}

	private function connection_sync_summary_from_view( $item, array $view ): string {
		$action = sanitize_key( (string) ( $item->action ?? '' ) );
		$event  = sanitize_key( (string) ( $view['event'] ?? '' ) );
		$remote = sanitize_key( (string) ( $view['remote_role'] ?? '' ) );
		$entry  = isset( $view['local_id'] ) ? absint( $view['local_id'] ) : 0;

		return sprintf(
			'<strong>%s</strong> %s from %s. %s: %s%s',
			esc_html__( 'Connection sync:', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $event ?: 'received' ) ),
			esc_html( $this->humanize_key( $remote ?: 'remote site' ) ),
			esc_html__( 'Result', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $action ?: 'updated' ) ),
			$entry > 0 ? '. ' . esc_html__( 'Local entry', 'wc-blacklist-manager' ) . ': #' . esc_html( (string) $entry ) . '.' : '.'
		);
	}

	private function country_access_summary_from_view( array $view ): string {
		$mode    = sanitize_key( (string) ( $view['mode'] ?? '' ) );
		$country = sanitize_text_field( (string) ( $view['visitor_country'] ?? '' ) );

		return sprintf(
			'<strong>%s</strong> %s. %s: %s. %s: %s.',
			esc_html__( 'Access restriction:', 'wc-blacklist-manager' ),
			esc_html__( 'Blocked visitor by country rule', 'wc-blacklist-manager' ),
			esc_html__( 'Mode', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $mode ?: 'prevent' ) ),
			esc_html__( 'Country', 'wc-blacklist-manager' ),
			esc_html( '' !== $country ? $country : __( 'unknown', 'wc-blacklist-manager' ) )
		);
	}

	private function active_challenge_summary_from_view( array $view ): string {
		$event   = sanitize_key( (string) ( $view['event'] ?? '' ) );
		$surface = sanitize_key( (string) ( $view['surface'] ?? '' ) );
		$action  = sanitize_key( (string) ( $view['action'] ?? '' ) );
		$risk    = isset( $view['risk'] ) && is_array( $view['risk'] ) ? $view['risk'] : [];
		$score   = isset( $risk['score'] ) ? (int) $risk['score'] : null;

		$parts = [
			esc_html( $this->humanize_key( $event ?: 'active_challenge' ) ),
			esc_html__( 'on', 'wc-blacklist-manager' ) . ' ' . esc_html( $this->humanize_key( $surface ?: 'checkout' ) ),
		];

		if ( null !== $score ) {
			$parts[] = esc_html__( 'risk', 'wc-blacklist-manager' ) . ' ' . esc_html( (string) $score );
		}

		return sprintf(
			'<strong>%s</strong> %s. %s: %s.',
			esc_html__( 'Active challenge:', 'wc-blacklist-manager' ),
			implode( ' ', $parts ),
			esc_html__( 'Result', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $action ?: 'logged' ) )
		);
	}

	private function is_payment_flow_view( array $view ): bool {
		return isset( $view['payment_flow'] ) || isset( $view['paypal'] ) || isset( $view['integration'] );
	}

	private function payment_flow_summary_from_view( array $view, string $details ): string {
		$event       = sanitize_key( (string) ( $view['event'] ?? '' ) );
		$provider    = sanitize_key( (string) ( $view['provider'] ?? '' ) );
		$integration = sanitize_key( (string) ( $view['integration'] ?? $view['source'] ?? '' ) );
		$reason      = sanitize_key( (string) ( $view['reason'] ?? '' ) );

		if ( '' === $event && preg_match( '/(?:payment_flow_captcha|paypal_flow_captcha):\s*([a-z0-9_]+)/i', $details, $m ) ) {
			$event = sanitize_key( $m[1] );
		}

		return sprintf(
			'<strong>%s</strong> %s. %s: %s. %s: %s%s',
			esc_html__( 'Payment challenge:', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $event ?: 'logged' ) ),
			esc_html__( 'Provider', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $provider ?: 'captcha' ) ),
			esc_html__( 'Integration', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $integration ?: 'payment' ) ),
			'' !== $reason ? '. ' . esc_html__( 'Reason', 'wc-blacklist-manager' ) . ': ' . esc_html( $this->humanize_key( $reason ) ) . '.' : '.'
		);
	}

	private function is_captcha_view( array $view ): bool {
		return isset( $view['captcha'] ) && isset( $view['provider'] );
	}

	private function captcha_summary_from_view( array $view ): string {
		$provider = sanitize_key( (string) ( $view['provider'] ?? '' ) );
		$source   = sanitize_key( (string) ( $view['source'] ?? '' ) );
		$reason   = sanitize_text_field( (string) ( $view['reason'] ?? '' ) );

		return sprintf(
			'<strong>%s</strong> %s: %s. %s: %s%s',
			esc_html__( 'CAPTCHA:', 'wc-blacklist-manager' ),
			esc_html__( 'Provider', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $provider ?: 'captcha' ) ),
			esc_html__( 'Source', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $source ?: 'checkout' ) ),
			'' !== $reason ? '. ' . esc_html__( 'Reason', 'wc-blacklist-manager' ) . ': ' . esc_html( $this->humanize_key( $reason ) ) . '.' : '.'
		);
	}

	private function is_checkout_snapshot_view( array $view ): bool {
		return isset( $view['cart_total'] ) || isset( $view['cart_items'] ) || isset( $view['billing'] ) || isset( $view['shipping'] );
	}

	private function checkout_snapshot_summary_from_view( $item, array $view ): string {
		$details = (string) ( $item->details ?? '' );
		$signals = $this->matched_signal_labels_from_details( $details );
		$total = isset( $view['cart_total'] ) ? $this->format_amount( $view['cart_total'], (string) ( $view['currency'] ?? '' ) ) : '';
		$payment = sanitize_text_field( (string) ( $view['payment_method'] ?? '' ) );

		$tail = [];
		if ( '' !== $total ) {
			$tail[] = esc_html__( 'Cart', 'wc-blacklist-manager' ) . ': ' . esc_html( $total );
		}
		if ( '' !== $payment ) {
			$tail[] = esc_html__( 'Payment', 'wc-blacklist-manager' ) . ': ' . esc_html( $this->humanize_key( $payment ) );
		}

		return sprintf(
			'<strong>%s</strong> %s%s',
			esc_html__( 'Checkout blocked:', 'wc-blacklist-manager' ),
			esc_html( '' !== $signals ? $signals : __( 'Matched blacklist rules', 'wc-blacklist-manager' ) ),
			! empty( $tail ) ? '. ' . implode( '. ', $tail ) . '.' : '.'
		);
	}

	private function is_manual_action_view( array $view ): bool {
		return isset( $view['reason_code'] ) || isset( $view['removed_counts'] ) || isset( $view['removed_from'] );
	}

	private function manual_action_summary_from_view( $item, array $view ): string {
		$action = sanitize_key( (string) ( $item->action ?? '' ) );
		$reason = sanitize_text_field( (string) ( $view['reason_code'] ?? '' ) );
		$note = sanitize_text_field( (string) ( $view['description'] ?? $view['note'] ?? '' ) );

		return sprintf(
			'<strong>%s</strong> %s%s%s',
			esc_html__( 'Manual review:', 'wc-blacklist-manager' ),
			esc_html( $this->humanize_key( $action ?: 'updated' ) ),
			'' !== $reason ? '. ' . esc_html__( 'Reason', 'wc-blacklist-manager' ) . ': ' . esc_html( $this->humanize_key( $reason ) ) : '',
			'' !== $note ? '. ' . esc_html( wp_trim_words( $note, 12, '...' ) ) : '.'
		);
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

	private function legacy_details_summary( string $details, $item = null ): string {
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

		$prefix_summaries = [
			'block_bot_js_proof_attempt:'             => [ __( 'Browser proof:', 'wc-blacklist-manager' ), __( 'Blocked missing or invalid browser proof.', 'wc-blacklist-manager' ) ],
			'block_session_continuity_attempt:'       => [ __( 'Session continuity:', 'wc-blacklist-manager' ), __( 'Blocked inconsistent checkout journey.', 'wc-blacklist-manager' ) ],
			'block_fingerprint_anomalies_attempt:'    => [ __( 'Browser fingerprint:', 'wc-blacklist-manager' ), __( 'Blocked suspicious browser fingerprint signals.', 'wc-blacklist-manager' ) ],
			'block_rest_api_attempt:'                 => [ __( 'Store API shield:', 'wc-blacklist-manager' ), __( 'Blocked suspicious REST checkout request.', 'wc-blacklist-manager' ) ],
			'block_captcha_attempt:'                  => [ __( 'CAPTCHA:', 'wc-blacklist-manager' ), __( 'Blocked failed CAPTCHA validation.', 'wc-blacklist-manager' ) ],
			'blocked_risk_score_attempt'              => [ __( 'Risk score:', 'wc-blacklist-manager' ), __( 'Blocked by risk score automation.', 'wc-blacklist-manager' ) ],
			'suspected_risk_score_attempt'            => [ __( 'Risk score:', 'wc-blacklist-manager' ), __( 'Added to suspect list by risk score automation.', 'wc-blacklist-manager' ) ],
			'verified_email_attempt:'                 => [ __( 'Verification:', 'wc-blacklist-manager' ), __( 'Email verification completed.', 'wc-blacklist-manager' ) ],
			'verified_phone_attempt:'                 => [ __( 'Verification:', 'wc-blacklist-manager' ), __( 'Phone verification completed.', 'wc-blacklist-manager' ) ],
			'blocked_browser_attempt:'                => [ __( 'Access restriction:', 'wc-blacklist-manager' ), __( 'Blocked visitor by browser rule.', 'wc-blacklist-manager' ) ],
			'blocked_ip_country_attempt:'             => [ __( 'Access restriction:', 'wc-blacklist-manager' ), __( 'Blocked visitor by country rule.', 'wc-blacklist-manager' ) ],
			'blocked_ip_country_allowlist_attempt:'   => [ __( 'Access restriction:', 'wc-blacklist-manager' ), __( 'Blocked visitor outside the allowed country list.', 'wc-blacklist-manager' ) ],
			'blocked_order_status_attempt:'           => [ __( 'Order status automation:', 'wc-blacklist-manager' ), __( 'Blocked customer details from a matched order status.', 'wc-blacklist-manager' ) ],
			'suspected_order_status_attempt:'         => [ __( 'Order status automation:', 'wc-blacklist-manager' ), __( 'Added customer details to suspect list from a matched order status.', 'wc-blacklist-manager' ) ],
			'global_blacklist_decision:'              => [ __( 'Global Blacklist:', 'wc-blacklist-manager' ), __( 'Decision recorded from Global Blacklist Decisions.', 'wc-blacklist-manager' ) ],
			'connection_sync_received:'               => [ __( 'Connection sync:', 'wc-blacklist-manager' ), __( 'Received blacklist data from a connected site.', 'wc-blacklist-manager' ) ],
			'connection_sync_updated:'                => [ __( 'Connection sync:', 'wc-blacklist-manager' ), __( 'Updated blacklist data from a connected site.', 'wc-blacklist-manager' ) ],
			'connection_sync_removed:'                => [ __( 'Connection sync:', 'wc-blacklist-manager' ), __( 'Removed blacklist data from a connected site.', 'wc-blacklist-manager' ) ],
		];

		foreach ( $prefix_summaries as $prefix => $summary ) {
			if ( 0 === strpos( $details, $prefix ) ) {
				return '<strong>' . esc_html( $summary[0] ) . '</strong> ' . esc_html( $summary[1] );
			}
		}

		if ( preg_match( '/^(payment_flow_captcha|paypal_flow_captcha):\s*([a-z0-9_]+)/i', $details, $m ) ) {
			return sprintf(
				'<strong>%s</strong> %s.',
				esc_html__( 'Payment challenge:', 'wc-blacklist-manager' ),
				esc_html( $this->humanize_key( $m[2] ) )
			);
		}

		if ( false !== strpos( $details, '_by:' ) || 0 === strpos( $details, 'by:' ) ) {
			$action = $item ? sanitize_key( (string) ( $item->action ?? '' ) ) : '';
			return sprintf(
				'<strong>%s</strong> %s.',
				esc_html__( 'Manual review:', 'wc-blacklist-manager' ),
				esc_html( $this->humanize_key( $action ?: 'updated' ) )
			);
		}

		return '';
	}

	private function fallback_details_summary( $item ): string {
		$details = (string) ( $item->details ?? '' );
		$source  = sanitize_key( (string) ( $item->source ?? '' ) );
		$action  = sanitize_key( (string) ( $item->action ?? '' ) );
		$signals = $this->matched_signal_labels_from_details( $details );
		$items   = $this->parse_detail_items( $details );
		$first   = '';

		if ( ! empty( $items ) ) {
			$first = $items[0]['label'] . ': ' . $this->compact_value( $items[0]['value'], 90 );
		}

		return sprintf(
			'<strong>%s</strong> %s%s',
			esc_html( $this->humanize_key( $source ?: 'activity_log' ) . ':' ),
			esc_html( '' !== $signals ? $signals : $this->humanize_key( $action ?: 'logged' ) ),
			'' !== $first ? '. ' . esc_html( $first ) . '.' : '.'
		);
	}

	private function details_chips( $item ): string {
		$chips   = [];
		$details = (string) ( $item->details ?? '' );
		$view    = json_decode( (string) ( $item->view ?? '' ), true );

		foreach ( $this->parse_detail_items( $details ) as $detail ) {
			$this->add_chip( $chips, $detail['label'], $detail['value'] );
		}

		if ( is_array( $view ) ) {
			if ( isset( $view['score'] ) || isset( $view['threshold'] ) ) {
				$score = isset( $view['score'] ) ? (int) $view['score'] : 0;
				$threshold = isset( $view['threshold'] ) ? (int) $view['threshold'] : 0;
				$this->add_chip( $chips, __( 'Risk', 'wc-blacklist-manager' ), $threshold > 0 ? "{$score}/{$threshold}" : (string) $score );
			}

			foreach ( [
				'email'            => __( 'Email', 'wc-blacklist-manager' ),
				'phone'            => __( 'Phone', 'wc-blacklist-manager' ),
				'ip_address'       => __( 'IP', 'wc-blacklist-manager' ),
				'payment_method'   => __( 'Payment', 'wc-blacklist-manager' ),
				'provider'         => __( 'Provider', 'wc-blacklist-manager' ),
				'integration'      => __( 'Integration', 'wc-blacklist-manager' ),
				'event'            => __( 'Event', 'wc-blacklist-manager' ),
			] as $key => $label ) {
				if ( isset( $view[ $key ] ) && is_scalar( $view[ $key ] ) && '' !== (string) $view[ $key ] ) {
					$this->add_chip( $chips, $label, (string) $view[ $key ] );
				}
			}

			if ( isset( $view['cart_total'] ) ) {
				$this->add_chip( $chips, __( 'Cart', 'wc-blacklist-manager' ), $this->format_amount( $view['cart_total'], (string) ( $view['currency'] ?? '' ) ) );
			}

			if ( isset( $view['request'] ) && is_array( $view['request'] ) ) {
				$request = $view['request'];
				if ( isset( $request['ip'] ) && '' !== (string) $request['ip'] ) {
					$this->add_chip( $chips, __( 'IP', 'wc-blacklist-manager' ), (string) $request['ip'] );
				}
				if ( isset( $request['route'] ) && '' !== (string) $request['route'] ) {
					$this->add_chip( $chips, __( 'Route', 'wc-blacklist-manager' ), (string) $request['route'] );
				}
				if ( isset( $request['payment_method'] ) && '' !== (string) $request['payment_method'] ) {
					$this->add_chip( $chips, __( 'Payment', 'wc-blacklist-manager' ), (string) $request['payment_method'] );
				}
			}
		}

		if ( empty( $chips ) ) {
			return '';
		}

		return implode( '', array_slice( $chips, 0, 6 ) );
	}

	private function add_chip( array &$chips, string $label, $value ): void {
		$value = $this->compact_value( $value );

		if ( '' === $value ) {
			return;
		}

		$key = strtolower( $label . ':' . $value );
		if ( isset( $chips[ $key ] ) ) {
			return;
		}

		$chips[ $key ] = sprintf(
			'<span class="bm-log-chip"><span>%s</span>%s</span>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	private function parse_detail_items( string $details ): array {
		$details = trim( $details );
		if ( '' === $details ) {
			return [];
		}

		$parts = preg_split( '/,\s(?=[A-Za-z0-9_]+:)|\s\|\s(?=[A-Za-z0-9_]+(?:[:=]))/', $details );
		$items = [];

		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}

			$key = '';
			$value = '';

			if ( false !== strpos( $part, ':' ) ) {
				list( $key, $value ) = array_map( 'trim', explode( ':', $part, 2 ) + [ '', '' ] );
			} elseif ( false !== strpos( $part, '=' ) ) {
				list( $key, $value ) = array_map( 'trim', explode( '=', $part, 2 ) + [ '', '' ] );
			}

			if ( '' === $key || '' === $value ) {
				continue;
			}

			$items[] = [
				'key'   => sanitize_key( $key ),
				'label' => $this->detail_label( $key ),
				'value' => $value,
			];
		}

		return $items;
	}

	private function detail_label( string $key ): string {
		$key = sanitize_key( $key );
		$clean = preg_replace( '/^(blocked|suspected|verified)_/', '', $key );
		$clean = str_replace( '_attempt', '', (string) $clean );

		$labels = [
			'email'                => __( 'Email', 'wc-blacklist-manager' ),
			'phone'                => __( 'Phone', 'wc-blacklist-manager' ),
			'ip'                   => __( 'IP', 'wc-blacklist-manager' ),
			'user_ip'              => __( 'IP', 'wc-blacklist-manager' ),
			'domain'               => __( 'Domain', 'wc-blacklist-manager' ),
			'tld'                  => __( 'TLD', 'wc-blacklist-manager' ),
			'disposable_email'     => __( 'Disposable email', 'wc-blacklist-manager' ),
			'disposable_phone'     => __( 'Disposable phone', 'wc-blacklist-manager' ),
			'proxy_vpn'            => __( 'Proxy/VPN', 'wc-blacklist-manager' ),
			'device'               => __( 'Device', 'wc-blacklist-manager' ),
			'user'                 => __( 'User', 'wc-blacklist-manager' ),
			'normalized'           => __( 'Normalized', 'wc-blacklist-manager' ),
			'browser'              => __( 'Browser', 'wc-blacklist-manager' ),
			'ua'                   => __( 'User agent', 'wc-blacklist-manager' ),
			'payment_flow_captcha' => __( 'Payment challenge', 'wc-blacklist-manager' ),
			'paypal_flow_captcha'  => __( 'PayPal challenge', 'wc-blacklist-manager' ),
			'integration'          => __( 'Integration', 'wc-blacklist-manager' ),
			'provider'             => __( 'Provider', 'wc-blacklist-manager' ),
			'reason'               => __( 'Reason', 'wc-blacklist-manager' ),
			'by'                   => __( 'By', 'wc-blacklist-manager' ),
		];

		if ( false !== strpos( $clean, 'billing' ) && false !== strpos( $clean, 'address' ) ) {
			return __( 'Billing address', 'wc-blacklist-manager' );
		}

		if ( false !== strpos( $clean, 'shipping' ) && false !== strpos( $clean, 'address' ) ) {
			return __( 'Shipping address', 'wc-blacklist-manager' );
		}

		if ( false !== strpos( $clean, 'name' ) ) {
			return __( 'Name', 'wc-blacklist-manager' );
		}

		return isset( $labels[ $clean ] ) ? $labels[ $clean ] : $this->humanize_key( $clean );
	}

	private function matched_signal_labels_from_details( string $details ): string {
		$map = [
			'blocked_email_attempt'              => __( 'Email', 'wc-blacklist-manager' ),
			'suspected_email_attempt'            => __( 'Email', 'wc-blacklist-manager' ),
			'blocked_phone_attempt'              => __( 'Phone', 'wc-blacklist-manager' ),
			'suspected_phone_attempt'            => __( 'Phone', 'wc-blacklist-manager' ),
			'blocked_ip_attempt'                 => __( 'IP', 'wc-blacklist-manager' ),
			'suspected_ip_attempt'               => __( 'IP', 'wc-blacklist-manager' ),
			'blocked_domain_attempt'             => __( 'Domain', 'wc-blacklist-manager' ),
			'blocked_tld_attempt'                => __( 'Domain/TLD', 'wc-blacklist-manager' ),
			'blocked_name_attempt'               => __( 'Name', 'wc-blacklist-manager' ),
			'blocked_billing'                    => __( 'Billing address', 'wc-blacklist-manager' ),
			'blocked_shipping'                   => __( 'Shipping address', 'wc-blacklist-manager' ),
			'blocked_disposable_email_attempt'   => __( 'Disposable email', 'wc-blacklist-manager' ),
			'blocked_disposable_phone_attempt'   => __( 'Disposable phone', 'wc-blacklist-manager' ),
			'blocked_proxy_vpn_attempt'          => __( 'Proxy/VPN', 'wc-blacklist-manager' ),
			'blocked_device_attempt'             => __( 'Device', 'wc-blacklist-manager' ),
			'blocked_user_attempt'               => __( 'User', 'wc-blacklist-manager' ),
			'block_bot_js_proof_attempt'         => __( 'Browser proof', 'wc-blacklist-manager' ),
			'block_session_continuity_attempt'   => __( 'Session continuity', 'wc-blacklist-manager' ),
			'block_fingerprint_anomalies_attempt'=> __( 'Browser fingerprint', 'wc-blacklist-manager' ),
			'block_rest_api_attempt'             => __( 'REST shield', 'wc-blacklist-manager' ),
			'block_captcha_attempt'              => __( 'CAPTCHA', 'wc-blacklist-manager' ),
		];
		$found = [];

		foreach ( $map as $needle => $label ) {
			if ( false !== strpos( $details, $needle ) ) {
				$found[ $label ] = $label;
			}
		}

		return implode( ', ', array_slice( array_values( $found ), 0, 4 ) );
	}

	private function humanize_key( string $key ): string {
		$key = trim( str_replace( [ '-', '_' ], ' ', sanitize_text_field( $key ) ) );
		return '' !== $key ? ucwords( $key ) : '';
	}

	private function compact_value( $value, int $max = 72 ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max - 3 ) . '...';
		}

		if ( strlen( $value ) > $max ) {
			return substr( $value, 0, $max - 3 ) . '...';
		}

		return $value;
	}

	private function format_amount( $amount, string $currency = '' ): string {
		if ( ! is_numeric( $amount ) ) {
			return '';
		}

		$formatted = number_format_i18n( (float) $amount, 2 );
		$currency  = sanitize_text_field( $currency );

		return '' !== $currency ? $currency . ' ' . $formatted : $formatted;
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
		$view    = trim( (string) ( $item->view ?? '' ) );
		$details = trim( (string) ( $item->details ?? '' ) );

		if ( '' !== $view || '' !== $details ) {
			$payload = [
				'id'        => isset( $item->id ) ? absint( $item->id ) : 0,
				'timestamp' => isset( $item->timestamp ) ? (string) $item->timestamp : '',
				'type'      => isset( $item->type ) ? (string) $item->type : '',
				'source'    => isset( $item->source ) ? (string) $item->source : '',
				'action'    => isset( $item->action ) ? (string) $item->action : '',
				'details'   => $details,
				'view'      => $view,
			];

			return sprintf(
				'<button type="button" class="button show-view-data icon-button" data-log="%s" aria-label="%s"><span class="dashicons dashicons-info-outline"></span></button>',
				esc_attr( wp_json_encode( $payload ) ),
				esc_attr__( 'View activity log details', 'wc-blacklist-manager' )
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
