<?php
/**
 * Builds the My Fundraisers context for the donor dashboard block.
 *
 * @package MissionDP
 */

namespace MissionDP\DonorDashboard;

use MissionDP\Currency\Currency;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\FundraiserImageUploader;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares the donor's fundraiser pages for the dashboard: the Active/Ended
 * card lists, the drill-in detail working objects, and the Overview extras
 * (spotlight card, fundraiser stats, recent page donations).
 */
class FundraisersContextBuilder {

	/**
	 * Supporters shown per page in the fundraiser detail view.
	 */
	public const SUPPORTERS_PER_PAGE = 5;

	private string $currency;
	private bool $is_test;

	/** @var array<int, Campaign|null> */
	private array $campaigns = [];

	/** @var array<int, Team|null> */
	private array $teams = [];

	/** @var array<int, array<string, mixed>>|null Prepared cards, in query order. */
	private ?array $cards = null;

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
	 * Build the context.fundraisers payload, or null when the donor has no pages.
	 *
	 * @return array<string, mixed>|null
	 */
	public function build(): ?array {
		if ( empty( $this->fundraisers ) ) {
			return null;
		}

		$cards  = $this->cards();
		$active = array_values( array_filter( $cards, static fn( array $card ): bool => ! $card['isEnded'] ) );
		$ended  = array_values( array_filter( $cards, static fn( array $card ): bool => $card['isEnded'] ) );

		$detail = $active[0] ?? $ended[0];

		return [
			'ids'              => array_map( static fn( array $card ): int => $card['id'], $cards ),
			'active'           => $active,
			'ended'            => $ended,
			'hasActive'        => ! empty( $active ),
			'hasEnded'         => ! empty( $ended ),
			'activeCountLabel' => sprintf(
				/* translators: %s: number of active fundraising pages */
				__( 'Active (%s)', 'mission-donation-platform' ),
				number_format_i18n( count( $active ) )
			),
			'endedCountLabel'  => sprintf(
				/* translators: %s: number of ended fundraising pages */
				__( 'Ended (%s)', 'mission-donation-platform' ),
				number_format_i18n( count( $ended ) )
			),
			'detail'           => $detail,
			'edit'             => [
				'headline'    => $detail['headline'],
				'goal'        => $detail['goalMajor'],
				'story'       => $detail['story'],
				'tributeType' => $detail['tributeType'],
				'tributeName' => $detail['tributeName'],
				'saving'      => false,
				'saved'       => false,
				'error'       => '',
			],
			'supporters'       => [
				'items'      => $detail['supporters'],
				'page'       => 1,
				'perPage'    => self::SUPPORTERS_PER_PAGE,
				'total'      => $detail['supportersTotal'],
				'totalPages' => (int) ceil( $detail['supportersTotal'] / self::SUPPORTERS_PER_PAGE ),
				'loading'    => false,
			],
			'photoPreviewUrl'  => '',
			'photoRemoved'     => false,
			'uploadError'      => '',
			'maxPhotoBytes'    => FundraiserImageUploader::max_size(),
			'currencySymbol'   => Currency::get_symbol( $this->currency ),
			'i18n'             => [
				'save'          => __( 'Save changes', 'mission-donation-platform' ),
				'saving'        => __( 'Saving…', 'mission-donation-platform' ),
				'saved'         => __( 'Saved', 'mission-donation-platform' ),
				'savedToast'    => __( 'Fundraiser updated', 'mission-donation-platform' ),
				'photoTooLarge' => sprintf(
					/* translators: %s: maximum allowed file size, e.g. "5 MB". */
					__( 'The image is too large. Please upload a file under %s.', 'mission-donation-platform' ),
					size_format( FundraiserImageUploader::max_size() )
				),
				'copied'        => __( 'Link copied', 'mission-donation-platform' ),
				/* translators: 1: first row number, 2: last row number, 3: total rows */
				'range'         => __( '%1$s–%2$s of %3$s', 'mission-donation-platform' ),
			],
		];
	}

	/**
	 * Build the Overview additions: stats variant, fundraiser stats, spotlight,
	 * and (for fundraiser-only users) recent donations to their pages.
	 *
	 * @param bool $has_giving Whether the donor has any giving history.
	 * @return array<string, mixed>
	 */
	public function overview_extras( bool $has_giving ): array {
		$cards = $this->cards();

		$page_donations = $has_giving ? [] : $this->prepare_page_donations();

		return [
			'isFundraiserVariant' => ! $has_giving,
			'fundraiserStats'     => $this->prepare_fundraiser_stats( $cards ),
			'spotlight'           => $this->prepare_spotlight( $cards ),
			'recentPageDonations' => $page_donations,
			'hasPageDonations'    => ! empty( $page_donations ),
		];
	}

