<?php
/**
 * Annual receipt PDF template.
 *
 * This template renders as a self-contained HTML document for Dompdf.
 * All CSS must be embedded — no external stylesheets.
 *
 * Available variables (via $data array):
 *   bool   $data['is_single']    — true for single-transaction receipts
 *   array  $data['org']          — { name, address, ein }
 *   array  $data['donor']        — { name, address }
 *   int    $data['year']         — calendar year (null for single)
 *   string $data['year_label']   — heading text
 *   array  $data['transactions'] — [ { id, date, amount, campaign_name, payment_gateway } ]
 *   string $data['total']        — formatted total
 *   int    $data['count']        — transaction count
 *   string $data['disclaimer']   — tax-deductibility text
 *   string $data['generated_on'] — generation date
 *
 * @package Mission
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<style>
		/* Reset */
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		body {
			font-family: Helvetica, Arial, sans-serif;
			font-size: 11px;
			color: #1a1a1a;
			line-height: 1.5;
			padding: 40px 50px;
		}

		/* Green accent bar */
		.accent-bar {
			height: 4px;
			background-color: #2d6a4f;
			margin-bottom: 24px;
		}

		/* Header */
		.header {
			margin-bottom: 6px;
		}

		.header-table {
			width: 100%;
			border-collapse: collapse;
		}

		.org-name {
			font-size: 15px;
			font-weight: 700;
			color: #1a1a1a;
		}

		.receipt-label {
			font-size: 10px;
			color: #999999;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			text-align: right;
		}

		.divider {
			height: 1px;
			background-color: #e8e8e6;
			margin: 16px 0;
		}

		/* Receipt heading */
		.receipt-heading {
			font-size: 18px;
			font-weight: 700;
			color: #1a1a1a;
			margin-bottom: 20px;
		}

		/* Donor + Org info row */
		.info-table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 24px;
		}

		.info-label {
			font-size: 9px;
			color: #999999;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			font-weight: 600;
			padding-bottom: 4px;
		}

		.info-value {
			font-size: 11px;
			color: #1a1a1a;
			line-height: 1.6;
		}

		/* Transaction table */
		.txn-table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 20px;
		}

		.txn-table th {
			font-size: 9px;
			color: #999999;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			font-weight: 600;
			padding: 8px 10px;
			text-align: left;
			border-bottom: 2px solid #e8e8e6;
		}

		.txn-table th.amount-col {
			text-align: right;
		}

		.txn-table td {
			font-size: 11px;
			padding: 9px 10px;
			border-bottom: 1px solid #f0f0ee;
			vertical-align: top;
		}

		.txn-table td.amount-cell {
			text-align: right;
			font-weight: 600;
			white-space: nowrap;
		}

		.txn-table td.date-cell {
			white-space: nowrap;
		}

		/* Total row */
		.total-row td {
			border-bottom: none;
			border-top: 2px solid #e8e8e6;
			font-weight: 700;
			padding-top: 10px;
		}

		/* Disclaimer */
		.disclaimer-box {
			background-color: #f9f9f7;
			padding: 14px 18px;
			margin-top: 24px;
		}

		.disclaimer-text {
			font-size: 10px;
			color: #666666;
			line-height: 1.6;
		}

		.disclaimer-text strong {
			color: #1a1a1a;
		}

		/* Footer */
		.footer {
			margin-top: 30px;
			padding-top: 14px;
			border-top: 1px solid #e8e8e6;
		}

		.footer-text {
			font-size: 9px;
			color: #999999;
			line-height: 1.6;
		}
	</style>
