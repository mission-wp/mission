<?php
/**
 * Listens for subscription lifecycle events and sends email notifications.
 *
 * @package MissionDP
 */

namespace MissionDP\Email;

use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription email listener class.
 */
class SubscriptionEmailListener {

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
	 * Email module instance.
	 *
	 * @var EmailModule
	 */
	private EmailModule $email;

	/**
	 * Initialize the listener with event hooks.
	 *
	 * @param EmailModule $email Email module.
	 * @return void
	 */
	public function init( EmailModule $email ): void {
		$this->email = $email;

		add_action( 'missiondp_subscription_status_pending_to_active', [ $this, 'on_subscription_activated' ] );
		add_action( 'missiondp_subscription_renewed', [ $this, 'on_subscription_renewed' ], 10, 2 );
		add_action( 'missiondp_subscription_payment_failed', [ $this, 'on_payment_failed' ] );
		add_action( 'missiondp_subscription_status_active_to_cancelled', [ $this, 'on_subscription_cancelled' ] );
	}

	/**
	 * Send activation email when subscription becomes active.
	 *
	 * @param Subscription $subscription The subscription.
	 * @return void
	 */
	public function on_subscription_activated( Subscription $subscription ): void {
		$donor = $subscription->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'subscription_activated' ) ) {
			return;
		}

		$data = $this->build_email_data( $subscription, $donor );

		$subject = sprintf(
			/* translators: 1: formatted amount, 2: frequency label (e.g. "monthly") */
			__( 'Thank you for your %1$s %2$s donation', 'mission-donation-platform' ),
			$data['amount_formatted'],
			strtolower( $data['frequency_label'] ),
		);

		$custom_subject = $this->email->get_custom_subject( 'subscription_activated' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags( $custom_subject, $this->build_subject_tags( $data, $donor ) );
		}

		$html = $this->email->render_template( 'subscription-activated', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Send renewal receipt when a subscription renews.
	 *
	 * @param Subscription $subscription The subscription.
	 * @param Transaction  $transaction  The renewal transaction.
	 * @return void
	 */
	public function on_subscription_renewed( Subscription $subscription, Transaction $transaction ): void {
		$donor = $subscription->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'renewal_receipt' ) ) {
			return;
		}

		$data                = $this->build_email_data( $subscription, $donor );
		$data['transaction'] = $transaction;

		$subject = sprintf(
			/* translators: 1: frequency label (e.g. "monthly"), 2: formatted amount */
			__( 'Thank you for your %1$s gift of %2$s', 'mission-donation-platform' ),
			strtolower( $data['frequency_label'] ),
			$data['amount_formatted'],
		);

		$custom_subject = $this->email->get_custom_subject( 'renewal_receipt' );
		if ( $custom_subject ) {
			$tags                 = $this->build_subject_tags( $data, $donor );
			$tags['{date}']       = wp_date( get_option( 'date_format' ), strtotime( $transaction->date_completed ) );
			$tags['{receipt_id}'] = (string) $transaction->id;
			$subject              = $this->email->replace_subject_tags( $custom_subject, $tags );
		}

		$html = $this->email->render_template( 'renewal-receipt', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Send failure notice when a renewal payment fails.
	 *
	 * @param Subscription $subscription The subscription.
	 * @return void
	 */
	public function on_payment_failed( Subscription $subscription ): void {
		$donor = $subscription->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'payment_failed' ) ) {
			return;
		}

		$data = $this->build_email_data( $subscription, $donor );

		$subject = __( 'Action needed: Update your payment for your recurring donation', 'mission-donation-platform' );

		$custom_subject = $this->email->get_custom_subject( 'payment_failed' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags( $custom_subject, $this->build_subject_tags( $data, $donor ) );
		}

		$html = $this->email->render_template( 'payment-failed', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Send cancellation notice when a subscription is cancelled.
	 *
	 * @param Subscription $subscription The subscription.
	 * @return void
	 */
	public function on_subscription_cancelled( Subscription $subscription ): void {
		$donor = $subscription->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'subscription_cancelled' ) ) {
			return;
		}

		$data = $this->build_email_data( $subscription, $donor );

		$subject = __( 'Your recurring donation has ended', 'mission-donation-platform' );

		$custom_subject = $this->email->get_custom_subject( 'subscription_cancelled' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags( $custom_subject, $this->build_subject_tags( $data, $donor ) );
		}

		$html = $this->email->render_template( 'subscription-cancelled', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Build merge tag map for custom subjects.
	 *
	 * @param array<string, mixed>  $data  Email template data.
	 * @param \MissionDP\Models\Donor $donor The donor.
	 * @return array<string, string>
	 */
	private function build_subject_tags( array $data, \MissionDP\Models\Donor $donor ): array {
		return [
			'{donor_name}'        => $donor->first_name ?: __( 'Friend', 'mission-donation-platform' ),
			'{amount}'            => $data['amount_formatted'],
			'{frequency}'         => $data['frequency_label'],
			'{next_renewal_date}' => $data['next_renewal_formatted'],
			'{organization}'      => ( new \MissionDP\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ),
		];
	}

	/**
	 * Build common email template data from a subscription.
	 *
	 * @param Subscription         $subscription The subscription.
	 * @param \MissionDP\Models\Donor $donor        The donor.
	 * @return array<string, mixed>
	 */
	private function build_email_data( Subscription $subscription, \MissionDP\Models\Donor $donor ): array {
		$next_renewal = $subscription->date_next_renewal
			? wp_date( get_option( 'date_format' ), strtotime( $subscription->date_next_renewal ) )
			: __( 'N/A', 'mission-donation-platform' );

		return [
			'subscription'           => $subscription,
			'donor'                  => $donor,
			'amount_formatted'       => $this->email->format_amount( $subscription->total_amount, $subscription->currency ),
			'frequency_label'        => self::FREQUENCY_LABELS[ $subscription->frequency ] ?? $subscription->frequency,
			'next_renewal_formatted' => $next_renewal,
			'dashboard_url'          => $this->email->get_dashboard_url(),
		];
	}
}
