<?php
/**
 * Shared team roster row shaping for the donor dashboard.
 *
 * @package MissionDP
 */

namespace MissionDP\DonorDashboard;

use MissionDP\Currency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes team member rows for the dashboard, shared by the SSR context
 * builder and the team REST endpoint so first render and post-action
 * repaints carry identical rows.
 */
class TeamRoster {

	/**
	 * Prepare one member row for the team detail list.
	 *
	 * @param array<string, mixed> $row           Row from ReportingService::team_members().
	 * @param string               $currency      Currency code for formatting.
	 * @param int                  $self_donor_id The viewing donor's ID, for self detection.
	 * @return array<string, mixed>
	 */
	public static function member_row( array $row, string $currency, int $self_donor_id ): array {
		$raised_display = Currency::format_amount( $row['raised'], $currency );
		$goal_display   = $row['goal'] > 0 ? Currency::format_amount( $row['goal'], $currency ) : '';
		$progress       = $row['goal'] > 0 ? min( 100.0, round( $row['raised'] / $row['goal'] * 100, 2 ) ) : 0.0;

		return [
			'fundraiserId'      => $row['id'],
			'name'              => $row['name'],
			'initials'          => DashboardLabels::person_initials( $row['first_name'], $row['last_name'] ),
			'isCaptain'         => $row['is_captain'],
			'isSelf'            => $row['donor_id'] === $self_donor_id,
			'progress'          => $progress,
			'barWidth'          => min( 100, (int) round( $progress ) ) . '%',
			'raisedOfGoalLabel' => $goal_display
				/* translators: 1: amount raised, 2: personal goal */
				? sprintf( __( '%1$s of %2$s', 'mission-donation-platform' ), $raised_display, $goal_display )
				: $raised_display,
		];
	}
}
