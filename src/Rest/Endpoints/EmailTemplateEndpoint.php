<?php
/**
 * REST endpoint for retrieving default email template HTML.
 *
 * Returns the default PHP template rendered with merge tags as literal strings,
 * so the JS editor can display the default body without duplicating template HTML.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Donor;
use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Email template endpoint class.
 */
class EmailTemplateEndpoint {

	/**
	 * Template file name per email type (hyphenated).
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATE_MAP = [
		'donation_receipt'          => 'donation-receipt',
		'subscription_activated'    => 'subscription-activated',
		'renewal_receipt'           => 'renewal-receipt',
		'payment_failed'            => 'payment-failed',
		'subscription_cancelled'    => 'subscription-cancelled',
		'account_activation'        => 'account-activation',
		'password_reset'            => 'password-reset',
		'email_change_verification' => 'email-change-verification',
		'donor_note'                => 'donor-note',
		'tribute_notification'      => 'tribute-notification',
	];

	/**
	 * Default subjects per email type.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_SUBJECTS = [
		'donation_receipt'          => 'Thank you for your {amount} donation',
		'subscription_activated'    => 'Thank you for your {amount} {frequency} donation',
		'renewal_receipt'           => 'Thank you for your {frequency} gift of {amount}',
		'payment_failed'            => 'Action needed: Update your payment for your recurring donation',
		'subscription_cancelled'    => 'Your recurring donation has ended',
		'account_activation'        => 'Verify your email to activate your donor account',
		'password_reset'            => 'Reset your password',
		'email_change_verification' => 'Verify your new email address',
		'donor_note'                => 'A note about your donation',
		'tribute_notification'      => 'A donation has been made {tribute_type_label} {honoree_name}',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private readonly SettingsService $settings, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- reserved for future use.
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/email/template/(?P<type>[a-z_]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_template' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'type' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission check — requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view email templates.', 'mission' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Return the default template body HTML with merge tags as literal strings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );

		if ( ! isset( self::TEMPLATE_MAP[ $type ] ) ) {
			return new WP_Error(
				'invalid_email_type',
				__( 'Unknown email type.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		$email_module = \Mission\Plugin::instance()->get_email_module();
		$template     = self::TEMPLATE_MAP[ $type ];
		$subject      = self::DEFAULT_SUBJECTS[ $type ] ?? '';

		// Build data where values ARE the merge tag strings.
		$data            = $this->build_tag_data( $type );
		$data['subject'] = $subject;

		// Render body, header, and footer separately.
		$body   = $email_module->render_template_body( $template, $data );
		$header = $email_module->render_partial( 'header', $data );
		$footer = $email_module->render_partial( 'footer', $data );

		return new WP_REST_Response(
			[
				'body'    => $body,
				'header'  => $header,
				'footer'  => $footer,
				'subject' => $subject,
			],
			200
		);
	}

	/**
	 * Build template data where each value is the literal merge tag string.
	 *
	 * @param string $type Email type key.
	 * @return array<string, mixed>
	 */
	private function build_tag_data( string $type ): array {
		// Create a fake donor with merge tag as first_name.
		$donor = new Donor(
			[
				'id'         => 0,
				'email'      => '{donor_email}',
				'first_name' => '{donor_name}',
				'last_name'  => '',
			]
		);

		$data = [
			'donor'                  => $donor,
			'amount_formatted'       => '{amount}',
			'date_formatted'         => '{date}',
			'campaign_name'          => '{campaign}',
			'frequency_label'        => '{frequency}',
			'next_renewal_formatted' => '{next_renewal_date}',
			'subject'                => self::DEFAULT_SUBJECTS[ $type ] ?? '',
		];

		// Add type-specific tag data.
		switch ( $type ) {
			case 'renewal_receipt':
			case 'donation_receipt':
				$data['transaction'] = (object) [ 'id' => '{receipt_id}' ];
				break;

			case 'account_activation':
				$data['verification_url'] = '{verification_url}';
				$data['expiry_hours']     = '{expiry_hours}';
				break;

			case 'password_reset':
				$data['reset_url']    = '{reset_url}';
				$data['expiry_hours'] = '{expiry_hours}';
				break;

			case 'email_change_verification':
				$data['new_email']        = '{new_email}';
				$data['verification_url'] = '{verification_url}';
				$data['expiry_hours']     = '{expiry_hours}';
				break;

			case 'payment_failed':
				$data['dashboard_url'] = '{donor_dashboard}';
				break;

			case 'donor_note':
				$data['note'] = (object) [ 'content' => '{note_content}' ];
				break;

			case 'tribute_notification':
				$data['tribute_type_label'] = '{tribute_type_label}';
				$data['honoree_name']       = '{honoree_name}';
				$data['message']            = '{message}';
				$data['organization']       = '{organization}';
				break;
		}

		return $data;
	}
}
