<?php
/**
 * The fundraiser sign-up modal shell.
 *
 * Rendered once per page by SignupModal::render() and opened by the sign-up
 * CTAs in other blocks, which pass their own campaign payload to the store's
 * `open( payload )`. The markup is campaign-agnostic: every campaign-dependent
 * value (brandline, teams list, currency symbol, success copy) is a state
 * binding, server-rendered here with the default payload's values and rebound
 * at open time. Step 1 branches on the email (login, inline OTP verification,
 * or inline password reset); steps 2-3 are the fundraiser setup and the
 * share-focused success screen. Submission is wired in view.js against the
 * /p2p REST routes.
 *
 * @package MissionDP
 *
 * @var array                        $payload          Default sign-up payload (see SignupModal::payload()).
 * @var array                        $context          Shell Interactivity context (restUrl, nonce, i18n, shareTemplates).
 * @var string[]                     $share_networks   Enabled share networks, in display order.
 * @var \MissionDP\Models\Campaign   $campaign         Campaign the shell defaults to.
 * @var \MissionDP\Models\Team|null  $preselected_team Preselected team, when rendered on a team page.
 */

use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;

// Success-screen share buttons, in render order (see mission_share_networks).
$share_buttons = [
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
?>
<div
	class="mission-su"
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
		<div class="mission-su__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $payload['brandline'] ); ?>" data-wp-bind--aria-label="state.brandline" tabindex="-1">
			<div class="mission-su__head">
				<span class="mission-su__brand" data-wp-text="state.brandline"><?php echo esc_html( $payload['brandline'] ); ?></span>
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

					<div class="mission-su__locked" data-wp-bind--hidden="!state.preselectedTeamId" <?php echo $preselected_team ? '' : 'hidden'; ?>>
						<strong data-wp-text="state.preselectedTeamName"><?php echo esc_html( $payload['preselectedTeamName'] ); ?></strong>
						<span><?php esc_html_e( 'Joining as a team member', 'mission-donation-platform' ); ?></span>
					</div>

					<div data-wp-bind--hidden="!state.showTeamChooser" <?php echo $payload['showTeamChooser'] ? '' : 'hidden'; ?>>
						<div class="mission-su__toggle">
							<button type="button" class="mission-su__toggle-btn" data-wp-class--is-active="state.isJoinMode" data-wp-on--click="actions.setJoinMode"><?php esc_html_e( 'Join a team', 'mission-donation-platform' ); ?></button>
							<button type="button" class="mission-su__toggle-btn" data-wp-class--is-active="state.isCreateMode" data-wp-on--click="actions.setCreateMode" data-wp-bind--hidden="!state.teamCreationEnabled" <?php echo $payload['teamCreationEnabled'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Create a team', 'mission-donation-platform' ); ?></button>
						</div>
						<div class="mission-su__panel" data-wp-class--is-active="state.isJoinMode">
							<label class="mission-su__field">
								<span><?php esc_html_e( 'Select a team', 'mission-donation-platform' ); ?> <span class="mission-su__optional"><?php esc_html_e( '(optional)', 'mission-donation-platform' ); ?></span></span>
								<select data-wp-bind--value="state.teamId" data-wp-on--change="actions.updateTeamId">
									<option value=""><?php esc_html_e( 'No team — fundraise on your own', 'mission-donation-platform' ); ?></option>
									<template data-wp-each--team="state.teams" data-wp-each-key="context.team.id">
										<option data-wp-bind--value="context.team.id" data-wp-text="context.team.name"></option>
									</template>
									<?php foreach ( $payload['teams'] as $team_option ) : ?>
										<option data-wp-each-child="mission-donation-platform/p2p-signup::state.teams" value="<?php echo esc_attr( $team_option['id'] ); ?>"><?php echo esc_html( $team_option['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="mission-su__hint"><?php esc_html_e( 'Leave blank to fundraise on your own.', 'mission-donation-platform' ); ?></span>
							</label>
						</div>
						<div class="mission-su__panel" data-wp-class--is-active="state.isCreateMode" data-wp-bind--hidden="!state.teamCreationEnabled" <?php echo $payload['teamCreationEnabled'] ? '' : 'hidden'; ?>>
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
					</div>

					<label class="mission-su__field">
						<span><?php esc_html_e( 'Your fundraising goal', 'mission-donation-platform' ); ?></span>
						<span class="mission-su__prefix-wrap">
							<span class="mission-su__prefix" data-wp-text="state.currencySymbol"><?php echo esc_html( $payload['currencySymbol'] ); ?></span>
							<input type="number" min="1" data-wp-bind--value="state.goal" data-wp-on--input="actions.updateGoal" />
						</span>
					</label>
					<label class="mission-su__field"><span><?php esc_html_e( 'Tell your story', 'mission-donation-platform' ); ?></span><textarea rows="4" placeholder="<?php echo esc_attr( $payload['storyPlaceholder'] ); ?>" data-wp-bind--placeholder="state.storyPlaceholder" data-wp-bind--value="state.story" data-wp-on--input="actions.updateStory"></textarea></label>

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
						<h2 class="mission-su__title mission-su__title--success" data-wp-text="state.successTitle"><?php echo esc_html( $payload['successTitle'] ); ?></h2>
						<p class="mission-su__subtitle" data-wp-text="state.successMessage"><?php echo esc_html( $payload['successMessage'] ); ?></p>
						<div class="mission-su__share">
							<?php foreach ( $share_networks as $network ) : ?>
								<button type="button" class="mission-su__share-btn" data-wp-on--click="<?php echo esc_attr( $share_buttons[ $network ]['action'] ); ?>" aria-label="<?php echo esc_attr( $share_buttons[ $network ]['label'] ); ?>"><span class="<?php echo esc_attr( 'mission-su__share-icon mission-su__share-icon--' . $network ); ?>" aria-hidden="true"></span></button>
							<?php endforeach; ?>
							<button type="button" class="mission-su__share-btn" data-wp-class--is-copied="state.copied" data-wp-on--click="actions.copyLink" aria-label="<?php esc_attr_e( 'Copy link', 'mission-donation-platform' ); ?>"><span class="mission-su__share-icon mission-su__share-icon--copy" aria-hidden="true"></span><span class="mission-su__sr-only" aria-live="polite" data-wp-text="state.copyLabel"></span></button>
						</div>
						<a class="mission-su__btn" data-wp-bind--href="state.successUrl" target="_blank" rel="noopener"><?php esc_html_e( 'View my page', 'mission-donation-platform' ); ?></a>
					</div>
					<div class="mission-su__success" data-wp-bind--hidden="!state.isPending">
						<h2 class="mission-su__title mission-su__title--success" data-wp-text="state.pendingTitle"><?php echo esc_html( $payload['pendingTitle'] ); ?></h2>
						<p class="mission-su__subtitle" data-wp-text="state.pendingMessage"><?php echo esc_html( $payload['pendingMessage'] ); ?></p>
						<button type="button" class="mission-su__btn" data-wp-on--click="actions.close"><?php esc_html_e( 'Done', 'mission-donation-platform' ); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
