<?php
/**
 * Builds the My Teams context for the donor dashboard block.
 *
 * @package MissionDP
 */

namespace MissionDP\DonorDashboard;

use MissionDP\Currency\Currency;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use MissionDP\P2P\FundraiserImageUploader;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares the donor's team memberships for the dashboard: current team cards
 * (with rank, members, and captain tools) and the compact past-teams list.
 */
class TeamsContextBuilder {

	/**
	 * Members shown per page in the team detail view (client-side paging).
	 */
	public const MEMBERS_PER_PAGE = 5;

	private string $currency;
	private bool $is_test;

	/**
	 * Constructor.
	 *
	 * @param Donor            $donor       The authenticated donor.
	 * @param array            $settings    Plugin settings (missiondp_settings option).
	 * @param ReportingService $reporting   Reporting service.
	 * @param Fundraiser[]     $fundraisers The donor's fundraisers, newest first.
	 */
	public function __construct(
		private Donor $donor,
		private array $settings,
		private ReportingService $reporting,
		private array $fundraisers,
	) {
		$this->currency = strtoupper( $settings['currency'] ?? 'USD' );
		$this->is_test  = ! empty( $settings['test_mode'] );
	}

	/**
	 * Build the context.teams payload, or null when the donor is on no teams.
	 *
	 * @return array<string, mixed>|null
	 */
	public function build(): ?array {
		$memberships = array_values( array_filter( $this->fundraisers, static fn( Fundraiser $f ): bool => null !== $f->team_id ) );

		if ( empty( $memberships ) ) {
			return null;
		}

		$team_ids  = array_unique( array_map( static fn( Fundraiser $f ): int => (int) $f->team_id, $memberships ) );
		$teams     = Team::find_many( $team_ids );
		$campaigns = Campaign::find_many( array_unique( array_map( static fn( Fundraiser $f ): int => $f->campaign_id, $memberships ) ) );

		$current = [];
		$past    = [];

		foreach ( $memberships as $membership ) {
			$team = $teams[ $membership->team_id ] ?? null;

			if ( ! $team ) {
				continue;
			}

			$campaign = $campaigns[ $team->campaign_id ] ?? null;

			if ( ! $campaign || $campaign->has_ended() ) {
				$past[] = $this->prepare_past_entry( $team, $campaign, $membership );
			} else {
				$current[] = $this->prepare_card( $team, $campaign, $membership );
			}
		}

		if ( empty( $current ) && empty( $past ) ) {
			return null;
		}

		// The detail working object starts on the first current team; drilling
		// in from a card (or a deep link) re-syncs it client-side.
		$detail = $current[0] ?? null;

		return [
			'ids'             => array_map( static fn( array $card ): int => $card['id'], $current ),
			'current'         => $current,
			'past'            => $past,
			'hasCurrent'      => ! empty( $current ),
			'hasPast'         => ! empty( $past ),
			'detail'          => $detail,
			'edit'            => [
				'name'        => $detail['name'] ?? '',
				'goal'        => $detail['goalMajor'] ?? '',
				'access'      => $detail['access'] ?? Team::ACCESS_PUBLIC,
				'description' => $detail['description'] ?? '',
				'saving'      => false,
				'saved'       => false,
				'error'       => '',
			],
			'invite'          => [
				'email'   => '',
				'error'   => '',
				'sending' => false,
			],
			'membersPage'     => 1,
			'membersPerPage'  => self::MEMBERS_PER_PAGE,
			'leaving'         => false,
			'photoPreviewUrl' => '',
			'photoRemoved'    => false,
			'uploadError'     => '',
			'maxPhotoBytes'   => FundraiserImageUploader::max_size(),
			'donorId'         => (int) $this->donor->id,
			'currencySymbol'  => Currency::get_symbol( $this->currency ),
			'i18n'            => [
				'save'           => __( 'Save changes', 'mission-donation-platform' ),
				'saving'         => __( 'Saving…', 'mission-donation-platform' ),
				'saved'          => __( 'Saved', 'mission-donation-platform' ),
				'teamToast'      => __( 'Team updated', 'mission-donation-platform' ),
				'photoTooLarge'  => sprintf(
					/* translators: %s: maximum allowed file size, e.g. "5 MB". */
					__( 'The image is too large. Please upload a file under %s.', 'mission-donation-platform' ),
					size_format( FundraiserImageUploader::max_size() )
				),
				'inviteToast'    => __( 'Invitation sent', 'mission-donation-platform' ),
				'removeToast'    => __( 'Member removed', 'mission-donation-platform' ),
				'promoteToast'   => __( 'New captain set', 'mission-donation-platform' ),
				'leaveToast'     => __( 'You left the team', 'mission-donation-platform' ),
				'memberRole'     => __( 'Member', 'mission-donation-platform' ),
				/* translators: %s: team captain's name */
				'captainChip'    => __( 'Captain: %s', 'mission-donation-platform' ),
				'confirmRemove'  => __( 'Remove this member from the team? Their fundraiser page stays active but will no longer count toward the team total.', 'mission-donation-platform' ),
				'confirmPromote' => __( 'Make this member the captain? You will no longer manage the team.', 'mission-donation-platform' ),
				'confirmLeave'   => __( 'Leave this team? Your fundraiser page stays active. It will just no longer count toward the team total.', 'mission-donation-platform' ),
				/* translators: 1: first row number, 2: last row number, 3: total rows */
				'range'          => __( '%1$s–%2$s of %3$s', 'mission-donation-platform' ),
			],
		];
	}

