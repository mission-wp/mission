<?php
/**
 * Renewal receipt email template.
 *
 * @var array $data Template data: subscription, transaction, donor, amount_formatted, frequency_label, next_renewal_formatted.
 */

defined( 'ABSPATH' ) || exit;

$donor      = $data['donor'];
$first_name = $donor->first_name ?: __( 'Friend', 'mission' );
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Recurring donation receipt', 'mission' ); ?>
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

<p style="margin: 0 0 16px;">
	<?php esc_html_e( 'Your recurring donation has been processed successfully.', 'mission' ); ?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 8px;">
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 40%;">
			<?php esc_html_e( 'Amount', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['amount_formatted'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
			<?php esc_html_e( 'Frequency', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['frequency_label'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; font-weight: 600;">
			<?php esc_html_e( 'Next payment', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px;">
			<?php echo esc_html( $data['next_renewal_formatted'] ); ?>
		</td>
	</tr>
</table>

<p style="margin: 16px 0 0; color: #6b7280; font-size: 14px;">
	<?php esc_html_e( 'Thank you for your continued generosity!', 'mission' ); ?>
</p>
