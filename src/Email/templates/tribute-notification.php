<?php
/**
 * Tribute notification email — sent to the recipient of a dedication.
 *
 * @var array $data Template data: tribute, transaction, donor, organization,
 *                   tribute_type_label, honoree_name, message.
 */

defined( 'ABSPATH' ) || exit;

$settings      = new \Mission\Settings\SettingsService();
$primary_color = $settings->get( 'primary_color', '#2fa36b' );
$org_name      = $data['organization'] ?? get_bloginfo( 'name' );
$donor_name    = $data['donor']->first_name ?? __( 'Someone', 'missionwp-donation-platform' );
$type_label    = $data['tribute_type_label'] ?? __( 'in honor of', 'missionwp-donation-platform' );
$honoree_name  = $data['honoree_name'] ?? '';
$message       = $data['message'] ?? '';
?>
<div style="text-align: center; padding: 10px 0 20px;">
	<p style="margin: 0 0 4px; font-size: 20px; color: #4a4a5a; line-height: 1.4;">
		<?php
		printf(
			/* translators: %s: donor first name */
			esc_html__( '%s has dedicated a donation to', 'missionwp-donation-platform' ),
			esc_html( $donor_name )
		);
		?>
	</p>

	<p style="margin: 0 0 28px; font-size: 28px; font-weight: 700; color: <?php echo esc_attr( $primary_color ); ?>; line-height: 1.3;">
		<?php echo esc_html( $org_name ); ?>
	</p>

	<p style="margin: 0 0 4px; font-size: 18px; color: #4a4a5a; line-height: 1.4;">
		<?php echo esc_html( $type_label ); ?>
	</p>

	<p style="margin: 0 0 28px; font-size: 32px; font-weight: 700; color: <?php echo esc_attr( $primary_color ); ?>; line-height: 1.2;">
		<?php echo esc_html( $honoree_name ); ?>
	</p>

	<?php if ( $message ) : ?>
		<p style="margin: 0 0 28px; font-size: 15px; line-height: 1.6; color: #1a1a2e; font-style: italic;">
			<?php echo nl2br( esc_html( $message ) ); ?>
		</p>
	<?php endif; ?>

	<div style="width: 40px; height: 2px; background: <?php echo esc_attr( $primary_color ); ?>; margin: 0 auto 24px; opacity: 0.4;"></div>

	<p style="margin: 0; font-size: 14px; color: #6b7280; line-height: 1.6;">
		<?php
		printf(
			/* translators: %s: organization name */
			esc_html__( 'This generous act of kindness will help %s continue making a difference.', 'missionwp-donation-platform' ),
			esc_html( $org_name )
		);
		?>
	</p>
</div>
