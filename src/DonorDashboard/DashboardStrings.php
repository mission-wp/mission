<?php
/**
 * Translated strings for the donor dashboard's front-end scripts.
 *
 * @package MissionDP
 */

namespace MissionDP\DonorDashboard;

use MissionDP\Constants\Frequency;

defined( 'ABSPATH' ) || exit;

/**
 * Script modules can't import @wordpress/i18n, so the dashboard passes every
 * string its scripts display through the block context's `i18n` map.
 */
class DashboardStrings {

	/**
	 * Get the strings keyed by the name the scripts look them up with.
	 *
	 * @return array<string, string|array<string, string>>
	 */
	public static function all(): array {
		$strings = [
			'genericError'           => __( 'Something went wrong. Please try again.', 'mission-donation-platform' ),

			// Sign in, activation and password reset.
			'passwordWeak'           => __( 'Weak — try adding more characters', 'mission-donation-platform' ),
			'passwordFair'           => __( 'Fair — add numbers or symbols', 'mission-donation-platform' ),
			'passwordGood'           => __( 'Good password', 'mission-donation-platform' ),
			'passwordStrong'         => __( 'Strong password', 'mission-donation-platform' ),
			'hidePassword'           => __( 'Hide password', 'mission-donation-platform' ),
			'showPassword'           => __( 'Show password', 'mission-donation-platform' ),
			'enterEmailPassword'     => __( 'Please enter your email and password.', 'mission-donation-platform' ),
			'loginFailed'            => __( 'Login failed. Please try again.', 'mission-donation-platform' ),
			'enterEmail'             => __( 'Please enter your email address.', 'mission-donation-platform' ),
			'enterPassword'          => __( 'Please enter your password.', 'mission-donation-platform' ),
			'createPassword'         => __( 'Please create a password.', 'mission-donation-platform' ),
			'passwordTooShort'       => __( 'Password must be at least 8 characters.', 'mission-donation-platform' ),
			'activationFailed'       => __( 'Activation failed. Please try again.', 'mission-donation-platform' ),
			'enterNewPassword'       => __( 'Please enter a new password.', 'mission-donation-platform' ),
			'resetFailed'            => __( 'Password reset failed. Please try again.', 'mission-donation-platform' ),

			// Donation history.
			'overview'               => __( 'Overview', 'mission-donation-platform' ),
			'typeRecurring'          => __( 'Recurring', 'mission-donation-platform' ),
			'typeOneTime'            => __( 'One-time', 'mission-donation-platform' ),
			'statuses'               => DashboardLabels::transaction_status_labels(),

			// Profile.
			'emailUpdated'           => __( 'Email address updated successfully!', 'mission-donation-platform' ),
			'emailVerifyFailed'      => __( 'Failed to verify email. Please try again.', 'mission-donation-platform' ),
			'preferenceSaveFailed'   => __( 'Failed to save preference. Please try again.', 'mission-donation-platform' ),
			'preferenceSaved'        => __( 'Preference saved', 'mission-donation-platform' ),
			'saved'                  => __( 'Saved', 'mission-donation-platform' ),
			'saving'                 => __( 'Saving…', 'mission-donation-platform' ),
			'saveChanges'            => __( 'Save Changes', 'mission-donation-platform' ),
			'sending'                => __( 'Sending…', 'mission-donation-platform' ),
			'sendVerification'       => __( 'Send Verification', 'mission-donation-platform' ),
			'verificationSendFailed' => __( 'Failed to send verification email.', 'mission-donation-platform' ),
			'verificationSent'       => __( 'Verification email sent. Check your inbox.', 'mission-donation-platform' ),
			'emailChangeCancelled'   => __( 'Email change cancelled.', 'mission-donation-platform' ),
			'saveFailed'             => __( 'Failed to save. Please try again.', 'mission-donation-platform' ),
			'profileUpdated'         => __( 'Profile updated', 'mission-donation-platform' ),

			// Payment methods.
			'card'                   => __( 'Card', 'mission-donation-platform' ),
			/* translators: 1: card brand, e.g. "Visa", 2: last four digits of the card */
			'cardEndingIn'           => __( '%1$s ending in %2$s', 'mission-donation-platform' ),
			/* translators: %s: card expiry date, e.g. "03/2027" */
			'cardExpires'            => __( 'Expires %s', 'mission-donation-platform' ),
			'paymentUnavailable'     => __( 'Payment system unavailable. Please refresh and try again.', 'mission-donation-platform' ),
			'paymentInitFailed'      => __( 'Failed to initialize. Please try again.', 'mission-donation-platform' ),
			'paymentMethodUpdated'   => __( 'Payment method updated', 'mission-donation-platform' ),
			'updatePaymentMethod'    => __( 'Update Payment Method', 'mission-donation-platform' ),

			// Recurring donations.
			'update'                 => __( 'Update', 'mission-donation-platform' ),
			'updating'               => __( 'Updating…', 'mission-donation-platform' ),
			/* translators: %s: new recurring amount with its frequency, e.g. "$25.00 / month" */
			'updateTo'               => __( 'Update to %s', 'mission-donation-platform' ),
			'newAmount'              => __( 'New amount', 'mission-donation-platform' ),
			'newAmountByFrequency'   => [
				Frequency::WEEKLY    => __( 'New weekly amount', 'mission-donation-platform' ),
				Frequency::MONTHLY   => __( 'New monthly amount', 'mission-donation-platform' ),
				Frequency::QUARTERLY => __( 'New quarterly amount', 'mission-donation-platform' ),
				Frequency::ANNUALLY  => __( 'New annual amount', 'mission-donation-platform' ),
			],
			'confirmCancel'          => __( 'Are you sure you want to cancel this recurring donation? This cannot be undone.', 'mission-donation-platform' ),
			'cancelFailed'           => __( 'Failed to cancel. Please try again.', 'mission-donation-platform' ),
			'subscriptionCancelled'  => __( 'Subscription cancelled', 'mission-donation-platform' ),
			'pauseFailed'            => __( 'Failed to pause. Please try again.', 'mission-donation-platform' ),
			'subscriptionPaused'     => __( 'Subscription paused', 'mission-donation-platform' ),
			'resumeFailed'           => __( 'Failed to resume. Please try again.', 'mission-donation-platform' ),
			'subscriptionResumed'    => __( 'Subscription resumed', 'mission-donation-platform' ),
			'updateFailed'           => __( 'Failed to update. Please try again.', 'mission-donation-platform' ),
			'amountUpdated'          => __( 'Donation amount updated', 'mission-donation-platform' ),

			// Fundraising pages and teams.
			'couldNotSave'           => __( 'Could not save. Please try again.', 'mission-donation-platform' ),
			'photoUploadFailed'      => __( 'Could not upload the image. Please try again.', 'mission-donation-platform' ),
			'photoRemoveFailed'      => __( 'Could not remove the image. Please try again.', 'mission-donation-platform' ),
		];

		/**
		 * Filters the strings passed to the donor dashboard's front-end scripts.
		 *
		 * @param array<string, string|array<string, string>> $strings Strings keyed by lookup name.
		 */
		return apply_filters( 'mission_donor_dashboard_strings', $strings );
	}
}
