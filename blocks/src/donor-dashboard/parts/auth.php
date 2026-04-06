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
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Donor Dashboard', 'mission' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Log in to view your donation history, download tax receipts, and manage recurring gifts.', 'mission' ); ?></p>
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
							<label class="mission-dd-auth-label" for="mission-dd-login-email"><?php esc_html_e( 'Email address', 'mission' ); ?></label>
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
							<label class="mission-dd-auth-label" for="mission-dd-login-password"><?php esc_html_e( 'Password', 'mission' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									id="mission-dd-login-password"
									class="mission-dd-auth-input mission-dd-auth-input-has-toggle"
									placeholder="<?php esc_attr_e( 'Enter your password', 'mission' ); ?>"
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
								<?php esc_html_e( 'Remember me', 'mission' ); ?>
							</label>
							<a href="#forgot-password" class="mission-dd-auth-forgot"><?php esc_html_e( 'Forgot password?', 'mission' ); ?></a>
						</div>

						<button
							type="submit"
							class="mission-dd-auth-submit"
							data-wp-class--is-loading="context.authLoading"
							data-wp-bind--disabled="context.authLoading"
						>
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Log in', 'mission' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Logging in...', 'mission' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'mission' ); ?>
						</div>
					</form>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: activate account link */
						esc_html__( 'First time here? %s', 'mission' ),
						'<a href="#activate">' . esc_html__( 'Activate your account', 'mission' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Activate View (email only) ── -->
			<div data-wp-bind--hidden="!state.isActivateView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Activate your account', 'mission' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Enter the email you used when you donated and we\'ll send you a verification link.', 'mission' ); ?></p>
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
							<label class="mission-dd-auth-label" for="mission-dd-activate-email"><?php esc_html_e( 'Email address', 'mission' ); ?></label>
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
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Send verification email', 'mission' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Sending...', 'mission' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'mission' ); ?>
						</div>
					</form>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Already have an account? %s', 'mission' ),
						'<a href="#login">' . esc_html__( 'Log in', 'mission' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Activation Email Sent View ── -->
			<div data-wp-bind--hidden="!state.isActivateSentView">
				<div class="mission-dd-auth-header">
					<span class="mission-dd-icon mission-dd-icon-mail mission-dd-auth-icon-mail" aria-hidden="true"></span>
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Check your email', 'mission' ); ?></h1>
					<p class="mission-dd-auth-subtitle">
						<?php esc_html_e( 'If a donor account exists for that email, we\'ve sent a verification link. Click the link in the email to finish setting up your account.', 'mission' ); ?>
					</p>
				</div>

				<div class="mission-dd-auth-form-card">
					<p class="mission-dd-auth-hint">
						<?php esc_html_e( 'Didn\'t receive an email? Check your spam folder or try again with a different email address.', 'mission' ); ?>
					</p>
					<button
						type="button"
						class="mission-dd-auth-submit mission-dd-auth-submit--secondary"
						data-wp-on--click="actions.showActivate"
					>
						<?php esc_html_e( 'Try another email', 'mission' ); ?>
					</button>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Already have an account? %s', 'mission' ),
						'<a href="#login">' . esc_html__( 'Log in', 'mission' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Set Password View (after email verification) ── -->
			<div data-wp-bind--hidden="!state.isSetPasswordView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Set your password', 'mission' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Your email has been verified. Choose a password to complete your account setup.', 'mission' ); ?></p>
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
							<label class="mission-dd-auth-label" for="mission-dd-set-password"><?php esc_html_e( 'Password', 'mission' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									id="mission-dd-set-password"
									class="mission-dd-auth-input mission-dd-auth-input-has-toggle"
									placeholder="<?php esc_attr_e( 'Create a password', 'mission' ); ?>"
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
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Activate account', 'mission' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Activating...', 'mission' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'mission' ); ?>
						</div>
					</form>
				</div>
			</div>

			<!-- ── Forgot Password View ── -->
			<div data-wp-bind--hidden="!state.isForgotPasswordView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Forgot your password?', 'mission' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Enter your email address and we\'ll send you a link to reset your password.', 'mission' ); ?></p>
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
							<label class="mission-dd-auth-label" for="mission-dd-forgot-email"><?php esc_html_e( 'Email address', 'mission' ); ?></label>
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
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Send reset link', 'mission' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Sending...', 'mission' ); ?></span>
						</button>
					</form>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Remember your password? %s', 'mission' ),
						'<a href="#login">' . esc_html__( 'Log in', 'mission' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Forgot Password Email Sent View ── -->
			<div data-wp-bind--hidden="!state.isForgotPasswordSentView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Check your email', 'mission' ); ?></h1>
					<p class="mission-dd-auth-subtitle">
						<?php esc_html_e( 'If an account exists for that email, we\'ve sent a password reset link. Click the link in the email to choose a new password.', 'mission' ); ?>
					</p>
				</div>

				<div class="mission-dd-auth-form-card">
					<p class="mission-dd-auth-hint">
						<?php esc_html_e( 'Didn\'t receive an email? Check your spam folder or try again with a different email address.', 'mission' ); ?>
					</p>
					<button
						type="button"
						class="mission-dd-auth-submit mission-dd-auth-submit--secondary"
						data-wp-on--click="actions.showForgotPassword"
					>
						<?php esc_html_e( 'Try another email', 'mission' ); ?>
					</button>
				</div>

				<div class="mission-dd-auth-switch">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Remember your password? %s', 'mission' ),
						'<a href="#login">' . esc_html__( 'Log in', 'mission' ) . '</a>'
					);
					?>
				</div>
			</div>

			<!-- ── Reset Password View (from email link) ── -->
			<div data-wp-bind--hidden="!state.isResetPasswordView">
				<div class="mission-dd-auth-header">
					<h1 class="mission-dd-auth-title"><?php esc_html_e( 'Choose a new password', 'mission' ); ?></h1>
					<p class="mission-dd-auth-subtitle"><?php esc_html_e( 'Enter a new password for your donor account.', 'mission' ); ?></p>
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
							<label class="mission-dd-auth-label" for="mission-dd-reset-password"><?php esc_html_e( 'New password', 'mission' ); ?></label>
							<div class="mission-dd-auth-input-wrap">
								<input
									id="mission-dd-reset-password"
									class="mission-dd-auth-input mission-dd-auth-input-has-toggle"
									placeholder="<?php esc_attr_e( 'Enter a new password', 'mission' ); ?>"
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
							<span data-wp-bind--hidden="context.authLoading"><?php esc_html_e( 'Reset password', 'mission' ); ?></span>
							<span data-wp-bind--hidden="!context.authLoading"><?php esc_html_e( 'Resetting...', 'mission' ); ?></span>
						</button>

						<div class="mission-dd-auth-secure">
							<span class="mission-dd-icon mission-dd-icon-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Secured with encryption', 'mission' ); ?>
						</div>
					</form>
				</div>
			</div>

		</div>
	</div>
</div>
