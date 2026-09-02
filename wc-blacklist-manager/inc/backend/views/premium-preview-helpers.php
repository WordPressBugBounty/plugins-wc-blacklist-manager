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
		$premium_action = WC_Blacklist_Manager_Commercial_Router::premium_action();
		$unlock_url  = ! empty( $premium_action['url'] ) ? $premium_action['url'] : ( ! empty( $args['unlock_url'] ) ? $args['unlock_url'] : WC_Blacklist_Manager_Commercial_Router::premium_product_url() );
		$context     = ! empty( $args['context'] ) ? $args['context'] : 'premium';
		$cta_label   = ! empty( $args['cta_label'] ) ? $args['cta_label'] : wc_blacklist_manager_premium_cta_label( $context );
		if ( WC_Blacklist_Manager_Commercial_Router::PREMIUM_SETUP === WC_Blacklist_Manager_Commercial_Router::premium_state() ) {
			$cta_label = $premium_action['label'];
		}
		$icon        = ! empty( $args['icon'] ) ? $args['icon'] : 'dashicons-lock';
		$candidate_id = ! empty( $args['candidate_id'] ) ? (string) $args['candidate_id'] : '';
		$show_action = '' !== $candidate_id
			&& class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
			&& WC_Blacklist_Manager_Opportunity_Engine::is_selected( $candidate_id );
		?>
		<div class="yobm-premium-preview-banner">
			<span class="dashicons <?php echo esc_attr( $icon ); ?> yobm-premium-preview-banner__icon"></span>
			<div class="yobm-premium-preview-banner__copy">
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( '' !== $description ) : ?>
					<p><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $show_action ) : ?>
				<a href="<?php echo esc_url( $unlock_url ); ?>"<?php echo ! empty( $premium_action['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="button button-primary yobm-premium-preview-banner__button">
					<?php echo esc_html( $cta_label ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wc_blacklist_manager_render_premium_preview_cards' ) ) {
	function wc_blacklist_manager_render_premium_preview_cards( array $cards, array $args = array() ) {
		if ( empty( $cards ) ) {
			return;
		}

		$compact = ! empty( $args['compact'] );
		if ( $compact ) {
			?>
			<ul class="yobm-premium-feature-summary">
				<?php foreach ( $cards as $card ) : ?>
					<?php
					$title       = isset( $card['title'] ) ? $card['title'] : '';
					$description = isset( $card['description'] ) ? $card['description'] : '';
					$icon        = ! empty( $card['icon'] ) ? $card['icon'] : 'dashicons-yes-alt';
					?>
					<li>
						<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
						<span>
							<strong><?php echo esc_html( $title ); ?></strong>
							<?php if ( '' !== $description ) : ?>
								<span><?php echo esc_html( $description ); ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
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
	function wc_blacklist_manager_render_premium_inline_cta( $unlock_url, $context = 'premium', $label = '', $candidate_id = '' ) {
		$premium_action = WC_Blacklist_Manager_Commercial_Router::premium_action();
		$unlock_url     = ! empty( $premium_action['url'] ) ? $premium_action['url'] : $unlock_url;
		$cta_label      = WC_Blacklist_Manager_Commercial_Router::PREMIUM_SETUP === WC_Blacklist_Manager_Commercial_Router::premium_state()
			? $premium_action['label']
			: ( '' !== $label ? $label : wc_blacklist_manager_premium_cta_label( $context ) );
		$show_action = '' !== (string) $candidate_id
			&& class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
			&& WC_Blacklist_Manager_Opportunity_Engine::is_selected( $candidate_id );

		if ( ! $show_action ) {
			return false;
		}
		?>
		<p class="yobm-premium-cta-row">
			<a href="<?php echo esc_url( $unlock_url ); ?>"<?php echo ! empty( $premium_action['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="button button-secondary yobm-premium-inline-cta">
				<?php echo esc_html( $cta_label ); ?>
			</a>
		</p>
		<?php
		return true;
	}
}
