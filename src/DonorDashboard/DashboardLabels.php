<?php
/**
 * Shared human-readable labels for the donor dashboard.
 *
 * @package MissionDP
 */

namespace MissionDP\DonorDashboard;

use MissionDP\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Label and formatting helpers shared by the dashboard context builders and
 * the donor dashboard REST endpoints, so SSR and REST refreshes stay in sync.
 */
class DashboardLabels {

	/**
	 * Human-readable progress label for a fundraiser ("$480 raised of $1,000 goal").
	 *
	 * @param string $raised_display Formatted amount raised.
	 * @param string $goal_display   Formatted goal, or an empty string when no goal is set.
	 * @return string
	 */
	public static function progress_label( string $raised_display, string $goal_display ): string {
		return $goal_display
			/* translators: 1: amount raised, 2: goal amount */
			? sprintf( __( '%1$s raised of %2$s goal', 'mission-donation-platform' ), $raised_display, $goal_display )
			/* translators: %s: amount raised */
			: sprintf( __( '%s raised', 'mission-donation-platform' ), $raised_display );
	}

	/**
	 * Human-readable progress label for a team ("$8,420 of $10,000 team goal").
	 *
	 * @param string $raised_display Formatted amount raised.
	 * @param string $goal_display   Formatted team goal, or an empty string when no goal is set.
	 * @return string
	 */
	public static function team_progress_label( string $raised_display, string $goal_display ): string {
		return $goal_display
			/* translators: 1: amount raised, 2: team goal */
			? sprintf( __( '%1$s of %2$s team goal', 'mission-donation-platform' ), $raised_display, $goal_display )
			/* translators: %s: amount raised */
			: sprintf( __( '%s raised', 'mission-donation-platform' ), $raised_display );
	}

	/**
	 * Initials for a person's avatar, with a placeholder for anonymous or unnamed people.
	 *
	 * @param string $first        First name.
	 * @param string $last         Last name.
	 * @param bool   $is_anonymous Whether the person chose to stay anonymous.
	 * @return string
	 */
	public static function person_initials( string $first, string $last, bool $is_anonymous = false ): string {
		if ( $is_anonymous ) {
			return '?';
		}

		$initials = strtoupper( mb_substr( $first, 0, 1 ) . mb_substr( $last, 0, 1 ) );

		return '' === trim( $initials ) ? '?' : $initials;
	}

	/**
	 * The attachment ID a cover image field references, or 0 when it holds a URL.
	 *
	 * Fundraiser/team cover images are a varchar that may hold either form.
	 *
	 * @param string $cover Attachment ID or image URL.
	 * @return int
	 */
	public static function cover_image_id( string $cover ): int {
		return ctype_digit( $cover ) ? (int) $cover : 0;
	}

	/**
	 * Resolve a cover image field (attachment ID or URL) to a URL.
	 *
	 * @param string $cover Attachment ID or image URL.
	 * @return string
	 */
	public static function cover_image_url( string $cover ): string {
		if ( '' !== $cover && ctype_digit( $cover ) ) {
			return wp_get_attachment_image_url( (int) $cover, 'large' ) ?: '';
		}

		return $cover;
	}

	/**
	 * The card meta-line time label ("24 days left" / "Ends Sep 12, 2026" / "Ended Jul 12, 2025").
	 *
	 * @param Campaign|null $campaign The campaign, if it still exists.
	 * @param bool          $is_ended Whether the campaign has ended.
	 * @return string
	 */
	public static function time_label( ?Campaign $campaign, bool $is_ended ): string {
		if ( $is_ended ) {
			return $campaign && $campaign->date_end
				/* translators: %s: campaign end date */
				? sprintf( __( 'Ended %s', 'mission-donation-platform' ), date_i18n( 'M j, Y', strtotime( $campaign->date_end ) ) )
				: __( 'Ended', 'mission-donation-platform' );
		}

		$days = $campaign?->days_left();

		if ( null === $days ) {
			return '';
		}

		if ( 0 === $days ) {
			return __( 'Ends today', 'mission-donation-platform' );
		}

		if ( $days <= 30 ) {
			/* translators: %s: number of days */
			return sprintf( _n( '%s day left', '%s days left', $days, 'mission-donation-platform' ), number_format_i18n( $days ) );
		}

		/* translators: %s: campaign end date */
		return sprintf( __( 'Ends %s', 'mission-donation-platform' ), date_i18n( 'M j, Y', strtotime( (string) $campaign->date_end ) ) );
	}
}
