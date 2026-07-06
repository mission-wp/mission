<?php
/**
 * Block Name: Fundraiser Sign-up Modal
 * Description: Multi-step modal for becoming a fundraiser or joining a team.
 *
 * Rendered once per peer-to-peer page and opened by the "Become a Fundraiser" /
 * "Join this Team" buttons. Step 1 branches on the email (login, inline OTP
 * verification, or inline password reset); steps 2-3 are the fundraiser setup
 * and the share-focused success screen. Submission is wired in view.js against
 * the /p2p REST routes.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Currency\Currency;
use MissionDP\Helpers\Sharing;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
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

	if ( ! $campaign || ! $campaign->is_registration_open() ) {
		return;
	}

	$settings = $campaign->p2p_settings();

	$teams_enabled     = ! empty( $settings['teams_enabled'] );
	$creation_enabled  = $teams_enabled && ! empty( $settings['team_creation_enabled'] );
	$currency          = $campaign->currency ?: 'USD';
	$default_goal      = Currency::minor_to_major( (int) ( $settings['default_fundraiser_goal'] ?? 0 ), $currency );
	$story_placeholder = (string) ( $settings['story_placeholder'] ?? '' );
	// Only public teams are browseable; private teams are joined via invitation.
	$teams = $teams_enabled ? Team::query(
		[
			'campaign_id' => $campaign->id,
			'status'      => Team::STATUS_ACTIVE,
			'access'      => Team::ACCESS_PUBLIC,
		]
	) : [];

	// Resolve a signed-in donor inline (cheap; avoids booting the auth service).
	$current_user  = wp_get_current_user();
	$current_donor = ( $current_user->ID && in_array( 'missiondp_donor', (array) $current_user->roles, true ) )
		? Donor::find_by_user_id( $current_user->ID )
		: null;

	$brandline = $preselected_team
		/* translators: %s: team name */
		? sprintf( __( 'Join %s', 'mission-donation-platform' ), $preselected_team->name )
		: $campaign->title;

	$context = [
		'restUrl'             => trailingslashit( get_rest_url( null, 'mission-donation-platform/v1' ) ),
		'nonce'               => wp_create_nonce( 'wp_rest' ),
		'campaignId'          => (int) $campaign->id,
		'currency'            => $currency,
		'defaultGoal'         => $default_goal,
		'teamsEnabled'        => $teams_enabled,
		'teamCreationEnabled' => $creation_enabled,
		'preselectedTeamId'   => $preselected_team ? (int) $preselected_team->id : 0,
		'signedIn'            => (bool) $current_donor,
		'donorName'           => $current_donor ? trim( $current_donor->first_name . ' ' . $current_donor->last_name ) : '',
		'donorEmail'          => $current_donor ? $current_donor->email : '',
		// Translated strings for view.js (script modules can't import @wordpress/i18n).
		'i18n'                => [
			'genericError'     => __( 'Something went wrong. Please try again.', 'mission-donation-platform' ),
			'copy'             => __( 'Copy', 'mission-donation-platform' ),
			'copied'           => __( 'Copied', 'mission-donation-platform' ),
			'copyFailed'       => __( 'Copy failed', 'mission-donation-platform' ),
			'enterCode'        => __( 'Enter the 6-digit code.', 'mission-donation-platform' ),
			'enterNewPassword' => __( 'Enter a new password.', 'mission-donation-platform' ),
		],
	];

	/**
	 * Filters the heading on the sign-up success screen.
	 *
	 * @param string   $title    Default heading.
	 * @param Campaign $campaign The campaign being fundraised for.
	 */
	$success_title = apply_filters( 'mission_signup_success_title', __( "You're a fundraiser!", 'mission-donation-platform' ), $campaign );

	/**
	 * Filters the message on the sign-up success screen.
	 *
	 * @param string   $message  Default message.
	 * @param Campaign $campaign The campaign being fundraised for.
	 */
	$success_message = apply_filters( 'mission_signup_success_message', __( 'Your page is live. Share it with friends and family to start raising funds.', 'mission-donation-platform' ), $campaign );

	/**
	 * Filters the heading on the sign-up success screen when approval is pending.
	 *
	 * @param string   $title    Default heading.
	 * @param Campaign $campaign The campaign being fundraised for.
	 */
	$pending_title = apply_filters( 'mission_signup_pending_title', __( "You're almost there!", 'mission-donation-platform' ), $campaign );

	/**
	 * Filters the message on the sign-up success screen when approval is pending.
	 *
	 * @param string   $message  Default message.
	 * @param Campaign $campaign The campaign being fundraised for.
	 */
	$pending_message = apply_filters( 'mission_signup_pending_message', __( "Your fundraising page has been submitted for review. We'll email you as soon as it's approved and ready to share.", 'mission-donation-platform' ), $campaign );

	// Success-screen share buttons, in render order (see mission_share_networks).
	$share_networks = Sharing::networks( 'signup-modal' );
	$share_buttons  = [
		'facebook' => [
			'action' => 'actions.shareFacebook',
			'label'  => __( 'Share on Facebook', 'mission-donation-platform' ),
		],
		'x'        => [
			'action' => 'actions.shareX',
			'label'  => __( 'Share on X', 'mission-donation-platform' ),
		],
		'bluesky'  => [
			'action' => 'actions.shareBluesky',
			'label'  => __( 'Share on Bluesky', 'mission-donation-platform' ),
		],
	];

	ob_start();
	?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-su' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/p2p-signup"
	<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core function, self-escaping. ?>
	data-wp-init="callbacks.init"
	style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
