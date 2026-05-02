<?php
/**
 * Donor Dashboard — Recurring Donations panel.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mission-dd-panel" data-wp-class--active="state.isRecurring">
	<!-- Empty state -->
	<div class="mission-dd-empty" data-wp-bind--hidden="context.recurring.hasAny">
		<p><?php esc_html_e( 'You don\'t have any recurring donations yet. When you set up a recurring gift, it will appear here.', 'mission-donation-platform' ); ?></p>
	</div>

	<!-- Active Subscriptions -->
	<div data-wp-bind--hidden="!context.recurring.hasActive">
		<h2 class="mission-dd-section-title"><?php esc_html_e( 'Active', 'mission-donation-platform' ); ?></h2>

		<template data-wp-each--sub="context.recurring.activeSubscriptions">
			<div class="mission-dd-subscription">
				<div class="mission-dd-subscription-header">
					<div>
						<div class="mission-dd-subscription-amount">
							<span data-wp-text="context.sub.formattedAmount"></span>
							<span class="mission-dd-subscription-freq" data-wp-text="context.sub.frequencySuffix"></span>
						</div>
						<div class="mission-dd-subscription-campaign" data-wp-text="context.sub.campaignName"></div>
					</div>
					<span class="mission-dd-badge mission-dd-badge-active" data-wp-bind--hidden="state.subIsPaused"><?php esc_html_e( 'Active', 'mission-donation-platform' ); ?></span>
					<span class="mission-dd-badge mission-dd-badge-paused" data-wp-bind--hidden="state.subIsActive"><?php esc_html_e( 'Paused', 'mission-donation-platform' ); ?></span>
				</div>

				<!-- Error banner -->
				<div class="mission-dd-subscription-error" data-wp-bind--hidden="!context.sub.actionError" role="alert" aria-live="polite">
					<span data-wp-text="context.sub.actionError"></span>
				</div>

				<div class="mission-dd-subscription-details">
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Frequency', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.frequencyLabel"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Started', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.started"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Next Payment', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-bind--hidden="state.subIsPaused" data-wp-text="context.sub.nextPayment"></div>
						<div class="mission-dd-subscription-detail-value" data-wp-bind--hidden="state.subIsActive"><?php esc_html_e( 'Paused', 'mission-donation-platform' ); ?></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Payments Made', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.paymentsMade"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Total Contributed', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.totalContributed"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Payment Method', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.paymentMethod"></div>
					</div>
				</div>

				<div class="mission-dd-subscription-actions">
					<?php if ( $show_update_payment ) : ?>
					<button
						class="mission-dd-btn"
						data-wp-on--click="actions.openUpdatePayment"
					>
						<span class="mission-dd-icon mission-dd-icon-credit-card" aria-hidden="true"></span>
						<?php esc_html_e( 'Update Payment Method', 'mission-donation-platform' ); ?>
					</button>
					<?php endif; ?>
					<button class="mission-dd-btn" data-wp-on--click="actions.openChangeAmount">
						<span class="mission-dd-icon mission-dd-icon-pencil" aria-hidden="true"></span>
						<?php esc_html_e( 'Change Amount', 'mission-donation-platform' ); ?>
					</button>
					<!-- Pause (shown when active) -->
					<button
						class="mission-dd-btn"
						data-wp-on--click="actions.pauseSubscription"
						data-wp-bind--disabled="context.sub.pauseLoading"
						data-wp-bind--hidden="!state.subIsActive"
					>
						<span class="mission-dd-icon mission-dd-icon-pause" aria-hidden="true"></span>
						<?php esc_html_e( 'Pause', 'mission-donation-platform' ); ?>
					</button>
					<!-- Resume (shown when paused) -->
					<button
						class="mission-dd-btn"
						data-wp-on--click="actions.resumeSubscription"
						data-wp-bind--disabled="context.sub.pauseLoading"
						data-wp-bind--hidden="!state.subIsPaused"
					>
						<span class="mission-dd-icon mission-dd-icon-play" aria-hidden="true"></span>
						<?php esc_html_e( 'Resume', 'mission-donation-platform' ); ?>
					</button>
					<button
						class="mission-dd-btn mission-dd-btn-danger"
						data-wp-on--click="actions.cancelSubscription"
						data-wp-bind--disabled="context.sub.cancelLoading"
					>
						<span class="mission-dd-icon mission-dd-icon-x-circle" aria-hidden="true"></span>
						<?php esc_html_e( 'Cancel', 'mission-donation-platform' ); ?>
					</button>
				</div>

				<!-- Change Amount Panel -->
				<div class="mission-dd-ca" data-wp-class--visible="state.subChangeAmountOpen">
					<div class="mission-dd-ca-inner">

						<div class="mission-dd-subscription-error" data-wp-bind--hidden="!context.sub.changeError" role="alert" aria-live="polite">
							<span data-wp-text="context.sub.changeError"></span>
						</div>

						<?php // Amount input. ?>
						<div class="mission-dd-ca-label" data-wp-text="state.changeAmountLabel"></div>
						<div class="mission-dd-ca-amount-field">
							<span class="mission-dd-ca-amount-prefix" data-wp-text="context.recurring.currencySymbol"></span>
							<input
								type="number"
								min="1"
								step="0.01"
								class="mission-dd-ca-amount-input"
								data-wp-bind--value="context.sub.changeAmountInput"
								data-wp-on--input="actions.updateChangeAmountInput"
							/>
						</div>

						<?php // Fee recovery. ?>
						<div class="mission-dd-ca-fee">
							<p class="mission-dd-ca-fee-line">
								<span class="mission-dd-ca-fee-text" data-wp-class--uncovered="!context.sub.changeFeeRecoveryChecked">+ <span data-wp-text="state.changeFormattedFeeAmount"></span>
								<?php esc_html_e( 'processing fee', 'mission-donation-platform' ); ?></span>
								<button type="button" class="mission-dd-ca-fee-edit" data-wp-on--click="actions.toggleChangeFeeDetails">
									<?php esc_html_e( 'Edit', 'mission-donation-platform' ); ?>
								</button>
							</p>
							<div class="mission-dd-ca-fee-details" data-wp-bind--hidden="!context.sub.changeFeeDetailsOpen" data-wp-class--visible="context.sub.changeFeeDetailsOpen">
								<div class="mission-dd-ca-fee-details-inner">
									<p><?php esc_html_e( 'Payment processors take a cut of each transaction. You have the option to cover these fees so 100% of your gift goes directly to the cause you care about.', 'mission-donation-platform' ); ?></p>
									<label class="mission-dd-ca-checkbox-label">
										<input
											type="checkbox"
											data-wp-on--change="actions.toggleChangeFeeRecovery"
											data-wp-bind--checked="context.sub.changeFeeRecoveryChecked"
										/>
										<?php esc_html_e( 'I want to cover the fee', 'mission-donation-platform' ); ?>
									</label>
								</div>
							</div>
						</div>

						<?php // Tip (only when fee_mode is not flat). ?>
						<div class="mission-dd-ca-tip" data-wp-bind--hidden="context.sub.feeModeFlat" data-wp-on-document--click="actions.closeChangeTipMenu">
							<div class="mission-dd-ca-tip-card">
								<div class="mission-dd-ca-tip-header">
									<p class="mission-dd-ca-tip-text">
										<?php esc_html_e( 'An optional tip allows us to use MissionDP\'s free donation platform and keeps it running for all nonprofits. Thank you!', 'mission-donation-platform' ); ?>
									</p>
									<div class="mission-dd-ca-tip-trigger-wrap">
										<button type="button" class="mission-dd-ca-tip-trigger" data-wp-on--click="actions.toggleChangeTipMenu">
											<span class="mission-dd-ca-tip-trigger-chevron">
												<svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5L5 1 1 5"/></svg>
											</span>
											<span class="mission-dd-ca-tip-trigger-value" data-wp-text="state.changeTipTriggerLabel"></span>
											<span class="mission-dd-ca-tip-trigger-chevron">
												<svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 1l4 4 4-4"/></svg>
											</span>
										</button>
										<div class="mission-dd-ca-tip-menu" data-wp-bind--hidden="!context.sub.changeTipMenuOpen" data-wp-class--visible="context.sub.changeTipMenuOpen">
											<button type="button" class="mission-dd-ca-tip-option" data-wp-context='{"changeTipPercent":20}' data-wp-on--click="actions.selectChangeTipPercent" data-wp-class--active="state.isChangeTipOptionActive">20%</button>
											<button type="button" class="mission-dd-ca-tip-option" data-wp-context='{"changeTipPercent":15}' data-wp-on--click="actions.selectChangeTipPercent" data-wp-class--active="state.isChangeTipOptionActive">15%</button>
											<button type="button" class="mission-dd-ca-tip-option" data-wp-context='{"changeTipPercent":10}' data-wp-on--click="actions.selectChangeTipPercent" data-wp-class--active="state.isChangeTipOptionActive">10%</button>
											<button type="button" class="mission-dd-ca-tip-option mission-dd-ca-tip-option--other" data-wp-on--click="actions.selectChangeCustomTip" data-wp-class--active="context.sub.changeIsCustomTip">
												<?php esc_html_e( 'Other', 'mission-donation-platform' ); ?>
											</button>
										</div>
									</div>
								</div>
								<div class="mission-dd-ca-tip-custom" data-wp-bind--hidden="!context.sub.changeIsCustomTip" data-wp-class--visible="context.sub.changeIsCustomTip">
									<div class="mission-dd-ca-tip-custom-inner">
										<div class="mission-dd-ca-tip-custom-label">
											<span class="mission-dd-ca-tip-heart">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 9.1371C2 14 6.01943 16.5914 8.96173 18.9109C10 19.7294 11 20.5 12 20.5C13 20.5 14 19.7294 15.0383 18.9109C17.9806 16.5914 22 14 22 9.1371C22 4.27416 16.4998 0.825464 12 5.50063C7.50016 0.825464 2 4.27416 2 9.1371Z"/></svg>
											</span>
											<?php esc_html_e( 'Help keep this platform free', 'mission-donation-platform' ); ?>
										</div>
										<div class="mission-dd-ca-tip-custom-row">
											<button type="button" class="mission-dd-ca-tip-custom-btn" data-wp-on--click="actions.changeTipCustomDown" aria-label="<?php esc_attr_e( 'Decrease tip', 'mission-donation-platform' ); ?>">&minus;</button>
											<div class="mission-dd-ca-tip-custom-input-wrap">
												<span class="mission-dd-ca-tip-custom-prefix" data-wp-text="context.recurring.currencySymbol"></span>
												<input
													type="number"
													class="mission-dd-ca-tip-custom-input"
													min="0"
													step="1"
													data-wp-on--input="actions.updateChangeCustomTipAmount"
													data-wp-bind--value="state.changeCustomTipDisplayValue"
													aria-label="<?php esc_attr_e( 'Custom tip amount', 'mission-donation-platform' ); ?>"
												/>
											</div>
											<button type="button" class="mission-dd-ca-tip-custom-btn" data-wp-on--click="actions.changeTipCustomUp" aria-label="<?php esc_attr_e( 'Increase tip', 'mission-donation-platform' ); ?>">&plus;</button>
										</div>
									</div>
								</div>
							</div>
						</div>

						<?php // Actions. ?>
						<div class="mission-dd-ca-actions">
							<button
								type="button"
								class="mission-dd-ca-update-btn"
								data-wp-on--click="actions.submitChangeAmount"
								data-wp-bind--disabled="context.sub.changeLoading"
							>
								<span data-wp-text="state.changeUpdateButtonLabel"></span>
							</button>
							<button type="button" class="mission-dd-ca-cancel" data-wp-on--click="actions.closeChangeAmount">
								<?php esc_html_e( 'Cancel', 'mission-donation-platform' ); ?>
							</button>
						</div>
					</div>
				</div>

				<?php if ( $show_update_payment ) : ?>
				<!-- Update Payment Method Modal -->
				<div class="mission-dd-modal" data-wp-bind--hidden="!context.sub.updatePaymentOpen">
					<div class="mission-dd-modal-backdrop" data-wp-on--click="actions.closeUpdatePayment"></div>
					<div class="mission-dd-modal-content">
						<h3 class="mission-dd-modal-title"><?php esc_html_e( 'Update Payment Method', 'mission-donation-platform' ); ?></h3>

						<div class="mission-dd-subscription-error" data-wp-bind--hidden="!context.sub.updatePaymentError" role="alert" aria-live="polite">
							<span data-wp-text="context.sub.updatePaymentError"></span>
						</div>

						<div class="mission-dd-modal-field">
							<div
								class="mission-dd-stripe-element"
								data-wp-bind--id="state.subPaymentElementId"
							></div>
						</div>

						<div class="mission-dd-modal-actions">
							<button class="mission-dd-btn" data-wp-on--click="actions.closeUpdatePayment">
								<?php esc_html_e( 'Cancel', 'mission-donation-platform' ); ?>
							</button>
							<button
								class="mission-dd-btn mission-dd-btn-primary"
								data-wp-on--click="actions.submitUpdatePayment"
								data-wp-bind--disabled="state.subUpdatePaymentDisabled"
							>
								<span data-wp-text="state.subUpdatePaymentLabel"></span>
							</button>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</template>
	</div>

	<!-- Past / Cancelled Subscriptions -->
	<div data-wp-bind--hidden="!context.recurring.hasCancelled">
		<h2 class="mission-dd-section-title mission-dd-subscription-past-title"><?php esc_html_e( 'Past', 'mission-donation-platform' ); ?></h2>

		<template data-wp-each--sub="context.recurring.cancelledSubscriptions">
			<div class="mission-dd-subscription mission-dd-subscription-past">
				<div class="mission-dd-subscription-header">
					<div>
						<div class="mission-dd-subscription-amount">
							<span data-wp-text="context.sub.formattedAmount"></span>
							<span class="mission-dd-subscription-freq" data-wp-text="context.sub.frequencySuffix"></span>
						</div>
						<div class="mission-dd-subscription-campaign" data-wp-text="context.sub.campaignName"></div>
					</div>
					<span class="mission-dd-badge mission-dd-badge-cancelled"><?php esc_html_e( 'Cancelled', 'mission-donation-platform' ); ?></span>
				</div>

				<div class="mission-dd-subscription-details">
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Frequency', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.frequencyLabel"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Period', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.period"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Payments Made', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.paymentsMade"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Total Contributed', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.totalContributed"></div>
					</div>
					<div class="mission-dd-subscription-detail">
						<div class="mission-dd-subscription-detail-label"><?php esc_html_e( 'Cancelled', 'mission-donation-platform' ); ?></div>
						<div class="mission-dd-subscription-detail-value" data-wp-text="context.sub.cancelled"></div>
					</div>
				</div>
			</div>
		</template>
	</div>

</div>
