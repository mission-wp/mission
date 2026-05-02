<?php
/**
 * Email module - handles email sending and notifications.
 *
 * @package MissionDP
 */

namespace MissionDP\Email;

use MissionDP\Currency\Currency;
use MissionDP\Models\Donor;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Email module class.
 */
class EmailModule {

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

		// Check for custom body in settings.
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

			// Donation / transaction.
			'{amount}'             => $data['amount_formatted'] ?? '',
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

		$from_name  = apply_filters( 'missiondp_email_from_name', $from_name );
		$from_email = apply_filters( 'missiondp_email_from_email', $from_email );

		$reply_to = $this->settings->get( 'email_reply_to', '' );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		];

		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return apply_filters( 'missiondp_email_headers', $headers );
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

		$subject = apply_filters( 'missiondp_email_subject', $subject, $to );
		$message = apply_filters( 'missiondp_email_message', $message, $to );

		$result = wp_mail( $to, $subject, $message, $headers );

		if ( $result ) {
			/**
			 * Fires after an email is sent successfully.
			 *
			 * @param string $to      Recipient email address.
			 * @param string $subject Email subject.
			 */
			do_action( 'missiondp_email_sent', $to, $subject );
		} else {
			/**
			 * Fires when an email fails to send.
			 *
			 * @param string $to      Recipient email address.
			 * @param string $subject Email subject.
			 */
			do_action( 'missiondp_email_failed', $to, $subject );
		}

		return $result;
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
