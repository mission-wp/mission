/* eslint-env jest */

/**
 * Tests for the tip visibility helpers.
 *
 * jsdom performs no layout, so rects and computed styles are stubbed per
 * element via data attributes read by the stubs installed in beforeEach.
 */
import {
  readDeclaredStyle,
  isElementShown,
  forceShown,
  MIN_CONTAINER_SIZE,
} from '../tip-visibility';

const ESSENTIALS = [ '.tip-text', '.tip-trigger' ];

/**
 * Build the root + tip fixture and return its parts.
 *
 * @param {string} extraTipAttrs Extra attributes for the tip container.
 * @return {{ root: Element, tip: Element }} Fixture elements.
 */
function mountFixture( extraTipAttrs = '' ) {
  document.body.innerHTML = `
    <section class="root" data-rect="0,0,600,800">
      <div class="tip" data-rect="20,300,560,80" ${ extraTipAttrs }>
        <div class="card">
          <p class="tip-text" data-rect="36,316,400,40">Tip text</p>
          <button class="tip-trigger" data-rect="460,316,80,40">15%</button>
          <div class="menu" data-wp-bind--hidden="!state.open" hidden>
            <button class="option">20%</button>
          </div>
        </div>
      </div>
    </section>`;
  return {
    root: document.querySelector( '.root' ),
    tip: document.querySelector( '.tip' ),
  };
}

/**
 * Parse a data-rect attribute into a DOMRect-like object.
 *
 * @param {Element} el Element with a data-rect attribute.
 * @return {Object} Rect with left/top/width/height/right/bottom.
 */
function rectOf( el ) {
  const raw = el.getAttribute?.( 'data-rect' );
  if ( ! raw ) {
    return { left: 0, top: 0, width: 0, height: 0, right: 0, bottom: 0 };
  }
  const [ left, top, width, height ] = raw.split( ',' ).map( Number );
  return {
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
  };
}

let computedOverrides;
let originalGetComputedStyle;
let originalCheckVisibility;

beforeEach( () => {
  computedOverrides = new Map();
  originalGetComputedStyle = window.getComputedStyle;
  originalCheckVisibility = window.Element.prototype.checkVisibility;

  jest
    .spyOn( window.Element.prototype, 'getBoundingClientRect' )
    .mockImplementation( function () {
      return rectOf( this );
    } );

  window.getComputedStyle = ( el ) => {
    const base = {
      display: 'block',
      visibility: 'visible',
      opacity: '1',
      fontSize: '14px',
      clipPath: 'none',
      clip: 'auto',
      filter: 'none',
      position: 'static',
    };
    return { ...base, ...( computedOverrides.get( el ) || {} ) };
  };

  // Default: rendered unless the stub says otherwise.
  window.Element.prototype.checkVisibility = function () {
    const override = computedOverrides.get( this ) || {};
    return (
      override.display !== 'none' &&
      override.visibility !== 'hidden' &&
      override.opacity !== '0'
    );
  };
} );

afterEach( () => {
  jest.restoreAllMocks();
  window.getComputedStyle = originalGetComputedStyle;
  if ( originalCheckVisibility ) {
    window.Element.prototype.checkVisibility = originalCheckVisibility;
  } else {
    delete window.Element.prototype.checkVisibility;
  }
  document.head.querySelectorAll( 'style' ).forEach( ( s ) => s.remove() );
  document.body.innerHTML = '';
} );

/**
 * Append a stylesheet to the document head.
 *
 * @param {string} css Stylesheet text.
 */
function addSheet( css ) {
  const style = document.createElement( 'style' );
  style.textContent = css;
  document.head.appendChild( style );
}

// ── readDeclaredStyle ──

