<?php
/**
 * REST endpoint for donors.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Donor;
use MissionDP\Reporting\ReportingService;
use MissionDP\Rest\RestModule;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Donors endpoint class.
 */
class DonorsEndpoint {

	/**
	 * Constructor.
	 *
	 * @param ReportingService $reporting Reporting service.
	 * @param SettingsService  $settings  Settings service.
	 */
	public function __construct(
		private readonly ReportingService $reporting,
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
			'/donors',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_donors' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_collection_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donors',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_donor' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_create_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donors/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_donor' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_donor' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_update_params(),
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donors/summary',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_summary' ],
				'permission_callback' => [ $this, 'check_permission' ],
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
				__( 'You do not have permission to view donors.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns paginated donors.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_donors( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' ) ?? 25;
		$page     = $request->get_param( 'page' ) ?? 1;
		$orderby  = $request->get_param( 'orderby' ) ?? 'date_created';
		$order    = $request->get_param( 'order' ) ?? 'DESC';
		$search   = $request->get_param( 'search' );

		if ( $this->is_test_mode() ) {
			$test_map = [
				'total_donated'     => 'test_total_donated',
				'transaction_count' => 'test_transaction_count',
				'last_transaction'  => 'test_last_transaction',
			];
			$orderby  = $test_map[ $orderby ] ?? $orderby;
		}

		$query_args = [
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
		];

		if ( $search ) {
			$query_args['search'] = $search;
		}

		$donors = Donor::query( $query_args );
		$total  = Donor::count( $query_args );

