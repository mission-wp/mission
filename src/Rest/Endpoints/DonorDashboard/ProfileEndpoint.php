<?php
/**
 * Donor dashboard profile endpoint.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints\DonorDashboard;

use Mission\Models\Donor;
use Mission\Rest\RestModule;
use Mission\Rest\Traits\ResolveDonorTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles donor dashboard profile, preferences, and account routes.
 */
class ProfileEndpoint {

	use ResolveDonorTrait;

	/**
	 * Preference meta keys and their defaults.
	 *
	 * @var array<string, bool>
	 */
	private const PREFERENCE_DEFAULTS = [
		'email_receipts'         => true,
		'email_campaign_updates' => true,
		'email_annual_reminder'  => true,
	];

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/profile',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_profile' ],
					'permission_callback' => [ $this, 'check_donor_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_profile' ],
					'permission_callback' => [ $this, 'check_donor_permission' ],
					'args'                => $this->get_profile_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/preferences',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_preferences' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => $this->get_preference_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/account',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_account' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);
	}

	/**
	 * GET /donor-dashboard/profile
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile(): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		return new WP_REST_Response( $this->prepare_profile( $donor ) );
	}

	/**
	 * PUT /donor-dashboard/profile
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$updatable = [ 'first_name', 'last_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'zip', 'country' ];

		foreach ( $updatable as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$donor->$field = sanitize_text_field( $value );
			}
		}

		$donor->save();

		/**
		 * Fires after a donor updates their profile.
		 *
		 * @param Donor $donor The updated donor.
		 */
		do_action( 'mission_donor_profile_updated', $donor );

		return new WP_REST_Response( $this->prepare_profile( $donor ) );
	}

	/**
	 * PUT /donor-dashboard/preferences
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_preferences( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		foreach ( array_keys( self::PREFERENCE_DEFAULTS ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$donor->update_meta( $key, $value ? '1' : '0' );
			}
		}

		return new WP_REST_Response( $this->get_preferences( $donor ) );
	}

	/**
	 * DELETE /donor-dashboard/account
	 *
	 * Unlinks the WP user from the donor record. The donor record and all
	 * transaction history are preserved for the organization's records.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_account(): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$user_id = $donor->user_id;

		// Null out user_id before deleting the WP user to prevent hooks from
		// finding the donor still linked.
		$donor->user_id = null;
		$donor->save();

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );

		/**
		 * Fires after a donor deletes their account.
		 *
		 * @param Donor $donor   The donor whose account was deleted.
		 * @param int   $user_id The WP user ID that was deleted.
		 */
		do_action( 'mission_donor_account_deleted', $donor, $user_id );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Your account has been deleted. Your donation history has been preserved for the organization\'s records.', 'missionwp-donation-platform' ),
			]
		);
	}

	/**
	 * Prepare a donor's profile for REST response.
	 *
	 * @param Donor $donor Donor model.
	 * @return array<string, mixed>
	 */
	private function prepare_profile( Donor $donor ): array {
		return [
			'id'            => $donor->id,
			'email'         => $donor->email,
			'first_name'    => $donor->first_name,
			'last_name'     => $donor->last_name,
			'phone'         => $donor->phone,
			'address_1'     => $donor->address_1,
			'address_2'     => $donor->address_2,
			'city'          => $donor->city,
			'state'         => $donor->state,
			'zip'           => $donor->zip,
			'country'       => $donor->country,
			'gravatar_hash' => $donor->gravatar_hash(),
			'preferences'   => $this->get_preferences( $donor ),
		];
	}

	/**
	 * Get a donor's communication preferences with defaults.
	 *
	 * @param Donor $donor Donor model.
	 * @return array<string, bool>
	 */
	private function get_preferences( Donor $donor ): array {
		$preferences = [];

		foreach ( self::PREFERENCE_DEFAULTS as $key => $default ) {
			$value = $donor->get_meta( $key );

			$preferences[ $key ] = ( '' === $value || false === $value )
				? $default
				: '1' === $value;
		}

		return $preferences;
	}

	/**
	 * Get parameters for the profile update endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_profile_params(): array {
		return [
			'first_name' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'last_name'  => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'phone'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'address_1'  => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'address_2'  => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'city'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'state'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'zip'        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'country'    => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get parameters for the preferences update endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_preference_params(): array {
		$params = [];

		foreach ( array_keys( self::PREFERENCE_DEFAULTS ) as $key ) {
			$params[ $key ] = [
				'type' => 'boolean',
			];
		}

		return $params;
	}
}
