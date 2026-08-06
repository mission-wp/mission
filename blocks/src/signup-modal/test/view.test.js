/* eslint-env jest */
/* global HTMLElement */

/**
 * Tests for the sign-up modal's keyboard handling.
 *
 * Uses the mock of @wordpress/interactivity (mapped via jest.config.js) so we
 * can call the store's onKeydown action against jsdom-built modal DOM.
 */

const interactivity = require( '@wordpress/interactivity' );

// Import the view module — this calls store() and populates _mockStoreDefinition.
require( '../view' );

const { _mockStoreDefinition: storeDef } = interactivity;

// jsdom never lays out, so offsetParent is always null; the handler uses it
// to skip hidden buttons. Treat every attached element as visible.
beforeAll( () => {
  Object.defineProperty( HTMLElement.prototype, 'offsetParent', {
    configurable: true,
    get() {
      return this.parentNode;
    },
  } );
} );

describe( 'onKeydown Enter handling', () => {
  let root;
  let primary;

  beforeEach( () => {
    document.body.innerHTML = `
      <div class="mission-su">
        <div class="mission-su__dialog">
          <button type="button" class="mission-su__close">&times;</button>
          <input type="text" class="test-text-input" />
          <textarea></textarea>
          <select><option value="">Choose a team</option></select>
          <a href="https://example.com/page" class="mission-su__btn">View my page</a>
          <button type="button" class="mission-su__link">Log out</button>
          <button type="button" class="mission-su__toggle-btn">Create a team</button>
          <button type="button" class="mission-su__btn">Continue</button>
          <button type="button" class="mission-su__btn mission-su__btn--ghost">Back</button>
        </div>
      </div>`;
    root = document.querySelector( '.mission-su' );
    primary = root.querySelector(
      'button.mission-su__btn:not(.mission-su__btn--ghost)'
    );
    jest.spyOn( primary, 'click' );
  } );

  /**
   * Dispatch an Enter keydown from the given element through the store action.
   *
   * @param {Element} target The focused element.
   * @return {Object} The fake event, for asserting on preventDefault.
   */
  function pressEnter( target ) {
    const event = { key: 'Enter', target, preventDefault: jest.fn() };
    storeDef.actions.onKeydown( event );
    return event;
  }

  it( 'clicks the primary button when Enter is pressed in a text input', () => {
    const event = pressEnter( root.querySelector( '.test-text-input' ) );

    expect( event.preventDefault ).toHaveBeenCalled();
    expect( primary.click ).toHaveBeenCalled();
  } );

  it.each( [
    [ 'close button', '.mission-su__close' ],
    [ 'link button', '.mission-su__link' ],
    [ 'toggle button', '.mission-su__toggle-btn' ],
    [ 'anchor', 'a' ],
    [ 'select', 'select' ],
    [ 'textarea', 'textarea' ],
  ] )( 'leaves native Enter activation on the %s', ( label, selector ) => {
    const event = pressEnter( root.querySelector( selector ) );

    expect( event.preventDefault ).not.toHaveBeenCalled();
    expect( primary.click ).not.toHaveBeenCalled();
  } );

  it( 'ignores keys other than Enter', () => {
    const event = {
      key: 'a',
      target: root.querySelector( '.test-text-input' ),
      preventDefault: jest.fn(),
    };

    storeDef.actions.onKeydown( event );

    expect( event.preventDefault ).not.toHaveBeenCalled();
    expect( primary.click ).not.toHaveBeenCalled();
  } );
} );

