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
use MissionDP\Rest\RestModule;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Email test endpoint class.
 */
class EmailTestEndpoint {

	/**
	 * Default subjects per email type (mirrors listener hardcoded defaults).
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_SUBJECTS = [
		'donation_receipt'          => 'Thank you for your %s donation',
		'subscription_activated'    => 'Thank you for your %s %s donation',
		'renewal_receipt'           => 'Thank you for your %s gift of %s',
		'payment_failed'            => 'Action needed: Update your payment for your recurring donation',
		'subscription_cancelled'    => 'Your recurring donation has ended',
		'account_activation'        => 'Verify your email to activate your donor account',
		'password_reset'            => 'Reset your password',
		'email_change_verification' => 'Verify your new email address',
	];

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
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private readonly SettingsService $settings,
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
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'email_type' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
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
	 * Permission check — requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to send test emails.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
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

		if ( ! isset( self::TEMPLATE_MAP[ $email_type ] ) ) {
			return new WP_Error(
				'invalid_email_type',
				__( 'Unknown email type.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $to ) {
			$to = wp_get_current_user()->user_email;
		}

		$email_module = \MissionDP\Plugin::instance()->get_email_module();
		$data         = $this->build_sample_data( $email_type, $to );
		$template     = self::TEMPLATE_MAP[ $email_type ];

		// Build subject.
		$subject        = $this->get_default_subject( $email_type, $data );
		$custom_subject = $email_module->get_custom_subject( $email_type );

		if ( $custom_subject ) {
			$tags    = $email_module->build_merge_tags( $data );
			$subject = $email_module->replace_subject_tags( $custom_subject, $tags );
		}

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

		// Add type-specific sample data.
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
		}

		return $base;
	}

	/**
	 * Get the default subject for an email type with sample data interpolated.
	 *
	 * @param string $email_type Email type key.
	 * @param array  $data       Sample data.
	 * @return string
	 */
	private function get_default_subject( string $email_type, array $data ): string {
		return match ( $email_type ) {
			'donation_receipt'       => sprintf( self::DEFAULT_SUBJECTS[ $email_type ], $data['amount_formatted'] ),
			'subscription_activated' => sprintf( self::DEFAULT_SUBJECTS[ $email_type ], $data['amount_formatted'], strtolower( $data['frequency_label'] ) ),
			'renewal_receipt'        => sprintf( self::DEFAULT_SUBJECTS[ $email_type ], strtolower( $data['frequency_label'] ), $data['amount_formatted'] ),
			default                  => self::DEFAULT_SUBJECTS[ $email_type ] ?? '',
		};
	}
}
