<?php
/**
 * Donor Dashboard — Donation History panel.
 *
 * @package Mission
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isHistory">
	<!-- Filters -->
	<div class="mission-dd-history-filters">
		<select
			class="mission-dd-filter-select"
			data-wp-on--change="actions.changeHistoryYear"
			data-wp-bind--value="context.history.filterYear"
			aria-label="<?php esc_attr_e( 'Filter by year', 'missionwp-donation-platform' ); ?>"
		>
			<option value=""><?php esc_html_e( 'All Years', 'missionwp-donation-platform' ); ?></option>
			<template data-wp-each--year="context.history.years">
				<option data-wp-bind--value="context.year" data-wp-text="context.year"></option>
			</template>
		</select>

		<select
			class="mission-dd-filter-select"
			data-wp-on--change="actions.changeHistoryCampaign"
			data-wp-bind--value="context.history.filterCampaign"
			aria-label="<?php esc_attr_e( 'Filter by campaign', 'missionwp-donation-platform' ); ?>"
		>
			<option value=""><?php esc_html_e( 'All Campaigns', 'missionwp-donation-platform' ); ?></option>
			<template data-wp-each--campaign="context.history.campaigns">
				<option data-wp-bind--value="context.campaign.id" data-wp-text="context.campaign.name"></option>
			</template>
		</select>

		<select
			class="mission-dd-filter-select"
			data-wp-on--change="actions.changeHistoryType"
			data-wp-bind--value="context.history.filterType"
			aria-label="<?php esc_attr_e( 'Filter by type', 'missionwp-donation-platform' ); ?>"
		>
			<option value=""><?php esc_html_e( 'All Types', 'missionwp-donation-platform' ); ?></option>
			<option value="one_time"><?php esc_html_e( 'One-time', 'missionwp-donation-platform' ); ?></option>
			<option value="recurring"><?php esc_html_e( 'Recurring', 'missionwp-donation-platform' ); ?></option>
		</select>
	</div>

	<!-- Loading overlay -->
	<div class="mission-dd-history-loading" data-wp-bind--hidden="!context.history.loading">
		<span><?php esc_html_e( 'Loading…', 'missionwp-donation-platform' ); ?></span>
	</div>

	<!-- Table -->
	<div data-wp-bind--hidden="state.historyIsEmpty">
		<div class="mission-dd-table-wrap">
			<table class="mission-dd-table mission-dd-history-table" aria-label="<?php esc_attr_e( 'Donation history', 'missionwp-donation-platform' ); ?>">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'missionwp-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'missionwp-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Campaign', 'missionwp-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Type', 'missionwp-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Status', 'missionwp-donation-platform' ); ?></th>
						<th class="mission-dd-col-download"><span class="screen-reader-text"><?php esc_html_e( 'Download', 'missionwp-donation-platform' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<template data-wp-each--txn="context.history.transactions">
						<tr>
							<td class="mission-dd-date" data-wp-text="context.txn.formattedDate"></td>
							<td class="mission-dd-amount" data-wp-text="context.txn.formattedAmount"></td>
							<td data-wp-text="context.txn.campaignName"></td>
							<td>
								<span
									class="mission-dd-badge"
									data-wp-class--mission-dd-badge-recurring="context.txn.isRecurring"
									data-wp-class--mission-dd-badge-one-time="!context.txn.isRecurring"
									data-wp-text="context.txn.typeLabel"
								></span>
							</td>
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
							<td class="mission-dd-col-download">
								<button
									class="mission-dd-download-btn"
									data-wp-bind--hidden="!state.txnIsCompleted"
									data-wp-on--click="actions.downloadTransactionReceipt"
									title="<?php esc_attr_e( 'Download receipt', 'missionwp-donation-platform' ); ?>"
								>
									<span class="mission-dd-icon mission-dd-icon-download" aria-hidden="true"></span>
								</button>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>

		<!-- Pagination -->
		<div class="mission-dd-pagination" data-wp-bind--hidden="state.historyHasOnePage">
			<button
				class="mission-dd-pagination-btn"
				data-wp-on--click="actions.historyPrevPage"
				data-wp-bind--disabled="state.historyIsFirstPage"
			>
				<span class="mission-dd-icon mission-dd-icon-chevron-left" aria-hidden="true"></span>
				<?php esc_html_e( 'Previous', 'missionwp-donation-platform' ); ?>
			</button>
			<span class="mission-dd-pagination-info" data-wp-text="state.historyPaginationLabel" aria-live="polite"></span>
			<button
				class="mission-dd-pagination-btn"
				data-wp-on--click="actions.historyNextPage"
				data-wp-bind--disabled="state.historyIsLastPage"
			>
				<?php esc_html_e( 'Next', 'missionwp-donation-platform' ); ?>
				<span class="mission-dd-icon mission-dd-icon-chevron-right" aria-hidden="true"></span>
			</button>
		</div>
	</div>

	<!-- Empty state -->
	<div class="mission-dd-empty" data-wp-bind--hidden="!state.historyIsEmpty">
		<p><?php esc_html_e( 'No donations match your filters.', 'missionwp-donation-platform' ); ?></p>
	</div>

</div>
