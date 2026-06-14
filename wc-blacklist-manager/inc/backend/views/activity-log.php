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

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';

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
			<a href="https://docs.yoohw.com/category/blacklist-manager/" target="_blank" class="button button-secondary yoohw-docs-btn" style="display: inline-flex;"><span class="dashicons dashicons-editor-help"></span> <?php echo esc_html__('Docs', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if (!$premium_active): ?>
			<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php echo esc_html__('Support', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary"><?php echo esc_html__('Support', 'wc-blacklist-manager'); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-blacklist-manager-settings&tab=tools#activity_log_retention' ) ); ?>" class="activity_logs_tool_settings_url">
				<?php esc_html_e( 'Tool settings', 'wc-blacklist-manager' ); ?>
			</a>
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
		<?php
		wc_blacklist_manager_render_premium_preview_banner(
			array(
				'title'       => __( 'Activity logs', 'wc-blacklist-manager' ),
				'description' => __( 'Review what was blocked, suspected, verified, or removed so investigations are based on a clear event history.', 'wc-blacklist-manager' ),
				'unlock_url'  => $unlock_url,
				'context'     => 'activity',
				'icon'        => 'dashicons-list-view',
			)
		);

		wc_blacklist_manager_render_premium_preview_cards(
			array(
				array(
					'icon'        => 'dashicons-search',
					'title'       => __( 'Investigation timeline', 'wc-blacklist-manager' ),
					'description' => __( 'See the source, action, timestamp, and details behind detection events.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-filter',
					'title'       => __( 'Human and bot context', 'wc-blacklist-manager' ),
					'description' => __( 'Separate checkout, access, login, form, and verification events during review.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-backup',
					'title'       => __( 'Retention tools', 'wc-blacklist-manager' ),
					'description' => __( 'Keep logs useful by cleaning old records by age or amount from Premium tools.', 'wc-blacklist-manager' ),
				),
			),
			array( 'columns' => 3 )
		);
		?>
		<p><a href="https://docs.yoohw.com/use-activity-logs-to-review-blocked-attempts/" target="_blank"><?php esc_html_e( 'Learn how activity logs help review blocked attempts.', 'wc-blacklist-manager' ); ?></a></p>
	<?php endif; ?>
</div>
