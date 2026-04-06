<?php
/**
 * Donor Dashboard — Sidebar navigation.
 *
 * @package Mission
 *
 * Expected variables: $donor, $initials, $nav_items.
 */

defined( 'ABSPATH' ) || exit;

// Map panel IDs to their Interactivity API state getter names.
$panel_state_map = [
	'overview'  => 'state.isOverview',
	'history'   => 'state.isHistory',
	'recurring' => 'state.isRecurring',
	'receipts'  => 'state.isReceipts',
	'profile'   => 'state.isProfile',
];
?>
<aside class="mission-dd-sidebar">
	<div class="mission-dd-sidebar-inner">
		<!-- Close button (mobile drawer) -->
		<button
			class="mission-dd-sidebar-close"
			data-wp-on--click="actions.closeSidebar"
			aria-label="<?php esc_attr_e( 'Close menu', 'mission' ); ?>"
		>
			<span class="mission-dd-icon mission-dd-icon-close" aria-hidden="true"></span>
		</button>

		<!-- User -->
		<div class="mission-dd-user">
			<div class="mission-dd-avatar" data-wp-text="context.donor.initials"><?php echo esc_html( $initials ); ?></div>
			<div>
				<div class="mission-dd-user-name" data-wp-text="state.donorFullName"><?php echo esc_html( $donor->first_name . ' ' . $donor->last_name ); ?></div>
				<div class="mission-dd-user-email" data-wp-text="context.donor.email"><?php echo esc_html( $donor->email ); ?></div>
			</div>
		</div>

		<!-- Nav -->
		<nav aria-label="<?php esc_attr_e( 'Dashboard navigation', 'mission' ); ?>">
			<ul class="mission-dd-nav">
				<?php foreach ( $nav_items as $item ) : ?>
					<?php
					$state_binding = $panel_state_map[ $item['id'] ] ?? '';
					$icon_class    = 'mission-dd-icon mission-dd-icon-' . esc_attr( $item['icon'] );
					?>
					<li class="mission-dd-nav-item">
						<button
							class="mission-dd-nav-link"
							<?php if ( $state_binding ) : ?>
								data-wp-class--active="<?php echo esc_attr( $state_binding ); ?>"
								data-wp-bind--aria-current="<?php echo esc_attr( $state_binding ); ?>"
							<?php endif; ?>
							data-wp-on--click="actions.navigate"
							data-panel="<?php echo esc_attr( $item['id'] ); ?>"
						>
							<span class="<?php echo esc_attr( $icon_class ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $item['label'] ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="mission-dd-nav-divider"></div>

			<ul class="mission-dd-nav">
				<li class="mission-dd-nav-item">
					<button
						class="mission-dd-nav-link mission-dd-nav-link-logout"
						data-wp-on--click="actions.logout"
					>
						<span class="mission-dd-icon mission-dd-icon-logout" aria-hidden="true"></span>
						<?php esc_html_e( 'Log Out', 'mission' ); ?>
					</button>
				</li>
			</ul>
		</nav>
	</div>
</aside>
