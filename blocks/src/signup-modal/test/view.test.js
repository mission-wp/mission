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
