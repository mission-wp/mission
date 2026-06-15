<?php
/**
 * Block Name: Fundraiser Sign-up Modal
 * Description: Multi-step modal for becoming a fundraiser or joining a team.
 *
 * The visual shell + step navigation only; account/fundraiser/team creation is
 * wired in the registration phase. Rendered once per peer-to-peer page and
 * opened by the "Become a Fundraiser" / "Join this Team" buttons.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
// Resolve the campaign (and a preselected team on a team page) from the page.
$campaign         = null;
$preselected_team = null;
$current_post     = get_post();

if ( ! empty( $attributes['campaignId'] ) ) {
	$campaign = Campaign::find( (int) $attributes['campaignId'] );
} elseif ( $current_post ) {
	if ( CampaignPostType::POST_TYPE === $current_post->post_type ) {
		$campaign = Campaign::find_by_post_id( $current_post->ID );
	} elseif ( Fundraiser::POST_TYPE === $current_post->post_type ) {
		$campaign = Fundraiser::find_by_post_id( $current_post->ID )?->campaign();
	} elseif ( Team::POST_TYPE === $current_post->post_type ) {
		$preselected_team = Team::find_by_post_id( $current_post->ID );
		$campaign         = $preselected_team?->campaign();
	}
}

if ( ! $campaign || ! $campaign->is_p2p() ) {
	return;
}

$settings = $campaign->p2p_settings();

if ( empty( $settings['registration_open'] ) ) {
	return;
}

$teams_enabled    = ! empty( $settings['teams_enabled'] );
$creation_enabled = $teams_enabled && ! empty( $settings['team_creation_enabled'] );
$default_goal     = (int) round( ( $settings['default_fundraiser_goal'] ?? 0 ) / 100 );
$story_placeholder = (string) ( $settings['story_placeholder'] ?? '' );
$teams            = $teams_enabled ? Team::query( [ 'campaign_id' => $campaign->id, 'status' => Team::STATUS_ACTIVE ] ) : [];

$brandline = $preselected_team
	/* translators: %s: team name */
	? sprintf( __( 'Join %s', 'mission-donation-platform' ), $preselected_team->name )
	: $campaign->title;

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-su' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/p2p-signup"
	style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
