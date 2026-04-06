<?php
/**
 * Account activation email template.
 *
 * @var array $data Template data: donor, verification_url, expiry_hours.
 */

defined( 'ABSPATH' ) || exit;

$donor      = $data['donor'];
$first_name = $donor->first_name ?: __( 'Friend', 'mission' );
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Verify your email', 'mission' ); ?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	printf(
		/* translators: %s: donor first name */
		esc_html__( 'Hi %s,', 'mission' ),
		esc_html( $first_name )
	);
	?>
</p>

<p style="margin: 0 0 24px;">
	<?php esc_html_e( 'Click the button below to verify your email and set up your donor dashboard account. You\'ll be able to view your donation history, download tax receipts, and manage recurring gifts.', 'mission' ); ?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
	<tr>
		<td align="center">
			<a href="<?php echo esc_attr( $data['verification_url'] ); ?>" style="display: inline-block; padding: 14px 32px; background-color: #2fa36b; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 6px;">
				<?php esc_html_e( 'Verify email address', 'mission' ); ?>
			</a>
		</td>
	</tr>
</table>

<p style="margin: 0 0 8px; color: #6b7280; font-size: 14px;">
	<?php
	printf(
		/* translators: %s: number of hours before the link expires */
		esc_html__( 'This link expires in %s hours. If you didn\'t request this, you can safely ignore this email.', 'mission' ),
		esc_html( $data['expiry_hours'] )
	);
	?>
</p>

<p style="margin: 0; color: #6b7280; font-size: 13px; word-break: break-all;">
	<?php esc_html_e( 'If the button doesn\'t work, copy and paste this link into your browser:', 'mission' ); ?><br>
	<?php echo esc_html( $data['verification_url'] ); ?>
</p>
