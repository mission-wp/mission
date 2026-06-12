<?php
/**
 * Tests for the ReceiptPdfGenerator.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Receipts;

use MissionDP\Models\Donor;
use MissionDP\Receipts\ReceiptPdfGenerator;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Receipt PDF generator test class.
 *
 * PDF binaries are not inspected beyond the %PDF signature; all content
 * assertions run against the intermediate HTML captured (and swapped for a
 * minimal document, to keep Dompdf fast) via the mission_receipt_html filter.
 */
class ReceiptPdfGeneratorTest extends WP_UnitTestCase {

	/**
	 * Generator under test.
	 *
	 * @var ReceiptPdfGenerator
	 */
	private ReceiptPdfGenerator $generator;

	/**
	 * Filters added during the test, removed in tear_down.
	 *
	 * @var array<int, array{string, callable}>
	 */
	private array $filters_to_remove = [];

	/**
	 * Set up a fresh generator before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->generator = new ReceiptPdfGenerator( new SettingsService() );
	}

	/**
	 * Clean up settings and filters after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );

		foreach ( $this->filters_to_remove as [ $hook, $callback ] ) {
			remove_filter( $hook, $callback );
		}
		$this->filters_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Add a filter that is automatically removed in tear_down.
	 *
	 * @param string   $hook     Filter name.
	 * @param callable $callback Filter callback.
	 * @param int      $args     Number of accepted arguments.
	 * @return void
	 */
	private function add_test_filter( string $hook, callable $callback, int $args = 1 ): void {
		add_filter( $hook, $callback, 10, $args );
		$this->filters_to_remove[] = [ $hook, $callback ];
	}

	/**
	 * Capture the receipt HTML and hand Dompdf a trivial document instead.
	 *
	 * @param string|null $html Captured HTML, set by reference.
	 * @return void
	 */
	private function capture_html( ?string &$html ): void {
		$this->add_test_filter(
			'mission_receipt_html',
			static function ( $original ) use ( &$html ) {
				$html = $original;
				return '<html><body>stub</body></html>';
			}
		);
	}

	/**
	 * Create an unsaved donor with a full address.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Donor
	 */
	private function make_donor( array $overrides = [] ): Donor {
		return new Donor( array_merge(
			[
				'email'      => 'donor@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'address_1'  => '1 Main St',
				'address_2'  => 'Apt 2',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'zip'        => '62701',
			],
			$overrides
		) );
	}

