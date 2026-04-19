<?php
/**
 * Donor Dashboard — Auth forms (login, activate, check-email, set-password).
 *
 * @package Mission
 *
 * Expected variables: $auth_context, $color_style.
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-donor-dashboard' ] ) ); ?>
	data-wp-interactive="mission/donor-dashboard"
	<?php echo wp_kses_post( wp_interactivity_data_wp_context( $auth_context ) ); ?>
	data-wp-init="callbacks.initAuth"
	style="<?php echo esc_attr( $color_style ); ?>"
>
	<div class="mission-dd-auth-wrapper">
		<div class="mission-dd-auth-card">

			<!-- ── Login View ── -->
			<div data-wp-bind--hidden="!state.isLoginView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Donor Dashboard', 'missionwp-donation-platform' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Log in to view your donation history, download tax receipts, and manage recurring gifts.', 'missionwp-donation-platform' ); ?></p>
				</div>

				<div class="mission-dd-auth-form-card">
					<!-- Error banner -->
					<div
						class="mission-dd-auth-error"
						role="alert"
						aria-live="polite"
						data-wp-bind--hidden="!context.authError"
					>
						<span class="mission-dd-icon mission-dd-icon-alert" aria-hidden="true"></span>
						<span data-wp-text="context.authError"></span>
					</div>

					<form data-wp-on--submit="actions.submitLogin" novalidate>
						<div class="mission-dd-auth-field">
							<label class="mission-dd-auth-label" for="mission-dd-login-email"><?php esc_html_e( 'Email address', 'missionwp-donation-platform' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									type="email"
									id="mission-dd-login-email"
									class="mission-dd-auth-input"
									placeholder="you@example.com"
									autocomplete="email"
									data-wp-bind--value="context.authEmail"
									data-wp-on--input="actions.updateEmail"
								>
							</div>
						</div>

						<div class="mission-dd-auth-field">
							<label class="mission-dd-auth-label" for="mission-dd-login-password"><?php esc_html_e( 'Password', 'missionwp-donation-platform' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									id="mission-dd-login-password"
									class="mission-dd-auth-input mission-dd-auth-input-has-toggle"
									placeholder="<?php esc_attr_e( 'Enter your password', 'missionwp-donation-platform' ); ?>"
									autocomplete="current-password"
									data-wp-bind--value="context.authPassword"
									data-wp-bind--type="state.passwordInputType"
									data-wp-on--input="actions.updatePassword"
								>
								<button
									type="button"
									class="mission-dd-auth-pw-toggle"
									data-wp-on--click="actions.togglePasswordVisibility"
									data-wp-bind--aria-label="state.passwordToggleLabel"
								>
									<span class="mission-dd-icon mission-dd-icon-eye" data-wp-bind--hidden="context.passwordVisible" aria-hidden="true"></span>
									<span class="mission-dd-icon mission-dd-icon-eye-off" data-wp-bind--hidden="!context.passwordVisible" aria-hidden="true"></span>
								</button>
							</div>
						</div>

						<div class="mission-dd-auth-options">
							<label class="mission-dd-auth-remember">
								<input
									type="checkbox"
									data-wp-bind--checked="context.authRemember"
									data-wp-on--change="actions.toggleRemember"
								>
								<?php esc_html_e( 'Remember me', 'missionwp-donation-platform' ); ?>
							</label>
							<a href="#forgot-password" class="mission-dd-auth-forgot"><?php esc_html_e( 'Forgot password?', 'missionwp-donation-platform' ); ?></a>
						</div>

						<button
							type="submit"
							class="mission-dd-auth-submit"
							data-wp-class--is-loading="context.authLoading"
							data-wp-bind--disabled="context.authLoading"
						>
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Log in', 'missionwp-donation-platform' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Logging in...', 'missionwp-donation-platform' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'missionwp-donation-platform' ); ?>
						</div>
					</form>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: activate account link */
						esc_html__( 'First time here? %s', 'missionwp-donation-platform' ),
						'<a href="#activate">' . esc_html__( 'Activate your account', 'missionwp-donation-platform' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Activate View (email only) ── -->
			<div data-wp-bind--hidden="!state.isActivateView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Activate your account', 'missionwp-donation-platform' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Enter the email you used when you donated and we\'ll send you a verification link.', 'missionwp-donation-platform' ); ?></p>
				</div>

				<div class="mission-dd-auth-form-card">
					<!-- Error banner -->
					<div
						class="mission-dd-auth-error"
						role="alert"
						aria-live="polite"
						data-wp-bind--hidden="!context.authError"
					>
						<span class="mission-dd-icon mission-dd-icon-alert" aria-hidden="true"></span>
						<span data-wp-text="context.authError"></span>
					</div>

					<form data-wp-on--submit="actions.submitActivate" novalidate>
						<div class="mission-dd-auth-field">
							<label class="mission-dd-auth-label" for="mission-dd-activate-email"><?php esc_html_e( 'Email address', 'missionwp-donation-platform' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									type="email"
									id="mission-dd-activate-email"
									class="mission-dd-auth-input"
									placeholder="you@example.com"
									autocomplete="email"
									data-wp-bind--value="context.authEmail"
									data-wp-on--input="actions.updateEmail"
								>
							</div>
						</div>

						<button
							type="submit"
							class="mission-dd-auth-submit"
							data-wp-class--is-loading="context.authLoading"
							data-wp-bind--disabled="context.authLoading"
						>
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Send verification email', 'missionwp-donation-platform' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Sending...', 'missionwp-donation-platform' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'missionwp-donation-platform' ); ?>
						</div>
					</form>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Already have an account? %s', 'missionwp-donation-platform' ),
						'<a href="#login">' . esc_html__( 'Log in', 'missionwp-donation-platform' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Activation Email Sent View ── -->
			<div data-wp-bind--hidden="!state.isActivateSentView">
				<div class="mission-dd-auth-header">
					<span class="mission-dd-icon mission-dd-icon-mail mission-dd-auth-icon-mail" aria-hidden="true"></span>
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Check your email', 'missionwp-donation-platform' ); ?></h1>
					<p class="mission-dd-auth-subtitle">
						<?php esc_html_e( 'If a donor account exists for that email, we\'ve sent a verification link. Click the link in the email to finish setting up your account.', 'missionwp-donation-platform' ); ?>
					</p>
				</div>

				<div class="mission-dd-auth-form-card">
					<p class="mission-dd-auth-hint">
						<?php esc_html_e( 'Didn\'t receive an email? Check your spam folder or try again with a different email address.', 'missionwp-donation-platform' ); ?>
					</p>
					<button
						type="button"
						class="mission-dd-auth-submit mission-dd-auth-submit--secondary"
						data-wp-on--click="actions.showActivate"
					>
						<?php esc_html_e( 'Try another email', 'missionwp-donation-platform' ); ?>
					</button>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Already have an account? %s', 'missionwp-donation-platform' ),
						'<a href="#login">' . esc_html__( 'Log in', 'missionwp-donation-platform' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Set Password View (after email verification) ── -->
			<div data-wp-bind--hidden="!state.isSetPasswordView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Set your password', 'missionwp-donation-platform' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Your email has been verified. Choose a password to complete your account setup.', 'missionwp-donation-platform' ); ?></p>
				</div>

				<div class="mission-dd-auth-form-card">
					<!-- Error banner -->
					<div
						class="mission-dd-auth-error"
						role="alert"
						aria-live="polite"
						data-wp-bind--hidden="!context.authError"
					>
						<span class="mission-dd-icon mission-dd-icon-alert" aria-hidden="true"></span>
						<span data-wp-text="context.authError"></span>
					</div>

					<form data-wp-on--submit="actions.submitSetPassword" novalidate>
						<div class="mission-dd-auth-field">
							<label class="mission-dd-auth-label" for="mission-dd-set-password"><?php esc_html_e( 'Password', 'missionwp-donation-platform' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									id="mission-dd-set-password"
									class="mission-dd-auth-input mission-dd-auth-input-has-toggle"
									placeholder="<?php esc_attr_e( 'Create a password', 'missionwp-donation-platform' ); ?>"
									autocomplete="new-password"
									data-wp-bind--value="context.authPassword"
									data-wp-bind--type="state.passwordInputType"
									data-wp-on--input="actions.updatePassword"
								>
								<button
									type="button"
									class="mission-dd-auth-pw-toggle"
									data-wp-on--click="actions.togglePasswordVisibility"
									data-wp-bind--aria-label="state.passwordToggleLabel"
								>
									<span class="mission-dd-icon mission-dd-icon-eye" data-wp-bind--hidden="context.passwordVisible" aria-hidden="true"></span>
									<span class="mission-dd-icon mission-dd-icon-eye-off" data-wp-bind--hidden="!context.passwordVisible" aria-hidden="true"></span>
								</button>
							</div>
							<!-- Password strength bar -->
							<div class="mission-dd-auth-pw-strength" data-wp-bind--hidden="!context.authPassword">
								<div class="mission-dd-auth-pw-strength-bar">
									<div
										class="mission-dd-auth-pw-strength-fill"
										data-wp-class--weak="state.isStrengthWeak"
										data-wp-class--fair="state.isStrengthFair"
										data-wp-class--good="state.isStrengthGood"
										data-wp-class--strong="state.isStrengthStrong"
									></div>
								</div>
								<div class="mission-dd-auth-pw-strength-text" data-wp-text="context.strengthLabel"></div>
							</div>
						</div>

						<button
							type="submit"
							class="mission-dd-auth-submit"
							data-wp-class--is-loading="context.authLoading"
							data-wp-bind--disabled="context.authLoading"
						>
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Activate account', 'missionwp-donation-platform' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Activating...', 'missionwp-donation-platform' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'missionwp-donation-platform' ); ?>
						</div>
					</form>
				</div>
			</div>

			<!-- ── Forgot Password View ── -->
			<div data-wp-bind--hidden="!state.isForgotPasswordView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Forgot your password?', 'missionwp-donation-platform' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Enter your email address and we\'ll send you a link to reset your password.', 'missionwp-donation-platform' ); ?></p>
				</div>

				<div class="mission-dd-auth-form-card">
					<!-- Error banner -->
					<div
						class="mission-dd-auth-error"
						role="alert"
						aria-live="polite"
						data-wp-bind--hidden="!context.authError"
					>
						<span class="mission-dd-icon mission-dd-icon-alert" aria-hidden="true"></span>
						<span data-wp-text="context.authError"></span>
					</div>

					<form data-wp-on--submit="actions.submitForgotPassword" novalidate>
						<div class="mission-dd-auth-field">
							<label class="mission-dd-auth-label" for="mission-dd-forgot-email"><?php esc_html_e( 'Email address', 'missionwp-donation-platform' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									type="email"
									id="mission-dd-forgot-email"
									class="mission-dd-auth-input"
									placeholder="you@example.com"
									autocomplete="email"
									data-wp-bind--value="context.authEmail"
									data-wp-on--input="actions.updateEmail"
								>
							</div>
						</div>

						<button
							type="submit"
							class="mission-dd-auth-submit"
							data-wp-class--is-loading="context.authLoading"
							data-wp-bind--disabled="context.authLoading"
						>
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Send reset link', 'missionwp-donation-platform' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Sending...', 'missionwp-donation-platform' ); ?></span>
						</button>
					</form>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Remember your password? %s', 'missionwp-donation-platform' ),
						'<a href="#login">' . esc_html__( 'Log in', 'missionwp-donation-platform' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Forgot Password Email Sent View ── -->
			<div data-wp-bind--hidden="!state.isForgotPasswordSentView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Check your email', 'missionwp-donation-platform' ); ?></h1>
					<p class="mission-dd-auth-subtitle">
						<?php esc_html_e( 'If an account exists for that email, we\'ve sent a password reset link. Click the link in the email to choose a new password.', 'missionwp-donation-platform' ); ?>
					</p>
				</div>

				<div class="mission-dd-auth-form-card">
					<p class="mission-dd-auth-hint">
						<?php esc_html_e( 'Didn\'t receive an email? Check your spam folder or try again with a different email address.', 'missionwp-donation-platform' ); ?>
					</p>
					<button
						type="button"
						class="mission-dd-auth-submit mission-dd-auth-submit--secondary"
						data-wp-on--click="actions.showForgotPassword"
					>
						<?php esc_html_e( 'Try another email', 'missionwp-donation-platform' ); ?>
					</button>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Remember your password? %s', 'missionwp-donation-platform' ),
						'<a href="#login">' . esc_html__( 'Log in', 'missionwp-donation-platform' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Reset Password View (from email link) ── -->
			<div data-wp-bind--hidden="!state.isResetPasswordView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Choose a new password', 'missionwp-donation-platform' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Enter a new password for your donor account.', 'missionwp-donation-platform' ); ?></p>
				</div>

				<div class="mission-dd-auth-form-card">
					<!-- Error banner -->
					<div
						class="mission-dd-auth-error"
						role="alert"
						aria-live="polite"
						data-wp-bind--hidden="!context.authError"
					>
						<span class="mission-dd-icon mission-dd-icon-alert" aria-hidden="true"></span>
						<span data-wp-text="context.authError"></span>
					</div>

					<form data-wp-on--submit="actions.submitResetPassword" novalidate>
						<div class="mission-dd-auth-field">
							<label class="mission-dd-auth-label" for="mission-dd-reset-password"><?php esc_html_e( 'New password', 'missionwp-donation-platform' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									id="mission-dd-reset-password"
									class="mission-dd-auth-input mission-dd-auth-input-has-toggle"
									placeholder="<?php esc_attr_e( 'Enter a new password', 'missionwp-donation-platform' ); ?>"
									autocomplete="new-password"
									data-wp-bind--value="context.authPassword"
									data-wp-bind--type="state.passwordInputType"
									data-wp-on--input="actions.updatePassword"
								>
								<button
									type="button"
									class="mission-dd-auth-pw-toggle"
									data-wp-on--click="actions.togglePasswordVisibility"
									data-wp-bind--aria-label="state.passwordToggleLabel"
								>
									<span class="mission-dd-icon mission-dd-icon-eye" data-wp-bind--hidden="context.passwordVisible" aria-hidden="true"></span>
									<span class="mission-dd-icon mission-dd-icon-eye-off" data-wp-bind--hidden="!context.passwordVisible" aria-hidden="true"></span>
								</button>
							</div>
							<!-- Password strength bar -->
							<div class="mission-dd-auth-pw-strength" data-wp-bind--hidden="!context.authPassword">
								<div class="mission-dd-auth-pw-strength-bar">
									<div
										class="mission-dd-auth-pw-strength-fill"
										data-wp-class--weak="state.isStrengthWeak"
										data-wp-class--fair="state.isStrengthFair"
										data-wp-class--good="state.isStrengthGood"
										data-wp-class--strong="state.isStrengthStrong"
									></div>
								</div>
								<div class="mission-dd-auth-pw-strength-text" data-wp-text="context.strengthLabel"></div>
							</div>
						</div>

						<button
							type="submit"
							class="mission-dd-auth-submit"
							data-wp-class--is-loading="context.authLoading"
							data-wp-bind--disabled="context.authLoading"
						>
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Reset password', 'missionwp-donation-platform' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Resetting...', 'missionwp-donation-platform' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'missionwp-donation-platform' ); ?>
						</div>
					</form>
				</div>
			</div>

		</div>
	</div>
</div>
