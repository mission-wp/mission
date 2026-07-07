<?php
/**
 * Email module - handles email sending and notifications.
 *
 * @package MissionDP
 */

namespace MissionDP\Email;

use MissionDP\Currency\Currency;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Email module class.
 */
class EmailModule {

	/**
	 * Template file name per editable email type (hyphenated).
	 *
	 * The single source for the admin editor preview and test-send endpoints.
	 * The OTP email (p2p_otp_code) is intentionally absent: it has no editor UI.
	 *
	 * @var array<string, string>
	 */
	public const TEMPLATE_MAP = [
		'donation_receipt'                 => 'donation-receipt',
		'subscription_activated'           => 'subscription-activated',
		'renewal_receipt'                  => 'renewal-receipt',
		'payment_failed'                   => 'payment-failed',
		'subscription_cancelled'           => 'subscription-cancelled',
		'account_activation'               => 'account-activation',
		'password_reset'                   => 'password-reset',
		'email_change_verification'        => 'email-change-verification',
		'donor_note'                       => 'donor-note',
		'tribute_notification'             => 'tribute-notification',
		'p2p_fundraiser_approved'          => 'p2p-fundraiser-approved',
		'p2p_fundraiser_received_donation' => 'p2p-fundraiser-received-donation',
		'p2p_fundraiser_milestone'         => 'p2p-fundraiser-milestone',
		'p2p_team_invitation'              => 'p2p-team-invitation',
		'p2p_team_member_joined'           => 'p2p-team-member-joined',
		'p2p_team_approved'                => 'p2p-team-approved',
	];

	/**
	 * Template directory path.
	 *
	 * @var string
	 */
	private string $template_dir;

	/**
	 * Settings service instance.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Initialize the email module.
	 *
	 * @param SettingsService|null $settings Optional settings service.
	 * @return void
	 */
	public function init( ?SettingsService $settings = null ): void {
		$this->template_dir = plugin_dir_path( __FILE__ ) . 'templates/';
		$this->settings     = $settings ?? new SettingsService();
	}

	/**
	 * Check if a specific email type is enabled.
	 *
	 * @param string $email_type Email type key (e.g. 'donation_receipt').
	 * @return bool
	 */
	public function is_email_enabled( string $email_type ): bool {
		$emails = $this->settings->get( 'emails', [] );

		return $emails[ $email_type ]['enabled'] ?? true;
	}

	/**
	 * Get the custom subject for an email type, or empty string if using default.
	 *
	 * @param string $email_type Email type key.
	 * @return string Custom subject or empty string.
	 */
	public function get_custom_subject( string $email_type ): string {
		$emails = $this->settings->get( 'emails', [] );

		return $emails[ $email_type ]['subject'] ?? '';
	}

	/**
	 * Replace merge tags in a subject line.
	 *
	 * @param string              $subject Subject with {tag} placeholders.
	 * @param array<string,string> $tags    Map of '{tag}' => 'replacement'.
	 * @return string
	 */
	public function replace_subject_tags( string $subject, array $tags ): string {
		return strtr( $subject, $tags );
	}

	/**
	 * Default subject per email type, with merge tags as literal placeholders.
	 *
	 * The single source for both the send path and the admin editor preview.
	 * Wrapped in a method instead of a const so strings can be translated.
	 *
	 * @param string $email_type Email type key.
	 * @return string Default subject, or empty string for an unknown type.
	 */
	public function default_subject( string $email_type ): string {
		$subjects = [
			'donation_receipt'                 => __( 'Thank you for your {amount} donation', 'mission-donation-platform' ),
			'subscription_activated'           => __( 'Thank you for your {amount} {frequency} donation', 'mission-donation-platform' ),
			'renewal_receipt'                  => __( 'Thank you for your {frequency} gift of {amount}', 'mission-donation-platform' ),
			'payment_failed'                   => __( 'Action needed: Update your payment for your recurring donation', 'mission-donation-platform' ),
			'subscription_cancelled'           => __( 'Your recurring donation has ended', 'mission-donation-platform' ),
			'account_activation'               => __( 'Verify your email to activate your donor account', 'mission-donation-platform' ),
			'password_reset'                   => __( 'Reset your password', 'mission-donation-platform' ),
			'email_change_verification'        => __( 'Verify your new email address', 'mission-donation-platform' ),
			'donor_note'                       => __( 'A note about your donation', 'mission-donation-platform' ),
			'tribute_notification'             => __( 'A donation has been made {tribute_type_label} {honoree_name}', 'mission-donation-platform' ),
			'p2p_otp_code'                     => __( 'Your verification code', 'mission-donation-platform' ),
			'p2p_fundraiser_approved'          => __( 'Your fundraising page is live', 'mission-donation-platform' ),
			'p2p_fundraiser_received_donation' => __( 'You received a {amount} donation!', 'mission-donation-platform' ),
			'p2p_fundraiser_milestone'         => __( "You've reached {milestone} of your goal!", 'mission-donation-platform' ),
			'p2p_team_invitation'              => __( "You're invited to join {team_name}", 'mission-donation-platform' ),
			'p2p_team_member_joined'           => __( 'A new member joined {team_name}', 'mission-donation-platform' ),
			'p2p_team_approved'                => __( 'Your team {team_name} has been approved', 'mission-donation-platform' ),
		];

		return $subjects[ $email_type ] ?? '';
	}

