<?php
/**
 * Donor Dashboard — Fundraising panel.
 *
 * The only place a fundraiser edits their page. All data is scoped to a
 * fundraiser the current donor owns; the server re-checks ownership on every
 * request. Rendered only when the donor has at least one fundraiser.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isFundraising">

	<!-- Fundraiser switcher (only when the donor runs more than one) -->
	<div class="mission-fd-switcher" data-wp-bind--hidden="!context.fundraising.multiple">
		<template data-wp-each--f="context.fundraising.list">
			<button
				class="mission-fd-switcher-tab"
				data-wp-class--active="state.fundraiserTabActive"
				data-wp-on--click="actions.selectFundraiser"
				data-wp-text="context.f.campaignTitle"
			></button>
		</template>
	</div>

	<!-- Pending notice -->
	<div class="mission-fd-notice" data-wp-bind--hidden="!context.fundraising.view.isPending" role="status">
		<?php esc_html_e( 'Your page is awaiting approval. You can edit it now and it will go live once an organizer approves it.', 'mission-donation-platform' ); ?>
	</div>

	<!-- Overview stats -->
	<div class="mission-dd-stats">
		<div class="mission-dd-stat">
			<div class="mission-dd-stat-value" data-wp-text="context.fundraising.view.raisedDisplay"></div>
			<div class="mission-dd-stat-label"><?php esc_html_e( 'Raised', 'mission-donation-platform' ); ?></div>
		</div>
		<div class="mission-dd-stat">
			<div class="mission-dd-stat-value" data-wp-text="context.fundraising.view.donorCount"></div>
			<div class="mission-dd-stat-label"><?php esc_html_e( 'Donors', 'mission-donation-platform' ); ?></div>
		</div>
		<div class="mission-dd-stat" data-wp-bind--hidden="!context.fundraising.view.hasGoal">
			<div class="mission-dd-stat-value" data-wp-text="context.fundraising.view.goalDisplay"></div>
			<div class="mission-dd-stat-label"><?php esc_html_e( 'Goal', 'mission-donation-platform' ); ?></div>
		</div>
	</div>

	<!-- Progress bar -->
	<div class="mission-fd-progress" data-wp-bind--hidden="!context.fundraising.view.hasGoal">
		<div class="mission-fd-progress-track">
			<div class="mission-fd-progress-fill" data-wp-style--width="state.fundraisingBarWidth"></div>
		</div>
		<p class="mission-fd-progress-label" data-wp-text="context.fundraising.view.progressLabel"></p>
	</div>

	<!-- Cover photo -->
	<div class="mission-fd-section">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Cover photo', 'mission-donation-platform' ); ?></h2>
		<div class="mission-fd-cover">
			<img
				class="mission-fd-cover-preview"
				alt=""
				data-wp-bind--src="context.fundraising.view.coverImageUrl"
				data-wp-bind--hidden="!context.fundraising.view.hasCover"
			>
			<div class="mission-fd-cover-placeholder" data-wp-bind--hidden="context.fundraising.view.hasCover">
				<?php esc_html_e( 'No cover photo yet.', 'mission-donation-platform' ); ?>
			</div>
			<input
				type="file"
				class="mission-fd-cover-input"
				accept="image/jpeg,image/png,image/gif,image/webp"
				data-wp-on--change="actions.uploadPhoto"
				hidden
			>
			<button
				type="button"
				class="mission-dd-btn-secondary"
				data-wp-on--click="actions.triggerPhotoUpload"
				data-wp-bind--disabled="context.fundraising.uploading"
			>
				<span class="mission-dd-icon mission-dd-icon-upload" aria-hidden="true"></span>
				<?php esc_html_e( 'Upload photo', 'mission-donation-platform' ); ?>
			</button>
			<span class="mission-dd-field-error" data-wp-bind--hidden="!context.fundraising.uploadError" data-wp-text="context.fundraising.uploadError"></span>
		</div>
	</div>

	<!-- Edit page -->
	<div class="mission-fd-section">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Edit your page', 'mission-donation-platform' ); ?></h2>

		<div class="mission-dd-profile-error" data-wp-bind--hidden="!context.fundraising.edit.error" role="alert" aria-live="polite">
			<span data-wp-text="context.fundraising.edit.error"></span>
		</div>

		<div class="mission-dd-profile-group">
			<label class="mission-dd-profile-label" for="mission-fd-headline"><?php esc_html_e( 'Headline', 'mission-donation-platform' ); ?></label>
			<input
				type="text"
				id="mission-fd-headline"
				class="mission-dd-profile-input"
				maxlength="255"
				data-wp-bind--value="context.fundraising.edit.headline"
				data-wp-on--input="actions.editHeadline"
				data-wp-bind--disabled="context.fundraising.edit.saving"
			>
		</div>

		<div class="mission-dd-profile-group">
			<label class="mission-dd-profile-label" for="mission-fd-goal"><?php esc_html_e( 'Fundraising goal', 'mission-donation-platform' ); ?></label>
			<div class="mission-fd-goal-input">
				<span class="mission-fd-goal-prefix" data-wp-text="context.fundraising.currencySymbol"></span>
				<input
					type="number"
					id="mission-fd-goal"
					class="mission-dd-profile-input"
					min="0"
					step="1"
					data-wp-bind--value="context.fundraising.edit.goal"
					data-wp-on--input="actions.editGoal"
					data-wp-bind--disabled="context.fundraising.edit.saving"
				>
			</div>
		</div>

		<div class="mission-dd-profile-group">
			<label class="mission-dd-profile-label" for="mission-fd-story"><?php esc_html_e( 'Your story', 'mission-donation-platform' ); ?></label>
			<textarea
				id="mission-fd-story"
				class="mission-dd-profile-input mission-fd-story"
				rows="8"
				data-wp-bind--value="context.fundraising.edit.story"
				data-wp-on--input="actions.editStory"
				data-wp-bind--disabled="context.fundraising.edit.saving"
			></textarea>
		</div>

		<button
			class="mission-dd-btn-primary"
			data-wp-on--click="actions.saveFundraiser"
			data-wp-bind--disabled="state.fundraisingSaveDisabled"
			data-wp-class--mission-dd-btn-saved="context.fundraising.edit.saved"
		>
			<span data-wp-text="state.fundraisingSaveLabel"></span>
		</button>
	</div>

	<!-- Share -->
	<div class="mission-fd-section">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Share your page', 'mission-donation-platform' ); ?></h2>
		<div class="mission-fd-share-url">
			<input class="mission-dd-profile-input" type="text" readonly data-wp-bind--value="context.fundraising.view.url">
			<button type="button" class="mission-dd-btn-secondary" data-wp-on--click="actions.copyShareUrl">
				<span class="mission-dd-icon mission-dd-icon-copy" aria-hidden="true"></span>
				<?php esc_html_e( 'Copy', 'mission-donation-platform' ); ?>
			</button>
		</div>
		<div class="mission-fd-share-links">
			<a class="mission-dd-btn-secondary" target="_blank" rel="noopener noreferrer" data-wp-bind--href="context.fundraising.view.shareFacebook"><?php esc_html_e( 'Share on Facebook', 'mission-donation-platform' ); ?></a>
			<a class="mission-dd-btn-secondary" target="_blank" rel="noopener noreferrer" data-wp-bind--href="context.fundraising.view.shareTwitter"><?php esc_html_e( 'Share on X', 'mission-donation-platform' ); ?></a>
			<a class="mission-dd-btn-secondary" data-wp-bind--href="context.fundraising.view.shareEmail"><?php esc_html_e( 'Share by email', 'mission-donation-platform' ); ?></a>
		</div>
		<div class="mission-fd-embed">
			<label class="mission-dd-profile-label" for="mission-fd-embed"><?php esc_html_e( 'Embed code', 'mission-donation-platform' ); ?></label>
			<textarea id="mission-fd-embed" class="mission-dd-profile-input mission-fd-embed-code" rows="2" readonly data-wp-bind--value="context.fundraising.view.embed"></textarea>
			<button type="button" class="mission-dd-btn-secondary" data-wp-on--click="actions.copyEmbed">
				<span class="mission-dd-icon mission-dd-icon-copy" aria-hidden="true"></span>
				<?php esc_html_e( 'Copy embed code', 'mission-donation-platform' ); ?>
			</button>
		</div>
	</div>

	<!-- Team -->
	<div class="mission-fd-section" data-wp-bind--hidden="!context.fundraising.view.onTeam">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Your team', 'mission-donation-platform' ); ?></h2>
		<p>
			<a target="_blank" rel="noopener noreferrer" data-wp-bind--href="context.fundraising.view.teamUrl" data-wp-text="context.fundraising.view.teamName"></a>
		</p>
	</div>

	<!-- My Donors / Activity -->
	<div class="mission-fd-section">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Your donors', 'mission-donation-platform' ); ?></h2>

		<div class="mission-dd-table-wrap" data-wp-bind--hidden="!context.fundraising.view.hasDonors">
			<table class="mission-dd-table" aria-label="<?php esc_attr_e( 'Donors', 'mission-donation-platform' ); ?>">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Donor', 'mission-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'mission-donation-platform' ); ?></th>
						<th><?php esc_html_e( 'Date', 'mission-donation-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template data-wp-each--donor="context.fundraising.view.donors">
						<tr>
							<td data-wp-text="context.donor.name"></td>
							<td class="mission-dd-amount" data-wp-text="context.donor.amount"></td>
							<td class="mission-dd-date" data-wp-text="context.donor.date"></td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>

		<div class="mission-dd-empty" data-wp-bind--hidden="context.fundraising.view.hasDonors">
			<p><?php esc_html_e( 'No donations yet. Share your page to start raising funds.', 'mission-donation-platform' ); ?></p>
		</div>
	</div>

</div>