	/**
	 * Prepare one current team card for the dashboard list and detail view.
	 *
	 * @param Team       $team       Team model.
	 * @param Campaign   $campaign   The team's campaign.
	 * @param Fundraiser $membership The donor's fundraiser on this team.
	 * @return array<string, mixed>
	 */
	private function prepare_card( Team $team, Campaign $campaign, Fundraiser $membership ): array {
		$totals         = $this->reporting->team_totals( (int) $team->id );
		$raised_display = Currency::format_amount( $totals['raised'], $this->currency );
		$goal_display   = $team->goal > 0 ? Currency::format_amount( $team->goal, $this->currency ) : '';
		$progress       = $team->goal > 0 ? min( 100.0, round( $totals['raised'] / $team->goal * 100, 2 ) ) : 0.0;
		$is_captain     = $membership->is_captain();
		$members        = array_map( [ $this, 'prepare_member' ], $this->reporting->team_members( (int) $team->id ) );
		$cover_url      = ctype_digit( $team->cover_image )
			? ( wp_get_attachment_image_url( (int) $team->cover_image, 'large' ) ?: '' )
			: $team->cover_image;

		$rank_label = '';
		$rank       = [
			'rank'  => 0,
			'total' => 0,
		];
		if ( Team::STATUS_ACTIVE === $team->status ) {
			$rank = $this->reporting->team_rank( (int) $team->id );
			if ( $rank['total'] > 1 ) {
				/* translators: 1: this team's rank, 2: number of teams */
				$rank_label = sprintf( __( 'Rank #%1$s of %2$s teams', 'mission-donation-platform' ), number_format_i18n( $rank['rank'] ), number_format_i18n( $rank['total'] ) );
			}
		}

		$captain_name = '';
		foreach ( $members as $member ) {
			if ( $member['isCaptain'] ) {
				$captain_name = $member['name'];
				break;
			}
		}

		$status_labels = [
			Team::STATUS_ACTIVE   => __( 'Active', 'mission-donation-platform' ),
			Team::STATUS_PENDING  => __( 'Pending review', 'mission-donation-platform' ),
			Team::STATUS_INACTIVE => __( 'Inactive', 'mission-donation-platform' ),
		];

		$url = $team->get_url();

		$card = [
			'id'               => (int) $team->id,
			'name'             => $team->name,
			'campaignTitle'    => $campaign->title,
			'description'      => $team->description,
			'status'           => $team->status,
			'statusLabel'      => $status_labels[ $team->status ] ?? $team->status,
			'isPending'        => Team::STATUS_PENDING === $team->status,
			'isInactive'       => Team::STATUS_INACTIVE === $team->status,
			'isLocked'         => false,
			'access'           => $team->access,
			'isPrivate'        => Team::ACCESS_PRIVATE === $team->access,
			'isCaptain'        => $is_captain,
			'roleLabel'        => $is_captain ? __( 'Captain', 'mission-donation-platform' ) : __( 'Member', 'mission-donation-platform' ),
			'captainName'      => $is_captain ? '' : $captain_name,
			'captainChipLabel' => ! $is_captain && '' !== $captain_name
				/* translators: %s: team captain's name */
				? sprintf( __( 'Captain: %s', 'mission-donation-platform' ), $captain_name )
				: '',
			'raisedDisplay'    => $raised_display,
			'goalDisplay'      => $goal_display,
			'hasGoal'          => $team->goal > 0,
			'goalMajor'        => (string) Currency::minor_to_major( $team->goal, $this->currency ),
			'progress'         => $progress,
			'barWidth'         => min( 100, (int) round( $progress ) ) . '%',
			'progressLabel'    => $goal_display
				/* translators: 1: amount raised, 2: team goal */
				? sprintf( __( '%1$s of %2$s team goal', 'mission-donation-platform' ), $raised_display, $goal_display )
				/* translators: %s: amount raised */
				: sprintf( __( '%s raised', 'mission-donation-platform' ), $raised_display ),
			'percentLabel'     => $team->goal > 0 ? min( 100, (int) round( $progress ) ) . '%' : '',
			'goalStatLabel'    => $goal_display
				/* translators: %s: team goal */
				? sprintf( __( 'Of %s Goal', 'mission-donation-platform' ), $goal_display )
				: __( 'Raised', 'mission-donation-platform' ),
			'memberCount'      => $totals['member_count'],
			'memberCountLabel' => sprintf(
				/* translators: %s: number of team members */
				_n( '%s member', '%s members', $totals['member_count'], 'mission-donation-platform' ),
				number_format_i18n( $totals['member_count'] )
			),
			'donationCount'    => $totals['donations'],
			'rank'             => $rank['rank'],
			'rankTotal'        => $rank['total'],
			'rankLabel'        => $rank_label,
			'rankStatValue'    => $rank_label ? '#' . number_format_i18n( $rank['rank'] ) : '',
			'rankStatLabel'    => $rank_label
				/* translators: %s: number of teams */
				? sprintf( __( 'Of %s Teams', 'mission-donation-platform' ), number_format_i18n( $rank['total'] ) )
				: '',
			'timeLabel'        => $this->time_label( $campaign ),
			'url'              => $url ?? '',
			'hasUrl'           => null !== $url,
			'coverImageUrl'    => $cover_url,
			'hasCover'         => '' !== $cover_url,
			'myFundraiserId'   => (int) $membership->id,
			'members'          => $members,
			'invitations'      => $is_captain ? $this->prepare_invitations( $team ) : [],
		];

		/**
		 * Filters a team card as prepared for the donor dashboard.
		 *
		 * @param array $card Prepared card data.
		 * @param Team  $team The team model.
		 */
		return apply_filters( 'mission_donor_dashboard_team_card', $card, $team );
	}

