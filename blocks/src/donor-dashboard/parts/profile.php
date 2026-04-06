<?php
/**
 * Donor Dashboard — Profile panel.
 *
 * @package Mission
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isProfile">
	<!-- Personal Information -->
	<div class="mission-dd-profile-section">
		<h2 class="mission-dd-profile-section-title"><?php esc_html_e( 'Personal Information', 'mission' ); ?></h2>
		<p class="mission-dd-profile-section-desc"><?php esc_html_e( 'Update your contact details and address.', 'mission' ); ?></p>

		<div class="mission-dd-profile-error" data-wp-bind--hidden="!context.profile.error" role="alert" aria-live="polite">
			<span data-wp-text="context.profile.error"></span>
		</div>

		<div class="mission-dd-profile-grid">

			<!-- First Name -->
			<div class="mission-dd-profile-group">
				<label class="mission-dd-profile-label" for="mission-dd-first-name"><?php esc_html_e( 'First Name', 'mission' ); ?></label>
				<input
					type="text"
					id="mission-dd-first-name"
					class="mission-dd-profile-input"
					data-wp-bind--value="context.profile.firstName"
					data-wp-on--input="actions.updateProfileFirstName"
					data-wp-bind--disabled="context.profile.saving"
					data-wp-class--mission-dd-input-error="state.firstNameEmpty"
					data-wp-bind--aria-invalid="state.firstNameEmpty"
				>
				<span class="mission-dd-field-error" data-wp-bind--hidden="!state.firstNameEmpty"><?php esc_html_e( 'First name is required.', 'mission' ); ?></span>
			</div>

			<!-- Last Name -->
			<div class="mission-dd-profile-group">
				<label class="mission-dd-profile-label" for="mission-dd-last-name"><?php esc_html_e( 'Last Name', 'mission' ); ?></label>
				<input
					type="text"
					id="mission-dd-last-name"
					class="mission-dd-profile-input"
					data-wp-bind--value="context.profile.lastName"
					data-wp-on--input="actions.updateProfileLastName"
					data-wp-bind--disabled="context.profile.saving"
					data-wp-class--mission-dd-input-error="state.lastNameEmpty"
					data-wp-bind--aria-invalid="state.lastNameEmpty"
				>
				<span class="mission-dd-field-error" data-wp-bind--hidden="!state.lastNameEmpty"><?php esc_html_e( 'Last name is required.', 'mission' ); ?></span>
			</div>

			<!-- Email -->
			<div class="mission-dd-profile-group">
				<label class="mission-dd-profile-label" for="mission-dd-email"><?php esc_html_e( 'Email', 'mission' ); ?></label>

				<!-- Display mode -->
				<div data-wp-bind--hidden="context.profile.emailChangeEditing">
					<input
						type="email"
						id="mission-dd-email"
						class="mission-dd-profile-input"
						data-wp-bind--value="context.profile.email"
						disabled
					>
					<!-- Pending notice -->
					<div class="mission-dd-email-pending" data-wp-bind--hidden="!context.profile.pendingEmail">
						<span><?php esc_html_e( 'Verification sent to', 'mission' ); ?> </span>
						<strong data-wp-text="context.profile.pendingEmail"></strong>
						<button
							type="button"
							class="mission-dd-email-pending-cancel"
							data-wp-on--click="actions.cancelEmailChange"
						><?php esc_html_e( 'Cancel', 'mission' ); ?></button>
					</div>
					<!-- Change link (hidden while pending) -->
					<button
						type="button"
						class="mission-dd-email-change-link"
						data-wp-bind--hidden="context.profile.pendingEmail"
						data-wp-on--click="actions.startEmailChange"
					><?php esc_html_e( 'Change email', 'mission' ); ?></button>
				</div>

				<!-- Edit mode -->
				<div data-wp-bind--hidden="!context.profile.emailChangeEditing">
					<input
						type="email"
						id="mission-dd-email-new"
						class="mission-dd-profile-input"
						data-wp-bind--value="context.profile.newEmail"
						data-wp-on--input="actions.updateNewEmail"
						data-wp-bind--disabled="context.profile.emailChangeSending"
						placeholder="<?php esc_attr_e( 'Enter new email address', 'mission' ); ?>"
					>
					<div class="mission-dd-profile-error" data-wp-bind--hidden="!context.profile.emailChangeError" role="alert" aria-live="polite">
						<span data-wp-text="context.profile.emailChangeError"></span>
					</div>
					<div class="mission-dd-email-change-actions">
						<button
							type="button"
							class="mission-dd-btn-primary mission-dd-btn-sm"
							data-wp-on--click="actions.sendEmailVerification"
							data-wp-bind--disabled="state.emailSendDisabled"
						>
							<span data-wp-text="state.emailSendLabel"></span>
						</button>
						<button
							type="button"
							class="mission-dd-btn-secondary mission-dd-btn-sm"
							data-wp-on--click="actions.cancelEmailEdit"
							data-wp-bind--disabled="context.profile.emailChangeSending"
						><?php esc_html_e( 'Cancel', 'mission' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Phone -->
			<div class="mission-dd-profile-group">
				<label class="mission-dd-profile-label" for="mission-dd-phone"><?php esc_html_e( 'Phone', 'mission' ); ?></label>
				<input
					type="tel"
					id="mission-dd-phone"
					class="mission-dd-profile-input"
					data-wp-bind--value="context.profile.phone"
					data-wp-on--input="actions.updateProfilePhone"
					data-wp-bind--disabled="context.profile.saving"
				>
			</div>

			<!-- Address (full width) -->
			<div class="mission-dd-profile-group mission-dd-profile-group-full">
				<label class="mission-dd-profile-label" for="mission-dd-address"><?php esc_html_e( 'Address', 'mission' ); ?></label>
				<input
					type="text"
					id="mission-dd-address"
					class="mission-dd-profile-input"
					data-wp-bind--value="context.profile.address1"
					data-wp-on--input="actions.updateProfileAddress1"
					data-wp-bind--disabled="context.profile.saving"
				>
			</div>

			<!-- City / State / ZIP -->
			<div class="mission-dd-profile-group-address">
				<div class="mission-dd-profile-group">
					<label class="mission-dd-profile-label" for="mission-dd-city"><?php esc_html_e( 'City', 'mission' ); ?></label>
					<input
						type="text"
						id="mission-dd-city"
						class="mission-dd-profile-input"
						data-wp-bind--value="context.profile.city"
						data-wp-on--input="actions.updateProfileCity"
						data-wp-bind--disabled="context.profile.saving"
					>
				</div>
				<div class="mission-dd-profile-group">
					<label class="mission-dd-profile-label" for="mission-dd-state"><?php esc_html_e( 'State', 'mission' ); ?></label>
					<input
						type="text"
						id="mission-dd-state"
						class="mission-dd-profile-input"
						data-wp-bind--value="context.profile.state"
						data-wp-on--input="actions.updateProfileState"
						data-wp-bind--disabled="context.profile.saving"
					>
				</div>
				<div class="mission-dd-profile-group">
					<label class="mission-dd-profile-label" for="mission-dd-zip"><?php esc_html_e( 'ZIP Code', 'mission' ); ?></label>
					<input
						type="text"
						id="mission-dd-zip"
						class="mission-dd-profile-input"
						data-wp-bind--value="context.profile.zip"
						data-wp-on--input="actions.updateProfileZip"
						data-wp-bind--disabled="context.profile.saving"
					>
				</div>
			</div>

		</div>

		<div class="mission-dd-profile-actions">
			<button
				class="mission-dd-btn-primary"
				data-wp-on--click="actions.saveProfile"
				data-wp-bind--disabled="state.profileSaveDisabled"
				data-wp-class--mission-dd-btn-saved="context.profile.saved"
			>
				<span data-wp-text="state.profileSaveLabel"></span>
			</button>
		</div>
	</div>

	<hr class="mission-dd-divider">

	<!-- Communication Preferences -->
	<div class="mission-dd-profile-section">
		<h2 class="mission-dd-profile-section-title"><?php esc_html_e( 'Communication Preferences', 'mission' ); ?></h2>
		<p class="mission-dd-profile-section-desc"><?php esc_html_e( 'Choose what emails you\'d like to receive.', 'mission' ); ?></p>

		<div class="mission-dd-profile-error" data-wp-bind--hidden="!context.profile.prefError" role="alert" aria-live="polite">
			<span data-wp-text="context.profile.prefError"></span>
		</div>

		<!-- Donation Receipts -->
		<div class="mission-dd-toggle-row">
			<div>
				<div class="mission-dd-toggle-label"><?php esc_html_e( 'Donation Receipts', 'mission' ); ?></div>
				<div class="mission-dd-toggle-desc"><?php esc_html_e( 'Receive a receipt after each donation', 'mission' ); ?></div>
			</div>
			<button
				type="button"
				role="switch"
				class="mission-dd-toggle<?php echo $preferences['email_receipts'] ? ' is-on' : ''; ?>"
				aria-checked="<?php echo $preferences['email_receipts'] ? 'true' : 'false'; ?>"
				data-wp-class--is-on="context.profile.preferences.emailReceipts"
				data-wp-bind--aria-checked="context.profile.preferences.emailReceipts"
				data-wp-on--click="actions.toggleEmailReceipts"
			>
				<span class="mission-dd-toggle-slider"></span>
			</button>
		</div>

		<!-- Campaign Updates -->
		<div class="mission-dd-toggle-row">
			<div>
				<div class="mission-dd-toggle-label"><?php esc_html_e( 'Campaign Updates', 'mission' ); ?></div>
				<div class="mission-dd-toggle-desc"><?php esc_html_e( 'Get notified about campaigns you\'ve donated to', 'mission' ); ?></div>
			</div>
			<button
				type="button"
				role="switch"
				class="mission-dd-toggle<?php echo $preferences['email_campaign_updates'] ? ' is-on' : ''; ?>"
				aria-checked="<?php echo $preferences['email_campaign_updates'] ? 'true' : 'false'; ?>"
				data-wp-class--is-on="context.profile.preferences.emailCampaignUpdates"
				data-wp-bind--aria-checked="context.profile.preferences.emailCampaignUpdates"
				data-wp-on--click="actions.toggleEmailCampaignUpdates"
			>
				<span class="mission-dd-toggle-slider"></span>
			</button>
		</div>

		<!-- Annual Receipt Reminder -->
		<div class="mission-dd-toggle-row">
			<div>
				<div class="mission-dd-toggle-label"><?php esc_html_e( 'Annual Receipt Reminder', 'mission' ); ?></div>
				<div class="mission-dd-toggle-desc"><?php esc_html_e( 'Reminder to download your annual tax receipt in January', 'mission' ); ?></div>
			</div>
			<button
				type="button"
				role="switch"
				class="mission-dd-toggle<?php echo $preferences['email_annual_reminder'] ? ' is-on' : ''; ?>"
				aria-checked="<?php echo $preferences['email_annual_reminder'] ? 'true' : 'false'; ?>"
				data-wp-class--is-on="context.profile.preferences.emailAnnualReminder"
				data-wp-bind--aria-checked="context.profile.preferences.emailAnnualReminder"
				data-wp-on--click="actions.toggleEmailAnnualReminder"
			>
				<span class="mission-dd-toggle-slider"></span>
			</button>
		</div>
	</div>

	<hr class="mission-dd-divider">

	<!-- Payment Method -->
	<div class="mission-dd-profile-section">
		<h2 class="mission-dd-profile-section-title"><?php esc_html_e( 'Payment Method', 'mission' ); ?></h2>
		<p class="mission-dd-profile-section-desc"><?php esc_html_e( 'Manage your saved payment method.', 'mission' ); ?></p>

		<div data-wp-bind--hidden="!context.profile.hasPaymentMethod">
			<div class="mission-dd-payment-card">
				<div class="mission-dd-payment-card-info">
					<div class="mission-dd-payment-card-icon" data-wp-text="state.paymentBrandLabel"></div>
					<div>
						<div class="mission-dd-payment-card-number" data-wp-text="state.paymentCardDisplay"></div>
						<div class="mission-dd-payment-card-exp" data-wp-text="state.paymentExpiry"></div>
					</div>
				</div>
				<a class="mission-dd-btn-secondary" href="#recurring" data-wp-on--click="actions.navigate" data-panel="recurring">
					<?php esc_html_e( 'Manage', 'mission' ); ?>
				</a>
			</div>
			<div class="mission-dd-payment-note"><?php esc_html_e( 'Payment information is securely managed through Stripe. We never store your full card details.', 'mission' ); ?></div>
		</div>

		<div class="mission-dd-payment-empty" data-wp-bind--hidden="context.profile.hasPaymentMethod">
			<p><?php esc_html_e( 'No payment method on file.', 'mission' ); ?></p>
		</div>
	</div>

	<!-- Danger Zone -->
	<div class="mission-dd-danger-zone">
		<div class="mission-dd-profile-error" data-wp-bind--hidden="!context.profile.deleteError" role="alert" aria-live="polite">
			<span data-wp-text="context.profile.deleteError"></span>
		</div>
		<button
			class="mission-dd-danger-link"
			data-wp-on--click="actions.deleteAccount"
			data-wp-bind--disabled="context.profile.deleteLoading"
		>
			<?php esc_html_e( 'Delete my account', 'mission' ); ?>
		</button>
		<div class="mission-dd-danger-desc"><?php esc_html_e( 'This will remove your login access. Your donation history will be preserved for the organization\'s records.', 'mission' ); ?></div>
	</div>

</div>
