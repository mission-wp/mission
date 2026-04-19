<?php
/**
 * Listens for plugin events and sends admin notification emails.
 *
 * @package Mission
 */

namespace Mission\Email;

use Mission\Models\Campaign;
use Mission\Models\Subscription;
use Mission\Models\Transaction;
use Mission\Models\Tribute;

defined( 'ABSPATH' ) || exit;

/**
 * Admin notification listener class.
 */
class AdminNotificationListener {

	/**
	 * Frequency labels for email content.
	 *
	 * @var array<string, string>
	 */
	private const FREQUENCY_LABELS = [
		'weekly'    => 'Weekly',
		'monthly'   => 'Monthly',
		'quarterly' => 'Quarterly',
		'annually'  => 'Annually',
	];

	/**
	 * Admin notifier service.
	 *
	 * @var AdminNotifier
	 */
	private AdminNotifier $notifier;

	/**
	 * Email module instance.
	 *
	 * @var EmailModule
	 */
	private EmailModule $email;

	/**
	 * Initialize the listener with event hooks.
	 *
	 * @param AdminNotifier $notifier Admin notifier service.
	 * @param EmailModule   $email    Email module.
	 * @return void
	 */
	public function init( AdminNotifier $notifier, EmailModule $email ): void {
		$this->notifier = $notifier;
		$this->email    = $email;

		// New donation (one-time).
		add_action( 'mission_transaction_status_pending_to_completed', [ $this, 'on_donation_completed' ] );
		add_action( 'mission_transaction_created', [ $this, 'on_transaction_created' ] );

		// New donation (first recurring).
		add_action( 'mission_subscription_status_pending_to_active', [ $this, 'on_first_recurring_donation' ] );

		// Recurring renewal.
		add_action( 'mission_subscription_renewed', [ $this, 'on_subscription_renewed' ], 10, 2 );

		// Refund processed.
		add_action( 'mission_transaction_refund_applied', [ $this, 'on_refund_applied' ], 10, 2 );

		// Failed payment.
		add_action( 'mission_subscription_payment_failed', [ $this, 'on_payment_failed' ] );

		// Subscription cancelled.
		add_action( 'mission_subscription_status_active_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_pending_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_paused_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
		add_action( 'mission_subscription_status_past_due_to_cancelled', [ $this, 'on_subscription_cancelled' ] );

		// Campaign milestone.
		add_action( 'mission_campaign_milestone_reached', [ $this, 'on_campaign_milestone' ], 10, 3 );

		// Mail dedication pending.
		add_action( 'mission_tribute_created', [ $this, 'on_mail_dedication' ] );
	}

	/**
	 * Handle directly-completed transactions (e.g. manual donations).
	 *
	 * @param Transaction $transaction The transaction.
	 * @return void
	 */
	public function on_transaction_created( Transaction $transaction ): void {
		if ( 'completed' === $transaction->status ) {
			$this->on_donation_completed( $transaction );
		}
	}

	/**
	 * Send admin notification when a one-time donation is completed.
	 *
	 * @param Transaction $transaction The transaction.
	 * @return void
	 */
	public function on_donation_completed( Transaction $transaction ): void {
		if ( 'one_time' !== $transaction->type ) {
			return;
		}

		$donor = $transaction->donor();
		if ( ! $donor ) {
			return;
		}

		$campaign         = $transaction->campaign();
		$amount_formatted = $this->email->format_amount( $transaction->amount, $transaction->currency );
		$donor_name       = trim( $donor->first_name . ' ' . $donor->last_name ) ?: $donor->email;

		$data = [
			'transaction'      => $transaction,
			'donor'            => $donor,
			'donor_name'       => $donor_name,
			'amount_formatted' => $amount_formatted,
			'date_formatted'   => wp_date( get_option( 'date_format' ), strtotime( $transaction->date_completed ?: $transaction->date_created ) ),
			'campaign_name'    => $campaign?->title,
			'donation_type'    => 'one_time',
			'admin_url'        => admin_url( 'admin.php?page=mission-transactions&transaction=' . $transaction->id ),
		];

		$subject = sprintf(
			/* translators: 1: formatted amount, 2: donor name */
			__( 'New donation: %1$s from %2$s', 'missionwp-donation-platform' ),
			$amount_formatted,
			$donor_name,
		);

		$this->notifier->notify( 'admin_new_donation', $subject, $data );
	}

	/**
	 * Send admin notification when a first recurring donation is activated.
	 *
	 * @param Subscription $subscription The subscription.
	 * @return void
	 */
	public function on_first_recurring_donation( Subscription $subscription ): void {
		$donor = $subscription->donor();
		if ( ! $donor ) {
			return;
		}

		$campaign         = $subscription->campaign();
		$amount_formatted = $this->email->format_amount( $subscription->total_amount, $subscription->currency );
		$donor_name       = trim( $donor->first_name . ' ' . $donor->last_name ) ?: $donor->email;
		$frequency_label  = self::FREQUENCY_LABELS[ $subscription->frequency ] ?? $subscription->frequency;

		$data = [
			'subscription'     => $subscription,
			'donor'            => $donor,
			'donor_name'       => $donor_name,
			'amount_formatted' => $amount_formatted,
			'date_formatted'   => wp_date( get_option( 'date_format' ) ),
			'campaign_name'    => $campaign?->title,
			'donation_type'    => 'recurring',
			'frequency_label'  => $frequency_label,
			'admin_url'        => admin_url( 'admin.php?page=mission-transactions&subscription=' . $subscription->id ),
		];

		$subject = sprintf(
			/* translators: 1: formatted amount, 2: frequency (e.g. "Monthly"), 3: donor name */
			__( 'New recurring donation: %1$s/%2$s from %3$s', 'missionwp-donation-platform' ),
			$amount_formatted,
			strtolower( $frequency_label ),
			$donor_name,
		);

		$this->notifier->notify( 'admin_new_donation', $subject, $data );
	}

	/**
	 * Send admin notification when a recurring donation renews.
	 *
	 * @param Subscription $subscription The subscription.
	 * @param Transaction  $transaction  The renewal transaction.
	 * @return void
	 */
	public function on_subscription_renewed( Subscription $subscription, Transaction $transaction ): void {
		$donor = $subscription->donor();
		if ( ! $donor ) {
			return;
		}

		$campaign         = $subscription->campaign();
		$amount_formatted = $this->email->format_amount( $transaction->amount, $transaction->currency );
		$donor_name       = trim( $donor->first_name . ' ' . $donor->last_name ) ?: $donor->email;
		$frequency_label  = self::FREQUENCY_LABELS[ $subscription->frequency ] ?? $subscription->frequency;

		$data = [
			'subscription'     => $subscription,
			'transaction'      => $transaction,
			'donor'            => $donor,
			'donor_name'       => $donor_name,
			'amount_formatted' => $amount_formatted,
			'date_formatted'   => wp_date( get_option( 'date_format' ), strtotime( $transaction->date_completed ?: $transaction->date_created ) ),
			'campaign_name'    => $campaign?->title,
			'frequency_label'  => $frequency_label,
			'admin_url'        => admin_url( 'admin.php?page=mission-transactions&transaction=' . $transaction->id ),
		];

		$subject = sprintf(
			/* translators: 1: formatted amount, 2: donor name */
			__( 'Recurring renewal: %1$s from %2$s', 'missionwp-donation-platform' ),
			$amount_formatted,
			$donor_name,
		);

		$this->notifier->notify( 'admin_recurring_renewal', $subject, $data );
	}

	/**
	 * Send admin notification when a refund is processed.
	 *
	 * @param Transaction $transaction  The transaction.
	 * @param int         $refund_delta Amount refunded in this event (minor units).
	 * @return void
	 */
	public function on_refund_applied( Transaction $transaction, int $refund_delta ): void {
		$donor = $transaction->donor();
		if ( ! $donor ) {
			return;
		}

		$campaign         = $transaction->campaign();
		$donor_name       = trim( $donor->first_name . ' ' . $donor->last_name ) ?: $donor->email;
		$refund_formatted = $this->email->format_amount( $refund_delta, $transaction->currency );
		$is_full_refund   = $transaction->amount_refunded >= $transaction->amount;

		$data = [
			'transaction'        => $transaction,
			'donor'              => $donor,
			'donor_name'         => $donor_name,
			'refund_formatted'   => $refund_formatted,
			'amount_formatted'   => $this->email->format_amount( $transaction->amount, $transaction->currency ),
			'refunded_formatted' => $this->email->format_amount( $transaction->amount_refunded, $transaction->currency ),
			'date_formatted'     => wp_date( get_option( 'date_format' ) ),
			'campaign_name'      => $campaign?->title,
			'is_full_refund'     => $is_full_refund,
			'admin_url'          => admin_url( 'admin.php?page=mission-transactions&transaction=' . $transaction->id ),
		];

		$subject = $is_full_refund
			? sprintf(
				/* translators: 1: formatted refund amount, 2: donor name */
				__( 'Full refund: %1$s to %2$s', 'missionwp-donation-platform' ),
				$refund_formatted,
				$donor_name,
			)
			: sprintf(
				/* translators: 1: formatted refund amount, 2: donor name */
				__( 'Partial refund: %1$s to %2$s', 'missionwp-donation-platform' ),
				$refund_formatted,
				$donor_name,
			);

		$this->notifier->notify( 'admin_refund', $subject, $data );
	}

	/**
	 * Send admin notification when a recurring payment fails.
	 *
	 * @param Subscription $subscription The subscription.
	 * @return void
	 */
	public function on_payment_failed( Subscription $subscription ): void {
		$donor = $subscription->donor();
		if ( ! $donor ) {
			return;
		}

		$campaign         = $subscription->campaign();
		$amount_formatted = $this->email->format_amount( $subscription->total_amount, $subscription->currency );
		$donor_name       = trim( $donor->first_name . ' ' . $donor->last_name ) ?: $donor->email;
		$frequency_label  = self::FREQUENCY_LABELS[ $subscription->frequency ] ?? $subscription->frequency;

		$data = [
			'subscription'     => $subscription,
			'donor'            => $donor,
			'donor_name'       => $donor_name,
			'amount_formatted' => $amount_formatted,
			'date_formatted'   => wp_date( get_option( 'date_format' ) ),
			'campaign_name'    => $campaign?->title,
			'frequency_label'  => $frequency_label,
			'admin_url'        => admin_url( 'admin.php?page=mission-transactions&subscription=' . $subscription->id ),
		];

		$subject = sprintf(
			/* translators: 1: formatted amount, 2: donor name */
			__( 'Failed payment: %1$s from %2$s', 'missionwp-donation-platform' ),
			$amount_formatted,
			$donor_name,
		);

		$this->notifier->notify( 'admin_payment_failed', $subject, $data );
	}

	/**
	 * Send admin notification when a subscription is cancelled.
	 *
	 * @param Subscription $subscription The subscription.
	 * @return void
	 */
	public function on_subscription_cancelled( Subscription $subscription ): void {
		$donor = $subscription->donor();
		if ( ! $donor ) {
			return;
		}

		$campaign         = $subscription->campaign();
		$amount_formatted = $this->email->format_amount( $subscription->total_amount, $subscription->currency );
		$donor_name       = trim( $donor->first_name . ' ' . $donor->last_name ) ?: $donor->email;
		$frequency_label  = self::FREQUENCY_LABELS[ $subscription->frequency ] ?? $subscription->frequency;

		$data = [
			'subscription'      => $subscription,
			'donor'             => $donor,
			'donor_name'        => $donor_name,
			'amount_formatted'  => $amount_formatted,
			'date_formatted'    => wp_date( get_option( 'date_format' ) ),
			'campaign_name'     => $campaign?->title,
			'frequency_label'   => $frequency_label,
			'renewal_count'     => $subscription->renewal_count,
			'total_renewed_fmt' => $this->email->format_amount( $subscription->total_renewed, $subscription->currency ),
			'admin_url'         => admin_url( 'admin.php?page=mission-transactions&subscription=' . $subscription->id ),
		];

		$subject = sprintf(
			/* translators: 1: formatted amount, 2: frequency (e.g. "monthly"), 3: donor name */
			__( 'Subscription cancelled: %1$s/%2$s from %3$s', 'missionwp-donation-platform' ),
			$amount_formatted,
			strtolower( $frequency_label ),
			$donor_name,
		);

		$this->notifier->notify( 'admin_subscription_cancelled', $subject, $data );
	}

	/**
	 * Milestone labels keyed by milestone ID.
	 *
	 * @var array<string, string>
	 */
	private const MILESTONE_LABELS = [
		'first-donation' => 'First donation',
		'25-pct'         => '25%',
		'50-pct'         => '50%',
		'75-pct'         => '75%',
		'100-pct'        => '100%',
	];

	/**
	 * Send admin notification when a campaign milestone is reached.
	 *
	 * @param Campaign $campaign     The campaign.
	 * @param string   $milestone_id Milestone ID (e.g. '25-pct', '100-pct', 'first-donation').
	 * @param bool     $is_test      Whether the triggering transaction is a test.
	 * @return void
	 */
	public function on_campaign_milestone( Campaign $campaign, string $milestone_id, bool $is_test ): void {
		// Skip the 'created' milestone — it fires on campaign creation, not a donation event.
		if ( 'created' === $milestone_id || $is_test ) {
			return;
		}

		$milestone_label  = self::MILESTONE_LABELS[ $milestone_id ] ?? $milestone_id;
		$goal_formatted   = $campaign->goal_amount > 0
			? $this->email->format_amount( $campaign->goal_amount, $campaign->currency )
			: '';
		$raised_formatted = $this->email->format_amount( $campaign->total_raised, $campaign->currency );

		$data = [
			'campaign'         => $campaign,
			'milestone_id'     => $milestone_id,
			'milestone_label'  => $milestone_label,
			'campaign_name'    => $campaign->title,
			'goal_formatted'   => $goal_formatted,
			'raised_formatted' => $raised_formatted,
			'date_formatted'   => wp_date( get_option( 'date_format' ) ),
			'admin_url'        => admin_url( 'admin.php?page=mission-campaigns&campaign=' . $campaign->id ),
		];

		$subject = sprintf(
			/* translators: 1: campaign name, 2: milestone label (e.g. "50%", "First donation") */
			__( 'Campaign milestone: %1$s reached %2$s', 'missionwp-donation-platform' ),
			$campaign->title,
			$milestone_label,
		);

		$this->notifier->notify( 'admin_milestone', $subject, $data );
	}

	/**
	 * Send admin notification when a donation includes a mail dedication.
	 *
	 * @param Tribute $tribute The tribute.
	 * @return void
	 */
	public function on_mail_dedication( Tribute $tribute ): void {
		if ( 'mail' !== $tribute->notify_method ) {
			return;
		}

		$transaction = $tribute->transaction();
		if ( ! $transaction ) {
			return;
		}

		$donor            = $transaction->donor();
		$donor_name       = $donor ? trim( $donor->first_name . ' ' . $donor->last_name ) : '';
		$donor_name       = $donor_name ?: ( $donor->email ?? __( 'Anonymous', 'missionwp-donation-platform' ) );
		$amount_formatted = $this->email->format_amount( $transaction->amount, $transaction->currency );
		$type_label       = 'in_memory' === $tribute->tribute_type
			? __( 'in memory of', 'missionwp-donation-platform' )
			: __( 'in honor of', 'missionwp-donation-platform' );

		$address_parts = array_filter(
			[
				$tribute->notify_address_1,
				implode( ', ', array_filter( [ $tribute->notify_city, $tribute->notify_state, $tribute->notify_zip ] ) ),
				$tribute->notify_country && 'US' !== $tribute->notify_country ? $tribute->notify_country : '',
			]
		);

		$data = [
			'tribute'            => $tribute,
			'transaction'        => $transaction,
			'donor'              => $donor,
			'donor_name'         => $donor_name,
			'amount_formatted'   => $amount_formatted,
			'honoree_name'       => $tribute->honoree_name,
			'tribute_type_label' => $type_label,
			'notify_name'        => $tribute->notify_name,
			'notify_address'     => implode( ', ', $address_parts ),
			'admin_url'          => admin_url( 'admin.php?page=mission-transactions&transaction=' . $transaction->id ),
		];

		$subject = sprintf(
			/* translators: 1: honoree name, 2: donor name */
			__( 'Mail dedication pending: %1$s (from %2$s)', 'missionwp-donation-platform' ),
			$tribute->honoree_name,
			$donor_name,
		);

		$this->notifier->notify( 'admin_mail_dedication', $subject, $data );
	}
}
