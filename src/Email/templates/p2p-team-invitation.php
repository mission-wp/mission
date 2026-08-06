<?php
/**
 * Invitee email: you've been invited to join a team.
 *
 * @var array $data Template data: team, organization, accept_url.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	$team = $data['team'];
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php
	printf(
		/* translators: %s: team name */
		esc_html__( "You're invited to join %s", 'mission-donation-platform' ),
		esc_html( $team->name )
	);
	?>
</h1>

<p style="margin: 0 0 24px;">
	<?php esc_html_e( 'A team captain has invited you to fundraise together. Accept the invitation to create your fundraising page and join the team.', 'mission-donation-platform' ); ?>
</p>

	<?php if ( ! empty( $data['accept_url'] ) ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
		<tr>
			<td align="center">
				<a href="<?php echo esc_url( $data['accept_url'] ); ?>" style="display: inline-block; padding: 14px 32px; background-color: #2fa36b; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 6px;">
					<?php esc_html_e( 'Accept invitation', 'mission-donation-platform' ); ?>
				</a>
			</td>
		</tr>
	</table>

	<p style="margin: 0; color: #6b7280; font-size: 13px; word-break: break-all;">
		<?php echo esc_html( $data['accept_url'] ); ?>
	</p>
	<?php endif; ?>
	<?php
} )( $data );
