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
		'p2p_team_invitation'              => 'p2p-team-invitation',
		'p2p_team_member_joined'           => 'p2p-team-member-joined',
		'p2p_team_approved'                => 'p2p-team-approved',
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
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/email/template/(?P<type>[a-z0-9_]+)',
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
		$subject      = $this->email->default_subject( $type );

		$data            = $this->build_tag_data( $type );
		$data['subject'] = $subject;

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
			'subject'                => $this->email->default_subject( $type ),
		];

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

			case 'p2p_team_invitation':
				$data['team']       = (object) [ 'name' => '{team_name}' ];
				$data['accept_url'] = '{accept_url}';
				break;

			case 'p2p_team_member_joined':
				$data['donor']       = $this->fake_captain();
				$data['team']        = (object) [ 'name' => '{team_name}' ];
				$data['member_name'] = '{member_name}';
				$data['page_url']    = '{page_url}';
				break;

			case 'p2p_team_approved':
				$data['donor']    = $this->fake_captain();
				$data['team']     = (object) [ 'name' => '{team_name}' ];
				$data['page_url'] = '{page_url}';
				break;
		}

		return $data;
	}

	/**
	 * A fake donor whose name renders as the captain merge tag (team emails
	 * greet the captain, so the editor should show {captain_name}).
	 *
	 * @return Donor
	 */
	private function fake_captain(): Donor {
		return new Donor(
			[
				'id'         => 0,
				'email'      => '',
				'first_name' => '{captain_name}',
				'last_name'  => '',
			]
		);
	}
}