describe( 'open( payload ) rebinding', () => {
  beforeEach( () => {
    document.body.innerHTML = `
      <div class="mission-su"><div class="mission-su__dialog"></div></div>`;
    // Simulate the server-seeded default payload for campaign 1.
    Object.assign( storeDef.state, {
      campaignId: 1,
      brandline: 'First Drive',
      defaultGoal: 500,
      goal: 500,
      preselectedTeamId: 0,
      preselectedTeamName: '',
      showTeamChooser: true,
      teams: [ { id: '7', name: 'Open Crew' } ],
      signedIn: false,
      teamId: '',
      teamName: '',
      story: '',
      successUrl: '',
    } );
  } );

  afterEach( () => {
    document.body.style.overflow = '';
  } );

  it( 'resets campaign-scoped form state when opening another campaign', () => {
    Object.assign( storeDef.state, {
      firstName: 'Jane',
      teamId: '7',
      teamName: 'My Team',
      goal: 999,
      story: 'Half-typed story',
      tributeChecked: true,
      honoreeName: 'Grandma',
      successUrl: 'https://example.com/jane',
    } );

    storeDef.actions.open( {
      campaignId: 2,
      brandline: 'Second Drive',
      defaultGoal: 250,
      preselectedTeamId: 0,
      preselectedTeamName: '',
      showTeamChooser: false,
      teams: [],
    } );

    expect( storeDef.state.isOpen ).toBe( true );
    expect( storeDef.state.campaignId ).toBe( 2 );
    expect( storeDef.state.brandline ).toBe( 'Second Drive' );
    expect( storeDef.state.teams ).toEqual( [] );
    expect( storeDef.state.firstName ).toBe( '' );
    expect( storeDef.state.teamId ).toBe( '' );
    expect( storeDef.state.teamName ).toBe( '' );
    expect( storeDef.state.goal ).toBe( 250 );
    expect( storeDef.state.story ).toBe( '' );
    expect( storeDef.state.tributeChecked ).toBe( false );
    expect( storeDef.state.honoreeName ).toBe( '' );
    expect( storeDef.state.successUrl ).toBe( '' );
  } );

  it( 'keeps form state when reopening the same campaign', () => {
    Object.assign( storeDef.state, {
      firstName: 'Jane',
      goal: 999,
      story: 'Half-typed story',
    } );

    storeDef.actions.open( {
      campaignId: 1,
      brandline: 'First Drive',
      defaultGoal: 500,
      preselectedTeamId: 0,
      preselectedTeamName: '',
      showTeamChooser: true,
      teams: [ { id: '7', name: 'Open Crew' } ],
    } );

    expect( storeDef.state.firstName ).toBe( 'Jane' );
    expect( storeDef.state.goal ).toBe( 999 );
    expect( storeDef.state.story ).toBe( 'Half-typed story' );
  } );

  it( 'keeps the current binding when opened without a payload', () => {
    storeDef.state.firstName = 'Jane';

    storeDef.actions.open();

    expect( storeDef.state.isOpen ).toBe( true );
    expect( storeDef.state.campaignId ).toBe( 1 );
    expect( storeDef.state.brandline ).toBe( 'First Drive' );
    expect( storeDef.state.firstName ).toBe( 'Jane' );
  } );

  it( 'preselects the payload team', () => {
    storeDef.actions.open( {
      campaignId: 3,
      brandline: 'Join Closed Crew',
      defaultGoal: 500,
      preselectedTeamId: 12,
      preselectedTeamName: 'Closed Crew',
      showTeamChooser: false,
      teams: [],
    } );

    expect( storeDef.state.teamMode ).toBe( 'join' );
    expect( storeDef.state.teamId ).toBe( '12' );
  } );
} );

describe( 'copyLink', () => {
  let writeText;

  beforeEach( () => {
    jest.useFakeTimers();
    writeText = jest.fn();
    Object.defineProperty( window.navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    } );
    storeDef.state.copied = false;
    storeDef.state.copyLabel = 'Copy';
  } );

  afterEach( () => {
    jest.useRealTimers();
    delete window.navigator.clipboard;
  } );

  it( 'confirms only after the clipboard write resolves', async () => {
    writeText.mockReturnValue( Promise.resolve() );

    const pending = storeDef.actions.copyLink();

    expect( storeDef.state.copied ).toBe( false );
    await pending;

    expect( storeDef.state.copied ).toBe( true );
    expect( storeDef.state.copyLabel ).toBe( 'Copied' );

    jest.advanceTimersByTime( 2000 );
    expect( storeDef.state.copied ).toBe( false );
    expect( storeDef.state.copyLabel ).toBe( 'Copy' );
  } );

  it( 'reports failure when the clipboard write rejects', async () => {
    writeText.mockReturnValue( Promise.reject( new Error( 'denied' ) ) );

    await storeDef.actions.copyLink();

    expect( storeDef.state.copied ).toBe( false );
    expect( storeDef.state.copyLabel ).toBe( 'Copy failed' );

    jest.advanceTimersByTime( 2000 );
    expect( storeDef.state.copyLabel ).toBe( 'Copy' );
  } );
} );

