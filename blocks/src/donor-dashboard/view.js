/**
 * Donor Dashboard — Interactivity API store.
 *
 * Assembles state, callbacks, and actions from domain-specific modules
 * and registers the unified store.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { authState, authCallbacks, authActions } from './actions/auth';
import { historyState, historyActions } from './actions/history';
import { profileState, profileActions } from './actions/profile';
import { receiptsActions } from './actions/receipts';
import { showToast } from './utils/toast';
import {
  recurringState,
  recurringActions,
  getOpenModal,
  closeAnyModal,
} from './actions/recurring';

/**
 * Merge objects while preserving getter descriptors.
 *
 * The spread operator evaluates getters into plain values, which breaks
 * the Interactivity API's reactivity (store() needs actual getter
 * descriptors to create computed signals). This helper copies property
 * descriptors directly so getters survive the merge.
 *
 * @param {...Object} sources Objects to merge.
 * @return {Object} Merged object with getters intact.
 */
function mergeState( ...sources ) {
  const result = {};
  for ( const source of sources ) {
    Object.defineProperties(
      result,
      Object.getOwnPropertyDescriptors( source )
    );
  }
  return result;
}

/**
 * Read the active panel from the URL hash.
 *
 * Validates against the set of valid panels provided via PHP context.
 * Falls back to 'overview' if the hash is not a known panel.
 *
 * @param {string[]} validPanels Array of valid panel IDs.
 * @return {string} Panel ID.
 */
function panelFromHash( validPanels ) {
  const hash = window.location.hash.replace( '#', '' );
  return validPanels.includes( hash ) ? hash : 'overview';
}

/**
 * Move focus to the active panel's heading.
 *
 * Uses requestAnimationFrame to wait for the DOM to update after
 * the panel switch, then focuses the first heading with tabindex="-1".
 */
function focusActivePanel() {
  // eslint-disable-next-line no-undef -- Browser API.
  requestAnimationFrame( () => {
    const title = document.querySelector( '.mission-dd-page-title' );
    if ( title ) {
      title.focus();
    }
  } );
}

store( 'mission-donation-platform/donor-dashboard', {
  state: mergeState( authState, historyState, recurringState, profileState, {
    // ── Toast ──
    get toastIsSuccess() {
      return getContext().toast?.type === 'success';
    },
    get toastIsError() {
      return getContext().toast?.type === 'error';
    },

    // ── Donor info (sidebar) ──
    get donorFullName() {
      const ctx = getContext();
      return (
        [ ctx.donor?.firstName, ctx.donor?.lastName ]
          .filter( Boolean )
          .join( ' ' ) || ''
      );
    },

    // ── Dashboard panels ──
    get panelTitle() {
      const ctx = getContext();
      return ctx.panelLabels?.[ ctx.activePanel ] || 'Overview';
    },
    get isOverview() {
      return getContext().activePanel === 'overview';
    },
    get isHistory() {
      return getContext().activePanel === 'history';
    },
    get isRecurring() {
      return getContext().activePanel === 'recurring';
    },
    get isReceipts() {
      return getContext().activePanel === 'receipts';
    },
    get isProfile() {
      return getContext().activePanel === 'profile';
    },
  } ),

  callbacks: {
    ...authCallbacks,

    /**
     * Initialise the dashboard — read hash, listen for navigation,
     * watch container width for sidebar drawer auto-close.
     */
    init() {
      const ctx = getContext();
      const { ref } = getElement();

      ctx.activePanel = panelFromHash( ctx.validPanels );

      // Handle email change verification link.
      const params = new URLSearchParams( window.location.search );
      if (
        params.get( 'action' ) === 'verify-email' &&
        params.get( 'token' ) &&
        params.get( 'email' )
      ) {
        ctx.activePanel = 'profile';
        window.location.hash = 'profile';

        fetch( ctx.restUrl + 'donor-dashboard/email-change/confirm', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            email: params.get( 'email' ),
            token: params.get( 'token' ),
          } ),
        } )
          .then( ( r ) => r.json() )
          .then( ( data ) => {
            if ( data.email ) {
              ctx.profile.email = data.email;
              ctx.profile.pendingEmail = '';
              ctx.donor.email = data.email;
              showToast( ctx, 'Email address updated successfully!' );
            } else {
              showToast(
                ctx,
                data.message ||
                  'This verification link is invalid or has expired.',
                'error'
              );
            }
          } )
          .catch( () => {
            showToast(
              ctx,
              'Failed to verify email. Please try again.',
              'error'
            );
          } );

        // Clean the URL.
        const cleanUrl = window.location.pathname + '#profile';
        window.history.replaceState( null, '', cleanUrl );
      }

      const onHashChange = () => {
        ctx.activePanel = panelFromHash( ctx.validPanels );
        focusActivePanel();
      };
      window.addEventListener( 'hashchange', onHashChange );

      // Auto-close sidebar drawer when container expands past mobile breakpoint.
      const wrapper = ref.querySelector( '.mission-dd-wrapper' );
      if ( wrapper ) {
        // eslint-disable-next-line no-undef -- Browser API.
        const observer = new ResizeObserver( ( entries ) => {
          const width = entries[ 0 ]?.contentBoxSize?.[ 0 ]?.inlineSize;
          if ( width > 600 && ctx.sidebarOpen ) {
            ctx.sidebarOpen = false;
          }
        } );
        observer.observe( wrapper );
      }
    },
  },

  actions: {
    // ── Auth ──
    ...authActions,

    // ── Dashboard navigation ──
    navigate( event ) {
      const ctx = getContext();
      const el = event?.target?.closest( '[data-panel]' );
      const panel = el?.dataset?.panel;

      if ( panel && ctx.validPanels?.includes( panel ) ) {
        window.location.hash = panel === 'overview' ? '' : panel;
        ctx.activePanel = panel;
        ctx.sidebarOpen = false;
        focusActivePanel();
      }
    },

    // ── Sidebar drawer (mobile) ──
    openSidebar() {
      getContext().sidebarOpen = true;
    },

    closeSidebar() {
      getContext().sidebarOpen = false;
    },

    *logout() {
      const ctx = getContext();

      try {
        yield fetch( ctx.restUrl + 'donor-auth/logout', {
          method: 'POST',
          headers: {
            'X-WP-Nonce': ctx.nonce,
            'Content-Type': 'application/json',
          },
        } );
      } catch {
        // Proceed with reload even if the request fails.
      }

      window.location.reload();
    },

    // ── Global keyboard ──
    handleGlobalKeydown( event ) {
      if ( event.key !== 'Escape' ) {
        return;
      }

      const ctx = getContext();

      // Close subscription modals first.
      if ( getOpenModal( ctx ) ) {
        closeAnyModal( ctx );
        return;
      }

      // Close mobile sidebar drawer.
      if ( ctx.sidebarOpen ) {
        ctx.sidebarOpen = false;
        const toggle = document.querySelector( '.mission-dd-mobile-toggle' );
        if ( toggle ) {
          toggle.focus();
        }
      }
    },

    // ── History ──
    ...historyActions,

    // ── Recurring ──
    ...recurringActions,

    // ── Receipts ──
    ...receiptsActions,

    // ── Profile ──
    ...profileActions,
  },
} );
