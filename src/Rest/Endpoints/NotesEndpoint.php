<?php
/**
 * Unified REST endpoint for notes on transactions, donors, and subscriptions.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Models\Donor;
use MissionDP\Models\Note;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Rest\RestModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Notes endpoint class.
 */
class NotesEndpoint {

	/**
	 * Map of object type to model class and route parameter name.
	 *
	 * @var array<string, array{class: class-string, param: string, route: string}>
	 */
	private const OBJECT_TYPES = [
		'transaction'  => [
			'class' => Transaction::class,
			'param' => 'object_id',
			'route' => 'transactions',
		],
		'donor'        => [
			'class' => Donor::class,
			'param' => 'object_id',
			'route' => 'donors',
		],
		'subscription' => [
			'class' => Subscription::class,
			'param' => 'object_id',
			'route' => 'subscriptions',
		],
	];

	/**
	 * Register REST routes for all object types.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::OBJECT_TYPES as $object_type => $config ) {
			$route_base = $config['route'];

			register_rest_route(
				RestModule::NAMESPACE,
				"/{$route_base}/(?P<object_id>\\d+)/notes",
				[
					[
						'methods'             => 'GET',
						'callback'            => fn( WP_REST_Request $r ) => $this->get_notes( $r, $object_type ),
						'permission_callback' => [ $this, 'check_permission' ],
						'args'                => $this->get_collection_args( $object_type ),
					],
					[
						'methods'             => 'POST',
						'callback'            => fn( WP_REST_Request $r ) => $this->create_note( $r, $object_type ),
						'permission_callback' => [ $this, 'check_permission' ],
						'args'                => $this->get_create_args( $object_type ),
					],
				]
			);

			register_rest_route(
				RestModule::NAMESPACE,
				"/{$route_base}/(?P<object_id>\\d+)/notes/(?P<note_id>\\d+)",
				[
					'methods'             => 'DELETE',
					'callback'            => fn( WP_REST_Request $r ) => $this->delete_note( $r, $object_type ),
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'object_id' => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
						'note_id'   => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				]
			);
		}
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
				__( 'You do not have permission to manage notes.', 'mission-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns notes for an object.
	 *
	 * @param WP_REST_Request $request     Request object.
	 * @param string          $object_type Object type.
	 * @return WP_REST_Response|WP_Error
	 */
	private function get_notes( WP_REST_Request $request, string $object_type ): WP_REST_Response|WP_Error {
		$object_id = $request->get_param( 'object_id' );

		$parent = $this->find_parent( $object_type, $object_id );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$query_args = [
			'object_type' => $object_type,
			'object_id'   => $object_id,
		];

		$type = $request->get_param( 'type' );
		if ( $type ) {
			$query_args['type'] = $type;
		}

		$notes = Note::query( $query_args );

		return new WP_REST_Response(
			array_map( [ $this, 'prepare_note' ], $notes ),
			200
		);
	}

	/**
	 * POST handler — creates a note for an object.
	 *
	 * @param WP_REST_Request $request     Request object.
	 * @param string          $object_type Object type.
	 * @return WP_REST_Response|WP_Error
	 */
	private function create_note( WP_REST_Request $request, string $object_type ): WP_REST_Response|WP_Error {
		$object_id = $request->get_param( 'object_id' );

		$parent = $this->find_parent( $object_type, $object_id );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$content = $request->get_param( 'content' );

		if ( empty( trim( $content ) ) ) {
			return new WP_Error(
				'empty_content',
				__( 'Note content cannot be empty.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$note = new Note(
			[
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'type'        => $request->get_param( 'type' ) ?? 'internal',
				'content'     => $content,
				'author_id'   => get_current_user_id(),
			]
		);

		$note->save();

		return new WP_REST_Response( $this->prepare_note( $note ), 201 );
	}

	/**
	 * DELETE handler — deletes a note.
	 *
	 * @param WP_REST_Request $request     Request object.
	 * @param string          $object_type Object type.
	 * @return WP_REST_Response|WP_Error
	 */
	private function delete_note( WP_REST_Request $request, string $object_type ): WP_REST_Response|WP_Error {
		$object_id = $request->get_param( 'object_id' );
		$note_id   = $request->get_param( 'note_id' );

		$note = Note::find( $note_id );

		if ( ! $note || $note->object_type !== $object_type || $note->object_id !== $object_id ) {
			return new WP_Error(
				'note_not_found',
				__( 'Note not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$note->delete();

		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * Find and validate the parent object exists.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return \MissionDP\Models\Model|WP_Error The parent model or error.
	 */
	private function find_parent( string $object_type, int $object_id ): \MissionDP\Models\Model|WP_Error {
		$model_class = self::OBJECT_TYPES[ $object_type ]['class'] ?? null;

		if ( ! $model_class ) {
			return new WP_Error(
				'invalid_object_type',
				__( 'Invalid object type.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$parent = $model_class::find( $object_id );

		if ( ! $parent ) {
			return new WP_Error(
				"{$object_type}_not_found",
				/* translators: %s: object type (e.g. "transaction", "donor") */
				sprintf( __( '%s not found.', 'mission-donation-platform' ), ucfirst( $object_type ) ),
				[ 'status' => 404 ]
			);
		}

		return $parent;
	}

	/**
	 * Prepare a note model for REST response.
	 *
	 * @param Note $note Note model.
	 * @return array<string, mixed>
	 */
	private function prepare_note( Note $note ): array {
		$author_name = '';

		if ( $note->author_id ) {
			$user = get_userdata( $note->author_id );

			if ( $user ) {
				$author_name = $user->display_name;
			}
		}

		return [
			'id'           => $note->id,
			'object_type'  => $note->object_type,
			'object_id'    => $note->object_id,
			'type'         => $note->type,
			'content'      => $note->content,
			'author_id'    => $note->author_id,
			'author_name'  => $author_name,
			'date_created' => $note->date_created,
		];
	}

	/**
	 * Get args for the GET collection route.
	 *
	 * @param string $object_type Object type.
	 * @return array<string, mixed>
	 */
	private function get_collection_args( string $object_type ): array {
		$args = [
			'object_id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];

		// Only transactions support type filtering (internal vs donor).
		if ( 'transaction' === $object_type ) {
			$args['type'] = [
				'type'              => 'string',
				'enum'              => [ 'internal', 'donor' ],
				'sanitize_callback' => 'sanitize_text_field',
			];
		}

		return $args;
	}

	/**
	 * Get args for the POST create route.
	 *
	 * @param string $object_type Object type.
	 * @return array<string, mixed>
	 */
	private function get_create_args( string $object_type ): array {
		$args = [
			'object_id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'content'   => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
			],
		];

		// Only transactions support type selection.
		if ( 'transaction' === $object_type ) {
			$args['type'] = [
				'type'              => 'string',
				'required'          => true,
				'enum'              => [ 'internal', 'donor' ],
				'sanitize_callback' => 'sanitize_text_field',
			];
		}

		return $args;
	}
}
