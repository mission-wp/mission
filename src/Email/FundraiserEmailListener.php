<?php
/**
 * Listens for peer-to-peer fundraiser events and emails the participant.
 *
 * Sends the "your page is live" email when a pending fundraiser is approved and
 * a "you received a donation" email when a gift is credited to a fundraiser.
 *
 * @package MissionDP
 */

namespace MissionDP\Email;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Fundraiser email listener class.
 */
class FundraiserEmailListener {

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

		add_action( 'mission_fundraiser_approved', [ $this, 'on_fundraiser_approved' ] );
		add_action( 'mission_transaction_status_pending_to_completed', [ $this, 'on_donation_completed' ] );
		add_action( 'mission_transaction_created', [ $this, 'on_transaction_created' ] );
		add_action( 'mission_fundraiser_milestone_reached', [ $this, 'on_fundraiser_milestone' ], 10, 3 );
	}

	/**
	 * Percentage labels for fundraiser milestone IDs.
	 *
	 * @return array<string, string>
	 */
	private function milestone_labels(): array {
		return [
			'25-pct'  => '25%',
			'50-pct'  => '50%',
			'75-pct'  => '75%',
			'100-pct' => '100%',
		];
	}

	/**
	 * Congratulate the participant when they reach a goal milestone.
	 *
	 * @param Fundraiser $fundraiser   The fundraiser.
	 * @param string     $milestone_id Milestone ID (e.g. '50-pct').
	 * @param bool       $is_test      Whether the triggering donation is a test.
	 * @return void
	 */
	public function on_fundraiser_milestone( Fundraiser $fundraiser, string $milestone_id, bool $is_test = false ): void {
		if ( $is_test ) {
			return;
		}

		$label = $this->milestone_labels()[ $milestone_id ] ?? '';
		if ( '' === $label ) {
			return;
		}

		$donor = $fundraiser->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'p2p_fundraiser_milestone' ) ) {
			return;
		}

		$org_name         = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );
		$campaign         = $fundraiser->campaign();
		$currency         = $campaign?->currency ?: 'USD';
		$raised_formatted = $this->email->format_amount( $fundraiser->amount_raised(), $currency );
		$goal_formatted   = $fundraiser->goal > 0 ? $this->email->format_amount( $fundraiser->goal, $currency ) : '';

		$data = [
			'fundraiser'       => $fundraiser,
			'donor'            => $donor,
			'organization'     => $org_name,
			'milestone_label'  => $label,
			'raised_formatted' => $raised_formatted,
			'goal_formatted'   => $goal_formatted,
			'page_url'         => $fundraiser->get_url(),
		];

		$subject = sprintf(
			/* translators: %s: milestone percentage (e.g. "50%") */
			__( "You've reached %s of your goal!", 'mission-donation-platform' ),
			$label,
		);

		$custom_subject = $this->email->get_custom_subject( 'p2p_fundraiser_milestone' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission-donation-platform' ),
					'{milestone}'    => $label,
					'{organization}' => $org_name,
				]
			);
		}

		$html = $this->email->render_template( 'p2p-fundraiser-milestone', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Email the participant when their fundraiser is approved.
	 *
	 * Fires only on the pending -> active transition; an auto-approved fundraiser
	 * already saw the success screen at signup.
	 *
	 * @param Fundraiser $fundraiser The approved fundraiser.
	 * @return void
	 */
	public function on_fundraiser_approved( Fundraiser $fundraiser ): void {
		$donor = $fundraiser->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'p2p_fundraiser_approved' ) ) {
			return;
		}

		$org_name = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );

		$data = [
			'fundraiser'   => $fundraiser,
			'donor'        => $donor,
			'organization' => $org_name,
			'page_url'     => $fundraiser->get_url(),
		];

		$subject = __( 'Your fundraising page is live', 'mission-donation-platform' );

		$custom_subject = $this->email->get_custom_subject( 'p2p_fundraiser_approved' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission-donation-platform' ),
					'{organization}' => $org_name,
				]
			);
		}

		$html = $this->email->render_template( 'p2p-fundraiser-approved', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Handle directly-completed transactions (e.g. manual donations).
	 *
	 * @param Transaction $transaction The transaction.
	 * @return void
	 */
	public function on_transaction_created( Transaction $transaction ): void {
		if ( Transaction::STATUS_COMPLETED === $transaction->status ) {
			$this->on_donation_completed( $transaction );
		}
	}

	/**
	 * Email the participant when a gift is credited to their fundraiser.
	 *
	 * @param Transaction $transaction The completed transaction.
	 * @return void
	 */
	public function on_donation_completed( Transaction $transaction ): void {
		if ( ! $transaction->fundraiser_id ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'p2p_fundraiser_received_donation' ) ) {
			return;
		}

		$fundraiser = Fundraiser::find( $transaction->fundraiser_id );
		$owner      = $fundraiser?->donor();
		if ( ! $owner?->email ) {
			return;
		}

		$giver      = $transaction->donor();
		$giver_name = ( $giver && ! $transaction->is_anonymous )
			? ( trim( $giver->first_name . ' ' . $giver->last_name ) ?: __( 'Someone', 'mission-donation-platform' ) )
			: __( 'Someone', 'mission-donation-platform' );

		$org_name         = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );
		$amount_formatted = $this->email->format_amount( $transaction->amount, $transaction->currency );

		$data = [
			'fundraiser'       => $fundraiser,
			'donor'            => $owner,
			'giver_name'       => $giver_name,
			'amount_formatted' => $amount_formatted,
			'organization'     => $org_name,
			'page_url'         => $fundraiser->get_url(),
		];

		$subject = sprintf(
			/* translators: %s: formatted amount */
			__( 'You received a %s donation!', 'mission-donation-platform' ),
			$amount_formatted,
		);

		$custom_subject = $this->email->get_custom_subject( 'p2p_fundraiser_received_donation' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $owner->first_name ?: __( 'Friend', 'mission-donation-platform' ),
					'{giver_name}'   => $giver_name,
					'{amount}'       => $amount_formatted,
					'{organization}' => $org_name,
				]
			);
		}

		$html = $this->email->render_template( 'p2p-fundraiser-received-donation', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $owner->email, $subject, $html );
	}
}
