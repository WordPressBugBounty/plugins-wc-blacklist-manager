<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';
?>

<div>
	<?php settings_errors('wc_blacklist_verifications_settings'); ?>

	<?php if (!$premium_active): ?>
		<?php
		wc_blacklist_manager_render_premium_preview_banner(
			array(
				'title'        => __( 'Returning customer trust', 'wc-blacklist-manager' ),
				'description'  => __( 'Use completed-order history to record returning-customer trust and approved policy exemptions that can reduce repeat verification where current policies allow.', 'wc-blacklist-manager' ),
				'unlock_url'   => $unlock_url,
				'context'      => 'verifications',
				'icon'         => 'dashicons-yes-alt',
				'candidate_id' => 'premium.passive.verifications.advanced.banner',
			)
		);
		?>
		<?php if ($woocommerce_active): ?>
			<?php
			wc_blacklist_manager_render_premium_preview_cards(
				array(
					array(
						'icon'        => 'dashicons-database-import',
						'title'       => __( 'Completed-order trust import', 'wc-blacklist-manager' ),
						'description' => __( 'Record returning-customer trust and approved policy exemptions from completed orders. This does not create current OTP proof.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'        => 'dashicons-phone',
						'title'       => __( 'Phone normalization', 'wc-blacklist-manager' ),
						'description' => __( 'Handle leading zeroes and country dial codes more consistently for multi-country stores.', 'wc-blacklist-manager' ),
					),
					array(
						'icon'        => 'dashicons-update',
						'title'       => __( 'Refresh completed-order trust', 'wc-blacklist-manager' ),
						'description' => __( 'Re-run the import when completed-order history changes.', 'wc-blacklist-manager' ),
					),
				),
				array( 'compact' => true )
			);
			?>
		<?php else: ?>
			<p><?php echo esc_html__('No available tool.', 'wc-blacklist-manager'); ?></p>
		<?php endif; ?>
	<?php else: ?>
		<h2><?php echo esc_html__('Returning customer trust', 'wc-blacklist-manager'); ?></h2>
	<?php endif; ?>

	<?php if ($premium_active && $woocommerce_active): ?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="merge_completed_order_whitelist">
						<?php echo esc_html__('Completed-order trust import', 'wc-blacklist-manager'); ?>
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
							<?php echo esc_html__('Import completed orders', 'wc-blacklist-manager'); ?>
						</a>
						<span id="loading_indicator" class="loading-indicator" style="display: none;">
							<img src="<?php echo esc_url(admin_url('images/spinner.gif')); ?>" alt="Loading...">
							<?php echo esc_html__('Importing... Please wait and do not leave the page until it is finished.', 'wc-blacklist-manager'); ?>
						</span>
						<span id="finished_message" class="finished-message" style="display: none; color: green;"></span>
						<p class="description" style="max-width: 500px;">
							<?php echo esc_html__('Use completed orders to record returning-customer trust and the approved policy exemptions that can reduce repeat verification where current policies allow. This does not mark customers as OTP-verified and does not create current verification proof.', 'wc-blacklist-manager'); ?>
						</p>
					<?php else : ?>
						<?php
						$refresh_merge_url = wp_nonce_url(
							admin_url('admin-post.php?action=refresh_merging'),
							'wc_blacklist_refresh_merging'
						);
						?>
						<span style="color: green;">
							<?php echo esc_html__('Completed-order trust imported.', 'wc-blacklist-manager'); ?>
						</span>
						<p>
							<a href="<?php echo esc_url($refresh_merge_url); ?>" id="refresh_button" class="button button-secondary">
								<?php echo esc_html__('Refresh completed-order trust', 'wc-blacklist-manager'); ?>
							</a>
						</p>
						<p class="description" style="max-width: 500px;">
							<?php echo esc_html__('Re-run the import when completed-order history changes. Existing phone-normalization rules continue to apply when order phone identities are processed.', 'wc-blacklist-manager'); ?>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php echo esc_html__('Completed-order phone identities keep their existing dial code when present. Otherwise, the existing phone-normalization rules use the order billing country when processing a leading zero or adding the applicable dial code.', 'wc-blacklist-manager'); ?>
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
			// Import completed-order trust through the existing protected action.
			var mergeButton = document.getElementById('merge_button');
			var loadingIndicator = document.getElementById('loading_indicator');
			var finishedMessage = document.getElementById('finished_message');

			if (mergeButton) {
				mergeButton.addEventListener('click', function () {
					loadingIndicator.style.display = 'inline-block';
					finishedMessage.style.display = 'none';
				});
			}

			window.updateMergeProgress = function (processed, total) {
				if (processed === total) {
					loadingIndicator.style.display = 'none';
					finishedMessage.textContent = 'Completed-order trust imported: ' + total + '/' + total + '.';
					finishedMessage.style.display = 'inline-block';
				} else {
					loadingIndicator.innerHTML = 'Completed orders found: ' + total + '. Importing... ' + processed + '/' + total;
				}
			};
		});
	</script>
</div>
