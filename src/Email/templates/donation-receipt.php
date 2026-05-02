<?php
/**
 * Donation receipt email template.
 *
 * @var array $data Template data: transaction, donor, amount_formatted, date_formatted, campaign_name.
 */

defined( 'ABSPATH' ) || exit;

$donor      = $data['donor'];
$first_name = $donor->first_name ?: __( 'Friend', 'mission-donation-platform' );
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Thank you for your donation', 'mission-donation-platform' ); ?>
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

<p style="margin: 0 0 16px;">
	<?php esc_html_e( 'Your donation has been received successfully.', 'mission-donation-platform' ); ?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 8px;">
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 40%;">
			<?php esc_html_e( 'Amount', 'mission-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['amount_formatted'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; <?php echo esc_attr( $data['campaign_name'] ? 'border-bottom: 1px solid #e5e7eb; ' : '' ); ?>font-weight: 600;">
			<?php esc_html_e( 'Date', 'mission-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; <?php echo esc_attr( $data['campaign_name'] ? 'border-bottom: 1px solid #e5e7eb; ' : '' ); ?>">
			<?php echo esc_html( $data['date_formatted'] ); ?>
		</td>
	</tr>
	<?php if ( $data['campaign_name'] ) : ?>
		<tr>
			<td style="padding: 12px 16px; font-weight: 600;">
				<?php esc_html_e( 'Campaign', 'mission-donation-platform' ); ?>
			</td>
			<td style="padding: 12px 16px;">
				<?php echo esc_html( $data['campaign_name'] ); ?>
			</td>
		</tr>
	<?php endif; ?>
</table>

<p style="margin: 16px 0 0; color: #6b7280; font-size: 14px;">
	<?php esc_html_e( 'Thank you for your generosity!', 'mission-donation-platform' ); ?>
</p>
