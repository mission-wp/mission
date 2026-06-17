<?php
/**
 * Admin notification: a new peer-to-peer fundraiser registered.
 *
 * @var array $data Template data: donor_name, campaign_name, approval_required, admin_url, fundraiser.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'New Fundraiser Registered', 'mission-donation-platform' ); ?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	if ( ! empty( $data['campaign_name'] ) ) {
		printf(
			wp_kses(
				/* translators: 1: participant name (bold), 2: campaign name */
				__( '<strong>%1$s</strong> signed up to fundraise for %2$s.', 'mission-donation-platform' ),
				[ 'strong' => [] ]
			),
			esc_html( $data['donor_name'] ),
			esc_html( $data['campaign_name'] )
		);
	} else {
		printf(
			wp_kses(
				/* translators: %s: participant name (bold) */
				__( '<strong>%s</strong> signed up as a fundraiser.', 'mission-donation-platform' ),
				[ 'strong' => [] ]
			),
			esc_html( $data['donor_name'] )
		);
	}
	?>
</p>

	<?php if ( ! empty( $data['approval_required'] ) ) : ?>
	<p style="margin: 0 0 24px; color: #9c3b3b;">
		<?php esc_html_e( 'This fundraiser is pending your approval before their page goes live.', 'mission-donation-platform' ); ?>
	</p>
	<?php endif; ?>

	<?php if ( ! empty( $data['admin_url'] ) ) : ?>
	<p style="margin: 20px 0 0;">
		<a href="<?php echo esc_url( $data['admin_url'] ); ?>" style="display: inline-block; padding: 10px 20px; background-color: #2fa36b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
			<?php esc_html_e( 'View Fundraisers', 'mission-donation-platform' ); ?>
		</a>
	</p>
	<?php endif; ?>
	<?php
} )( $data );
