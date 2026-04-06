<?php
/**
 * Payment failed email template.
 *
 * @var array $data Template data: subscription, donor, amount_formatted, frequency_label,
 *                   next_renewal_formatted, dashboard_url.
 */

defined( 'ABSPATH' ) || exit;

$donor      = $data['donor'];
$first_name = $donor->first_name ?: __( 'Friend', 'mission' );
// dashboard_url may be a real URL or the literal merge tag '{donor_dashboard}'.
$dashboard_url = $data['dashboard_url'] ?? home_url( '/' );
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Payment failed', 'mission' ); ?>
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
	<?php
	printf(
		/* translators: %1$s: donation amount, %2$s: frequency label */
		esc_html__( 'We were unable to process your %1$s %2$s donation.', 'mission' ),
		esc_html( $data['amount_formatted'] ),
		esc_html( strtolower( $data['frequency_label'] ) )
	);
	?>
</p>

<p style="margin: 0 0 16px;">
	<?php esc_html_e( 'To keep your recurring donation active, please log in to your donor dashboard and update your payment method.', 'mission' ); ?>
</p>

<p style="margin: 20px 0;">
	<a href="<?php echo esc_attr( $dashboard_url . '#recurring' ); ?>" style="display: inline-block; padding: 12px 24px; background-color: #2fa36b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
		<?php esc_html_e( 'Update Payment Method', 'mission' ); ?>
	</a>
</p>

<p style="margin: 16px 0 0; color: #6b7280; font-size: 14px;">
	<?php esc_html_e( 'Your subscription will remain active while the payment is retried. If the issue is not resolved, your subscription may be cancelled.', 'mission' ); ?>
</p>
