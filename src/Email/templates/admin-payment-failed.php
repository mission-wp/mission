<?php
/**
 * Admin notification: recurring payment failed.
 *
 * @var array $data Template data: subscription, donor, donor_name, amount_formatted,
 *                   date_formatted, campaign_name, frequency_label, admin_url.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Recurring Payment Failed', 'mission' ); ?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	printf(
		wp_kses(
			/* translators: 1: formatted amount, 2: frequency (e.g. "monthly"), 3: donor name */
			__( 'A %1$s %2$s renewal payment from <strong>%3$s</strong> has failed.', 'mission' ),
			[ 'strong' => [] ]
		),
		esc_html( $data['amount_formatted'] ),
		esc_html( strtolower( $data['frequency_label'] ?? '' ) ),
		esc_html( $data['donor_name'] )
	);
	?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 8px;">
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 40%;">
			<?php esc_html_e( 'Donor', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['donor_name'] ); ?>
			<?php if ( ! empty( $data['donor']->email ) ) : ?>
				<span style="color: #6b7280;">(<?php echo esc_html( $data['donor']->email ); ?>)</span>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
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
			<?php echo esc_html( $data['frequency_label'] ?? '' ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; <?php echo ! empty( $data['campaign_name'] ) ? 'border-bottom: 1px solid #e5e7eb; ' : ''; ?>font-weight: 600;">
			<?php esc_html_e( 'Date', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px; <?php echo ! empty( $data['campaign_name'] ) ? 'border-bottom: 1px solid #e5e7eb; ' : ''; ?>">
			<?php echo esc_html( $data['date_formatted'] ); ?>
		</td>
	</tr>
	<?php if ( ! empty( $data['campaign_name'] ) ) : ?>
		<tr>
			<td style="padding: 12px 16px; font-weight: 600;">
				<?php esc_html_e( 'Campaign', 'mission' ); ?>
			</td>
			<td style="padding: 12px 16px;">
				<?php echo esc_html( $data['campaign_name'] ); ?>
			</td>
		</tr>
	<?php endif; ?>
</table>

<?php if ( ! empty( $data['admin_url'] ) ) : ?>
	<p style="margin: 20px 0 0;">
		<a href="<?php echo esc_url( $data['admin_url'] ); ?>" style="display: inline-block; padding: 10px 20px; background-color: #2fa36b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
			<?php esc_html_e( 'View in Dashboard', 'mission' ); ?>
		</a>
	</p>
<?php endif; ?>
