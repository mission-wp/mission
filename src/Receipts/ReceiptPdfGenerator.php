<?php
/**
 * Receipt PDF generator using Dompdf.
 *
 * @package MissionDP
 */

namespace MissionDP\Receipts;

use MissionDP\Currency\Currency;
use MissionDP\Models\Donor;
use MissionDP\Settings\SettingsService;
use Dompdf\Dompdf;
use Dompdf\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Generates donation receipt PDFs.
 */
class ReceiptPdfGenerator {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {}

	/**
	 * Generate an annual receipt PDF for a donor.
	 *
	 * @param Donor $donor        Donor model.
	 * @param int   $year         Calendar year.
	 * @param array $receipt_data Data from ReportingService::donor_annual_receipt_data().
	 * @return string Raw PDF binary content.
	 */
	public function generate_annual( Donor $donor, int $year, array $receipt_data ): string {
		$currency = strtoupper( $receipt_data['currency'] ?: $this->settings->get( 'currency', 'USD' ) );

		$is_current_year = (int) gmdate( 'Y' ) === $year;

		$data = [
			'is_single'    => false,
			'org'          => $this->get_org_data(),
			'donor'        => $this->get_donor_data( $donor ),
			'year'         => $year,
			'year_label'   => $is_current_year
				/* translators: %d: calendar year */
				? sprintf( __( 'Year to Date — %d', 'mission-donation-platform' ), $year )
				/* translators: %d: calendar year */
				: sprintf( __( 'Annual Donation Receipt — %d', 'mission-donation-platform' ), $year ),
			'transactions' => $this->format_transactions( $receipt_data['transactions'], $currency ),
			'total'        => Currency::format_amount( $receipt_data['total'], $currency ),
			'count'        => $receipt_data['count'],
			'disclaimer'   => $this->get_disclaimer(),
			'generated_on' => date_i18n( 'F j, Y' ),
		];

		return $this->render_pdf( $data );
	}

	/**
	 * Generate a single-transaction receipt PDF.
	 *
	 * @param Donor $donor            Donor model.
	 * @param array $transaction_data Single transaction data array (same shape as one element of receipt_data['transactions']).
	 * @return string Raw PDF binary content.
	 */
	public function generate_single( Donor $donor, array $transaction_data ): string {
		$currency = strtoupper( $transaction_data['currency'] ?: $this->settings->get( 'currency', 'USD' ) );

		$data = [
			'is_single'    => true,
			'org'          => $this->get_org_data(),
			'donor'        => $this->get_donor_data( $donor ),
			'year'         => null,
			'year_label'   => __( 'Donation Receipt', 'mission-donation-platform' ),
			'transactions' => $this->format_transactions( [ $transaction_data ], $currency ),
			'total'        => Currency::format_amount( (int) $transaction_data['amount'], $currency ),
			'count'        => 1,
			'disclaimer'   => $this->get_disclaimer(),
			'generated_on' => date_i18n( 'F j, Y' ),
		];

		return $this->render_pdf( $data );
	}

	/**
	 * Get organization data for the receipt.
	 *
	 * @return array{name: string, address: string, ein: string}
	 */
	private function get_org_data(): array {
		$name    = apply_filters( 'missiondp_receipt_org_name', $this->settings->get( 'org_name', get_bloginfo( 'name' ) ) );
		$address = apply_filters( 'missiondp_receipt_org_address', $this->settings->format_org_address() );
		$ein     = apply_filters( 'missiondp_receipt_org_ein', $this->settings->get( 'org_ein', '' ) );

		return [
			'name'    => $name,
			'address' => $address,
			'ein'     => $ein,
		];
	}

	/**
	 * Get formatted donor data for the receipt.
	 *
	 * @param Donor $donor Donor model.
	 * @return array{name: string, address: string}
	 */
	private function get_donor_data( Donor $donor ): array {
		$parts = array_filter(
			[
				$donor->address_1,
				$donor->address_2,
			]
		);

		$city_line = array_filter(
			[
				$donor->city,
				$donor->state ? $donor->state . ( $donor->zip ? ' ' . $donor->zip : '' ) : $donor->zip,
			]
		);

		if ( $city_line ) {
			$parts[] = implode( ', ', $city_line );
		}

		return [
			'name'    => $donor->full_name(),
			'address' => implode( "\n", $parts ),
		];
	}

	/**
	 * Format transaction rows for display.
	 *
	 * @param array  $transactions Raw transaction arrays.
	 * @param string $currency     Currency code.
	 * @return array Formatted transaction rows.
	 */
	private function format_transactions( array $transactions, string $currency ): array {
		return array_map(
			static fn( array $txn ) => [
				'id'              => $txn['id'],
				'date'            => date_i18n( 'F j, Y', strtotime( $txn['date_completed'] ) ),
				'amount'          => Currency::format_amount( (int) $txn['amount'], $currency ),
				'campaign_name'   => $txn['campaign_name'],
				'payment_gateway' => ucfirst( $txn['payment_gateway'] ?? '' ),
			],
			$transactions
		);
	}

	/**
	 * Get the tax-deductibility disclaimer text.
	 *
	 * @return string
	 */
	private function get_disclaimer(): string {
		$disclaimer = __(
			'No goods or services were provided in exchange for these contributions. All donations are tax-deductible to the extent allowed by law.',
			'mission-donation-platform'
		);

		return apply_filters( 'missiondp_receipt_disclaimer', $disclaimer );
	}

	/**
	 * Render template HTML and convert to PDF.
	 *
	 * @param array $data Template data.
	 * @return string Raw PDF bytes.
	 */
	private function render_pdf( array $data ): string {
		ob_start();
		require __DIR__ . '/templates/annual-receipt.php';
		$html = ob_get_clean();

		/**
		 * Filters the receipt HTML before PDF rendering.
		 *
		 * @param string $html The full HTML document.
		 * @param array  $data Template data.
		 */
		$html = apply_filters( 'missiondp_receipt_html', $html, $data );

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'defaultFont', 'Helvetica' );
		$options->set( 'tempDir', get_temp_dir() );

		/**
		 * Filters Dompdf options before rendering.
		 *
		 * @param Options $options Dompdf Options object.
		 * @param array   $data    Template data.
		 */
		$options = apply_filters( 'missiondp_receipt_pdf_options', $options, $data );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'letter', 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}
}
