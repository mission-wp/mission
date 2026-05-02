/**
 * Campaign Grid frontend — Interactivity API store.
 *
 * Registers the same store as the Campaign Card block for progress bar
 * animation. The Interactivity API safely merges duplicate registrations.
 */
/* global IntersectionObserver */
import { store, getElement } from '@wordpress/interactivity';

store( 'mission-donation-platform/campaign', {
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
