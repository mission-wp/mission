<?php
/**
 * Email verification code (OTP) template.
 *
 * Deliberately neutral: it must read as an ordinary verification step and never
 * reveal whether the recipient is an existing donor.
 *
 * @var array $data Template data: code, expiry_minutes, organization.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Verify your email', 'mission-donation-platform' ); ?>
</h1>

<p style="margin: 0 0 24px;">
	<?php esc_html_e( 'Enter this code to continue setting up your fundraising page:', 'mission-donation-platform' ); ?>
</p>

<p style="margin: 0 0 24px; font-size: 34px; font-weight: 700; letter-spacing: 8px; color: #1a1a2e; text-align: center;">
	<?php echo esc_html( $data['code'] ); ?>
</p>

<p style="margin: 0; color: #6b7280; font-size: 14px;">
	<?php
	printf(
		/* translators: %d: number of minutes before the code expires */
		esc_html__( 'This code expires in %d minutes. If you didn\'t request it, you can safely ignore this email.', 'mission-donation-platform' ),
		absint( $data['expiry_minutes'] )
	);
	?>
</p>
	<?php
} )( $data );