describe( 'readDeclaredStyle', () => {
  it( 'returns the last matching declaration', () => {
    const { tip } = mountFixture();
    addSheet( '.tip { display: flex; } .tip { display: grid; }' );
    expect( readDeclaredStyle( tip, 'display' ) ).toBe( 'grid' );
  } );

  it( 'skips rejected values and non-matching selectors', () => {
    const { tip } = mountFixture();
    addSheet(
      '.tip { display: flex; } .other { display: inline; } .tip { display: none !important; }'
    );
    expect( readDeclaredStyle( tip, 'display', ( v ) => v === 'none' ) ).toBe(
      'flex'
    );
  } );

  it( 'returns null when nothing matches', () => {
    const { tip } = mountFixture();
    addSheet( '.other { display: inline; }' );
    expect( readDeclaredStyle( tip, 'display' ) ).toBeNull();
  } );

  it( 'recurses into grouping rules', () => {
    const { tip } = mountFixture();
    addSheet( '@supports (display: grid) { .tip { display: grid; } }' );
    expect( readDeclaredStyle( tip, 'display' ) ).toBe( 'grid' );
  } );

  it( 'tolerates a stylesheet whose rules cannot be read', () => {
    const { tip } = mountFixture();
    addSheet( '.tip { display: flex; }' );
    const sheets = document.styleSheets;
    const throwing = {
      get cssRules() {
        throw new Error( 'cross-origin' );
      },
    };
    jest.spyOn( document, 'styleSheets', 'get' ).mockReturnValue( {
      length: 2,
      0: throwing,
      1: sheets[ 0 ],
    } );
    expect( readDeclaredStyle( tip, 'display' ) ).toBe( 'flex' );
  } );
} );

// ── isElementShown ──

describe( 'isElementShown', () => {
  it( 'is true for a rendered, sized, contained tip with essentials', () => {
    const { root, tip } = mountFixture();
    expect( isElementShown( tip, root, { essentials: ESSENTIALS } ) ).toBe(
      true
    );
  } );

  it( 'is false for a missing or detached container', () => {
    const { root, tip } = mountFixture();
    expect( isElementShown( null, root ) ).toBe( false );
    tip.remove();
    expect( isElementShown( tip, root ) ).toBe( false );
  } );

  it( 'is false when the root has no size', () => {
    const { root, tip } = mountFixture();
    root.setAttribute( 'data-rect', '0,0,0,0' );
    expect( isElementShown( tip, root ) ).toBe( false );
  } );

  it( 'is false when checkVisibility rejects the container', () => {
    const { root, tip } = mountFixture();
    computedOverrides.set( tip, { display: 'none' } );
    expect( isElementShown( tip, root ) ).toBe( false );
  } );

  it( 'falls back to computed styles when checkVisibility is unsupported', () => {
    const { root, tip } = mountFixture();
    delete window.Element.prototype.checkVisibility;
    expect( isElementShown( tip, root ) ).toBe( true );
    computedOverrides.set( tip.parentElement, { opacity: '0' } );
    expect( isElementShown( tip, root ) ).toBe( false );
  } );

  it( 'is false when the container is clipped', () => {
    const { root, tip } = mountFixture();
    computedOverrides.set( tip, { clipPath: 'inset(100%)' } );
    expect( isElementShown( tip, root ) ).toBe( false );
  } );

  it( 'is false when the container is below the size floor', () => {
    const { root, tip } = mountFixture();
    tip.setAttribute( 'data-rect', `20,300,560,${ MIN_CONTAINER_SIZE - 1 }` );
    expect( isElementShown( tip, root ) ).toBe( false );
  } );

  it( 'is false when the container is pushed outside the root', () => {
    const { root, tip } = mountFixture();
    tip.setAttribute( 'data-rect', '-9999,300,560,80' );
    expect( isElementShown( tip, root ) ).toBe( false );
  } );

  it( 'allows a tip scrolled out of a scrollable root', () => {
    const { root, tip } = mountFixture();
    root.setAttribute( 'data-rect', '0,0,600,300' );
    Object.defineProperty( root, 'scrollHeight', { value: 1200 } );
    Object.defineProperty( root, 'scrollTop', { value: 100 } );
    tip.setAttribute( 'data-rect', '20,700,560,80' );
    expect( isElementShown( tip, root ) ).toBe( true );
  } );

  it( 'is false when an essential is missing, hidden or tiny', () => {
    const { root, tip } = mountFixture();
    const text = tip.querySelector( '.tip-text' );

    computedOverrides.set( text, { visibility: 'hidden' } );
    expect( isElementShown( tip, root, { essentials: ESSENTIALS } ) ).toBe(
      false
    );
    computedOverrides.delete( text );

    text.setAttribute( 'data-rect', '36,316,400,0' );
    expect( isElementShown( tip, root, { essentials: ESSENTIALS } ) ).toBe(
      false
    );

    text.remove();
    expect( isElementShown( tip, root, { essentials: ESSENTIALS } ) ).toBe(
      false
    );
  } );
} );

