<?php
/**
 * Block Name: Donation Form
 * Description: A donation form block.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use MissionDP\Blocks\DonationFormSettings;
use MissionDP\Currency\Currency;

$settings = DonationFormSettings::resolve( $attributes );

$block_classes = [ 'mission-donation-form' ];
if ( ! empty( $attributes['align'] ) ) {
	$block_classes[] = 'align' . $attributes['align'];
}

// Recurring frequency options.
$recurring_frequencies = [];
$frequency_labels      = [
	'one_time' => __( 'One Time', 'mission-donation-platform' ),
];

if ( ! empty( $settings['recurringEnabled'] ) && ! empty( $settings['recurringFrequencies'] ) ) {
	$label_map = [
		'weekly'    => __( 'Weekly', 'mission-donation-platform' ),
		'monthly'   => __( 'Monthly', 'mission-donation-platform' ),
		'quarterly' => __( 'Quarterly', 'mission-donation-platform' ),
		'annually'  => __( 'Annually', 'mission-donation-platform' ),
	];
	// Sort recurring frequencies in a fixed canonical order.
	$canonical_order = array_keys( $label_map );
	$enabled         = array_flip( $settings['recurringFrequencies'] );
	foreach ( $canonical_order as $freq ) {
		if ( isset( $enabled[ $freq ] ) ) {
			$recurring_frequencies[]   = $freq;
			$frequency_labels[ $freq ] = $label_map[ $freq ];
		}
	}
}

$default_frequency = $settings['recurringDefault'] ?? 'one_time';
// If the default is a recurring frequency, the mode starts as "ongoing".
$is_ongoing = 'one_time' !== $default_frequency && ! empty( $recurring_frequencies );
$currency   = $settings['currency'] ?? 'USD';

// Determine initial frequency key for amounts lookup.
$amounts_by_frequency = $settings['amountsByFrequency'] ?? [];
$default_amounts      = $settings['defaultAmounts'] ?? [];
$initial_freq_key     = $is_ongoing ? $default_frequency : 'one_time';
$initial_amounts      = $amounts_by_frequency[ $initial_freq_key ] ?? $amounts_by_frequency['one_time'] ?? [];
$default_amount       = $default_amounts[ $initial_freq_key ] ?? ( $initial_amounts[0] ?? 0 );

$currency_symbol = Currency::get_symbol( $currency );

// Unique prefix for field IDs (multi-form support).
$uid = wp_unique_id( 'mission-df-' );

$tip_percentages     = $settings['tipPercentages'] ?? [ 5, 10, 15, 20 ];
$default_tip_percent = 15;

// Primary color: per-form override wins, then global setting, then fallback.
$mission_settings = get_option( 'missiondp_settings', [] );
$global_primary   = $mission_settings['primary_color'] ?? '#2fa36b';
$primary_color    = ! empty( $settings['primaryColor'] ) ? $settings['primaryColor'] : $global_primary;

/**
 * Darken a hex color by a percentage.
 *
 * @param string $hex     Hex color (e.g. '#2fa36b').
 * @param float  $percent Percentage to darken (0–100).
 * @return string Darkened hex color.
 */
$darken_color = static function ( string $hex, float $percent ): string {
	$hex = ltrim( $hex, '#' );
	$r   = max( 0, (int) round( hexdec( substr( $hex, 0, 2 ) ) * ( 1 - $percent / 100 ) ) );
	$g   = max( 0, (int) round( hexdec( substr( $hex, 2, 2 ) ) * ( 1 - $percent / 100 ) ) );
	$b   = max( 0, (int) round( hexdec( substr( $hex, 4, 2 ) ) * ( 1 - $percent / 100 ) ) );
	return sprintf( '#%02x%02x%02x', $r, $g, $b );
};

$primary_hover = $darken_color( $primary_color, 12 );
$hex_trimmed   = ltrim( $primary_color, '#' );
$primary_r     = hexdec( substr( $hex_trimmed, 0, 2 ) );
$primary_g     = hexdec( substr( $hex_trimmed, 2, 2 ) );
$primary_b     = hexdec( substr( $hex_trimmed, 4, 2 ) );
$primary_light = "rgba({$primary_r}, {$primary_g}, {$primary_b}, 0.08)";
$luminance            = ( 0.299 * $primary_r + 0.587 * $primary_g + 0.114 * $primary_b ) / 255;
$primary_text         = $luminance > 0.5 ? '#1e1e1e' : '#ffffff';
$primary_text_on_light = $luminance > 0.5 ? $darken_color( $primary_color, 45 ) : $primary_color;

