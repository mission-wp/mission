<?php
/**
 * Email change verification email template.
 *
 * @var array $data Template data: donor, new_email, verification_url, expiry_hours, subject.
 */

defined( 'ABSPATH' ) || exit;

$donor      = $data['donor'];
$first_name = $donor->first_name ?: __( 'Friend', 'mission-donation-platform' );
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Verify your new email address', 'mission-donation-platform' ); ?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	printf(
		/* translators: %s: donor first name */
		esc_html__( 'Hi %s,', 'mission-donation-platform' ),
		esc_html( $first_name )
	);
	?>
</p>

<p style="margin: 0 0 24px;">
	<?php
	printf(
		/* translators: %s: new email address */
		esc_html__( 'You requested to change your email address to %s. Click the button below to confirm this change.', 'mission-donation-platform' ),
		'<strong>' . esc_html( $data['new_email'] ) . '</strong>'
	);
	?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
	<tr>
		<td align="center">
			<a href="<?php echo esc_url( $data['verification_url'] ); ?>" style="display: inline-block; padding: 14px 32px; background-color: #2fa36b; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 6px;">
				<?php esc_html_e( 'Verify new email address', 'mission-donation-platform' ); ?>
			</a>
		</td>
	</tr>
</table>

<p style="margin: 0 0 8px; color: #6b7280; font-size: 14px;">
	<?php
	printf(
		/* translators: %s: number of hours before the link expires */
		esc_html__( 'This link expires in %s hours. If you didn\'t request this change, you can safely ignore this email.', 'mission-donation-platform' ),
		esc_html( $data['expiry_hours'] )
	);
	?>
</p>

<p style="margin: 0; color: #6b7280; font-size: 13px; word-break: break-all;">
	<?php esc_html_e( 'If the button doesn\'t work, copy and paste this link into your browser:', 'mission-donation-platform' ); ?><br>
	<?php echo esc_html( $data['verification_url'] ); ?>
</p>
