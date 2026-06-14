<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wc_blacklist_manager_dashboard_insight_table_exists' ) ) {
	function wc_blacklist_manager_dashboard_insight_table_exists( $table_name ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return $found === $table_name;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_dashboard_insight_order_statuses' ) ) {
	function wc_blacklist_manager_dashboard_insight_order_statuses() {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		return array_keys( wc_get_order_statuses() );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_dashboard_insight_recent_order_count' ) ) {
	function wc_blacklist_manager_dashboard_insight_recent_order_count( $days ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		try {
			$result = wc_get_orders(
				array(
					'limit'        => 1,
					'paginate'     => true,
					'return'       => 'ids',
					'status'       => wc_blacklist_manager_dashboard_insight_order_statuses(),
					'date_created' => '>' . ( time() - ( absint( $days ) * DAY_IN_SECONDS ) ),
				)
			);
		} catch ( Exception $e ) {
			return 0;
		}

		if ( is_object( $result ) && isset( $result->total ) ) {
			return absint( $result->total );
		}

		return is_array( $result ) ? count( $result ) : 0;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_dashboard_insight_repeated_patterns_count' ) ) {
	function wc_blacklist_manager_dashboard_insight_repeated_patterns_count() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_blacklist';
		if ( ! wc_blacklist_manager_dashboard_insight_table_exists( $table_name ) ) {
			return 0;
		}

		$phone_sql = "
			SELECT COUNT(*) FROM (
				SELECT COALESCE(NULLIF(normalized_phone, ''), NULLIF(phone_number, '')) AS identity_key
				FROM {$table_name}
				WHERE COALESCE(NULLIF(normalized_phone, ''), NULLIF(phone_number, '')) <> ''
				GROUP BY identity_key
				HAVING COUNT(*) > 1
			) repeated_phone
		";

		$email_sql = "
			SELECT COUNT(*) FROM (
				SELECT COALESCE(NULLIF(normalized_email, ''), NULLIF(email_address, '')) AS identity_key
				FROM {$table_name}
				WHERE COALESCE(NULLIF(normalized_email, ''), NULLIF(email_address, '')) <> ''
				GROUP BY identity_key
				HAVING COUNT(*) > 1
			) repeated_email
		";

		return absint( $wpdb->get_var( $phone_sql ) ) + absint( $wpdb->get_var( $email_sql ) );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_dashboard_insight_payment_methods' ) ) {
	function wc_blacklist_manager_dashboard_insight_payment_methods( $days ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'orders'  => 0,
				'methods' => 0,
			);
		}

		try {
			$orders = wc_get_orders(
				array(
					'limit'        => 50,
					'orderby'      => 'date',
					'order'        => 'DESC',
					'return'       => 'objects',
					'status'       => wc_blacklist_manager_dashboard_insight_order_statuses(),
					'date_created' => '>' . ( time() - ( absint( $days ) * DAY_IN_SECONDS ) ),
				)
			);
		} catch ( Exception $e ) {
			return array(
				'orders'  => 0,
				'methods' => 0,
			);
		}

		$methods = array();
		$orders_with_payment = 0;

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_payment_method' ) ) {
				continue;
			}

			$method = sanitize_key( $order->get_payment_method() );
			if ( '' === $method ) {
				continue;
			}

			$orders_with_payment++;
			$methods[ $method ] = true;
		}

		return array(
			'orders'  => $orders_with_payment,
			'methods' => count( $methods ),
		);
	}
}

if ( ! function_exists( 'wc_blacklist_manager_dashboard_insight_suspicious_order_count' ) ) {
	function wc_blacklist_manager_dashboard_insight_suspicious_order_count( $days ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_blacklist';
		if ( ! wc_blacklist_manager_dashboard_insight_table_exists( $table_name ) ) {
			return 0;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT order_id)
					FROM {$table_name}
					WHERE order_id IS NOT NULL
					AND order_id > 0
					AND date_added >= %s",
					$since
				)
			)
		);
	}
}

if ( ! function_exists( 'wc_blacklist_manager_get_dashboard_locked_insights' ) ) {
	function wc_blacklist_manager_get_dashboard_locked_insights( $days = 30 ) {
		$days              = absint( $days );
		$repeated_patterns = wc_blacklist_manager_dashboard_insight_repeated_patterns_count();
		$payment_data      = wc_blacklist_manager_dashboard_insight_payment_methods( $days );
		$suspicious_orders = wc_blacklist_manager_dashboard_insight_suspicious_order_count( $days );

		$insights = array();

		if ( $suspicious_orders > 0 ) {
			$insights[] = array(
				'icon'        => 'dashicons-controls-repeat',
				'title'       => sprintf(
					_n( '%s order already touched blacklist activity.', '%s orders already touched blacklist activity.', $suspicious_orders, 'wc-blacklist-manager' ),
					number_format_i18n( $suspicious_orders )
				),
				'description' => __( 'Premium automation can flag similar future orders before they require manual review.', 'wc-blacklist-manager' ),
			);
		}

		if ( $repeated_patterns > 0 ) {
			$insights[] = array(
				'icon'        => 'dashicons-admin-users',
				'title'       => sprintf(
					_n( '%s repeated phone/email pattern detected.', '%s repeated phone/email patterns detected.', $repeated_patterns, 'wc-blacklist-manager' ),
					number_format_i18n( $repeated_patterns )
				),
				'description' => __( 'Premium automation can turn repeated risky identities into rules.', 'wc-blacklist-manager' ),
			);
		}

		if ( $payment_data['methods'] > 0 ) {
			$insights[] = array(
				'icon'        => 'dashicons-credit-card',
				'title'       => sprintf(
					_n( '%s payment method seen in recent orders.', '%s payment methods seen in recent orders.', $payment_data['methods'], 'wc-blacklist-manager' ),
					number_format_i18n( $payment_data['methods'] )
				),
				'description' => __( 'Premium Payment Intelligence can use payment context inside risk scoring.', 'wc-blacklist-manager' ),
			);
		}

		return array_slice( $insights, 0, 2 );
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_dashboard_locked_insights' ) ) {
	function wc_blacklist_manager_render_dashboard_locked_insights( $days = 30 ) {
		if (
			function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available()
		) {
			return;
		}

		$insights   = wc_blacklist_manager_get_dashboard_locked_insights( $days );
		$unlock_url = 'https://yoohw.com/product/blacklist-manager-premium/';

		if ( empty( $insights ) ) {
			return;
		}
		?>
		<section class="yobm-dashboard-insights yobm-dashboard-insights--mini" aria-label="<?php esc_attr_e( 'Premium insights preview', 'wc-blacklist-manager' ); ?>">
			<div class="yobm-dashboard-insights__topline">
				<div class="yobm-dashboard-insights__label">
					<span class="dashicons dashicons-lock"></span>
					<span><?php esc_html_e( 'Premium insights', 'wc-blacklist-manager' ); ?></span>
				</div>

				<a href="<?php echo esc_url( $unlock_url ); ?>" target="_blank" rel="noopener noreferrer" class="button-link yobm-dashboard-insights__cta">
					<?php esc_html_e( 'Unlock Risk Scoring', 'wc-blacklist-manager' ); ?>
				</a>
			</div>

			<ul class="yobm-dashboard-insights__rows">
				<?php foreach ( $insights as $insight ) : ?>
					<li class="yobm-dashboard-insight" title="<?php echo esc_attr( $insight['description'] ); ?>">
						<span class="dashicons <?php echo esc_attr( $insight['icon'] ); ?>"></span>
						<strong><?php echo esc_html( $insight['title'] ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
