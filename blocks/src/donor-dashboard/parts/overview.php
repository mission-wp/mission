<?php
/**
 * Donor Dashboard — Overview panel.
 *
 * Adapts to the user's persona: donors see giving stats and recent donations,
 * fundraiser-only users see page stats and donations to their pages, and users
 * with any live fundraiser get the "Your Fundraising" spotlight card.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isOverview">
	<!-- Stats (donor variant) -->
	<div class="mission-dd-stats" data-wp-bind--hidden="context.overview.isFundraiserVariant">
		<template data-wp-each--stat="context.overview.stats">
			<div class="mission-dd-stat">
				<div class="mission-dd-stat-value" data-wp-text="context.stat.value"></div>
				<div class="mission-dd-stat-label" data-wp-text="context.stat.label"></div>
			</div>
		</template>
	</div>

	<!-- Stats (fundraiser variant) -->
	<div class="mission-dd-stats" data-wp-bind--hidden="!context.overview.isFundraiserVariant">
		<template data-wp-each--fstat="context.overview.fundraiserStats">
			<div class="mission-dd-stat">
				<div class="mission-dd-stat-value" data-wp-text="context.fstat.value"></div>
				<div class="mission-dd-stat-label" data-wp-text="context.fstat.label"></div>
			</div>
		</template>
	</div>

	<!-- Fundraising spotlight -->
	<div class="mission-dd-spotlight" data-wp-bind--hidden="!context.overview.spotlight">
		<div class="mission-dd-section-title-row">
			<h2 class="mission-dd-section-title"><?php esc_html_e( 'Your Fundraising', 'mission-donation-platform' ); ?></h2>
			<button
				class="mission-dd-view-all"
				data-wp-on--click="actions.navigate"
				data-panel="fundraisers"
			><?php esc_html_e( 'Manage', 'mission-donation-platform' ); ?></button>
		</div>
		<div class="mission-dd-fr-campaign" data-wp-text="context.overview.spotlight.campaignTitle"></div>
		<div class="mission-dd-fr-title" data-wp-text="context.overview.spotlight.headline"></div>
		<div class="mission-dd-progress-track">
			<div class="mission-dd-progress-fill" data-wp-style--width="context.overview.spotlight.barWidth"></div>
		</div>
		<p class="mission-dd-progress-meta">
			<span data-wp-text="context.overview.spotlight.progressLabel"></span>
			<span data-wp-text="context.overview.spotlight.donationCountLabel"></span>
			<span data-wp-text="context.overview.spotlight.timeLabel"></span>
		</p>
		<p class="mission-dd-progress-meta" data-wp-bind--hidden="!context.overview.spotlight.onTeam">
			<span class="mission-dd-team-chip">
				<span data-wp-text="context.overview.spotlight.teamName"></span>
				<span class="mission-dd-role-badge" data-wp-class--mission-dd-role-badge-captain="context.overview.spotlight.isCaptain" data-wp-text="context.overview.spotlight.roleLabel"></span>
			</span>
			<span data-wp-text="context.overview.spotlight.teamProgressLabel"></span>
		</p>
		<div class="mission-dd-card-actions">
			<a
				class="mission-dd-btn-secondary"
				target="_blank"
				rel="noopener noreferrer"
				data-wp-bind--href="context.overview.spotlight.url"
				data-wp-bind--hidden="!context.overview.spotlight.hasUrl"
			><?php esc_html_e( 'View page', 'mission-donation-platform' ); ?></a>
			<button
				type="button"
				class="mission-dd-btn-secondary"
				data-wp-on--click="actions.copyLink"
				data-wp-bind--data-url="context.overview.spotlight.url"
			>
				<span class="mission-dd-icon mission-dd-icon-copy" aria-hidden="true"></span>
				<?php esc_html_e( 'Share', 'mission-donation-platform' ); ?>
			</button>
		</div>
		<div class="mission-dd-spotlight-secondary" data-wp-bind--hidden="!context.overview.spotlight.hasOthers">
			<span><?php esc_html_e( 'Also active:', 'mission-donation-platform' ); ?></span>
			<template data-wp-each--other="context.overview.spotlight.others">
				<span class="mission-dd-spotlight-other">
					<strong data-wp-text="context.other.headline"></strong>
					<span data-wp-text="context.other.campaignTitle"></span>
					<span data-wp-text="context.other.progressLabel"></span>
					<span data-wp-text="context.other.percentLabel"></span>
				</span>
			</template>
		</div>
	</div>

	<!-- Donor blocks: recent donations + active recurring -->
	<div data-wp-bind--hidden="context.overview.isFundraiserVariant">
		<!-- Recent Donations -->
		<div data-wp-bind--hidden="!context.overview.hasTransactions">
			<div class="mission-dd-section-title-row">
				<h2 class="mission-dd-section-title"><?php esc_html_e( 'Recent Donations', 'mission-donation-platform' ); ?></h2>
				<button
					class="mission-dd-view-all"
					data-wp-on--click="actions.navigate"
					data-panel="history"
				><?php esc_html_e( 'View all', 'mission-donation-platform' ); ?></button>
			</div>
			<div class="mission-dd-table-wrap">
				<table class="mission-dd-table" aria-label="<?php esc_attr_e( 'Recent donations', 'mission-donation-platform' ); ?>">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'mission-donation-platform' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'mission-donation-platform' ); ?></th>
							<th><?php esc_html_e( 'Campaign', 'mission-donation-platform' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mission-donation-platform' ); ?></th>
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
			<p><?php esc_html_e( 'No donations yet. Your donation history will appear here once you make your first gift.', 'mission-donation-platform' ); ?></p>
		</div>

		<!-- Active Recurring Donations -->
		<div data-wp-bind--hidden="!context.overview.hasSubscriptions">
			<template data-wp-each--sub="context.overview.activeSubscriptions">
				<div class="mission-dd-recurring-card">
					<div class="mission-dd-section-title"><?php esc_html_e( 'Active Recurring Donation', 'mission-donation-platform' ); ?></div>
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
									esc_html__( 'Next payment: %s', 'mission-donation-platform' ),
									'<span data-wp-text="context.sub.nextPayment"></span>'
								);
								?>
							</div>
						</div>
						<button
							class="mission-dd-recurring-card-link"
							data-wp-on--click="actions.navigate"
							data-panel="recurring"
						><?php esc_html_e( 'Manage', 'mission-donation-platform' ); ?></button>
					</div>
				</div>
			</template>
		</div>
	</div>

	<!-- Fundraiser blocks: recent donations to their pages -->
	<div data-wp-bind--hidden="!context.overview.isFundraiserVariant">
		<div data-wp-bind--hidden="!context.overview.hasPageDonations">
			<div class="mission-dd-section-title-row">
				<h2 class="mission-dd-section-title"><?php esc_html_e( 'Recent Donations to Your Pages', 'mission-donation-platform' ); ?></h2>
				<button
					class="mission-dd-view-all"
					data-wp-on--click="actions.navigate"
					data-panel="fundraisers"
				><?php esc_html_e( 'View all', 'mission-donation-platform' ); ?></button>
			</div>
			<div class="mission-dd-table-wrap">
				<table class="mission-dd-table" aria-label="<?php esc_attr_e( 'Recent donations to your pages', 'mission-donation-platform' ); ?>">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'mission-donation-platform' ); ?></th>
							<th><?php esc_html_e( 'Donor', 'mission-donation-platform' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'mission-donation-platform' ); ?></th>
							<th><?php esc_html_e( 'Page', 'mission-donation-platform' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<template data-wp-each--gift="context.overview.recentPageDonations">
							<tr>
								<td class="mission-dd-date" data-wp-text="context.gift.date"></td>
								<td data-wp-text="context.gift.donor"></td>
								<td class="mission-dd-amount" data-wp-text="context.gift.amount"></td>
								<td data-wp-text="context.gift.page"></td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>

		<div class="mission-dd-empty" data-wp-bind--hidden="context.overview.hasPageDonations">
			<p><?php esc_html_e( 'No donations to your pages yet. Share your fundraiser to start raising funds.', 'mission-donation-platform' ); ?></p>
		</div>
	</div>

</div>
