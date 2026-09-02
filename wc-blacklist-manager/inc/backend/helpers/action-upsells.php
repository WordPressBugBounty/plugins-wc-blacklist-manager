<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_catalog' ) ) {
	function wc_blacklist_manager_action_upsell_catalog() {
		$premium_action = WC_Blacklist_Manager_Commercial_Router::premium_action();
		$premium_url    = ! empty( $premium_action['url'] ) ? $premium_action['url'] : WC_Blacklist_Manager_Commercial_Router::premium_product_url();

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
				'message'   => __( 'Premium adds Twilio/TextMagic phone OTP and phone intelligence.', 'wc-blacklist-manager' ),
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

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_snoozed' ) ) {
	function wc_blacklist_manager_action_upsell_snoozed( $user_id, $event ) {
		$snoozed_until = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_snoozed_until' );

		return ! empty( $snoozed_until[ $event ] ) && time() < (int) $snoozed_until[ $event ];
	}
}

if ( ! function_exists( 'wc_blacklist_manager_queue_action_upsell' ) ) {
	function wc_blacklist_manager_queue_action_upsell( $event ) {
		$catalog = wc_blacklist_manager_action_upsell_catalog();

		if ( empty( $catalog[ $event ] ) || wc_blacklist_manager_action_upsells_premium_active() ) {
			return false;
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		if ( ! $user_id || wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) || wc_blacklist_manager_action_upsell_snoozed( $user_id, $event ) ) {
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
		if ( ! $user_id || wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) || wc_blacklist_manager_action_upsell_snoozed( $user_id, $event ) ) {
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

if ( ! function_exists( 'wc_blacklist_manager_get_action_upsell_candidates' ) ) {
	/**
	 * Read-only pending candidates for Core's request-local resolver.
	 */
	function wc_blacklist_manager_get_action_upsell_candidates( $surface ) {
		if ( wc_blacklist_manager_action_upsells_premium_active() ) {
			return array();
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$surface    = sanitize_key( (string) $surface );
		$catalog    = wc_blacklist_manager_action_upsell_catalog();
		$pending    = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_pending' );
		$candidates = array();

		foreach ( $pending as $event => $queued_at ) {
			if ( empty( $catalog[ $event ] ) || ! in_array( $surface, $catalog[ $event ]['surfaces'], true ) ) {
				continue;
			}

			if ( wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) || wc_blacklist_manager_action_upsell_snoozed( $user_id, $event ) ) {
				continue;
			}

			$candidates[] = array(
				'id'       => class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
					? WC_Blacklist_Manager_Opportunity_Engine::action_candidate_id( $event, $surface, 'pending' )
					: 'premium.action.pending.' . $surface . '.' . sanitize_key( $event ),
				'target'   => 'premium',
				'priority' => 200,
				'sort'     => 0,
				'recency'  => (int) $queued_at,
				'event'    => sanitize_key( $event ),
			);
		}

		return $candidates;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_get_action_upsell_action_url' ) ) {
	function wc_blacklist_manager_get_action_upsell_action_url( $event, $mode ) {
		$event = sanitize_key( $event );
		$mode  = 'dismiss' === $mode ? 'dismiss' : 'snooze';

		$url = add_query_arg(
			array(
				'action'      => 'wc_blacklist_manager_dismiss_action_upsell',
				'event'       => $event,
				'mode'        => $mode,
				'redirect_to' => rawurlencode( wc_blacklist_manager_get_current_admin_url() ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'wc_blacklist_manager_dismiss_action_upsell_' . $mode . '_' . $event );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_markup' ) ) {
	function wc_blacklist_manager_action_upsell_markup( $event, array $config, array $args = array() ) {
		$premium_action = WC_Blacklist_Manager_Commercial_Router::premium_action();
		if ( ! empty( $premium_action ) ) {
			$config['url']      = $premium_action['url'];
			$config['external'] = $premium_action['external'];
			if ( WC_Blacklist_Manager_Commercial_Router::PREMIUM_SETUP === WC_Blacklist_Manager_Commercial_Router::premium_state() ) {
				$config['cta'] = $premium_action['label'];
			}
		}
		$inline      = ! empty( $args['inline'] );
		$class_names = $inline
			? 'notice notice-info inline yobm-action-upsell yobm-action-upsell--inline'
			: 'notice notice-info yobm-action-upsell';

		ob_start();
		?>
		<div class="<?php echo esc_attr( $class_names ); ?>">
			<p>
				<strong><?php echo esc_html( $config['title'] ); ?></strong>
				<?php echo esc_html( $config['message'] ); ?>
			</p>
			<p class="yobm-action-upsell__actions">
				<a href="<?php echo esc_url( $config['url'] ); ?>"<?php echo ! empty( $config['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="button button-primary">
					<?php echo esc_html( $config['cta'] ); ?>
				</a>
				<a href="<?php echo esc_url( wc_blacklist_manager_get_action_upsell_action_url( $event, 'snooze' ) ); ?>" class="button-link yobm-action-upsell__dismiss">
					<?php esc_html_e( 'Not now', 'wc-blacklist-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( wc_blacklist_manager_get_action_upsell_action_url( $event, 'dismiss' ) ); ?>" class="button-link yobm-action-upsell__dismiss">
					<?php esc_html_e( 'Don\'t show this again', 'wc-blacklist-manager' ); ?>
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

if ( ! function_exists( 'wc_blacklist_manager_action_upsell_validate_redirect' ) ) {
	function wc_blacklist_manager_action_upsell_validate_redirect( $redirect_to ) {
		if ( null === $redirect_to || '' === $redirect_to ) {
			return admin_url();
		}

		$redirect = esc_url_raw( rawurldecode( wp_unslash( $redirect_to ) ) );
		return wp_validate_redirect( $redirect, false );
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
		$selected_id = '';
		if ( class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' ) ) {
			$winner = WC_Blacklist_Manager_Opportunity_Engine::winner();
			$selected_id = is_array( $winner ) && ! empty( $winner['id'] ) ? (string) $winner['id'] : '';
		}

		foreach ( $pending as $event => $queued_at ) {
			if ( empty( $catalog[ $event ] ) ) {
				continue;
			}

			$config = $catalog[ $event ];
			if ( ! in_array( $surface, $config['surfaces'], true ) ) {
				continue;
			}

			if ( wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) || wc_blacklist_manager_action_upsell_snoozed( $user_id, $event ) ) {
				wc_blacklist_manager_action_upsell_clear_pending( $user_id, $event );
				continue;
			}

			$candidate_id = class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
				? WC_Blacklist_Manager_Opportunity_Engine::action_candidate_id( $event, $surface, 'pending' )
				: '';
			if ( '' !== $selected_id && $candidate_id !== $selected_id ) {
				continue;
			}
			if ( class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' ) && '' === $selected_id ) {
				return false;
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
		return wc_blacklist_manager_render_static_action_upsell_candidates( array( $event ), $surface, $args );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_static_action_upsell_candidates' ) ) {
	function wc_blacklist_manager_render_static_action_upsell_candidates( array $events, $surface, array $args = array() ) {
		if ( wc_blacklist_manager_action_upsells_premium_active() ) {
			return false;
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		$catalog = wc_blacklist_manager_action_upsell_catalog();

		if ( ! $user_id ) {
			return false;
		}

		$surface    = sanitize_key( (string) $surface );
		$additional = array();
		$eligible   = array();

		foreach ( array_values( array_unique( array_map( 'sanitize_key', $events ) ) ) as $event ) {
			if ( empty( $catalog[ $event ] ) ) {
				continue;
			}

			$config = $catalog[ $event ];
			if (
				! in_array( $surface, $config['surfaces'], true ) ||
				wc_blacklist_manager_action_upsell_dismissed( $user_id, $event ) ||
				wc_blacklist_manager_action_upsell_snoozed( $user_id, $event ) ||
				wc_blacklist_manager_action_upsell_recently_shown( $user_id, $event, $config['cooldown'] )
			) {
				continue;
			}

			$candidate_id = class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
				? WC_Blacklist_Manager_Opportunity_Engine::action_candidate_id( $event, $surface, 'static' )
				: 'premium.action.static.' . $surface . '.' . $event;
			$eligible[ $candidate_id ] = $event;
			$additional[] = array(
				'id'       => $candidate_id,
				'target'   => 'premium',
				'priority' => 200,
				'sort'     => 1,
				'recency'  => 0,
			);
		}

		if ( empty( $eligible ) ) {
			return false;
		}

		if ( class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' ) ) {
			$winner = WC_Blacklist_Manager_Opportunity_Engine::winner( $additional );
			$winner_id = is_array( $winner ) && ! empty( $winner['id'] ) ? (string) $winner['id'] : '';
			if ( empty( $eligible[ $winner_id ] ) ) {
				return false;
			}
			$event = $eligible[ $winner_id ];
		} else {
			$event = reset( $eligible );
		}

		echo wp_kses_post( wc_blacklist_manager_action_upsell_markup( $event, $catalog[ $event ], $args ) );
		wc_blacklist_manager_action_upsell_mark_shown( $user_id, $event );
		return true;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_dismiss_action_upsell' ) ) {
	function wc_blacklist_manager_dismiss_action_upsell() {
		$event = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		$mode  = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'dismiss';
		$catalog = wc_blacklist_manager_action_upsell_catalog();

		if ( '' === $event || empty( $catalog[ $event ] ) || ! in_array( $mode, array( 'snooze', 'dismiss' ), true ) ) {
			wp_die( esc_html__( 'Invalid prompt.', 'wc-blacklist-manager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'wc_blacklist_manager_dismiss_action_upsell_' . $mode . '_' . $event );

		$redirect = wc_blacklist_manager_action_upsell_validate_redirect( $_GET['redirect_to'] ?? null );
		if ( ! $redirect ) {
			wp_die( esc_html__( 'Invalid redirect.', 'wc-blacklist-manager' ) );
		}

		$user_id = wc_blacklist_manager_action_upsell_user_id();
		if ( $user_id ) {
			if ( 'snooze' === $mode ) {
				$snoozed_until           = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_snoozed_until' );
				$snoozed_until[ $event ] = time() + ( 30 * DAY_IN_SECONDS );
				update_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_snoozed_until', $snoozed_until );
			} else {
				$dismissed           = wc_blacklist_manager_action_upsell_get_meta_array( $user_id, 'wc_blacklist_manager_action_upsell_dismissed' );
				$dismissed[ $event ] = time();
				update_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_dismissed', $dismissed );
			}
			wc_blacklist_manager_action_upsell_clear_pending( $user_id, $event );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}

add_action( 'admin_post_wc_blacklist_manager_dismiss_action_upsell', 'wc_blacklist_manager_dismiss_action_upsell' );