// Initial context for Interactivity API.
$context = [
	'currentStep'          => 1,
	'isOngoing'            => $is_ongoing,
	'selectedFrequency'    => $is_ongoing ? $default_frequency : 'one_time',
	'recurringFrequencies' => $recurring_frequencies,
	'frequencyLabels'      => $frequency_labels,
	'frequencyDropdownOpen' => false,
	'amountsByFrequency'   => $amounts_by_frequency,
	'currentAmounts'       => $amounts_by_frequency[ $is_ongoing ? $default_frequency : 'one_time' ] ?? $amounts_by_frequency['one_time'] ?? [],
	'defaultAmounts'       => (object) $default_amounts,
	'amountDescriptions'   => (object) ( $settings['amountDescriptions'] ?? [] ),
	'selectedAmount'       => $default_amount,
	'isCustomAmount'       => false,
	'customAmountValue'    => '',
	'feeMode'              => $settings['feeMode'] ?? 'optional',
	'feeRecoveryChecked'   => true,
	'stripeFeePercent'     => (float) ( $mission_settings['stripe_fee_percent'] ?? 2.9 ),
	'stripeFeeFixed'       => (int) ( $mission_settings['stripe_fee_fixed'] ?? 30 ),
	'showFeeDetails'       => false,
	'selectedTipPercent'   => ! empty( $settings['tipEnabled'] ) ? $default_tip_percent : 0,
	'tipMenuOpen'          => false,
	'isCustomTip'          => false,
	'customTipAmount'      => 0,
	'tributeChecked'       => false,
	'tributeType'          => 'in_honor',
	'honoreeName'          => '',
	'honoreeEmail'         => '',
	'notifyEnabled'        => false,
	'notifyName'           => '',
	'notifyEmail'          => '',
	'notifyMethod'         => 'email',
	'notifyCountry'        => 'US',
	'notifyAddress'        => '',
	'notifyCity'           => '',
	'notifyState'          => '',
	'notifyZip'            => '',
	'tributeMessage'       => '',
	'isAnonymous'          => false,
	'firstName'            => '',
	'lastName'             => '',
	'email'                => '',
	'phone'                => '',
	'phoneError'           => false,
	'comment'              => '',
	'commentChecked'       => false,
	'settings'             => $settings,
	'stripePublishableKey' => ! empty( $mission_settings['test_mode'] ) ? MISSIONDP_STRIPE_PK_TEST : MISSIONDP_STRIPE_PK_LIVE,
	'chargesEnabled'       => ! empty( $mission_settings['stripe_charges_enabled'] ),
	'restUrl'              => trailingslashit( get_rest_url( null, 'mission-donation-platform/v1' ) ),
	'restNonce'            => wp_create_nonce( 'wp_rest' ),
	'formId'               => $attributes['formId'] ?? '',
	'campaignId'           => $settings['campaignId'] ?? 0,
	'sourcePostId'         => get_the_ID() ?: 0,
	'isSubmitting'         => false,
	'paymentError'         => '',
	'paymentSuccess'       => false,
	'honoreeNameError'     => false,
	'notifyNameError'      => false,
	'notifyEmailError'     => false,
	'firstNameError'       => false,
	'lastNameError'        => false,
	'emailError'           => false,
	'primaryColor'         => $primary_color,
	'stripeAppearance'     => apply_filters( 'missiondp_stripe_appearance', [] ),
	'locale'               => str_replace( '_', '-', get_locale() ),
	'stepDirection'            => 'forward',
	'leavingStep'              => 0,
	'confirmationType'         => $settings['confirmationType'] ?? 'message',
	'confirmationRedirectUrl'  => $settings['confirmationRedirectUrl'] ?? '',
	'customFields'             => $settings['customFields'] ?? [],
	'customFieldValues'        => (object) [],
	'customFieldErrors'        => (object) [],
	'hasCustomFields'          => ! empty( $settings['customFields'] ),
	'openMultiselectId'        => '',
];
?>

