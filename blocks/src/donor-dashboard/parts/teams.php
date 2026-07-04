<?php
/**
 * Donor Dashboard — My Teams panel (card list).
 *
 * The donor's team memberships on live campaigns as drill-in cards, plus a
 * compact read-only list of teams from ended campaigns. Rendered only when the
 * donor has (or had) a team.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isTeams">

	<div data-wp-bind--hidden="!context.teams.hasCurrent">
		<template data-wp-each--card="context.teams.current">
			<button
				type="button"
				class="mission-dd-fr-card"
				data-wp-on--click="actions.openTeam"
			>
				<span class="mission-dd-fr-title" data-wp-text="context.card.name"></span>
				<span class="mission-dd-fr-campaign" data-wp-text="context.card.campaignTitle"></span>
				<span class="mission-dd-fr-foot">
					<span
						class="mission-dd-role-badge"
						data-wp-class--mission-dd-role-badge-captain="context.card.isCaptain"
						data-wp-text="context.card.roleLabel"
					></span>
					<span class="mission-dd-badge" data-badge="inactive" data-wp-bind--hidden="!context.card.isPrivate"><?php esc_html_e( 'Private', 'mission-donation-platform' ); ?></span>
					<span class="mission-dd-badge" data-badge="pending" data-wp-bind--hidden="!context.card.isPending" data-wp-text="context.card.statusLabel"></span>
					<span class="mission-dd-team-chip" data-wp-bind--hidden="!context.card.captainChipLabel" data-wp-text="context.card.captainChipLabel"></span>
				</span>
				<span class="mission-dd-progress-track" data-wp-bind--hidden="!context.card.hasGoal">
					<span class="mission-dd-progress-fill" data-wp-style--width="context.card.barWidth"></span>
				</span>
				<span class="mission-dd-progress-meta">
					<span data-wp-text="context.card.progressLabel"></span>
					<span data-wp-bind--hidden="!context.card.percentLabel" data-wp-text="context.card.percentLabel"></span>
					<span data-wp-text="context.card.memberCountLabel"></span>
					<span data-wp-bind--hidden="!context.card.rankLabel" data-wp-text="context.card.rankLabel"></span>
				</span>
			</button>
		</template>
	</div>

	<div class="mission-dd-empty" data-wp-bind--hidden="context.teams.hasCurrent">
		<p><?php esc_html_e( 'You are not on a team for any current campaign.', 'mission-donation-platform' ); ?></p>
	</div>

	<div data-wp-bind--hidden="!context.teams.hasPast">
		<h2 class="mission-dd-section-title mission-dd-section-title-spaced"><?php esc_html_e( 'Past Teams', 'mission-donation-platform' ); ?></h2>
		<div class="mission-dd-past-list">
			<template data-wp-each--pastteam="context.teams.past">
				<div class="mission-dd-past-item">
					<strong data-wp-text="context.pastteam.name"></strong>
					<span data-wp-text="context.pastteam.campaignTitle"></span>
					<span class="mission-dd-role-badge" data-wp-text="context.pastteam.roleLabel"></span>
					<span data-wp-text="context.pastteam.raisedLabel"></span>
				</div>
			</template>
		</div>
	</div>

</div>