>
	<div
		class="mission-su__overlay"
		data-wp-class--is-open="state.isOpen"
		data-wp-on--click="actions.onOverlayClick"
		data-wp-on--keydown="actions.onKeydown"
	>
		<div class="mission-su__dialog" role="dialog" aria-modal="true">
			<div class="mission-su__head">
				<span class="mission-su__brand"><?php echo esc_html( $brandline ); ?></span>
				<button type="button" class="mission-su__close" aria-label="<?php esc_attr_e( 'Close', 'mission-donation-platform' ); ?>" data-wp-on--click="actions.close">&times;</button>
			</div>

			<div class="mission-su__progress" aria-hidden="true">
				<span class="mission-su__dot" data-wp-class--is-active="state.isStep1"></span>
				<span class="mission-su__line"></span>
				<span class="mission-su__dot" data-wp-class--is-active="state.isStep2"></span>
				<span class="mission-su__line"></span>
				<span class="mission-su__dot" data-wp-class--is-active="state.isStep3"></span>
			</div>

			<div class="mission-su__body">
				<!-- Step 1: account -->
				<div class="mission-su__step" data-wp-class--is-active="state.isStep1">
					<h2 class="mission-su__title"><?php esc_html_e( 'Create your account', 'mission-donation-platform' ); ?></h2>
					<p class="mission-su__subtitle"><?php esc_html_e( "Let's set up your fundraising page. First, the basics.", 'mission-donation-platform' ); ?></p>

					<div class="mission-su__row">
						<label class="mission-su__field"><span><?php esc_html_e( 'First name', 'mission-donation-platform' ); ?></span><input type="text" autocomplete="given-name" /></label>
						<label class="mission-su__field"><span><?php esc_html_e( 'Last name', 'mission-donation-platform' ); ?></span><input type="text" autocomplete="family-name" /></label>
					</div>
					<label class="mission-su__field"><span><?php esc_html_e( 'Email', 'mission-donation-platform' ); ?></span><input type="email" autocomplete="email" /></label>
					<label class="mission-su__field"><span><?php esc_html_e( 'Password', 'mission-donation-platform' ); ?></span><input type="password" autocomplete="new-password" /></label>

					<button type="button" class="mission-su__btn" data-wp-on--click="actions.next"><?php esc_html_e( 'Continue', 'mission-donation-platform' ); ?></button>
				</div>

				<!-- Step 2: fundraiser setup -->
				<div class="mission-su__step" data-wp-class--is-active="state.isStep2">
					<h2 class="mission-su__title"><?php esc_html_e( 'Set up your fundraiser', 'mission-donation-platform' ); ?></h2>

					<?php if ( $preselected_team ) : ?>
						<div class="mission-su__locked">
							<strong><?php echo esc_html( $preselected_team->name ); ?></strong>
							<span><?php esc_html_e( 'Joining as a team member', 'mission-donation-platform' ); ?></span>
						</div>
					<?php elseif ( $teams_enabled ) : ?>
						<div class="mission-su__toggle">
							<button type="button" class="mission-su__toggle-btn" data-wp-class--is-active="state.isJoinMode" data-wp-on--click="actions.setJoinMode"><?php esc_html_e( 'Join a team', 'mission-donation-platform' ); ?></button>
							<?php if ( $creation_enabled ) : ?>
								<button type="button" class="mission-su__toggle-btn" data-wp-class--is-active="state.isCreateMode" data-wp-on--click="actions.setCreateMode"><?php esc_html_e( 'Create a team', 'mission-donation-platform' ); ?></button>
							<?php endif; ?>
						</div>
						<div class="mission-su__panel" data-wp-class--is-active="state.isJoinMode">
							<label class="mission-su__field">
								<span><?php esc_html_e( 'Select a team', 'mission-donation-platform' ); ?></span>
								<select>
									<option value=""><?php esc_html_e( 'No team — fundraise on your own', 'mission-donation-platform' ); ?></option>
									<?php foreach ( $teams as $team ) : ?>
										<option value="<?php echo esc_attr( (string) $team->id ); ?>"><?php echo esc_html( $team->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>
						<?php if ( $creation_enabled ) : ?>
							<div class="mission-su__panel" data-wp-class--is-active="state.isCreateMode">
								<label class="mission-su__field"><span><?php esc_html_e( 'Team name', 'mission-donation-platform' ); ?></span><input type="text" /></label>
							</div>
						<?php endif; ?>
					<?php endif; ?>

					<label class="mission-su__field"><span><?php esc_html_e( 'Your fundraising goal', 'mission-donation-platform' ); ?></span><input type="number" min="1" value="<?php echo esc_attr( (string) $default_goal ); ?>" /></label>
					<label class="mission-su__field"><span><?php esc_html_e( 'Tell your story', 'mission-donation-platform' ); ?></span><textarea rows="4" placeholder="<?php echo esc_attr( $story_placeholder ); ?>"></textarea></label>

					<label class="mission-su__check">
						<input type="checkbox" data-wp-on--change="actions.toggleTribute" />
						<?php esc_html_e( 'Dedicate this fundraiser in honor or memory of someone', 'mission-donation-platform' ); ?>
					</label>
					<div class="mission-su__panel" data-wp-class--is-active="state.tributeChecked">
						<label class="mission-su__field"><span><?php esc_html_e( 'Their name', 'mission-donation-platform' ); ?></span><input type="text" /></label>
					</div>

					<button type="button" class="mission-su__btn" data-wp-on--click="actions.submit"><?php esc_html_e( 'Create fundraiser', 'mission-donation-platform' ); ?></button>
					<button type="button" class="mission-su__btn mission-su__btn--ghost" data-wp-on--click="actions.back"><?php esc_html_e( 'Back', 'mission-donation-platform' ); ?></button>
				</div>

				<!-- Step 3: success -->
				<div class="mission-su__step" data-wp-class--is-active="state.isStep3">
					<div class="mission-su__success">
						<h2 class="mission-su__title"><?php esc_html_e( "You're a fundraiser!", 'mission-donation-platform' ); ?></h2>
						<p class="mission-su__subtitle"><?php esc_html_e( 'Your page is live. Share it with friends and family to start raising funds.', 'mission-donation-platform' ); ?></p>
						<div class="mission-su__share">
							<button type="button" class="mission-su__share-btn" data-wp-on--click="actions.share"><?php esc_html_e( 'Share', 'mission-donation-platform' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
$output = ob_get_clean();

echo wp_kses( apply_filters( 'mission_signup_modal_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes, $content, $block );
