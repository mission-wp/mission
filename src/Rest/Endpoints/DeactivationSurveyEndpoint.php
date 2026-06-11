<?php
/**
 * REST endpoint for the deactivation survey.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation survey endpoint class.
 *
 * Relays the anonymous survey response to the Mission API without ever
 * blocking the deactivation flow.
 */
class DeactivationSurveyEndpoint {

	/**
	 * Valid survey reason keys, shared with the modal markup.
	 *
	 * @var string[]
	 */
	public const REASONS = [
		'temporary',
		'no-longer-needed',
		'found-better-plugin',
		'missing-feature',
		'not-working',
		'too-complicated',
		'other',
	];

	/**
	 * Maximum length of the free-text feedback.
	 */
	private const FEEDBACK_MAX_LENGTH = 1000;

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/deactivation-survey',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'reason'   => [
						'type'              => 'string',
						'required'          => true,
						'enum'              => self::REASONS,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'feedback' => [
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => static fn( $value ): string => mb_substr( sanitize_textarea_field( (string) $value ), 0, self::FEEDBACK_MAX_LENGTH ),
					],
				],
			]
		);
	}

	/**
	 * Permission check — requires the capability that gates deactivation.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Relay the survey response to the Mission API.
	 *
	 * The payload is intentionally anonymous: reason, feedback, and plugin
	 * version only. No domain or other site-identifying data is sent.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response {
		/**
		 * Filters the deactivation survey payload sent to the Mission API.
		 *
		 * @param array $payload Payload with reason, feedback, and version.
		 */
		$payload = apply_filters(
			'mission_deactivation_survey_payload',
			[
				'reason'   => $request->get_param( 'reason' ),
				'feedback' => $request->get_param( 'feedback' ),
				'version'  => MISSIONDP_VERSION,
			]
		);

		// Non-blocking so a slow or unreachable API never delays deactivation.
		wp_remote_post(
			'https://api.missionwp.com/v1/deactivation-survey',
			[
				'blocking' => false,
				'body'     => $payload,
			]
		);

		/**
		 * Fires after a deactivation survey response has been submitted.
		 *
		 * @param array $payload The payload sent to the Mission API.
		 */
		do_action( 'mission_deactivation_survey_submitted', $payload );

		return new WP_REST_Response( [ 'success' => true ] );
	}
}