		$items = array_map( [ $this, 'prepare_donor' ], $donors );

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET handler — returns aggregate donor stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->reporting->donor_summary(), 200 );
	}

	/**
	 * GET handler — returns a single donor with detail fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_donor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$donor = Donor::find( $request->get_param( 'id' ) );

		if ( ! $donor ) {
			return new WP_Error(
				'donor_not_found',
				__( 'Donor not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->prepare_donor_detail( $donor ), 200 );
	}

	/**
	 * PUT handler — updates a donor's fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_donor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$donor = Donor::find( $request->get_param( 'id' ) );

		if ( ! $donor ) {
			return new WP_Error(
				'donor_not_found',
				__( 'Donor not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$email = $request->get_param( 'email' );

		if ( null !== $email ) {
			$email = sanitize_email( $email );

			if ( ! is_email( $email ) ) {
				return new WP_Error(
					'invalid_email',
					__( 'A valid email address is required.', 'mission-donation-platform' ),
					[ 'status' => 400 ]
				);
			}

			if ( $email !== $donor->email ) {
				$existing = Donor::find_by_email( $email );

				if ( $existing ) {
					return new WP_Error(
						'duplicate_donor',
						__( 'A donor with this email address already exists.', 'mission-donation-platform' ),
						[ 'status' => 409 ]
					);
				}

				$donor->email = $email;
			}
		}

		$updatable = [ 'first_name', 'last_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'zip', 'country' ];

		foreach ( $updatable as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$donor->$field = sanitize_text_field( $value );
			}
		}

		$donor->save();

		return new WP_REST_Response( $this->prepare_donor_detail( $donor ), 200 );
	}

	/**
	 * POST handler — creates a donor manually.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_donor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = sanitize_email( $request->get_param( 'email' ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$existing = Donor::find_by_email( $email );

		if ( $existing ) {
			return new WP_Error(
				'duplicate_donor',
				__( 'A donor with this email address already exists.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		$donor = new Donor(
			[
				'email'      => $email,
				'first_name' => sanitize_text_field( $request->get_param( 'first_name' ) ?? '' ),
				'last_name'  => sanitize_text_field( $request->get_param( 'last_name' ) ?? '' ),
				'phone'      => sanitize_text_field( $request->get_param( 'phone' ) ?? '' ),
				'address_1'  => sanitize_text_field( $request->get_param( 'address_1' ) ?? '' ),
				'address_2'  => sanitize_text_field( $request->get_param( 'address_2' ) ?? '' ),
				'city'       => sanitize_text_field( $request->get_param( 'city' ) ?? '' ),
				'state'      => sanitize_text_field( $request->get_param( 'state' ) ?? '' ),
				'zip'        => sanitize_text_field( $request->get_param( 'zip' ) ?? '' ),
				'country'    => sanitize_text_field( $request->get_param( 'country' ) ?? 'US' ),
			]
		);

		$donor->save();

		$note = sanitize_textarea_field( $request->get_param( 'note' ) ?? '' );

		if ( $note ) {
			$donor->add_meta( 'note', $note );
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'donor_id' => $donor->id,
			],
			201
		);
	}

	/**
	 * Whether test mode is active.
	 *
	 * @return bool
	 */
	private function is_test_mode(): bool {
		return (bool) $this->settings->get( 'test_mode' );
	}

	/**
	 * Get the mode-appropriate aggregate values for a donor.
	 *
	 * @param Donor $donor Donor model.
	 * @return array{total_donated: int, transaction_count: int, first_transaction: ?string, last_transaction: ?string}
	 */
	private function get_donor_aggregate( Donor $donor ): array {
		if ( $this->is_test_mode() ) {
			return [
				'total_donated'     => $donor->test_total_donated,
				'transaction_count' => $donor->test_transaction_count,
				'first_transaction' => $donor->test_first_transaction,
				'last_transaction'  => $donor->test_last_transaction,
			];
		}

		return [
			'total_donated'     => $donor->total_donated,
			'transaction_count' => $donor->transaction_count,
			'first_transaction' => $donor->first_transaction,
			'last_transaction'  => $donor->last_transaction,
		];
	}

	/**
	 * Prepare a donor model for REST response.
	 *
	 * @param Donor $donor Donor model.
	 * @return array<string, mixed>
	 */
	private function prepare_donor( Donor $donor ): array {
		$agg = $this->get_donor_aggregate( $donor );

		return [
			'id'                => $donor->id,
			'email'             => $donor->email,
			'first_name'        => $donor->first_name,
			'last_name'         => $donor->last_name,
			'phone'             => $donor->phone,
			'address_1'         => $donor->address_1,
			'address_2'         => $donor->address_2,
			'city'              => $donor->city,
			'state'             => $donor->state,
			'zip'               => $donor->zip,
			'country'           => $donor->country,
			'total_donated'     => $agg['total_donated'],
			'transaction_count' => $agg['transaction_count'],
			'last_transaction'  => $agg['last_transaction'],
			'date_created'      => $donor->date_created,
			'gravatar_hash'     => $donor->gravatar_hash(),
		];
	}

	/**
	 * Prepare a donor model for a detail response (extends prepare_donor).
	 *
	 * @param Donor $donor Donor model.
	 * @return array<string, mixed>
	 */
	private function prepare_donor_detail( Donor $donor ): array {
		$data = $this->prepare_donor( $donor );
		$agg  = $this->get_donor_aggregate( $donor );

		$data['first_transaction'] = $agg['first_transaction'];
		$data['date_modified']     = $donor->date_modified;

		$detail_stats         = $this->reporting->donor_detail_stats( $donor );
		$data['is_recurring'] = $detail_stats['is_recurring'];
		$data['is_top_donor'] = $detail_stats['is_top_donor'];

		// Since label — formatted "Jan 2024" from date_created.
		$data['since_label'] = '';
		if ( $donor->date_created && ! str_starts_with( $donor->date_created, '0000' ) ) {
			$timestamp           = strtotime( $donor->date_created );
			$data['since_label'] = $timestamp ? date_i18n( 'M Y', $timestamp ) : '';
		}

		return $data;
	}

	/**
	 * Get collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_params(): array {
		return [
			'page'     => [
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
			'orderby'  => [
				'type'              => 'string',
				'default'           => 'date_created',
				'enum'              => [ 'date_created', 'total_donated', 'transaction_count', 'last_transaction' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'order'    => [
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => [ 'ASC', 'DESC' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'search'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get parameters for the update (PUT) endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_params(): array {
		return [
			'id'         => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'email'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			],
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
	 * Get parameters for the create (POST) endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_create_params(): array {
		return [
			'email'      => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
			],
			'first_name' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'last_name'  => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'phone'      => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'address_1'  => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'address_2'  => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'city'       => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'state'      => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'zip'        => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'country'    => [
				'type'              => 'string',
				'default'           => 'US',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'note'       => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
		];
	}
}