<section
	<?php echo get_block_wrapper_attributes( [ 'class' => implode( ' ', $block_classes ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core function, self-escaping. ?>
	data-wp-interactive="mission-donation-platform/donation-form"
	<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core function, self-escaping. ?>
	style="--mission-primary: <?php echo esc_attr( $primary_color ); ?>; --mission-primary-hover: <?php echo esc_attr( $primary_hover ); ?>; --mission-primary-light: <?php echo esc_attr( $primary_light ); ?>; --mission-primary-text: <?php echo esc_attr( $primary_text ); ?>; --mission-primary-text-on-light: <?php echo esc_attr( $primary_text_on_light ); ?>;"
>
	<?php if ( ! empty( $mission_settings['test_mode'] ) ) : ?>
		<div class="mission-df-test-mode-banner">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
				<path d="M12 2L1 21h22L12 2z" fill="currentColor" opacity="0.2"/>
				<path d="M12 2L1 21h22L12 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" fill="none"/>
				<line x1="12" y1="10" x2="12" y2="14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				<circle cx="12" cy="17" r="1" fill="currentColor"/>
			</svg>
			<?php esc_html_e( 'Test mode active: Donations in test mode are not processed', 'mission-donation-platform' ); ?>
		</div>
	<?php endif; ?>

	<?php // ───── Progress Dots ───── ?>
	<div class="mission-df-progress" data-wp-bind--hidden="state.paymentSuccess">
		<div class="mission-df-progress-dot" data-wp-class--active="callbacks.isProgressDot1Active" data-wp-class--completed="callbacks.isProgressDot1Complete"></div>
		<div class="mission-df-progress-line" data-wp-class--completed="callbacks.isProgressLine1Complete"></div>
		<div class="mission-df-progress-dot" data-wp-class--active="callbacks.isProgressDot2Active" data-wp-class--completed="callbacks.isProgressDot2Complete"></div>
		<?php if ( ! empty( $settings['customFields'] ) ) : ?>
		<div class="mission-df-progress-line" data-wp-class--completed="callbacks.isProgressLine2Complete"></div>
		<div class="mission-df-progress-dot" data-wp-class--active="callbacks.isProgressDot3Active"></div>
		<?php endif; ?>
	</div>

	<div class="mission-df-steps-viewport"
		data-wp-class--slide-back="callbacks.isSlideBack"
		data-wp-bind--hidden="state.paymentSuccess"
	>

	<?php // ───── Step 1: Choose Your Gift ───── ?>
	<div
		class="mission-df-step mission-df-step-1"
		data-wp-class--active="callbacks.isStep1Active"
		data-wp-class--leaving="callbacks.isStep1Leaving"
	>
		<h2 class="mission-df-step-title"><?php echo esc_html( ! empty( $settings['chooseGiftHeading'] ) ? $settings['chooseGiftHeading'] : __( 'Choose Your Gift', 'mission-donation-platform' ) ); ?></h2>

		<?php // Frequency toggle (only if recurring is enabled). ?>
		<?php if ( ! empty( $settings['recurringEnabled'] ) && ! empty( $recurring_frequencies ) ) : ?>
			<div class="mission-df-frequency-toggle" role="tablist">
				<div class="mission-df-frequency-slider" data-wp-class--right="state.isOngoing"></div>
				<button
					type="button"
					role="tab"
					class="mission-df-frequency-btn"
					data-wp-on--click="actions.selectOneTime"
					data-wp-class--active="!state.isOngoing"
				>
					<?php esc_html_e( 'One Time', 'mission-donation-platform' ); ?>
				</button>
				<button
					type="button"
					role="tab"
					class="mission-df-frequency-btn"
					data-wp-on--click="actions.selectOngoing"
					data-wp-class--active="state.isOngoing"
				>
					<?php
					if ( 1 === count( $recurring_frequencies ) ) {
						echo esc_html( $frequency_labels[ $recurring_frequencies[0] ] );
					} else {
						esc_html_e( 'Ongoing', 'mission-donation-platform' );
					}
					?>
				</button>
			</div>

			<?php // Recurring frequency selector — visible when Ongoing is active (hidden for single frequency). ?>
			<div class="mission-df-recurring-selector<?php echo 1 === count( $recurring_frequencies ) ? ' mission-df-recurring-selector--single' : ''; ?>" data-wp-bind--hidden="!state.isOngoing" data-wp-class--visible="state.isOngoing">
				<span class="mission-df-recurring-label"><?php esc_html_e( 'Give', 'mission-donation-platform' ); ?></span>
				<div class="mission-df-recurring-dropdown" data-wp-on-document--click="actions.closeFrequencyDropdown">
					<button
						type="button"
						class="mission-df-recurring-trigger"
						data-wp-on--click="actions.toggleFrequencyDropdown"
						data-wp-bind--aria-expanded="state.frequencyDropdownOpen"
						aria-label="<?php esc_attr_e( 'Select frequency', 'mission-donation-platform' ); ?>"
					>
						<span data-wp-text="callbacks.selectedFrequencyLabel"></span>
						<span class="mission-df-recurring-arrow" data-wp-class--open="state.frequencyDropdownOpen"></span>
					</button>
					<div class="mission-df-recurring-menu" data-wp-bind--hidden="!state.frequencyDropdownOpen" data-wp-class--visible="state.frequencyDropdownOpen">
						<?php foreach ( $recurring_frequencies as $freq ) : ?>
							<button
								type="button"
								class="mission-df-recurring-option"
								data-wp-context='<?php echo wp_json_encode( [ 'frequency' => $freq ] ); ?>'
								data-wp-on--click="actions.selectRecurringFrequency"
								data-wp-class--active="callbacks.isSelectedFrequency"
							>
								<?php echo esc_html( $frequency_labels[ $freq ] ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php // Amount grid. ?>
		<div class="mission-df-amount-grid" data-wp-class--has-descriptions="state.currentFrequencyHasDescriptions">
			<template data-wp-each--amount="state.currentAmounts">
				<button
					type="button"
					class="mission-df-amount-btn"
					data-wp-on--click="actions.selectAmount"
					data-wp-class--active="callbacks.isSelectedAmount"
				>
					<span data-wp-text="callbacks.formattedPresetAmount"></span>
					<span class="mission-df-amount-desc" data-wp-text="callbacks.amountDescription"></span>
				</button>
			</template>

			<?php if ( ! empty( $settings['customAmount'] ) ) : ?>
				<div class="mission-df-amount-other-cell">
					<button
						type="button"
						class="mission-df-amount-btn mission-df-amount-btn--other"
						data-wp-on--click="actions.toggleCustomAmount"
						data-wp-bind--hidden="state.isCustomAmount"
					>
						<?php esc_html_e( 'Other', 'mission-donation-platform' ); ?>
					</button>
					<div class="mission-df-amount-btn--other-input" hidden data-wp-bind--hidden="!state.isCustomAmount">
						<span class="mission-df-other-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
						<input type="number" class="mission-df-other-field"
							id="<?php echo esc_attr( $uid . 'custom-amount' ); ?>"
							placeholder="0.00"
							min="0"
							step="0.01"
							data-wp-on--input="actions.updateCustomAmount"
							data-wp-on--blur="actions.blurCustomAmount"
							data-wp-watch="callbacks.focusCustomInput"
							aria-label="<?php esc_attr_e( 'Custom amount', 'mission-donation-platform' ); ?>"
						/>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php // Minimum amount warning. ?>
		<?php if ( ! empty( $settings['customAmount'] ) ) : ?>
			<p class="mission-df-minimum-warning" data-wp-bind--hidden="!callbacks.showMinimumWarning">
				<?php
				printf(
					/* translators: %s: formatted minimum amount */
					esc_html__( 'Minimum donation is %s', 'mission-donation-platform' ),
					'<span data-wp-text="callbacks.formattedMinimumAmount"></span>'
				);
				?>
			</p>
		<?php endif; ?>

		<?php // Tribute dedication. ?>
		<?php if ( ! empty( $settings['tributeEnabled'] ) ) : ?>
		<div class="mission-df-tribute">
			<label class="mission-df-checkbox-label">
				<input
					type="checkbox"
					data-wp-on--change="actions.toggleTribute"
					data-wp-bind--checked="state.tributeChecked"
				/>
				<?php esc_html_e( 'Dedicate this gift in honor or memory of someone', 'mission-donation-platform' ); ?>
			</label>

			<div class="mission-df-tribute-fields" data-wp-bind--hidden="!state.tributeChecked" data-wp-class--visible="state.tributeChecked">
				<div class="mission-df-tribute-combo" data-wp-class--mission-df-field-error="state.honoreeNameError">
					<select
						class="mission-df-tribute-select"
						data-wp-on--change="actions.selectTributeType"
						data-wp-bind--value="state.tributeType"
					>
						<option value="in_honor"><?php esc_html_e( 'In honor of', 'mission-donation-platform' ); ?></option>
						<option value="in_memory"><?php esc_html_e( 'In memory of', 'mission-donation-platform' ); ?></option>
					</select>
					<input
						type="text"
						class="mission-df-tribute-honoree"
						id="<?php echo esc_attr( $uid . 'honoree-name' ); ?>"
						placeholder="<?php esc_attr_e( 'Honoree name', 'mission-donation-platform' ); ?>"
						data-wp-on--input="actions.updateHonoreeName"
						data-wp-bind--value="state.honoreeName"
						data-wp-bind--aria-invalid="state.honoreeNameError"
						aria-describedby="<?php echo esc_attr( $uid . 'honoree-name-error' ); ?>"
					/>
				</div>
				<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'honoree-name-error' ); ?>" role="alert" data-wp-bind--hidden="!state.honoreeNameError"><?php esc_html_e( 'Please provide the honoree\'s name.', 'mission-donation-platform' ); ?></p>

				<div class="mission-df-tribute-notify" data-wp-bind--hidden="!state.notifyEnabled" data-wp-class--visible="state.notifyEnabled">
					<div class="mission-df-notify-method-toggle">
						<div class="mission-df-notify-method-slider" data-wp-class--right="callbacks.isNotifyMail"></div>
						<button
							type="button"
							class="mission-df-notify-method-btn"
							data-wp-context='<?php echo wp_json_encode( [ 'method' => 'email' ] ); ?>'
							data-wp-on--click="actions.selectNotifyMethod"
							data-wp-class--active="callbacks.isNotifyEmail"
						>
							<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1.5" y="3" width="13" height="10" rx="1.5"/><path d="M1.5 4.5L8 9l6.5-4.5"/></svg>
							<?php esc_html_e( 'Email', 'mission-donation-platform' ); ?>
						</button>
						<button
							type="button"
							class="mission-df-notify-method-btn"
							data-wp-context='<?php echo wp_json_encode( [ 'method' => 'mail' ] ); ?>'
							data-wp-on--click="actions.selectNotifyMethod"
							data-wp-class--active="callbacks.isNotifyMail"
						>
							<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="12" height="9" rx="1.5"/><path d="M2 7h12M6 10h4"/></svg>
							<?php esc_html_e( 'Mail', 'mission-donation-platform' ); ?>
						</button>
					</div>

					<?php // Personal message (optional, shared across email and mail). ?>
					<div class="mission-df-field mission-df-tribute-message">
						<label for="<?php echo esc_attr( $uid . 'tribute-message' ); ?>"><?php esc_html_e( 'Personal Message', 'mission-donation-platform' ); ?> <span class="mission-df-optional"><?php esc_html_e( '(optional)', 'mission-donation-platform' ); ?></span></label>
						<textarea
							id="<?php echo esc_attr( $uid . 'tribute-message' ); ?>"
							rows="2"
							data-wp-on--input="actions.updateTributeMessage"
							data-wp-bind--value="context.tributeMessage"
							placeholder="<?php esc_attr_e( 'Add a personal message to include in the notification', 'mission-donation-platform' ); ?>"
						></textarea>
					</div>

					<?php // Email panel. ?>
					<div class="mission-df-notify-panel" data-wp-bind--hidden="!callbacks.isNotifyEmail" data-wp-class--active="callbacks.isNotifyEmail">
						<div class="mission-df-notify-row">
							<div class="mission-df-field">
								<label for="<?php echo esc_attr( $uid . 'notify-name' ); ?>"><?php esc_html_e( 'Recipient Name', 'mission-donation-platform' ); ?></label>
								<input
									type="text"
									id="<?php echo esc_attr( $uid . 'notify-name' ); ?>"
									data-wp-on--input="actions.updateNotifyName"
									data-wp-bind--value="state.notifyName"
									data-wp-class--mission-df-field-error="state.notifyNameError"
									data-wp-bind--aria-invalid="state.notifyNameError"
									aria-describedby="<?php echo esc_attr( $uid . 'notify-name-error' ); ?>"
								/>
								<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'notify-name-error' ); ?>" role="alert" data-wp-bind--hidden="!state.notifyNameError"><?php esc_html_e( 'Please provide the recipient\'s name.', 'mission-donation-platform' ); ?></p>
							</div>
							<div class="mission-df-field">
								<label for="<?php echo esc_attr( $uid . 'notify-email' ); ?>"><?php esc_html_e( 'Recipient Email', 'mission-donation-platform' ); ?></label>
								<input
									type="email"
									id="<?php echo esc_attr( $uid . 'notify-email' ); ?>"
									data-wp-on--input="actions.updateNotifyEmail"
									data-wp-bind--value="state.notifyEmail"
									data-wp-class--mission-df-field-error="state.notifyEmailError"
									data-wp-bind--aria-invalid="state.notifyEmailError"
									aria-describedby="<?php echo esc_attr( $uid . 'notify-email-error' ); ?>"
								/>
								<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'notify-email-error' ); ?>" role="alert" data-wp-bind--hidden="!state.notifyEmailError"><?php esc_html_e( 'Please provide a valid email address.', 'mission-donation-platform' ); ?></p>
							</div>
						</div>
					</div>

					<?php // Mail panel. ?>
					<div class="mission-df-notify-panel" data-wp-bind--hidden="!callbacks.isNotifyMail" data-wp-class--active="callbacks.isNotifyMail">
						<div class="mission-df-field">
							<label for="<?php echo esc_attr( $uid . 'notify-name-mail' ); ?>"><?php esc_html_e( 'Recipient Name', 'mission-donation-platform' ); ?></label>
							<input
								type="text"
								id="<?php echo esc_attr( $uid . 'notify-name-mail' ); ?>"
								data-wp-on--input="actions.updateNotifyName"
								data-wp-bind--value="state.notifyName"
								data-wp-class--mission-df-field-error="state.notifyNameError"
								data-wp-bind--aria-invalid="state.notifyNameError"
								aria-describedby="<?php echo esc_attr( $uid . 'notify-name-mail-error' ); ?>"
							/>
							<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'notify-name-mail-error' ); ?>" role="alert" data-wp-bind--hidden="!state.notifyNameError"><?php esc_html_e( 'Please provide the recipient\'s name.', 'mission-donation-platform' ); ?></p>
						</div>
						<div class="mission-df-field">
							<label><?php esc_html_e( 'Country', 'mission-donation-platform' ); ?></label>
							<select class="mission-df-select" data-wp-on--change="actions.updateNotifyCountry">
								<option value="US" selected><?php esc_html_e( 'United States', 'mission-donation-platform' ); ?></option>
								<option value="CA"><?php esc_html_e( 'Canada', 'mission-donation-platform' ); ?></option>
								<option value="GB"><?php esc_html_e( 'United Kingdom', 'mission-donation-platform' ); ?></option>
								<option value="AU"><?php esc_html_e( 'Australia', 'mission-donation-platform' ); ?></option>
								<option value="DE"><?php esc_html_e( 'Germany', 'mission-donation-platform' ); ?></option>
								<option value="FR"><?php esc_html_e( 'France', 'mission-donation-platform' ); ?></option>
								<option value="NZ"><?php esc_html_e( 'New Zealand', 'mission-donation-platform' ); ?></option>
								<option value="IE"><?php esc_html_e( 'Ireland', 'mission-donation-platform' ); ?></option>
								<option value="NL"><?php esc_html_e( 'Netherlands', 'mission-donation-platform' ); ?></option>
							</select>
						</div>
						<div class="mission-df-field">
							<label><?php esc_html_e( 'Street Address', 'mission-donation-platform' ); ?></label>
							<input type="text" data-wp-on--input="actions.updateNotifyAddress" data-wp-bind--value="context.notifyAddress" />
						</div>
						<div class="mission-df-notify-address-row">
							<div class="mission-df-field">
								<label><?php esc_html_e( 'City', 'mission-donation-platform' ); ?></label>
								<input type="text" data-wp-on--input="actions.updateNotifyCity" data-wp-bind--value="context.notifyCity" />
							</div>
							<div class="mission-df-field">
								<label><?php esc_html_e( 'State / Province', 'mission-donation-platform' ); ?></label>
								<input type="text" data-wp-on--input="actions.updateNotifyState" data-wp-bind--value="context.notifyState" />
							</div>
							<div class="mission-df-field">
								<label><?php esc_html_e( 'Postal Code', 'mission-donation-platform' ); ?></label>
								<input type="text" data-wp-on--input="actions.updateNotifyZip" data-wp-bind--value="context.notifyZip" />
							</div>
						</div>
					</div>
				</div>

				<button
					type="button"
					class="mission-df-tribute-notify-link"
					data-wp-on--click="actions.toggleNotify"
					data-wp-text="callbacks.notifyLinkLabel"
				></button>
			</div>
		</div>
		<?php endif; ?>

		<?php // Donor comment (checkbox toggle + collapsible textarea). ?>
		<?php if ( ! empty( $settings['commentsEnabled'] ) ) : ?>
		<div class="mission-df-comment">
			<label class="mission-df-checkbox-label">
				<input
					type="checkbox"
					data-wp-on--change="actions.toggleComment"
					data-wp-bind--checked="state.commentChecked"
				/>
				<?php esc_html_e( 'Add a comment to my donation', 'mission-donation-platform' ); ?>
			</label>
			<div class="mission-df-comment-field" data-wp-class--visible="state.commentChecked">
				<textarea
					id="<?php echo esc_attr( $uid . 'comment' ); ?>"
					rows="3"
					data-wp-on--input="actions.updateComment"
					data-wp-bind--value="state.comment"
					data-wp-watch="callbacks.focusComment"
					placeholder="<?php esc_attr_e( 'Leave a message (optional)', 'mission-donation-platform' ); ?>"
				></textarea>
			</div>
		</div>
		<?php endif; ?>

		<button
			type="button"
			class="mission-df-btn mission-df-btn--primary"
			data-wp-on--click="actions.nextStep"
		>
			<span><?php echo esc_html( ! empty( $settings['continueButtonText'] ) ? $settings['continueButtonText'] : __( 'Continue', 'mission-donation-platform' ) ); ?></span>
			<svg class="mission-df-btn-arrow" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
		</button>
	</div>

	<?php // ───── Custom Fields Step (only when custom fields configured) ───── ?>
	<?php if ( ! empty( $settings['customFields'] ) ) : ?>
	<div
		class="mission-df-step mission-df-step-custom-fields"
		data-wp-class--active="callbacks.isCustomFieldsStepActive"
		data-wp-class--leaving="callbacks.isCustomFieldsStepLeaving"
	>
		<div class="mission-df-step-header">
			<button type="button" class="mission-df-back-link" data-wp-on--click="actions.prevStep">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3L5 8l5 5"/></svg>
				<?php esc_html_e( 'Back', 'mission-donation-platform' ); ?>
			</button>
			<h2 class="mission-df-step-title"><?php echo esc_html( ! empty( $settings['additionalInfoHeading'] ) ? $settings['additionalInfoHeading'] : __( 'Additional Information', 'mission-donation-platform' ) ); ?></h2>
		</div>

		<?php foreach ( $settings['customFields'] as $cf ) :
			$cf_id   = sanitize_key( $cf['id'] ?? '' );
			$cf_type = $cf['type'] ?? 'text';
			$cf_label = $cf['label'] ?? '';
			$cf_required = ! empty( $cf['required'] );
			$cf_placeholder = $cf['placeholder'] ?? '';
			$cf_options = $cf['options'] ?? [];
		?>
		<div
			class="mission-df-custom-field"
			data-wp-context='<?php echo wp_json_encode( [ 'fieldId' => $cf_id ] ); ?>'
		>
			<?php if ( 'checkbox' !== $cf_type ) : ?>
			<label class="mission-df-custom-field__label">
				<?php echo esc_html( $cf_label ); ?>
				<?php if ( $cf_required ) : ?>
					<span class="mission-df-required">*</span>
				<?php endif; ?>
			</label>
			<?php endif; ?>

			<?php if ( 'text' === $cf_type ) : ?>
				<input
					type="text"
					class="mission-df-custom-field__input"
					placeholder="<?php echo esc_attr( $cf_placeholder ); ?>"
					data-wp-on--input="actions.updateCustomField"
					data-wp-class--mission-df-field-error="callbacks.hasCustomFieldError"
					data-wp-bind--aria-invalid="callbacks.hasCustomFieldError"
					aria-describedby="<?php echo esc_attr( $uid . 'cf-' . $cf_id . '-error' ); ?>"
				/>

			<?php elseif ( 'textarea' === $cf_type ) : ?>
				<textarea
					class="mission-df-custom-field__input"
					rows="3"
					placeholder="<?php echo esc_attr( $cf_placeholder ); ?>"
					data-wp-on--input="actions.updateCustomField"
					data-wp-class--mission-df-field-error="callbacks.hasCustomFieldError"
					data-wp-bind--aria-invalid="callbacks.hasCustomFieldError"
					aria-describedby="<?php echo esc_attr( $uid . 'cf-' . $cf_id . '-error' ); ?>"
				></textarea>

			<?php elseif ( 'checkbox' === $cf_type ) : ?>
				<label class="mission-df-checkbox-label">
					<input
						type="checkbox"
						data-wp-on--change="actions.updateCustomField"
					/>
					<?php echo esc_html( $cf_label ); ?>
					<?php if ( $cf_required ) : ?>
						<span class="mission-df-required">*</span>
					<?php endif; ?>
				</label>

			<?php elseif ( 'select' === $cf_type ) : ?>
				<select
					class="mission-df-select"
					data-wp-on--change="actions.updateCustomField"
					data-wp-class--mission-df-field-error="callbacks.hasCustomFieldError"
					data-wp-bind--aria-invalid="callbacks.hasCustomFieldError"
					aria-describedby="<?php echo esc_attr( $uid . 'cf-' . $cf_id . '-error' ); ?>"
				>
					<option value=""><?php esc_html_e( 'Select an option', 'mission-donation-platform' ); ?></option>
					<?php foreach ( $cf_options as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
					<?php endforeach; ?>
				</select>

			<?php elseif ( 'multiselect' === $cf_type ) : ?>
				<div
					class="mission-df-multiselect"
					data-wp-class--mission-df-field-error="callbacks.hasCustomFieldError"
					data-wp-bind--aria-invalid="callbacks.hasCustomFieldError"
					aria-describedby="<?php echo esc_attr( $uid . 'cf-' . $cf_id . '-error' ); ?>"
					data-wp-on-document--click="actions.closeMultiselectDropdown"
				>
					<button
						type="button"
						class="mission-df-multiselect__trigger"
						data-wp-on--click="actions.toggleMultiselectDropdown"
						data-wp-text="callbacks.multiselectLabel"
					></button>
					<div class="mission-df-multiselect__dropdown" data-wp-bind--hidden="!callbacks.isMultiselectOpen" data-wp-class--visible="callbacks.isMultiselectOpen">
						<?php foreach ( $cf_options as $opt ) : ?>
							<button
								type="button"
								class="mission-df-multiselect__option"
								data-wp-context='<?php echo wp_json_encode( [ 'fieldId' => $cf_id, 'optionValue' => $opt ] ); ?>'
								data-wp-on--click="actions.toggleMultiselectOption"
								data-wp-class--is-selected="callbacks.isMultiselectOptionSelected"
							>
								<span class="mission-df-multiselect__check" data-wp-class--checked="callbacks.isMultiselectOptionSelected">
									<svg width="10" height="8" viewBox="0 0 10 8" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
										<path d="M1 4l2.5 3L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</span>
								<?php echo esc_html( $opt ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

			<?php elseif ( 'radio' === $cf_type ) : ?>
				<div class="mission-df-radio-group">
					<?php foreach ( $cf_options as $opt ) : ?>
						<label class="mission-df-radio-label">
							<input
								type="radio"
								name="<?php echo esc_attr( $uid . $cf_id ); ?>"
								value="<?php echo esc_attr( $opt ); ?>"
								data-wp-on--change="actions.updateCustomField"
							/>
							<?php echo esc_html( $opt ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( 'checkbox' !== $cf_type ) : ?>
				<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'cf-' . $cf_id . '-error' ); ?>" role="alert" data-wp-bind--hidden="!callbacks.hasCustomFieldError">
					<?php esc_html_e( 'This field is required.', 'mission-donation-platform' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>

		<button
			type="button"
			class="mission-df-btn mission-df-btn--primary"
			data-wp-on--click="actions.nextStep"
		>
			<span><?php echo esc_html( ! empty( $settings['continueButtonText'] ) ? $settings['continueButtonText'] : __( 'Continue', 'mission-donation-platform' ) ); ?></span>
			<svg class="mission-df-btn-arrow" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
		</button>
	</div>
	<?php endif; ?>

	<?php // ───── Payment Step ───── ?>
	<div
		class="mission-df-step mission-df-step-2"
		data-wp-class--active="callbacks.isPaymentStepActive"
		data-wp-class--leaving="callbacks.isPaymentStepLeaving"
	>
		<div class="mission-df-step-header">
			<button type="button" class="mission-df-back-link" data-wp-on--click="actions.prevStep">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3L5 8l5 5"/></svg>
				<?php esc_html_e( 'Back', 'mission-donation-platform' ); ?>
			</button>
			<h2 class="mission-df-step-title"><?php echo esc_html( ! empty( $settings['summaryHeading'] ) ? $settings['summaryHeading'] : __( 'Complete Your Gift', 'mission-donation-platform' ) ); ?></h2>
		</div>

		<?php // Donation amount headline. ?>
		<div class="mission-df-amount-headline">
			<span class="mission-df-amount-display" data-wp-text="callbacks.formattedAmountWithFrequency"></span><span class="mission-df-amount-freq" data-wp-text="callbacks.frequencySuffix"></span>
		</div>

		<?php // Fee recovery. ?>
		<?php if ( ! empty( $settings['feeRecovery'] ) ) : ?>
			<div class="mission-df-fee-recovery">
				<p class="mission-df-fee-line">
					<?php if ( 'required' === ( $settings['feeMode'] ?? 'optional' ) ) : ?>
						<span class="mission-df-fee-amount-text">+ <span data-wp-text="callbacks.formattedFeeAmount"></span>
						<?php esc_html_e( 'processing fee', 'mission-donation-platform' ); ?></span>
					<?php else : ?>
						<span class="mission-df-fee-amount-text" data-wp-class--uncovered="!state.feeRecoveryChecked">+ <span data-wp-text="callbacks.formattedFeeAmount"></span>
						<?php esc_html_e( 'processing fee', 'mission-donation-platform' ); ?></span>
						<button type="button" class="mission-df-fee-edit" data-wp-on--click="actions.toggleFeeDetails">
							<?php esc_html_e( 'Edit', 'mission-donation-platform' ); ?>
						</button>
					<?php endif; ?>
				</p>
				<?php if ( 'required' !== ( $settings['feeMode'] ?? 'optional' ) ) : ?>
					<div class="mission-df-fee-details" data-wp-bind--hidden="!state.showFeeDetails" data-wp-class--visible="state.showFeeDetails">
						<div>
							<p><?php esc_html_e( 'Payment processors take a cut of each transaction. You have the option to cover these fees so 100% of your gift can go to the cause you care about.', 'mission-donation-platform' ); ?></p>
							<label class="mission-df-checkbox-label">
								<input
									type="checkbox"
									data-wp-on--change="actions.toggleFeeRecovery"
									data-wp-bind--checked="state.feeRecoveryChecked"
								/>
								<?php esc_html_e( 'I want to cover the fee', 'mission-donation-platform' ); ?>
							</label>
						</div>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php // Donor fields. ?>
		<div class="mission-df-donor-fields">
			<?php if ( empty( $settings['collectAddress'] ) ) : ?>
			<div class="mission-df-name-row">
				<div class="mission-df-field">
					<label for="<?php echo esc_attr( $uid . 'first-name' ); ?>"><?php esc_html_e( 'First Name', 'mission-donation-platform' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $uid . 'first-name' ); ?>"
						required
						data-wp-on--input="actions.updateFirstName"
						data-wp-bind--value="state.firstName"
						data-wp-class--mission-df-field-error="state.firstNameError"
						data-wp-bind--aria-invalid="state.firstNameError"
						aria-describedby="<?php echo esc_attr( $uid . 'first-name-error' ); ?>"
					/>
					<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'first-name-error' ); ?>" role="alert" data-wp-bind--hidden="!state.firstNameError"><?php esc_html_e( 'This field is incomplete.', 'mission-donation-platform' ); ?></p>
				</div>
				<div class="mission-df-field">
					<label for="<?php echo esc_attr( $uid . 'last-name' ); ?>"><?php esc_html_e( 'Last Name', 'mission-donation-platform' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $uid . 'last-name' ); ?>"
						required
						data-wp-on--input="actions.updateLastName"
						data-wp-bind--value="state.lastName"
						data-wp-class--mission-df-field-error="state.lastNameError"
						data-wp-bind--aria-invalid="state.lastNameError"
						aria-describedby="<?php echo esc_attr( $uid . 'last-name-error' ); ?>"
					/>
					<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'last-name-error' ); ?>" role="alert" data-wp-bind--hidden="!state.lastNameError"><?php esc_html_e( 'This field is incomplete.', 'mission-donation-platform' ); ?></p>
				</div>
			</div>
			<?php endif; ?>
			<div class="mission-df-field">
				<label for="<?php echo esc_attr( $uid . 'email' ); ?>"><?php esc_html_e( 'Email Address', 'mission-donation-platform' ); ?></label>
				<input
					type="email"
					id="<?php echo esc_attr( $uid . 'email' ); ?>"
					required
					data-wp-on--input="actions.updateEmail"
					data-wp-bind--value="state.email"
					data-wp-class--mission-df-field-error="state.emailError"
					data-wp-bind--aria-invalid="state.emailError"
					aria-describedby="<?php echo esc_attr( $uid . 'email-error' ); ?>"
				/>
				<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'email-error' ); ?>" role="alert" data-wp-bind--hidden="!state.emailError"><?php esc_html_e( 'Please provide a valid email address.', 'mission-donation-platform' ); ?></p>
			</div>
			<?php if ( ! empty( $settings['phoneRequired'] ) ) : ?>
			<div class="mission-df-field">
				<label for="<?php echo esc_attr( $uid . 'phone' ); ?>"><?php esc_html_e( 'Phone Number', 'mission-donation-platform' ); ?></label>
				<input
					type="tel"
					id="<?php echo esc_attr( $uid . 'phone' ); ?>"
					required
					data-wp-on--input="actions.updatePhone"
					data-wp-bind--value="state.phone"
					data-wp-class--mission-df-field-error="state.phoneError"
					data-wp-bind--aria-invalid="state.phoneError"
					aria-describedby="<?php echo esc_attr( $uid . 'phone-error' ); ?>"
				/>
				<p class="mission-df-field-error-msg" id="<?php echo esc_attr( $uid . 'phone-error' ); ?>" role="alert" data-wp-bind--hidden="!state.phoneError"><?php esc_html_e( 'Please provide a phone number.', 'mission-donation-platform' ); ?></p>
			</div>
			<?php endif; ?>
		</div>

		<?php // Anonymous checkbox. ?>
		<?php if ( ! empty( $settings['anonymousEnabled'] ) ) : ?>
			<div class="mission-df-anonymous">
				<label class="mission-df-checkbox-label">
					<input
						type="checkbox"
						data-wp-on--change="actions.toggleAnonymous"
						data-wp-bind--checked="state.isAnonymous"
					/>
					<?php esc_html_e( 'Make my donation anonymous', 'mission-donation-platform' ); ?>
				</label>
			</div>
		<?php endif; ?>

		<?php // Stripe Address Element (full billing address). ?>
		<?php if ( ! empty( $settings['collectAddress'] ) ) : ?>
			<div class="mission-df-address-element"
				id="<?php echo esc_attr( $uid . 'address-element' ); ?>">
			</div>
		<?php endif; ?>

		<?php // Stripe Payment Element. ?>
		<?php if ( ! empty( $mission_settings['stripe_charges_enabled'] ) ) : ?>
		<div
			class="mission-df-payment-element"
			id="<?php echo esc_attr( $uid . 'payment-element' ); ?>"
			data-wp-init="callbacks.mountPaymentElement"
			data-wp-watch="callbacks.watchAmounts"
		></div>
		<?php else : ?>
		<div class="mission-df-charges-disabled">
			<p><?php esc_html_e( 'Donations are not available right now. Please check back soon.', 'mission-donation-platform' ); ?></p>
		</div>
		<?php endif; ?>
		<div
			class="mission-df-card-error"
			role="alert"
			aria-atomic="true"
			data-wp-bind--hidden="!state.paymentError"
		>
			<svg class="mission-df-card-error-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
				<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>
				<line x1="12" y1="8" x2="12" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				<circle cx="12" cy="16.5" r="1" fill="currentColor"/>
			</svg>
			<span data-wp-text="state.paymentError"></span>
			<button type="button" class="mission-df-card-error-dismiss" data-wp-on--click="actions.dismissError" aria-label="<?php esc_attr_e( 'Dismiss error', 'mission-donation-platform' ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					<line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>
			</button>
		</div>

		<?php // Tip section. ?>
		<?php if ( ! empty( $settings['tipEnabled'] ) ) : ?>
			<div class="mission-df-tip" data-wp-on-document--click="actions.closeTipMenu">
				<div class="mission-df-tip-card">
					<div class="mission-df-tip-header">
						<p class="mission-df-tip-text">
							<?php esc_html_e( 'An optional tip keeps this free donation platform running', 'mission-donation-platform' ); ?>
						</p>
						<div class="mission-df-tip-trigger-wrap">
							<button
								type="button"
								class="mission-df-tip-trigger"
								data-wp-on--click="actions.toggleTipMenu"
								data-wp-bind--aria-expanded="state.tipMenuOpen"
								aria-label="<?php esc_attr_e( 'Select tip amount', 'mission-donation-platform' ); ?>"
							>
								<span class="mission-df-tip-trigger-chevron">
									<svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5L5 1 1 5"/></svg>
								</span>
								<span class="mission-df-tip-trigger-value" data-wp-text="callbacks.tipTriggerLabel"></span>
								<span class="mission-df-tip-trigger-chevron">
									<svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 1l4 4 4-4"/></svg>
								</span>
							</button>
							<div class="mission-df-tip-menu" data-wp-bind--hidden="!state.tipMenuOpen" data-wp-class--visible="state.tipMenuOpen">
								<button type="button" class="mission-df-tip-option" data-wp-context='{"tipPercent":20}' data-wp-on--click="actions.selectTipPercent" data-wp-class--active="callbacks.isTipOptionActive">20%</button>
								<button type="button" class="mission-df-tip-option" data-wp-context='{"tipPercent":15}' data-wp-on--click="actions.selectTipPercent" data-wp-class--active="callbacks.isTipOptionActive">15%</button>
								<button type="button" class="mission-df-tip-option" data-wp-context='{"tipPercent":10}' data-wp-on--click="actions.selectTipPercent" data-wp-class--active="callbacks.isTipOptionActive">10%</button>
								<button type="button" class="mission-df-tip-option mission-df-tip-option--other" data-wp-on--click="actions.selectCustomTip" data-wp-class--active="state.isCustomTip">
									<?php esc_html_e( 'Other', 'mission-donation-platform' ); ?>
								</button>
							</div>
						</div>
					</div>
					<div class="mission-df-tip-custom" data-wp-bind--hidden="!state.isCustomTip" data-wp-class--visible="state.isCustomTip">
						<div class="mission-df-tip-custom-inner">
							<div class="mission-df-tip-custom-label">
								<span class="mission-df-tip-heart">
									<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 9.1371C2 14 6.01943 16.5914 8.96173 18.9109C10 19.7294 11 20.5 12 20.5C13 20.5 14 19.7294 15.0383 18.9109C17.9806 16.5914 22 14 22 9.1371C22 4.27416 16.4998 0.825464 12 5.50063C7.50016 0.825464 2 4.27416 2 9.1371Z"/></svg>
								</span>
								<?php esc_html_e( 'Help keep this platform free', 'mission-donation-platform' ); ?>
							</div>
							<div class="mission-df-tip-custom-row">
								<button type="button" class="mission-df-tip-custom-btn" data-wp-on--click="actions.tipCustomDown" aria-label="<?php esc_attr_e( 'Decrease tip', 'mission-donation-platform' ); ?>">&minus;</button>
								<div class="mission-df-tip-custom-input-wrap">
									<span class="mission-df-tip-custom-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
									<input
										type="number"
										class="mission-df-tip-custom-input"
										min="0"
										step="1"
										data-wp-on--input="actions.updateCustomTipAmount"
										data-wp-bind--value="callbacks.customTipDisplayValue"
										aria-label="<?php esc_attr_e( 'Custom tip amount', 'mission-donation-platform' ); ?>"
									/>
								</div>
								<button type="button" class="mission-df-tip-custom-btn" data-wp-on--click="actions.tipCustomUp" aria-label="<?php esc_attr_e( 'Increase tip', 'mission-donation-platform' ); ?>">&plus;</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php // Donate button. ?>
		<?php if ( ! empty( $mission_settings['stripe_charges_enabled'] ) ) : ?>
		<button
			type="button"
			class="mission-df-btn mission-df-btn--primary mission-df-donate-btn"
			data-wp-on--click="actions.submit"
			data-wp-bind--disabled="state.isSubmitting"
			data-wp-bind--aria-busy="state.isSubmitting"
			data-wp-class--is-submitting="state.isSubmitting"
		>
			<span data-wp-bind--hidden="state.isSubmitting">
				<?php echo esc_html( ! empty( $settings['donateButtonText'] ) ? $settings['donateButtonText'] : __( 'Donate', 'mission-donation-platform' ) ); ?> <span data-wp-text="callbacks.formattedTotalAmount"></span>
			</span>
			<span data-wp-bind--hidden="!state.isSubmitting"><?php esc_html_e( 'Processing', 'mission-donation-platform' ); ?></span>
			<span class="mission-df-spinner" data-wp-bind--hidden="!state.isSubmitting"></span>
			<span class="mission-df-btn-arrow" data-wp-bind--hidden="state.isSubmitting"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4"/></svg></span>
		</button>
		<?php endif; ?>
	</div>

	</div><?php // Close .mission-df-steps-viewport ?>

	<?php // ───── Success State ───── ?>
	<div
		class="mission-df-success"
		data-wp-bind--hidden="!state.paymentSuccess"
		data-wp-class--visible="state.paymentSuccess"
	>
		<svg class="mission-df-checkmark-svg" viewBox="0 0 56 56">
			<circle class="mission-df-checkmark-circle" cx="28" cy="28" r="25"/>
			<polyline class="mission-df-checkmark-check" points="18 28 25 35 38 22"/>
		</svg>
		<div class="mission-df-success-content">
			<?php
			if ( ! empty( $content ) ) {
				echo \MissionDP\Helpers\Kses::block_output( $content );
			} else {
				?>
				<h2><?php esc_html_e( 'Thank you!', 'mission-donation-platform' ); ?></h2>
				<p data-wp-bind--hidden="state.isOngoing">
					<?php esc_html_e( 'Your donation has been processed successfully. You will receive a confirmation email shortly.', 'mission-donation-platform' ); ?>
				</p>
				<p data-wp-bind--hidden="!state.isOngoing">
					<?php esc_html_e( 'Your recurring donation has been set up successfully. You will receive a confirmation email shortly.', 'mission-donation-platform' ); ?>
				</p>
				<?php
			}
			?>
		</div>
	</div>

	<?php // ───── Footer (inside card) ───── ?>
	<?php if ( ! empty( $mission_settings['show_powered_by'] ) ) : ?>
	<div class="mission-df-footer">
		<a href="https://missionwp.com" target="_blank" rel="noopener noreferrer" class="mission-df-secure-badge">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
				<path d="M12 2L4 5.5V11c0 5.25 3.4 10.15 8 11.5 4.6-1.35 8-6.25 8-11.5V5.5L12 2z" fill="#2fa36b"/>
				<path d="M10 14.2l-2.6-2.6L6 13l4 4 8-8-1.4-1.4L10 14.2z" fill="#fff"/>
			</svg>
			<?php
			printf(
				/* translators: %s: Mission brand name */
				esc_html__( 'Secure donation powered by %s', 'mission-donation-platform' ),
				'<span>Mission</span>'
			);
			?>
		</a>
	</div>
	<?php endif; ?>

</section>