	/**
	 * Resolve the subject for an email type: custom if set, default otherwise,
	 * with merge tags replaced either way.
	 *
	 * @param string               $email_type Email type key.
	 * @param array<string,string> $tags       Map of '{tag}' => 'replacement'.
	 * @return string
	 */
	public function subject( string $email_type, array $tags = [] ): string {
		$subject = $this->get_custom_subject( $email_type ) ?: $this->default_subject( $email_type );

		return $this->replace_subject_tags( $subject, $tags );
	}

	/**
	 * Render a partial template.
	 *
	 * @param string $partial Partial name (e.g., 'header', 'footer').
	 * @param array  $data Template data.
	 * @return string Rendered HTML.
	 */
	public function render_partial( string $partial, array $data = [] ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is available to the included template.
		$partial_file = $this->template_dir . 'partials/' . $partial . '.php';

		if ( ! file_exists( $partial_file ) ) {
			return '';
		}

		ob_start();
		include $partial_file;
		return ob_get_clean();
	}

	/**
	 * Render a full email template (header + body + footer).
	 *
	 * Checks for a custom body in settings before falling back to the PHP template file.
	 *
	 * @param string $template Template name (without .php extension, e.g. 'donation-receipt').
	 * @param array  $data     Template data.
	 * @return string Rendered HTML email.
	 */
	public function render_template( string $template, array $data = [] ): string {
		$header_data = array_merge( $data, [ 'subject' => $data['subject'] ?? '' ] );
		$header      = $this->render_partial( 'header', $header_data );
		$footer      = $this->render_partial( 'footer', $data );

		$settings_key = str_replace( '-', '_', $template );
		$custom_body  = $this->get_custom_body( $settings_key );

		if ( $custom_body ) {
			$tags = $this->build_merge_tags( $data );
			$body = strtr( $custom_body, $tags );
		} else {
			$template_file = $this->template_dir . $template . '.php';

			if ( ! file_exists( $template_file ) ) {
				return '';
			}

			ob_start();
			include $template_file;
			$body = ob_get_clean();
		}

		return $header . $body . $footer;
	}

	/**
	 * Render just the body of a template (no header/footer), for the template editor API.
	 *
	 * @param string $template Template name (without .php extension).
	 * @param array  $data     Template data.
	 * @return string Rendered body HTML.
	 */
	public function render_template_body( string $template, array $data = [] ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is available to the included template.
		$template_file = $this->template_dir . $template . '.php';

		if ( ! file_exists( $template_file ) ) {
			return '';
		}

		ob_start();
		include $template_file;
		return ob_get_clean();
	}

	/**
	 * Get the custom body HTML for an email type, or empty string if using default.
	 *
	 * @param string $email_type Email type key (underscore format).
	 * @return string Custom body HTML or empty string.
	 */
	public function get_custom_body( string $email_type ): string {
		$emails = $this->settings->get( 'emails', [] );

		return $emails[ $email_type ]['body'] ?? '';
	}

	/**
	 * Build a merge tag replacement map from template data.
	 *
	 * Builds all possible tags — unused ones for a given email type simply won't match.
	 *
	 * @param array $data Template data array as passed by listeners.
	 * @return array<string,string> Map of '{tag}' => replacement value.
	 */
	public function build_merge_tags( array $data ): array {
		$donor = $data['donor'] ?? null;

		return [
			// Universal.
			'{donor_name}'         => $donor->first_name ?? __( 'Friend', 'mission-donation-platform' ),
			'{organization}'       => $this->settings->get( 'org_name', get_bloginfo( 'name' ) ),
			'{site_url}'           => home_url(),

			// Donation / transaction. Milestone emails pass the raised total.
			'{amount}'             => $data['amount_formatted'] ?? $data['raised_formatted'] ?? '',
			'{date}'               => $data['date_formatted'] ?? '',
			'{campaign}'           => $data['campaign_name'] ?? '',
			'{receipt_id}'         => isset( $data['transaction'] ) ? (string) $data['transaction']->id : '',

			// Subscription.
			'{frequency}'          => $data['frequency_label'] ?? '',
			'{next_renewal_date}'  => $data['next_renewal_formatted'] ?? '',

			// Account / auth.
			'{verification_url}'   => $data['verification_url'] ?? '',
			'{reset_url}'          => $data['reset_url'] ?? '',
			'{new_email}'          => $data['new_email'] ?? '',
			'{expiry_hours}'       => isset( $data['expiry_hours'] ) ? (string) $data['expiry_hours'] : '',

			// Donor dashboard.
			'{donor_dashboard}'    => $this->get_dashboard_url(),

			// Notes.
			'{note_content}'       => isset( $data['note'] ) ? $data['note']->content : '',

			// Tributes.
			'{tribute_type_label}' => $data['tribute_type_label'] ?? '',
			'{honoree_name}'       => $data['honoree_name'] ?? '',
			'{message}'            => $data['message'] ?? '',

			// Peer-to-peer. The donor in team emails is the captain.
			'{page_url}'           => $data['page_url'] ?? '',
			'{giver_name}'         => $data['giver_name'] ?? '',
			'{milestone}'          => $data['milestone_label'] ?? '',
			'{goal}'               => $data['goal_formatted'] ?? '',
			'{team_name}'          => isset( $data['team'] ) ? (string) $data['team']->name : '',
			'{member_name}'        => $data['member_name'] ?? '',
			'{captain_name}'       => $donor->first_name ?? '',
			'{accept_url}'         => $data['accept_url'] ?? '',
		];
	}

