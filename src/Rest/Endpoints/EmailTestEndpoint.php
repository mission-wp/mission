<?php
/**
 * REST endpoint for sending test emails.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Currency\Currency;
use MissionDP\Email\EmailModule;
use MissionDP\Models\Donor;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Email test endpoint class.
 */
class EmailTestEndpoint {

	use AdminPermissionTrait;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 * @param EmailModule     $email    Email module.
	 */
	public function __construct(
		private SettingsService $settings,
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
			'/email/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_test' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'email_type' => Args::string( [ 'required' => true ] ),
					'to'         => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
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
		return __( 'You do not have permission to send test emails.', 'mission-donation-platform' );
	}

	/**
	 * Send a test email.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email_type = $request->get_param( 'email_type' );
		$to         = $request->get_param( 'to' );

		if ( ! isset( EmailModule::TEMPLATE_MAP[ $email_type ] ) ) {
			return new WP_Error(
				'invalid_email_type',
				__( 'Unknown email type.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $to ) {
			$to = wp_get_current_user()->user_email;
		}

		$email_module = $this->email;
		$data         = $this->build_sample_data( $email_type, $to );
		$template     = EmailModule::TEMPLATE_MAP[ $email_type ];

		$tags = $email_module->build_merge_tags( $data );

		$subject = $email_module->subject( $email_type, $tags );

		$html = $email_module->render_template( $template, array_merge( $data, [ 'subject' => $subject ] ) );

		if ( ! $html ) {
			return new WP_Error(
				'render_failed',
				__( 'Failed to render email template.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		$sent = $email_module->send( $to, $subject, $html );

		if ( ! $sent ) {
			return new WP_Error(
				'send_failed',
				__( 'Failed to send test email. Check your mail configuration.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Build sample data for a test email.
	 *
	 * @param string $email_type Email type key.
	 * @param string $to         Recipient email.
	 * @return array<string, mixed>
	 */
	private function build_sample_data( string $email_type, string $to ): array {
		$currency = $this->settings->get( 'currency', 'USD' );

		$donor = new Donor(
			[
				'id'         => 0,
				'email'      => $to,
				'first_name' => 'Sarah',
				'last_name'  => 'Johnson',
			]
		);

		$base = [
			'donor'                  => $donor,
			'amount_formatted'       => Currency::format_amount( 5000, $currency ),
			'date_formatted'         => wp_date( get_option( 'date_format' ) ),
			'campaign_name'          => __( 'General Fund', 'mission-donation-platform' ),
			'frequency_label'        => __( 'Monthly', 'mission-donation-platform' ),
			'next_renewal_formatted' => wp_date( get_option( 'date_format' ), strtotime( '+1 month' ) ),
		];

		switch ( $email_type ) {
			case 'account_activation':
				$base['verification_url'] = home_url( '/?action=activate&token=sample-test-token' );
				$base['expiry_hours']     = 24;
				break;

			case 'password_reset':
				$base['reset_url']    = home_url( '/?action=reset-password&key=sample-test-key' );
				$base['expiry_hours'] = 24;
				break;

			case 'email_change_verification':
				$base['new_email']        = 'newemail@example.com';
				$base['verification_url'] = home_url( '/?action=verify-email&token=sample-test-token' );
				$base['expiry_hours']     = 24;
				break;

			case 'donor_note':
				$base['note'] = (object) [
					'content' => __( 'Thank you so much for your generous support. It means the world to us!', 'mission-donation-platform' ),
				];
				break;

			case 'tribute_notification':
				$base['organization']       = $this->settings->get( 'org_name', get_bloginfo( 'name' ) );
				$base['tribute_type_label'] = __( 'in memory of', 'mission-donation-platform' );
				$base['honoree_name']       = 'Robert Johnson';
				$base['message']            = __( 'Forever in our hearts.', 'mission-donation-platform' );
				break;

			case 'p2p_fundraiser_approved':
				$base['page_url'] = home_url( '/fundraisers/sample-fundraiser/' );
				break;

			case 'p2p_fundraiser_received_donation':
				$base['page_url']   = home_url( '/fundraisers/sample-fundraiser/' );
				$base['giver_name'] = 'Alex Rivera';
				break;

			case 'p2p_fundraiser_milestone':
				$base['page_url']         = home_url( '/fundraisers/sample-fundraiser/' );
				$base['milestone_label']  = '50%';
				$base['raised_formatted'] = Currency::format_amount( 25000, $currency );
				$base['goal_formatted']   = Currency::format_amount( 50000, $currency );
				break;

			case 'p2p_team_invitation':
				$base['team']       = (object) [ 'name' => __( 'Team Sunshine', 'mission-donation-platform' ) ];
				$base['accept_url'] = add_query_arg( 'team_invite', 'sample-test-token', home_url( '/teams/sample-team/' ) );
				break;

			case 'p2p_team_member_joined':
				$base['team']        = (object) [ 'name' => __( 'Team Sunshine', 'mission-donation-platform' ) ];
				$base['member_name'] = 'Alex Rivera';
				$base['page_url']    = home_url( '/teams/sample-team/' );
				break;

			case 'p2p_team_approved':
				$base['team']     = (object) [ 'name' => __( 'Team Sunshine', 'mission-donation-platform' ) ];
				$base['page_url'] = home_url( '/teams/sample-team/' );
				break;
		}

		return $base;
	}
}
