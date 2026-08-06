<?php
/**
 * Participant email: fundraiser reached a goal milestone.
 *
 * @var array $data Template data: donor, milestone_label, raised_formatted,
 *                  goal_formatted, organization, page_url.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	$donor      = $data['donor'];
	$first_name = $donor->first_name ?: __( 'Friend', 'mission-donation-platform' );
	$milestone  = $data['milestone_label'] ?? '';
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php
	printf(
		/* translators: %s: milestone percentage (e.g. "50%") */
		esc_html__( "You've reached %s of your goal!", 'mission-donation-platform' ),
		esc_html( $milestone )
	);
	?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	printf(
		/* translators: %s: participant first name */
		esc_html__( 'Hi %s,', 'mission-donation-platform' ),
		esc_html( $first_name )
	);
	?>
</p>

<p style="margin: 0 0 24px;">
	<?php
	if ( ! empty( $data['goal_formatted'] ) ) {
		printf(
			/* translators: 1: amount raised, 2: goal amount */
			esc_html__( "Congratulations! You've raised %1\$s toward your %2\$s goal. Keep the momentum going by sharing your page.", 'mission-donation-platform' ),
			esc_html( $data['raised_formatted'] ?? '' ),
			esc_html( $data['goal_formatted'] )
		);
	} else {
		printf(
			/* translators: %s: amount raised */
			esc_html__( "Congratulations! You've raised %s. Keep the momentum going by sharing your page.", 'mission-donation-platform' ),
			esc_html( $data['raised_formatted'] ?? '' )
		);
	}
	?>
</p>

	<?php if ( ! empty( $data['page_url'] ) ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
		<tr>
			<td align="center">
				<a href="<?php echo esc_url( $data['page_url'] ); ?>" style="display: inline-block; padding: 14px 32px; background-color: #2fa36b; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 6px;">
					<?php esc_html_e( 'Share your page', 'mission-donation-platform' ); ?>
				</a>
			</td>
		</tr>
	</table>

	<p style="margin: 0; color: #6b7280; font-size: 13px; word-break: break-all;">
		<?php echo esc_html( $data['page_url'] ); ?>
	</p>
	<?php endif; ?>
	<?php
} )( $data );
