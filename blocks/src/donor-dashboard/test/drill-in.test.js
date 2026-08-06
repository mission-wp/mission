/* eslint-env jest */

/**
 * Tests for the donor-dashboard drill-in actions.
 *
 * The hash is the single driver of panel state: openFundraiser/openTeam set
 * the hash and close the sidebar, and the always-registered hashchange
 * listener performs the detail sync exactly once. Regression: the actions
 * used to also sync detail themselves, so every drill-in ran the sync twice
 * and issued two concurrent supporters fetches.
 */

const interactivity = require( '@wordpress/interactivity' );
const {
  fundraisersActions,
  syncFundraiserDetail,
} = require( '../actions/fundraisers' );
const { teamActions, syncTeamDetail } = require( '../actions/team' );

/**
 * Context for a drill-in from the My Fundraisers list.
 *
 * @return {Object} Mock interactivity context.
 */
function fundraiserContext() {
  const card = {
    id: 7,
    headline: 'Ride for the Rec Center',
    goalMajor: 500,
    story: 'Story',
    tributeType: '',
    tributeName: '',
    // An ended page: supporters are not embedded, so the sync fetches page 1.
    supporters: [],
    supportersTotal: 3,
  };

  return {
    card,
    restUrl: 'https://example.test/wp-json/mission-donation-platform/v1/',
    nonce: 'test-nonce',
    activePanel: 'fundraisers',
    sidebarOpen: true,
    fundraisers: {
      active: [],
      ended: [ card ],
      edit: {},
      uploadError: '',
      photoRemoved: false,
      photoPreviewUrl: '',
      supporters: {
        items: [],
        page: 1,
        perPage: 5,
        total: 0,
        totalPages: 0,
        loading: false,
      },
    },
  };
}

/**
 * Context for a drill-in from the My Teams list.
 *
 * @return {Object} Mock interactivity context.
 */
function teamContext() {
  const card = {
    id: 9,
    name: 'Trail Blazers',
    goalMajor: 1000,
    access: 'open',
    description: '',
  };

  return {
    card,
    activePanel: 'teams',
    sidebarOpen: true,
    teams: {
      current: [ card ],
      edit: {},
      invite: {},
      uploadError: '',
      photoRemoved: false,
      photoPreviewUrl: '',
    },
  };
}

describe( 'donor dashboard drill-in', () => {
  beforeEach( () => {
    global.fetch = jest.fn( () => new Promise( () => {} ) );
    window.location.hash = '';
  } );

  afterEach( () => {
    delete global.fetch;
  } );

  describe( 'openFundraiser', () => {
    it( 'sets the hash and closes the sidebar', () => {
      interactivity._mockContext = fundraiserContext();

      fundraisersActions.openFundraiser();

      expect( window.location.hash ).toBe( '#fundraiser-7' );
      expect( interactivity._mockContext.sidebarOpen ).toBe( false );
    } );

    it( 'leaves the detail sync to the hashchange listener', () => {
      const ctx = fundraiserContext();
      interactivity._mockContext = ctx;

      // The click action itself must not sync detail or fetch supporters …
      fundraisersActions.openFundraiser();
      expect( global.fetch ).not.toHaveBeenCalled();
      expect( ctx.fundraisers.detail ).toBeUndefined();

      // … so the hashchange listener's sync issues the only supporters fetch.
      syncFundraiserDetail( ctx, 7 );
      expect( ctx.fundraisers.detail ).toBe( ctx.card );
      expect( global.fetch ).toHaveBeenCalledTimes( 1 );
    } );
  } );

  describe( 'openTeam', () => {
    it( 'sets the hash and closes the sidebar, leaving detail sync to the hashchange listener', () => {
      const ctx = teamContext();
      interactivity._mockContext = ctx;

      teamActions.openTeam();

      expect( window.location.hash ).toBe( '#team-9' );
      expect( ctx.sidebarOpen ).toBe( false );
      expect( ctx.teams.detail ).toBeUndefined();

      syncTeamDetail( ctx, 9 );
      expect( ctx.teams.detail ).toBe( ctx.card );
    } );
  } );
} );
