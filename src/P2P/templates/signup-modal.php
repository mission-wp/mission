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
 * or inline password reset); step 2 is the fundraiser setup; step 3 is the
 * success screen with the first-gift nudge (amount picker, Stripe payment, and
 * a share-focused thank-you), falling back to a share-only screen when Stripe
 * charges are disabled. Submission is wired in view.js/gift.js against the
 * /p2p and /donations REST routes.
 *
 * @package MissionDP
 *
 * @var array                        $payload          Default sign-up payload (see SignupModal::payload()).
 * @var array                        $context          Shell Interactivity context (restUrl, nonce, i18n, shareTemplates).
 * @var string[]                     $share_networks   Enabled share networks, in display order.
 * @var \MissionDP\Models\Campaign   $campaign         Campaign the shell defaults to.
 * @var \MissionDP\Models\Team|null  $preselected_team Preselected team, when rendered on a team page.
 */

use MissionDP\Currency\Currency;
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
	'email'    => [
		'action' => 'actions.shareEmail',
		'label'  => __( 'Share by email', 'mission-donation-platform' ),
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
							<label class="mission-su__field"><span><?php esc_html_e( 'First name', 'mission-donation-platform' ); ?></span><input type="text" autocomplete="given-name" data-wp-bind--value="state.firstName" data-wp-on--input="actions.updateFirstName" data-wp-class--mission-su__input--error="state.firstNameError" data-wp-bind--aria-invalid="state.firstNameError" /></label>
							<label class="mission-su__field"><span><?php esc_html_e( 'Last name', 'mission-donation-platform' ); ?></span><input type="text" autocomplete="family-name" data-wp-bind--value="state.lastName" data-wp-on--input="actions.updateLastName" data-wp-class--mission-su__input--error="state.lastNameError" data-wp-bind--aria-invalid="state.lastNameError" /></label>
						</div>
						<label class="mission-su__field"><span><?php esc_html_e( 'Email', 'mission-donation-platform' ); ?></span><input type="email" autocomplete="email" data-wp-bind--value="state.email" data-wp-on--input="actions.updateEmail" data-wp-class--mission-su__input--error="state.emailError" data-wp-bind--aria-invalid="state.emailError" /></label>
						<label class="mission-su__field"><span><?php esc_html_e( 'Password', 'mission-donation-platform' ); ?></span><input type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'At least 8 characters', 'mission-donation-platform' ); ?>" data-wp-bind--value="state.password" data-wp-on--input="actions.updatePassword" data-wp-class--mission-su__input--error="state.passwordError" data-wp-bind--aria-invalid="state.passwordError" /></label>

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

				<!-- Step 3: first-gift nudge, payment, and thanks (or pending approval) -->
				<div class="mission-su__step" data-wp-class--is-active="state.isStep3">

					<?php // kickoffEnabled is site-global (Stripe charges), so the nudge/payment/thanks panels vs the share-only panel is a render-time branch, not a binding. ?>
					<?php if ( ! $payload['kickoffEnabled'] ) : ?>
					<!-- Success without the nudge (Stripe charges disabled) -->
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
					<?php else : ?>
					<!-- Success + first-gift nudge -->
					<div class="mission-su__success" data-wp-bind--hidden="!state.isNudgeView">
						<h2 class="mission-su__title mission-su__title--success" data-wp-text="state.successTitle"><?php echo esc_html( $payload['successTitle'] ); ?></h2>
						<p class="mission-su__subtitle" data-wp-text="state.kickoffMessage"><?php echo esc_html( $payload['kickoffMessage'] ); ?></p>

						<h3 class="mission-su__kickoff-heading" data-wp-text="state.kickoffHeadlineText"></h3>
						<button type="button" class="mission-su__link mission-su__kickoff-change" data-wp-on--click="actions.toggleGiftPicker"><?php esc_html_e( 'Change amount', 'mission-donation-platform' ); ?></button>

						<div class="mission-su__kickoff-picker" data-wp-bind--hidden="!state.giftPickerOpen" hidden>
							<div class="mission-su__amounts">
								<template data-wp-each--preset="state.kickoffAmounts" data-wp-each-key="context.preset">
									<button type="button" class="mission-su__amount-btn" data-wp-class--is-active="callbacks.isGiftPresetActive" data-wp-on--click="actions.selectGiftPreset" data-wp-text="callbacks.giftPresetLabel"></button>
								</template>
								<?php foreach ( $payload['kickoffAmounts'] as $kickoff_amount ) : ?>
									<button type="button" class="mission-su__amount-btn" data-wp-each-child="mission-donation-platform/p2p-signup::state.kickoffAmounts" data-wp-context='<?php echo esc_attr( wp_json_encode( [ 'preset' => $kickoff_amount ] ) ); ?>' data-wp-class--is-active="callbacks.isGiftPresetActive" data-wp-on--click="actions.selectGiftPreset" data-wp-text="callbacks.giftPresetLabel"><?php echo esc_html( $payload['currencySymbol'] . Currency::minor_to_major( $kickoff_amount, $payload['currency'] ) ); ?></button>
								<?php endforeach; ?>
								<div class="mission-su__amount-other-cell">
									<button type="button" class="mission-su__amount-btn mission-su__amount-btn--other" data-wp-bind--hidden="state.isCustomGift" data-wp-on--click="actions.chooseOtherGift"><?php esc_html_e( 'Other', 'mission-donation-platform' ); ?></button>
									<div class="mission-su__amount-other-input" hidden data-wp-bind--hidden="!state.isCustomGift">
										<span class="mission-su__amount-other-prefix" data-wp-text="state.currencySymbol"><?php echo esc_html( $payload['currencySymbol'] ); ?></span>
										<input type="number" class="mission-su__amount-other-field" placeholder="0.00" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Custom amount', 'mission-donation-platform' ); ?>" data-wp-bind--value="state.customGiftValue" data-wp-on--input="actions.updateCustomGift" data-wp-on--blur="actions.blurCustomGift" data-wp-watch="callbacks.focusCustomGiftInput" />
									</div>
								</div>
							</div>
						</div>

						<button type="button" class="mission-su__btn mission-su__btn--inline" data-wp-on--click="actions.showGiftPayment"><?php esc_html_e( 'Make the first gift', 'mission-donation-platform' ); ?></button>
						<p class="mission-su__note"><a class="mission-su__skip" data-wp-bind--href="state.successUrl"><?php esc_html_e( 'Skip for now and view my page', 'mission-donation-platform' ); ?></a></p>
					</div>

					<!-- First-gift payment -->
					<div class="mission-su__pay" data-wp-bind--hidden="!state.isGiftPaymentView" hidden>
						<button type="button" class="mission-su__back" data-wp-on--click="actions.backToNudge"><?php esc_html_e( 'Back', 'mission-donation-platform' ); ?></button>

						<div class="mission-su__pay-amount" data-wp-text="state.giftAmountDisplay"></div>

						<p class="mission-su__pay-fee" data-wp-bind--hidden="!state.feeRecovery">
							<span class="mission-su__pay-fee-text" data-wp-class--uncovered="!state.giftFeeCovered">+ <span data-wp-text="state.giftFeeDisplay"></span> <?php esc_html_e( 'processing fee', 'mission-donation-platform' ); ?></span>
							<button type="button" class="mission-su__link mission-su__pay-fee-edit" data-wp-bind--hidden="!state.isGiftFeeOptional" data-wp-on--click="actions.toggleGiftFeeDetails"><?php esc_html_e( 'Edit', 'mission-donation-platform' ); ?></button>
						</p>
						<div class="mission-su__pay-fee-details" data-wp-bind--hidden="!state.showGiftFeeDetails" hidden>
							<p><?php esc_html_e( 'Payment processors take a cut of each transaction. You have the option to cover these fees so 100% of your gift can go to the cause you care about.', 'mission-donation-platform' ); ?></p>
							<label class="mission-su__check">
								<input type="checkbox" data-wp-bind--checked="state.giftFeeCovered" data-wp-on--change="actions.toggleGiftFeeCovered" />
								<?php esc_html_e( 'I want to cover the fee', 'mission-donation-platform' ); ?>
							</label>
						</div>

						<p class="mission-su__pay-identity">
							<?php esc_html_e( 'Donating as', 'mission-donation-platform' ); ?>
							<strong data-wp-text="state.donorName"></strong>
							(<span data-wp-text="state.donorEmail"></span>)
						</p>

						<p class="mission-su__pay-testmode" data-wp-bind--hidden="!state.testMode" hidden>
							<?php esc_html_e( 'Test mode active: Donations in test mode are not processed', 'mission-donation-platform' ); ?>
						</p>

						<div class="mission-su__payment-element" data-wp-watch="callbacks.watchGiftAmounts"></div>

						<p class="mission-su__error" role="alert" data-wp-bind--hidden="!state.giftError" data-wp-text="state.giftError"></p>
						<p class="mission-su__hint mission-su__pay-slow" role="status" data-wp-bind--hidden="!state.giftTakingLong" hidden>
							<?php esc_html_e( 'This is taking longer than expected. If nothing happens shortly, refresh the page and try again. Your card is only charged when a payment completes.', 'mission-donation-platform' ); ?>
						</p>

						<div class="mission-su__tip" data-wp-bind--hidden="!state.tipEnabled" data-wp-on-document--click="actions.closeGiftTipMenu" <?php echo $payload['tipEnabled'] ? '' : 'hidden'; ?>>
							<div class="mission-su__tip-card">
								<div class="mission-su__tip-header">
									<p class="mission-su__tip-text"><?php esc_html_e( 'An optional tip keeps this free donation platform running', 'mission-donation-platform' ); ?></p>
									<div class="mission-su__tip-trigger-wrap">
										<button type="button" class="mission-su__tip-trigger" data-wp-on--click="actions.toggleGiftTipMenu" data-wp-bind--aria-expanded="state.giftTipMenuOpen" aria-label="<?php esc_attr_e( 'Select tip amount', 'mission-donation-platform' ); ?>">
											<span class="mission-su__tip-chevron mission-su__tip-chevron--up" aria-hidden="true"></span>
											<span class="mission-su__tip-value" data-wp-text="state.giftTipTriggerLabel">15%</span>
											<span class="mission-su__tip-chevron" aria-hidden="true"></span>
										</button>
										<div class="mission-su__tip-menu" data-wp-bind--hidden="!state.giftTipMenuOpen" hidden>
											<button type="button" class="mission-su__tip-option" data-wp-context='{"tipPercent":20}' data-wp-on--click="actions.selectGiftTipPercent" data-wp-class--is-active="callbacks.isGiftTipOptionActive">20%</button>
											<button type="button" class="mission-su__tip-option" data-wp-context='{"tipPercent":15}' data-wp-on--click="actions.selectGiftTipPercent" data-wp-class--is-active="callbacks.isGiftTipOptionActive">15%</button>
											<button type="button" class="mission-su__tip-option" data-wp-context='{"tipPercent":10}' data-wp-on--click="actions.selectGiftTipPercent" data-wp-class--is-active="callbacks.isGiftTipOptionActive">10%</button>
											<button type="button" class="mission-su__tip-option" data-wp-class--is-active="state.isCustomGiftTip" data-wp-on--click="actions.selectGiftCustomTip"><?php esc_html_e( 'Other', 'mission-donation-platform' ); ?></button>
										</div>
									</div>
								</div>
								<div class="mission-su__tip-custom" data-wp-bind--hidden="!state.isCustomGiftTip" hidden>
									<span class="mission-su__tip-custom-label">
										<span class="mission-su__tip-heart" aria-hidden="true"></span>
										<?php esc_html_e( 'Help keep this platform free', 'mission-donation-platform' ); ?>
									</span>
									<span class="mission-su__tip-stepper">
										<?php // Literal characters: kses does not recognize the &plus;/&minus; entities. ?>
										<button type="button" class="mission-su__tip-step-btn" data-wp-on--click="actions.giftTipDown" aria-label="<?php esc_attr_e( 'Decrease tip', 'mission-donation-platform' ); ?>">−</button>
										<span class="mission-su__tip-input-wrap">
											<span class="mission-su__tip-input-prefix" data-wp-text="state.currencySymbol"><?php echo esc_html( $payload['currencySymbol'] ); ?></span>
											<input type="number" class="mission-su__tip-input" min="0" step="1" data-wp-bind--value="callbacks.customGiftTipDisplay" data-wp-on--input="actions.updateGiftCustomTip" aria-label="<?php esc_attr_e( 'Custom tip amount', 'mission-donation-platform' ); ?>" />
										</span>
										<button type="button" class="mission-su__tip-step-btn" data-wp-on--click="actions.giftTipUp" aria-label="<?php esc_attr_e( 'Increase tip', 'mission-donation-platform' ); ?>">+</button>
									</span>
								</div>
							</div>
						</div>

						<button type="button" class="mission-su__btn" data-wp-on--click="actions.submitGift" data-wp-bind--disabled="state.isSubmittingGift" data-wp-bind--aria-busy="state.isSubmittingGift" data-wp-class--is-loading="state.isSubmittingGift">
							<span data-wp-bind--hidden="state.isSubmittingGift" data-wp-text="state.giftSubmitLabel"></span>
							<span data-wp-bind--hidden="!state.isSubmittingGift" hidden><?php esc_html_e( 'Processing', 'mission-donation-platform' ); ?></span>
							<span class="mission-su__spinner" data-wp-bind--hidden="!state.isSubmittingGift" aria-hidden="true"></span>
						</button>
					</div>

					<!-- First-gift complete -->
					<div class="mission-su__success" data-wp-bind--hidden="!state.isGiftSuccessView" hidden>
						<span class="mission-su__success-check" aria-hidden="true"></span>
						<h2 class="mission-su__title mission-su__title--success"><?php esc_html_e( 'Thank you!', 'mission-donation-platform' ); ?></h2>
						<p class="mission-su__subtitle" data-wp-text="state.giftSuccessText"></p>
						<div class="mission-su__share">
							<?php foreach ( $share_networks as $network ) : ?>
								<button type="button" class="mission-su__share-btn" data-wp-on--click="<?php echo esc_attr( $share_buttons[ $network ]['action'] ); ?>" aria-label="<?php echo esc_attr( $share_buttons[ $network ]['label'] ); ?>"><span class="<?php echo esc_attr( 'mission-su__share-icon mission-su__share-icon--' . $network ); ?>" aria-hidden="true"></span></button>
							<?php endforeach; ?>
							<button type="button" class="mission-su__share-btn" data-wp-class--is-copied="state.copied" data-wp-on--click="actions.copyLink" aria-label="<?php esc_attr_e( 'Copy link', 'mission-donation-platform' ); ?>"><span class="mission-su__share-icon mission-su__share-icon--copy" aria-hidden="true"></span><span class="mission-su__sr-only" aria-live="polite" data-wp-text="state.copyLabel"></span></button>
						</div>
						<a class="mission-su__btn" data-wp-bind--href="state.successUrl" target="_blank" rel="noopener"><?php esc_html_e( 'View my page', 'mission-donation-platform' ); ?></a>
					</div>
					<?php endif; ?>

					<!-- Submitted pending approval -->
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
