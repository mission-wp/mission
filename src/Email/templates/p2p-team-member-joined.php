<?php
/**
 * Captain email: a new member joined the team.
 *
 * @var array $data Template data: team, donor, member_name, organization, page_url.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	$donor       = $data['donor'];
	$first_name  = $donor->first_name ?: __( 'Captain', 'mission-donation-platform' );
	$member_name = $data['member_name'];
	$team        = $data['team'];
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Your team has a new member!', 'mission-donation-platform' ); ?>
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
		/* translators: 1: new member name, 2: team name */
		esc_html__( '%1$s just joined %2$s. Cheer them on as they start raising funds.', 'mission-donation-platform' ),
		esc_html( $member_name ),
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
	<?php endif; ?>
	<?php
} )( $data );
