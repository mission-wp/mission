<?php
/**
 * Donor wall REST endpoint.
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

defined( 'ABSPATH' ) || exit;

/**
 * Public endpoint for the donor wall block.
 *
 * Returns sanitized donation data — no emails, IPs, or internal IDs.
 */
class DonorWallEndpoint {

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
			'/donor-wall',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'campaign_id'    => [
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'per_page'       => [
						'type'              => 'integer',
						'default'           => 12,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $val ) => $val >= 1 && $val <= 50,
					],
					'page'           => [
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $val ) => $val >= 1,
					],
					'orderby'        => [
						'type'              => 'string',
						'default'           => 'date_completed',
						'enum'              => [ 'date_completed', 'amount' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'order'          => [
						'type'              => 'string',
						'default'           => 'DESC',
						'enum'              => [ 'ASC', 'DESC' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'show_anonymous' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
			]
		);
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->reporting->donor_wall_query(
			campaign_id: (int) $request->get_param( 'campaign_id' ),
			per_page: (int) $request->get_param( 'per_page' ),
			page: (int) $request->get_param( 'page' ),
			orderby: $request->get_param( 'orderby' ),
			order: $request->get_param( 'order' ),
			show_anonymous: (bool) $request->get_param( 'show_anonymous' ),
		);

		// Sanitize items for public consumption.
		$items = array_map( [ $this, 'sanitize_item' ], $result['items'] );

		return new WP_REST_Response(
			[
				'items' => $items,
				'total' => $result['total'],
			]
		);
	}

	/**
	 * Sanitize a donation item for public display.
	 *
	 * Never exposes email, donor ID, or IP address.
	 *
	 * @param array $item Raw item from ReportingService.
	 * @return array Sanitized item.
	 */
	private function sanitize_item( array $item ): array {
		if ( $item['is_anonymous'] ) {
			return [
				'name'          => __( 'Anonymous', 'mission-donation-platform' ),
				'initials'      => '?',
				'is_anonymous'  => true,
				'amount'        => $item['amount'],
				'currency'      => $item['currency'],
				'date'          => $item['date'],
				'type'          => $item['type'],
				'gravatar_hash' => null,
				'comment'       => $this->truncate_comment( $item['comment'] ),
			];
		}

		$first    = $item['first_name'] ?? '';
		$last     = $item['last_name'] ?? '';
		$name     = trim( $first . ' ' . mb_substr( $last, 0, 1 ) . '.' );
		$initials = strtoupper( mb_substr( $first, 0, 1 ) . mb_substr( $last, 0, 1 ) );

		if ( '.' === $name ) {
			$name = __( 'Anonymous', 'mission-donation-platform' );
		}
		if ( '' === trim( $initials ) ) {
			$initials = '?';
		}

		$gravatar_hash = null;
		if ( ! empty( $item['email'] ) ) {
			$gravatar_hash = md5( strtolower( trim( $item['email'] ) ) );
		}

		return [
			'name'          => $name,
			'initials'      => $initials,
			'is_anonymous'  => false,
			'amount'        => $item['amount'],
			'currency'      => $item['currency'],
			'date'          => $item['date'],
			'type'          => $item['type'],
			'gravatar_hash' => $gravatar_hash,
			'comment'       => $this->truncate_comment( $item['comment'] ),
		];
	}

	/**
	 * Truncate a donor comment for safety.
	 *
	 * @param string|null $comment Comment text.
	 * @return string|null
	 */
	private function truncate_comment( ?string $comment ): ?string {
		if ( null === $comment || '' === $comment ) {
			return null;
		}

		if ( mb_strlen( $comment ) > 500 ) {
			return mb_substr( $comment, 0, 500 ) . '…';
		}

		return $comment;
	}
}