</head>
<body>

	<div class="accent-bar"></div>

	<!-- Header -->
	<div class="header">
		<table class="header-table">
			<tr>
				<td class="org-name"><?php echo esc_html( $data['org']['name'] ); ?></td>
				<td class="receipt-label">
					<?php
					echo $data['is_single']
						? esc_html__( 'DONATION RECEIPT', 'missionwp-donation-platform' )
						: esc_html__( 'ANNUAL DONATION RECEIPT', 'missionwp-donation-platform' );
					?>
				</td>
			</tr>
		</table>
	</div>

	<div class="divider"></div>

	<!-- Receipt heading -->
	<h1 class="receipt-heading"><?php echo esc_html( $data['year_label'] ); ?></h1>

	<!-- Donor + Org info -->
	<table class="info-table">
		<tr>
			<td style="width: 50%; vertical-align: top; padding-right: 20px;">
				<div class="info-label"><?php esc_html_e( 'Donor', 'missionwp-donation-platform' ); ?></div>
				<div class="info-value">
					<?php echo esc_html( $data['donor']['name'] ); ?>
					<?php if ( $data['donor']['address'] ) : ?>
						<br><?php echo nl2br( esc_html( $data['donor']['address'] ) ); ?>
					<?php endif; ?>
				</div>
			</td>
			<td style="width: 50%; vertical-align: top; text-align: right;">
				<div class="info-label"><?php esc_html_e( 'Organization', 'missionwp-donation-platform' ); ?></div>
				<div class="info-value">
					<?php echo esc_html( $data['org']['name'] ); ?>
					<?php if ( $data['org']['address'] ) : ?>
						<br><?php echo nl2br( esc_html( $data['org']['address'] ) ); ?>
					<?php endif; ?>
					<?php if ( $data['org']['ein'] ) : ?>
						<br>
						<?php
							/* translators: %s: EIN number */
							printf( esc_html__( 'EIN: %s', 'missionwp-donation-platform' ), esc_html( $data['org']['ein'] ) );
						?>
					<?php endif; ?>
				</div>
			</td>
		</tr>
	</table>

	<!-- Transaction table -->
	<table class="txn-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'missionwp-donation-platform' ); ?></th>
				<th class="amount-col"><?php esc_html_e( 'Amount', 'missionwp-donation-platform' ); ?></th>
				<th><?php esc_html_e( 'Campaign', 'missionwp-donation-platform' ); ?></th>
				<th><?php esc_html_e( 'Payment Method', 'missionwp-donation-platform' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['transactions'] as $txn ) : ?>
				<tr>
					<td class="date-cell"><?php echo esc_html( $txn['date'] ); ?></td>
					<td class="amount-cell"><?php echo esc_html( $txn['amount'] ); ?></td>
					<td><?php echo esc_html( $txn['campaign_name'] ); ?></td>
					<td><?php echo esc_html( $txn['payment_gateway'] ); ?></td>
				</tr>
			<?php endforeach; ?>

			<!-- Total row -->
			<tr class="total-row">
				<td><?php esc_html_e( 'Total', 'missionwp-donation-platform' ); ?></td>
				<td class="amount-cell"><?php echo esc_html( $data['total'] ); ?></td>
				<td colspan="2">
					<?php
					printf(
						/* translators: %d: number of donations */
						esc_html( _n( '%d donation', '%d donations', $data['count'], 'missionwp-donation-platform' ) ),
						(int) $data['count']
					);
					?>
				</td>
			</tr>
		</tbody>
	</table>

	<!-- Disclaimer -->
	<div class="disclaimer-box">
		<div class="disclaimer-text">
			<strong><?php esc_html_e( 'Tax-deductible contribution.', 'missionwp-donation-platform' ); ?></strong>
			<?php echo esc_html( $data['disclaimer'] ); ?>
			<?php if ( $data['org']['ein'] ) : ?>
				<br>
				<?php
					printf(
						/* translators: 1: organization name 2: EIN */
						esc_html__( '%1$s is a registered 501(c)(3) nonprofit organization. EIN: %2$s', 'missionwp-donation-platform' ),
						esc_html( $data['org']['name'] ),
						esc_html( $data['org']['ein'] )
					);
				?>
			<?php endif; ?>
		</div>
	</div>

	<!-- Footer -->
	<div class="footer">
		<div class="footer-text">
			<?php if ( $data['org']['address'] ) : ?>
				<?php echo esc_html( $data['org']['name'] . ' — ' . str_replace( "\n", ', ', $data['org']['address'] ) ); ?>
				<br>
			<?php endif; ?>
			<?php
			printf(
				/* translators: %s: date */
				esc_html__( 'Generated on %s', 'missionwp-donation-platform' ),
				esc_html( $data['generated_on'] )
			);
			?>
		</div>
	</div>

</body>
</html>
