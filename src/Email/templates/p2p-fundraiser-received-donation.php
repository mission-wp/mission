<?php
/**
 * Participant email: a gift was credited to their fundraiser.
 *
 * @var array $data Template data: fundraiser, donor, giver_name, amount_formatted, organization, page_url.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	$donor      = $data['donor'];
	$first_name = $donor->first_name ?: __( 'Friend', 'mission-donation-platform' );
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'You received a donation!', 'mission-donation-platform' ); ?>
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
	printf(
		wp_kses(
			/* translators: 1: giver name (bold), 2: formatted amount (bold) */
			__( '<strong>%1$s</strong> just donated <strong>%2$s</strong> to your fundraiser. Every share helps you raise more.', 'mission-donation-platform' ),
			[ 'strong' => [] ]
		),
		esc_html( $data['giver_name'] ),
		esc_html( $data['amount_formatted'] )
	);
	?>
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
	<?php endif; ?>
	<?php
} )( $data );
