<?php
/**
 * Admin notification: campaign milestone reached.
 *
 * @var array $data Template data: campaign, milestone_id, milestone_label,
 *                   campaign_name, goal_formatted, raised_formatted,
 *                   date_formatted, admin_url.
 */

defined( 'ABSPATH' ) || exit;

$is_percentage = str_ends_with( $data['milestone_id'], '-pct' );
?>
<h1 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a2e;">
	<?php esc_html_e( 'Campaign Milestone Reached', 'mission' ); ?>
</h1>

<p style="margin: 0 0 16px;">
	<?php
	if ( $is_percentage ) {
		printf(
			wp_kses(
				/* translators: 1: campaign name, 2: milestone percentage (e.g. "50%") */
				__( '<strong>%1$s</strong> has reached <strong>%2$s</strong> of its goal!', 'mission' ),
				[ 'strong' => [] ]
			),
			esc_html( $data['campaign_name'] ),
			esc_html( $data['milestone_label'] )
		);
	} else {
		printf(
			wp_kses(
				/* translators: %s: campaign name */
				__( '<strong>%s</strong> has received its first donation!', 'mission' ),
				[ 'strong' => [] ]
			),
			esc_html( $data['campaign_name'] )
		);
	}
	?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 20px 0; border: 1px solid #e5e7eb; border-radius: 8px;">
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 40%;">
			<?php esc_html_e( 'Campaign', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['campaign_name'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
			<?php esc_html_e( 'Milestone', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
			<?php echo esc_html( $data['milestone_label'] ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding: 12px 16px; <?php echo ! empty( $data['goal_formatted'] ) ? 'border-bottom: 1px solid #e5e7eb; ' : ''; ?>font-weight: 600;">
			<?php esc_html_e( 'Total raised', 'mission' ); ?>
		</td>
		<td style="padding: 12px 16px; <?php echo ! empty( $data['goal_formatted'] ) ? 'border-bottom: 1px solid #e5e7eb; ' : ''; ?>">
			<?php echo esc_html( $data['raised_formatted'] ); ?>
		</td>
	</tr>
	<?php if ( ! empty( $data['goal_formatted'] ) ) : ?>
		<tr>
			<td style="padding: 12px 16px; font-weight: 600;">
				<?php esc_html_e( 'Goal', 'mission' ); ?>
			</td>
			<td style="padding: 12px 16px;">
				<?php echo esc_html( $data['goal_formatted'] ); ?>
			</td>
		</tr>
	<?php endif; ?>
</table>

<?php if ( ! empty( $data['admin_url'] ) ) : ?>
	<p style="margin: 20px 0 0;">
		<a href="<?php echo esc_url( $data['admin_url'] ); ?>" style="display: inline-block; padding: 10px 20px; background-color: #2fa36b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
			<?php esc_html_e( 'View Campaign', 'mission' ); ?>
		</a>
	</p>
<?php endif; ?>
