<?php
/**
 * REST endpoint for data export.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Export\ExportService;
use MissionDP\Rest\Args;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Export endpoint class.
 */
class ExportEndpoint {

	use AdminPermissionTrait;

	/**
	 * Constructor.
	 *
	 * @param ExportService $export Export service.
	 */
	public function __construct(
		private ExportService $export,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/export/count',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_count' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_filter_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/export/preview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_preview' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => $this->get_filter_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/export/download',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => array_merge(
					$this->get_filter_params(),
					[
						'format' => Args::string( [ 'default' => 'csv' ] ),
					]
				),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/export/download-all',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download_all' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'format' => Args::string( [ 'default' => 'csv' ] ),
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
		return __( 'You do not have permission to export data.', 'mission-donation-platform' );
	}

	/**
	 * Get the record count for a data type.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_count( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );

		if ( ! $this->export->is_valid_type( $type ) ) {
			return $this->invalid_type_error();
		}

		$count = $this->export->get_count( $type, $this->get_params( $request ) );

		return new WP_REST_Response( [ 'count' => $count ] );
	}

	/**
	 * Get preview data for the export table.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );

		if ( ! $this->export->is_valid_type( $type ) ) {
			return $this->invalid_type_error();
		}

		$preview = $this->export->get_preview( $type, $this->get_params( $request ) );

		return new WP_REST_Response( $preview );
	}

	/**
	 * Download an export file for a single data type.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_Error|void
	 */
	public function download( WP_REST_Request $request ) {
		$type   = $request->get_param( 'type' );
		$format = $request->get_param( 'format' );

		if ( ! $this->export->is_valid_type( $type ) ) {
			return $this->invalid_type_error();
		}

		$formatter = $this->export->get_formatter( $format );

		if ( ! $formatter ) {
			return new WP_Error(
				'invalid_format',
				__( 'Invalid export format.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$params  = $this->get_params( $request );
		$result  = $this->export->get_records( $type, $params );
		$content = $formatter->format( $result['columns'], $result['rows'], $type );

		$filename = sanitize_file_name(
			sprintf( 'mission-%s-export-%s.%s', $type, gmdate( 'Y-m-d' ), $formatter->get_extension() )
		);

		/**
		 * Fires when a data export is downloaded.
		 *
		 * @param string $type   Data type exported.
		 * @param string $format File format.
		 * @param int    $count  Number of records exported.
		 */
		do_action( 'mission_data_exported', $type, $format, count( $result['rows'] ) );

		$this->stream_download( $content, $filename, $formatter->get_content_type() );
	}

	/**
	 * Download all data types as a ZIP file.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_Error|void
	 */
	public function download_all( WP_REST_Request $request ) {
		$format    = $request->get_param( 'format' );
		$formatter = $this->export->get_formatter( $format );

		if ( ! $formatter ) {
			return new WP_Error(
				'invalid_format',
				__( 'Invalid export format.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'zip_unavailable',
				__( 'ZIP support is not available on this server.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp_file = wp_tempnam( 'mission-export' );
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $tmp_file, ZipArchive::OVERWRITE ) ) {
			return new WP_Error(
				'zip_failed',
				__( 'Failed to create ZIP file.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		$types       = [ 'donors', 'transactions', 'campaigns', 'subscriptions', 'tributes' ];
		$total_count = 0;

		foreach ( $types as $type ) {
			$result       = $this->export->get_records( $type, [] );
			$content      = $formatter->format( $result['columns'], $result['rows'], $type );
			$total_count += count( $result['rows'] );

			$zip->addFromString(
				sprintf( 'mission-%s.%s', $type, $formatter->get_extension() ),
				$content
			);
		}

		$zip->close();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file.
		$zip_content = file_get_contents( $tmp_file );
		wp_delete_file( $tmp_file );

		$filename = sanitize_file_name(
			sprintf( 'mission-export-%s.zip', gmdate( 'Y-m-d' ) )
		);

		/** This action is documented in src/Rest/Endpoints/ExportEndpoint.php */
		do_action( 'mission_data_exported', 'all', $format, $total_count );

		$this->stream_download( $zip_content, $filename, 'application/zip' );
	}

	/**
	 * Stream file content as a download response.
	 *
	 * @param string $content      File content.
	 * @param string $filename     Download filename.
	 * @param string $content_type MIME type.
	 *
	 * @return void
	 */
	private function stream_download( string $content, string $filename, string $content_type ): void {
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		header( 'Cache-Control: private, max-age=0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary/text export content.
		echo $content;
		exit;
	}

	/**
	 * Extract filter parameters from a request.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array<string, string>
	 */
	private function get_params( WP_REST_Request $request ): array {
		return array_filter(
			[
				'id'                  => $request->get_param( 'id' ),
				'date_from'           => $request->get_param( 'date_from' ),
				'date_to'             => $request->get_param( 'date_to' ),
				'notify_method'       => $request->get_param( 'notify_method' ),
				'notification_status' => $request->get_param( 'notification_status' ),
			]
		);
	}

	/**
	 * Get the shared filter parameter definitions.
	 *
	 * @return array
	 */
	private function get_filter_params(): array {
		return [
			'type'                => Args::string( [ 'required' => true ] ),
			'id'                  => Args::integer(),
			'date_from'           => Args::string(),
			'date_to'             => Args::string(),
			'notify_method'       => Args::enum( [ 'email', 'mail' ] ),
			'notification_status' => Args::enum( [ 'pending', 'sent' ] ),
		];
	}

	/**
	 * Return a standard invalid type error.
	 *
	 * @return WP_Error
	 */
	private function invalid_type_error(): WP_Error {
		return new WP_Error(
			'invalid_type',
			__( 'Invalid export type. Must be one of: donors, transactions, campaigns, subscriptions, tributes.', 'mission-donation-platform' ),
			[ 'status' => 400 ]
		);
	}
}
