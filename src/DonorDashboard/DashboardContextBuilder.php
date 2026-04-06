<?php
/**
 * Builds the Interactivity API context for the donor dashboard block.
 *
 * Extracts data querying, preparation, and context assembly from index.php
 * so the template file stays focused on rendering.
 *
 * @package Mission
 */

namespace Mission\DonorDashboard;

use Mission\Currency\Currency;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use Mission\Models\Transaction;
use Mission\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard context builder.
 */
class DashboardContextBuilder {

	/**
	 * Per-page limit for history pagination.
	 */
	private const HISTORY_PER_PAGE = 20;

	/**
	 * Preference meta keys and their defaults.
	 */
	private const PREFERENCE_DEFAULTS = [
		'email_receipts'         => true,
		'email_campaign_updates' => true,
		'email_annual_reminder'  => true,
	];

	private string $currency;
	private bool $is_test;
	private ReportingService $reporting;

	/** @var array<string, string> */
	private array $frequency_suffix;

	/** @var array<string, string> */
	private array $frequency_labels;

	/** @var array<int, Campaign|null> */
	private array $campaign_map = [];

	/**
	 * Constructor.
	 *
	 * @param Donor $donor    The authenticated donor.
	 * @param array $settings Plugin settings (mission_settings option).
	 */
	public function __construct(
		private readonly Donor $donor,
		private readonly array $settings,
	) {
		$this->currency  = strtoupper( $this->settings['currency'] ?? 'USD' );
		$this->is_test   = ! empty( $this->settings['test_mode'] );
		$this->reporting = new ReportingService();

		$this->frequency_suffix = [
			'weekly'    => __( '/ week', 'mission' ),
			'monthly'   => __( '/ month', 'mission' ),
			'quarterly' => __( '/ quarter', 'mission' ),
			'annually'  => __( '/ year', 'mission' ),
		];

		$this->frequency_labels = [
			'weekly'    => __( 'Weekly', 'mission' ),
			'monthly'   => __( 'Monthly', 'mission' ),
			'quarterly' => __( 'Quarterly', 'mission' ),
			'annually'  => __( 'Annually', 'mission' ),
		];
	}

