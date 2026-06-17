<?php
/**
 * Participant email: fundraiser approved, page is live.
 *
 * @var array $data Template data: fundraiser, donor, organization, page_url.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	$donor      = $data['donor'];
	$first_name = $donor->first_name ?: __( 'Friend', 'mission-donation-platform' );
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Your fundraising page is live!', 'mission-donation-platform' ); ?>
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
	<?php esc_html_e( 'Your fundraising page has been approved and is now live. Share your link with friends and family to start raising funds.', 'mission-donation-platform' ); ?>
</p>

	<?php if ( ! empty( $data['page_url'] ) ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
		<tr>
			<td align="center">
				<a href="<?php echo esc_url( $data['page_url'] ); ?>" style="display: inline-block; padding: 14px 32px; background-color: #2fa36b; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 6px;">
					<?php esc_html_e( 'View your page', 'mission-donation-platform' ); ?>
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
