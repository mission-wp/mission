<?php
/**
 * Subscription cancelled email template.
 *
 * @var array $data Template data: subscription, donor, amount_formatted, frequency_label.
 */

defined( 'ABSPATH' ) || exit;

$donor      = $data['donor'];
$first_name = $donor->first_name ?: __( 'Friend', 'mission-donation-platform' );
$site_name  = ( new \MissionDP\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Subscription cancelled', 'mission-donation-platform' ); ?>
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
	<?php
	printf(
		/* translators: %1$s: donation amount, %2$s: frequency label */
		esc_html__( 'Your %1$s %2$s recurring donation has been cancelled.', 'mission-donation-platform' ),
		esc_html( $data['amount_formatted'] ),
		esc_html( strtolower( $data['frequency_label'] ) )
	);
	?>
</p>

<p style="margin: 0 0 16px;">
	<?php esc_html_e( 'No further payments will be processed. Thank you for your past support!', 'mission-donation-platform' ); ?>
</p>

<p style="margin: 16px 0 0; color: #6b7280; font-size: 14px;">
	<?php
	printf(
		/* translators: %s: site/organization name */
		esc_html__( 'If you would like to start a new donation, please visit %s.', 'mission-donation-platform' ),
		esc_html( $site_name )
	);
	?>
</p>
