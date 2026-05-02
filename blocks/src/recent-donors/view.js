/**
 * Recent Donors frontend — Interactivity API store.
 */
import { store } from '@wordpress/interactivity';

store( 'mission-donation-platform/recent-donors', {
  actions: {
    scrollToForm() {
      const form = document.querySelector( '.mission-donation-form' );
      if ( form ) {
        form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
      }
    },
  },
} );
