/**
 * Team Progress frontend — Interactivity API store.
 */
import { store } from '@wordpress/interactivity';
import { animateBar, openSignup, scrollToForm } from '@shared/p2p-view-utils';

store( 'mission-donation-platform/team-progress', {
  actions: {
    scrollToForm,
    openSignup,
  },
  callbacks: {
    animateBar,
  },
} );
