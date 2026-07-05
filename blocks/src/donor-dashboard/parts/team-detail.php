<?php
/**
 * Donor Dashboard — Team detail view (drill-in from My Teams).
 *
 * Captains manage the team (details, image, invitations, members); members see
 * the roster and can leave. Every write is captain-scoped server-side, and
 * ended campaigns are locked by the server as well.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isTeamDetail">

	<button type="button" class="mission-dd-detail-back" data-wp-on--click="actions.navigate" data-panel="teams">
		&larr; <?php esc_html_e( 'My Teams', 'mission-donation-platform' ); ?>
	</button>

	<!-- Pending notice (captain) -->
	<div class="mission-dd-notice" data-wp-bind--hidden="!state.teamPendingNoticeVisible" role="status">
		<?php esc_html_e( 'Your team is awaiting approval. You can edit it now and it will go live once an organizer approves it.', 'mission-donation-platform' ); ?>
	</div>

	<!-- Hero -->
	<div class="mission-dd-detail-hero">
		<div class="mission-dd-fr-card-top">
			<span class="mission-dd-fr-campaign" data-wp-text="context.teams.detail.campaignTitle"></span>
			<span class="mission-dd-badge" data-badge="inactive" data-wp-bind--hidden="!context.teams.detail.isPrivate"><?php esc_html_e( 'Private', 'mission-donation-platform' ); ?></span>
		</div>
		<div class="mission-dd-fr-title" data-wp-text="context.teams.detail.name"></div>
		<div class="mission-dd-fr-foot">
			<span
				class="mission-dd-role-badge"
				data-wp-class--mission-dd-role-badge-captain="context.teams.detail.isCaptain"
				data-wp-text="context.teams.detail.roleLabel"
			></span>
			<span class="mission-dd-team-chip" data-wp-bind--hidden="!context.teams.detail.captainChipLabel" data-wp-text="context.teams.detail.captainChipLabel"></span>
		</div>
		<div class="mission-dd-progress-track" data-wp-bind--hidden="!context.teams.detail.hasGoal">
			<div class="mission-dd-progress-fill" data-wp-style----bar-width="context.teams.detail.barWidth"></div>
		</div>
		<div class="mission-dd-detail-stats">
			<div>
				<div class="mission-dd-detail-stat-value" data-wp-text="context.teams.detail.raisedDisplay"></div>
				<div class="mission-dd-detail-stat-label" data-wp-text="context.teams.detail.goalStatLabel"></div>
			</div>
			<div>
				<div class="mission-dd-detail-stat-value" data-wp-text="context.teams.detail.memberCount"></div>
				<div class="mission-dd-detail-stat-label"><?php esc_html_e( 'Members', 'mission-donation-platform' ); ?></div>
			</div>
			<div>
				<div class="mission-dd-detail-stat-value" data-wp-text="context.teams.detail.donationCount"></div>
				<div class="mission-dd-detail-stat-label"><?php esc_html_e( 'Donations', 'mission-donation-platform' ); ?></div>
			</div>
			<div data-wp-bind--hidden="!context.teams.detail.rankLabel">
				<div class="mission-dd-detail-stat-value" data-wp-text="context.teams.detail.rankStatValue"></div>
				<div class="mission-dd-detail-stat-label" data-wp-text="context.teams.detail.rankStatLabel"></div>
			</div>
		</div>
		<div class="mission-dd-card-actions">
			<a
				class="mission-dd-btn-primary"
				target="_blank"
				rel="noopener noreferrer"
				data-wp-bind--href="context.teams.detail.url"
				data-wp-bind--hidden="!context.teams.detail.hasUrl"
			><?php esc_html_e( 'View team page', 'mission-donation-platform' ); ?></a>
		</div>
	</div>

	<!-- Captain tools -->
	<div data-wp-bind--hidden="!context.teams.detail.isCaptain">

		<div class="mission-dd-profile-error" data-wp-bind--hidden="!context.teams.edit.error" role="alert" aria-live="polite">
			<span data-wp-text="context.teams.edit.error"></span>
		</div>

		<!-- Invite by email -->
		<div class="mission-dd-fr-section">
			<h2 class="mission-dd-section-title"><?php esc_html_e( 'Invite members', 'mission-donation-platform' ); ?></h2>
			<p class="mission-dd-help"><?php esc_html_e( 'Send an invitation link by email. They can join even if your team is private.', 'mission-donation-platform' ); ?></p>
			<div class="mission-dd-invite">
				<input
					type="email"
					class="mission-dd-profile-input"
					placeholder="<?php esc_attr_e( 'name@example.com', 'mission-donation-platform' ); ?>"
					data-wp-bind--value="context.teams.invite.email"
					data-wp-on--input="actions.editInviteEmail"
					data-wp-bind--disabled="context.teams.invite.sending"
				>
				<button
					type="button"
					class="mission-dd-btn-secondary"
					data-wp-on--click="actions.inviteMember"
					data-wp-bind--disabled="context.teams.invite.sending"
				>
					<?php esc_html_e( 'Send invite', 'mission-donation-platform' ); ?>
				</button>
			</div>
			<span class="mission-dd-field-error" data-wp-bind--hidden="!context.teams.invite.error" data-wp-text="context.teams.invite.error"></span>

			<ul class="mission-dd-invitations" data-wp-bind--hidden="!state.hasInvitations">
				<template data-wp-each--invite="context.teams.detail.invitations">
					<li class="mission-dd-invitation">
						<span data-wp-text="context.invite.email"></span>
						<span class="mission-dd-invitation-status"><?php esc_html_e( 'Pending', 'mission-donation-platform' ); ?></span>
					</li>
				</template>
			</ul>
		</div>

		<!-- Edit team -->
		<div class="mission-dd-fr-section">
			<h2 class="mission-dd-section-title"><?php esc_html_e( 'Edit team', 'mission-donation-platform' ); ?></h2>
			<p class="mission-dd-help"><?php esc_html_e( 'Changes appear on your public team page right away.', 'mission-donation-platform' ); ?></p>

			<div class="mission-dd-profile-grid">

				<div class="mission-dd-profile-group">
					<label class="mission-dd-profile-label" for="mission-dd-team-name"><?php esc_html_e( 'Team name', 'mission-donation-platform' ); ?></label>
					<input
						type="text"
						id="mission-dd-team-name"
						class="mission-dd-profile-input"
						maxlength="200"
						data-wp-bind--value="context.teams.edit.name"
						data-wp-on--input="actions.editTeamName"
						data-wp-bind--disabled="context.teams.edit.saving"
					>
				</div>

				<div class="mission-dd-profile-group">
					<label class="mission-dd-profile-label" for="mission-dd-team-goal"><?php esc_html_e( 'Team goal', 'mission-donation-platform' ); ?></label>
					<div class="mission-dd-goal-input">
						<span class="mission-dd-goal-prefix" data-wp-text="context.teams.currencySymbol"></span>
						<input
							type="number"
							id="mission-dd-team-goal"
							class="mission-dd-profile-input"
							min="0"
							step="1"
							data-wp-bind--value="context.teams.edit.goal"
							data-wp-on--input="actions.editTeamGoal"
							data-wp-bind--disabled="context.teams.edit.saving"
						>
					</div>
				</div>

				<div class="mission-dd-profile-group">
					<label class="mission-dd-profile-label" for="mission-dd-team-access"><?php esc_html_e( 'Team privacy', 'mission-donation-platform' ); ?></label>
					<select
						id="mission-dd-team-access"
						class="mission-dd-profile-input"
						data-wp-bind--value="context.teams.edit.access"
						data-wp-on--change="actions.editTeamAccess"
						data-wp-bind--disabled="context.teams.edit.saving"
					>
						<option value="public"><?php esc_html_e( 'Public — anyone can join', 'mission-donation-platform' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private — invitation only', 'mission-donation-platform' ); ?></option>
					</select>
					<span class="mission-dd-profile-hint"><?php esc_html_e( 'Private teams are hidden from the sign-up form; people join through your email invitations.', 'mission-donation-platform' ); ?></span>
				</div>

				<!-- Team image (staged locally; uploaded on save) -->
				<div class="mission-dd-profile-group mission-dd-profile-group-full mission-dd-cover">
					<span class="mission-dd-profile-label"><?php esc_html_e( 'Team image', 'mission-donation-platform' ); ?></span>
					<img
						class="mission-dd-cover-preview"
						alt=""
						data-wp-bind--src="state.teamCoverSrc"
						data-wp-bind--hidden="!state.teamHasCover"
					>
					<div class="mission-dd-cover-placeholder" data-wp-bind--hidden="state.teamHasCover">
						<?php esc_html_e( 'No team image yet.', 'mission-donation-platform' ); ?>
					</div>
					<input
						type="file"
						class="mission-dd-cover-input"
						accept="image/jpeg,image/png,image/gif,image/webp"
						data-wp-on--change="actions.selectTeamPhoto"
						hidden
					>
					<span class="mission-dd-cover-buttons">
						<button
							type="button"
							class="mission-dd-btn-secondary"
							data-wp-on--click="actions.triggerTeamPhotoUpload"
							data-wp-bind--disabled="context.teams.edit.saving"
						>
							<span class="mission-dd-icon mission-dd-icon-upload" aria-hidden="true"></span>
							<?php esc_html_e( 'Choose team image', 'mission-donation-platform' ); ?>
						</button>
						<button
							type="button"
							class="mission-dd-btn-secondary"
							data-wp-on--click="actions.removeTeamPhoto"
							data-wp-bind--hidden="!state.teamHasCover"
							data-wp-bind--disabled="context.teams.edit.saving"
						>
							<?php esc_html_e( 'Remove image', 'mission-donation-platform' ); ?>
						</button>
					</span>
					<span class="mission-dd-profile-hint" data-wp-bind--hidden="!context.teams.photoPreviewUrl">
						<?php esc_html_e( 'The new image will be uploaded when you save your changes.', 'mission-donation-platform' ); ?>
					</span>
					<span class="mission-dd-profile-hint" data-wp-bind--hidden="!context.teams.photoRemoved">
						<?php esc_html_e( 'The image will be removed when you save your changes.', 'mission-donation-platform' ); ?>
					</span>
					<span class="mission-dd-field-error" data-wp-bind--hidden="!context.teams.uploadError" data-wp-text="context.teams.uploadError"></span>
				</div>

				<div class="mission-dd-profile-group mission-dd-profile-group-full">
					<label class="mission-dd-profile-label" for="mission-dd-team-story"><?php esc_html_e( 'Team story', 'mission-donation-platform' ); ?></label>
					<textarea
						id="mission-dd-team-story"
						class="mission-dd-profile-input mission-dd-story"
						rows="6"
						data-wp-bind--value="context.teams.edit.description"
						data-wp-on--input="actions.editTeamDescription"
						data-wp-bind--disabled="context.teams.edit.saving"
					></textarea>
				</div>

			</div>

			<div class="mission-dd-profile-actions">
				<button
					class="mission-dd-btn-primary"
					data-wp-on--click="actions.saveTeam"
					data-wp-bind--disabled="state.teamSaveDisabled"
					data-wp-class--mission-dd-btn-saved="context.teams.edit.saved"
				>
					<span data-wp-text="state.teamSaveLabel"></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Members -->
	<div class="mission-dd-fr-section">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Members', 'mission-donation-platform' ); ?></h2>
		<div class="mission-dd-person-list">
			<template data-wp-each--member="state.teamMembersPageItems">
				<div class="mission-dd-person-item">
					<span class="mission-dd-person-avatar" aria-hidden="true" data-wp-text="context.member.initials"></span>
					<span class="mission-dd-person-body">
						<span class="mission-dd-person-name">
							<span data-wp-text="context.member.name"></span>
							<span class="mission-dd-role-badge mission-dd-role-badge-captain" data-wp-bind--hidden="!context.member.isCaptain"><?php esc_html_e( 'Captain', 'mission-donation-platform' ); ?></span>
						</span>
						<span class="mission-dd-member-bar">
							<span class="mission-dd-member-bar-fill" data-wp-style----bar-width="context.member.barWidth"></span>
						</span>
						<span class="mission-dd-person-sub" data-wp-text="context.member.raisedOfGoalLabel"></span>
					</span>
					<span class="mission-dd-person-actions" data-wp-bind--hidden="state.memberActionsHidden">
						<button type="button" class="mission-dd-btn-secondary" data-wp-on--click="actions.promoteMember"><?php esc_html_e( 'Make captain', 'mission-donation-platform' ); ?></button>
						<button type="button" class="mission-dd-btn-secondary" data-wp-on--click="actions.removeMember"><?php esc_html_e( 'Remove', 'mission-donation-platform' ); ?></button>
					</span>
				</div>
			</template>
		</div>

		<div class="mission-dd-pagination" data-wp-bind--hidden="!state.teamMembersHasPages">
			<span class="mission-dd-pagination-info" data-wp-text="state.teamMembersRangeLabel"></span>
			<span class="mission-dd-pagination-buttons">
				<button
					type="button"
					class="mission-dd-btn-secondary"
					data-wp-on--click="actions.teamMembersPrev"
					data-wp-bind--disabled="state.teamMembersPrevDisabled"
				><?php esc_html_e( 'Previous', 'mission-donation-platform' ); ?></button>
				<button
					type="button"
					class="mission-dd-btn-secondary"
					data-wp-on--click="actions.teamMembersNext"
					data-wp-bind--disabled="state.teamMembersNextDisabled"
				><?php esc_html_e( 'Next', 'mission-donation-platform' ); ?></button>
			</span>
		</div>
	</div>

	<!-- Leave team (members only) -->
	<div class="mission-dd-fr-section mission-dd-danger-zone" data-wp-bind--hidden="context.teams.detail.isCaptain">
		<button
			type="button"
			class="mission-dd-danger-link"
			data-wp-on--click="actions.leaveTeam"
			data-wp-bind--disabled="context.teams.leaving"
		><?php esc_html_e( 'Leave this team', 'mission-donation-platform' ); ?></button>
		<p class="mission-dd-danger-desc"><?php esc_html_e( 'Your fundraiser page stays active. It will just no longer count toward the team total.', 'mission-donation-platform' ); ?></p>
	</div>

</div>
