<?php
/**
 * Export service — coordinates data export across models and formatters.
 *
 * @package MissionDP
 */

namespace MissionDP\Export;

use MissionDP\Currency\Currency;
use MissionDP\Export\Formatters\CsvFormatter;
use MissionDP\Export\Formatters\FormatterInterface;
use MissionDP\Export\Formatters\JsonFormatter;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Model;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Models\Tribute;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Central export coordinator.
 */
class ExportService {

	/**
	 * Valid export types.
	 */
	private const TYPES = [ 'donors', 'transactions', 'campaigns', 'subscriptions', 'tributes' ];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service instance.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {}

	/**
	 * Check if a type string is valid.
	 *
	 * @param string $type Data type.
	 */
	public function is_valid_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Get the full column definitions for an export type.
	 *
	 * Each column has: key (data key), label (human header), type (string|int|bool|amount|date).
	 *
	 * @param string $type Data type.
	 *
	 * @return array<int, array{key: string, label: string, type: string}>
	 */
	public function get_columns( string $type ): array {
		$is_test = $this->is_test_mode();

		$columns = match ( $type ) {
			'donors'        => $this->donor_columns( $is_test ),
			'transactions'  => $this->transaction_columns(),
			'campaigns'     => $this->campaign_columns( $is_test ),
			'subscriptions' => $this->subscription_columns(),
			'tributes'      => $this->tribute_columns(),
			default         => [],
		};

		/**
		 * Filter the export columns for a data type.
		 *
		 * @param array  $columns Column definitions.
		 */
		return apply_filters( "missiondp_export_{$type}_columns", $columns );
	}

