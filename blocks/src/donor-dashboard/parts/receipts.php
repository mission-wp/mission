<?php
/**
 * Donor Dashboard — Annual Receipts panel.
 *
 * @package Mission
 */

defined( 'ABSPATH' ) || exit;

$receipt_intro = apply_filters(
	'mission_receipt_intro_text',
	sprintf(
		/* translators: %s: site/organization name */
		__( 'Download your annual donation receipts for tax purposes. %s — All donations are tax-deductible to the extent allowed by law.', 'mission' ),
		esc_html( get_bloginfo( 'name' ) )
	)
);
?>
<div class="mission-dd-panel" data-wp-class--active="state.isReceipts">
	<p class="mission-dd-receipts-intro"><?php echo wp_kses_post( $receipt_intro ); ?></p>

	<!-- Year rows -->
	<div data-wp-bind--hidden="!context.receipts.hasAny">
		<template data-wp-each--receipt="context.receipts.years">
			<div class="mission-dd-receipt-row">
				<div class="mission-dd-receipt-year">
					<span data-wp-text="context.receipt.year"></span>
					<span
						class="mission-dd-badge-ytd"
						data-wp-bind--hidden="!context.receipt.isCurrentYear"
					><?php esc_html_e( 'YTD', 'mission' ); ?></span>
				</div>
				<div class="mission-dd-receipt-summary">
					<strong data-wp-text="context.receipt.formattedTotal"></strong>
					<?php
					printf(
						/* translators: %s: number of donations */
						esc_html__( 'from %s donations', 'mission' ),
						'<span data-wp-text="context.receipt.count"></span>'
					);
					?>
				</div>
				<button
					class="mission-dd-receipt-download"
					data-wp-on--click="actions.downloadAnnualReceipt"
					title="<?php esc_attr_e( 'Download annual receipt PDF', 'mission' ); ?>"
				>
					<span class="mission-dd-icon mission-dd-icon-download-sm" aria-hidden="true"></span>
					<?php esc_html_e( 'Download PDF', 'mission' ); ?>
				</button>
			</div>
		</template>
	</div>

	<!-- Empty state -->
	<div class="mission-dd-empty" data-wp-bind--hidden="context.receipts.hasAny">
		<p><?php esc_html_e( 'No donation receipts available yet. Your annual receipts will appear here after your first donation.', 'mission' ); ?></p>
	</div>

</div>