	/**
	 * Get default email headers.
	 *
	 * @return array
	 */
	private function get_default_headers(): array {
		$from_name  = $this->settings->get( 'email_from_name', get_bloginfo( 'name' ) );
		$from_email = $this->settings->get( 'email_from_address', get_bloginfo( 'admin_email' ) );

		$from_name  = apply_filters( 'mission_email_from_name', $from_name );
		$from_email = apply_filters( 'mission_email_from_email', $from_email );

		$reply_to = $this->settings->get( 'email_reply_to', '' );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		];

		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return apply_filters( 'mission_email_headers', $headers );
	}

	/**
	 * Send an email.
	 *
	 * @param string $to Email address.
	 * @param string $subject Email subject.
	 * @param string $message Email message (HTML).
	 * @param array  $headers Email headers (optional, will use defaults if not provided).
	 * @return bool
	 */
	public function send( string $to, string $subject, string $message, array $headers = [] ): bool {
		if ( empty( $headers ) ) {
			$headers = $this->get_default_headers();
		}

		$subject = apply_filters( 'mission_email_subject', $subject, $to );
		$message = apply_filters( 'mission_email_message', $message, $to );

		$result = wp_mail( $to, $subject, $message, $headers );

		if ( $result ) {
			/**
			 * Fires after an email is sent successfully.
			 *
			 * @param string $to      Recipient email address.
			 * @param string $subject Email subject.
			 */
			do_action( 'mission_email_sent', $to, $subject );
		} else {
			/**
			 * Fires when an email fails to send.
			 *
			 * @param string $to      Recipient email address.
			 * @param string $subject Email subject.
			 */
			do_action( 'mission_email_failed', $to, $subject );
		}

		return $result;
	}

	/**
	 * Build and send the donation receipt email for a transaction.
	 *
	 * Callers own the gating (email enabled, skip_receipt, donation type);
	 * this builds the subject and body and sends to the donor's email.
	 *
	 * @param Transaction $transaction The transaction.
	 * @param Donor       $donor       The donor (must have an email).
	 * @return bool Whether the email was sent.
	 */
	public function send_donation_receipt( Transaction $transaction, Donor $donor ): bool {
		$campaign = $transaction->campaign();

		$data = [
			'transaction'      => $transaction,
			'donor'            => $donor,
			'amount_formatted' => $this->format_amount( $transaction->amount, $transaction->currency ),
			'date_formatted'   => wp_date( get_option( 'date_format' ), strtotime( $transaction->date_completed ?: $transaction->date_created ) ),
			'campaign_name'    => $campaign?->title,
		];

		$subject = $this->subject(
			'donation_receipt',
			[
				'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'mission-donation-platform' ),
				'{amount}'       => $data['amount_formatted'],
				'{campaign}'     => $data['campaign_name'] ?? '',
				'{date}'         => $data['date_formatted'],
				'{organization}' => $this->settings->get( 'org_name', get_bloginfo( 'name' ) ),
				'{receipt_id}'   => (string) $transaction->id,
			]
		);

		$html = $this->render_template( 'donation-receipt', array_merge( $data, [ 'subject' => $subject ] ) );

		return $this->send( $donor->email, $subject, $html );
	}

	/**
	 * Format a donor greeting line.
	 *
	 * @param Donor $donor The donor.
	 * @return string e.g. "Hi Jane," or "Hi Friend,".
	 */
	public function format_donor_greeting( Donor $donor ): string {
		$first_name = $donor->first_name ?: __( 'Friend', 'mission-donation-platform' );

		return sprintf(
			/* translators: %s: donor first name */
			__( 'Hi %s,', 'mission-donation-platform' ),
			$first_name
		);
	}

	/**
	 * Format a minor-unit amount as a currency string.
	 *
	 * @param int    $amount   Amount in minor units.
	 * @param string $currency ISO 4217 currency code.
	 * @return string Formatted amount (e.g. "$50.00").
	 */
	public function format_amount( int $amount, string $currency ): string {
		return Currency::format_amount( $amount, strtoupper( $currency ) );
	}

	/**
	 * Get the donor dashboard URL.
	 *
	 * @return string Dashboard URL, or home URL as fallback.
	 */
	public function get_dashboard_url(): string {
		$page_id = (int) get_option( 'missiondp_dashboard_page_id', 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}
}
