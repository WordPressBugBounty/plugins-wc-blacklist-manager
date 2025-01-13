<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div>
	<?php settings_errors('wc_blacklist_verifications_settings'); ?>

	<form method="post" action="">
		<?php if (!$premium_active): ?>
			<h2 class='premium-text'><?php echo esc_html__('Tools', 'wc-blacklist-manager'); ?> <a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a></h2>
		<?php endif; ?>

		<?php if ($premium_active): ?>
			<h2><?php echo esc_html__('Tools', 'wc-blacklist-manager'); ?></h2>
		<?php endif; ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="merge_completed_order_whitelist" class="<?php echo !$premium_active ? 'premium-text' : ''; ?>">
						<?php echo esc_html__('Verifications merge:', 'wc-blacklist-manager'); ?>
					</label>
				</th>
				<td>
					<?php if (!$premium_active): ?>
						<button id="merge_button" class="button button-secondary" disabled>
							<?php echo esc_html__('Start to merge', 'wc-blacklist-manager'); ?>
						</button>
						<p class="description" style="max-width: 500px; color: #aaaaaa;">
							<?php echo esc_html__('This will set all of the emails and phones from the completed orders to verified. So the return customers will not need to verify their emails or phone numbers anymore.', 'wc-blacklist-manager'); ?>
						</p>
					<?php else: ?>
						<?php if (get_option('wc_blacklist_whitelist_merged_success') != 1) : ?>
							<a href="<?php echo esc_url(admin_url('admin-post.php?action=merge_completed_orders_to_whitelist')); ?>" id="merge_button" class="button button-secondary">
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
							<span style="color: green;">
								<?php echo esc_html__('Merged successfully.', 'wc-blacklist-manager'); ?>
							</span>
							<p>
								<a href="<?php echo esc_url(admin_url('admin-post.php?action=refresh_merging')); ?>" id="refresh_button" class="button button-secondary">
									<?php echo esc_html__('Refresh merging', 'wc-blacklist-manager'); ?>
								</a>
							</p>
							<p class="description" style="max-width: 500px;">
								<?php echo esc_html__('Refresh to re-merge the emails and phones from the completed orders to be verified again.', 'wc-blacklist-manager'); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>

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
