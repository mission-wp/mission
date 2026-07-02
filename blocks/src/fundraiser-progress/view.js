/**
 * Fundraiser Progress frontend — Interactivity API store.
 */
/* global navigator */
import { store, getElement } from '@wordpress/interactivity';
import { animateBar, scrollToForm } from '@shared/p2p-view-utils';

store( 'mission-donation-platform/fundraiser-progress', {
  actions: {
    scrollToForm,
    share() {
      const { ref } = getElement();
      const url = ref?.getAttribute( 'data-share-url' ) || window.location.href;

      if ( navigator.share ) {
        navigator.share( { url } ).catch( () => {} );
        return;
      }

      if ( navigator.clipboard ) {
        navigator.clipboard.writeText( url ).catch( () => {} );
      }
    },
  },
  callbacks: {
    animateBar,
  },
} );
