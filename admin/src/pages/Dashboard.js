import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { formatAmount } from '@shared/currency';
import StatCard from './dashboard/StatCard';
import DonationsChart from './dashboard/DonationsChart';
import TopCampaigns from './dashboard/TopCampaigns';
import ActivityFeed from './dashboard/ActivityFeed';
import ReviewBanner from './dashboard/ReviewBanner';
import StripeBanner from './dashboard/StripeBanner';
import PeriodToggle from './dashboard/PeriodToggle';
import OnboardingModal from '../components/OnboardingModal';

const POLL_INTERVAL = 30000;

const PERIOD_LABELS = {
  today: __( 'vs yesterday', 'missionwp-donation-platform' ),
  week: __( 'vs last week', 'missionwp-donation-platform' ),
  month: __( 'vs last month', 'missionwp-donation-platform' ),
};

const CHART_LABELS = {
  today: __( 'Today', 'missionwp-donation-platform' ),
  week: __( 'Last 7 days', 'missionwp-donation-platform' ),
  month: __( 'Last 30 days', 'missionwp-donation-platform' ),
};

export default function Dashboard() {
  const [ showOnboarding, setShowOnboarding ] = useState(
    () => ! window.missionAdmin?.onboardingCompleted
  );
  const STORAGE_KEY = 'mission_dashboard_period';
  const [ period, setPeriodState ] = useState( () => {
    try {
      return window.localStorage.getItem( STORAGE_KEY ) || 'month';
    } catch {
      return 'month';
    }
  } );
  const setPeriod = useCallback( ( value ) => {
    setPeriodState( value );
    try {
      window.localStorage.setItem( STORAGE_KEY, value );
    } catch {
      // Storage full — silently ignore.
    }
  }, [] );
  const [ data, setData ] = useState( null );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ stripeConnected, setStripeConnected ] = useState(
    window.missionAdmin?.stripeConnected ?? false
  );
  const intervalRef = useRef( null );
  const leftRef = useRef( null );
  const feedRef = useRef( null );

  const fetchData = useCallback(
    async ( showLoading = false ) => {
      if ( showLoading ) {
        setIsLoading( true );
      }

      try {
        const result = await apiFetch( {
          path: `/mission/v1/dashboard?period=${ period }`,
          headers: { 'X-WP-Nonce': window.missionAdmin.restNonce },
        } );

        setData( result );
        setStripeConnected( result.stripe_connected );
      } catch ( error ) {
        // eslint-disable-next-line no-console
        console.error( 'Dashboard fetch error:', error );
      } finally {
        setIsLoading( false );
      }
    },
    [ period ]
  );

  // Initial fetch and refetch on period change.
  useEffect( () => {
    fetchData( true );
  }, [ fetchData ] );

  // Polling with visibility-aware pause/resume.
  useEffect( () => {
    function startPolling() {
      stopPolling();
      intervalRef.current = setInterval(
        () => fetchData( false ),
        POLL_INTERVAL
      );
    }

    function stopPolling() {
      if ( intervalRef.current ) {
        clearInterval( intervalRef.current );
        intervalRef.current = null;
      }
    }

    function handleVisibility() {
      if ( document.hidden ) {
        stopPolling();
      } else {
        fetchData( false );
        startPolling();
      }
    }

    startPolling();
    document.addEventListener( 'visibilitychange', handleVisibility );

    return () => {
      stopPolling();
      document.removeEventListener( 'visibilitychange', handleVisibility );
    };
  }, [ fetchData ] );

  // Sync feed card height to left column.
  useEffect( () => {
    function syncHeight() {
      if ( ! leftRef.current || ! feedRef.current ) {
        return;
      }
      feedRef.current.style.height = '';
      if ( window.innerWidth > 1024 ) {
        feedRef.current.style.height = leftRef.current.offsetHeight + 'px';
      }
    }

    const timer = setTimeout( syncHeight, 300 );
    window.addEventListener( 'resize', syncHeight );

    return () => {
      clearTimeout( timer );
      window.removeEventListener( 'resize', syncHeight );
    };
  }, [ data, isLoading ] );

  const stats = data?.stats;
  const periodLabel = PERIOD_LABELS[ period ];

  return (
    <div className="mission-admin-page">
      <div className="mission-dashboard-header">
        <div>
          <h1>
            { __( 'MissionWP Dashboard', 'missionwp-donation-platform' ) }{ ' ' }
            <span className="mission-version-tag">
              { `v${ window.missionAdmin?.version || '' }` }
            </span>
          </h1>
          <span style={ { color: '#6b6b7b', fontSize: '14px' } }>
            { __(
              'Your donation activity at a glance',
              'missionwp-donation-platform'
            ) }
          </span>
        </div>
        <PeriodToggle period={ period } onChange={ setPeriod } />
      </div>

      { ! stripeConnected && <StripeBanner /> }

      <div className="mission-dashboard-grid">
        <div className="mission-dashboard-grid__left" ref={ leftRef }>
          { data?.review_banner?.show && (
            <ReviewBanner totalRaised={ data.review_banner.total_raised } />
          ) }
          <div className="mission-stats-row">
            <StatCard
              label={ __( 'Total Donations', 'missionwp-donation-platform' ) }
              value={ stats ? formatAmount( stats.total_donations ) : '' }
              current={ stats?.total_donations }
              previous={ stats?.total_donations_previous }
              periodLabel={ periodLabel }
              isLoading={ isLoading }
            />
            <StatCard
              label={ __( 'Total Donors', 'missionwp-donation-platform' ) }
              value={ stats ? stats.total_donors.toLocaleString() : '' }
              current={ stats?.total_donors }
              previous={ stats?.total_donors_previous }
              periodLabel={ periodLabel }
              isLoading={ isLoading }
            />
            <StatCard
              label={ __( 'Avg. Donation', 'missionwp-donation-platform' ) }
              value={ stats ? formatAmount( stats.average_donation ) : '' }
              current={ stats?.average_donation }
              previous={ stats?.average_donation_previous }
              periodLabel={ periodLabel }
              isLoading={ isLoading }
            />
          </div>
          <DonationsChart
            chartData={ data?.chart }
            isLoading={ isLoading }
            period={ period }
            periodLabel={ CHART_LABELS[ period ] }
          />
          <TopCampaigns campaigns={ data?.campaigns } isLoading={ isLoading } />
        </div>

        <ActivityFeed
          activity={ data?.activity }
          isLoading={ isLoading }
          feedRef={ feedRef }
        />
      </div>

      { showOnboarding && (
        <OnboardingModal onComplete={ () => setShowOnboarding( false ) } />
      ) }
    </div>
  );
}
