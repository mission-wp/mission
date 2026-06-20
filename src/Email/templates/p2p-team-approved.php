<?php
/**
 * Captain email: team approved, page is live.
 *
 * @var array $data Template data: team, donor, organization, page_url.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	$donor      = $data['donor'];
	$first_name = $donor->first_name ?: __( 'Captain', 'mission-donation-platform' );
	$team       = $data['team'];
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Your team page is live!', 'mission-donation-platform' ); ?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	printf(
		/* translators: %s: captain first name */
		esc_html__( 'Hi %s,', 'mission-donation-platform' ),
		esc_html( $first_name )
	);
	?>
</p>

<p style="margin: 0 0 24px;">
	<?php
	printf(
		/* translators: %s: team name */
		esc_html__( '%s has been approved and is now live. Invite members and share your team link to start raising funds together.', 'mission-donation-platform' ),
		esc_html( $team->name )
	);
	?>
</p>

	<?php if ( ! empty( $data['page_url'] ) ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
		<tr>
			<td align="center">
				<a href="<?php echo esc_url( $data['page_url'] ); ?>" style="display: inline-block; padding: 14px 32px; background-color: #2fa36b; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 6px;">
					<?php esc_html_e( 'View your team', 'mission-donation-platform' ); ?>
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
