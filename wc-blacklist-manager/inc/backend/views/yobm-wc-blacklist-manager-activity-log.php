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
    
	<?php if ($premium_active): ?>
		<form method="post">
			<?php wp_nonce_field( 'bulk_detection_log_delete', 'bulk_detection_log_nonce' ); ?>
			<!-- Bulk Actions - Top -->
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<select name="action" id="bulk-action-selector-top">
						<option value="-1"><?php esc_html_e( 'Bulk actions', 'wc-blacklist-manager' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'wc-blacklist-manager' ); ?></option>
					</select>
					<input type="submit" name="bulk_submit" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply', 'wc-blacklist-manager' ); ?>" onclick="var action = document.getElementById('bulk-action-selector-top').value; if(action === 'delete'){ var checkboxes = document.querySelectorAll('input[name=\'bulk_ids[]\']:checked'); if(checkboxes.length > 0){ return confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected detection logs?', 'wc-blacklist-manager' ) ); ?>'); } }">
				</div>
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php echo esc_html( number_format_i18n( $total_items ) . ' ' . __( 'items', 'wc-blacklist-manager' ) ); ?>
					</span>
					<?php if ( $total_pages > 1 ) : ?>
						<span class="pagination-links">
							<?php if ( $current_page <= 1 ) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
							<?php else: ?>
								<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'First page', 'wc-blacklist-manager' ); ?></span>
									&laquo;
								</a>
								<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1 ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'wc-blacklist-manager' ); ?></span>
									&lsaquo;
								</a>
							<?php endif; ?>

							<span class="paging-input">
								<label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'wc-blacklist-manager' ); ?></label>
								<input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr( $current_page ); ?>" size="2" aria-describedby="table-paging">
								<span class="tablenav-paging-text"><?php esc_html_e( ' of ', 'wc-blacklist-manager' ); ?><span class="total-pages"><?php echo esc_html( $total_pages ); ?></span></span>
							</span>

							<?php if ( $current_page >= $total_pages ) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
							<?php else: ?>
								<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1 ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'wc-blacklist-manager' ); ?></span>
									&rsaquo;
								</a>
								<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'wc-blacklist-manager' ); ?></span>
									&raquo;
								</a>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped activity-log">
				<thead>
					<tr>
						<td id="cb" class="manage-column column-cb check-column">
							<input type="checkbox" id="cb-select-all">
						</td>
						<th scope="col" style="width: 14%;"><?php esc_html_e( 'Timestamp', 'wc-blacklist-manager' ); ?></th>
						<th scope="col" style="width: 10ch;"><?php esc_html_e( 'Type', 'wc-blacklist-manager' ); ?></th>
						<th scope="col" style="width: 15ch;"><?php esc_html_e( 'Source', 'wc-blacklist-manager' ); ?></th>
						<th scope="col" style="width: 10ch;"><?php esc_html_e( 'Action', 'wc-blacklist-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'wc-blacklist-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $logs ) ) : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="bulk_ids[]" value="<?php echo esc_attr( $log->id ); ?>">
								</th>
								<td>
									<?php 
									echo esc_html( 
										date_i18n( 
											get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
											strtotime( $log->timestamp )
										) 
									); 
									?>
								</td>
								<td>
									<?php 
									if ( 'human' === $log->type ) {
										echo '<img src="' . esc_url( plugins_url( '../../../img/spy.svg', __FILE__ ) ) . '" alt="' . esc_attr__( 'Human', 'wc-blacklist-manager' ) . '" width="16">';
									} elseif ( 'bot' === $log->type ) {
										echo '<img src="' . esc_url( plugins_url( '../../../img/user-robot.svg', __FILE__ ) ) . '" alt="' . esc_attr__( 'Bot', 'wc-blacklist-manager' ) . '" width="16">';
									} else {
										echo esc_html( $log->type );
									}
									?>
								</td>
								<td class="activity-log-source">
									<?php 
									$source = $log->source;
									$img_html = '';
									$text = $source;
									
									// Check for our specific prefixes with an underscore separator.
									if ( preg_match( '/^(woo|cf7|gravity|wpforms)_(.+)$/', $source, $matches ) ) {
										$prefix = $matches[1];
										$remainder = $matches[2];
										
										// Pick the image according to the prefix.
										switch ( $prefix ) {
											case 'woo':
												$img = 'woo.svg';
												break;
											case 'cf7':
												$img = 'cf7.svg';
												break;
											case 'gravity':
												$img = 'gravity.svg';
												break;
											case 'wpforms':
												$img = 'wpforms.svg';
												break;
											default:
												$img = '';
												break;
										}
										
										if ( ! empty( $img ) ) {
											$img_url = plugins_url( '../../../img/' . $img, __FILE__ );
											$img_html = '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( ucfirst( __( $prefix, 'wc-blacklist-manager' ) ) ) . '" width="16">';   
										}
										
										// Remove underscores from the remainder, add spaces and capitalize.
										$text = ucfirst( str_replace( '_', ' ', $remainder ) );
										
									} elseif ( in_array( $source, array( 'access', 'register', 'login', 'checkout', 'submit', 'order' ), true ) ) {
										// For these specific words, use 'site.svg' and make the text translatable.
										$img = 'site.svg';
										$img_url = plugins_url( '../../../img/' . $img, __FILE__ );
										// Translate the source string.
										$translated = ucfirst( __( $source, 'wc-blacklist-manager' ) );
										$img_html = '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $translated ) . '" width="16">';
										$text = $translated;
									} else {
										// Fallback: Replace underscores with spaces and translate the result.
										$text = ucfirst( __( str_replace( '_', ' ', $source ), 'wc-blacklist-manager' ) );
									}
									
									// Output the image and the final text.
									echo $img_html . ' ' . esc_html( $text );
									?>
								</td>
								<td>
									<?php 
									if ( 'block' === $log->action ) {
										echo '<span class="bm-status-block">' . esc_html__( 'Block', 'wc-blacklist-manager' ) . '</span>';
									} elseif ( 'suspect' === $log->action ) {
										echo '<span class="bm-status-suspect">' . esc_html__( 'Suspect', 'wc-blacklist-manager' ) . '</span>';
									} else {
										echo esc_html( $log->action );
									}
									?>
								</td>
								<td><?php echo esc_html( $log->details ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No detection log entries found.', 'wc-blacklist-manager' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Bulk Actions - Bottom -->
			<div class="tablenav bottom">
				<div class="alignleft actions bulkactions">
					<select name="action" id="bulk-action-selector-bottom">
						<option value="-1"><?php esc_html_e( 'Bulk actions', 'wc-blacklist-manager' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'wc-blacklist-manager' ); ?></option>
					</select>
					<input type="submit" name="bulk_submit" id="doaction2" class="button action" value="<?php esc_attr_e( 'Apply', 'wc-blacklist-manager' ); ?>">
				</div>
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php echo esc_html( number_format_i18n( $total_items ) . ' ' . __( 'items', 'wc-blacklist-manager' ) ); ?>
					</span>
					<?php if ( $total_pages > 1 ) : ?>
						<span class="pagination-links">
							<?php if ( $current_page <= 1 ) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
							<?php else: ?>
								<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'First page', 'wc-blacklist-manager' ); ?></span>
									&laquo;
								</a>
								<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1 ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'wc-blacklist-manager' ); ?></span>
									&lsaquo;
								</a>
							<?php endif; ?>

							<span class="paging-input">
								<label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'wc-blacklist-manager' ); ?></label>
								<input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr( $current_page ); ?>" size="2" aria-describedby="table-paging">
								<span class="tablenav-paging-text"><?php esc_html_e( ' of ', 'wc-blacklist-manager' ); ?><span class="total-pages"><?php echo esc_html( $total_pages ); ?></span></span>
							</span>

							<?php if ( $current_page >= $total_pages ) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
							<?php else: ?>
								<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1 ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'wc-blacklist-manager' ); ?></span>
									&rsaquo;
								</a>
								<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>">
									<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'wc-blacklist-manager' ); ?></span>
									&raquo;
								</a>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		</form>
	<?php else : ?>
		<p>
			<?php esc_html_e( 'To record and see the detection log, please upgrade to the premium version.', 'wc-blacklist-manager' ); ?>
			<a href='https://yoohw.com/product/woocommerce-blacklist-manager-premium/' target='_blank' class='premium-label'>Unlock</a>
		</p>
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
					<th scope="col"><?php esc_html_e( 'Timestamp', 'wc-blacklist-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'wc-blacklist-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'wc-blacklist-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'wc-blacklist-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'wc-blacklist-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td colspan="6"><?php esc_html_e('No detection log entries found.', 'wc-blacklist-manager'); ?></td>
				</tr>
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
	<?php endif; ?>
</div>