	/**
	 * Prepare one member row for the team detail list.
	 *
	 * @param array<string, mixed> $row Row from ReportingService::team_members().
	 * @return array<string, mixed>
	 */
	private function prepare_member( array $row ): array {
		$raised_display = Currency::format_amount( $row['raised'], $this->currency );
		$goal_display   = $row['goal'] > 0 ? Currency::format_amount( $row['goal'], $this->currency ) : '';
		$progress       = $row['goal'] > 0 ? min( 100.0, round( $row['raised'] / $row['goal'] * 100, 2 ) ) : 0.0;
		$initials       = strtoupper( mb_substr( $row['first_name'], 0, 1 ) . mb_substr( $row['last_name'], 0, 1 ) );

		return [
			'fundraiserId'      => $row['id'],
			'donorId'           => $row['donor_id'],
			'name'              => $row['name'],
			'initials'          => '' === trim( $initials ) ? '?' : $initials,
			'isCaptain'         => $row['is_captain'],
			'isSelf'            => $row['donor_id'] === (int) $this->donor->id,
			'progress'          => $progress,
			'barWidth'          => min( 100, (int) round( $progress ) ) . '%',
			'raisedOfGoalLabel' => $goal_display
				/* translators: 1: amount raised, 2: personal goal */
				? sprintf( __( '%1$s of %2$s', 'mission-donation-platform' ), $raised_display, $goal_display )
				: $raised_display,
		];
	}

	/**
	 * Prepare a team's pending invitations (captain view).
	 *
	 * @param Team $team Team model.
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_invitations( Team $team ): array {
		return array_map(
			static fn( TeamInvitation $invitation ): array => [
				'id'    => (int) $invitation->id,
				'email' => $invitation->email,
				'sent'  => ! empty( $invitation->sent_at ),
			],
			$team->invitations(
				[
					'status'   => TeamInvitation::STATUS_PENDING,
					'per_page' => -1,
				]
			)
		);
	}

	/**
	 * Prepare one past-team row (ended campaign; read-only).
	 *
	 * @param Team          $team       Team model.
	 * @param Campaign|null $campaign   The team's campaign, if it still exists.
	 * @param Fundraiser    $membership The donor's fundraiser on this team.
	 * @return array<string, mixed>
	 */
	private function prepare_past_entry( Team $team, ?Campaign $campaign, Fundraiser $membership ): array {
		$raised = $this->reporting->team_totals( (int) $team->id )['raised'];

		return [
			'name'          => $team->name,
			'campaignTitle' => $campaign?->title ?? '',
			'roleLabel'     => $membership->is_captain()
				? __( 'Captain', 'mission-donation-platform' )
				: __( 'Member', 'mission-donation-platform' ),
			'raisedLabel'   => sprintf(
				/* translators: %s: amount the team raised */
				__( 'team raised %s', 'mission-donation-platform' ),
				Currency::format_amount( $raised, $this->currency )
			),
		];
	}

	/**
	 * The card meta-line time label for a live campaign.
	 *
	 * @param Campaign $campaign The campaign.
	 * @return string
	 */
	private function time_label( Campaign $campaign ): string {
		$days = $campaign->days_left();

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
