/**
 * Shared Interactivity API helpers for the peer-to-peer blocks.
 *
 * These are plain functions the block view stores reference directly in their
 * `actions`/`callbacks`, so each block keeps its own store namespace.
 */
/* global IntersectionObserver */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Scroll smoothly to the donation form on the current page.
 */
export function scrollToForm() {
  const form = document.querySelector( '.mission-donation-form' );
  if ( form ) {
    form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
  }
}

/**
 * Open the shared peer-to-peer sign-up modal, bound to the campaign payload
 * the calling block embedded in its own context under the `signup` key.
 *
 * The modal shell is rendered (once per page) by SignupModal::render() from
 * whichever block shows a sign-up CTA, so the store is registered whenever a
 * CTA exists; the optional chaining is a belt-and-braces no-op guard.
 */
export function openSignup() {
  const { signup } = getContext();
  store( 'mission-donation-platform/p2p-signup' )?.actions?.open?.( signup );
}

/**
 * `data-wp-init` callback that reveals the progress bar fill once the bar
 * scrolls into view.
 */
export function animateBar() {
  const { ref } = getElement();
  if ( ! ref ) {
    return;
  }

  const observer = new IntersectionObserver(
    ( entries ) => {
      for ( const entry of entries ) {
        if ( entry.isIntersecting ) {
          ref.classList.add( 'is-visible' );
          observer.disconnect();
        }
      }
    },
    { threshold: 0.2 }
  );

  observer.observe( ref );
}
