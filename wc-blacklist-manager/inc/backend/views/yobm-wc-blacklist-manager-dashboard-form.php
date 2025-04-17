<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap">
	<?php if (!$premium_active): ?>
	<p>Please support us by <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
	<?php endif; ?>

	<h1>
		<?php echo esc_html__('Blacklist management', 'wc-blacklist-manager'); ?>
		<?php if (get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/blacklist-management/" target="_blank" class="button button-secondary" style="display: inline-flex; align-items: center;"><span class="dashicons dashicons-editor-help"></span> Documents</a> 
		<?php endif; ?>
			<?php if (!$premium_active): ?>
			<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary">Support / Suggestion</a>
		<?php endif; ?>
		<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary">Premium support</a>
		<?php endif; ?>
	</h1>

	<?php
	if (!empty($this->message)) {
		echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($this->message) . '</p></div>';
	}
	?>
	<div class="wc-blacklist-container">
		<div class="form-column">
			<h2><?php echo esc_html__('Add new', 'wc-blacklist-manager'); ?></h2>
			<?php
			$last_selected_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'suspect';
			
			$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', '0');
			$ip_blocking_enabled = get_option('wc_blacklist_ip_enabled', '0');
			$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', '0');
			$domain_blocking_enabled = get_option('wc_blacklist_domain_enabled', '0');

			$allowed_countries_option = get_option('woocommerce_allowed_countries', 'all');
			$specific_countries = get_option('woocommerce_specific_allowed_countries', []);
			$skip_country_code = ($allowed_countries_option === 'specific' && count($specific_countries) === 1);
			?>
			<?php if (!$premium_active || $customer_name_blocking_enabled === '0'): ?>
				<p class="description"><?php echo esc_html__('You can add only a phone number or an email address or either both.', 'wc-blacklist-manager'); ?></p>
			<?php endif; ?>
			<?php if ($premium_active && $customer_name_blocking_enabled === '1'): ?>
				<p class="description"><?php echo esc_html__('You can add only customer name, phone number or email address or either all.', 'wc-blacklist-manager'); ?></p>
			<?php endif; ?>
			
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="add_suspect_action">
				<table class="form-table">
					<tbody>
						<?php if ($premium_active && $customer_name_blocking_enabled === '1'): ?>
							<tr>
								<th scope="row">
									<label for="new_customer_name">
										<?php echo esc_html__('Customer name', 'wc-blacklist-manager'); ?>
									</label>
								</th>
								<td>
									<input type="text" id="new_first_name" name="new_first_name" placeholder="<?php echo esc_attr__('Enter first name', 'wc-blacklist-manager'); ?>" style="width: 12.25em;" />
									<input type="text" id="new_last_name" name="new_last_name" placeholder="<?php echo esc_attr__('Enter last name', 'wc-blacklist-manager'); ?>" style="width: 12.25em;" />
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<label for="new_phone_number">
									<?php echo esc_html__('Phone number', 'wc-blacklist-manager'); ?>
								</label>
							</th>
							<?php if (!$premium_active || ($premium_active && $skip_country_code)): ?>
								<td>
									<input type="tel" id="new_phone_number" name="new_phone_number" placeholder="<?php echo esc_attr__('Enter phone number', 'wc-blacklist-manager'); ?>" class="regular-text" title="<?php echo esc_attr__('Phone number format: 0123456789 or +19876543210', 'wc-blacklist-manager'); ?>" pattern="[0-9\+]*" />
								</td>
							<?php endif; ?>
							<?php if ($premium_active && !$skip_country_code): ?>
								<td>
									<input type="tel" id="phone_number_holder" name="phone_number_holder" placeholder="<?php echo esc_attr__('Enter phone number', 'wc-blacklist-manager'); ?>" class="regular-text" />
									<input type="hidden" name="phone_dial_code_holder" id="phone_dial_code_holder" value="">
									<input type="hidden" name="new_phone_number" id="new_phone_number" value="">
								</td>
							<?php endif; ?>
						</tr>
						<tr>
							<th scope="row">
								<label for="new_email_address">
									<?php echo esc_html__('Email address', 'wc-blacklist-manager'); ?>
								</label>
							</th>
							<td>
								<input type="email" id="new_email_address" name="new_email_address" placeholder="<?php echo esc_attr__('Enter email address', 'wc-blacklist-manager'); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="status">
									<?php echo esc_html__('Status', 'wc-blacklist-manager'); ?>
								</label>
							</th>
							<td>
								<select id="status" name="status">
									<option value="suspect" <?php selected($last_selected_status, 'suspect'); ?>><?php echo esc_html__('Suspect', 'wc-blacklist-manager'); ?></option>
									<option value="blocked" <?php selected($last_selected_status, 'blocked'); ?>><?php echo esc_html__('Blocked', 'wc-blacklist-manager'); ?></option>
								</select>
							</td>
						</tr>
						<?php wp_nonce_field('add_suspect_action_nonce', 'add_suspect_action_nonce_field'); ?>
						<tr>
							<td colspan="1" style="padding-left: 0;">
								<input type="submit" name="submit" value="<?php echo esc_attr__('Add to blacklist', 'wc-blacklist-manager'); ?>" class="button button-primary" />
							</td>
						</tr>
					</tbody>
				</table>
			</form>
		</div>
		<div class="summary-column">
			<h2><?php echo esc_html__('Stats overview', 'wc-blacklist-manager'); ?></h2>

			<hr>

			<div class="summary-child">
				<div class="entries-column">
					<label><span class="dashicons dashicons-list-view" style="margin-right: 5px;"></span> <?php echo esc_html__('Blacklist entries', 'wc-blacklist-manager'); ?></label>

					<table>
						<tbody>
							<tr>
								<th>
									• <?php echo esc_html__( 'Customer name', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($premium_active): ?>
									<?php if ($customer_name_blocking_enabled === '1'): ?>
										<td><?php echo esc_html( get_option( 'wc_blacklist_sum_name', 0 ) ); ?></td>
									<?php else : ?>
										<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
									<?php endif; ?>
								<?php else : ?>
									<td><span class="sum-premium"><?php echo esc_html__( 'Premium', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Phone number', 'wc-blacklist-manager' ); ?>
								</th>
								<td><?php echo esc_html( get_option( 'wc_blacklist_sum_phone', 0 ) ); ?></td>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Email address', 'wc-blacklist-manager' ); ?>
								</th>
								<td><?php echo esc_html( get_option( 'wc_blacklist_sum_email', 0 ) ); ?></td>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'IP address', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($ip_blocking_enabled === '1' || $ip_blocking_enabled === '2'): ?>
									<td><?php echo esc_html( get_option( 'wc_blacklist_sum_ip', 0 ) ); ?></td>
								<?php else : ?>
									<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Address', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($premium_active): ?>
									<?php if ($address_blocking_enabled === '1'): ?>
										<td><?php echo esc_html( get_option( 'wc_blacklist_sum_address', 0 ) ); ?></td>
									<?php else : ?>
										<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
									<?php endif; ?>
								<?php else : ?>
									<td><span class="sum-premium"><?php echo esc_html__( 'Premium', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Email domain', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($domain_blocking_enabled === '1'): ?>
									<td><?php echo esc_html( get_option( 'wc_blacklist_sum_domain', 0 ) ); ?></td>
								<?php else : ?>
									<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									<strong><?php echo esc_html__( 'Total', 'wc-blacklist-manager' ); ?></strong>
								</th>
								<td>
									<strong><?php echo esc_html( get_option( 'wc_blacklist_sum_total', 0 ) ); ?></strong>
								</td>
							</tr>
						</tbody>
					</table>

					<hr>

					<span class="bm-notice" style="display: none;">
						<img src="<?php echo plugins_url('../../../img/shield-slash.svg', __FILE__); ?>" width="14" height="14" alt="<?php esc_attr_e('BMC Protection disabled', 'wc-blacklist-manager'); ?>">
						<?php echo esc_html__('BMC protection is not active yet.', 'wc-blacklist-manager'); ?> <a href=""><?php echo esc_html__('[Enable]', 'wc-blacklist-manager'); ?></a>
					</span>

				</div>
				<div class="attempts-column">
					<label><span class="dashicons dashicons-shield-alt" style="margin-right: 5px;"></span> <?php echo esc_html__('Detection attempts', 'wc-blacklist-manager'); ?></label>

					<table>
						<tbody>
							<tr>
								<th>
									• <?php echo esc_html__( 'Customer name', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($premium_active): ?>
									<?php if ($customer_name_blocking_enabled === '1'): ?>
										<td><?php echo esc_html( get_option( 'wc_blacklist_sum_block_name', 0 ) ); ?></td>
									<?php else : ?>
										<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
									<?php endif; ?>
								<?php else : ?>
									<td><span class="sum-premium"><?php echo esc_html__( 'Premium', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Phone number', 'wc-blacklist-manager' ); ?>
								</th>
								<td><?php echo esc_html( get_option( 'wc_blacklist_sum_block_phone', 0 ) ); ?></td>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Email address', 'wc-blacklist-manager' ); ?>
								</th>
								<td><?php echo esc_html( get_option( 'wc_blacklist_sum_block_email', 0 ) ); ?></td>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'IP address', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($ip_blocking_enabled === '1' || $ip_blocking_enabled === '2'): ?>
									<td><?php echo esc_html( get_option( 'wc_blacklist_sum_block_ip', 0 ) ); ?></td>
								<?php else : ?>
									<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Address', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($premium_active): ?>
									<?php if ($address_blocking_enabled === '1'): ?>
										<td><?php echo esc_html( get_option( 'wc_blacklist_sum_block_address', 0 ) ); ?></td>
									<?php else : ?>
										<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
									<?php endif; ?>
								<?php else : ?>
									<td><span class="sum-premium"><?php echo esc_html__( 'Premium', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									• <?php echo esc_html__( 'Email domain', 'wc-blacklist-manager' ); ?>
								</th>
								<?php if ($domain_blocking_enabled === '1'): ?>
									<td><?php echo esc_html( get_option( 'wc_blacklist_sum_block_domain', 0 ) ); ?></td>
								<?php else : ?>
									<td><span class="sum-disabled"><?php echo esc_html__( 'Disabled', 'wc-blacklist-manager' ); ?></span></td>
								<?php endif; ?>
							</tr>
							<tr>
								<th>
									<?php if ($premium_active): ?>
										• <?php echo esc_html__( 'Other', 'wc-blacklist-manager' ); ?> - 										
									<?php endif; ?>
									<strong><?php echo esc_html__( 'Total', 'wc-blacklist-manager' ); ?></strong>
								</th>
								<td>
									<strong><?php echo esc_html( get_option( 'wc_blacklist_sum_block_total', 0 ) ); ?></strong>
								</td>
							</tr>
						</tbody>
					</table>

					<hr>

					<span class="bm-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-blacklist-manager-activity-logs' ) ); ?>"><?php echo esc_html__('See detection log', 'wc-blacklist-manager'); ?></a>
						<?php if (!$premium_active): ?>
							<a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Upgrade</a>
						<?php endif; ?>
					</span>
				</div>
			</div>
		</div>
	</div>

	<!-- Search Form -->
	<form method="get" action="<?php echo esc_url(admin_url('admin.php?page=wc-blacklist-manager')); ?>">
		<p class="search-box wcbm-search-box">
			<label class="screen-reader-text" for="blacklist_search"><?php echo esc_html__('Search Blacklist', 'wc-blacklist-manager'); ?></label>
			<input type="search" id="blacklist_search" name="blacklist_search" placeholder="<?php echo esc_attr__('Enter to search', 'wc-blacklist-manager'); ?>" value="<?php echo esc_attr($search_query); ?>"/>
			<?php wp_nonce_field('blacklist_search_action', 'blacklist_search_nonce'); ?>
			<input type="submit" id="search-submit" class="button" value="<?php echo esc_attr__('Search', 'wc-blacklist-manager'); ?>"/>
			<input type="hidden" name="page" value="wc-blacklist-manager" />
		</p>
	</form>

	<script>
	document.getElementById('blacklist_search').addEventListener('input', function() {
		var searchInput = document.getElementById('blacklist_search');
		if (searchInput.value === '') {
			searchInput.form.submit();
		}
	});
	</script>

	<!-- Tab Links -->
	<nav class="nav-tab-wrapper">
		<a href="#blacklisted" class="nav-tab nav-tab-active" data-tab="blacklisted"><?php echo esc_html__('Suspects', 'wc-blacklist-manager'); ?></a>
		<a href="#blocked" class="nav-tab" data-tab="blocked"><?php echo esc_html__('Blocklist', 'wc-blacklist-manager'); ?></a>
		<?php if ($ip_blacklist_enabled): ?>
			<a href="#ip-banned" class="nav-tab" data-tab="ip-banned"><?php echo esc_html__('IP blocking', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if ($premium_active && $customer_address_blocking_enabled): ?>
			<a href="#customer-address" class="nav-tab" data-tab="customer-address"><?php echo esc_html__('Address blocking', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>        
		<?php if ($domain_blocking_enabled): ?>
			<a href="#domain-blocking" class="nav-tab" data-tab="domain-blocking"><?php echo esc_html__('Domain blocking', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
	</nav>

	<div class="tab-content">
		<div id="blacklisted" class="tab-pane active">
			<h2><?php echo esc_html__('Suspect entries', 'wc-blacklist-manager'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bulk-action-form">
				<?php wp_nonce_field('yobm_nonce_action', 'yobm_nonce_field'); ?>
				<input type="hidden" name="action" value="handle_bulk_action">
				<div class="tablenav top">
					<div class="actions bulkactions">
						<select name="bulk_action" id="bulk_action">
							<option value=""><?php echo esc_html__('Bulk Actions', 'wc-blacklist-manager'); ?></option>
							<option value="delete"><?php echo esc_html__('Delete', 'wc-blacklist-manager'); ?></option>
						</select>
						<input type="submit" class="button action" value="<?php echo esc_attr__('Apply', 'wc-blacklist-manager'); ?>" onclick="if(document.getElementById('bulk_action').value === 'delete'){ return confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected entries?', 'yo-booking' ) ); ?>'); }">
					</div>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="max-width: 24px;" class="check-column"><input type="checkbox" id="select_all" /></th>
							<?php if ($premium_active && get_option('wc_blacklist_customer_name_blocking_enabled', '0') === '1'): ?>
								<th style="max-width: 20%;"><?php echo esc_html__('Customer name', 'wc-blacklist-manager'); ?></th>
							<?php endif; ?>
							<th style="max-width: 20%;"><?php echo esc_html__('Phone number', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 20%;"><?php echo esc_html__('Email address', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 20%;"><?php echo esc_html__('Date added', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 10%;"><?php echo esc_html__('Source', 'wc-blacklist-manager'); ?></th>
							<th style="width: 100px"><?php echo esc_html__('Actions', 'wc-blacklist-manager'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (count($blacklisted_entries) > 0): ?>
							<?php foreach ($blacklisted_entries as $entry): ?>
								<?php
								$display_row = true;
								if (!$premium_active || get_option('wc_blacklist_customer_name_blocking_enabled', '0') === '0') {
									$display_row = !empty($entry->phone_number) || !empty($entry->email_address);
								}
								if (!$display_row) continue;
								?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr($entry->id); ?>" /></th>
									<?php if ($premium_active && get_option('wc_blacklist_customer_name_blocking_enabled', '0') === '1'): ?>
										<td><?php echo esc_html(trim($entry->first_name . ' ' . $entry->last_name)); ?></td>
									<?php endif; ?>
									<td><?php echo esc_html($entry->phone_number); ?></td>
									<td><?php echo esc_html($entry->email_address); ?></td>
									<td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_added))); ?></td>
									<td>
										<?php
										if (empty($entry->sources)) {
											echo esc_html__('Unknown', 'wc-blacklist-manager');
										} else {
											$pattern = '/^Order ID: (\d+)$/';
											if ($entry->sources === 'manual') {
												echo esc_html__('Manual form', 'wc-blacklist-manager');
											} elseif (preg_match($pattern, $entry->sources, $matches)) {
												$order_id = intval($matches[1]);
												$edit_order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
												echo sprintf(
													'Order ID: <a href="%s">%d</a>',
													esc_url($edit_order_url),
													esc_html($order_id)
												);
											} else {
												echo esc_html($entry->sources);
											}
										}
										?>
									</td>
									<td>
										<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'block', 'id' => $entry->id]), 'block_action')); ?>" class="button red-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to block this entry?', 'wc-blacklist-manager')); ?>')">
											<span class="dashicons dashicons-dismiss"></span>
										</a>
										<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete', 'id' => $entry->id]), 'delete_action')); ?>" class="button button-secondary icon-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to remove this entry?', 'wc-blacklist-manager')); ?>')"><span class="dashicons dashicons-trash"></span></a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else: ?>
							<tr>
								<td colspan="7"><?php echo esc_html__('No matching results found.', 'wc-blacklist-manager'); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php echo esc_html($total_items_blacklisted . ' items'); ?></span>
						<span class="pagination-links">
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blacklisted' => 1])); ?>" title="<?php echo esc_attr__('Go to the first page', 'wc-blacklist-manager'); ?>">&laquo;</a>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blacklisted' => max(1, $current_page_blacklisted - 1)])); ?>" title="<?php echo esc_attr__('Go to the previous page', 'wc-blacklist-manager'); ?>">&lsaquo;</a>
							<span class="paging-input"><?php echo esc_html($current_page_blacklisted . ' of ' . $total_pages_blacklisted); ?></span>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blacklisted' => min($total_pages_blacklisted, $current_page_blacklisted + 1)])); ?>" title="<?php echo esc_attr__('Go to the next page', 'wc-blacklist-manager'); ?>">&rsaquo;</a>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blacklisted' => $total_pages_blacklisted])); ?>" title="<?php echo esc_attr__('Go to the last page', 'wc-blacklist-manager'); ?>">&raquo;</a>
						</span>
					</div>
				</div>
			</form>
		</div>
		<div id="blocked" class="tab-pane" style="display: none;">
			<h2><?php echo esc_html__('Blocked entries', 'wc-blacklist-manager'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bulk-action-form-blocked">
				<?php wp_nonce_field('yobm_nonce_action', 'yobm_nonce_field'); ?>
				<input type="hidden" name="action" value="handle_bulk_action">
				<div class="tablenav top">
					<div class="actions bulkactions">
						<select name="bulk_action" id="bulk_action_blocked">
							<option value=""><?php echo esc_html__('Bulk Actions', 'wc-blacklist-manager'); ?></option>
							<option value="delete"><?php echo esc_html__('Delete', 'wc-blacklist-manager'); ?></option>
						</select>
						<input type="submit" class="button action" value="<?php echo esc_attr__('Apply', 'wc-blacklist-manager'); ?>" onclick="if(document.getElementById('bulk_action_blocked').value === 'delete'){ return confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected entries?', 'yo-booking' ) ); ?>'); }">					</div>
					</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="max-width: 24px;" class="check-column"><input type="checkbox" id="select_all" /></th>
							<?php if ($premium_active && get_option('wc_blacklist_customer_name_blocking_enabled', '0') === '1'): ?>
								<th style="max-width: 20%;"><?php echo esc_html__('Customer name', 'wc-blacklist-manager'); ?></th>
							<?php endif; ?>
							<th style="max-width: 20%;"><?php echo esc_html__('Phone number', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 20%;"><?php echo esc_html__('Email address', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 20%;"><?php echo esc_html__('Date added', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 10%;"><?php echo esc_html__('Source', 'wc-blacklist-manager'); ?></th>
							<th style="width: 100px;"><?php echo esc_html__('Actions', 'wc-blacklist-manager'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (count($blocked_entries) > 0): ?>
							<?php foreach ($blocked_entries as $entry): ?>
								<?php
								$display_row = true;
								if (!$premium_active || get_option('wc_blacklist_customer_name_blocking_enabled', '0') === '0') {
									$display_row = !empty($entry->phone_number) || !empty($entry->email_address);
								}
								if (!$display_row) continue;
								?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr($entry->id); ?>" /></th>
									<?php if ($premium_active && get_option('wc_blacklist_customer_name_blocking_enabled', '0') === '1'): ?>
										<td><?php echo esc_html(trim($entry->first_name . ' ' . $entry->last_name)); ?></td>
									<?php endif; ?>
									<td><?php echo esc_html($entry->phone_number); ?></td>
									<td><?php echo esc_html($entry->email_address); ?></td>
									<td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_added))); ?></td>
									<td>
										<?php
										if (empty($entry->sources)) {
											echo esc_html__('Unknown', 'wc-blacklist-manager');
										} else {
											$pattern = '/^Order ID: (\d+)$/';
											if ($entry->sources === 'manual') {
												echo esc_html__('Manual form', 'wc-blacklist-manager');
											} elseif (preg_match($pattern, $entry->sources, $matches)) {
												$order_id = intval($matches[1]);
												$edit_order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
												echo sprintf(
													'Order ID: <a href="%s">%d</a>',
													esc_url($edit_order_url),
													esc_html($order_id)
												);
											} else {
												echo esc_html($entry->sources);
											}
										}
										?>
									</td>
									<td>
										<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete', 'id' => $entry->id, 'tab' => 'blocked']), 'delete_action', '_wpnonce')); ?>" class="button button-secondary icon-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to remove this entry?', 'wc-blacklist-manager')); ?>')"><span class="dashicons dashicons-trash"></span></a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else: ?>
							<tr>
								<td colspan="7"><?php echo esc_html__('No matching results found.', 'wc-blacklist-manager'); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php echo esc_html($total_items_blocked . ' items'); ?></span>
						<span class="pagination-links">
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blocked' => 1, 'tab' => 'blocked'])); ?>" title="<?php echo esc_attr__('Go to the first page', 'wc-blacklist-manager'); ?>">&laquo;</a>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blocked' => max(1, $current_page_blocked - 1), 'tab' => 'blocked'])); ?>" title="<?php echo esc_attr__('Go to the previous page', 'wc-blacklist-manager'); ?>">&lsaquo;</a>
							<span class="paging-input"><?php echo esc_html($current_page_blocked . ' of ' . $total_pages_blocked); ?></span>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blocked' => min($total_pages_blocked, $current_page_blocked + 1), 'tab' => 'blocked'])); ?>" title="<?php echo esc_attr__('Go to the next page', 'wc-blacklist-manager'); ?>">&rsaquo;</a>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_blocked' => $total_pages_blocked, 'tab' => 'blocked'])); ?>" title="<?php echo esc_attr__('Go to the last page', 'wc-blacklist-manager'); ?>">&raquo;</a>
						</span>
					</div>
				</div>
			</form>
		</div>
		<?php if ($ip_blacklist_enabled): ?>
			<div id="ip-banned" class="tab-pane" style="display: none;">
				<h2><?php echo esc_html__('IP entries', 'wc-blacklist-manager'); ?></h2>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="add-ip-address-form">
					<?php wp_nonce_field('add_ip_address_nonce_action', 'add_ip_address_nonce_field'); ?>
					<input type="hidden" name="action" value="add_ip_address_action">
					<button type="button" id="add-ip-address-btn" class="button button-primary"><?php echo esc_html__('Add IP address(es)', 'wc-blacklist-manager'); ?></button>
					<div id="add-ip-address-container" style="margin-top: 20px; display: none;">
						<textarea id="ip-address-input" name="ip-addresses" rows="3" placeholder="<?php echo esc_attr__('Enter IP address(es) here... (One per line, max 50 lines)', 'wc-blacklist-manager'); ?>" class="large-text" style="max-width: 500px;"></textarea><br />
						<button type="submit" class="button button-primary" style="margin-bottom: 20px;"><?php echo esc_html__('Submit', 'wc-blacklist-manager'); ?></button>
					</div>
				</form>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bulk-action-form-ip-banned">
					<?php wp_nonce_field('yobm_nonce_action', 'yobm_nonce_field'); ?>
					<input type="hidden" name="action" value="handle_bulk_action">
					<div class="tablenav top">
						<div class="actions bulkactions">
							<select name="bulk_action" id="bulk_action_ip_banned">
								<option value=""><?php echo esc_html__('Bulk Actions', 'wc-blacklist-manager'); ?></option>
								<option value="delete"><?php echo esc_html__('Delete', 'wc-blacklist-manager'); ?></option>
							</select>
							<input type="submit" class="button action" value="<?php echo esc_attr__('Apply', 'wc-blacklist-manager'); ?>" onclick="if(document.getElementById('bulk_action_ip_banned').value === 'delete'){ return confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected entries?', 'yo-booking' ) ); ?>'); }">						</div>
						</div>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="max-width: 24px;" class="check-column"><input type="checkbox" id="select_all_ip_banned" /></th>
								<th style="max-width: 20%;"><?php echo esc_html__('IP address', 'wc-blacklist-manager'); ?></th>
								<th style="max-width: 20%;"><?php echo esc_html__('Date added', 'wc-blacklist-manager'); ?></th>
								<th style="max-width: 10%;"><?php echo esc_html__('Source', 'wc-blacklist-manager'); ?></th>
								<th style="max-width: 10%;"><?php echo esc_html__('Status', 'wc-blacklist-manager'); ?></th>
								<th style="width: 100px;"><?php echo esc_html__('Actions', 'wc-blacklist-manager'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if (count($ip_banned_entries) > 0): ?>
								<?php foreach ($ip_banned_entries as $entry): ?>
									<tr>
										<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr($entry->id); ?>" /></th>
										<td><?php echo esc_html($entry->ip_address); ?></td>
										<td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_added))); ?></td>
										<td>
											<?php
											if (empty($entry->sources)) {
												echo esc_html__('Unknown', 'wc-blacklist-manager');
											} else {
												$pattern = '/^Order ID: (\d+)$/';
												if ($entry->sources === 'manual') {
													echo esc_html__('Manual form', 'wc-blacklist-manager');
												} elseif (preg_match($pattern, $entry->sources, $matches)) {
													$order_id = intval($matches[1]);
													$edit_order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
													echo sprintf(
														'Order ID: <a href="%s">%d</a>',
														esc_url($edit_order_url),
														esc_html($order_id)
													);
												} else {
													echo esc_html($entry->sources);
												}
											}
											?>
										</td>
										<td><?php echo esc_html($entry->is_blocked ? __('Blocked', 'wc-blacklist-manager') : __('Suspect', 'wc-blacklist-manager')); ?></td>
										<td>
											<?php if (!$entry->is_blocked): ?>
												<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'block', 'id' => $entry->id]), 'block_action')); ?>" class="button red-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to block this entry?', 'wc-blacklist-manager')); ?>')">
													<span class="dashicons dashicons-dismiss"></span>
												</a>
											<?php endif; ?>
											<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete', 'id' => $entry->id]), 'delete_action', '_wpnonce')); ?>" class="button button-secondary icon-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to remove this entry?', 'wc-blacklist-manager')); ?>')"><span class="dashicons dashicons-trash"></span></a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else: ?>
								<tr>
									<td colspan="6"><?php echo esc_html__('No IP banned entries found.', 'wc-blacklist-manager'); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
					<div class="tablenav">
						<div class="tablenav-pages">
							<span class="displaying-num"><?php echo esc_html($total_items_ip_banned . ' items'); ?></span>
							<span class="pagination-links">
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_ip_banned' => 1])); ?>" title="<?php echo esc_attr__('Go to the first page', 'wc-blacklist-manager'); ?>">&laquo;</a>
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_ip_banned' => max(1, $current_page_ip_banned - 1)])); ?>" title="<?php echo esc_attr__('Go to the previous page', 'wc-blacklist-manager'); ?>">&lsaquo;</a>
								<span class="paging-input"><?php echo esc_html($current_page_ip_banned . ' of ' . $total_pages_ip_banned); ?></span>
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_ip_banned' => min($total_pages_ip_banned, $current_page_ip_banned + 1)])); ?>" title="<?php echo esc_attr__('Go to the next page', 'wc-blacklist-manager'); ?>">&rsaquo;</a>
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_ip_banned' => $total_pages_ip_banned])); ?>" title="<?php echo esc_attr__('Go to the last page', 'wc-blacklist-manager'); ?>">&raquo;</a>
							</span>
						</div>
					</div>
				</form>
			</div>
			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function() {
					var addIpAddressBtn = document.getElementById('add-ip-address-btn');
					var addIpAddressContainer = document.getElementById('add-ip-address-container');

					addIpAddressBtn.addEventListener('click', function() {
						if (addIpAddressContainer.style.display === 'none') {
							addIpAddressContainer.style.display = 'block';
						} else {
							addIpAddressContainer.style.display = 'none';
						}
					});
				});
			</script>
		<?php endif; ?>

		<?php if ($premium_active && $customer_address_blocking_enabled): ?>
		<div id="customer-address" class="tab-pane" style="display: none;">
			<h2><?php echo esc_html__('Address entries', 'wc-blacklist-manager'); ?></h2>

			<!-- Form for adding addresses -->
			<button type="button" id="add-address-btn" class="button button-primary"><?php echo esc_html__('Add address', 'wc-blacklist-manager'); ?></button>

			<?php
			$allowed_countries = wc()->countries->get_allowed_countries();
			$single_country = count($allowed_countries) === 1;
			$single_country_code = $single_country ? key($allowed_countries) : '';
			?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="add-address-form">

				<?php wp_nonce_field('add_address_nonce_action', 'add_address_nonce_field'); ?>
				<input type="hidden" name="action" value="add_address_action">
				<div id="add-address-container" style="display: none; margin-top: 20px;">
					<p class="description"><?php echo esc_html__('You have to enter the correct format of the address on your site.', 'wc-blacklist-manager'); ?></p>
					<div class="country-state-container">
						<?php if (!$single_country): ?>
							<select id="country-select" name="country" class="wc-enhanced-select" style="max-width: 500px; width: 200px;">
								<option value=""><?php echo esc_html__('Select a country...', 'wc-blacklist-manager'); ?></option>
								<?php
								foreach ($allowed_countries as $country_code => $country_name) {
									echo '<option value="' . esc_attr($country_code) . '">' . esc_html($country_name) . '</option>';
								}
								?>
							</select>
						<?php else: ?>
							<input type="hidden" id="country-select" name="country" value="<?php echo esc_attr($single_country_code); ?>" />
							<span style="max-width: 500px; width: 200px; display: inline-flex; align-items: center; font-weight: 600;"><?php echo esc_html($allowed_countries[$single_country_code]); ?></span>
						<?php endif; ?>

						<span id="hide-state-selection">
							<select id="state-select" name="state" class="wc-enhanced-select" style="max-width: 500px; width: 200px; display: none;">
								<option value=""><?php echo esc_html__('Select a state...', 'wc-blacklist-manager'); ?></option>
							</select>
						</span>  

						<input type="text" id="state-input" name="state_input" placeholder="<?php echo esc_attr__('State', 'wc-blacklist-manager'); ?>" style="max-width: 500px; width: 200px; display: none;" /><br>
					</div>

					<div class="address-1-2-container">
						<input type="text" id="address-1-input" name="address_1_input" placeholder="<?php echo esc_attr__('Address 1', 'wc-blacklist-manager'); ?>" style="max-width: 500px; width: 200px;" />
						<input type="text" id="address-2-input" name="address_2_input" placeholder="<?php echo esc_attr__('Address 2', 'wc-blacklist-manager'); ?>" style="max-width: 500px; width: 200px;" />
					</div>

					<div class="city-postcode-container">
						<input type="text" id="city-input" name="city_input" placeholder="<?php echo esc_attr__('City', 'wc-blacklist-manager'); ?>" style="max-width: 500px; width: 200px;" />
						<input type="text" id="postcode-input" name="postcode_input" placeholder="<?php echo esc_attr__('Postcode / ZIP', 'wc-blacklist-manager'); ?>" style="max-width: 500px; width: 200px;" />
					</div>
						
					<button type="submit" class="button button-primary" style="margin-bottom: 20px;"><?php echo esc_html__('Submit', 'wc-blacklist-manager'); ?></button>
				</div>
			</form>

			<script type="text/javascript">
			jQuery(document).ready(function($) {
				function updateStateField(country) {
					var stateSelect = $('#state-select');
					var stateInput = $('#state-input');
					var stateSpan = $('#hide-state-selection');
					
					stateSelect.empty().append('<option value=""><?php echo esc_html__('Select a state...', 'wc-blacklist-manager'); ?></option>');
					
					if (country) {
						var states = <?php echo json_encode(wc()->countries->get_states()); ?>;
						
						if (states[country] && Object.keys(states[country]).length > 0) {
							$.each(states[country], function(code, name) {
								stateSelect.append('<option value="' + code + '">' + name + '</option>');
							});
							stateSelect.show();
							stateSpan.show();
							stateInput.hide();
						} else {
							stateSelect.hide();
							stateSpan.hide();
							stateInput.show();
						}
					} else {
						stateSelect.hide();
						stateSpan.hide();
						stateInput.hide();
					}
				}

				<?php if ($single_country): ?>
					updateStateField('<?php echo esc_js($single_country_code); ?>');
				<?php else: ?>
					$('#country-select').change(function() {
						var country = $(this).val();
						updateStateField(country);
					});
				<?php endif; ?>
			});
			</script>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bulk-action-form-address">
				<?php wp_nonce_field('yobm_nonce_action', 'yobm_nonce_field'); ?>
				<input type="hidden" name="action" value="handle_bulk_action_address">
				<div class="tablenav top">
					<div class="actions bulkactions">
						<select name="bulk_action" id="bulk_action_address">
							<option value=""><?php echo esc_html__('Bulk Actions', 'wc-blacklist-manager'); ?></option>
							<option value="delete"><?php echo esc_html__('Delete', 'wc-blacklist-manager'); ?></option>
						</select>
						<input type="submit" class="button action" value="<?php echo esc_attr__('Apply', 'wc-blacklist-manager'); ?>" onclick="if(document.getElementById('bulk_action_address').value === 'delete'){ return confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected entries?', 'yo-booking' ) ); ?>'); }">					</div>
					</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="max-width: 24px;" class="check-column"><input type="checkbox" id="select_all_address" /></th>
							<th style="width: 40%;"><?php echo esc_html__('Customer address', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 20%;"><?php echo esc_html__('Date added', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 10%;"><?php echo esc_html__('Source', 'wc-blacklist-manager'); ?></th>
							<th style="max-width: 10%;"><?php echo esc_html__('Status', 'wc-blacklist-manager'); ?></th>
							<th style="width: 100px;"><?php echo esc_html__('Actions', 'wc-blacklist-manager'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (count($address_blocking_entries) > 0): ?>
							<?php foreach ($address_blocking_entries as $entry): ?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr($entry->id); ?>" /></th>
									<td><?php echo esc_html($entry->customer_address); ?></td>
									<td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_added))); ?></td>
									<td>
										<?php
										if (empty($entry->sources)) {
											echo esc_html__('Unknown', 'wc-blacklist-manager');
										} else {
											$pattern = '/^Order ID: (\d+)$/';
											if ($entry->sources === 'manual') {
												echo esc_html__('Manual form', 'wc-blacklist-manager');
											} elseif (preg_match($pattern, $entry->sources, $matches)) {
												$order_id = intval($matches[1]);
												$edit_order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
												echo sprintf(
													'Order ID: <a href="%s">%d</a>',
													esc_url($edit_order_url),
													esc_html($order_id)
												);
											} else {
												echo esc_html($entry->sources);
											}
										}
										?>
									</td>
									<td><?php echo esc_html($entry->is_blocked ? __('Blocked', 'wc-blacklist-manager') : __('Suspect', 'wc-blacklist-manager')); ?></td>
									<td>
										<?php if (!$entry->is_blocked): ?>
											<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'block', 'id' => $entry->id]), 'block_action')); ?>" class="button red-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to block this entry?', 'wc-blacklist-manager')); ?>')">
												<span class="dashicons dashicons-dismiss"></span>
											</a>
										<?php endif; ?>
										<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete', 'id' => $entry->id, 'tab' => 'customer-address']), 'delete_action', '_wpnonce')); ?>" class="button button-secondary icon-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to remove this entry?', 'wc-blacklist-manager')); ?>')"><span class="dashicons dashicons-trash"></span></a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else: ?>
							<tr>
								<td colspan="6"><?php echo esc_html__('No address blocking entries found.', 'wc-blacklist-manager'); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php echo esc_html($total_items_address_blocking . ' items'); ?></span>
						<span class="pagination-links">
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_address_blocking' => 1])); ?>" title="<?php echo esc_attr__('Go to the first page', 'wc-blacklist-manager'); ?>">&laquo;</a>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_address_blocking' => max(1, $current_page_address_blocking - 1)])); ?>" title="<?php echo esc_attr__('Go to the previous page', 'wc-blacklist-manager'); ?>">&lsaquo;</a>
							<span class="paging-input"><?php echo esc_html($current_page_address_blocking . ' of ' . $total_pages_address_blocking); ?></span>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_address_blocking' => min($total_pages_address_blocking, $current_page_address_blocking + 1)])); ?>" title="<?php echo esc_attr__('Go to the next page', 'wc-blacklist-manager'); ?>">&rsaquo;</a>
							<a class="button" href="<?php echo esc_url(add_query_arg(['paged_address_blocking' => $total_pages_address_blocking])); ?>" title="<?php echo esc_attr__('Go to the last page', 'wc-blacklist-manager'); ?>">&raquo;</a>
						</span>
					</div>
				</div>
			</form>
		</div>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				var addAddressBtn = document.getElementById('add-address-btn');
				var addAddressContainer = document.getElementById('add-address-container');

				addAddressBtn.addEventListener('click', function() {
					if (addAddressContainer.style.display === 'none') {
						addAddressContainer.style.display = 'block';
					} else {
						addAddressContainer.style.display = 'none';
					}
				});
			});
		</script>
		<?php endif; ?>
		
		<?php if ($domain_blocking_enabled): ?>
			<div id="domain-blocking" class="tab-pane" style="display: none;">
				<h2><?php echo esc_html__('Domain entries', 'wc-blacklist-manager'); ?></h2>
				<p class="description"><?php echo esc_html__('This is the blocklist of email domains.', 'wc-blacklist-manager'); ?></p>
				
				<!-- Form for adding domains -->
				<button type="button" id="add-domain-btn" class="button button-primary"><?php echo esc_html__('Add domain(s)', 'wc-blacklist-manager'); ?></button>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="add-domain-form">
					<?php wp_nonce_field('add_domain_nonce_action', 'add_domain_nonce_field'); ?>
					<input type="hidden" name="action" value="add_domain_action">
					<div id="add-domain-container" style="display: none; margin-top: 20px;">
						<textarea id="domain-input" name="domains" rows="3" placeholder="<?php echo esc_attr__('Enter domain(s) here... (One per line, max 50 lines)', 'wc-blacklist-manager'); ?>" class="large-text" style="max-width: 500px;"></textarea><br />
						<button type="submit" class="button button-primary" style="margin-bottom: 20px;"><?php echo esc_html__('Submit', 'wc-blacklist-manager'); ?></button>
					</div>
				</form>

				<!-- Bulk action form -->
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bulk-action-form-domain">
					<?php wp_nonce_field('yobm_nonce_action', 'yobm_nonce_field'); ?>
					<input type="hidden" name="action" value="handle_bulk_action">
					<div class="tablenav top">
						<div class="actions bulkactions">
							<select name="bulk_action" id="bulk_action_domain">
								<option value=""><?php echo esc_html__('Bulk Actions', 'wc-blacklist-manager'); ?></option>
								<option value="delete"><?php echo esc_html__('Delete', 'wc-blacklist-manager'); ?></option>
							</select>
							<input type="submit" class="button action" value="<?php echo esc_attr__('Apply', 'wc-blacklist-manager'); ?>" onclick="if(document.getElementById('bulk_action_domain').value === 'delete'){ return confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected entries?', 'yo-booking' ) ); ?>'); }">						</div>
						</div>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="max-width: 24px;" class="check-column"><input type="checkbox" id="select_all_domain" /></th>
								<th style="max-width: 30%;"><?php echo esc_html__('Domain', 'wc-blacklist-manager'); ?></th>
								<th style="max-width: 30%;"><?php echo esc_html__('Date added', 'wc-blacklist-manager'); ?></th>
								<th style="width: 100px;"><?php echo esc_html__('Actions', 'wc-blacklist-manager'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($domain_blocking_entries as $entry): ?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr($entry->id); ?>" /></th>
									<td><?php echo esc_html($entry->domain); ?></td>
									<td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_added))); ?></td>
									<td><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete', 'id' => $entry->id]), 'delete_action', '_wpnonce')); ?>" class="button button-secondary icon-button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to remove this entry?', 'wc-blacklist-manager')); ?>')"><span class="dashicons dashicons-trash"></span></a></td>
								</tr>
							<?php endforeach; ?>
							<?php if (empty($domain_blocking_entries)): ?>
								<tr><td colspan="4"><?php echo esc_html__('No domain entries found.', 'wc-blacklist-manager'); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
					<div class="tablenav">
						<div class="tablenav-pages">
							<span class="displaying-num"><?php echo esc_html($total_items_domain_blocking . ' items'); ?></span>
							<span class="pagination-links">
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_domain_blocking' => 1])); ?>" title="<?php echo esc_attr__('Go to the first page', 'wc-blacklist-manager'); ?>">&laquo;</a>
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_domain_blocking' => max(1, $current_page_domain_blocking - 1)])); ?>" title="<?php echo esc_attr__('Go to the previous page', 'wc-blacklist-manager'); ?>">&lsaquo;</a>
								<span class="paging-input"><?php echo esc_html($current_page_domain_blocking . ' of ' . $total_pages_domain_blocking); ?></span>
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_domain_blocking' => min($total_pages_domain_blocking, $current_page_domain_blocking + 1)])); ?>" title="<?php echo esc_attr__('Go to the next page', 'wc-blacklist-manager'); ?>">&rsaquo;</a>
								<a class="button" href="<?php echo esc_url(add_query_arg(['paged_domain_blocking' => $total_pages_domain_blocking])); ?>" title="<?php echo esc_attr__('Go to the last page', 'wc-blacklist-manager'); ?>">&raquo;</a>
							</span>
						</div>
					</div>
				</form>
			</div>
			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function() {
					var addDomainBtn = document.getElementById('add-domain-btn');
					var addDomainContainer = document.getElementById('add-domain-container');

					addDomainBtn.addEventListener('click', function() {
						if (addDomainContainer.style.display === 'none') {
							addDomainContainer.style.display = 'block';
						} else {
							addDomainContainer.style.display = 'none';
						}
					});
				});
			</script>
		<?php endif; ?>
	</div>
</div>