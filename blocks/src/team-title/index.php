<?php
/**
 * Block Name: Team Title
 * Description: A team's name and the campaign it is fundraising for.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the team from the block attribute or the queried shell page.
	$team = BlockSupport::resolve_team( $attributes );

	if ( ! $team ) {
		return;
	}

	$campaign = $team->campaign();

	ob_start();
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-tt-title' ] ) ); ?>
		style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
	>
		<h1 class="mission-tt-title__heading"><?php echo esc_html( $team->name ); ?></h1>
		<p class="mission-tt-title__subtitle">
			<?php
			if ( $campaign && $campaign->get_url() ) {
				printf(
					/* translators: %s: linked campaign title */
					esc_html__( 'Team fundraising for %s', 'mission-donation-platform' ),
					'<a href="' . esc_url( $campaign->get_url() ) . '">' . esc_html( $campaign->title ) . '</a>'
				);
			} else {
				esc_html_e( 'Team fundraising', 'mission-donation-platform' );
			}
			?>
		</p>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the team title block output.
	 *
	 * @param string $output     HTML output.
	 * @param Team   $team       Team model.
	 * @param array  $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_team_title_output', $output, $team, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
