<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wc_blacklist_manager_premium_cta_label' ) ) {
	function wc_blacklist_manager_premium_cta_label( $context = 'premium' ) {
		$labels = array(
			'automation'    => __( 'Unlock Automation', 'wc-blacklist-manager' ),
			'scoring'       => __( 'Unlock Risk Scoring', 'wc-blacklist-manager' ),
			'payments'      => __( 'Unlock Payment Intelligence', 'wc-blacklist-manager' ),
			'connection'    => __( 'Unlock Multi-store Sync', 'wc-blacklist-manager' ),
			'integrations'  => __( 'Unlock Integrations', 'wc-blacklist-manager' ),
			'tools'         => __( 'Unlock Premium Tools', 'wc-blacklist-manager' ),
			'verifications' => __( 'Unlock Verification Tools', 'wc-blacklist-manager' ),
			'notifications' => __( 'Unlock Notification Controls', 'wc-blacklist-manager' ),
			'activity'      => __( 'Unlock Activity Logs', 'wc-blacklist-manager' ),
			'anti_bots'     => __( 'Unlock Anti-bot Protection', 'wc-blacklist-manager' ),
			'permission'    => __( 'Unlock Team Permissions', 'wc-blacklist-manager' ),
			'premium'       => __( 'Unlock Premium', 'wc-blacklist-manager' ),
		);

		return isset( $labels[ $context ] ) ? $labels[ $context ] : $labels['premium'];
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_premium_preview_banner' ) ) {
	function wc_blacklist_manager_render_premium_preview_banner( array $args ) {
		$title       = isset( $args['title'] ) ? $args['title'] : __( 'Premium feature', 'wc-blacklist-manager' );
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$unlock_url  = ! empty( $args['unlock_url'] ) ? $args['unlock_url'] : 'https://yoohw.com/product/blacklist-manager-premium/';
		$context     = ! empty( $args['context'] ) ? $args['context'] : 'premium';
		$cta_label   = ! empty( $args['cta_label'] ) ? $args['cta_label'] : wc_blacklist_manager_premium_cta_label( $context );
		$icon        = ! empty( $args['icon'] ) ? $args['icon'] : 'dashicons-lock';
		?>
		<div class="yobm-premium-preview-banner">
			<span class="dashicons <?php echo esc_attr( $icon ); ?> yobm-premium-preview-banner__icon"></span>
			<div class="yobm-premium-preview-banner__copy">
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( '' !== $description ) : ?>
					<p><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
			<a href="<?php echo esc_url( $unlock_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary yobm-premium-preview-banner__button">
				<?php echo esc_html( $cta_label ); ?>
			</a>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_premium_preview_cards' ) ) {
	function wc_blacklist_manager_render_premium_preview_cards( array $cards, array $args = array() ) {
		if ( empty( $cards ) ) {
			return;
		}

		$columns = ! empty( $args['columns'] ) ? absint( $args['columns'] ) : 3;
		$columns = max( 1, min( 4, $columns ) );
		$class   = ! empty( $args['class'] ) ? ' ' . sanitize_html_class( $args['class'] ) : '';
		?>
		<div class="yobm-premium-card-grid yobm-premium-card-grid--<?php echo esc_attr( (string) $columns ); ?><?php echo esc_attr( $class ); ?>">
			<?php foreach ( $cards as $card ) : ?>
				<?php wc_blacklist_manager_render_premium_preview_card( $card ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_premium_preview_card' ) ) {
	function wc_blacklist_manager_render_premium_preview_card( array $card ) {
		$title       = isset( $card['title'] ) ? $card['title'] : '';
		$description = isset( $card['description'] ) ? $card['description'] : '';
		$icon        = ! empty( $card['icon'] ) ? $card['icon'] : 'dashicons-yes-alt';
		$badge       = isset( $card['badge'] ) ? $card['badge'] : '';
		?>
		<div class="yobm-premium-card">
			<div class="yobm-premium-card__top">
				<span class="dashicons <?php echo esc_attr( $icon ); ?> yobm-premium-card__icon"></span>
				<?php if ( '' !== $badge ) : ?>
					<span class="yobm-premium-card__badge"><?php echo esc_html( $badge ); ?></span>
				<?php endif; ?>
			</div>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_premium_preview_tab' ) ) {
	function wc_blacklist_manager_render_premium_preview_tab( array $args ) {
		$tab_id      = isset( $args['tab_id'] ) ? $args['tab_id'] : '';
		$cards       = isset( $args['cards'] ) && is_array( $args['cards'] ) ? $args['cards'] : array();
		$columns     = ! empty( $args['columns'] ) ? absint( $args['columns'] ) : 3;
		$after_cards = isset( $args['after_cards'] ) ? $args['after_cards'] : '';

		if ( '' === $tab_id ) {
			return;
		}
		?>
		<div id="tab-content-<?php echo esc_attr( $tab_id ); ?>" class="tab-content yobm-premium-settings-preview" style="display:none;">
			<?php wc_blacklist_manager_render_premium_preview_banner( $args ); ?>
			<?php wc_blacklist_manager_render_premium_preview_cards( $cards, array( 'columns' => $columns ) ); ?>
			<?php if ( '' !== $after_cards ) : ?>
				<div class="yobm-premium-preview-note">
					<?php echo wp_kses_post( $after_cards ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_premium_inline_cta' ) ) {
	function wc_blacklist_manager_render_premium_inline_cta( $unlock_url, $context = 'premium', $label = '' ) {
		$cta_label = '' !== $label ? $label : wc_blacklist_manager_premium_cta_label( $context );
		?>
		<p class="yobm-premium-cta-row">
			<a href="<?php echo esc_url( $unlock_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary yobm-premium-inline-cta">
				<?php echo esc_html( $cta_label ); ?>
			</a>
		</p>
		<?php
	}
}
