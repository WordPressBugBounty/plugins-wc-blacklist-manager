<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_catalog' ) ) {
	function wc_blacklist_manager_action_upsell_catalog() {
		$premium_url = 'https://yoohw.com/product/blacklist-manager-premium/';

		return array(
			'manual_entry' => array(
				'title'     => __( 'Reduce manual blacklist work', 'wc-blacklist-manager' ),
				'message'   => __( 'You are adding blacklist entries manually. Premium can auto-add similar risky customers from repeat order patterns.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Automation', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 3,
				'window'    => 7 * DAY_IN_SECONDS,
				'cooldown'  => 14 * DAY_IN_SECONDS,
				'surfaces'  => array( 'dashboard' ),
			),
			'manual_block' => array(
				'title'     => __( 'Turn repeated blocks into rules', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium can score and block similar future orders automatically when the same risk patterns keep appearing.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Risk Scoring', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 2,
				'window'    => 14 * DAY_IN_SECONDS,
				'cooldown'  => 14 * DAY_IN_SECONDS,
				'surfaces'  => array( 'dashboard' ),
			),
			'ip_manual_add' => array(
				'title'     => __( 'Catch risky IP patterns earlier', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium can detect proxy, VPN, TOR, hosting IP, and IP/location mismatches before checkout.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Risk Scoring', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 5,
				'window'    => 30 * DAY_IN_SECONDS,
				'cooldown'  => 21 * DAY_IN_SECONDS,
				'surfaces'  => array( 'dashboard' ),
			),
			'domain_manual_add' => array(
				'title'     => __( 'Stop chasing throwaway domains', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium can catch disposable email and risky domain patterns before you maintain long domain lists manually.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Integrations', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 5,
				'window'    => 30 * DAY_IN_SECONDS,
				'cooldown'  => 21 * DAY_IN_SECONDS,
				'surfaces'  => array( 'dashboard' ),
			),
			'bulk_cleanup' => array(
				'title'     => __( 'Review cleanup decisions with history', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium tools help clean old records with retention controls and activity history before bulk cleanup.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Premium Tools', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 5,
				'window'    => 30 * DAY_IN_SECONDS,
				'cooldown'  => 21 * DAY_IN_SECONDS,
				'surfaces'  => array( 'dashboard' ),
			),
			'order_suspect' => array(
				'title'     => __( 'Connect order signals automatically', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium can connect order signals like customer, IP, address, device, and payment behavior into risk scoring.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Risk Scoring', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 2,
				'window'    => 14 * DAY_IN_SECONDS,
				'cooldown'  => 14 * DAY_IN_SECONDS,
				'surfaces'  => array( 'order' ),
			),
			'order_block' => array(
				'title'     => __( 'Block similar future orders sooner', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium can score similar future orders automatically before you need to block them by hand.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Risk Scoring', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 2,
				'window'    => 14 * DAY_IN_SECONDS,
				'cooldown'  => 14 * DAY_IN_SECONDS,
				'surfaces'  => array( 'order' ),
			),
			'order_remove' => array(
				'title'     => __( 'Keep blacklist changes auditable', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium activity logs help review who blocked, removed, or changed blacklist decisions.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Activity Logs', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 2,
				'window'    => 30 * DAY_IN_SECONDS,
				'cooldown'  => 21 * DAY_IN_SECONDS,
				'surfaces'  => array( 'order' ),
			),
			'sms_key' => array(
				'title'     => __( 'Use a verification provider that fits your workflow', 'wc-blacklist-manager' ),
				'message'   => __( 'Premium supports Twilio/Textmagic and phone intelligence when Yo Credits is not enough.', 'wc-blacklist-manager' ),
				'cta'       => __( 'Unlock Integrations', 'wc-blacklist-manager' ),
				'url'       => $premium_url,
				'threshold' => 1,
				'window'    => 30 * DAY_IN_SECONDS,
				'cooldown'  => 30 * DAY_IN_SECONDS,
				'surfaces'  => array( 'verifications' ),
			),
		);
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsells_premium_active' ) ) {
	function wc_blacklist_manager_action_upsells_premium_active() {
		return function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_user_id' ) ) {
	function wc_blacklist_manager_action_upsell_user_id() {
		$user_id = get_current_user_id();
		return $user_id ? (int) $user_id : 0;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_get_meta_array' ) ) {
	function wc_blacklist_manager_action_upsell_get_meta_array( $user_id, $key ) {
		$value = get_user_meta( $user_id, $key, true );
		return is_array( $value ) ? $value : array();
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_dismissed' ) ) {
	function wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) {
		$dismissed = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_dismissed' );
		return ! empty( $dismissed[ $event ] );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_recently_shown' ) ) {
	function wc_blacklist_manager_action_upsell_recently_shown( $user_id, $event, $cooldown ) {
		$last_shown = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_last_shown' );

		if ( empty( $last_shown[ $event ] ) ) {
			return false;
		}

		return ( time() - (int) $last_shown[ $event ] ) < (int) $cooldown;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_queue_action_upsell' ) ) {
	function wc_blacklist_manager_queue_action_upsell( $event ) {
		$catalog = wc_blacklist_manager_action_upsell_catalog();

		if ( empty( $catalog[ $event ] ) || wc_blacklist_manager_action_upsells_premium_active() ) {
			return false;
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		if ( ! $user_id || wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) ) {
			return false;
		}

		$config = $catalog[ $event ];
		if ( wc_blacklist_manager_action_upsell_recently_shown( $user_id, $event, $config['cooldown'] ) ) {
			return false;
		}

		$pending           = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_pending' );
		$pending[ $event ] = time();

		update_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_pending', $pending );
		return true;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_record_action_upsell_event' ) ) {
	function wc_blacklist_manager_record_action_upsell_event( $event, $amount = 1 ) {
		$catalog = wc_blacklist_manager_action_upsell_catalog();

		if ( empty( $catalog[ $event ] ) || wc_blacklist_manager_action_upsells_premium_active() ) {
			return false;
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		if ( ! $user_id || wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) ) {
			return false;
		}

		$config    = $catalog[ $event ];
		$amount    = max( 1, absint( $amount ) );
		$now       = time();
		$counts    = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_counts' );
		$event_log = isset( $counts[ $event ] ) && is_array( $counts[ $event ] ) ? $counts[ $event ] : array();

		if ( empty( $event_log['started'] ) || ( $now - (int) $event_log['started'] ) > (int) $config['window'] ) {
			$event_log = array(
				'count'   => 0,
				'started' => $now,
			);
		}

		$event_log['count'] = (int) $event_log['count'] + $amount;
		$counts[ $event ]  = $event_log;

		update_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_counts', $counts );

		if ( $amount >= (int) $config['threshold'] || (int) $event_log['count'] >= (int) $config['threshold'] ) {
			return wc_blacklist_manager_queue_action_upsell( $event );
		}

		return false;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_get_current_admin_url' ) ) {
	function wc_blacklist_manager_get_current_admin_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $host || '' === $uri ) {
			return admin_url();
		}

		return esc_url_raw( $scheme . $host . $uri );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_get_action_upsell_dismiss_url' ) ) {
	function wc_blacklist_manager_get_action_upsell_dismiss_url( $event ) {
		$url = add_query_arg(
			array(
				'action'      => 'wc_blacklist_manager_dismiss_action_upsell',
				'event'       => sanitize_key( $event ),
				'redirect_to' => rawurlencode( wc_blacklist_manager_get_current_admin_url() ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'wc_blacklist_manager_dismiss_action_upsell_' . sanitize_key( $event ) );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_markup' ) ) {
	function wc_blacklist_manager_action_upsell_markup( $event, array $config, array $args = array() ) {
		$inline      = ! empty( $args['inline'] );
		$class_names = $inline
			? 'notice notice-info inline yobm-action-upsell yobm-action-upsell--inline'
			: 'notice notice-info is-dismissible yobm-action-upsell';

		ob_start();
		?>
		<div class="<?php echo esc_attr( $class_names ); ?>">
			<p>
				<strong><?php echo esc_html( $config['title'] ); ?></strong>
				<?php echo esc_html( $config['message'] ); ?>
			</p>
			<p class="yobm-action-upsell__actions">
				<a href="<?php echo esc_url( $config['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
					<?php echo esc_html( $config['cta'] ); ?>
				</a>
				<a href="<?php echo esc_url( wc_blacklist_manager_get_action_upsell_dismiss_url( $event ) ); ?>" class="button-link yobm-action-upsell__dismiss">
					<?php esc_html_e( 'Not now', 'wc-blacklist-manager' ); ?>
				</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_mark_shown' ) ) {
	function wc_blacklist_manager_action_upsell_mark_shown( $user_id, $event ) {
		$last_shown           = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_last_shown' );
		$last_shown[ $event ] = time();
		update_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_last_shown', $last_shown );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_clear_pending' ) ) {
	function wc_blacklist_manager_action_upsell_clear_pending( $user_id, $event ) {
		$pending = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_pending' );

		if ( isset( $pending[ $event ] ) ) {
			unset( $pending[ $event ] );
			update_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_pending', $pending );
		}
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_action_upsell' ) ) {
	function wc_blacklist_manager_render_action_upsell( $surface, array $args = array() ) {
		if ( wc_blacklist_manager_action_upsells_premium_active() ) {
			return false;
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$catalog = wc_blacklist_manager_action_upsell_catalog();
		$pending = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_pending' );

		if ( empty( $pending ) ) {
			return false;
		}

		arsort( $pending );

		foreach ( $pending as $event => $queued_at ) {
			if ( empty( $catalog[ $event ] ) ) {
				continue;
			}

			$config = $catalog[ $event ];
			if ( ! in_array( $surface, $config['surfaces'], true ) ) {
				continue;
			}

			if ( wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) ) {
				wc_blacklist_manager_action_upsell_clear_pending( $user_id, $event );
				continue;
			}

			echo wp_kses_post( wc_blacklist_manager_action_upsell_markup( $event, $config, $args ) );

			wc_blacklist_manager_action_upsell_mark_shown( $user_id, $event );
			wc_blacklist_manager_action_upsell_clear_pending( $user_id, $event );
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_static_action_upsell' ) ) {
	function wc_blacklist_manager_render_static_action_upsell( $event, $surface, array $args = array() ) {
		if ( wc_blacklist_manager_action_upsells_premium_active() ) {
			return false;
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		$catalog = wc_blacklist_manager_action_upsell_catalog();

		if ( ! $user_id || empty( $catalog[ $event ] ) ) {
			return false;
		}

		$config = $catalog[ $event ];
		if (
			! in_array( $surface, $config['surfaces'], true ) ||
			wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) ||
			wc_blacklist_manager_action_upsell_recently_shown( $user_id, $event, $config['cooldown'] )
		) {
			return false;
		}

		echo wp_kses_post( wc_blacklist_manager_action_upsell_markup( $event, $config, $args ) );
		wc_blacklist_manager_action_upsell_mark_shown( $user_id, $event );
		return true;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_dismiss_action_upsell' ) ) {
	function wc_blacklist_manager_dismiss_action_upsell() {
		$event = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';

		if ( '' === $event ) {
			wp_die( esc_html__( 'Invalid prompt.', 'wc-blacklist-manager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'wc_blacklist_manager_dismiss_action_upsell_' . $event );

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		if ( $user_id ) {
			$dismissed           = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_dismissed' );
			$dismissed[ $event ] = time();
			update_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_dismissed', $dismissed );
			wc_blacklist_manager_action_upsell_clear_pending( $user_id, $event );
		}

		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['redirect_to'] ) ) ) : admin_url();
		wp_safe_redirect( wp_validate_redirect( $redirect, admin_url() ) );
		exit;
	}
}

add_action( 'admin_post_wc_blacklist_manager_dismiss_action_upsell', 'wc_blacklist_manager_dismiss_action_upsell' );
