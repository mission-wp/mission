<?php
/**
 * Donor Dashboard — My Fundraisers panel (card list).
 *
 * The donor's fundraising pages grouped by campaign state; clicking a card
 * drills into the fundraiser detail view. Rendered only when the donor has at
 * least one fundraiser.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

// One card template for both groups. Emitted by the closure below so the
// Active and Ended lists can't drift apart.
$mission_dd_fr_card = static function ( string $list ): void {
	?>
	<template data-wp-each--card="context.fundraisers.<?php echo esc_attr( $list ); ?>">
		<button
			type="button"
			class="mission-dd-fr-card"
			data-wp-class--is-ended="context.card.isEnded"
			data-wp-on--click="actions.openFundraiser"
		>
			<span class="mission-dd-fr-card-top">
				<span class="mission-dd-fr-campaign" data-wp-text="context.card.campaignTitle"></span>
				<span class="mission-dd-badge" data-wp-bind--data-badge="context.card.badgeType" data-wp-text="context.card.statusLabel"></span>
			</span>
			<span class="mission-dd-fr-title" data-wp-text="context.card.headline"></span>
			<span class="mission-dd-fr-tribute" data-wp-bind--hidden="!context.card.dedicationLabel" data-wp-text="context.card.dedicationLabel"></span>
			<span class="mission-dd-progress-track" data-wp-bind--hidden="!context.card.hasGoal">
				<span class="mission-dd-progress-fill" data-wp-class--is-ended="context.card.isEnded" data-wp-style--width="context.card.barWidth"></span>
			</span>
			<span class="mission-dd-progress-meta">
				<span data-wp-text="context.card.progressLabel"></span>
				<span data-wp-bind--hidden="!context.card.percentLabel" data-wp-text="context.card.percentLabel"></span>
				<span data-wp-text="context.card.donationCountLabel"></span>
				<span data-wp-bind--hidden="!context.card.timeLabel" data-wp-text="context.card.timeLabel"></span>
			</span>
			<span class="mission-dd-fr-foot">
				<span class="mission-dd-team-chip" data-wp-bind--hidden="!context.card.onTeam" data-wp-text="context.card.teamName"></span>
				<span
					class="mission-dd-role-badge"
					data-wp-bind--hidden="!context.card.onTeam"
					data-wp-class--mission-dd-role-badge-captain="context.card.isCaptain"
					data-wp-text="context.card.roleLabel"
				></span>
				<span class="mission-dd-team-chip" data-wp-bind--hidden="context.card.onTeam"><?php esc_html_e( 'Individual fundraiser', 'mission-donation-platform' ); ?></span>
			</span>
		</button>
	</template>
	<?php
};
?>
<div class="mission-dd-panel" data-wp-class--active="state.isFundraisers">

	<div data-wp-bind--hidden="!context.fundraisers.hasActive">
		<h2 class="mission-dd-section-title" data-wp-text="context.fundraisers.activeCountLabel"></h2>
		<?php $mission_dd_fr_card( 'active' ); ?>
	</div>

	<div data-wp-bind--hidden="!context.fundraisers.hasEnded">
		<h2 class="mission-dd-section-title mission-dd-section-title-spaced" data-wp-text="context.fundraisers.endedCountLabel"></h2>
		<?php $mission_dd_fr_card( 'ended' ); ?>
	</div>

</div>
<?php unset( $mission_dd_fr_card ); ?>
