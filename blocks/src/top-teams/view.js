/**
 * Top Teams frontend — Interactivity API store.
 */
import { store } from '@wordpress/interactivity';
import { openSignup } from '@shared/p2p-view-utils';

store( 'mission-donation-platform/top-teams', {
  actions: {
    openSignup,
  },
} );
