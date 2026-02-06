<?php
/**
 * Activity Log Template
 *
 * This file displays the detection log entries.
 *
 * @package WC_Blacklist_Manager
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'wc_blacklist_detection_log';
$logs_per_page = 20;

// Get current page (ensure it's at least 1).
$current_page = isset($_GET['paged']) ? max( 1, intval($_GET['paged']) ) : 1;
$offset = ($current_page - 1) * $logs_per_page;

// Get total number of items.
$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

// Calculate the total pages.
$total_pages = ceil( $total_items / $logs_per_page );

// Retrieve log entries for the current page.
$logs = $wpdb->get_results(
    $wpdb->prepare( 
        "SELECT * FROM $table_name ORDER BY `timestamp` DESC LIMIT %d OFFSET %d",
        $logs_per_page,
        $offset
    )
);
?>

<div class="wrap">
	<?php if (!$premium_active): ?>
		<p><?php esc_html_e('Please support us by', 'wc-blacklist-manager'); ?> <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank"><?php esc_html_e('leaving a review', 'wc-blacklist-manager'); ?></a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> <?php esc_html_e('to keep updating & improving.', 'wc-blacklist-manager'); ?></p>
	<?php endif; ?>

	<h1>
		<?php echo esc_html__('Activity logs', 'wc-blacklist-manager'); ?>
		<?php if (get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/activity-logs/" target="_blank" class="button button-secondary" style="display: inline-flex; align-items: center;"><span class="dashicons dashicons-editor-help"></span> Documents</a>
		<?php endif; ?>
		<?php if (!$premium_active): ?>
			<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php esc_html_e('Support / Suggestion', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary">Premium support</a>
		<?php endif; ?>
	</h1>
    
	<?php if ( $premium_active ): ?>
		<form method="post">
			<?php wp_nonce_field( 'bulk_detection_log_delete', 'bulk_detection_log_nonce' ); ?>

			<?php
			// Build & render the List Table (this automatically prints bulk selectors + pagination top/bottom)
			$list_table = new WC_Blacklist_Manager_Activity_Log_Table();
			$list_table->prepare_items();
			$list_table->display();
			?>
		</form>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			// Add lightweight CSS once (mobile-friendly)
			if (!document.getElementById('bm-view-styles')) {
				const style = document.createElement('style');
				style.id = 'bm-view-styles';
				style.textContent = `
					#bm-view-overlay {
						position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000;
					}
					#bm-view-popup {
						position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%);
						background: #fff; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
						max-width: 840px; width: 40vw; max-height: 80vh; overflow: auto; border-radius: 10px; z-index: 10001;
					}
					#bm-view-popup pre {
						white-space: pre-wrap; word-break: break-word; margin: 0;
					}
					#bm-view-popup .bm-close {
						margin-top: 12px; padding: 10px 16px; background: #007cba; color: #fff; border: none;
						border-radius: 6px; cursor: pointer;
					}
					/* Mobile tweaks */
					@media (max-width: 782px) {
						#bm-view-popup {
							width: 92vw; padding: 16px; border-radius: 12px;
							left: 50%; top: 50%; transform: translate(-50%, -50%);
						}
						#bm-view-popup pre { font-size: 13px; line-height: 1.4; }
						#bm-view-popup .bm-close { width: 100%; }
					}
				`;
				document.head.appendChild(style);
			}

			function openPopup(raw) {
				let content;
				try { content = JSON.stringify(JSON.parse(raw || '{}'), null, 2); } catch (e) { content = raw || ''; }

				// Build overlay
				const overlay = document.createElement('div');
				overlay.id = 'bm-view-overlay';

				// Build popup
				const popup = document.createElement('div');
				popup.id = 'bm-view-popup';
				popup.setAttribute('role', 'dialog');
				popup.setAttribute('aria-modal', 'true');
				popup.setAttribute('aria-label', '<?php echo esc_js( __( 'Activity log details', 'wc-blacklist-manager' ) ); ?>');

				const pre = document.createElement('pre');
				pre.textContent = content;

				const closeBtn = document.createElement('button');
				closeBtn.type = 'button';
				closeBtn.className = 'bm-close';
				closeBtn.textContent = '<?php echo esc_js( __( 'Close', 'wc-blacklist-manager' ) ); ?>';

				// Close helper
				function removePopup() {
					document.body.classList.remove('bm-no-scroll');
					window.removeEventListener('keydown', onKeyDown);
					if (popup.parentNode) popup.parentNode.removeChild(popup);
					if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
					// Return focus to the last trigger if available
					if (openPopup.lastTrigger && openPopup.lastTrigger.focus) {
						openPopup.lastTrigger.focus();
					}
				}

				// Keyboard: Esc to close
				function onKeyDown(e) {
					if (e.key === 'Escape') {
						e.preventDefault();
						removePopup();
					}
				}

				closeBtn.addEventListener('click', removePopup);
				overlay.addEventListener('click', removePopup);
				window.addEventListener('keydown', onKeyDown);

				popup.appendChild(pre);
				popup.appendChild(closeBtn);
				document.body.appendChild(overlay);
				document.body.appendChild(popup);

				// Prevent background scroll
				document.body.classList.add('bm-no-scroll');

				// Focus management
				closeBtn.focus();
			}

			// Add small CSS rule to lock scroll when popup is open
			if (!document.getElementById('bm-no-scroll-style')) {
				const lockStyle = document.createElement('style');
				lockStyle.id = 'bm-no-scroll-style';
				lockStyle.textContent = 'body.bm-no-scroll { overflow: hidden !important; }';
				document.head.appendChild(lockStyle);
			}

			document.querySelectorAll('.show-view-data').forEach(function (btn) {
				btn.addEventListener('click', function () {
					openPopup.lastTrigger = this;
					openPopup(this.getAttribute('data-view') || '{}');
				});
			});
		});
		</script>

	<?php else : ?>
		<span class="yo-premium" style="margin-top: 20px;">
			<span class="dashicons dashicons-lock"></span>
			To record and see the activity logs, please upgrade to the premium version.
			<a href="<?php echo esc_url( $unlock_url ); ?>" target="_blank" class="premium-label">Unlock</a><br>
		</span>
		<p><a href="https://yoohw.com/docs/woocommerce-blacklist-manager/activity-logs/activity-logs/" target="_blank">Find out how it performs here</a></p>

		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<select name="action" disabled>
					<option value="-1"><?php esc_html_e( 'Bulk actions', 'wc-blacklist-manager' ); ?></option>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'wc-blacklist-manager' ); ?>" disabled>
			</div>
		</div>

		<table class="wp-list-table widefat fixed striped activity-log-demo">
			<thead>
				<tr>
					<td id="cb" class="manage-column column-cb check-column">
						<input type="checkbox" disabled>
					</td>
					<th scope="col" style="width:14%;"><?php esc_html_e( 'Timestamp', 'wc-blacklist-manager' ); ?></th>
					<th scope="col" style="width:6ch;"><?php esc_html_e( 'Type', 'wc-blacklist-manager' ); ?></th>
					<th scope="col" style="width:15ch;"><?php esc_html_e( 'Source', 'wc-blacklist-manager' ); ?></th>
					<th scope="col" style="width:10ch;"><?php esc_html_e( 'Action', 'wc-blacklist-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'wc-blacklist-manager' ); ?></th>
					<th scope="col" style="width:7ch;"><?php esc_html_e( 'View', 'wc-blacklist-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$now = current_time( 'timestamp' );
				$demo_rows = [
					[
						'offset_min' => 5,
						'type'       => 'human',
						'source'     => 'woo_order_1307',
						'action'     => 'block',
						'details'    => 'ip: 203.0.113.25, email: fraud@example.com, user: 0, reason: Disposable email detected',
						'view'       => wp_json_encode( [ 'ip' => '203.0.113.25', 'score' => 92, 'rules' => [ 'disposable_domain', 'ip_proxy' ] ] ),
					],
					[
						'offset_min' => 18,
						'type'       => 'bot',
						'source'     => 'access',
						'action'     => 'block',
						'details'    => 'ip: 198.51.100.44, user_agent: python-requests, reason: Rate limit exceeded',
						'view'       => wp_json_encode( [ 'ip' => '198.51.100.44', 'attempts' => 184, 'window' => '10m' ] ),
					],
					[
						'offset_min' => 42,
						'type'       => 'human',
						'source'     => 'login',
						'action'     => 'suspect',
						'details'    => 'ip: 2001:db8::1, username: test_user, reason: Too many attempts',
						'view'       => wp_json_encode( [ 'username' => 'test_user', 'failed' => 7, 'captcha' => 'passed' ] ),
					],
					[
						'offset_min' => 69,
						'type'       => 'human',
						'source'     => 'woo_order_1299',
						'action'     => 'verify',
						'details'    => 'ip: 192.0.2.9, email: buyer@example.com, method: Email OTP, result: Passed',
						'view'       => wp_json_encode( [ 'otp_method' => 'email', 'attempts' => 1, 'verified_at' => current_time( 'mysql' ) ] ),
					],
					[
						'offset_min' => 135,
						'type'       => 'bot',
						'source'     => 'cf7_contact_form_7',
						'action'     => 'block',
						'details'    => 'ip: 203.0.113.77, reason: CAPTCHA failed',
						'view'       => wp_json_encode( [ 'captcha' => 'failed', 'threshold' => 'strict' ] ),
					],
					[
						'offset_min' => 182,
						'type'       => 'human',
						'source'     => 'wpforms_quote_request',
						'action'     => 'suspect',
						'details'    => 'ip: 198.51.100.88, email: temp@mailinator.com, reason: Disposable domain',
						'view'       => wp_json_encode( [ 'email' => 'temp@mailinator.com', 'mx' => 'mailinator.com', 'risk' => 'high' ] ),
					],
					[
						'offset_min' => 267,
						'type'       => 'human',
						'source'     => 'register',
						'action'     => 'verify',
						'details'    => 'ip: 192.0.2.50, phone: +1 555-0100, method: SMS OTP, result: Passed',
						'view'       => wp_json_encode( [ 'otp_method' => 'sms', 'phone' => '+1 555-0100', 'latency_ms' => 820 ] ),
					],
					[
						'offset_min' => 305,
						'type'       => 'human',
						'source'     => 'woo_order_1288',
						'action'     => 'remove',
						'details'    => 'ip: 203.0.113.200, email: legit@customer.com, note: Removed from suspect list',
						'view'       => wp_json_encode( [ 'moderator' => 'admin', 'note' => 'whitelisted after review' ] ),
					],
				];

				$icon_map = [
					'human' => plugins_url( '../../../img/spy.svg', __FILE__ ),
					'bot'   => plugins_url( '../../../img/user-robot.svg', __FILE__ ),
				];

				function bm_demo_source_cell( $source ) {
					$base = plugins_url( '../../../img/', __FILE__ );
					$img  = ''; $text = $source;

					if ( preg_match( '/^(woo|cf7|gravity|wpforms)_(.+)$/', $source, $m ) ) {
						$map = [ 'woo' => 'woo.svg', 'cf7' => 'cf7.svg', 'gravity' => 'gravity.svg', 'wpforms' => 'wpforms.svg' ];
						if ( isset( $map[ $m[1] ] ) ) {
							$img = '<img src="' . esc_url( $base . $map[ $m[1] ] ) . '" alt="' . esc_attr( ucfirst( $m[1] ) ) . '" width="16">';
						}
						if ( 'woo' === $m[1] && preg_match( '/^order_(\d+)$/', $m[2], $idm ) ) {
							$text = 'Order&nbsp;#' . absint( $idm[1] ); // no link in demo
						} else {
							$text = ucfirst( str_replace( '_', ' ', $m[2] ) );
						}
					} elseif ( in_array( $source, [ 'access','register','login','checkout','submit','order','comment' ], true ) ) {
						$img  = '<img src="' . esc_url( $base . 'site.svg' ) . '" alt="' . esc_attr( ucfirst( $source ) ) . '" width="16">';
						$text = ucfirst( __( $source, 'wc-blacklist-manager' ) );
					} else {
						$text = ucfirst( __( str_replace( '_', ' ', $source ), 'wc-blacklist-manager' ) );
					}

					return $img . ' ' . esc_html( $text );
				}

				function bm_demo_action_badge( $action ) {
					$map = [
						'block'   => [ 'bm-status-block',   __( 'Block', 'wc-blacklist-manager' ) ],
						'suspect' => [ 'bm-status-suspect', __( 'Suspect', 'wc-blacklist-manager' ) ],
						'verify'  => [ 'bm-status-verify',  __( 'Verify', 'wc-blacklist-manager' ) ],
						'remove'  => [ 'bm-status-verify',  __( 'Remove', 'wc-blacklist-manager' ) ],
						'unblock' => [ 'bm-status-verify',  __( 'Unblock', 'wc-blacklist-manager' ) ],
					];
					return isset( $map[ $action ] )
						? '<span class="' . esc_attr( $map[ $action ][0] ) . '">' . esc_html( $map[ $action ][1] ) . '</span>'
						: esc_html( $action );
				}

				foreach ( $demo_rows as $row ) :
					$ts = $now - ( absint( $row['offset_min'] ) * MINUTE_IN_SECONDS );
					?>
					<tr>
						<td class="check-column"><input type="checkbox" disabled></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ); ?></td>
						<td>
							<?php
							$t = $row['type'];
							if ( isset( $icon_map[ $t ] ) ) {
								echo '<img src="' . esc_url( $icon_map[ $t ] ) . '" alt="' . esc_attr( ucfirst( $t ) ) . '" width="16">';
							} else {
								echo esc_html( $t );
							}
							?>
						</td>
						<td class="activity-log-source"><?php echo wp_kses_post( bm_demo_source_cell( $row['source'] ) ); ?></td>
						<td><?php echo wp_kses_post( bm_demo_action_badge( $row['action'] ) ); ?></td>
						<td>
							<?php
							$entries = preg_split( '/,\s(?=\w+:)/', (string) $row['details'] );
							$out = [];
							foreach ( $entries as $entry ) {
								list( $k, $v ) = array_map( 'trim', explode( ':', $entry, 2 ) + [ '', '' ] );
								$k = str_replace( [ 'suspected_', 'blocked_', 'verified_', '_attempt' ], '', $k );
								$label = ucfirst( str_replace( '_', ' ', $k ) );
								$out[] = esc_html( $label . ': ' . $v );
							}
							echo implode( ', ', $out );
							?>
						</td>
						<td>
							<?php if ( ! empty( $row['view'] ) ) : ?>
								<button type="button" class="button show-view-data icon-button" data-view="<?php echo esc_attr( $row['view'] ); ?>">
									<span class="dashicons dashicons-info-outline"></span>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="tablenav bottom">
			<div class="alignleft actions bulkactions">
				<select name="action" disabled>
					<option value="-1"><?php esc_html_e( 'Bulk actions', 'wc-blacklist-manager' ); ?></option>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'wc-blacklist-manager' ); ?>" disabled>
			</div>
		</div>

		<!-- Reuse the same mobile-friendly popup for demo -->
		<script>
		(function(){
			if (!document.getElementById('bm-view-styles')) {
				const style = document.createElement('style');
				style.id = 'bm-view-styles';
				style.textContent = `
					#bm-view-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; }
					#bm-view-popup { position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px;
						box-shadow: 0 5px 15px rgba(0,0,0,0.3); max-width: 840px; width: 40vw; max-height: 80vh; overflow: auto; border-radius: 10px; z-index: 10001; }
					#bm-view-popup pre { white-space: pre-wrap; word-break: break-word; margin: 0; }
					#bm-view-popup .bm-close { margin-top: 12px; padding: 10px 16px; background: #007cba; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
					@media (max-width: 782px) {
						#bm-view-popup { width: 92vw; padding: 16px; border-radius: 12px; }
						#bm-view-popup pre { font-size: 13px; line-height: 1.4; }
						#bm-view-popup .bm-close { width: 100%; }
					}
					body.bm-no-scroll { overflow: hidden !important; }
				`;
				document.head.appendChild(style);
			}

			function openPopup(raw) {
				let content;
				try { content = JSON.stringify(JSON.parse(raw || '{}'), null, 2); } catch (e) { content = raw || ''; }

				const overlay = document.createElement('div'); overlay.id = 'bm-view-overlay';
				const popup = document.createElement('div'); popup.id = 'bm-view-popup';
				popup.setAttribute('role', 'dialog'); popup.setAttribute('aria-modal', 'true');
				popup.setAttribute('aria-label', '<?php echo esc_js( __( 'Activity log details', 'wc-blacklist-manager' ) ); ?>');

				const pre = document.createElement('pre'); pre.textContent = content;
				const closeBtn = document.createElement('button'); closeBtn.type = 'button'; closeBtn.className = 'bm-close';
				closeBtn.textContent = '<?php echo esc_js( __( 'Close', 'wc-blacklist-manager' ) ); ?>';

				function removePopup(){ document.body.classList.remove('bm-no-scroll'); window.removeEventListener('keydown', onKeyDown);
					popup.remove(); overlay.remove(); if (openPopup.lastTrigger && openPopup.lastTrigger.focus) openPopup.lastTrigger.focus(); }
				function onKeyDown(e){ if (e.key === 'Escape'){ e.preventDefault(); removePopup(); } }

				closeBtn.addEventListener('click', removePopup);
				overlay.addEventListener('click', removePopup);
				window.addEventListener('keydown', onKeyDown);

				popup.append(pre, closeBtn); document.body.append(overlay, popup);
				document.body.classList.add('bm-no-scroll'); closeBtn.focus();
			}

			document.addEventListener('DOMContentLoaded', function(){
				document.querySelectorAll('.show-view-data').forEach(function(btn){
					btn.addEventListener('click', function(){
						openPopup.lastTrigger = this;
						openPopup(this.getAttribute('data-view') || '{}');
					});
				});
			});
		})();
		</script>
	<?php endif; ?>
</div>
