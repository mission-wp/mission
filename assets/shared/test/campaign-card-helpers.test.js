/* eslint-env jest */

import {
  getDaysRemaining,
  getTag,
  getProgressDisplay,
  getTimeText,
} from '../campaign-card-helpers';

describe( 'campaign card helpers', () => {
  beforeEach( () => {
    window.missiondpAdmin = { currency: 'USD' };
    jest.useFakeTimers();
    jest.setSystemTime( new Date( 2026, 5, 12, 12, 0, 0 ) ); // June 12, 2026.
  } );

  afterEach( () => {
    jest.useRealTimers();
    delete window.missiondpAdmin;
  } );

  describe( 'getDaysRemaining', () => {
    it( 'returns null without an end date', () => {
      expect( getDaysRemaining( null ) ).toBeNull();
      expect( getDaysRemaining( '' ) ).toBeNull();
    } );

    it( 'counts days until the end of the end date', () => {
      // From June 12 noon, June 22 ends 10.5 days away; ceil gives 11.
      expect( getDaysRemaining( '2026-06-22' ) ).toBe( 11 );
      expect( getDaysRemaining( '2026-06-12' ) ).toBe( 1 );
    } );

    it( 'clamps past dates to zero', () => {
      expect( getDaysRemaining( '2026-06-01' ) ).toBe( 0 );
    } );

    it( 'ignores the time portion of datetime strings', () => {
      expect( getDaysRemaining( '2026-06-22 08:30:00' ) ).toBe( 11 );
    } );
  } );

  describe( 'getTag', () => {
    it( 'tags ended campaigns', () => {
      expect( getTag( { status: 'ended', goal_amount: 0 }, null ) ).toEqual( {
        text: 'Ended',
        className: 'mission-cc-tag--ended',
      } );
      // Zero days remaining also counts as ended.
      expect(
        getTag( { status: 'active', goal_amount: 0 }, 0 ).className
      ).toBe( 'mission-cc-tag--ended' );
    } );

    it( 'tags reached goals', () => {
      const tag = getTag(
        { status: 'active', goal_amount: 1000, goal_progress: 1000 },
        null
      );
      expect( tag.className ).toBe( 'mission-cc-tag--goal-reached' );
    } );

    it( 'tags campaigns ending within 30 days, with singular day text', () => {
      const campaign = { status: 'active', goal_amount: 0 };
      expect( getTag( campaign, 30 ).text ).toBe( '30 Days Left' );
      expect( getTag( campaign, 1 ).text ).toBe( '1 Day Left' );
      expect( getTag( campaign, 31 ) ).toBeNull();
    } );

    it( 'returns null for ongoing campaigns without goals met', () => {
      expect(
        getTag(
          { status: 'active', goal_amount: 1000, goal_progress: 50 },
          null
        )
      ).toBeNull();
    } );
  } );

  describe( 'getProgressDisplay', () => {
    it( 'formats amount goals as currency with a percentage', () => {
      const display = getProgressDisplay( {
        goal_amount: 10000,
        goal_type: 'amount',
        goal_progress: 2500,
      } );
      expect( display ).toEqual( {
        raisedText: '$25.00',
        goalText: 'of $100.00',
        percentage: 25,
      } );
    } );

    it( 'formats donor-count goals as plain numbers', () => {
      const display = getProgressDisplay( {
        goal_amount: 2000,
        goal_type: 'donors',
        goal_progress: 1500,
      } );
      expect( display ).toEqual( {
        raisedText: '1,500',
        goalText: 'of 2,000',
        percentage: 75,
      } );
    } );

    it( 'omits the percentage and goal text without a goal', () => {
      const display = getProgressDisplay( {
        goal_amount: 0,
        goal_type: 'amount',
        goal_progress: 2500,
      } );
      expect( display.percentage ).toBeNull();
      expect( display.goalText ).toBe( '' );
    } );
  } );

  describe( 'getTimeText', () => {
    it( 'shows Ongoing without an end date', () => {
      expect( getTimeText( { status: 'active', date_end: null }, null ) ).toBe(
        'Ongoing'
      );
    } );

    it( 'shows Ends for future end dates and Ended for finished ones', () => {
      expect(
        getTimeText( { status: 'active', date_end: '2026-06-22' }, 10 )
      ).toMatch( /^Ends / );
      expect(
        getTimeText( { status: 'ended', date_end: '2026-06-01' }, 0 )
      ).toMatch( /^Ended / );
    } );
  } );
} );
