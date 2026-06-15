<?php
/**
 * Block Name: Team Profile
 * Description: A team's cover image, name, and description.
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


( static function ( $attributes, $content, $block ): void {
// Resolve the team from the block attribute or the queried shell page.
$team = null;

if ( ! empty( $attributes['teamId'] ) ) {
	$team = Team::find( (int) $attributes['teamId'] );
} else {
	$current_post = get_post();
	if ( $current_post && Team::POST_TYPE === $current_post->post_type ) {
		$team = Team::find_by_post_id( $current_post->ID );
	}
}

if ( ! $team ) {
	return;
}

$campaign    = $team->campaign();
$description = $team->description;
$cover_html  = BlockSupport::image_html( $team->cover_image, $team->name );

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-tp-profile' ] ) ); ?>
	style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
>
	<header class="mission-tp-profile__header">
		<h1 class="mission-tp-profile__title"><?php echo esc_html( $team->name ); ?></h1>
		<p class="mission-tp-profile__subtitle">
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
	</header>

	<?php if ( '' !== $cover_html ) : ?>
		<figure class="mission-tp-profile__cover"><?php echo wp_kses_post( $cover_html ); ?></figure>
	<?php endif; ?>

	<?php if ( '' !== trim( $description ) ) : ?>
		<div class="mission-tp-profile__about">
			<h2 class="mission-tp-profile__about-heading"><?php esc_html_e( 'About Our Team', 'mission-donation-platform' ); ?></h2>
			<?php echo wp_kses_post( wpautop( $description ) ); ?>
		</div>
	<?php endif; ?>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the team profile block output.
 *
 * @param string $output     HTML output.
 * @param Team   $team       Team model.
 * @param array  $attributes Block attributes.
 */
echo wp_kses( apply_filters( 'mission_team_profile_output', $output, $team, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes, $content, $block );
