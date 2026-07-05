/**
 * Fundraiser Progress frontend — Interactivity API store.
 */
import { store } from '@wordpress/interactivity';
import { animateBar, scrollToForm } from '@shared/p2p-view-utils';

store( 'mission-donation-platform/fundraiser-progress', {
  actions: {
    scrollToForm,
  },
  callbacks: {
    animateBar,
  },
} );
