/**
 * Team Progress frontend — Interactivity API store.
 */
/* global IntersectionObserver */
import { store, getElement } from '@wordpress/interactivity';

store( 'mission-donation-platform/team-progress', {
  actions: {
    scrollToForm() {
      const form = document.querySelector( '.mission-donation-form' );
      if ( form ) {
        form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
      }
    },
    // Stub: the team sign-up modal wires this action in a later step.
    openSignup() {},
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
