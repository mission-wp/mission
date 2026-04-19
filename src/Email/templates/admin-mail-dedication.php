<?php
/**
 * Admin notification: mail dedication pending.
 *
 * Sent when a donation includes a dedication that needs to be physically mailed.
 *
 * @var array $data Template data: tribute, transaction, donor, donor_name, amount_formatted,
 *                   honoree_name, tribute_type_label, notify_name, notify_address, admin_url.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Mail Dedication Pending', 'missionwp-donation-platform' ); ?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	printf(
		wp_kses(
			/* translators: 1: donor name, 2: tribute type (e.g. "in honor of"), 3: honoree name */
			__( '<strong>%1$s</strong> made a donation %2$s <strong>%3$s</strong> and requested a mail notification.', 'missionwp-donation-platform' ),
			[ 'strong' => [] ]
		),
		esc_html( $data['donor_name'] ),
		esc_html( $data['tribute_type_label'] ),
		esc_html( $data['honoree_name'] )
	);
	?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 8px;">
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 40%;">
			<?php esc_html_e( 'Mail to', 'missionwp-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['notify_name'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
			<?php esc_html_e( 'Address', 'missionwp-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['notify_address'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
			<?php esc_html_e( 'Donation', 'missionwp-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['amount_formatted'] ); ?>
		</td>
	</tr>
	<?php if ( ! empty( $data['tribute']->message ) ) : ?>
		<tr>
			<td style="padding: 12px 16px; font-weight: 600;">
				<?php esc_html_e( 'Message', 'missionwp-donation-platform' ); ?>
			</td>
			<td style="padding: 12px 16px;">
				<?php echo esc_html( $data['tribute']->message ); ?>
			</td>
		</tr>
	<?php endif; ?>
</table>

<?php if ( ! empty( $data['admin_url'] ) ) : ?>
	<p style="margin: 20px 0 0;">
		<a href="<?php echo esc_url( $data['admin_url'] ); ?>" style="display: inline-block; padding: 10px 20px; background-color: #2fa36b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
			<?php esc_html_e( 'View Transaction', 'missionwp-donation-platform' ); ?>
		</a>
	</p>
<?php endif; ?>
