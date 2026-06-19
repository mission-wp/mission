/**
 * Top Fundraisers frontend — Interactivity API store.
 */
import { store } from '@wordpress/interactivity';

store( 'mission-donation-platform/top-fundraisers', {
  actions: {
    // Open the shared peer-to-peer sign-up modal (registered by the signup-modal block).
    openSignup() {
      store( 'mission-donation-platform/p2p-signup' )?.actions?.open?.();
    },
  },
} );
