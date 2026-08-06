<?php
/**
 * Block Name: Fundraiser Supporters
 * Description: The people who donated through a fundraiser's page.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\DonorDashboard\DashboardLabels;
use MissionDP\Models\Fundraiser;
use MissionDP\P2P\BlockSupport;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the fundraiser from the block attribute or the queried shell page.
	$fundraiser = BlockSupport::resolve_fundraiser( $attributes );

	if ( ! $fundraiser ) {
		return;
	}

	$mission_settings = get_option( 'missiondp_settings', [] );
	$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );
	$limit            = max( 1, (int) ( $attributes['numberOfSupporters'] ?? 20 ) );

	$result     = ( new ReportingService() )->fundraiser_donations_query( (int) $fundraiser->id, $limit, 1 );
	$supporters = $result['items'];
	$total      = $result['total'];

	ob_start();
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-supporters' ] ) ); ?>
		style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
	>
		<?php if ( empty( $supporters ) ) : ?>
			<div class="mission-donor-empty">
				<div class="mission-donor-empty__icon">&#9734;</div>
				<p class="mission-donor-empty__title"><?php esc_html_e( 'No supporters yet.', 'mission-donation-platform' ); ?></p>
				<p class="mission-donor-empty__subtitle"><?php esc_html_e( 'Be the first to chip in and cheer this fundraiser on.', 'mission-donation-platform' ); ?></p>
			</div>
		<?php else : ?>
			<p class="mission-supporters__count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of donations */
						_n( '%s donation', '%s donations', $total, 'mission-donation-platform' ),
						number_format_i18n( $total )
					)
				);
				?>
			</p>
			<ul class="mission-donor-list">
				<?php
				foreach ( $supporters as $supporter ) :
					$first    = $supporter['first_name'] ?? '';
					$last     = $supporter['last_name'] ?? '';
					$initials = DashboardLabels::person_initials( $first, $last, (bool) $supporter['is_anonymous'] );

					if ( $supporter['is_anonymous'] ) {
						$name = __( 'Anonymous', 'mission-donation-platform' );
					} else {
						$name = trim( $first . ' ' . mb_substr( $last, 0, 1 ) . '.' );
						$name = '.' === $name ? __( 'Anonymous', 'mission-donation-platform' ) : $name;
					}

					$comment  = $supporter['comment'] ?? '';
					$amount   = Currency::format_amount( $supporter['amount'], $currency );
					$time_ago = $supporter['date']
						/* translators: %s: human time diff, e.g. "2 hours" */
						? sprintf( __( '%s ago', 'mission-donation-platform' ), human_time_diff( strtotime( $supporter['date'] ) ) )
						: '';
					?>
					<li class="mission-donor-item">
						<div class="mission-donor-item-left">
							<span class="mission-donor-avatar"><?php echo esc_html( $initials ); ?></span>
							<div class="mission-donor-info">
								<span class="mission-donor-name"><?php echo esc_html( $name ); ?></span>
								<?php if ( '' !== (string) $comment ) : ?>
									<span class="mission-donor-dedication"><?php echo esc_html( $comment ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $time_ago ) : ?>
									<span class="mission-supporters__time"><?php echo esc_html( $time_ago ); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<span class="mission-donor-amount"><?php echo esc_html( $amount ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the fundraiser supporters block output.
	 *
	 * @param string     $output     HTML output.
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @param array      $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_fundraiser_supporters_output', $output, $fundraiser, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
