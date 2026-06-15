<?php
/**
 * Block Name: Fundraiser Profile
 * Description: A fundraiser's cover image, name, headline, tribute, and story.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Models\Fundraiser;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
// Resolve the fundraiser from the block attribute or the queried shell page.
$fundraiser = null;

if ( ! empty( $attributes['fundraiserId'] ) ) {
	$fundraiser = Fundraiser::find( (int) $attributes['fundraiserId'] );
} else {
	$current_post = get_post();
	if ( $current_post && Fundraiser::POST_TYPE === $current_post->post_type ) {
		$fundraiser = Fundraiser::find_by_post_id( $current_post->ID );
	}
}

if ( ! $fundraiser ) {
	return;
}

$donor      = $fundraiser->donor();
$name       = $donor ? trim( $donor->first_name . ' ' . $donor->last_name ) : '';
$name       = $name ?: __( 'A fundraiser', 'mission-donation-platform' );
$campaign   = $fundraiser->campaign();
$headline   = $fundraiser->headline;
$story      = $fundraiser->story;
$cover_html = BlockSupport::image_html( $fundraiser->cover_image, $name );

// Optional tribute (set during registration).
$tribute_type = (string) $fundraiser->get_meta( 'tribute_type' );
$tribute_name = (string) $fundraiser->get_meta( 'tribute_name' );
$tribute_text = '';
if ( '' !== $tribute_type && '' !== $tribute_name ) {
	$tribute_text = 'in_memory' === $tribute_type
		/* translators: %s: person being honored */
		? sprintf( __( 'In memory of %s', 'mission-donation-platform' ), $tribute_name )
		/* translators: %s: person being honored */
		: sprintf( __( 'In honor of %s', 'mission-donation-platform' ), $tribute_name );
}

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-fp-profile' ] ) ); ?>
	style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
>
	<header class="mission-fp-profile__header">
		<h1 class="mission-fp-profile__title">
			<?php
			if ( $campaign && $campaign->get_url() ) {
				printf(
					/* translators: 1: fundraiser name, 2: linked campaign title */
					esc_html__( '%1$s is fundraising for %2$s', 'mission-donation-platform' ),
					esc_html( $name ),
					'<a href="' . esc_url( $campaign->get_url() ) . '">' . esc_html( $campaign->title ) . '</a>'
				);
			} else {
				echo esc_html( $name );
			}
			?>
		</h1>

		<?php if ( '' !== $headline ) : ?>
			<p class="mission-fp-profile__headline"><?php echo esc_html( $headline ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $tribute_text ) : ?>
			<p class="mission-fp-profile__tribute"><?php echo esc_html( $tribute_text ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( '' !== $cover_html ) : ?>
		<figure class="mission-fp-profile__cover"><?php echo wp_kses_post( $cover_html ); ?></figure>
	<?php endif; ?>

	<?php if ( '' !== trim( $story ) ) : ?>
		<div class="mission-fp-profile__story">
			<h2 class="mission-fp-profile__story-heading"><?php esc_html_e( 'My Story', 'mission-donation-platform' ); ?></h2>
			<?php echo wp_kses_post( wpautop( $story ) ); ?>
		</div>
	<?php endif; ?>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the fundraiser profile block output.
 *
 * @param string     $output     HTML output.
 * @param Fundraiser $fundraiser Fundraiser model.
 * @param array      $attributes Block attributes.
 */
echo wp_kses( apply_filters( 'mission_fundraiser_profile_output', $output, $fundraiser, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes, $content, $block );
