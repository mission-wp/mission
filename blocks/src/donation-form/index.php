<?php
/**
 * Block Name: Donation Form
 * Description: A donation form block.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use Mission\Blocks\DonationFormSettings;
use Mission\Currency\Currency;

$settings = DonationFormSettings::resolve( $attributes );

$block_classes = array( 'mission-donation-form' );
if ( ! empty( $attributes['align'] ) ) {
	$block_classes[] = 'align' . $attributes['align'];
}

// Recurring frequency options.
$recurring_frequencies = array();
$frequency_labels      = array(
	'one_time' => __( 'One Time', 'mission' ),
);

if ( ! empty( $settings['recurringEnabled'] ) && ! empty( $settings['recurringFrequencies'] ) ) {
	$label_map = array(
		'monthly'   => __( 'Monthly', 'mission' ),
		'quarterly' => __( 'Quarterly', 'mission' ),
		'annually'  => __( 'Annually', 'mission' ),
	);
	foreach ( $settings['recurringFrequencies'] as $freq ) {
		$recurring_frequencies[]    = $freq;
		$frequency_labels[ $freq ] = $label_map[ $freq ] ?? ucfirst( $freq );
	}
}

$default_frequency = $settings['recurringDefault'] ?? 'one_time';
// If the default is a recurring frequency, the mode starts as "ongoing".
$is_ongoing = 'one_time' !== $default_frequency && ! empty( $recurring_frequencies );
$default_amount = $settings['amounts'][0] ?? 0;
$currency       = $settings['currency'] ?? 'USD';

$currency_symbol = Currency::get_symbol( $currency );

// Unique prefix for field IDs (multi-form support).
$uid = wp_unique_id( 'mission-df-' );

$tip_percentages     = $settings['tipPercentages'] ?? array( 5, 10, 15, 20 );
$default_tip_percent = 15;

// Initial context for Interactivity API.
$context = array(
	'currentStep'          => 1,
	'isOngoing'            => $is_ongoing,
	'selectedFrequency'    => $is_ongoing ? ( $recurring_frequencies[0] ?? 'monthly' ) : 'one_time',
	'recurringFrequencies' => $recurring_frequencies,
	'frequencyLabels'      => $frequency_labels,
	'frequencyDropdownOpen' => false,
	'selectedAmount'       => $default_amount,
	'isCustomAmount'       => false,
	'customAmountValue'    => '',
	'feeRecoveryChecked'   => true,
	'showFeeDetails'       => false,
	'selectedTipPercent'   => ! empty( $settings['tipEnabled'] ) ? $default_tip_percent : 0,
	'tributeChecked'       => false,
	'honoreeName'          => '',
	'honoreeEmail'         => '',
	'isAnonymous'          => false,
	'firstName'            => '',
	'lastName'             => '',
	'email'                => '',
	'settings'             => $settings,
);
?>

<section
	<?php echo get_block_wrapper_attributes( array( 'class' => implode( ' ', $block_classes ) ) ); ?>
	data-wp-interactive="mission/donation-form"
	<?php echo wp_interactivity_data_wp_context( $context ); ?>
>
	<?php // ───── Step 1: Choose Your Gift ───── ?>
	<div
		class="mission-df-step mission-df-step-1"
		data-wp-bind--hidden="!callbacks.isStep1"
		data-wp-class--visible="callbacks.isStep1"
	>
		<h2 class="mission-df-step-title"><?php esc_html_e( 'Choose Your Gift', 'mission' ); ?></h2>

		<?php // Frequency toggle (only if recurring is enabled). ?>
		<?php if ( ! empty( $settings['recurringEnabled'] ) && ! empty( $recurring_frequencies ) ) : ?>
			<div class="mission-df-frequency-toggle" role="tablist">
				<button
					type="button"
					role="tab"
					class="mission-df-frequency-btn"
					data-wp-on--click="actions.selectOneTime"
					data-wp-class--active="!state.isOngoing"
				>
					<?php esc_html_e( 'One Time', 'mission' ); ?>
				</button>
				<button
					type="button"
					role="tab"
					class="mission-df-frequency-btn"
					data-wp-on--click="actions.selectOngoing"
					data-wp-class--active="state.isOngoing"
				>
					<?php esc_html_e( 'Ongoing', 'mission' ); ?>
				</button>
			</div>

			<?php // Recurring frequency selector — visible when Ongoing is active. ?>
			<div class="mission-df-recurring-selector" data-wp-bind--hidden="!state.isOngoing" data-wp-class--visible="state.isOngoing">
				<span class="mission-df-recurring-label"><?php esc_html_e( 'Give', 'mission' ); ?></span>
				<div class="mission-df-recurring-dropdown">
					<button type="button" class="mission-df-recurring-trigger" data-wp-on--click="actions.toggleFrequencyDropdown">
						<span data-wp-text="callbacks.selectedFrequencyLabel"></span>
						<svg class="mission-df-recurring-arrow" width="12" height="12" viewBox="0 0 12 12" data-wp-class--open="state.frequencyDropdownOpen">
							<path d="M3 5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</button>
					<div class="mission-df-recurring-menu" data-wp-bind--hidden="!state.frequencyDropdownOpen" data-wp-class--visible="state.frequencyDropdownOpen">
						<?php foreach ( $recurring_frequencies as $freq ) : ?>
							<button
								type="button"
								class="mission-df-recurring-option"
								data-wp-context='<?php echo wp_json_encode( array( 'frequency' => $freq ) ); ?>'
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
		<div class="mission-df-amount-grid">
			<?php foreach ( $settings['amounts'] as $amount ) : ?>
				<button
					type="button"
					class="mission-df-amount-btn"
					data-wp-context='<?php echo wp_json_encode( array( 'amount' => $amount ) ); ?>'
					data-wp-on--click="actions.selectAmount"
					data-wp-class--active="callbacks.isSelectedAmount"
				>
					<span data-wp-text="callbacks.formattedPresetAmount"></span>
				</button>
			<?php endforeach; ?>

			<?php if ( ! empty( $settings['customAmount'] ) ) : ?>
				<button
					type="button"
					class="mission-df-amount-btn mission-df-amount-btn--other"
					data-wp-on--click="actions.toggleCustomAmount"
					data-wp-class--active="state.isCustomAmount"
				>
					<?php esc_html_e( 'Other', 'mission' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<?php // Custom amount input. ?>
		<?php if ( ! empty( $settings['customAmount'] ) ) : ?>
			<div class="mission-df-custom-amount" data-wp-bind--hidden="!state.isCustomAmount" data-wp-class--visible="state.isCustomAmount">
				<div class="mission-df-input-with-prefix">
					<span class="mission-df-currency-symbol"><?php echo esc_html( $currency_symbol ); ?></span>
					<input
						type="number"
						class="mission-df-custom-input"
						id="<?php echo esc_attr( $uid . 'custom-amount' ); ?>"
						placeholder="0.00"
						min="0"
						step="0.01"
						data-wp-on--input="actions.updateCustomAmount"
						data-wp-watch="callbacks.focusCustomInput"
						aria-label="<?php esc_attr_e( 'Custom amount', 'mission' ); ?>"
					/>
				</div>
				<p class="mission-df-minimum-warning" data-wp-bind--hidden="!callbacks.showMinimumWarning">
					<?php
					printf(
						/* translators: %s: formatted minimum amount */
						esc_html__( 'Minimum donation is %s', 'mission' ),
						'<span data-wp-text="callbacks.formattedMinimumAmount"></span>'
					);
					?>
				</p>
			</div>
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
					<?php esc_html_e( 'Dedicate this gift in honor or memory of someone', 'mission' ); ?>
				</label>

				<div class="mission-df-tribute-fields" data-wp-bind--hidden="!state.tributeChecked" data-wp-class--visible="state.tributeChecked">
					<div class="mission-df-field">
						<label for="<?php echo esc_attr( $uid . 'honoree-name' ); ?>"><?php esc_html_e( 'Honoree Name', 'mission' ); ?></label>
						<input
							type="text"
							id="<?php echo esc_attr( $uid . 'honoree-name' ); ?>"
							data-wp-on--input="actions.updateHonoreeName"
							data-wp-bind--value="state.honoreeName"
						/>
					</div>
					<div class="mission-df-field">
						<label for="<?php echo esc_attr( $uid . 'honoree-email' ); ?>"><?php esc_html_e( 'Notify by Email (optional)', 'mission' ); ?></label>
						<input
							type="email"
							id="<?php echo esc_attr( $uid . 'honoree-email' ); ?>"
							data-wp-on--input="actions.updateHonoreeEmail"
							data-wp-bind--value="state.honoreeEmail"
						/>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<button
			type="button"
			class="mission-df-btn mission-df-btn--primary"
			data-wp-on--click="actions.nextStep"
		>
			<?php esc_html_e( 'Continue', 'mission' ); ?>
		</button>
	</div>

	<?php // ───── Step 2: Payment ───── ?>
	<div
		class="mission-df-step mission-df-step-2"
		data-wp-bind--hidden="!callbacks.isStep2"
		data-wp-class--visible="callbacks.isStep2"
	>
		<button type="button" class="mission-df-back-link" data-wp-on--click="actions.prevStep">
			&larr; <?php esc_html_e( 'Back', 'mission' ); ?>
		</button>

		<?php // Donation amount headline. ?>
		<div class="mission-df-amount-headline">
			<span class="mission-df-amount-display" data-wp-text="callbacks.formattedAmountWithFrequency"></span>
		</div>

		<?php // Fee recovery. ?>
		<?php if ( ! empty( $settings['feeRecovery'] ) ) : ?>
			<div class="mission-df-fee-recovery">
				<p class="mission-df-fee-line">
					<span class="mission-df-fee-amount-text" data-wp-class--uncovered="!state.feeRecoveryChecked">+ <span data-wp-text="callbacks.formattedFeeAmount"></span>
					<?php esc_html_e( 'processing fee', 'mission' ); ?></span>
					<button type="button" class="mission-df-fee-edit" data-wp-on--click="actions.toggleFeeDetails">
						<?php esc_html_e( 'Edit', 'mission' ); ?>
					</button>
				</p>
				<div class="mission-df-fee-details" data-wp-bind--hidden="!state.showFeeDetails" data-wp-class--visible="state.showFeeDetails">
					<p><?php esc_html_e( 'Payment processors take a cut of each transaction. You have the option to cover these fees so 100% of your gift can go to the cause you care about.', 'mission' ); ?></p>
					<label class="mission-df-checkbox-label">
						<input
							type="checkbox"
							data-wp-on--change="actions.toggleFeeRecovery"
							data-wp-bind--checked="state.feeRecoveryChecked"
						/>
						<?php esc_html_e( 'I want to cover the fee', 'mission' ); ?>
					</label>
				</div>
			</div>
		<?php endif; ?>

		<?php // Donor fields. ?>
		<div class="mission-df-donor-fields">
			<div class="mission-df-name-row">
				<div class="mission-df-field">
					<label for="<?php echo esc_attr( $uid . 'first-name' ); ?>"><?php esc_html_e( 'First Name', 'mission' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $uid . 'first-name' ); ?>"
						required
						data-wp-on--input="actions.updateFirstName"
						data-wp-bind--value="state.firstName"
					/>
				</div>
				<div class="mission-df-field">
					<label for="<?php echo esc_attr( $uid . 'last-name' ); ?>"><?php esc_html_e( 'Last Name', 'mission' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $uid . 'last-name' ); ?>"
						required
						data-wp-on--input="actions.updateLastName"
						data-wp-bind--value="state.lastName"
					/>
				</div>
			</div>
			<div class="mission-df-field">
				<label for="<?php echo esc_attr( $uid . 'email' ); ?>"><?php esc_html_e( 'Email Address', 'mission' ); ?></label>
				<input
					type="email"
					id="<?php echo esc_attr( $uid . 'email' ); ?>"
					required
					data-wp-on--input="actions.updateEmail"
					data-wp-bind--value="state.email"
				/>
			</div>
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
					<?php esc_html_e( 'Make my donation anonymous', 'mission' ); ?>
				</label>
			</div>
		<?php endif; ?>

		<?php // Card element placeholder. ?>
		<div class="mission-df-card-element" id="<?php echo esc_attr( $uid . 'card-element' ); ?>">
			<p class="mission-df-card-placeholder"><?php esc_html_e( 'Card details will appear here', 'mission' ); ?></p>
		</div>

		<?php // Tip section. ?>
		<?php if ( ! empty( $settings['tipEnabled'] ) ) : ?>
			<div class="mission-df-tip">
				<div class="mission-df-tip-card">
					<p class="mission-df-tip-text">
						<?php
						printf(
							/* translators: %s: site name */
							esc_html__( 'An optional tip allows %s to use Mission\'s free donation platform and keeps it running for all nonprofits. Thank you!', 'mission' ),
							'<strong>' . esc_html( $settings['siteName'] ?? get_bloginfo( 'name' ) ) . '</strong>'
						);
						?>
					</p>
					<div class="mission-df-tip-selector">
						<button type="button" class="mission-df-tip-arrow mission-df-tip-arrow--up" data-wp-on--click="actions.tipUp" aria-label="<?php esc_attr_e( 'Increase tip', 'mission' ); ?>">
							<svg width="14" height="14" viewBox="0 0 14 14"><path d="M4 9l3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</button>
						<span class="mission-df-tip-value" data-wp-text="callbacks.tipPercentLabel"></span>
						<button type="button" class="mission-df-tip-arrow mission-df-tip-arrow--down" data-wp-on--click="actions.tipDown" aria-label="<?php esc_attr_e( 'Decrease tip', 'mission' ); ?>">
							<svg width="14" height="14" viewBox="0 0 14 14"><path d="M4 5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</button>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php // Donate button. ?>
		<button
			type="button"
			class="mission-df-btn mission-df-btn--primary mission-df-donate-btn"
			data-wp-on--click="actions.submit"
		>
			<?php esc_html_e( 'Donate', 'mission' ); ?> <span data-wp-text="callbacks.formattedTotalAmount"></span>
		</button>
	</div>
</section>
