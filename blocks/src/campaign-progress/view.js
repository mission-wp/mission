/**
 * Campaign Progress frontend — Interactivity API store.
 */
/* global IntersectionObserver */
import { store, getElement } from '@wordpress/interactivity';

store( 'mission-donation-platform/campaign-progress', {
  actions: {
    scrollToForm() {
      const form = document.querySelector( '.mission-donation-form' );
      if ( form ) {
        form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
      }
    },
    // Open the shared peer-to-peer sign-up modal (registered by the signup-modal block).
    openSignup() {
      store( 'mission-donation-platform/p2p-signup' )?.actions?.open?.();
    },
  },
  callbacks: {
    animateBar() {
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
    },
  },
} );
