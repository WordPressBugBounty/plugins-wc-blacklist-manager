<?php
/**
 * Core-owned settings surface for local WooCommerce REST review identity policy.
 *
 * @package WC_Blacklist_Manager
 */

defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Manager_REST_Review_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/** @return void */
	public function add_settings_page() {
		add_submenu_page(
			'wc-blacklist-manager',
			__( 'REST Review Protection', 'wc-blacklist-manager' ),
			__( 'REST Review Protection', 'wc-blacklist-manager' ),
			'manage_options',
			'wc-blacklist-manager-rest-review',
			array( $this, 'render_settings_page' )
		);
	}

	/** @return void */
	public function register_setting() {
		register_setting(
			'wc_blacklist_rest_review',
			'wc_blacklist_enable_woo_rest_review_local_identity',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'sanitize_enabled' ),
			)
		);

		do_action( 'wc_blacklist_manager_rest_review_register_settings_v1', 'wc_blacklist_rest_review' );
	}

	/**
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_enabled( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/** @return void */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		$enabled = 1 === (int) get_option( 'wc_blacklist_enable_woo_rest_review_local_identity', 0 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WooCommerce REST Review Protection', 'wc-blacklist-manager' ); ?></h1>
			<p><?php echo esc_html__( 'Control local blacklist checks for authenticated WooCommerce REST product-review create and update requests.', 'wc-blacklist-manager' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( 'wc_blacklist_rest_review' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Local reviewer identity protection', 'wc-blacklist-manager' ); ?></th>
						<td>
							<input type="hidden" name="wc_blacklist_enable_woo_rest_review_local_identity" value="0">
							<label>
								<input type="checkbox" name="wc_blacklist_enable_woo_rest_review_local_identity" value="1" <?php checked( $enabled ); ?>>
								<?php echo esc_html__( 'Check valid reviewer emails against the local blocked and suspect email lists.', 'wc-blacklist-manager' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Blocked emails are denied. Suspect emails remain allowed and are recorded only after WooCommerce stores the review.', 'wc-blacklist-manager' ); ?>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Reviewer-email domains are also checked only when Premium is available and the existing domain and comment-domain protections are enabled.', 'wc-blacklist-manager' ); ?>
							</p>
							<p class="description">
								<?php echo esc_html__( 'This control is separate from the WooCommerce REST order-protection setting.', 'wc-blacklist-manager' ); ?>
							</p>
						</td>
					</tr>
					<?php do_action( 'wc_blacklist_manager_rest_review_settings_fields_v1' ); ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

new WC_Blacklist_Manager_REST_Review_Settings();
