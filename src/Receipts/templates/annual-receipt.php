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
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

( static function ( $data ): void {
	?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<?php
	// Dompdf has no wp_head pipeline; CSS lives in a sibling file and is inlined here at render time.
	echo wp_kses(
		sprintf( '<%1$s>%2$s</%1$s>', 'style', file_get_contents( __DIR__ . '/annual-receipt.css' ) ),
		[ 'style' => [] ]
	);
	?>
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
						? esc_html__( 'DONATION RECEIPT', 'mission-donation-platform' )
						: esc_html__( 'ANNUAL DONATION RECEIPT', 'mission-donation-platform' );
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
				<div class="info-label"><?php esc_html_e( 'Donor', 'mission-donation-platform' ); ?></div>
				<div class="info-value">
					<?php echo esc_html( $data['donor']['name'] ); ?>
					<?php if ( $data['donor']['address'] ) : ?>
						<br><?php echo nl2br( esc_html( $data['donor']['address'] ) ); ?>
					<?php endif; ?>
				</div>
			</td>
			<td style="width: 50%; vertical-align: top; text-align: right;">
				<div class="info-label"><?php esc_html_e( 'Organization', 'mission-donation-platform' ); ?></div>
				<div class="info-value">
					<?php echo esc_html( $data['org']['name'] ); ?>
					<?php if ( $data['org']['address'] ) : ?>
						<br><?php echo nl2br( esc_html( $data['org']['address'] ) ); ?>
					<?php endif; ?>
					<?php if ( $data['org']['ein'] ) : ?>
						<br>
						<?php
							/* translators: %s: EIN number */
							printf( esc_html__( 'EIN: %s', 'mission-donation-platform' ), esc_html( $data['org']['ein'] ) );
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
				<th><?php esc_html_e( 'Date', 'mission-donation-platform' ); ?></th>
				<th class="amount-col"><?php esc_html_e( 'Amount', 'mission-donation-platform' ); ?></th>
				<th><?php esc_html_e( 'Campaign', 'mission-donation-platform' ); ?></th>
				<th><?php esc_html_e( 'Payment Method', 'mission-donation-platform' ); ?></th>
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
				<td><?php esc_html_e( 'Total', 'mission-donation-platform' ); ?></td>
				<td class="amount-cell"><?php echo esc_html( $data['total'] ); ?></td>
				<td colspan="2">
					<?php
					printf(
						/* translators: %d: number of donations */
						esc_html( _n( '%d donation', '%d donations', $data['count'], 'mission-donation-platform' ) ),
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
			<strong><?php esc_html_e( 'Tax-deductible contribution.', 'mission-donation-platform' ); ?></strong>
			<?php echo esc_html( $data['disclaimer'] ); ?>
			<?php if ( $data['org']['ein'] ) : ?>
				<br>
				<?php
					printf(
						/* translators: 1: organization name 2: EIN */
						esc_html__( '%1$s is a registered 501(c)(3) nonprofit organization. EIN: %2$s', 'mission-donation-platform' ),
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
				esc_html__( 'Generated on %s', 'mission-donation-platform' ),
				esc_html( $data['generated_on'] )
			);
			?>
		</div>
	</div>

</body>
</html>
	<?php
} )( $data );
