/**
 * Fundraiser sign-up modal — Interactivity API store.
 *
 * Visual shell only: step navigation, team-mode toggle, tribute reveal, and
 * share. The account/fundraiser/team submission is wired in the registration
 * phase; `submit` currently just advances to the success step.
 *
 * Open state lives in the store's global state (not element context) so the
 * "Become a Fundraiser" / "Join this Team" buttons in other blocks can open it.
 */
/* global navigator */
import { store } from '@wordpress/interactivity';

const { state } = store( 'mission-donation-platform/p2p-signup', {
  state: {
    isOpen: false,
    currentStep: 1,
    teamMode: 'join',
    tributeChecked: false,
    get isStep1() {
      return state.currentStep === 1;
    },
    get isStep2() {
      return state.currentStep === 2;
    },
    get isStep3() {
      return state.currentStep === 3;
    },
    get isJoinMode() {
      return state.teamMode === 'join';
    },
    get isCreateMode() {
      return state.teamMode === 'create';
    },
  },
  actions: {
    open() {
      state.isOpen = true;
      state.currentStep = 1;
      document.body.style.overflow = 'hidden';
    },
    close() {
      state.isOpen = false;
      document.body.style.overflow = '';
    },
    onOverlayClick( event ) {
      // Close only when the backdrop itself (not the dialog) is clicked.
      if ( event.target === event.currentTarget ) {
        state.isOpen = false;
        document.body.style.overflow = '';
      }
    },
    onKeydown( event ) {
      if ( event.key === 'Escape' ) {
        state.isOpen = false;
        document.body.style.overflow = '';
      }
    },
    next() {
      state.currentStep = Math.min( 3, state.currentStep + 1 );
    },
    back() {
      state.currentStep = Math.max( 1, state.currentStep - 1 );
    },
    setJoinMode() {
      state.teamMode = 'join';
    },
    setCreateMode() {
      state.teamMode = 'create';
    },
    toggleTribute( event ) {
      state.tributeChecked = !! event.target.checked;
    },
    // Stub: the registration phase creates the account/fundraiser/team here.
    submit() {
      state.currentStep = 3;
    },
    share() {
      const url = window.location.href;
      if ( navigator.share ) {
        navigator.share( { url } ).catch( () => {} );
        return;
      }
      if ( navigator.clipboard ) {
        navigator.clipboard.writeText( url ).catch( () => {} );
      }
    },
  },
} );
