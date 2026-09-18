/**
 * Guards against custom code suppressing the optional tip.
 *
 * Three layers, shared by the donation form and the fundraiser sign-up
 * modal: ignore synthetic events on the tip controls, keep the tip section
 * visible while the payment step is on screen, and report a tip that could
 * not be shown so the request falls back to the flat platform fee.
 */
import { isElementShown, forceShown } from './tip-visibility';

/** Delay before the first visibility check, past the step-enter animation. */
const SETTLE_DELAY_MS = 400;

/** Later re-checks, timed to outlast page scripts that run after load. */
const RECHECK_DELAYS_MS = [ 1500, 3000 ];

/** Coalescing window for observer-triggered checks. */
const DEBOUNCE_MS = 50;

/** Give up forcing after this many attempts so a hostile loop can't spin us. */
const MAX_FORCES = 25;

/**
 * Whether an event came from real user input.
 *
 * `element.click()` and `dispatchEvent()` produce events with
 * `isTrusted === false`; pointer, keyboard and assistive-tech input is
 * trusted. A missing event (internal calls, unit tests) passes.
 *
 * @param {?Event} event Event passed to a store action.
 * @return {boolean} True when trusted or absent.
 */
export function isTrustedEvent( event ) {
  return ! event || event.isTrusted !== false;
}

/**
 * Whether the tip is suppressed at submit time.
 *
 * A missing root means the form can't be inspected, so nothing is reported.
 * A missing container inside a present root means custom code removed it.
 * Otherwise the container is forced visible once and re-tested.
 *
 * @param {?Element} el   Tip container.
 * @param {?Element} root Form root.
 * @param {Object}   opts Options forwarded to the visibility helpers.
 * @return {boolean} True when the tip is still not shown.
 */
export function isTipSuppressed( el, root, opts = {} ) {
  if ( ! root ) {
    return false;
  }
  if ( ! el ) {
    return true;
  }
  if ( isElementShown( el, root, opts ) ) {
    return false;
  }
  forceShown( el, root, opts );
  return ! isElementShown( el, root, opts );
}

/**
 * Keep the tip container visible while the payment step is on screen.
 *
 * Checks after a settle delay and at fixed later times, plus whenever the
 * container's subtree mutates or the container resizes. A hidden container
 * is forced visible up to MAX_FORCES times.
 *
 * @param {Element}  el                 Tip container.
 * @param {Element}  root               Form root.
 * @param {Object}   opts               Options.
 * @param {string[]} opts.essentials    Selectors that must also be shown.
 * @param {string}   opts.skip          Subtrees to leave alone when forcing.
 * @param {Function} opts.onChange      Called with the new hidden state on change.
 * @param {number}   opts.settleDelay   Override for the settle delay (tests).
 * @param {number[]} opts.recheckDelays Override for the re-check delays (tests).
 * @return {{ stop: Function, check: Function, isHidden: Function }} Handle.
 */
export function watchTipVisibility( el, root, opts = {} ) {
  const {
    onChange,
    settleDelay = SETTLE_DELAY_MS,
    recheckDelays = RECHECK_DELAYS_MS,
    ...shownOpts
  } = opts;

  let hidden = null;
  let forces = 0;
  let settled = false;
  let stopped = false;
  let pending = null;
  let mutationObserver = null;
  let resizeObserver = null;
  const timers = [];

  const check = () => {
    if ( stopped ) {
      return hidden === true;
    }
    let shown = isElementShown( el, root, shownOpts );
    if ( ! shown && forces < MAX_FORCES ) {
      forces++;
      forceShown( el, root, shownOpts );
      // Discard the records our own writes just queued.
      mutationObserver?.takeRecords();
      shown = isElementShown( el, root, shownOpts );
    }
    if ( ! shown !== hidden ) {
      hidden = ! shown;
      onChange?.( hidden );
    }
    return hidden;
  };

  const scheduleCheck = () => {
    if ( ! settled || stopped || pending ) {
      return;
    }
    pending = setTimeout( () => {
      pending = null;
      check();
    }, DEBOUNCE_MS );
  };

  if ( typeof window.MutationObserver !== 'undefined' ) {
    mutationObserver = new window.MutationObserver( scheduleCheck );
    mutationObserver.observe( el, {
      attributes: true,
      attributeFilter: [ 'style', 'class', 'hidden' ],
      subtree: true,
      childList: true,
    } );
  }
  if ( typeof window.ResizeObserver !== 'undefined' ) {
    resizeObserver = new window.ResizeObserver( scheduleCheck );
    resizeObserver.observe( el );
  }

  timers.push(
    setTimeout( () => {
      settled = true;
      check();
    }, settleDelay )
  );
  for ( const delay of recheckDelays ) {
    timers.push(
      setTimeout( () => {
        if ( settled ) {
          check();
        }
      }, delay )
    );
  }

  return {
    stop() {
      stopped = true;
      timers.forEach( clearTimeout );
      clearTimeout( pending );
      pending = null;
      mutationObserver?.disconnect();
      resizeObserver?.disconnect();
    },
    check,
    isHidden: () => hidden === true,
  };
}
