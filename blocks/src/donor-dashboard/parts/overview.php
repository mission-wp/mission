<?php
/**
 * Donor Dashboard — Overview panel.
 *
 * @package Mission
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isOverview">
	<!-- Stats -->
	<div class="mission-dd-stats">
		<template data-wp-each--stat="context.overview.stats">
			<div class="mission-dd-stat">
				<div class="mission-dd-stat-value" data-wp-text="context.stat.value"></div>
				<div class="mission-dd-stat-label" data-wp-text="context.stat.label"></div>
			</div>
		</template>
	</div>

	<!-- Recent Donations -->
	<div data-wp-bind--hidden="!context.overview.hasTransactions">
		<div class="mission-dd-section-title-row">
			<h2 class="mission-dd-section-title"><?php esc_html_e( 'Recent Donations', 'missionwp-donation-platform' ); ?></h2>
			<button
				class="mission-dd-view-all"
				data-wp-on--click="actions.navigate"
				data-panel="history"
			><?php esc_html_e( 'View all', 'missionwp-donation-platform' ); ?></button>
		</div>
		<div class="mission-dd-table-wrap">
			<table class="mission-dd-table" aria-label="<?php esc_attr_e( 'Recent donations', 'missionwp-donation-platform' ); ?>">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'missionwp-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'missionwp-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Campaign', 'missionwp-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Status', 'missionwp-donation-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template data-wp-each--txn="context.overview.recentTransactions">
						<tr>
							<td class="mission-dd-date" data-wp-text="context.txn.formattedDate"></td>
							<td class="mission-dd-amount" data-wp-text="context.txn.formattedAmount"></td>
							<td data-wp-text="context.txn.campaignName"></td>
							<td>
								<span
									class="mission-dd-badge"
									data-wp-class--mission-dd-badge-completed="state.txnIsCompleted"
									data-wp-class--mission-dd-badge-pending="state.txnIsPending"
									data-wp-class--mission-dd-badge-refunded="state.txnIsRefunded"
									data-wp-class--mission-dd-badge-failed="state.txnIsFailed"
									data-wp-text="context.txn.statusLabel"
								></span>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Empty state: no donations -->
	<div class="mission-dd-empty" data-wp-bind--hidden="context.overview.hasTransactions">
		<p><?php esc_html_e( 'No donations yet. Your donation history will appear here once you make your first gift.', 'missionwp-donation-platform' ); ?></p>
	</div>

	<!-- Active Recurring Donations -->
	<div data-wp-bind--hidden="!context.overview.hasSubscriptions">
		<template data-wp-each--sub="context.overview.activeSubscriptions">
			<div class="mission-dd-recurring-card">
				<div class="mission-dd-section-title"><?php esc_html_e( 'Active Recurring Donation', 'missionwp-donation-platform' ); ?></div>
				<div class="mission-dd-recurring-card-header">
					<div>
						<div class="mission-dd-recurring-card-amount">
							<span data-wp-text="context.sub.formattedAmount"></span>
							<span class="mission-dd-recurring-card-freq" data-wp-text="context.sub.frequencySuffix"></span>
						</div>
						<div class="mission-dd-recurring-card-campaign" data-wp-text="context.sub.campaignName"></div>
						<div class="mission-dd-recurring-card-next">
							<?php
							printf(
								/* translators: %s: next payment date */
								esc_html__( 'Next payment: %s', 'missionwp-donation-platform' ),
								'<span data-wp-text="context.sub.nextPayment"></span>'
							);
							?>
						</div>
					</div>
					<button
						class="mission-dd-recurring-card-link"
						data-wp-on--click="actions.navigate"
						data-panel="recurring"
					><?php esc_html_e( 'Manage', 'missionwp-donation-platform' ); ?></button>
				</div>
			</div>
		</template>
	</div>

</div>
