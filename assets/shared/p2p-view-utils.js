/**
 * Shared Interactivity API helpers for the peer-to-peer blocks.
 *
 * These are plain functions the block view stores reference directly in their
 * `actions`/`callbacks`, so each block keeps its own store namespace.
 */
/* global IntersectionObserver */
import { store, getElement } from '@wordpress/interactivity';

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
 * Open the shared peer-to-peer sign-up modal (registered by the signup-modal block).
 */
export function openSignup() {
  store( 'mission-donation-platform/p2p-signup' )?.actions?.open?.();
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