	/**
	 * Get preview column definitions (human-readable subset for the UI preview table).
	 *
	 * Preview columns may include virtual keys (e.g. donor_name) that are resolved from relationships.
	 *
	 * @param string $type Data type.
	 *
	 * @return array<int, array{key: string, label: string, type: string}>
	 */
	public function get_preview_columns( string $type ): array {
		$is_test = $this->is_test_mode();

		$columns = match ( $type ) {
			'donors' => [
				[
					'key'   => 'name',
					'label' => __( 'Name', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'email',
					'label' => __( 'Email', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => $is_test ? 'test_total_donated' : 'total_donated',
					'label' => __( 'Total Given', 'mission-donation-platform' ),
					'type'  => 'amount',
				],
				[
					'key'   => $is_test ? 'test_transaction_count' : 'transaction_count',
					'label' => __( 'Donations', 'mission-donation-platform' ),
					'type'  => 'int',
				],
				[
					'key'   => $is_test ? 'test_first_transaction' : 'first_transaction',
					'label' => __( 'First Donation', 'mission-donation-platform' ),
					'type'  => 'date',
				],
			],
			'transactions' => [
				[
					'key'   => 'id',
					'label' => __( 'ID', 'mission-donation-platform' ),
					'type'  => 'int',
				],
				[
					'key'   => 'donor_name',
					'label' => __( 'Donor', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'amount',
					'label' => __( 'Amount', 'mission-donation-platform' ),
					'type'  => 'amount',
				],
				[
					'key'   => 'campaign_title',
					'label' => __( 'Campaign', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'date_created',
					'label' => __( 'Date', 'mission-donation-platform' ),
					'type'  => 'date',
				],
				[
					'key'   => 'status',
					'label' => __( 'Status', 'mission-donation-platform' ),
					'type'  => 'string',
				],
			],
			'campaigns' => [
				[
					'key'   => 'title',
					'label' => __( 'Name', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'goal_amount',
					'label' => __( 'Goal', 'mission-donation-platform' ),
					'type'  => 'amount',
				],
				[
					'key'   => $is_test ? 'test_total_raised' : 'total_raised',
					'label' => __( 'Raised', 'mission-donation-platform' ),
					'type'  => 'amount',
				],
				[
					'key'   => $is_test ? 'test_donor_count' : 'donor_count',
					'label' => __( 'Donors', 'mission-donation-platform' ),
					'type'  => 'int',
				],
				[
					'key'   => 'status',
					'label' => __( 'Status', 'mission-donation-platform' ),
					'type'  => 'string',
				],
			],
			'subscriptions' => [
				[
					'key'   => 'id',
					'label' => __( 'ID', 'mission-donation-platform' ),
					'type'  => 'int',
				],
				[
					'key'   => 'donor_name',
					'label' => __( 'Donor', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'amount',
					'label' => __( 'Amount', 'mission-donation-platform' ),
					'type'  => 'amount',
				],
				[
					'key'   => 'frequency',
					'label' => __( 'Frequency', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'status',
					'label' => __( 'Status', 'mission-donation-platform' ),
					'type'  => 'string',
				],
			],
			'tributes' => [
				[
					'key'   => 'transaction_id',
					'label' => __( 'Transaction', 'mission-donation-platform' ),
					'type'  => 'int',
				],
				[
					'key'   => 'donor_name',
					'label' => __( 'Donor', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'tribute_type',
					'label' => __( 'Type', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'honoree_name',
					'label' => __( 'Honoree', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'notify_method',
					'label' => __( 'Notify Method', 'mission-donation-platform' ),
					'type'  => 'string',
				],
				[
					'key'   => 'date_created',
					'label' => __( 'Date', 'mission-donation-platform' ),
					'type'  => 'date',
				],
			],
			default => [],
		};

		/**
		 * Filter the preview columns for an export type.
		 *
		 * @param array $columns Preview column definitions.
		 */
		return apply_filters( "missiondp_export_{$type}_preview_columns", $columns );
	}

	/**
	 * Count records for a given type and filter params.
	 *
	 * @param string               $type   Data type.
	 * @param array<string, mixed> $params Request parameters (date_from, date_to).
	 *
	 * @return int
	 */
	public function get_count( string $type, array $params ): int {
		$model_class = $this->get_model_class( $type );
		$query_args  = $this->get_query_args( $type, $params );

		return $model_class::count( $query_args );
	}

	/**
	 * Get preview data for the frontend table.
	 *
	 * @param string               $type   Data type.
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return array{columns: string[], rows: array<int, array<int, string>>}
	 */
	public function get_preview( string $type, array $params ): array {
		$model_class            = $this->get_model_class( $type );
		$query_args             = $this->get_query_args( $type, $params );
		$query_args['per_page'] = 5;
		$query_args['page']     = 1;

		$models          = $model_class::query( $query_args );
		$preview_columns = $this->get_preview_columns( $type );
		$currency        = strtoupper( $this->settings->get( 'currency', 'USD' ) );

		// Batch-load related models for relationship columns.
		$related = $this->resolve_relationships( $type, $models );

		$columns = array_column( $preview_columns, 'label' );
		$rows    = [];

		foreach ( $models as $model ) {
			$row = [];

			foreach ( $preview_columns as $col ) {
				$raw   = $this->get_preview_value( $col['key'], $model, $type, $related );
				$row[] = $this->format_preview_value( $raw, $col['type'], $model->currency ?? $currency );
			}

			$rows[] = $row;
		}

		return [
			'columns' => $columns,
			'rows'    => $rows,
		];
	}

	/**
	 * Get all records formatted for export.
	 *
	 * Returns column definitions (including meta) and row data arrays.
	 *
	 * @param string               $type   Data type.
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return array{columns: array, rows: array}
	 */
	public function get_records( string $type, array $params ): array {
		$model_class = $this->get_model_class( $type );
		$query_args  = $this->get_query_args( $type, $params );
		$models      = $model_class::query( $query_args );
		$columns     = $this->get_columns( $type );
		$currency    = strtoupper( $this->settings->get( 'currency', 'USD' ) );

		// Discover meta keys across all records.
		$meta_keys = $this->collect_meta_keys( $type, $models );

		// Append meta columns.
		$meta_columns = [];
		foreach ( $meta_keys as $key ) {
			$meta_columns[] = [
				'key'   => "meta:{$key}",
				'label' => "meta:{$key}",
				'type'  => 'string',
			];
		}

		$all_columns = array_merge( $columns, $meta_columns );

		// Build rows.
		$rows = [];
		foreach ( $models as $model ) {
			$row = [];

			foreach ( $columns as $col ) {
				$row[ $col['key'] ] = $model->{$col['key']} ?? null;
			}

			// Add currency hint for CSV formatter.
			$row['_currency'] = $model->currency ?? $currency;

			// Append meta values.
			if ( ! empty( $meta_keys ) ) {
				$meta = $model->get_all_meta();
				foreach ( $meta_keys as $key ) {
					$row[ "meta:{$key}" ] = $meta[ $key ] ?? null;
				}
			}

			/**
			 * Filter a single export row before it is included.
			 *
			 * @param array $row   Row data keyed by column key.
			 * @param Model $model The source model instance.
			 */
			$rows[] = apply_filters( "missiondp_export_{$type}_row", $row, $model );
		}

		return [
			'columns' => $all_columns,
			'rows'    => $rows,
		];
	}

	/**
	 * Get a formatter instance for the given format string.
	 *
	 * @param string $format Format identifier (csv, json).
	 *
	 * @return FormatterInterface|null
	 */
	public function get_formatter( string $format ): ?FormatterInterface {
		$formatters = [
			'csv'  => new CsvFormatter(),
			'json' => new JsonFormatter(),
		];

		/**
		 * Filter available export formatters.
		 *
		 * Allows plugins to register additional formats (e.g., XML, Excel).
		 *
		 * @param array<string, FormatterInterface> $formatters Keyed by format identifier.
		 */
		$formatters = apply_filters( 'missiondp_export_formats', $formatters );

		return $formatters[ $format ] ?? null;
	}

	/**
	 * Build query args from request params for Model::query() / Model::count().
	 *
	 * @param string               $type   Data type.
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_query_args( string $type, array $params ): array {
		$is_test    = $this->is_test_mode();
		$query_args = [];

		// Single-record export.
		if ( ! empty( $params['id'] ) ) {
			$query_args['id'] = (int) $params['id'];
		}

		// Test mode filtering.
		if ( in_array( $type, [ 'transactions', 'subscriptions' ], true ) ) {
			$query_args['is_test'] = $is_test;
		}

		if ( 'donors' === $type ) {
			$query_args['has_transactions'] = $is_test ? 'test_transaction_count' : 'transaction_count';
		}

		// Date range.
		if ( ! empty( $params['date_from'] ) ) {
			$query_args['date_after'] = sanitize_text_field( $params['date_from'] );
		}

		if ( ! empty( $params['date_to'] ) ) {
			$query_args['date_before'] = sanitize_text_field( $params['date_to'] );
		}

		// Tribute-specific filters.
		if ( 'tributes' === $type ) {
			if ( ! empty( $params['notify_method'] ) ) {
				$query_args['notify_method'] = sanitize_text_field( $params['notify_method'] );
			}
			if ( ! empty( $params['notification_status'] ) ) {
				$query_args['notification_status'] = sanitize_text_field( $params['notification_status'] );
			}
		}

		/**
		 * Filter query args before fetching export data.
		 *
		 * @param array $query_args The query arguments for Model::query().
		 * @param array $params     The original request parameters.
		 */
		return apply_filters( "missiondp_export_{$type}_query_args", $query_args, $params );
	}

	/**
	 * Get the model class for a data type.
	 *
	 * @param string $type Data type.
	 *
	 * @return class-string<Model>
	 */
	private function get_model_class( string $type ): string {
		return match ( $type ) {
			'donors'        => Donor::class,
			'transactions'  => Transaction::class,
			'campaigns'     => Campaign::class,
			'subscriptions' => Subscription::class,
			'tributes'      => Tribute::class,
		};
	}

	/**
	 * Check if test mode is active.
	 */
	private function is_test_mode(): bool {
		return (bool) $this->settings->get( 'test_mode' );
	}

	// ------------------------------------------------------------------
	// Column definitions per type.
	// ------------------------------------------------------------------

	/**
	 * Donor export columns.
	 *
	 * @param bool $is_test Whether test mode is active.
	 *
	 * @return array
	 */
	private function donor_columns( bool $is_test ): array {
		$columns = [
			[
				'key'   => 'id',
				'label' => 'ID',
				'type'  => 'int',
			],
			[
				'key'   => 'user_id',
				'label' => 'User ID',
				'type'  => 'int',
			],
			[
				'key'   => 'email',
				'label' => 'Email',
				'type'  => 'string',
			],
			[
				'key'   => 'first_name',
				'label' => 'First Name',
				'type'  => 'string',
			],
			[
				'key'   => 'last_name',
				'label' => 'Last Name',
				'type'  => 'string',
			],
			[
				'key'   => 'phone',
				'label' => 'Phone',
				'type'  => 'string',
			],
			[
				'key'   => 'address_1',
				'label' => 'Address 1',
				'type'  => 'string',
			],
			[
				'key'   => 'address_2',
				'label' => 'Address 2',
				'type'  => 'string',
			],
			[
				'key'   => 'city',
				'label' => 'City',
				'type'  => 'string',
			],
			[
				'key'   => 'state',
				'label' => 'State',
				'type'  => 'string',
			],
			[
				'key'   => 'zip',
				'label' => 'ZIP',
				'type'  => 'string',
			],
			[
				'key'   => 'country',
				'label' => 'Country',
				'type'  => 'string',
			],
		];

		if ( $is_test ) {
			$columns[] = [
				'key'   => 'test_total_donated',
				'label' => 'Total Donated',
				'type'  => 'amount',
			];
			$columns[] = [
				'key'   => 'test_total_tip',
				'label' => 'Total Tip',
				'type'  => 'amount',
			];
			$columns[] = [
				'key'   => 'test_transaction_count',
				'label' => 'Transaction Count',
				'type'  => 'int',
			];
			$columns[] = [
				'key'   => 'test_first_transaction',
				'label' => 'First Transaction',
				'type'  => 'date',
			];
			$columns[] = [
				'key'   => 'test_last_transaction',
				'label' => 'Last Transaction',
				'type'  => 'date',
			];
		} else {
			$columns[] = [
				'key'   => 'total_donated',
				'label' => 'Total Donated',
				'type'  => 'amount',
			];
			$columns[] = [
				'key'   => 'total_tip',
				'label' => 'Total Tip',
				'type'  => 'amount',
			];
			$columns[] = [
				'key'   => 'transaction_count',
				'label' => 'Transaction Count',
				'type'  => 'int',
			];
			$columns[] = [
				'key'   => 'first_transaction',
				'label' => 'First Transaction',
				'type'  => 'date',
			];
			$columns[] = [
				'key'   => 'last_transaction',
				'label' => 'Last Transaction',
				'type'  => 'date',
			];
		}

		$columns[] = [
			'key'   => 'date_created',
			'label' => 'Date Created',
			'type'  => 'date',
		];
		$columns[] = [
			'key'   => 'date_modified',
			'label' => 'Date Modified',
			'type'  => 'date',
		];

		return $columns;
	}

	/**
	 * Transaction export columns.
	 *
	 * @return array
	 */
	private function transaction_columns(): array {
		return [
			[
				'key'   => 'id',
				'label' => 'ID',
				'type'  => 'int',
			],
			[
				'key'   => 'status',
				'label' => 'Status',
				'type'  => 'string',
			],
			[
				'key'   => 'type',
				'label' => 'Type',
				'type'  => 'string',
			],
			[
				'key'   => 'donor_id',
				'label' => 'Donor ID',
				'type'  => 'int',
			],
			[
				'key'   => 'subscription_id',
				'label' => 'Subscription ID',
				'type'  => 'int',
			],
			[
				'key'   => 'parent_id',
				'label' => 'Parent ID',
				'type'  => 'int',
			],
			[
				'key'   => 'source_post_id',
				'label' => 'Source Post ID',
				'type'  => 'int',
			],
			[
				'key'   => 'campaign_id',
				'label' => 'Campaign ID',
				'type'  => 'int',
			],
			[
				'key'   => 'amount',
				'label' => 'Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'fee_amount',
				'label' => 'Fee Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'tip_amount',
				'label' => 'Tip Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'total_amount',
				'label' => 'Total Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'amount_refunded',
				'label' => 'Amount Refunded',
				'type'  => 'amount',
			],
			[
				'key'   => 'currency',
				'label' => 'Currency',
				'type'  => 'string',
			],
			[
				'key'   => 'payment_gateway',
				'label' => 'Payment Gateway',
				'type'  => 'string',
			],
			[
				'key'   => 'gateway_transaction_id',
				'label' => 'Gateway Transaction ID',
				'type'  => 'string',
			],
			[
				'key'   => 'gateway_subscription_id',
				'label' => 'Gateway Subscription ID',
				'type'  => 'string',
			],
			[
				'key'   => 'gateway_customer_id',
				'label' => 'Gateway Customer ID',
				'type'  => 'string',
			],
			[
				'key'   => 'is_anonymous',
				'label' => 'Anonymous',
				'type'  => 'bool',
			],
			[
				'key'   => 'is_test',
				'label' => 'Test',
				'type'  => 'bool',
			],
			[
				'key'   => 'donor_ip',
				'label' => 'Donor IP',
				'type'  => 'string',
			],
			[
				'key'   => 'date_created',
				'label' => 'Date Created',
				'type'  => 'date',
			],
			[
				'key'   => 'date_completed',
				'label' => 'Date Completed',
				'type'  => 'date',
			],
			[
				'key'   => 'date_refunded',
				'label' => 'Date Refunded',
				'type'  => 'date',
			],
			[
				'key'   => 'date_modified',
				'label' => 'Date Modified',
				'type'  => 'date',
			],
		];
	}

	/**
	 * Campaign export columns.
	 *
	 * @param bool $is_test Whether test mode is active.
	 *
	 * @return array
	 */
	private function campaign_columns( bool $is_test ): array {
		$columns = [
			[
				'key'   => 'id',
				'label' => 'ID',
				'type'  => 'int',
			],
			[
				'key'   => 'post_id',
				'label' => 'Post ID',
				'type'  => 'int',
			],
			[
				'key'   => 'title',
				'label' => 'Title',
				'type'  => 'string',
			],
			[
				'key'   => 'description',
				'label' => 'Description',
				'type'  => 'string',
			],
			[
				'key'   => 'goal_amount',
				'label' => 'Goal Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'goal_type',
				'label' => 'Goal Type',
				'type'  => 'string',
			],
		];

		if ( $is_test ) {
			$columns[] = [
				'key'   => 'test_total_raised',
				'label' => 'Total Raised',
				'type'  => 'amount',
			];
			$columns[] = [
				'key'   => 'test_transaction_count',
				'label' => 'Transaction Count',
				'type'  => 'int',
			];
			$columns[] = [
				'key'   => 'test_donor_count',
				'label' => 'Donor Count',
				'type'  => 'int',
			];
		} else {
			$columns[] = [
				'key'   => 'total_raised',
				'label' => 'Total Raised',
				'type'  => 'amount',
			];
			$columns[] = [
				'key'   => 'transaction_count',
				'label' => 'Transaction Count',
				'type'  => 'int',
			];
			$columns[] = [
				'key'   => 'donor_count',
				'label' => 'Donor Count',
				'type'  => 'int',
			];
		}

		$columns[] = [
			'key'   => 'currency',
			'label' => 'Currency',
			'type'  => 'string',
		];
		$columns[] = [
			'key'   => 'show_in_listings',
			'label' => 'Show in Listings',
			'type'  => 'bool',
		];
		$columns[] = [
			'key'   => 'status',
			'label' => 'Status',
			'type'  => 'string',
		];
		$columns[] = [
			'key'   => 'date_start',
			'label' => 'Date Start',
			'type'  => 'date',
		];
		$columns[] = [
			'key'   => 'date_end',
			'label' => 'Date End',
			'type'  => 'date',
		];
		$columns[] = [
			'key'   => 'date_created',
			'label' => 'Date Created',
			'type'  => 'date',
		];
		$columns[] = [
			'key'   => 'date_modified',
			'label' => 'Date Modified',
			'type'  => 'date',
		];

		return $columns;
	}

	/**
	 * Subscription export columns.
	 *
	 * @return array
	 */
	private function subscription_columns(): array {
		return [
			[
				'key'   => 'id',
				'label' => 'ID',
				'type'  => 'int',
			],
			[
				'key'   => 'status',
				'label' => 'Status',
				'type'  => 'string',
			],
			[
				'key'   => 'donor_id',
				'label' => 'Donor ID',
				'type'  => 'int',
			],
			[
				'key'   => 'source_post_id',
				'label' => 'Source Post ID',
				'type'  => 'int',
			],
			[
				'key'   => 'campaign_id',
				'label' => 'Campaign ID',
				'type'  => 'int',
			],
			[
				'key'   => 'initial_transaction_id',
				'label' => 'Initial Transaction ID',
				'type'  => 'int',
			],
			[
				'key'   => 'amount',
				'label' => 'Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'fee_amount',
				'label' => 'Fee Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'tip_amount',
				'label' => 'Tip Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'total_amount',
				'label' => 'Total Amount',
				'type'  => 'amount',
			],
			[
				'key'   => 'currency',
				'label' => 'Currency',
				'type'  => 'string',
			],
			[
				'key'   => 'frequency',
				'label' => 'Frequency',
				'type'  => 'string',
			],
			[
				'key'   => 'payment_gateway',
				'label' => 'Payment Gateway',
				'type'  => 'string',
			],
			[
				'key'   => 'gateway_subscription_id',
				'label' => 'Gateway Subscription ID',
				'type'  => 'string',
			],
			[
				'key'   => 'gateway_customer_id',
				'label' => 'Gateway Customer ID',
				'type'  => 'string',
			],
			[
				'key'   => 'renewal_count',
				'label' => 'Renewal Count',
				'type'  => 'int',
			],
			[
				'key'   => 'total_renewed',
				'label' => 'Total Renewed',
				'type'  => 'amount',
			],
			[
				'key'   => 'is_test',
				'label' => 'Test',
				'type'  => 'bool',
			],
			[
				'key'   => 'date_created',
				'label' => 'Date Created',
				'type'  => 'date',
			],
			[
				'key'   => 'date_next_renewal',
				'label' => 'Date Next Renewal',
				'type'  => 'date',
			],
			[
				'key'   => 'date_cancelled',
				'label' => 'Date Cancelled',
				'type'  => 'date',
			],
			[
				'key'   => 'date_modified',
				'label' => 'Date Modified',
				'type'  => 'date',
			],
		];
	}

	/**
	 * Column definitions for tribute (dedication) export.
	 *
	 * @return array<int, array{key: string, label: string, type: string}>
	 */
	private function tribute_columns(): array {
		return [
			[
				'key'   => 'id',
				'label' => 'ID',
				'type'  => 'int',
			],
			[
				'key'   => 'transaction_id',
				'label' => 'Transaction ID',
				'type'  => 'int',
			],
			[
				'key'   => 'tribute_type',
				'label' => 'Type',
				'type'  => 'string',
			],
			[
				'key'   => 'honoree_name',
				'label' => 'Honoree Name',
				'type'  => 'string',
			],
			[
				'key'   => 'message',
				'label' => 'Message',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_method',
				'label' => 'Notify Method',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_name',
				'label' => 'Notify Name',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_email',
				'label' => 'Notify Email',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_address_1',
				'label' => 'Notify Address',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_city',
				'label' => 'Notify City',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_state',
				'label' => 'Notify State',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_zip',
				'label' => 'Notify Zip',
				'type'  => 'string',
			],
			[
				'key'   => 'notify_country',
				'label' => 'Notify Country',
				'type'  => 'string',
			],
			[
				'key'   => 'notification_sent_at',
				'label' => 'Notification Sent',
				'type'  => 'date',
			],
			[
				'key'   => 'date_created',
				'label' => 'Date Created',
				'type'  => 'date',
			],
		];
	}

	// ------------------------------------------------------------------
	// Preview helpers.
	// ------------------------------------------------------------------

	/**
	 * Get a raw value for a preview column from a model.
	 *
	 * Handles virtual keys like 'name', 'donor_name', 'campaign_title'.
	 *
	 * @param string              $key     Column key.
	 * @param Model               $model   The source model.
	 * @param string              $type    Data type.
	 * @param array<string, array> $related Batch-loaded related models.
	 *
	 * @return mixed
	 */
	private function get_preview_value( string $key, Model $model, string $type, array $related ): mixed {
		// Virtual keys.
		if ( 'name' === $key && 'donors' === $type ) {
			return trim( $model->first_name . ' ' . $model->last_name );
		}

		if ( 'donor_name' === $key && 'tributes' === $type ) {
			$txn   = $related['transactions'][ $model->transaction_id ] ?? null;
			$donor = $txn ? ( $related['donors'][ $txn->donor_id ] ?? null ) : null;
			return $donor ? trim( $donor->first_name . ' ' . $donor->last_name ) : '';
		}

		if ( 'donor_name' === $key ) {
			$donor = $related['donors'][ $model->donor_id ] ?? null;
			return $donor ? trim( $donor->first_name . ' ' . $donor->last_name ) : '';
		}

		if ( 'campaign_title' === $key ) {
			$campaign = $related['campaigns'][ $model->campaign_id ] ?? null;
			return $campaign?->title ?? '';
		}

		return $model->{$key} ?? null;
	}

	/**
	 * Format a preview value for human display.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $col_type Column type.
	 * @param string $currency Currency code.
	 *
	 * @return string
	 */
	private function format_preview_value( mixed $value, string $col_type, string $currency ): string {
		if ( null === $value || '' === $value ) {
			return "\u{2014}";
		}

		return match ( $col_type ) {
			'amount' => Currency::format_amount( (int) $value, $currency ),
			'date'   => date_i18n( 'M j, Y', strtotime( (string) $value ) ),
			'bool'   => $value ? __( 'Yes', 'mission-donation-platform' ) : __( 'No', 'mission-donation-platform' ),
			'int'    => number_format_i18n( (int) $value ),
			default  => (string) $value,
		};
	}

	/**
	 * Batch-load related models (donors, campaigns) to avoid N+1 queries in preview.
	 *
	 * @param string  $type   Data type.
	 * @param Model[] $models The records being previewed.
	 *
	 * @return array<string, array<int, Model>>
	 */
	private function resolve_relationships( string $type, array $models ): array {
		$related = [];

		if ( in_array( $type, [ 'transactions', 'subscriptions' ], true ) ) {
			$donor_ids = array_filter( array_unique( array_map( fn( $m ) => $m->donor_id ?? 0, $models ) ) );

			if ( ! empty( $donor_ids ) ) {
				$related['donors'] = Donor::find_many( $donor_ids );
			}
		}

		if ( 'transactions' === $type ) {
			$campaign_ids = array_filter( array_unique( array_map( fn( $m ) => $m->campaign_id ?? 0, $models ) ) );

			if ( ! empty( $campaign_ids ) ) {
				$related['campaigns'] = Campaign::find_many( $campaign_ids );
			}
		}

		if ( 'tributes' === $type ) {
			$txn_ids = array_filter( array_unique( array_map( fn( $m ) => $m->transaction_id ?? 0, $models ) ) );

			if ( ! empty( $txn_ids ) ) {
				$related['transactions'] = Transaction::find_many( $txn_ids );

				$donor_ids = array_filter( array_unique( array_map( fn( $t ) => $t->donor_id ?? 0, $related['transactions'] ) ) );
				if ( ! empty( $donor_ids ) ) {
					$related['donors'] = Donor::find_many( $donor_ids );
				}
			}
		}

		return $related;
	}

	// ------------------------------------------------------------------
	// Meta helpers.
	// ------------------------------------------------------------------

	/**
	 * Collect all unique meta keys across a set of models.
	 *
	 * @param string  $type   Data type.
	 * @param Model[] $models The records.
	 *
	 * @return string[]
	 */
	private function collect_meta_keys( string $type, array $models ): array {
		$keys = [];

		foreach ( $models as $model ) {
			if ( ! method_exists( $model, 'get_all_meta' ) ) {
				return [];
			}

			foreach ( array_keys( $model->get_all_meta() ) as $key ) {
				$keys[ $key ] = true;
			}
		}

		$meta_keys = array_keys( $keys );
		sort( $meta_keys );

		/**
		 * Filter which meta keys are included in the export.
		 *
		 * @param string[] $meta_keys Sorted meta key names.
		 */
		return apply_filters( "missiondp_export_{$type}_meta_keys", $meta_keys );
	}
}