	/**
	 * Build annual receipt data with two transactions.
	 *
	 * @return array<string, mixed>
	 */
	private function annual_receipt_data(): array {
		return [
			'currency'     => 'USD',
			'total'        => 7500,
			'count'        => 2,
			'transactions' => [
				[
					'id'              => 11,
					'date_completed'  => '2025-03-01 10:00:00',
					'amount'          => 5000,
					'campaign_name'   => 'Clean Water',
					'payment_gateway' => 'stripe',
				],
				[
					'id'              => 12,
					'date_completed'  => '2025-09-15 10:00:00',
					'amount'          => 2500,
					'campaign_name'   => 'School Fund',
					'payment_gateway' => 'stripe',
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// PDF smoke tests.
	// -------------------------------------------------------------------------

	/**
	 * Test generate_annual returns a PDF binary.
	 */
	public function test_generate_annual_returns_pdf(): void {
		$pdf = $this->generator->generate_annual( $this->make_donor(), 2025, $this->annual_receipt_data() );

		$this->assertStringStartsWith( '%PDF', $pdf );
	}

	/**
	 * Test generate_single returns a PDF binary.
	 */
	public function test_generate_single_returns_pdf(): void {
		$pdf = $this->generator->generate_single(
			$this->make_donor(),
			[
				'id'              => 11,
				'date_completed'  => '2025-03-01 10:00:00',
				'amount'          => 5000,
				'currency'        => 'USD',
				'campaign_name'   => 'Clean Water',
				'payment_gateway' => 'stripe',
			]
		);

		$this->assertStringStartsWith( '%PDF', $pdf );
	}

	// -------------------------------------------------------------------------
	// HTML content tests.
	// -------------------------------------------------------------------------

	/**
	 * Test the annual receipt HTML contains org, donor, and transaction data.
	 */
	public function test_annual_receipt_html_contains_data(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'org_name'   => 'Test Org',
				'org_street' => '99 Charity Way',
				'org_city'   => 'Goodville',
				'org_state'  => 'CA',
				'org_zip'    => '90210',
				'org_ein'    => '12-3456789',
			]
		);

		$html = null;
		$this->capture_html( $html );

		$this->generator->generate_annual( $this->make_donor(), 2025, $this->annual_receipt_data() );

		$this->assertNotNull( $html );
		$this->assertStringContainsString( 'Test Org', $html );
		$this->assertStringContainsString( '99 Charity Way, Goodville, CA 90210', $html );
		$this->assertStringContainsString( '12-3456789', $html );
		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringContainsString( '1 Main St', $html );
		$this->assertStringContainsString( 'Springfield, IL 62701', $html );

		// One row per transaction, formatted.
		$this->assertStringContainsString( 'Clean Water', $html );
		$this->assertStringContainsString( 'School Fund', $html );
		$this->assertStringContainsString( '$50.00', $html );
		$this->assertStringContainsString( '$25.00', $html );
		$this->assertStringContainsString( date_i18n( 'F j, Y', strtotime( '2025-03-01 10:00:00' ) ), $html );

		// Year total.
		$this->assertStringContainsString( '$75.00', $html );
	}

	/**
	 * Test a past year is labelled as an annual receipt, the current year as YTD.
	 */
	public function test_year_label_depends_on_year(): void {
		$html = null;
		$this->capture_html( $html );

		$this->generator->generate_annual( $this->make_donor(), 2025, $this->annual_receipt_data() );
		$this->assertStringContainsString( 'Annual Donation Receipt', $html );

		$this->generator->generate_annual( $this->make_donor(), (int) gmdate( 'Y' ), $this->annual_receipt_data() );
		$this->assertStringContainsString( 'Year to Date', $html );
	}

	/**
	 * Test the org filters override the rendered values.
	 */
	public function test_org_filters_override_values(): void {
		$this->add_test_filter( 'mission_receipt_org_name', static fn() => 'Filtered Org' );
		$this->add_test_filter( 'mission_receipt_org_address', static fn() => 'Filtered Address' );
		$this->add_test_filter( 'mission_receipt_org_ein', static fn() => '99-9999999' );

		$html = null;
		$this->capture_html( $html );

		$this->generator->generate_annual( $this->make_donor(), 2025, $this->annual_receipt_data() );

		$this->assertStringContainsString( 'Filtered Org', $html );
		$this->assertStringContainsString( 'Filtered Address', $html );
		$this->assertStringContainsString( '99-9999999', $html );
	}

	/**
	 * Test the disclaimer filter overrides the default text.
	 */
	public function test_disclaimer_filter_overrides_text(): void {
		$this->add_test_filter( 'mission_receipt_disclaimer', static fn() => 'Custom disclaimer text.' );

		$html = null;
		$this->capture_html( $html );

		$this->generator->generate_annual( $this->make_donor(), 2025, $this->annual_receipt_data() );

		$this->assertStringContainsString( 'Custom disclaimer text.', $html );
		$this->assertStringNotContainsString( 'No goods or services were provided', $html );
	}

	/**
	 * Test a donor without an address renders without stray separators.
	 */
	public function test_donor_without_address_degrades_gracefully(): void {
		$donor = $this->make_donor( [
			'address_1' => '',
			'address_2' => '',
			'city'      => '',
			'state'     => '',
			'zip'       => '',
		] );

		$html = null;
		$this->capture_html( $html );

		$this->generator->generate_annual( $donor, 2025, $this->annual_receipt_data() );

		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringNotContainsString( 'Springfield', $html );
		// The donor address block collapses to nothing rather than ", " noise.
		$this->assertStringNotContainsString( ', ,', $html );
	}

	/**
	 * Test the single receipt is labelled and totalled from its one transaction.
	 */
	public function test_single_receipt_html(): void {
		$html = null;
		$this->capture_html( $html );

		$this->generator->generate_single(
			$this->make_donor(),
			[
				'id'              => 42,
				'date_completed'  => '2026-01-05 10:00:00',
				'amount'          => 12500,
				'currency'        => 'USD',
				'campaign_name'   => 'General Fund',
				'payment_gateway' => 'stripe',
			]
		);

		$this->assertStringContainsString( 'Donation Receipt', $html );
		$this->assertStringContainsString( 'General Fund', $html );
		$this->assertStringContainsString( '$125.00', $html );
		$this->assertStringContainsString( 'Stripe', $html );
	}
}
