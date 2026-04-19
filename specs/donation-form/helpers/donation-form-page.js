/**
 * Page object for the donation form block.
 *
 * Encapsulates common interactions so test files stay clean.
 */
class DonationFormPage {
  /**
   * @param {import('@playwright/test').Page} page Playwright page.
   */
  constructor( page ) {
    this.page = page;
    this.form = page.locator( '.mission-donation-form' );
  }

  /**
   * Navigate to the campaign frontend page.
   *
   * @param {string} url Campaign URL.
   */
  async goto( url ) {
    await this.page.goto( url );
    await this.form.waitFor( { state: 'visible' } );
  }

  // ---------------------------------------------------------------------------
  // Step 1 — Amount selection
  // ---------------------------------------------------------------------------

  /**
   * Click a preset amount button by its displayed text.
   *
   * @param {string} text The amount text (e.g. "$25").
   */
  async selectAmount( text ) {
    // Ensure the text includes ".00" for exact matching.
    const label = text.includes( '.' ) ? text : `${ text }.00`;
    await this.form.getByRole( 'button', { name: label, exact: true } ).click();
  }

  /**
   * Enter a custom amount.
   *
   * @param {string} value The amount to type (e.g. "75").
   */
  async enterCustomAmount( value ) {
    const otherBtn = this.form.locator( '.mission-df-amount-btn--other' );
    if ( await otherBtn.isVisible() ) {
      await otherBtn.click();
    }
    const input = this.form.locator( '.mission-df-other-field' );
    await input.fill( value );
  }

  /**
   * Click the "One Time" frequency button.
   */
  async selectOneTime() {
    await this.form.locator( '.mission-df-frequency-btn' ).first().click();
  }

  /**
   * Click the "Ongoing" frequency button.
   */
  async selectOngoing() {
    await this.form.locator( '.mission-df-frequency-btn' ).last().click();
  }

  /**
   * Select a recurring frequency from the dropdown.
   *
   * @param {string} label Visible label (e.g. "Monthly", "Annually").
   */
  async selectFrequency( label ) {
    // Open the dropdown.
    await this.form.locator( '.mission-df-recurring-dropdown' ).click();
    // Click the option.
    await this.form
      .locator( '.mission-df-recurring-dropdown-option' )
      .filter( { hasText: label } )
      .click();
  }

  /**
   * Click the Continue button (step 1 → step 2/3).
   */
  async clickContinue() {
    await this.form.locator( '.mission-df-btn--primary' ).first().click();
  }

  // ---------------------------------------------------------------------------
  // Step 1 — Tribute / Dedication
  // ---------------------------------------------------------------------------

  /**
   * Enable the tribute checkbox.
   */
  async enableTribute() {
    await this.form
      .locator( '.mission-df-tribute' )
      .locator( 'input[type="checkbox"]' )
      .check();
  }

  /**
   * Select the tribute type (in_honor or in_memory).
   *
   * @param {string} type "in_honor" or "in_memory".
   */
  async selectTributeType( type ) {
    await this.form
      .locator( `.mission-df-tribute-type-btn[data-type="${ type }"]` )
      .click();
  }

  /**
   * Fill the honoree name field.
   *
   * @param {string} name Honoree name.
   */
  async fillHonoreeName( name ) {
    await this.form.locator( 'input[id$="honoree-name"]' ).fill( name );
  }

  /**
   * Toggle the "Notify someone" button.
   */
  async enableNotification() {
    await this.form.locator( '.mission-df-tribute-notify-link' ).click();
  }

  /**
   * Fill notification email fields.
   *
   * @param {string} name  Recipient name.
   * @param {string} email Recipient email.
   */
  async fillNotifyEmail( name, email ) {
    await this.form.locator( 'input[id$="notify-name"]' ).fill( name );
    await this.form.locator( 'input[id$="notify-email"]' ).fill( email );
  }

  /**
   * Fill the tribute personal message.
   *
   * @param {string} msg Message text.
   */
  async fillTributeMessage( msg ) {
    await this.form.locator( 'textarea[id$="tribute-message"]' ).fill( msg );
  }

  // ---------------------------------------------------------------------------
  // Step 2 — Custom fields
  // ---------------------------------------------------------------------------

