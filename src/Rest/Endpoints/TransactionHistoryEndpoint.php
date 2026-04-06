<?php
/**
 * REST endpoint for transaction history.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Transaction;
use Mission\Models\TransactionHistory;
use Mission\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction history endpoint class.
 */
class TransactionHistoryEndpoint {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/transactions/(?P<transaction_id>\d+)/history',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_history' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'transaction_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'per_page'       => [
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					],
					'page'           => [
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
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
				__( 'You do not have permission to view transaction history.', 'mission' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns history for a transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_history( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction_id = $request->get_param( 'transaction_id' );
		$transaction    = Transaction::find( $transaction_id );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );

		$query_args = [
			'transaction_id' => $transaction_id,
			'per_page'       => $per_page,
			'page'           => $page,
			'order'          => 'DESC',
		];

		$entries = TransactionHistory::query( $query_args );
		$total   = TransactionHistory::count( [ 'transaction_id' => $transaction_id ] );

		$response = new WP_REST_Response(
			array_map( [ $this, 'prepare_entry' ], $entries ),
			200
		);

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Prepare a history entry for REST response.
	 *
	 * @param TransactionHistory $entry History entry.
	 * @return array<string, mixed>
	 */
	private function prepare_entry( TransactionHistory $entry ): array {
		$actor_name = null;

		if ( 'user' === $entry->actor_type && $entry->actor_id ) {
			$user = get_userdata( $entry->actor_id );

			if ( $user ) {
				$actor_name = $user->display_name;
			}
		}

		return [
			'id'             => $entry->id,
			'transaction_id' => $entry->transaction_id,
			'event_type'     => $entry->event_type,
			'actor_type'     => $entry->actor_type,
			'actor_id'       => $entry->actor_id,
			'actor_name'     => $actor_name,
			'context'        => $entry->get_context_data(),
			'created_at'     => $entry->created_at,
		];
	}
}
