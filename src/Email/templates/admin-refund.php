<?php
/**
 * Admin notification: refund processed.
 *
 * @var array $data Template data: transaction, donor, donor_name, refund_formatted,
 *                   amount_formatted, refunded_formatted, date_formatted, campaign_name,
 *                   is_full_refund, admin_url.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php
	if ( $data['is_full_refund'] ) {
		esc_html_e( 'Full Refund Processed', 'missionwp-donation-platform' );
	} else {
		esc_html_e( 'Partial Refund Processed', 'missionwp-donation-platform' );
	}
	?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	if ( $data['is_full_refund'] ) {
		printf(
			wp_kses(
				/* translators: 1: formatted refund amount, 2: donor name */
				__( 'A full refund of <strong>%1$s</strong> has been issued to <strong>%2$s</strong>.', 'missionwp-donation-platform' ),
				[ 'strong' => [] ]
			),
			esc_html( $data['refund_formatted'] ),
			esc_html( $data['donor_name'] )
		);
	} else {
		printf(
			wp_kses(
				/* translators: 1: formatted refund amount, 2: original donation amount, 3: donor name */
				__( 'A partial refund of <strong>%1$s</strong> (of %2$s) has been issued to <strong>%3$s</strong>.', 'missionwp-donation-platform' ),
				[ 'strong' => [] ]
			),
			esc_html( $data['refund_formatted'] ),
			esc_html( $data['amount_formatted'] ),
			esc_html( $data['donor_name'] )
		);
	}
	?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 8px;">
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 40%;">
			<?php esc_html_e( 'Donor', 'missionwp-donation-platform' ); ?>
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
			<?php esc_html_e( 'Refund amount', 'missionwp-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['refund_formatted'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
			<?php esc_html_e( 'Original amount', 'missionwp-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['amount_formatted'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
			<?php esc_html_e( 'Total refunded', 'missionwp-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['refunded_formatted'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; <?php echo ! empty( $data['campaign_name'] ) ? 'border-bottom: 1px solid #e5e7eb; ' : ''; ?>font-weight: 600;">
			<?php esc_html_e( 'Date', 'missionwp-donation-platform' ); ?>
		</td>
		<td style="padding: 12px 16px; <?php echo ! empty( $data['campaign_name'] ) ? 'border-bottom: 1px solid #e5e7eb; ' : ''; ?>">
			<?php echo esc_html( $data['date_formatted'] ); ?>
		</td>
	</tr>
	<?php if ( ! empty( $data['campaign_name'] ) ) : ?>
		<tr>
			<td style="padding: 12px 16px; font-weight: 600;">
				<?php esc_html_e( 'Campaign', 'missionwp-donation-platform' ); ?>
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
			<?php esc_html_e( 'View in Dashboard', 'missionwp-donation-platform' ); ?>
		</a>
	</p>
<?php endif; ?>
