<?php
/**
 * REST endpoint for retrieving default email template HTML.
 *
 * Returns the default PHP template rendered with merge tags as literal strings,
 * so the JS editor can display the default body without duplicating template HTML.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Email\EmailModule;
use MissionDP\Models\Donor;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Email template endpoint class.
 */
class EmailTemplateEndpoint {

	use AdminPermissionTrait;

	/**
	 * Template file name per email type (hyphenated).
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATE_MAP = [
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
	];

	/**
	 * Constructor.
	 *
	 * @param EmailModule $email Email module.
	 */
	public function __construct(
		private EmailModule $email,
	) {}

	/**
	 * Default subjects per email type, with merge tags as literal placeholders.
	 *
	 * Wrapped in a method instead of a const so strings can be translated.
	 *
	 * @return array<string, string>
	 */
	private function default_subjects(): array {
		return [
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
			'p2p_fundraiser_approved'          => __( 'Your fundraising page is live', 'mission-donation-platform' ),
			'p2p_fundraiser_received_donation' => __( 'You received a {amount} donation!', 'mission-donation-platform' ),
			'p2p_fundraiser_milestone'         => __( "You've reached {milestone} of your goal!", 'mission-donation-platform' ),
		];
	}

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
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'type' => Args::string( [ 'required' => true ] ),
				],
			]
		);
	}

	/**
	 * Message returned when the capability check fails.
	 *
	 * @return string
	 */
	protected function permission_denied_message(): string {
		return __( 'You do not have permission to view email templates.', 'mission-donation-platform' );
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
				__( 'Unknown email type.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$email_module = $this->email;
		$template     = self::TEMPLATE_MAP[ $type ];
		$subject      = $this->default_subjects()[ $type ] ?? '';

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
			'subject'                => $this->default_subjects()[ $type ] ?? '',
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

			case 'p2p_fundraiser_approved':
				$data['page_url'] = '{page_url}';
				break;

			case 'p2p_fundraiser_received_donation':
				$data['page_url']   = '{page_url}';
				$data['giver_name'] = '{giver_name}';
				break;

			case 'p2p_fundraiser_milestone':
				$data['page_url']         = '{page_url}';
				$data['milestone_label']  = '{milestone}';
				$data['raised_formatted'] = '{amount}';
				$data['goal_formatted']   = '{goal}';
				break;
		}

		return $data;
	}
}
