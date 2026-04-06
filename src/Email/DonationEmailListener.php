<?php
/**
 * Listens for one-time donation events and sends receipt emails.
 *
 * @package Mission
 */

namespace Mission\Email;

use Mission\Models\Note;
use Mission\Models\Transaction;
use Mission\Models\Tribute;

defined( 'ABSPATH' ) || exit;

/**
 * Donation email listener class.
 */
class DonationEmailListener {

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

		add_action( 'mission_transaction_status_pending_to_completed', [ $this, 'on_donation_completed' ] );
		add_action( 'mission_transaction_created', [ $this, 'on_transaction_created' ] );
		add_action( 'mission_note_created', [ $this, 'on_donor_note_created' ] );
		add_action( 'mission_tribute_created', [ $this, 'on_tribute_saved' ] );
		add_action( 'mission_tribute_updated', [ $this, 'on_tribute_saved' ] );
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
	 * Send a receipt email when a one-time donation is completed.
	 *
	 * @param Transaction $transaction The transaction.
	 * @return void
	 */
	public function on_donation_completed( Transaction $transaction ): void {
		if ( 'one_time' !== $transaction->type ) {
			return;
		}

		$donor = $transaction->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'donation_receipt' ) ) {
			return;
		}

		if ( $transaction->get_meta( 'skip_receipt' ) ) {
			return;
		}

		$campaign = $transaction->campaign();

		$data = [
			'transaction'      => $transaction,
			'donor'            => $donor,
			'amount_formatted' => $this->email->format_amount( $transaction->amount, $transaction->currency ),
			'date_formatted'   => wp_date( get_option( 'date_format' ), strtotime( $transaction->date_completed ) ),
			'campaign_name'    => $campaign?->title,
		];

		$subject = sprintf(
			/* translators: %s: formatted donation amount */
			__( 'Thank you for your %s donation', 'mission' ),
			$data['amount_formatted'],
		);

		$custom_subject = $this->email->get_custom_subject( 'donation_receipt' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission' ),
					'{amount}'       => $data['amount_formatted'],
					'{campaign}'     => $data['campaign_name'] ?? '',
					'{date}'         => $data['date_formatted'],
					'{organization}' => ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ),
					'{receipt_id}'   => (string) $transaction->id,
				]
			);
		}

		$html = $this->email->render_template( 'donation-receipt', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Send an email to the donor when a donor-visible note is added to their transaction.
	 *
	 * @param Note $note The note.
	 * @return void
	 */
	public function on_donor_note_created( Note $note ): void {
		if ( 'transaction' !== $note->object_type || 'donor' !== $note->type ) {
			return;
		}

		$transaction = Transaction::find( $note->object_id );
		if ( ! $transaction ) {
			return;
		}

		$donor = $transaction->donor();
		if ( ! $donor?->email ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'donor_note' ) ) {
			return;
		}

		$org_name = ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );

		$data = [
			'note'             => $note,
			'transaction'      => $transaction,
			'donor'            => $donor,
			'organization'     => $org_name,
			'amount_formatted' => $this->email->format_amount( $transaction->amount, $transaction->currency ),
		];

		$subject = __( 'A note about your donation', 'mission' );

		$custom_subject = $this->email->get_custom_subject( 'donor_note' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission' ),
					'{organization}' => $org_name,
					'{note_content}' => $note->content,
					'{amount}'       => $data['amount_formatted'],
					'{receipt_id}'   => (string) $transaction->id,
				]
			);
		}

		$html = $this->email->render_template( 'donor-note', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $donor->email, $subject, $html );
	}

	/**
	 * Send a tribute notification email when a tribute with email notification is saved.
	 *
	 * Fires on both create and update — skips if a notification was already sent.
	 *
	 * @param Tribute $tribute The tribute.
	 * @return void
	 */
	public function on_tribute_saved( Tribute $tribute ): void {
		if ( 'email' !== $tribute->notify_method || ! $tribute->notify_email ) {
			return;
		}

		if ( $tribute->notification_sent_at ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'tribute_notification' ) ) {
			return;
		}

		$transaction = $tribute->transaction();
		$donor       = $transaction?->donor();
		$org_name    = ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );

		$type_label = 'in_memory' === $tribute->tribute_type
			? __( 'in memory of', 'mission' )
			: __( 'in honor of', 'mission' );

		$data = [
			'tribute'            => $tribute,
			'transaction'        => $transaction,
			'donor'              => $donor,
			'organization'       => $org_name,
			'tribute_type_label' => $type_label,
			'honoree_name'       => $tribute->honoree_name,
			'message'            => $tribute->message,
		];

		$subject = sprintf(
			/* translators: 1: tribute type label (e.g. "in honor of"), 2: honoree name */
			__( 'A donation has been made %1$s %2$s', 'mission' ),
			$type_label,
			$tribute->honoree_name,
		);

		$custom_subject = $this->email->get_custom_subject( 'tribute_notification' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'         => $donor?->first_name ?: __( 'Someone', 'mission' ),
					'{organization}'       => $org_name,
					'{tribute_type_label}' => $type_label,
					'{honoree_name}'       => $tribute->honoree_name,
					'{message}'            => $tribute->message,
				]
			);
		}

		$html = $this->email->render_template( 'tribute-notification', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $tribute->notify_email, $subject, $html );

		// Mark notification as sent.
		$tribute->notification_sent_at = current_time( 'mysql', true );
		$tribute->save();
	}
}