// ── forceShown ──

describe( 'forceShown', () => {
  it( 'writes !important declarations and strips hidden attributes', () => {
    const { root, tip } = mountFixture( 'hidden' );
    forceShown( tip, root );
    expect( tip.hasAttribute( 'hidden' ) ).toBe( false );
    expect( tip.style.getPropertyValue( 'display' ) ).toBe( 'block' );
    expect( tip.style.getPropertyPriority( 'display' ) ).toBe( 'important' );
    expect( tip.style.getPropertyValue( 'opacity' ) ).toBe( '1' );
    expect( tip.style.getPropertyValue( 'position' ) ).toBe( 'static' );
    const text = tip.querySelector( '.tip-text' );
    expect( text.style.getPropertyValue( 'visibility' ) ).toBe( 'visible' );
  } );

  it( 'leaves the store-controlled subtrees alone', () => {
    const { root, tip } = mountFixture();
    forceShown( tip, root );
    const menu = tip.querySelector( '.menu' );
    expect( menu.hasAttribute( 'hidden' ) ).toBe( true );
    expect( menu.style.getPropertyValue( 'opacity' ) ).toBe( '' );
    expect(
      tip.querySelector( '.option' ).style.getPropertyValue( 'opacity' )
    ).toBe( '' );
  } );

  it( 'restores a descendant display from the declared stylesheet value', () => {
    const { root, tip } = mountFixture();
    addSheet( '.card { display: flex; } .card { display: none !important; }' );
    const card = tip.querySelector( '.card' );
    computedOverrides.set( card, { display: 'none' } );
    forceShown( tip, root );
    expect( card.style.getPropertyValue( 'display' ) ).toBe( 'flex' );
    expect( card.style.getPropertyPriority( 'display' ) ).toBe( 'important' );
  } );

  it( 'restores a descendant padding that a stylesheet zeroed', () => {
    const { root, tip } = mountFixture();
    addSheet( '.card { padding: 16px; } .card { padding: 0 !important; }' );
    const card = tip.querySelector( '.card' );
    computedOverrides.set( card, {
      paddingTop: '0px',
      paddingRight: '0px',
      paddingBottom: '0px',
      paddingLeft: '0px',
    } );
    forceShown( tip, root );
    expect( card.style.getPropertyValue( 'padding' ) ).toBe( '16px' );
    expect( card.style.getPropertyPriority( 'padding' ) ).toBe( 'important' );
  } );

  it( 'leaves padding alone when nothing declares a non-zero value', () => {
    const { root, tip } = mountFixture();
    const card = tip.querySelector( '.card' );
    computedOverrides.set( card, {
      paddingTop: '0px',
      paddingRight: '0px',
      paddingBottom: '0px',
      paddingLeft: '0px',
    } );
    forceShown( tip, root );
    expect( card.style.getPropertyValue( 'padding' ) ).toBe( '' );
  } );

  it( 'falls back to revert when no declaration exists', () => {
    const { root, tip } = mountFixture();
    const card = tip.querySelector( '.card' );
    computedOverrides.set( card, { display: 'none' } );
    forceShown( tip, root );
    expect( card.style.getPropertyValue( 'display' ) ).toBe( 'revert' );
  } );

  it( 'is idempotent on a second run', () => {
    const { root, tip } = mountFixture();
    forceShown( tip, root );
    const before = tip.getAttribute( 'style' );
    const setProperty = jest.spyOn( tip.style, 'setProperty' );
    forceShown( tip, root );
    // jsdom drops properties it doesn't know (translate, zoom, …), so only
    // the ones it keeps can prove the no-op; the attribute must not change.
    expect( setProperty ).not.toHaveBeenCalledWith(
      'display',
      expect.anything(),
      expect.anything()
    );
    expect( tip.getAttribute( 'style' ) ).toBe( before );
  } );
} );