  /**
   * Fill a custom text/textarea field by its label.
   *
   * @param {string} label Field label text.
   * @param {string} value Value to enter.
   */
  async fillCustomField( label, value ) {
    const field = this.form
      .locator( '.mission-df-custom-field' )
      .filter( { hasText: label } );
    const input = field.locator( 'input, textarea' );
    await input.fill( value );
  }

  // ---------------------------------------------------------------------------
  // Payment step — Donor info
  // ---------------------------------------------------------------------------

  /**
   * Fill donor information fields.
   *
   * @param {Object} info           Donor info.
   * @param {string} info.firstName First name.
   * @param {string} info.lastName  Last name.
   * @param {string} info.email     Email address.
   */
  async fillDonorInfo( { firstName, lastName, email } ) {
    await this.form.locator( 'input[id$="first-name"]' ).fill( firstName );
    await this.form.locator( 'input[id$="last-name"]' ).fill( lastName );
    await this.form.locator( 'input[id$="email"]' ).fill( email );
  }

  /**
   * Fill the phone field.
   *
   * @param {string} phone Phone number.
   */
  async fillPhone( phone ) {
    await this.form.locator( 'input[id$="phone"]' ).fill( phone );
  }

  /**
   * Toggle the fee recovery checkbox.
   */
  async toggleFeeRecovery() {
    // The checkbox is inside the fee details panel — expand it first if hidden.
    const details = this.form.locator( '.mission-df-fee-details' );
    if ( ! ( await details.isVisible() ) ) {
      await this.form.locator( '.mission-df-fee-edit' ).click();
      await details.waitFor( { state: 'visible' } );
    }
    await details.locator( 'input[type="checkbox"]' ).click();
  }

  /**
   * Open the tip menu, retrying if the first click gets swallowed by the
   * Interactivity API hydration race or by the `wp-on-document--click`
   * outside-click handler firing against its own opening click.
   */
  async openTipMenu() {
    const menu = this.form.locator( '.mission-df-tip-menu' );
    const trigger = this.form.locator( '.mission-df-tip-trigger' );

    if ( await menu.isVisible() ) {
      return menu;
    }

    // Wait for the trigger to be fully interactive (not just attached) before
    // clicking. Avoids the case where Interactivity API handlers haven't hooked up yet.
    await trigger.waitFor( { state: 'visible' } );

    for ( let attempt = 0; attempt < 3; attempt++ ) {
      await trigger.click();
      try {
        await menu.waitFor( { state: 'visible', timeout: 1500 } );
        return menu;
      } catch {
        // Click didn't open the menu. Brief pause before retry.
        await this.page.waitForTimeout( 200 );
      }
    }

    throw new Error( 'Tip menu did not open after 3 attempts.' );
  }

  /**
   * Open the tip menu and select a percentage.
   *
   * @param {number} percent Tip percentage (e.g. 15).
   */
  async selectTip( percent ) {
    const menu = await this.openTipMenu();

    await this.form
      .locator( '.mission-df-tip-option' )
      .filter( { hasText: new RegExp( `^${ percent }%$` ) } )
      .click();

    // Wait for the menu to close so a subsequent action sees a clean state.
    await menu.waitFor( { state: 'hidden' } );
  }

  /**
   * Enter a custom tip amount.
   *
   * @param {string} value Tip amount (e.g. "3").
   */
  async enterCustomTip( value ) {
    const menu = await this.openTipMenu();

    await this.form.locator( '.mission-df-tip-option--other' ).click();
    await menu.waitFor( { state: 'hidden' } );

    // Wait for the custom-tip input to actually become interactive before
    // filling it — the Interactivity API toggles visibility reactively
    // and Playwright's fill can race against that.
    const input = this.form.locator( '.mission-df-tip-custom-input' );
    await input.waitFor( { state: 'visible' } );
    await input.fill( value );
  }

