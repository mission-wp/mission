<?php
/**
 * REST endpoint for managing tributes (dedications) on transactions.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Transaction;
use Mission\Models\Tribute;
use Mission\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tribute endpoint class.
 */
class TributeEndpoint {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/transactions/(?P<transaction_id>\d+)/tribute',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'upsert_tribute' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_upsert_args(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_tribute' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'transaction_id' => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
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
				__( 'You do not have permission to manage tributes.', 'missionwp-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * PUT handler — create or update a tribute on a transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upsert_tribute( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction_id = $request->get_param( 'transaction_id' );
		$transaction    = Transaction::find( $transaction_id );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$tribute = Tribute::find_by_transaction_id( $transaction_id );

		$data = [
			'transaction_id'   => $transaction_id,
			'tribute_type'     => $request->get_param( 'tribute_type' ) ?? 'in_honor',
			'honoree_name'     => $request->get_param( 'honoree_name' ) ?? '',
			'message'          => $request->get_param( 'message' ) ?? '',
			'notify_name'      => $request->get_param( 'notify_name' ) ?? '',
			'notify_email'     => $request->get_param( 'notify_email' ) ?? '',
			'notify_method'    => $request->get_param( 'notify_method' ) ?? '',
			'notify_address_1' => $request->get_param( 'notify_address_1' ) ?? '',
			'notify_city'      => $request->get_param( 'notify_city' ) ?? '',
			'notify_state'     => $request->get_param( 'notify_state' ) ?? '',
			'notify_zip'       => $request->get_param( 'notify_zip' ) ?? '',
			'notify_country'   => $request->get_param( 'notify_country' ) ?? '',
		];

		$sent_at = $request->get_param( 'notification_sent_at' );
		if ( $sent_at ) {
			$data['notification_sent_at'] = $sent_at;
		}

		if ( $tribute ) {
			// Update existing.
			foreach ( $data as $key => $value ) {
				$tribute->$key = $value;
			}
			$tribute->save();
		} else {
			// Create new.
			$tribute = new Tribute( $data );
			$tribute->save();
		}

		return new WP_REST_Response( $this->prepare_tribute( $tribute ), 200 );
	}

	/**
	 * DELETE handler — remove a tribute from a transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_tribute( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction_id = $request->get_param( 'transaction_id' );
		$transaction    = Transaction::find( $transaction_id );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$tribute = Tribute::find_by_transaction_id( $transaction_id );

		if ( ! $tribute ) {
			return new WP_Error(
				'tribute_not_found',
				__( 'No tribute found on this transaction.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$tribute->delete();

		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * Prepare a tribute model for REST response.
	 *
	 * @param Tribute $tribute Tribute model.
	 * @return array<string, mixed>
	 */
	private function prepare_tribute( Tribute $tribute ): array {
		return [
			'id'                   => $tribute->id,
			'tribute_type'         => $tribute->tribute_type,
			'honoree_name'         => $tribute->honoree_name,
			'message'              => $tribute->message,
			'notify_name'          => $tribute->notify_name,
			'notify_email'         => $tribute->notify_email,
			'notify_method'        => $tribute->notify_method,
			'notify_address_1'     => $tribute->notify_address_1,
			'notify_city'          => $tribute->notify_city,
			'notify_state'         => $tribute->notify_state,
			'notify_zip'           => $tribute->notify_zip,
			'notify_country'       => $tribute->notify_country,
			'notification_sent_at' => $tribute->notification_sent_at,
		];
	}

	/**
	 * Get arguments for the PUT route.
	 *
	 * @return array<string, mixed>
	 */
	private function get_upsert_args(): array {
		return [
			'transaction_id'       => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'tribute_type'         => [
				'type'              => 'string',
				'enum'              => [ 'in_honor', 'in_memory' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'honoree_name'         => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'message'              => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'notify_name'          => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notify_email'         => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			],
			'notify_method'        => [
				'type'              => 'string',
				'enum'              => [ '', 'email', 'mail' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notify_address_1'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notify_city'          => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notify_state'         => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notify_zip'           => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notify_country'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notification_sent_at' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
