<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';
?>

<div>
	<?php settings_errors('wc_blacklist_verifications_settings'); ?>

	<form method="post" action="">
		<?php if (!$premium_active): ?>
			<?php
			wc_blacklist_manager_render_premium_preview_banner(
				array(
					'title'       => __( 'Verification tools', 'wc-blacklist-manager' ),
					'description' => __( 'Use completed order history to reduce verification friction for legitimate returning customers.', 'wc-blacklist-manager' ),
					'unlock_url'  => $unlock_url,
					'context'     => 'verifications',
					'icon'        => 'dashicons-yes-alt',
				)
			);
			?>
			<?php if ($woocommerce_active): ?>
				<?php
				wc_blacklist_manager_render_premium_preview_cards(
					array(
						array(
							'icon'        => 'dashicons-database-import',
							'title'       => __( 'Merge completed orders', 'wc-blacklist-manager' ),
							'description' => __( 'Add completed order emails and normalized phone numbers to the verified list in one maintenance flow.', 'wc-blacklist-manager' ),
						),
						array(
							'icon'        => 'dashicons-phone',
							'title'       => __( 'Phone normalization', 'wc-blacklist-manager' ),
							'description' => __( 'Handle leading zeroes and country dial codes more consistently for multi-country stores.', 'wc-blacklist-manager' ),
						),
						array(
							'icon'        => 'dashicons-update',
							'title'       => __( 'Refresh merge state', 'wc-blacklist-manager' ),
							'description' => __( 'Re-run the merge later when completed order history changes.', 'wc-blacklist-manager' ),
						),
					),
					array( 'columns' => 3 )
				);
				?>
			<?php else: ?>
				<p><?php echo esc_html__('No available tool.', 'wc-blacklist-manager'); ?></p>
			<?php endif; ?>
		<?php else: ?>
			<h2><?php echo esc_html__('Tools', 'wc-blacklist-manager'); ?></h2>
		<?php endif; ?>

		<?php if ($premium_active && $woocommerce_active): ?>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="merge_completed_order_whitelist">
							<?php echo esc_html__('Verifications merge:', 'wc-blacklist-manager'); ?>
						</label>
					</th>
					<td>
						<?php if (get_option('wc_blacklist_whitelist_merged_success') != 1) : ?>
							<?php
							$merge_url = wp_nonce_url(
								admin_url('admin-post.php?action=merge_completed_orders_to_whitelist'),
								'wc_blacklist_merge_completed_orders_to_whitelist'
							);
							?>
							<a href="<?php echo esc_url($merge_url); ?>" id="merge_button" class="button button-secondary">
								<?php echo esc_html__('Start to merge', 'wc-blacklist-manager'); ?>
							</a>
							<span id="loading_indicator" class="loading-indicator" style="display: none;">
								<img src="<?php echo esc_url(admin_url('images/spinner.gif')); ?>" alt="Loading...">
								<?php echo esc_html__('Merging... Please wait, DO NOT leave the page until finished.', 'wc-blacklist-manager'); ?>
							</span>
							<span id="finished_message" class="finished-message" style="display: none; color: green;"></span>
							<p class="description" style="max-width: 500px;">
								<?php echo esc_html__('This will set all of the emails and phones from the completed orders to verified. So the return customers will not need to verify their emails or phone numbers anymore.', 'wc-blacklist-manager'); ?>
							</p>
						<?php else : ?>
							<?php
							$refresh_merge_url = wp_nonce_url(
								admin_url('admin-post.php?action=refresh_merging'),
								'wc_blacklist_refresh_merging'
							);
							?>
							<span style="color: green;">
								<?php echo esc_html__('Merged successfully.', 'wc-blacklist-manager'); ?>
							</span>
							<p>
								<a href="<?php echo esc_url($refresh_merge_url); ?>" id="refresh_button" class="button button-secondary">
									<?php echo esc_html__('Refresh merging', 'wc-blacklist-manager'); ?>
								</a>
							</p>
							<p class="description" style="max-width: 500px;">
								<?php echo esc_html__('Refresh to re-merge the emails and phones from the completed orders to be verified again.', 'wc-blacklist-manager'); ?>
							</p>
						<?php endif; ?>
						<p class="description">
							<?php echo esc_html__('This feature updates the verification list from completed orders. If the phone number already has dial code (example: +1), the phone number remains unchanged. If the phone number has a leading 0, that 0 is removed and the appropriate dial code based on the order billing country is added, if not, then just add the dial code.', 'wc-blacklist-manager'); ?>
						</p>
					</td>
				</tr>
			</table>
		<?php else: ?>
			<p>
				<?php echo esc_html__('No available tool.', 'wc-blacklist-manager'); ?>
			</p>
		<?php endif; ?>

		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function () {
				// Merge the complepleted order to whitelist
				var mergeButton = document.getElementById('merge_button');
				var loadingIndicator = document.getElementById('loading_indicator');
				var finishedMessage = document.getElementById('finished_message');

				if (mergeButton) {
					mergeButton.addEventListener('click', function (e) {
						loadingIndicator.style.display = 'inline-block';
						finishedMessage.style.display = 'none';
					});
				}

				window.updateMergeProgress = function (processed, total) {
					if (processed === total) {
						loadingIndicator.style.display = 'none';
						finishedMessage.textContent = 'All done! Finished ' + total + '/' + total + '.';
						finishedMessage.style.display = 'inline-block';
					} else {
						loadingIndicator.innerHTML = 'Completed orders found: ' + total + '. Merging... ' + processed + '/' + total;
					}
				};
			});
		</script>
	</form>
</div>
