/* eslint-env jest */

/**
 * Tests for the tip tamper guard.
 */
import {
  isTrustedEvent,
  isTipSuppressed,
  watchTipVisibility,
} from '../tip-guard';

jest.mock( '../tip-visibility', () => ( {
  isElementShown: jest.fn(),
  forceShown: jest.fn(),
} ) );

const { isElementShown, forceShown } = require( '../tip-visibility' );

let resizeCallbacks;
let originalResizeObserver;

beforeEach( () => {
  jest.useFakeTimers();
  isElementShown.mockReset();
  forceShown.mockReset();
  document.body.innerHTML =
    '<section class="root"><div class="tip"><p class="text">x</p></div></section>';

  resizeCallbacks = [];
  originalResizeObserver = global.ResizeObserver;
  global.ResizeObserver = class {
    constructor( cb ) {
      resizeCallbacks.push( cb );
      this.disconnect = jest.fn();
    }
    observe() {}
  };
} );

afterEach( () => {
  jest.useRealTimers();
  global.ResizeObserver = originalResizeObserver;
  document.body.innerHTML = '';
} );

const root = () => document.querySelector( '.root' );
const tip = () => document.querySelector( '.tip' );

// ── isTrustedEvent ──

describe( 'isTrustedEvent', () => {
  it( 'rejects only events explicitly marked untrusted', () => {
    expect( isTrustedEvent( { isTrusted: false } ) ).toBe( false );
    expect( isTrustedEvent( { isTrusted: true } ) ).toBe( true );
    expect( isTrustedEvent( { stopPropagation() {} } ) ).toBe( true );
    expect( isTrustedEvent( undefined ) ).toBe( true );
  } );

  it( 'treats synthetic DOM events as untrusted', () => {
    expect( isTrustedEvent( new Event( 'input' ) ) ).toBe( false );
  } );
} );

// ── isTipSuppressed ──

describe( 'isTipSuppressed', () => {
  it( 'reports nothing without a root', () => {
    expect( isTipSuppressed( tip(), null ) ).toBe( false );
    expect( isElementShown ).not.toHaveBeenCalled();
  } );

  it( 'treats a missing container inside a root as suppressed', () => {
    expect( isTipSuppressed( null, root() ) ).toBe( true );
  } );

  it( 'is false when the tip is already shown', () => {
    isElementShown.mockReturnValue( true );
    expect( isTipSuppressed( tip(), root() ) ).toBe( false );
    expect( forceShown ).not.toHaveBeenCalled();
  } );

  it( 'forces once and is false when the force succeeds', () => {
    isElementShown.mockReturnValueOnce( false ).mockReturnValueOnce( true );
    expect( isTipSuppressed( tip(), root() ) ).toBe( false );
    expect( forceShown ).toHaveBeenCalledTimes( 1 );
  } );

  it( 'is true when the force fails', () => {
    isElementShown.mockReturnValue( false );
    expect( isTipSuppressed( tip(), root() ) ).toBe( true );
    expect( forceShown ).toHaveBeenCalledTimes( 1 );
  } );
} );

// ── watchTipVisibility ──

describe( 'watchTipVisibility', () => {
  it( 'does not check before the settle delay, then checks on schedule', () => {
    isElementShown.mockReturnValue( true );
    const watch = watchTipVisibility( tip(), root() );

    jest.advanceTimersByTime( 399 );
    expect( isElementShown ).not.toHaveBeenCalled();

    jest.advanceTimersByTime( 1 );
    expect( isElementShown ).toHaveBeenCalledTimes( 1 );

    jest.advanceTimersByTime( 1100 );
    expect( isElementShown ).toHaveBeenCalledTimes( 2 );

    jest.advanceTimersByTime( 1500 );
    expect( isElementShown ).toHaveBeenCalledTimes( 3 );
    expect( watch.isHidden() ).toBe( false );
    watch.stop();
  } );

  it( 'forces a hidden tip and reports the change', () => {
    const onChange = jest.fn();
    isElementShown.mockReturnValueOnce( false ).mockReturnValue( true );
    const watch = watchTipVisibility( tip(), root(), { onChange } );

    jest.advanceTimersByTime( 400 );
    expect( forceShown ).toHaveBeenCalledTimes( 1 );
    expect( watch.isHidden() ).toBe( false );
    expect( onChange ).toHaveBeenCalledWith( false );
    watch.stop();
  } );

  it( 'coalesces observer-triggered checks after settling', () => {
    isElementShown.mockReturnValue( true );
    const watch = watchTipVisibility( tip(), root() );

    // Before settling, a resize notification is ignored.
    resizeCallbacks[ 0 ]();
    jest.advanceTimersByTime( 100 );
    expect( isElementShown ).not.toHaveBeenCalled();

    jest.advanceTimersByTime( 300 );
    expect( isElementShown ).toHaveBeenCalledTimes( 1 );

    resizeCallbacks[ 0 ]();
    resizeCallbacks[ 0 ]();
    jest.advanceTimersByTime( 50 );
    expect( isElementShown ).toHaveBeenCalledTimes( 2 );
    watch.stop();
  } );

  it( 'stops forcing after the cap and stays hidden', () => {
    isElementShown.mockReturnValue( false );
    const watch = watchTipVisibility( tip(), root(), {
      settleDelay: 0,
      recheckDelays: [],
    } );

    for ( let i = 0; i < 40; i++ ) {
      watch.check();
    }
    expect( forceShown ).toHaveBeenCalledTimes( 25 );
    expect( watch.isHidden() ).toBe( true );
    watch.stop();
  } );

  it( 'stop() clears timers and disconnects observers', () => {
    isElementShown.mockReturnValue( true );
    const disconnect = jest.spyOn(
      window.MutationObserver.prototype,
      'disconnect'
    );
    const watch = watchTipVisibility( tip(), root() );
    watch.stop();

    jest.advanceTimersByTime( 5000 );
    expect( isElementShown ).not.toHaveBeenCalled();
    expect( disconnect ).toHaveBeenCalled();
    disconnect.mockRestore();
  } );
} );