	/**
	 * The prepared cards, building them on first use.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function cards(): array {
		if ( null === $this->cards ) {
			$this->preload_relations();
			$this->cards = array_map( [ $this, 'prepare_card' ], $this->fundraisers );
		}

		return $this->cards;
	}

	/**
	 * Batch-load the campaigns and teams the fundraisers reference.
	 */
	private function preload_relations(): void {
		$campaign_ids = array_unique( array_filter( array_map( static fn( Fundraiser $f ): int => $f->campaign_id, $this->fundraisers ) ) );
		$team_ids     = array_unique( array_filter( array_map( static fn( Fundraiser $f ): int => (int) $f->team_id, $this->fundraisers ) ) );

		$this->campaigns = $campaign_ids ? Campaign::find_many( $campaign_ids ) : [];
		$this->teams     = $team_ids ? Team::find_many( $team_ids ) : [];
	}

	/**
	 * Prepare one fundraiser card for the dashboard lists and detail view.
	 *
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @return array<string, mixed>
	 */
	private function prepare_card( Fundraiser $fundraiser ): array {
		$campaign = $this->campaigns[ $fundraiser->campaign_id ] ?? null;
		$team     = $fundraiser->team_id ? ( $this->teams[ $fundraiser->team_id ] ?? null ) : null;
		$is_ended = ! $campaign || $campaign->has_ended();

		$raised         = $fundraiser->amount_raised( $this->is_test );
		$donation_count = $this->is_test ? $fundraiser->test_transaction_count : $fundraiser->transaction_count;
		$average        = $donation_count > 0 ? (int) round( $raised / $donation_count ) : 0;
		$goal_display   = $fundraiser->goal > 0 ? Currency::format_amount( $fundraiser->goal, $this->currency ) : '';
		$raised_display = Currency::format_amount( $raised, $this->currency );
		$progress       = $fundraiser->progress( $this->is_test );
		$dedication     = $fundraiser->dedication();
		$url            = $fundraiser->get_url() ?? '';
		$cover_url      = ctype_digit( $fundraiser->cover_image )
			? ( wp_get_attachment_image_url( (int) $fundraiser->cover_image, 'large' ) ?: '' )
			: $fundraiser->cover_image;

		[ $badge_type, $status_label ]         = $this->badge( $fundraiser, $is_ended, $raised );
		[ $time_stat_value, $time_stat_label ] = $this->time_stat( $campaign, $is_ended );

		$share_text = $campaign
			/* translators: %s: campaign title */
			? sprintf( __( 'Support my fundraiser for %s', 'mission-donation-platform' ), $campaign->title )
			: __( 'Support my fundraiser', 'mission-donation-platform' );

		// Supporters page 1 is embedded for editable pages only; ended pages
		// fetch on drill-in so a long history doesn't slow every dashboard load.
		$supporters = [];
		if ( ! $is_ended && $donation_count > 0 ) {
			$page       = $this->reporting->fundraiser_donations_query( (int) $fundraiser->id, self::SUPPORTERS_PER_PAGE, 1 );
			$supporters = array_map( [ $this, 'prepare_supporter' ], $page['items'] );
		}

		$card = [
			'id'                 => (int) $fundraiser->id,
			'campaignTitle'      => $campaign?->title ?? '',
			'headline'           => $fundraiser->headline,
			'story'              => $is_ended ? '' : $fundraiser->story,
			'dedicationLabel'    => $this->dedication_label( $dedication ),
			'tributeType'        => $dedication['type'] ?? '',
			'tributeName'        => $dedication['name'] ?? '',
			'status'             => $fundraiser->status,
			'statusLabel'        => $status_label,
			'badgeType'          => $badge_type,
			'isEnded'            => $is_ended,
			'isLocked'           => $is_ended,
			'isPending'          => Fundraiser::STATUS_PENDING === $fundraiser->status,
			'raisedDisplay'      => $raised_display,
			'goalDisplay'        => $goal_display,
			'hasGoal'            => $fundraiser->goal > 0,
			'goalMajor'          => (string) Currency::minor_to_major( $fundraiser->goal, $this->currency ),
			'progress'           => $progress,
			'barWidth'           => min( 100, (int) round( $progress ) ) . '%',
			'progressLabel'      => $this->progress_label( $raised_display, $goal_display ),
			'percentLabel'       => $fundraiser->goal > 0 ? min( 100, (int) round( $progress ) ) . '%' : '',
			'donationCount'      => $donation_count,
			'donationCountLabel' => sprintf(
				/* translators: %s: number of donations */
				_n( '%s donation', '%s donations', $donation_count, 'mission-donation-platform' ),
				number_format_i18n( $donation_count )
			),
			'avgGiftDisplay'     => Currency::format_amount( $average, $this->currency ),
			'raisedStatLabel'    => $goal_display
				/* translators: %s: goal amount */
				? sprintf( __( 'Raised of %s', 'mission-donation-platform' ), $goal_display )
				: __( 'Raised', 'mission-donation-platform' ),
			'timeLabel'          => $this->time_label( $campaign, $is_ended ),
			'timeStatValue'      => $time_stat_value,
			'timeStatLabel'      => $time_stat_label,
			'url'                => $url,
			'hasUrl'             => '' !== $url,
			'coverImageUrl'      => $cover_url,
			'hasCover'           => '' !== $cover_url,
			'teamId'             => $fundraiser->team_id,
			'teamName'           => $team?->name ?? '',
			'teamUrl'            => $team?->get_url() ?? '',
			'onTeam'             => null !== $fundraiser->team_id,
			'isCaptain'          => $fundraiser->is_captain(),
			'roleLabel'          => $fundraiser->team_id
				? ( $fundraiser->is_captain() ? __( 'Captain', 'mission-donation-platform' ) : __( 'Member', 'mission-donation-platform' ) )
				: '',
			'shareFacebook'      => $url ? 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) : '',
			'shareX'             => $url ? 'https://twitter.com/intent/tweet?text=' . rawurlencode( $share_text ) . '&url=' . rawurlencode( $url ) : '',
			'shareBluesky'       => $url ? 'https://bsky.app/intent/compose?text=' . rawurlencode( $share_text . ' ' . $url ) : '',
			'supporters'         => $supporters,
			'supportersTotal'    => $donation_count,
		];

