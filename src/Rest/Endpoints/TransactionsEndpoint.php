<?php
/**
 * REST endpoint for transactions.
 *
 * @package Mission
 */

namespace Mission\Rest\Endpoints;

use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Receipts\ReceiptPdfGenerator;
use Mission\Reporting\ReportingService;
use Mission\Rest\RestModule;
use Mission\Settings\SettingsService;
use Mission\Tip\TipCalculator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Transactions endpoint class.
 */
class TransactionsEndpoint {

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
			'/transactions',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_transactions' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_collection_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/transactions',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_transaction' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_create_params(),
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/transactions/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_transaction' ],
				'permission_callback' => [ $this, 'check_permission' ],
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
			'/transactions/(?P<id>\d+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_transaction' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id'           => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'is_anonymous' => [
						'type' => 'boolean',
					],
					'status'       => [
						'type'              => 'string',
						'enum'              => [ 'pending', 'completed', 'refunded', 'cancelled', 'failed' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'campaign_id'  => [
						'type' => [ 'integer', 'null' ],
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/transactions/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_transaction' ],
				'permission_callback' => [ $this, 'check_permission' ],
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
			'/transactions/(?P<id>\d+)/refund',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'refund_transaction' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id'     => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'amount' => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			RestModule::NAMESPACE,
			'/transactions/(?P<id>\d+)/receipt-pdf',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download_receipt_pdf' ],
				'permission_callback' => [ $this, 'check_permission' ],
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
			'/transactions/(?P<id>\d+)/resend-receipt',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resend_receipt' ],
				'permission_callback' => [ $this, 'check_permission' ],
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
			'/transactions/summary',
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
				__( 'You do not have permission to view transactions.', 'missionwp-donation-platform' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * GET handler — returns paginated transactions with donor and campaign data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_transactions( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' ) ?? 25;

		$result = $this->reporting->transactions_with_donors(
			[
				'per_page'    => $per_page,
				'page'        => $request->get_param( 'page' ) ?? 1,
				'orderby'     => $request->get_param( 'orderby' ) ?? 'date_created',
				'order'       => $request->get_param( 'order' ) ?? 'DESC',
				'status'      => $request->get_param( 'status' ),
				'campaign_id' => $request->get_param( 'campaign_id' ),
				'donor_id'    => $request->get_param( 'donor_id' ),
				'search'      => $request->get_param( 'search' ),
				'dedication'  => $request->get_param( 'dedication' ),
			]
		);

		$total_pages = $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 0;

		$response = new WP_REST_Response( $result['items'], 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET handler — returns a single transaction with full detail.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transaction( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction = Transaction::find( $request->get_param( 'id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->prepare_transaction_detail( $transaction ), 200 );
	}

	/**
	 * GET handler — returns aggregate transaction stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary(): WP_REST_Response {
		$currency = strtolower( get_option( 'mission_currency', 'usd' ) );

		return new WP_REST_Response( $this->reporting->transaction_summary( $currency ), 200 );
	}

	/**
	 * PATCH handler — updates a transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_transaction( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction = Transaction::find( $request->get_param( 'id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		if ( $request->has_param( 'is_anonymous' ) ) {
			$transaction->is_anonymous = (bool) $request->get_param( 'is_anonymous' );
		}

		if ( $request->has_param( 'status' ) ) {
			$transaction->status = $request->get_param( 'status' );
		}

		if ( $request->has_param( 'campaign_id' ) ) {
			$campaign_id              = $request->get_param( 'campaign_id' );
			$transaction->campaign_id = $campaign_id ? (int) $campaign_id : null;
		}

		$transaction->save();

		return new WP_REST_Response( $this->prepare_transaction_detail( $transaction ), 200 );
	}

	/**
	 * DELETE handler — deletes a transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_transaction( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction = Transaction::find( $request->get_param( 'id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'transaction_not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$transaction->delete();

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * POST handler — creates a manual donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_transaction( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = sanitize_email( $request->get_param( 'donor_email' ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		// Upsert donor.
		$donor = Donor::find_by_email( $email );

		if ( ! $donor ) {
			$donor = new Donor(
				[
					'email'      => $email,
					'first_name' => $request->get_param( 'donor_first_name' ),
					'last_name'  => $request->get_param( 'donor_last_name' ) ?? '',
				]
			);
			$donor->save();
		}

		$amount      = (int) $request->get_param( 'donation_amount' );
		$campaign_id = $request->get_param( 'campaign_id' ) ? (int) $request->get_param( 'campaign_id' ) : null;
		$frequency   = $request->get_param( 'frequency' ) ?? 'one_time';
		$now         = current_time( 'mysql', true );

		$transaction = new Transaction(
			[
				'status'          => 'completed',
				'type'            => $frequency,
				'donor_id'        => $donor->id,
				'campaign_id'     => $campaign_id,
				'amount'          => $amount,
				'fee_amount'      => 0,
				'tip_amount'      => 0,
				'total_amount'    => $amount,
				'currency'        => strtolower( get_option( 'mission_currency', 'usd' ) ),
				'payment_gateway' => 'manual',
				'is_test'         => (bool) $request->get_param( 'is_test' ),
				'date_created'    => $this->resolve_date_created( $request->get_param( 'date_created' ), $now ),
				'date_completed'  => $now,
			]
		);

		$transaction->save();

		// Store optional meta.
		$notes = $request->get_param( 'notes' );
		if ( $notes ) {
			$transaction->add_meta( 'notes', $notes );
		}

		if ( ! $request->get_param( 'send_receipt' ) ) {
			$transaction->add_meta( 'skip_receipt', '1' );
		}

		// Store billing address on transaction meta and update donor record.
		$address_fields = [ 'address_1', 'address_2', 'city', 'state', 'zip', 'country' ];

		foreach ( $address_fields as $field ) {
			$value = $request->get_param( $field );
			if ( $value ) {
				$transaction->add_meta( $field, $value );
			}
		}

		// Update donor address if any address fields were provided.
		$has_address = false;
		foreach ( $address_fields as $field ) {
			$value = $request->get_param( $field );
			if ( $value ) {
				$donor->$field = $value;
				$has_address   = true;
			}
		}

		if ( $has_address ) {
			$donor->save();
		}

		return new WP_REST_Response(
			[
				'success'        => true,
				'transaction_id' => $transaction->id,
			],
			201
		);
	}

	/**
	 * If date_created is a bare date (YYYY-MM-DD), append the current time.
	 */
	private function resolve_date_created( ?string $date, string $fallback ): string {
		if ( ! $date ) {
			return $fallback;
		}

		// Bare date like "2026-03-05" — append current time.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date . ' ' . gmdate( 'H:i:s' );
		}

		return $date;
	}

	/**
	 * Prepare a transaction for the detail endpoint.
	 *
	 * @param Transaction $txn Transaction model.
	 * @return array<string, mixed>
	 */
	private function prepare_transaction_detail( Transaction $txn ): array {
		$donor    = $txn->donor();
		$campaign = $txn->campaign();
		$is_test  = (bool) $this->settings->get( 'test_mode' );

		return [
			'id'                      => $txn->id,
			'status'                  => $txn->status,
			'type'                    => $txn->type,
			'amount'                  => $txn->amount,
			'fee_amount'              => $txn->fee_amount,
			'tip_amount'              => $txn->tip_amount,
			'total_amount'            => $txn->total_amount,
			'amount_refunded'         => $txn->amount_refunded,
			'currency'                => $txn->currency,
			'payment_gateway'         => $txn->payment_gateway,
			'gateway_transaction_id'  => $txn->gateway_transaction_id,
			'subscription_id'         => $txn->subscription_id,
			'gateway_subscription_id' => $txn->gateway_subscription_id,
			'gateway_customer_id'     => $txn->gateway_customer_id,
			'source_post_id'          => $txn->source_post_id,
			'source_title'            => $txn->source_post_id ? get_the_title( $txn->source_post_id ) : '',
			'source_url'              => $txn->source_post_id ? get_permalink( $txn->source_post_id ) : '',
			'is_anonymous'            => $txn->is_anonymous,
			'is_test'                 => $txn->is_test,
			'donor_ip'                => $txn->donor_ip,
			'date_created'            => $txn->date_created,
			'date_completed'          => $txn->date_completed,
			'date_refunded'           => $txn->date_refunded,
			'net_amount'              => $this->calculate_net_amount( $txn ),
			'processing_fee'          => $this->calculate_processing_fee( $txn ),
			'fee_recovered'           => $txn->fee_amount,
			'adjusted_tip'            => TipCalculator::adjusted_tip( $txn ),
			'donor'                   => $donor ? [
				'id'                => $donor->id,
				'first_name'        => $donor->first_name,
				'last_name'         => $donor->last_name,
				'email'             => $donor->email,
				'gravatar_hash'     => md5( strtolower( trim( $donor->email ) ) ),
				'total_donated'     => $is_test ? $donor->test_total_donated : $donor->total_donated,
				'transaction_count' => $is_test ? $donor->test_transaction_count : $donor->transaction_count,
				'address_1'         => $donor->address_1,
				'address_2'         => $donor->address_2,
				'city'              => $donor->city,
				'state'             => $donor->state,
				'zip'               => $donor->zip,
				'country'           => $donor->country,
			] : null,
			'campaign'                => $campaign ? [
				'id'    => $campaign->id,
				'title' => $campaign->title,
			] : null,
			'tribute'                 => $this->prepare_tribute( $txn ),
			'billing_address'         => [
				'address_1' => $txn->get_meta( 'address_1' ),
				'address_2' => $txn->get_meta( 'address_2' ),
				'city'      => $txn->get_meta( 'city' ),
				'state'     => $txn->get_meta( 'state' ),
				'zip'       => $txn->get_meta( 'zip' ),
				'country'   => $txn->get_meta( 'country' ),
			],
			'meta'                    => $txn->get_all_meta(),
		];
	}

	/**
	 * Prepare tribute data for the response.
	 *
	 * @param Transaction $txn Transaction model.
	 * @return array<string, mixed>|null
	 */
	private function prepare_tribute( Transaction $txn ): ?array {
		$tribute = $txn->tribute();

		if ( ! $tribute ) {
			return null;
		}

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
	 * Calculate the processing fee for display.
	 *
	 * With fee recovery: total deductions from the gross charge (Stripe fee + tip),
	 * balanced by the fee_recovered line.
	 * Without fee recovery: estimated Stripe fee on the donation amount.
	 *
	 * @param Transaction $txn Transaction model.
	 * @return int Processing fee in minor units.
	 */
	private function calculate_processing_fee( Transaction $txn ): int {
		if ( $txn->fee_amount > 0 ) {
			return $txn->fee_amount;
		}

		[ $fee_rate, $fee_fixed ] = TipCalculator::get_fee_params( $txn );

		return (int) round( $txn->amount * $fee_rate + $fee_fixed );
	}

	/**
	 * Calculate the net amount the nonprofit receives.
	 *
	 * With fee recovery the donor covers all fees, so net = donation amount.
	 * Without fee recovery the nonprofit bears the Stripe fee on their portion.
	 *
	 * @param Transaction $txn Transaction model.
	 * @return int Net amount in minor units.
	 */
	private function calculate_net_amount( Transaction $txn ): int {
		if ( $txn->fee_amount > 0 ) {
			return $txn->amount;
		}

		[ $fee_rate, $fee_fixed ] = TipCalculator::get_fee_params( $txn );

		return $txn->amount - (int) round( $txn->amount * $fee_rate + $fee_fixed );
	}

	/**
	 * Get collection query parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_params(): array {
		return [
			'page'        => [
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page'    => [
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
			'orderby'     => [
				'type'              => 'string',
				'default'           => 'date_created',
				'enum'              => [ 'date_created', 'amount' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'order'       => [
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => [ 'ASC', 'DESC' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status'      => [
				'type'              => 'string',
				'enum'              => [ 'pending', 'completed', 'refunded', 'cancelled', 'failed' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'campaign_id' => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'donor_id'    => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'search'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'dedication'  => [
				'type'              => 'string',
				'enum'              => [ 'mail_pending', 'mail_sent', 'email_sent', 'any' ],
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
			'donor_email'      => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
			],
			'donor_first_name' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'donor_last_name'  => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'donation_amount'  => [
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'campaign_id'      => [
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			],
			'frequency'        => [
				'type'              => 'string',
				'default'           => 'one_time',
				'enum'              => [ 'one_time', 'monthly', 'quarterly', 'annually' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_created'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notes'            => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'is_test'          => [
				'type'    => 'boolean',
				'default' => false,
			],
			'send_receipt'     => [
				'type'    => 'boolean',
				'default' => true,
			],
			'address_1'        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'address_2'        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'city'             => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'state'            => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'zip'              => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'country'          => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Issue a refund for a transaction via the Mission API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refund_transaction( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction = Transaction::find( $request->get_param( 'id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'completed' !== $transaction->status ) {
			return new WP_Error(
				'not_refundable',
				__( 'Only completed transactions can be refunded.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $transaction->gateway_transaction_id ) {
			return new WP_Error(
				'no_gateway_id',
				__( 'This transaction has no payment gateway ID and cannot be refunded.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$refund_amount  = (int) $request->get_param( 'amount' );
		$max_refundable = $transaction->total_amount - $transaction->amount_refunded;

		if ( $refund_amount > $max_refundable ) {
			return new WP_Error(
				'amount_too_high',
				__( 'Refund amount exceeds the refundable balance.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$site_token = $this->settings->get( 'stripe_site_token' );

		if ( ! $site_token ) {
			return new WP_Error(
				'not_configured',
				__( 'Stripe is not connected. Please connect Stripe before issuing refunds.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$response = wp_remote_post(
			'https://api.missionwp.com/refund',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $site_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'payment_intent_id' => $transaction->gateway_transaction_id,
						'amount'            => $refund_amount,
						'test_mode'         => $transaction->is_test,
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Mission] Refund API call failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'refund_failed',
				__( 'Failed to process refund. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = $body['error'] ?? __( 'Failed to process refund. Please try again.', 'missionwp-donation-platform' );

			error_log( "[Mission] Refund API returned HTTP {$status_code}: " . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'refund_failed', $message, [ 'status' => $status_code ] );
		}

		// The webhook will update amount_refunded and status, but update
		// locally now so the UI reflects the change immediately.
		$transaction->amount_refunded = $transaction->amount_refunded + $refund_amount;

		if ( $transaction->amount_refunded >= $transaction->total_amount ) {
			$transaction->status        = 'refunded';
			$transaction->date_refunded = current_time( 'mysql', true );
		}

		$transaction->save();

		return new WP_REST_Response( $this->prepare_transaction_detail( $transaction ) );
	}

	/**
	 * Download a receipt PDF for a transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|null
	 */
	public function download_receipt_pdf( WP_REST_Request $request ): ?WP_Error {
		$transaction = Transaction::find( $request->get_param( 'id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$donor = $transaction->donor();

		if ( ! $donor ) {
			return new WP_Error(
				'no_donor',
				__( 'No donor associated with this transaction.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$campaign         = $transaction->campaign();
		$transaction_data = [
			'id'              => $transaction->id,
			'amount'          => $transaction->amount,
			'currency'        => $transaction->currency,
			'campaign_name'   => $campaign?->title ?? __( 'General Fund', 'missionwp-donation-platform' ),
			'payment_gateway' => $transaction->payment_gateway,
			'date_completed'  => $transaction->date_completed ?: $transaction->date_created,
		];

		$generator = new ReceiptPdfGenerator( $this->settings );
		$pdf       = $generator->generate_single( $donor, $transaction_data );

		$filename = sanitize_file_name(
			sprintf( '%s-receipt-%d.pdf', $this->settings->get( 'org_name', get_bloginfo( 'name' ) ), $transaction->id )
		);

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Cache-Control: private, max-age=0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF content.
		echo $pdf;
		exit;
	}

	/**
	 * Resend the donation receipt email for a transaction.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_receipt( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction = Transaction::find( $request->get_param( 'id' ) );

		if ( ! $transaction ) {
			return new WP_Error(
				'not_found',
				__( 'Transaction not found.', 'missionwp-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$donor = $transaction->donor();

		if ( ! $donor?->email ) {
			return new WP_Error(
				'no_email',
				__( 'This donor has no email address on file.', 'missionwp-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		$email_module = \Mission\Plugin::instance()->get_email_module();
		$campaign     = $transaction->campaign();

		$data = [
			'transaction'      => $transaction,
			'donor'            => $donor,
			'amount_formatted' => $email_module->format_amount( $transaction->amount, $transaction->currency ),
			'date_formatted'   => wp_date( get_option( 'date_format' ), strtotime( $transaction->date_completed ?: $transaction->date_created ) ),
			'campaign_name'    => $campaign?->title,
		];

		$subject = sprintf(
			/* translators: %s: formatted donation amount */
			__( 'Thank you for your %s donation', 'missionwp-donation-platform' ),
			$data['amount_formatted'],
		);

		$custom_subject = $email_module->get_custom_subject( 'donation_receipt' );
		if ( $custom_subject ) {
			$subject = $email_module->replace_subject_tags(
				$custom_subject,
				[
					'{donor_name}'   => $donor->first_name ?: __( 'Friend', 'missionwp-donation-platform' ),
					'{amount}'       => $data['amount_formatted'],
					'{campaign}'     => $data['campaign_name'] ?? '',
					'{date}'         => $data['date_formatted'],
					'{organization}' => $this->settings->get( 'org_name', get_bloginfo( 'name' ) ),
					'{receipt_id}'   => (string) $transaction->id,
				]
			);
		}

		$html = $email_module->render_template( 'donation-receipt', array_merge( $data, [ 'subject' => $subject ] ) );
		$sent = $email_module->send( $donor->email, $subject, $html );

		if ( ! $sent ) {
			return new WP_Error(
				'send_failed',
				__( 'Failed to send receipt email. Please try again.', 'missionwp-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [ 'sent_to' => $donor->email ] );
	}
}