describe( 'continueAccount validation', () => {
  beforeEach( () => {
    Object.assign( storeDef.state, {
      firstName: '',
      lastName: '',
      email: '',
      password: '',
      firstNameError: false,
      lastNameError: false,
      emailError: false,
      passwordError: false,
      formError: '',
      loading: false,
    } );
  } );

  /**
   * Run the generator action far enough to hit the validation gate.
   */
  function runValidation() {
    storeDef.actions.continueAccount().next();
  }

  it( 'flags every empty field and shows a message without fetching', () => {
    global.fetch = jest.fn();

    runValidation();

    expect( storeDef.state.firstNameError ).toBe( true );
    expect( storeDef.state.lastNameError ).toBe( true );
    expect( storeDef.state.emailError ).toBe( true );
    expect( storeDef.state.passwordError ).toBe( true );
    expect( storeDef.state.formError ).toBe(
      'Please check the highlighted fields.'
    );
    expect( global.fetch ).not.toHaveBeenCalled();
  } );

  it( 'flags a malformed email', () => {
    Object.assign( storeDef.state, {
      firstName: 'Jane',
      lastName: 'Doe',
      email: 'not-an-email',
      password: 'secret123',
    } );

    runValidation();

    expect( storeDef.state.emailError ).toBe( true );
    expect( storeDef.state.firstNameError ).toBe( false );
    expect( storeDef.state.formError ).toBe(
      'Please check the highlighted fields.'
    );
  } );

  it( 'passes validation and starts the lookup when the form is complete', () => {
    global.fetch = jest.fn( () => new Promise( () => {} ) );
    Object.assign( storeDef.state, {
      firstName: 'Jane',
      lastName: 'Doe',
      email: 'jane@example.com',
      password: 'secret123',
    } );

    runValidation();

    expect( storeDef.state.firstNameError ).toBe( false );
    expect( storeDef.state.lastNameError ).toBe( false );
    expect( storeDef.state.emailError ).toBe( false );
    expect( storeDef.state.passwordError ).toBe( false );
    expect( storeDef.state.formError ).toBe( '' );
    expect( storeDef.state.loading ).toBe( true );
    expect( global.fetch ).toHaveBeenCalled();
  } );

  it( 'clears a field error as the user types', () => {
    storeDef.state.firstNameError = true;
    storeDef.state.emailError = true;

    storeDef.actions.updateFirstName( { target: { value: 'J' } } );
    storeDef.actions.updateEmail( { target: { value: 'j' } } );

    expect( storeDef.state.firstNameError ).toBe( false );
    expect( storeDef.state.emailError ).toBe( false );
  } );
} );

describe( 'close guard while a gift charge is in flight', () => {
  afterEach( () => {
    storeDef.state.isSubmittingGift = false;
    document.body.style.overflow = '';
  } );

  it( 'ignores close and Escape until the submission settles', () => {
    Object.assign( storeDef.state, { isOpen: true, isSubmittingGift: true } );

    storeDef.actions.close();
    expect( storeDef.state.isOpen ).toBe( true );

    storeDef.actions.onKeydown( { key: 'Escape' } );
    expect( storeDef.state.isOpen ).toBe( true );

    storeDef.state.isSubmittingGift = false;
    storeDef.actions.close();
    expect( storeDef.state.isOpen ).toBe( false );
  } );
} );