		/**
		 * Filters a fundraiser card as prepared for the donor dashboard.
		 *
		 * @param array      $card       Prepared card data.
		 * @param Fundraiser $fundraiser The fundraiser model.
		 */
		return apply_filters( 'mission_donor_dashboard_fundraiser_card', $card, $fundraiser );
	}

	/**
	 * Prepare one supporter row for the detail view.
	 *
	 * @param array<string, mixed> $row Row from ReportingService::fundraiser_donations_query().
	 * @return array<string, mixed>
	 */
	private function prepare_supporter( array $row ): array {
		$name         = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		$is_anonymous = (bool) $row['is_anonymous'];
		$initials     = $is_anonymous ? '?' : strtoupper( mb_substr( (string) ( $row['first_name'] ?? '' ), 0, 1 ) . mb_substr( (string) ( $row['last_name'] ?? '' ), 0, 1 ) );

		return [
			'name'       => $is_anonymous || '' === $name ? __( 'Anonymous', 'mission-donation-platform' ) : $name,
			'initials'   => '' === trim( $initials ) ? '?' : $initials,
			'amount'     => Currency::format_amount( $row['amount'], $this->currency ),
			'timeAgo'    => $row['date']
				/* translators: %s: human-readable time difference (e.g. "3 days") */
				? sprintf( __( '%s ago', 'mission-donation-platform' ), human_time_diff( strtotime( $row['date'] ) ) )
				: '',
			'comment'    => $row['comment'] ?? '',
			'hasComment' => ! empty( $row['comment'] ),
		];
	}

	/**
	 * The card's badge type and label, by precedence.
	 *
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @param bool       $is_ended   Whether the campaign has ended.
	 * @param int        $raised     Amount raised in minor units.
	 * @return array{0: string, 1: string} Badge type and label.
	 */
	private function badge( Fundraiser $fundraiser, bool $is_ended, int $raised ): array {
		if ( Fundraiser::STATUS_PENDING === $fundraiser->status ) {
			return [ 'pending', __( 'Pending review', 'mission-donation-platform' ) ];
		}

		if ( Fundraiser::STATUS_INACTIVE === $fundraiser->status ) {
			return [ 'inactive', __( 'Inactive', 'mission-donation-platform' ) ];
		}

		if ( $is_ended && $fundraiser->goal > 0 && $raised >= $fundraiser->goal ) {
			return [ 'goal-met', __( 'Goal met', 'mission-donation-platform' ) ];
		}

		if ( $is_ended ) {
			return [ 'ended', __( 'Ended', 'mission-donation-platform' ) ];
		}

		return [ 'active', __( 'Active', 'mission-donation-platform' ) ];
	}

	/**
	 * Human progress label ("$480 raised of $1,000 goal").
	 *
	 * @param string $raised_display Formatted amount raised.
	 * @param string $goal_display   Formatted goal, or '' when no goal.
	 * @return string
	 */
	private function progress_label( string $raised_display, string $goal_display ): string {
		return $goal_display
			/* translators: 1: amount raised, 2: goal amount */
			? sprintf( __( '%1$s raised of %2$s goal', 'mission-donation-platform' ), $raised_display, $goal_display )
			/* translators: %s: amount raised */
			: sprintf( __( '%s raised', 'mission-donation-platform' ), $raised_display );
	}

	/**
	 * Human dedication label for a card, or '' when none.
	 *
	 * @param array{type: string, name: string}|null $dedication From Fundraiser::dedication().
	 * @return string
	 */
	private function dedication_label( ?array $dedication ): string {
		if ( ! $dedication ) {
			return '';
		}

		return 'memory' === $dedication['type']
			/* translators: %s: person being honored */
			? sprintf( __( 'In memory of %s', 'mission-donation-platform' ), $dedication['name'] )
			/* translators: %s: person being honored */
			: sprintf( __( 'In honor of %s', 'mission-donation-platform' ), $dedication['name'] );
	}

	/**
	 * The card meta-line time label ("24 days left" / "Ends Sep 12, 2026" / "Ended Jul 12, 2025").
	 *
	 * @param Campaign|null $campaign The campaign, if it still exists.
	 * @param bool          $is_ended Whether the campaign has ended.
	 * @return string
	 */
	private function time_label( ?Campaign $campaign, bool $is_ended ): string {
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

	/**
	 * The detail hero's fourth stat: value and label for the time dimension.
	 *
	 * @param Campaign|null $campaign The campaign, if it still exists.
	 * @param bool          $is_ended Whether the campaign has ended.
	 * @return array{0: string, 1: string} Stat value and label.
	 */
	private function time_stat( ?Campaign $campaign, bool $is_ended ): array {
		if ( $is_ended ) {
			return [
				$campaign && $campaign->date_end ? date_i18n( 'M Y', strtotime( $campaign->date_end ) ) : '—',
				__( 'Ended', 'mission-donation-platform' ),
			];
		}

		$days = $campaign?->days_left();

		if ( null === $days ) {
			return [ '—', __( 'No End Date', 'mission-donation-platform' ) ];
		}

		if ( $days <= 30 ) {
			return [
				number_format_i18n( $days ),
				_n( 'Day Left', 'Days Left', $days, 'mission-donation-platform' ),
			];
		}

		return [
			date_i18n( 'M j', strtotime( (string) $campaign->date_end ) ),
			__( 'Campaign Ends', 'mission-donation-platform' ),
		];
	}

	/**
	 * Aggregate stats across the donor's pages for the fundraiser Overview.
	 *
	 * @param array<int, array<string, mixed>> $cards Prepared cards.
	 * @return array<int, array{value: string, label: string}>
	 */
	private function prepare_fundraiser_stats( array $cards ): array {
		$total_raised   = 0;
		$donation_count = 0;
		$active_count   = 0;

		foreach ( $this->fundraisers as $fundraiser ) {
			$total_raised   += $fundraiser->amount_raised( $this->is_test );
			$donation_count += $this->is_test ? $fundraiser->test_transaction_count : $fundraiser->transaction_count;
		}

		foreach ( $cards as $card ) {
			if ( ! $card['isEnded'] && Fundraiser::STATUS_ACTIVE === $card['status'] ) {
				++$active_count;
			}
		}

		$stats = [
			[
				'value' => Currency::format_amount( $total_raised, $this->currency ),
				'label' => __( 'Total Raised', 'mission-donation-platform' ),
			],
			[
				'value' => number_format_i18n( $donation_count ),
				'label' => _n( 'Donation Received', 'Donations Received', $donation_count, 'mission-donation-platform' ),
			],
			[
				'value' => number_format_i18n( $active_count ),
				'label' => _n( 'Active Fundraiser', 'Active Fundraisers', $active_count, 'mission-donation-platform' ),
			],
		];

		/**
		 * Filters the fundraiser stats displayed on the donor dashboard Overview.
		 *
		 * Each stat has 'value' (formatted string) and 'label' (display name).
		 *
		 * @param array $stats Stats array.
		 * @param Donor $donor The current donor.
		 */
		return apply_filters( 'mission_donor_dashboard_fundraiser_stats', $stats, $this->donor );
	}

	/**
	 * The Overview "Your Fundraising" spotlight, or null with no live page.
	 *
	 * Features the most recently created active page; other active pages are
	 * listed in the "Also active" line.
	 *
	 * @param array<int, array<string, mixed>> $cards Prepared cards.
	 * @return array<string, mixed>|null
	 */
	private function prepare_spotlight( array $cards ): ?array {
		$live = array_values(
			array_filter(
				$cards,
				static fn( array $card ): bool => ! $card['isEnded'] && Fundraiser::STATUS_ACTIVE === $card['status']
			)
		);

		if ( empty( $live ) ) {
			return null;
		}

		$featured = $live[0];
		$others   = array_slice( $live, 1 );

		$spotlight = [
			'fundraiserId'       => $featured['id'],
			'campaignTitle'      => $featured['campaignTitle'],
			'headline'           => $featured['headline'],
			'barWidth'           => $featured['barWidth'],
			'progressLabel'      => $featured['progressLabel'],
			'donationCountLabel' => $featured['donationCountLabel'],
			'timeLabel'          => $featured['timeLabel'],
			'url'                => $featured['url'],
			'hasUrl'             => $featured['hasUrl'],
			'onTeam'             => $featured['onTeam'],
			'teamName'           => $featured['teamName'],
			'roleLabel'          => $featured['roleLabel'],
			'isCaptain'          => $featured['isCaptain'],
			'teamProgressLabel'  => $this->team_progress_label( $featured ),
			'others'             => array_map(
				static fn( array $card ): array => [
					'id'            => $card['id'],
					'headline'      => '' !== $card['headline'] ? $card['headline'] : $card['campaignTitle'],
					'campaignTitle' => $card['campaignTitle'],
					'progressLabel' => $card['progressLabel'],
					'percentLabel'  => $card['percentLabel'],
				],
				$others
			),
			'hasOthers'          => ! empty( $others ),
		];

		/**
		 * Filters the fundraising spotlight card on the donor dashboard Overview.
		 *
		 * @param array $spotlight Spotlight data.
		 * @param Donor $donor     The current donor.
		 */
		return apply_filters( 'mission_donor_dashboard_spotlight', $spotlight, $this->donor );
	}

	/**
	 * The spotlight's team-goal progress line ("$8,420 of $10,000 team goal").
	 *
	 * @param array<string, mixed> $card The featured card.
	 * @return string Empty when the fundraiser is not on a team.
	 */
	private function team_progress_label( array $card ): string {
		if ( ! $card['onTeam'] ) {
			return '';
		}

		$team = $this->teams[ $card['teamId'] ] ?? null;

		if ( ! $team ) {
			return '';
		}

		$raised_display = Currency::format_amount( $this->reporting->team_totals( (int) $team->id )['raised'], $this->currency );

		return $team->goal > 0
			/* translators: 1: amount raised, 2: team goal */
			? sprintf( __( '%1$s of %2$s team goal', 'mission-donation-platform' ), $raised_display, Currency::format_amount( $team->goal, $this->currency ) )
			/* translators: %s: amount raised */
			: sprintf( __( '%s raised', 'mission-donation-platform' ), $raised_display );
	}

	/**
	 * Recent donations across the donor's pages, for the fundraiser Overview table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_page_donations(): array {
		$titles = [];
		foreach ( $this->cards() as $card ) {
			$titles[ $card['id'] ] = '' !== $card['headline'] ? $card['headline'] : $card['campaignTitle'];
		}

		return array_map(
			function ( array $row ) use ( $titles ): array {
				$name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );

				return [
					'date'         => $row['date'] ? date_i18n( 'M j, Y', strtotime( $row['date'] ) ) : '',
					'donor'        => $row['is_anonymous'] || '' === $name
						? __( 'Anonymous', 'mission-donation-platform' )
						: $name,
					'amount'       => Currency::format_amount( $row['amount'], $this->currency ),
					'page'         => $titles[ $row['fundraiser_id'] ] ?? ( '' !== $row['headline'] ? $row['headline'] : '' ),
					'fundraiserId' => $row['fundraiser_id'],
				];
			},
			$this->reporting->donor_pages_recent_donations( $this->donor->id, 5 )
		);
	}
}
