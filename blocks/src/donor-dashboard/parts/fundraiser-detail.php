<?php
/**
 * Donor Dashboard — Fundraiser detail view (drill-in from My Fundraisers).
 *
 * Shows one page's hero stats and supporters, and — while the campaign is
 * live — the edit form. Ended campaigns are read-only; the server enforces
 * the same lock on every write route.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isFundraiserDetail">

	<button type="button" class="mission-dd-detail-back" data-wp-on--click="actions.navigate" data-panel="fundraisers">
		&larr; <?php esc_html_e( 'My Fundraisers', 'mission-donation-platform' ); ?>
	</button>

	<!-- Pending notice -->
	<div class="mission-dd-notice" data-wp-bind--hidden="!context.fundraisers.detail.isPending" role="status">
		<?php esc_html_e( 'Your page is awaiting approval. You can edit it now and it will go live once an organizer approves it.', 'mission-donation-platform' ); ?>
	</div>

	<!-- Hero -->
	<div class="mission-dd-detail-hero">
		<div class="mission-dd-fr-card-top">
			<span class="mission-dd-fr-campaign" data-wp-text="context.fundraisers.detail.campaignTitle"></span>
			<span class="mission-dd-badge" data-wp-bind--data-badge="context.fundraisers.detail.badgeType" data-wp-text="context.fundraisers.detail.statusLabel"></span>
		</div>
		<div class="mission-dd-fr-title" data-wp-text="context.fundraisers.detail.headline"></div>
		<div class="mission-dd-fr-tribute" data-wp-bind--hidden="!context.fundraisers.detail.dedicationLabel" data-wp-text="context.fundraisers.detail.dedicationLabel"></div>
		<div class="mission-dd-progress-track" data-wp-bind--hidden="!context.fundraisers.detail.hasGoal">
			<div class="mission-dd-progress-fill" data-wp-class--is-ended="context.fundraisers.detail.isEnded" data-wp-style--width="context.fundraisers.detail.barWidth"></div>
		</div>
		<div class="mission-dd-detail-stats">
			<div>
				<div class="mission-dd-detail-stat-value" data-wp-text="context.fundraisers.detail.raisedDisplay"></div>
				<div class="mission-dd-detail-stat-label" data-wp-text="context.fundraisers.detail.raisedStatLabel"></div>
			</div>
			<div>
				<div class="mission-dd-detail-stat-value" data-wp-text="context.fundraisers.detail.donationCount"></div>
				<div class="mission-dd-detail-stat-label"><?php esc_html_e( 'Donations', 'mission-donation-platform' ); ?></div>
			</div>
			<div>
				<div class="mission-dd-detail-stat-value" data-wp-text="context.fundraisers.detail.avgGiftDisplay"></div>
				<div class="mission-dd-detail-stat-label"><?php esc_html_e( 'Avg. Donation', 'mission-donation-platform' ); ?></div>
			</div>
			<div>
				<div class="mission-dd-detail-stat-value" data-wp-text="context.fundraisers.detail.timeStatValue"></div>
				<div class="mission-dd-detail-stat-label" data-wp-text="context.fundraisers.detail.timeStatLabel"></div>
			</div>
		</div>
		<div class="mission-dd-card-actions">
			<a
				class="mission-dd-btn-primary"
				target="_blank"
				rel="noopener noreferrer"
				data-wp-bind--href="context.fundraisers.detail.url"
				data-wp-bind--hidden="!context.fundraisers.detail.hasUrl"
			><?php esc_html_e( 'View public page', 'mission-donation-platform' ); ?></a>
			<button
				type="button"
				class="mission-dd-btn-secondary"
				data-wp-on--click="actions.copyLink"
				data-wp-bind--data-url="context.fundraisers.detail.url"
				data-wp-bind--hidden="!context.fundraisers.detail.hasUrl"
			>
				<span class="mission-dd-icon mission-dd-icon-copy" aria-hidden="true"></span>
				<?php esc_html_e( 'Copy link', 'mission-donation-platform' ); ?>
			</button>
		</div>
		<div class="mission-dd-readonly-note" data-wp-bind--hidden="!context.fundraisers.detail.isLocked">
			<?php esc_html_e( 'This campaign has ended, so this page is read-only.', 'mission-donation-platform' ); ?>
		</div>
	</div>

	<!-- Edit page (live campaigns only) -->
	<div class="mission-dd-fr-section" data-wp-bind--hidden="context.fundraisers.detail.isLocked">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Edit your page', 'mission-donation-platform' ); ?></h2>
		<p class="mission-dd-help"><?php esc_html_e( 'Changes appear on your public fundraising page right away.', 'mission-donation-platform' ); ?></p>

		<div class="mission-dd-profile-error" data-wp-bind--hidden="!context.fundraisers.edit.error" role="alert" aria-live="polite">
			<span data-wp-text="context.fundraisers.edit.error"></span>
		</div>

		<div class="mission-dd-profile-grid">

			<div class="mission-dd-profile-group mission-dd-profile-group-full">
				<label class="mission-dd-profile-label" for="mission-dd-fr-headline"><?php esc_html_e( 'Headline', 'mission-donation-platform' ); ?></label>
				<input
					type="text"
					id="mission-dd-fr-headline"
					class="mission-dd-profile-input"
					maxlength="255"
					data-wp-bind--value="context.fundraisers.edit.headline"
					data-wp-on--input="actions.editHeadline"
					data-wp-bind--disabled="context.fundraisers.edit.saving"
				>
			</div>

			<div class="mission-dd-profile-group">
				<label class="mission-dd-profile-label" for="mission-dd-fr-goal"><?php esc_html_e( 'Fundraising goal', 'mission-donation-platform' ); ?></label>
				<div class="mission-dd-goal-input">
					<span class="mission-dd-goal-prefix" data-wp-text="context.fundraisers.currencySymbol"></span>
					<input
						type="number"
						id="mission-dd-fr-goal"
						class="mission-dd-profile-input"
						min="0"
						step="1"
						data-wp-bind--value="context.fundraisers.edit.goal"
						data-wp-on--input="actions.editGoal"
						data-wp-bind--disabled="context.fundraisers.edit.saving"
					>
				</div>
			</div>

			<div class="mission-dd-profile-group">
				<label class="mission-dd-profile-label" for="mission-dd-fr-tribute-type"><?php esc_html_e( 'Dedication', 'mission-donation-platform' ); ?></label>
				<select
					id="mission-dd-fr-tribute-type"
					class="mission-dd-profile-input"
					data-wp-bind--value="context.fundraisers.edit.tributeType"
					data-wp-on--change="actions.editTributeType"
					data-wp-bind--disabled="context.fundraisers.edit.saving"
				>
					<option value=""><?php esc_html_e( 'No dedication', 'mission-donation-platform' ); ?></option>
					<option value="honor"><?php esc_html_e( 'In honor of', 'mission-donation-platform' ); ?></option>
					<option value="memory"><?php esc_html_e( 'In memory of', 'mission-donation-platform' ); ?></option>
				</select>
			</div>

			<div class="mission-dd-profile-group mission-dd-profile-group-full" data-wp-bind--hidden="!context.fundraisers.edit.tributeType">
				<label class="mission-dd-profile-label" for="mission-dd-fr-tribute-name"><?php esc_html_e( 'Dedication name', 'mission-donation-platform' ); ?></label>
				<input
					type="text"
					id="mission-dd-fr-tribute-name"
					class="mission-dd-profile-input"
					maxlength="200"
					data-wp-bind--value="context.fundraisers.edit.tributeName"
					data-wp-on--input="actions.editTributeName"
					data-wp-bind--disabled="context.fundraisers.edit.saving"
				>
			</div>

			<!-- Cover photo (staged locally; uploaded on save) -->
			<div class="mission-dd-profile-group mission-dd-profile-group-full mission-dd-cover">
				<span class="mission-dd-profile-label"><?php esc_html_e( 'Cover photo', 'mission-donation-platform' ); ?></span>
				<img
					class="mission-dd-cover-preview"
					alt=""
					data-wp-bind--src="state.fundraiserCoverSrc"
					data-wp-bind--hidden="!state.fundraiserHasCover"
				>
				<div class="mission-dd-cover-placeholder" data-wp-bind--hidden="state.fundraiserHasCover">
					<?php esc_html_e( 'No cover photo yet.', 'mission-donation-platform' ); ?>
				</div>
				<input
					type="file"
					class="mission-dd-cover-input"
					accept="image/jpeg,image/png,image/gif,image/webp"
					data-wp-on--change="actions.selectPhoto"
					hidden
				>
				<span class="mission-dd-cover-buttons">
					<button
						type="button"
						class="mission-dd-btn-secondary"
						data-wp-on--click="actions.triggerPhotoUpload"
						data-wp-bind--disabled="context.fundraisers.edit.saving"
					>
						<span class="mission-dd-icon mission-dd-icon-upload" aria-hidden="true"></span>
						<?php esc_html_e( 'Choose photo', 'mission-donation-platform' ); ?>
					</button>
					<button
						type="button"
						class="mission-dd-btn-secondary"
						data-wp-on--click="actions.removePhoto"
						data-wp-bind--hidden="!state.fundraiserHasCover"
						data-wp-bind--disabled="context.fundraisers.edit.saving"
					>
						<?php esc_html_e( 'Remove photo', 'mission-donation-platform' ); ?>
					</button>
				</span>
				<span class="mission-dd-profile-hint" data-wp-bind--hidden="!context.fundraisers.photoPreviewUrl">
					<?php esc_html_e( 'The new photo will be uploaded when you save your changes.', 'mission-donation-platform' ); ?>
				</span>
				<span class="mission-dd-profile-hint" data-wp-bind--hidden="!context.fundraisers.photoRemoved">
					<?php esc_html_e( 'The photo will be removed when you save your changes.', 'mission-donation-platform' ); ?>
				</span>
				<span class="mission-dd-field-error" data-wp-bind--hidden="!context.fundraisers.uploadError" data-wp-text="context.fundraisers.uploadError"></span>
			</div>

			<div class="mission-dd-profile-group mission-dd-profile-group-full">
				<label class="mission-dd-profile-label" for="mission-dd-fr-story"><?php esc_html_e( 'Your story', 'mission-donation-platform' ); ?></label>
				<textarea
					id="mission-dd-fr-story"
					class="mission-dd-profile-input mission-dd-story"
					rows="8"
					data-wp-bind--value="context.fundraisers.edit.story"
					data-wp-on--input="actions.editStory"
					data-wp-bind--disabled="context.fundraisers.edit.saving"
				></textarea>
			</div>

		</div>

		<div class="mission-dd-profile-actions">
			<button
				class="mission-dd-btn-primary"
				data-wp-on--click="actions.saveFundraiser"
				data-wp-bind--disabled="state.fundraisersSaveDisabled"
				data-wp-class--mission-dd-btn-saved="context.fundraisers.edit.saved"
			>
				<span data-wp-text="state.fundraisersSaveLabel"></span>
			</button>
		</div>
	</div>

	<!-- Share (live campaigns only) -->
	<div class="mission-dd-fr-section" data-wp-bind--hidden="context.fundraisers.detail.isLocked">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Share your page', 'mission-donation-platform' ); ?></h2>
		<div class="mission-dd-share-url">
			<input class="mission-dd-profile-input" type="text" readonly data-wp-bind--value="context.fundraisers.detail.url">
			<button type="button" class="mission-dd-btn-secondary" data-wp-on--click="actions.copyLink" data-wp-bind--data-url="context.fundraisers.detail.url">
				<span class="mission-dd-icon mission-dd-icon-copy" aria-hidden="true"></span>
				<?php esc_html_e( 'Copy', 'mission-donation-platform' ); ?>
			</button>
		</div>
		<?php
		// Same networks (and filter) as the sign-up success screen.
		$share_networks = \MissionDP\Helpers\Sharing::networks( 'donor-dashboard' );
		$share_chips    = [
			'facebook' => [
				'bind'  => 'context.fundraisers.detail.shareFacebook',
				'label' => __( 'Share on Facebook', 'mission-donation-platform' ),
			],
			'x'        => [
				'bind'  => 'context.fundraisers.detail.shareX',
				'label' => __( 'Share on X', 'mission-donation-platform' ),
			],
			'bluesky'  => [
				'bind'  => 'context.fundraisers.detail.shareBluesky',
				'label' => __( 'Share on Bluesky', 'mission-donation-platform' ),
			],
		];
		?>
		<?php if ( $share_networks ) : ?>
			<div class="mission-dd-share-links">
				<?php foreach ( $share_networks as $network ) : ?>
					<a class="mission-dd-btn-secondary" target="_blank" rel="noopener noreferrer" data-wp-bind--href="<?php echo esc_attr( $share_chips[ $network ]['bind'] ); ?>"><?php echo esc_html( $share_chips[ $network ]['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Supporters -->
	<div class="mission-dd-fr-section">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Supporters', 'mission-donation-platform' ); ?></h2>

		<div class="mission-dd-person-list" data-wp-bind--hidden="context.fundraisers.supporters.loading">
			<template data-wp-each--supporter="context.fundraisers.supporters.items">
				<div class="mission-dd-person-item">
					<span class="mission-dd-person-avatar" aria-hidden="true" data-wp-text="context.supporter.initials"></span>
					<span class="mission-dd-person-body">
						<span class="mission-dd-person-name" data-wp-text="context.supporter.name"></span>
						<span class="mission-dd-person-note" data-wp-bind--hidden="!context.supporter.hasComment" data-wp-text="context.supporter.comment"></span>
						<span class="mission-dd-person-sub" data-wp-text="context.supporter.timeAgo"></span>
					</span>
					<span class="mission-dd-person-amount" data-wp-text="context.supporter.amount"></span>
				</div>
			</template>
		</div>

		<!-- Skeleton rows while a page loads -->
		<div class="mission-dd-person-list" data-wp-bind--hidden="!context.fundraisers.supporters.loading" aria-hidden="true">
			<?php for ( $mission_dd_i = 0; $mission_dd_i < 3; $mission_dd_i++ ) : ?>
				<div class="mission-dd-person-item mission-dd-person-skeleton">
					<span class="mission-dd-person-avatar"></span>
					<span class="mission-dd-person-body">
						<span class="mission-dd-skeleton-line"></span>
						<span class="mission-dd-skeleton-line mission-dd-skeleton-line-short"></span>
					</span>
				</div>
			<?php endfor; ?>
		</div>

		<div class="mission-dd-empty" data-wp-bind--hidden="state.supportersNotEmpty">
			<p><?php esc_html_e( 'No donations yet. Share your page to start raising funds.', 'mission-donation-platform' ); ?></p>
		</div>

		<div class="mission-dd-pagination" data-wp-bind--hidden="!state.supportersHasPages">
			<span class="mission-dd-pagination-info" data-wp-text="state.supportersRangeLabel"></span>
			<span class="mission-dd-pagination-buttons">
				<button
					type="button"
					class="mission-dd-btn-secondary"
					data-wp-on--click="actions.supportersPrev"
					data-wp-bind--disabled="state.supportersPrevDisabled"
				><?php esc_html_e( 'Previous', 'mission-donation-platform' ); ?></button>
				<button
					type="button"
					class="mission-dd-btn-secondary"
					data-wp-on--click="actions.supportersNext"
					data-wp-bind--disabled="state.supportersNextDisabled"
				><?php esc_html_e( 'Next', 'mission-donation-platform' ); ?></button>
			</span>
		</div>
	</div>

</div>