  /**
   * Fill the Stripe Payment Element test card inside its iframe.
   *
   * @param {string} cardNumber Card number (default: Stripe test success card).
   */
  async fillStripeCard( cardNumber = '4242424242424242' ) {
    // Wait for the Payment Element to load.
    const paymentElement = this.form.locator( '.mission-df-payment-element' );
    await paymentElement.waitFor( { state: 'visible' } );

    // Stripe Payment Element renders nested iframes. Find the one
    // containing the card number field.
    const cardFrame = this.page
      .frameLocator( 'iframe[name*="__privateStripeFrame"]' )
      .first();

    // Wait for the card number input to be ready.
    const cardInput = cardFrame.locator(
      '[name="cardnumber"], [name="number"], [autocomplete="cc-number"]'
    );
    await cardInput.waitFor( { state: 'visible', timeout: 15000 } );
    await cardInput.fill( cardNumber );

    // Fill expiry — may be in the same or a different iframe.
    const expiryInput = cardFrame.locator(
      '[name="exp-date"], [name="expiry"], [autocomplete="cc-exp"]'
    );
    if ( await expiryInput.isVisible() ) {
      await expiryInput.fill( '12/30' );
    }

    // Fill CVC.
    const cvcInput = cardFrame.locator(
      '[name="cvc"], [autocomplete="cc-csc"]'
    );
    if ( await cvcInput.isVisible() ) {
      await cvcInput.fill( '123' );
    }

    // Fill postal/zip code if shown (depends on card country).
    const zipInput = cardFrame.locator(
      '[name="postalCode"], [name="zip"], [autocomplete="postal-code"]'
    );
    if ( await zipInput.isVisible( { timeout: 1000 } ).catch( () => false ) ) {
      await zipInput.fill( '12345' );
    }
  }

  /**
   * Click the Donate button.
   */
  async clickDonate() {
    await this.form.locator( '.mission-df-donate-btn' ).click();
  }

  /**
   * Click the back link to return to the previous step.
   */
  async clickBack() {
    await this.form.locator( '.mission-df-back-link' ).click();
  }

  // ---------------------------------------------------------------------------
  // Assertions
  // ---------------------------------------------------------------------------

  /**
   * Assert the form is on the expected step.
   *
   * @param {number}                            step   Expected step (1, 2, or 3).
   * @param {import('@playwright/test').expect} expect Playwright expect.
   */
  async expectStep( step, expect ) {
    if ( step === 1 ) {
      await expect(
        this.form.locator( '.mission-df-step-1.active' )
      ).toBeVisible();
    } else {
      await expect(
        this.form.locator( `.mission-df-step:nth-child(${ step }).active` )
      ).toBeVisible();
    }
  }

  /**
   * Assert the amount headline displays the expected text.
   *
   * @param {string}                            text   Expected text (e.g. "$25.00").
   * @param {import('@playwright/test').expect} expect Playwright expect.
   */
  async expectAmountHeadline( text, expect ) {
    await expect(
      this.form.locator( '.mission-df-amount-display' )
    ).toContainText( text );
  }

  /**
   * Assert the form shows the success state.
   *
   * @param {import('@playwright/test').expect} expect Playwright expect.
   */
  async expectSuccess( expect ) {
    await expect( this.form.locator( '.mission-df-success' ) ).toBeVisible( {
      timeout: 30000,
    } );
  }

  /**
   * Assert a payment error is visible.
   *
   * @param {string}                            text   Expected error text.
   * @param {import('@playwright/test').expect} expect Playwright expect.
   */
  async expectPaymentError( text, expect ) {
    await expect( this.form.locator( '.mission-df-card-error' ) ).toContainText(
      text,
      { timeout: 15000 }
    );
  }

  /**
   * Assert a field has an error state.
   *
   * @param {string}                            selector CSS selector for the field.
   * @param {import('@playwright/test').expect} expect   Playwright expect.
   */
  async expectFieldError( selector, expect ) {
    await expect( this.form.locator( selector ) ).toHaveClass(
      /mission-df-field-error/
    );
  }

  /**
   * Get the selected (active) amount button text.
   *
   * @return {Promise<string>} Selected amount button text.
   */
  async getSelectedAmountText() {
    return this.form
      .locator( '.mission-df-amount-btn.active' )
      .first()
      .textContent();
  }

  /**
   * Check if the fee recovery section is visible.
   *
   * @return {Promise<boolean>} Whether fee recovery is visible.
   */
  async isFeeRecoveryVisible() {
    return this.form.locator( '.mission-df-fee-recovery' ).isVisible();
  }

  /**
   * Get the fee amount text.
   *
   * @return {Promise<string>} Fee amount display text.
   */
  async getFeeAmountText() {
    return this.form.locator( '.mission-df-fee-amount-text' ).textContent();
  }

  /**
   * Check if the tip section is visible.
   *
   * @return {Promise<boolean>} Whether tip section is visible.
   */
  async isTipVisible() {
    return this.form.locator( '.mission-df-tip' ).isVisible();
  }
}

module.exports = { DonationFormPage };
