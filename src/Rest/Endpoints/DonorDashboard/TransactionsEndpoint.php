<?php
/**
 * Donor dashboard transactions and receipts endpoint.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints\DonorDashboard;

use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Receipts\ReceiptPdfGenerator;
use Mission\Reporting\ReportingService;
use Mission\Rest\RestModule;
use Mission\Rest\Traits\DonorDashboardPrepareTrait;
use Mission\Rest\Traits\ResolveDonorTrait;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles donor dashboard transaction history and receipt routes.
 */
class TransactionsEndpoint {

	use ResolveDonorTrait;
	use DonorDashboardPrepareTrait;

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
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/transactions',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_transactions' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => $this->get_transaction_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/transactions/(?P<id>\d+)/receipt',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_transaction_receipt_pdf' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/receipts',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_receipts' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/donor-dashboard/receipts/(?P<year>\d{4})/pdf',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_receipt_pdf' ],
				'permission_callback' => [ $this, 'check_donor_permission' ],
				'args'                => [
					'year' => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 2000,
						'maximum'           => 9999,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * GET /donor-dashboard/transactions
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$is_test  = $this->settings->get( 'test_mode', false );
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );

		$query_args = [
			'donor_id' => $donor->id,
			'is_test'  => $is_test,
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		];

		$campaign_id = $request->get_param( 'campaign_id' );
		if ( $campaign_id ) {
			$query_args['campaign_id'] = $campaign_id;
		}

		$type = $request->get_param( 'type' );
		if ( 'recurring' === $type ) {
			$query_args['type__not'] = 'one_time';
		} elseif ( $type ) {
			$query_args['type'] = $type;
		}

		$year = $request->get_param( 'year' );
		if ( $year ) {
			$query_args['date_after']  = "{$year}-01-01 00:00:00";
			$query_args['date_before'] = "{$year}-12-31 23:59:59";
		}

		$transactions = Transaction::query( $query_args );
		$total        = Transaction::count( $query_args );
		$total_pages  = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$this->preload_campaigns( array_map( static fn( $t ) => $t->campaign_id, $transactions ) );

		$response = new WP_REST_Response(
			array_map( [ $this, 'prepare_transaction' ], $transactions )
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET /donor-dashboard/receipts
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_receipts(): WP_REST_Response|WP_Error {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$is_test = $this->settings->get( 'test_mode', false );

		return new WP_REST_Response(
			$this->reporting->donor_annual_summary( $donor->id, $is_test )
		);
	}

	/**
	 * GET /donor-dashboard/receipts/{year}/pdf
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|null
	 */
	public function get_receipt_pdf( WP_REST_Request $request ): WP_Error|null {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$year         = (int) $request->get_param( 'year' );
		$is_test      = $this->settings->get( 'test_mode', false );
		$receipt_data = $this->reporting->donor_annual_receipt_data( $donor->id, $year, $is_test );

		if ( empty( $receipt_data['transactions'] ) ) {
			return new WP_Error(
				'no_transactions',
				__( 'No completed transactions found for this year.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		$generator = new ReceiptPdfGenerator( $this->settings );
		$pdf       = $generator->generate_annual( $donor, $year, $receipt_data );

		$filename = sanitize_file_name(
			sprintf( '%s-receipt-%d.pdf', ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ), $year )
		);

		/**
		 * Filters the receipt PDF filename.
		 *
		 * @param string $filename     Default filename.
		 * @param Donor  $donor        Donor model.
		 * @param int    $year         Calendar year.
		 * @param array  $receipt_data Receipt data.
		 */
		$filename = apply_filters( 'mission_receipt_filename', $filename, $donor, $year, $receipt_data );

		$this->stream_pdf( $pdf, $filename );

		return null;
	}

	/**
	 * GET /donor-dashboard/transactions/{id}/receipt
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|null
	 */
	public function get_transaction_receipt_pdf( WP_REST_Request $request ): WP_Error|null {
		$donor = $this->resolve_donor();

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$transaction = Transaction::find( (int) $request->get_param( 'id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'mission' ),
				[ 'status' => 404 ]
			);
		}

		if ( $transaction->donor_id !== $donor->id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this transaction.', 'mission' ),
				[ 'status' => 403 ]
			);
		}

		if ( 'completed' !== $transaction->status ) {
			return new WP_Error(
				'transaction_not_completed',
				__( 'Receipts are only available for completed transactions.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		$campaign = $transaction->campaign();

		$transaction_data = [
			'id'              => $transaction->id,
			'amount'          => $transaction->amount,
			'currency'        => $transaction->currency,
			'campaign_name'   => $campaign?->title ?? __( 'General Fund', 'mission' ),
			'payment_gateway' => $transaction->payment_gateway,
			'date_completed'  => $transaction->date_completed,
		];

		$generator = new ReceiptPdfGenerator( $this->settings );
		$pdf       = $generator->generate_single( $donor, $transaction_data );

		$filename = sanitize_file_name(
			sprintf( '%s-receipt-%d.pdf', ( new \Mission\Settings\SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) ), $transaction->id )
		);

		/** This filter is documented in TransactionsEndpoint::get_receipt_pdf(). */
		$filename = apply_filters( 'mission_receipt_filename', $filename, $donor, null, $transaction_data );

		$this->stream_pdf( $pdf, $filename );

		return null;
	}

	/**
	 * Stream a PDF to the browser and exit.
	 *
	 * @param string $pdf      Raw PDF binary content.
	 * @param string $filename Download filename.
	 * @return never
	 */
	private function stream_pdf( string $pdf, string $filename ): never {
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Cache-Control: private, max-age=0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF content.
		echo $pdf;
		exit;
	}

	/**
	 * Get parameters for the transactions endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_transaction_params(): array {
		return [
			'page'        => [
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page'    => [
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
			'year'        => [
				'type'              => 'integer',
				'minimum'           => 2000,
				'sanitize_callback' => 'absint',
			],
			'campaign_id' => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'type'        => [
				'type' => 'string',
				'enum' => [ 'one_time', 'recurring', 'weekly', 'monthly', 'quarterly', 'annually' ],
			],
		];
	}
}