	/**
	 * Build the full Interactivity API context array.
	 *
	 * @param array $panels      Panel definitions (keyed by panel ID).
	 * @param array $panel_labels Panel labels (keyed by panel ID).
	 * @return array{context: array, state: array} Context and state arrays.
	 */
	public function build( array $panels, array $panel_labels ): array {
		$donor = $this->donor;

		// ── Query all data up front ──
		$recent_transactions     = $this->query_recent_transactions();
		$active_subscriptions    = $this->query_active_subscriptions();
		$cancelled_subscriptions = $this->query_cancelled_subscriptions();
		$history_transactions    = $this->query_history_transactions();
		$history_total           = $this->count_history_transactions();
		$history_years           = $this->reporting->donor_transaction_years( $donor->id, $this->is_test );
		$history_campaigns       = $this->reporting->donor_transaction_campaigns( $donor->id, $this->is_test );

		// Batch-load all campaigns (avoids N+1 queries).
		$this->preload_campaigns(
			$recent_transactions,
			$active_subscriptions,
			$cancelled_subscriptions,
			$history_transactions
		);

		// ── Overview ──
		$total_donated     = $this->is_test ? $donor->test_total_donated : $donor->total_donated;
		$transaction_count = $this->is_test ? $donor->test_transaction_count : $donor->transaction_count;
		$average_donation  = $transaction_count > 0 ? (int) round( $total_donated / $transaction_count ) : 0;

		$overview_stats = $this->build_overview_stats( $total_donated, $transaction_count, $average_donation );

		// ── Prepare data ──
		$prepared_transactions        = array_map( [ $this, 'prepare_overview_transaction' ], $recent_transactions );
		$prepared_subscriptions       = array_map( [ $this, 'prepare_overview_subscription' ], $active_subscriptions );
		$prepared_active_recurring    = array_map( [ $this, 'prepare_active_recurring' ], $active_subscriptions );
		$prepared_cancelled_recurring = array_map( [ $this, 'prepare_cancelled_recurring' ], $cancelled_subscriptions );
		$prepared_history             = array_map( [ $this, 'prepare_history_transaction' ], $history_transactions );
		$prepared_receipts            = $this->prepare_receipts();

		// ── Profile ──
		$initials    = $this->compute_initials();
		$preferences = $this->load_preferences();
		$payment     = $this->load_payment_method( $active_subscriptions );

		$history_per_page = self::HISTORY_PER_PAGE;

		$context = [
			'activePanel'          => 'overview',
			'sidebarOpen'          => false,
			'donor'                => [
				'firstName' => $donor->first_name,
				'lastName'  => $donor->last_name,
				'email'     => $donor->email,
				'initials'  => $initials,
			],
			'profile'              => [
				'firstName'          => $donor->first_name,
				'lastName'           => $donor->last_name,
				'email'              => $donor->email,
				'phone'              => $donor->phone,
				'address1'           => $donor->address_1,
				'city'               => $donor->city,
				'state'              => $donor->state,
				'zip'                => $donor->zip,
				'saving'             => false,
				'saved'              => false,
				'validated'          => false,
				'error'              => '',
				'pendingEmail'       => $this->get_pending_email(),
				'emailChangeEditing' => false,
				'newEmail'           => '',
				'emailChangeError'   => '',
				'emailChangeSending' => false,
				'preferences'        => [
					'emailReceipts'        => $preferences['email_receipts'],
					'emailCampaignUpdates' => $preferences['email_campaign_updates'],
					'emailAnnualReminder'  => $preferences['email_annual_reminder'],
				],
				'prefSaving'         => '',
				'prefError'          => '',
				'paymentMethod'      => $payment,
				'hasPaymentMethod'   => null !== $payment,
				'deleteLoading'      => false,
				'deleteError'        => '',
			],
			'siteName'             => ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ),
			'overview'             => [
				'stats'               => $overview_stats,
				'recentTransactions'  => $prepared_transactions,
				'activeSubscriptions' => $prepared_subscriptions,
				'hasTransactions'     => $transaction_count > 0,
				'hasSubscriptions'    => count( $active_subscriptions ) > 0,
			],
			'recurring'            => [
				'activeSubscriptions'    => $prepared_active_recurring,
				'cancelledSubscriptions' => $prepared_cancelled_recurring,
				'currencySymbol'         => Currency::get_symbol( $this->currency ),
				'hasActive'              => count( $active_subscriptions ) > 0,
				'hasCancelled'           => count( $cancelled_subscriptions ) > 0,
				'hasAny'                 => count( $active_subscriptions ) + count( $cancelled_subscriptions ) > 0,
			],
			'history'              => [
				'transactions'   => $prepared_history,
				'total'          => $history_total,
				'totalPages'     => $history_per_page > 0 ? (int) ceil( $history_total / $history_per_page ) : 0,
				'page'           => 1,
				'perPage'        => $history_per_page,
				'filterYear'     => '',
				'filterCampaign' => '',
				'filterType'     => '',
				'years'          => $history_years,
				'campaigns'      => $history_campaigns,
				'loading'        => false,
			],
			'receipts'             => [
				'years'   => $prepared_receipts,
				'hasAny'  => count( $prepared_receipts ) > 0,
				'orgName' => ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ),
			],
			'restUrl'              => rest_url( 'mission/v1/' ),
			'nonce'                => wp_create_nonce( 'wp_rest' ),
			'dashboardUrl'         => get_permalink(),
			'siteHomeUrl'          => home_url( '/' ),
			'stripePublishableKey' => ! empty( $this->settings['test_mode'] ) ? MISSION_STRIPE_PK_TEST : MISSION_STRIPE_PK_LIVE,
			'validPanels'          => array_values( array_keys( $panels ) ),
			'panelLabels'          => $panel_labels,
			'toast'                => [
				'visible'    => false,
				'message'    => '',
				'type'       => 'success',
				'dismissing' => false,
			],
		];

		$state = [
			'isOverview'             => true,
			'isHistory'              => false,
			'isRecurring'            => false,
			'isReceipts'             => false,
			'isProfile'              => false,
			'panelTitle'             => __( 'Overview', 'mission' ),
			'historyIsEmpty'         => 0 === $history_total,
			'historyHasOnePage'      => $history_total <= $history_per_page,
			'historyIsFirstPage'     => true,
			'historyIsLastPage'      => $history_total <= $history_per_page,
			'historyPaginationLabel' => sprintf(
				/* translators: 1: current page, 2: total pages */
				__( 'Page %1$d of %2$d', 'mission' ),
				1,
				$history_per_page > 0 ? (int) ceil( $history_total / $history_per_page ) : 1
			),
		];

		return compact( 'context', 'state' );
	}

	// ── Query helpers ──

	/**
	 * @return Transaction[]
	 */
	private function query_recent_transactions(): array {
		return $this->donor->transactions(
			[
				'per_page' => 5,
				'status'   => 'completed',
				'is_test'  => $this->is_test,
				'orderby'  => 'date_completed',
				'order'    => 'DESC',
			]
		);
	}

	/**
	 * @return Subscription[]
	 */
	private function query_active_subscriptions(): array {
		return $this->donor->subscriptions(
			[
				'status__in' => [ 'active', 'paused' ],
				'is_test'    => $this->is_test,
			]
		);
	}

	/**
	 * @return Subscription[]
	 */
	private function query_cancelled_subscriptions(): array {
		return $this->donor->subscriptions(
			[
				'status'  => 'cancelled',
				'is_test' => $this->is_test,
				'orderby' => 'date_cancelled',
				'order'   => 'DESC',
			]
		);
	}

	/**
	 * @return Transaction[]
	 */
	private function query_history_transactions(): array {
		return Transaction::query(
			[
				'donor_id' => $this->donor->id,
				'is_test'  => $this->is_test,
				'per_page' => self::HISTORY_PER_PAGE,
				'page'     => 1,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			]
		);
	}

	private function count_history_transactions(): int {
		return Transaction::count(
			[
				'donor_id' => $this->donor->id,
				'is_test'  => $this->is_test,
			]
		);
	}

	// ── Campaign batch loading ──

	/**
	 * Batch-load all campaigns referenced by the given model arrays.
	 *
	 * @param array ...$model_arrays Arrays of Transaction or Subscription models.
	 */
	private function preload_campaigns( array ...$model_arrays ): void {
		$ids = [];

		foreach ( $model_arrays as $models ) {
			foreach ( $models as $model ) {
				$ids[] = $model->campaign_id;
			}
		}

		$ids = array_unique( array_filter( $ids ) );

		if ( ! empty( $ids ) ) {
			$this->campaign_map = Campaign::find_many( $ids );
		}
	}

	private function resolve_campaign( ?int $id ): ?Campaign {
		return $id ? ( $this->campaign_map[ $id ] ?? null ) : null;
	}

	// ── Data preparation ──

	private function prepare_overview_transaction( Transaction $txn ): array {
		$campaign     = $this->resolve_campaign( $txn->campaign_id );
		$is_recurring = 'one_time' !== $txn->type;

		return [
			'id'              => $txn->id,
			'formattedAmount' => Currency::format_amount( $txn->amount, $this->currency ),
			'formattedDate'   => $txn->date_completed
				? date_i18n( 'M j, Y', strtotime( $txn->date_completed ) )
				: date_i18n( 'M j, Y', strtotime( $txn->date_created ) ),
			'campaignName'    => $campaign?->title ?? __( 'Deleted Campaign', 'mission' ),
			'status'          => $txn->status,
			'statusLabel'     => ucfirst( $txn->status ),
			'isRecurring'     => $is_recurring,
		];
	}

	private function prepare_overview_subscription( Subscription $sub ): array {
		$campaign = $this->resolve_campaign( $sub->campaign_id );

		return [
			'id'              => $sub->id,
			'formattedAmount' => Currency::format_amount( $sub->amount, $this->currency ),
			'frequencySuffix' => $this->frequency_suffix[ $sub->frequency ] ?? $sub->frequency,
			'campaignName'    => $campaign?->title ?? __( 'Deleted Campaign', 'mission' ),
			'nextPayment'     => $sub->date_next_renewal
				? date_i18n( 'M j, Y', strtotime( $sub->date_next_renewal ) )
				: '',
		];
	}

	private function prepare_active_recurring( Subscription $sub ): array {
		$campaign = $this->resolve_campaign( $sub->campaign_id );
		$pm_last4 = $sub->get_meta( 'payment_method_last4' );

		return [
			'id'                       => $sub->id,
			'status'                   => $sub->status,
			'amount'                   => $sub->amount,
			'tipAmount'                => $sub->tip_amount,
			'currency'                 => strtoupper( $sub->currency ),
			'formattedAmount'          => Currency::format_amount( $sub->amount, $this->currency ),
			'frequencySuffix'          => $this->frequency_suffix[ $sub->frequency ] ?? $sub->frequency,
			'frequencyLabel'           => $this->frequency_labels[ $sub->frequency ] ?? ucfirst( $sub->frequency ),
			'campaignName'             => $campaign?->title ?? __( 'Deleted Campaign', 'mission' ),
			'paymentsMade'             => (string) ( 1 + $sub->renewal_count ),
			'totalContributed'         => Currency::format_amount( $sub->total_amount + $sub->total_renewed, $this->currency ),
			'started'                  => date_i18n( 'M j, Y', strtotime( $sub->date_created ) ),
			'nextPayment'              => $sub->date_next_renewal
				? date_i18n( 'M j, Y', strtotime( $sub->date_next_renewal ) )
				: '',
			'paymentMethod'            => $pm_last4
				? ucfirst( $sub->get_meta( 'payment_method_brand' ) ?: 'Card' ) . ' ending in ' . $pm_last4
				: __( 'Card on file', 'mission' ),
			'cancelLoading'            => false,
			'pauseLoading'             => false,
			'actionError'              => '',
			'feeAmount'                => $sub->fee_amount,
			'feeModeFlat'              => $sub->get_meta( 'fee_mode' ) === 'flat',
			'stripeFeePercent'         => (float) ( $this->settings['stripe_fee_percent'] ?? 2.9 ),
			'stripeFeeFixed'           => (int) ( $this->settings['stripe_fee_fixed'] ?? 30 ),
			'changeAmountOpen'         => false,
			'changeAmountInput'        => '',
			'changeFeeRecoveryChecked' => false,
			'changeFeeDetailsOpen'     => false,
			'changeTipMenuOpen'        => false,
			'changeSelectedTipPercent' => 15,
			'changeIsCustomTip'        => false,
			'changeCustomTipAmount'    => 0,
			'changeLoading'            => false,
			'changeError'              => '',
			'updatePaymentOpen'        => false,
			'updatePaymentLoading'     => false,
			'updatePaymentReady'       => false,
			'updatePaymentError'       => '',
		];
	}

	private function prepare_cancelled_recurring( Subscription $sub ): array {
		$campaign    = $this->resolve_campaign( $sub->campaign_id );
		$start_month = date_i18n( 'M Y', strtotime( $sub->date_created ) );
		$end_month   = $sub->date_cancelled
			? date_i18n( 'M Y', strtotime( $sub->date_cancelled ) )
			: '';
		$period      = $end_month && $start_month !== $end_month
			? $start_month . ' – ' . $end_month
			: $start_month;

		return [
			'id'               => $sub->id,
			'formattedAmount'  => Currency::format_amount( $sub->amount, $this->currency ),
			'frequencySuffix'  => $this->frequency_suffix[ $sub->frequency ] ?? $sub->frequency,
			'frequencyLabel'   => $this->frequency_labels[ $sub->frequency ] ?? ucfirst( $sub->frequency ),
			'campaignName'     => $campaign?->title ?? __( 'Deleted Campaign', 'mission' ),
			'paymentsMade'     => (string) ( 1 + $sub->renewal_count ),
			'totalContributed' => Currency::format_amount( $sub->total_amount + $sub->total_renewed, $this->currency ),
			'period'           => $period,
			'cancelled'        => $sub->date_cancelled
				? date_i18n( 'M j, Y', strtotime( $sub->date_cancelled ) )
				: '',
		];
	}

	private function prepare_history_transaction( Transaction $txn ): array {
		$campaign     = $this->resolve_campaign( $txn->campaign_id );
		$is_recurring = 'one_time' !== $txn->type;

		return [
			'id'              => $txn->id,
			'formattedAmount' => Currency::format_amount( $txn->amount, $this->currency ),
			'formattedDate'   => $txn->date_completed
				? date_i18n( 'M j, Y', strtotime( $txn->date_completed ) )
				: date_i18n( 'M j, Y', strtotime( $txn->date_created ) ),
			'campaignName'    => $campaign?->title ?? __( 'Deleted Campaign', 'mission' ),
			'status'          => $txn->status,
			'statusLabel'     => ucfirst( $txn->status ),
			'isRecurring'     => $is_recurring,
			'typeLabel'       => $is_recurring ? __( 'Recurring', 'mission' ) : __( 'One-time', 'mission' ),
		];
	}

	private function prepare_receipts(): array {
		$receipt_years = $this->reporting->donor_annual_summary( $this->donor->id, $this->is_test );
		$current_year  = (int) gmdate( 'Y' );

		return array_map(
			fn( array $row ) => [
				'year'           => $row['year'],
				'formattedTotal' => Currency::format_amount( $row['total'], $this->currency ),
				'count'          => $row['count'],
				'isCurrentYear'  => $row['year'] === $current_year,
			],
			$receipt_years
		);
	}

	// ── Profile helpers ──

	/**
	 * Get the pending email change address, if any and not expired.
	 *
	 * @return string Pending email or empty string.
	 */
	private function get_pending_email(): string {
		$pending = $this->donor->get_meta( 'pending_email' );
		$expires = $this->donor->get_meta( 'pending_email_token_expires' );

		if ( ! $pending || ! $expires || strtotime( $expires ) < time() ) {
			return '';
		}

		return $pending;
	}

	private function compute_initials(): string {
		$initials = strtoupper(
			mb_substr( $this->donor->first_name, 0, 1 ) . mb_substr( $this->donor->last_name, 0, 1 )
		);

		return '' === trim( $initials ) ? '?' : $initials;
	}

	/**
	 * @return array<string, bool>
	 */
	private function load_preferences(): array {
		$preferences = [];

		foreach ( self::PREFERENCE_DEFAULTS as $key => $default ) {
			$value               = $this->donor->get_meta( $key );
			$preferences[ $key ] = ( '' === $value || false === $value ) ? $default : '1' === $value;
		}

		return $preferences;
	}

	/**
	 * @param Subscription[] $active_subscriptions Active subscriptions.
	 * @return array|null Payment method details or null.
	 */
	private function load_payment_method( array $active_subscriptions ): ?array {
		if ( empty( $active_subscriptions ) ) {
			return null;
		}

		$pm_sub   = $active_subscriptions[0];
		$pm_last4 = $pm_sub->get_meta( 'payment_method_last4' );

		if ( ! $pm_last4 ) {
			return null;
		}

		return [
			'brand'    => $pm_sub->get_meta( 'payment_method_brand' ) ?: 'card',
			'last4'    => $pm_last4,
			'expMonth' => $pm_sub->get_meta( 'payment_method_exp_month' ) ?: '',
			'expYear'  => $pm_sub->get_meta( 'payment_method_exp_year' ) ?: '',
		];
	}

	// ── Overview stats ──

	private function build_overview_stats( int $total_donated, int $transaction_count, int $average_donation ): array {
		$stats = [
			[
				'value' => (string) $transaction_count,
				'label' => _n( 'Donation Made', 'Donations Made', $transaction_count, 'mission' ),
			],
			[
				'value' => Currency::format_amount( $total_donated, $this->currency ),
				'label' => __( 'Lifetime Given', 'mission' ),
			],
			[
				'value' => Currency::format_amount( $average_donation, $this->currency ),
				'label' => __( 'Avg. Donation', 'mission' ),
			],
		];

		/**
		 * Filters the overview stats displayed on the donor dashboard.
		 *
		 * Each stat has 'value' (formatted string) and 'label' (display name).
		 *
		 * @param array $stats Stats array.
		 * @param Donor $donor The current donor.
		 */
		return apply_filters( 'mission_donor_dashboard_overview_stats', $stats, $this->donor );
	}
}