>
	<div
		class="mission-su__overlay"
		data-wp-class--is-open="state.isOpen"
		data-wp-on--keydown="actions.onKeydown"
	>
		<div class="mission-su__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $brandline ); ?>" tabindex="-1">
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

					<!-- Signed-in shortcut -->
					<div data-wp-bind--hidden="!state.isSignedInView">
						<h2 class="mission-su__title"><?php esc_html_e( 'Welcome back', 'mission-donation-platform' ); ?></h2>
						<p class="mission-su__subtitle"><?php esc_html_e( 'Continue setting up your fundraiser.', 'mission-donation-platform' ); ?></p>
						<div class="mission-su__identity">
							<strong data-wp-text="state.donorName"></strong>
							<span data-wp-text="state.donorEmail"></span>
						</div>
						<button type="button" class="mission-su__btn" data-wp-on--click="actions.continueSignedIn"><?php esc_html_e( 'Continue', 'mission-donation-platform' ); ?></button>
						<p class="mission-su__note">
							<?php esc_html_e( 'Not you?', 'mission-donation-platform' ); ?>
							<button type="button" class="mission-su__link" data-wp-on--click="actions.logout"><?php esc_html_e( 'Log out', 'mission-donation-platform' ); ?></button>
						</p>
					</div>

					<!-- Account form -->
					<div data-wp-bind--hidden="!state.isFormView">
						<h2 class="mission-su__title"><?php esc_html_e( 'Create your account', 'mission-donation-platform' ); ?></h2>
						<p class="mission-su__subtitle"><?php esc_html_e( "Let's set up your fundraising page. First, the basics.", 'mission-donation-platform' ); ?></p>

						<div class="mission-su__row">
							<label class="mission-su__field"><span><?php esc_html_e( 'First name', 'mission-donation-platform' ); ?></span><input type="text" autocomplete="given-name" data-wp-bind--value="state.firstName" data-wp-on--input="actions.updateFirstName" data-wp-class--mission-su__input--error="state.firstNameError" /></label>
							<label class="mission-su__field"><span><?php esc_html_e( 'Last name', 'mission-donation-platform' ); ?></span><input type="text" autocomplete="family-name" data-wp-bind--value="state.lastName" data-wp-on--input="actions.updateLastName" data-wp-class--mission-su__input--error="state.lastNameError" /></label>
						</div>
						<label class="mission-su__field"><span><?php esc_html_e( 'Email', 'mission-donation-platform' ); ?></span><input type="email" autocomplete="email" data-wp-bind--value="state.email" data-wp-on--input="actions.updateEmail" data-wp-class--mission-su__input--error="state.emailError" /></label>
						<label class="mission-su__field"><span><?php esc_html_e( 'Password', 'mission-donation-platform' ); ?></span><input type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'At least 8 characters', 'mission-donation-platform' ); ?>" data-wp-bind--value="state.password" data-wp-on--input="actions.updatePassword" data-wp-class--mission-su__input--error="state.passwordError" /></label>

						<p class="mission-su__warning" data-wp-bind--hidden="!state.showPasswordWarning">
							<?php esc_html_e( 'An account with this email already exists. Enter the correct password or', 'mission-donation-platform' ); ?>
							<button type="button" class="mission-su__link" data-wp-on--click="actions.startReset"><?php esc_html_e( 'reset it', 'mission-donation-platform' ); ?></button>.
						</p>
						<p class="mission-su__error" data-wp-bind--hidden="!state.formError" data-wp-text="state.formError"></p>

						<button type="button" class="mission-su__btn" data-wp-bind--disabled="state.loading" data-wp-bind--aria-busy="state.loading" data-wp-class--is-loading="state.loading" data-wp-on--click="actions.continueAccount">
							<span data-wp-bind--hidden="state.loading"><?php esc_html_e( 'Continue', 'mission-donation-platform' ); ?></span>
							<span class="mission-su__spinner" data-wp-bind--hidden="!state.loading" aria-hidden="true"></span>
						</button>
					</div>

					<!-- 6-digit code (signup verify or password reset) -->
					<div data-wp-bind--hidden="!state.isOtpView">
						<button type="button" class="mission-su__back" data-wp-on--click="actions.showForm"><?php esc_html_e( 'Change email', 'mission-donation-platform' ); ?></button>
						<h2 class="mission-su__title"><?php esc_html_e( 'Verify your email', 'mission-donation-platform' ); ?></h2>
						<p class="mission-su__subtitle">
							<?php esc_html_e( 'We sent a 6-digit code to', 'mission-donation-platform' ); ?>
							<strong data-wp-text="state.email"></strong>.
						</p>
						<div class="mission-su__otp">
							<?php for ( $i = 0; $i < 6; $i++ ) : ?>
								<input class="mission-su__otp-input" inputmode="numeric" maxlength="1" autocomplete="one-time-code" data-otp-index="<?php echo (int) $i; ?>" data-wp-on--input="actions.onOtpInput" data-wp-on--keydown="actions.onOtpKeydown" data-wp-on--paste="actions.onOtpPaste" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: digit position */ __( 'Digit %d', 'mission-donation-platform' ), $i + 1 ) ); ?>" />
							<?php endfor; ?>
						</div>
						<p class="mission-su__error" data-wp-bind--hidden="!state.otpError" data-wp-text="state.otpError"></p>
						<button type="button" class="mission-su__btn" data-wp-bind--disabled="state.loading" data-wp-bind--aria-busy="state.loading" data-wp-class--is-loading="state.loading" data-wp-on--click="actions.verifyCode">
							<span data-wp-bind--hidden="state.loading"><?php esc_html_e( 'Verify', 'mission-donation-platform' ); ?></span>
							<span class="mission-su__spinner" data-wp-bind--hidden="!state.loading" aria-hidden="true"></span>
						</button>
						<p class="mission-su__resend">
							<?php esc_html_e( "Didn't get a code?", 'mission-donation-platform' ); ?>
							<button type="button" class="mission-su__link" data-wp-bind--disabled="state.resendIn" data-wp-on--click="actions.resendCode"><?php esc_html_e( 'Resend', 'mission-donation-platform' ); ?></button>
							<span data-wp-text="state.resendLabel"></span>
						</p>
					</div>

					<!-- New password (after a verified reset code) -->
					<div data-wp-bind--hidden="!state.isNewpassView">
						<h2 class="mission-su__title"><?php esc_html_e( 'Set a new password', 'mission-donation-platform' ); ?></h2>
						<p class="mission-su__subtitle"><?php esc_html_e( 'Choose a new password for your account.', 'mission-donation-platform' ); ?></p>
						<label class="mission-su__field"><span><?php esc_html_e( 'New password', 'mission-donation-platform' ); ?></span><input type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'At least 8 characters', 'mission-donation-platform' ); ?>" data-wp-bind--value="state.newPassword" data-wp-on--input="actions.updateNewPassword" /></label>
						<p class="mission-su__error" data-wp-bind--hidden="!state.formError" data-wp-text="state.formError"></p>
						<button type="button" class="mission-su__btn" data-wp-bind--disabled="state.loading" data-wp-bind--aria-busy="state.loading" data-wp-class--is-loading="state.loading" data-wp-on--click="actions.savePassword">
							<span data-wp-bind--hidden="state.loading"><?php esc_html_e( 'Save and continue', 'mission-donation-platform' ); ?></span>
							<span class="mission-su__spinner" data-wp-bind--hidden="!state.loading" aria-hidden="true"></span>
						</button>
					</div>
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
								<span><?php esc_html_e( 'Select a team', 'mission-donation-platform' ); ?> <span class="mission-su__optional"><?php esc_html_e( '(optional)', 'mission-donation-platform' ); ?></span></span>
								<select data-wp-on--change="actions.updateTeamId">
									<option value=""><?php esc_html_e( 'No team — fundraise on your own', 'mission-donation-platform' ); ?></option>
									<?php foreach ( $teams as $team ) : ?>
										<option value="<?php echo esc_attr( (string) $team->id ); ?>"><?php echo esc_html( $team->name ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="mission-su__hint"><?php esc_html_e( 'Leave blank to fundraise on your own.', 'mission-donation-platform' ); ?></span>
							</label>
						</div>
						<?php if ( $creation_enabled ) : ?>
							<div class="mission-su__panel" data-wp-class--is-active="state.isCreateMode">
								<label class="mission-su__field"><span><?php esc_html_e( 'Team name', 'mission-donation-platform' ); ?></span><input type="text" data-wp-bind--value="state.teamName" data-wp-on--input="actions.updateTeamName" /></label>
								<div class="mission-su__field">
									<span><?php esc_html_e( 'Team access', 'mission-donation-platform' ); ?></span>
									<div class="mission-su__access">
										<label class="mission-su__access-toggle">
											<input type="checkbox" aria-label="<?php esc_attr_e( 'Make this team private', 'mission-donation-platform' ); ?>" data-wp-bind--checked="state.teamPrivate" data-wp-on--change="actions.updateTeamPrivate" />
											<span class="mission-su__access-track">
												<span class="mission-su__access-knob"></span>
												<span class="mission-su__access-labels">
													<span class="mission-su__access-text mission-su__access-text--public"><?php esc_html_e( 'Public', 'mission-donation-platform' ); ?></span>
													<span class="mission-su__access-text mission-su__access-text--private"><?php esc_html_e( 'Private', 'mission-donation-platform' ); ?></span>
												</span>
											</span>
										</label>
										<p class="mission-su__hint" data-wp-bind--hidden="state.teamPrivate"><?php esc_html_e( 'Anyone can join your team.', 'mission-donation-platform' ); ?></p>
										<p class="mission-su__hint" data-wp-bind--hidden="!state.teamPrivate"><?php esc_html_e( 'Only people you invite can join.', 'mission-donation-platform' ); ?></p>
									</div>
								</div>
								<p class="mission-su__hint"><?php esc_html_e( 'You can add a team logo later from your dashboard.', 'mission-donation-platform' ); ?></p>
							</div>
						<?php endif; ?>
					<?php endif; ?>

					<label class="mission-su__field">
						<span><?php esc_html_e( 'Your fundraising goal', 'mission-donation-platform' ); ?></span>
						<span class="mission-su__prefix-wrap">
							<span class="mission-su__prefix"><?php echo esc_html( Currency::get_symbol( $currency ) ); ?></span>
							<input type="number" min="1" data-wp-bind--value="state.goal" data-wp-on--input="actions.updateGoal" />
						</span>
					</label>
					<label class="mission-su__field"><span><?php esc_html_e( 'Tell your story', 'mission-donation-platform' ); ?></span><textarea rows="4" placeholder="<?php echo esc_attr( $story_placeholder ); ?>" data-wp-bind--value="state.story" data-wp-on--input="actions.updateStory"></textarea></label>

					<label class="mission-su__check">
						<input type="checkbox" data-wp-on--change="actions.toggleTribute" />
						<?php esc_html_e( 'Dedicate this fundraiser in honor or memory of someone', 'mission-donation-platform' ); ?>
					</label>
					<div class="mission-su__panel" data-wp-class--is-active="state.tributeChecked">
						<div class="mission-su__toggle">
							<button type="button" class="mission-su__toggle-btn" data-wp-class--is-active="state.isHonor" data-wp-on--click="actions.setHonor"><?php esc_html_e( 'In honor of', 'mission-donation-platform' ); ?></button>
							<button type="button" class="mission-su__toggle-btn" data-wp-class--is-active="state.isMemory" data-wp-on--click="actions.setMemory"><?php esc_html_e( 'In memory of', 'mission-donation-platform' ); ?></button>
						</div>
						<label class="mission-su__field"><span><?php esc_html_e( 'Their name', 'mission-donation-platform' ); ?></span><input type="text" data-wp-bind--value="state.honoreeName" data-wp-on--input="actions.updateHonoreeName" /></label>
					</div>

					<p class="mission-su__error" data-wp-bind--hidden="!state.formError" data-wp-text="state.formError"></p>
					<button type="button" class="mission-su__btn" data-wp-bind--disabled="state.loading" data-wp-bind--aria-busy="state.loading" data-wp-class--is-loading="state.loading" data-wp-on--click="actions.submit">
						<span data-wp-bind--hidden="state.loading"><?php esc_html_e( 'Create fundraiser', 'mission-donation-platform' ); ?></span>
						<span class="mission-su__spinner" data-wp-bind--hidden="!state.loading" aria-hidden="true"></span>
					</button>
					<button type="button" class="mission-su__btn mission-su__btn--ghost" data-wp-bind--hidden="state.signedIn" data-wp-on--click="actions.back"><?php esc_html_e( 'Back', 'mission-donation-platform' ); ?></button>
				</div>

				<!-- Step 3: success (live page, or submitted pending approval) -->
				<div class="mission-su__step" data-wp-class--is-active="state.isStep3">
					<div class="mission-su__success" data-wp-bind--hidden="state.isPending">
						<h2 class="mission-su__title mission-su__title--success"><?php echo esc_html( $success_title ); ?></h2>
						<p class="mission-su__subtitle"><?php echo esc_html( $success_message ); ?></p>
						<div class="mission-su__share">
							<?php foreach ( $share_networks as $network ) : ?>
								<button type="button" class="mission-su__share-btn" data-wp-on--click="<?php echo esc_attr( $share_buttons[ $network ]['action'] ); ?>" aria-label="<?php echo esc_attr( $share_buttons[ $network ]['label'] ); ?>"><span class="<?php echo esc_attr( 'mission-su__share-icon mission-su__share-icon--' . $network ); ?>" aria-hidden="true"></span></button>
							<?php endforeach; ?>
							<button type="button" class="mission-su__share-btn" data-wp-class--is-copied="state.copied" data-wp-on--click="actions.copyLink" aria-label="<?php esc_attr_e( 'Copy link', 'mission-donation-platform' ); ?>"><span class="mission-su__share-icon mission-su__share-icon--copy" aria-hidden="true"></span><span class="mission-su__sr-only" aria-live="polite" data-wp-text="state.copyLabel"></span></button>
						</div>
						<a class="mission-su__btn" data-wp-bind--href="state.successUrl" target="_blank" rel="noopener"><?php esc_html_e( 'View my page', 'mission-donation-platform' ); ?></a>
					</div>
					<div class="mission-su__success" data-wp-bind--hidden="!state.isPending">
						<h2 class="mission-su__title mission-su__title--success"><?php echo esc_html( $pending_title ); ?></h2>
						<p class="mission-su__subtitle"><?php echo esc_html( $pending_message ); ?></p>
						<button type="button" class="mission-su__btn" data-wp-on--click="actions.close"><?php esc_html_e( 'Done', 'mission-donation-platform' ); ?></button>
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
