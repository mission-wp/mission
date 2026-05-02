/**
 * Edit component for the Campaign Progress block.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
  ComboboxControl,
  PanelBody,
  SelectControl,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import apiFetch from '@wordpress/api-fetch';
import { formatAmount } from '@shared/currency';

const DONATE_BUTTON_OPTIONS = [
  {
    label: __( 'Scroll to donation form', 'mission-donation-platform' ),
    value: 'scroll',
  },
  { label: __( 'Custom URL', 'mission-donation-platform' ), value: 'url' },
  { label: __( 'Hide', 'mission-donation-platform' ), value: 'hide' },
];

/**
 * Compute days remaining from an end date string.
 *
 * @param {string|null} dateEnd ISO date string (Y-m-d) or null.
 * @return {number|null} Days remaining, or null if no end date.
 */
function getDaysRemaining( dateEnd ) {
  if ( ! dateEnd ) {
    return null;
  }
  // dateEnd is a datetime string like "2026-04-15 00:00:00" from the API.
  // Parse just the date portion to avoid timezone issues.
  const datePart = dateEnd.split( ' ' )[ 0 ];
  const [ year, month, day ] = datePart.split( '-' ).map( Number );
  const end = new Date( year, month - 1, day, 23, 59, 59 );
  const now = new Date();
  const diff = Math.ceil( ( end - now ) / ( 1000 * 60 * 60 * 24 ) );
  return Math.max( 0, diff );
}

/**
 * Format the progress display based on goal type.
 *
 * @param {Object} campaign Campaign data from REST API.
 * @return {Object} { progressText, goalText, percentage }
 */
function getProgressDisplay( campaign ) {
  const {
    goal_amount: goalAmount,
    goal_type: goalType,
    goal_progress: goalProgress,
  } = campaign;
  const hasGoal = goalAmount > 0;
  const percentage = hasGoal
    ? Math.round( ( goalProgress / goalAmount ) * 100 )
    : null;

  if ( goalType === 'amount' ) {
    const raised = formatAmount( goalProgress );
    const goalFormatted = hasGoal ? formatAmount( goalAmount ) : null;
    return {
      progressText: raised,
      goalText: goalFormatted ? `raised of ${ goalFormatted } goal` : 'raised',
      percentage,
    };
  }

  // donations or donors goal type.
  const count = goalProgress.toLocaleString();
  const label = goalType === 'donations' ? 'donations' : 'donors';
  return {
    progressText: count,
    goalText: hasGoal
      ? `${ label } of ${ goalAmount.toLocaleString() } goal`
      : label,
    percentage,
  };
}

export default function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    donateButtonAction,
    donateButtonUrl,
    showDonors,
    showDaysRemaining,
  } = attributes;

  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoadingList, setIsLoadingList ] = useState( true );
  const [ filterValue, setFilterValue ] = useState( '' );

  const { currentPostType, currentPostId } = useSelect( ( select ) => {
    const editor = select( 'core/editor' );
    return {
      currentPostType: editor?.getCurrentPostType?.() || '',
      currentPostId: editor?.getCurrentPostId?.() || 0,
    };
  }, [] );

  useEffect( () => {
    apiFetch( { path: '/mission-donation-platform/v1/campaigns?per_page=100' } )
      .then( ( response ) => {
        setCampaigns( Array.isArray( response ) ? response : [] );
      } )
      .catch( () => setCampaigns( [] ) )
      .finally( () => setIsLoadingList( false ) );
  }, [] );

  // Resolve effective campaign ID.
  let effectiveId = campaignId;
  if (
    ! effectiveId &&
    currentPostType === 'missiondp_campaign' &&
    currentPostId &&
    ! isLoadingList
  ) {
    const match = campaigns.find( ( c ) => c.post_id === currentPostId );
    effectiveId = match?.id || 0;
  }

  const campaign = effectiveId
    ? campaigns.find( ( c ) => c.id === effectiveId )
    : null;

  const campaignOptions = campaigns
    .filter( ( c ) => {
      if ( ! filterValue ) {
        return true;
      }
      return c.title.toLowerCase().includes( filterValue.toLowerCase() );
    } )
    .map( ( c ) => ( {
      value: c.id,
      label: c.title,
    } ) );

  const onCampaignChange = useCallback(
    ( val ) => {
      setAttributes( { campaignId: val ? Number( val ) : 0 } );
    },
    [ setAttributes ]
  );

  const daysRemaining = campaign ? getDaysRemaining( campaign.date_end ) : null;
  const hasEndDate = daysRemaining !== null;

  function renderPreview() {
    if ( isLoadingList ) {
      return (
        <div className="mission-campaign-progress mission-cp-skeleton">
          <div className="mission-cp-header">
            <div
              className="mission-cp-skeleton-bar"
              style={ { width: '60%', height: 28 } }
            />
            <div
              className="mission-cp-skeleton-bar"
              style={ { width: 40, height: 20 } }
            />
          </div>
          <div
            className="mission-cp-skeleton-bar"
            style={ { width: '100%', height: 12, borderRadius: 6 } }
          />
          <div className="mission-cp-stats">
            <div
              className="mission-cp-skeleton-bar"
              style={ { width: 80, height: 16 } }
            />
            <div
              className="mission-cp-skeleton-bar"
              style={ { width: 100, height: 16 } }
            />
          </div>
          <div
            className="mission-cp-skeleton-bar"
            style={ { width: '100%', height: 44, borderRadius: 8 } }
          />
        </div>
      );
    }

    if ( ! campaign ) {
      return (
        <div className="mission-campaign-progress mission-cp-empty">
          <svg
            width="28"
            height="28"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M18 20V10" />
            <path d="M12 20V4" />
            <path d="M6 20v-6" />
          </svg>
          <p>
            { __(
              'Select a campaign or place this block on a campaign page.',
              'mission-donation-platform'
            ) }
          </p>
        </div>
      );
    }

    const { progressText, goalText, percentage } =
      getProgressDisplay( campaign );
    const hasGoal = campaign.goal_amount > 0;
    const barPercent = hasGoal ? Math.min( 100, percentage ) : 0;

    return (
      <div className="mission-campaign-progress" style={ primaryColorVars }>
        <div className="mission-cp-header">
          <span className="mission-cp-raised">{ progressText }</span>
          <span className="mission-cp-goal">{ goalText }</span>
          { hasGoal && (
            <span className="mission-cp-percentage">{ percentage + '%' }</span>
          ) }
        </div>
        { hasGoal && (
          <div className="mission-cp-bar">
            <div
              className="mission-cp-bar__fill"
              style={ { '--bar-width': barPercent + '%' } }
            />
          </div>
        ) }
        { ( showDonors || ( showDaysRemaining && hasEndDate ) ) && (
          <div className="mission-cp-stats">
            { showDonors && (
              <div className="mission-cp-stat">
                <span className="mission-cp-stat__value">
                  { ( campaign.donor_count || 0 ).toLocaleString() }
                </span>
                <span className="mission-cp-stat__label">
                  { __( 'donors', 'mission-donation-platform' ) }
                </span>
              </div>
            ) }
            { showDaysRemaining && hasEndDate && (
              <div className="mission-cp-stat">
                <span className="mission-cp-stat__value">
                  { daysRemaining.toLocaleString() }
                </span>
                <span className="mission-cp-stat__label">
                  { __( 'days remaining', 'mission-donation-platform' ) }
                </span>
              </div>
            ) }
          </div>
        ) }
        { donateButtonAction !== 'hide' && (
          <div className="mission-cp-donate-btn">
            { __( 'Donate Now', 'mission-donation-platform' ) }
          </div>
        ) }
      </div>
    );
  }

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'mission-donation-platform' ) }>
          <ComboboxControl
            label={ __( 'Campaign', 'mission-donation-platform' ) }
            value={ campaignId || null }
            options={ campaignOptions }
            onChange={ onCampaignChange }
            onFilterValueChange={ setFilterValue }
            help={
              ! campaignId
                ? __(
                    'If empty, the campaign will be detected from the current post.',
                    'mission-donation-platform'
                  )
                : undefined
            }
          />
          <SelectControl
            label={ __( 'Donate button', 'mission-donation-platform' ) }
            value={ donateButtonAction }
            options={ DONATE_BUTTON_OPTIONS }
            onChange={ ( val ) => setAttributes( { donateButtonAction: val } ) }
          />
          { donateButtonAction === 'url' && (
            <TextControl
              label={ __( 'Donate URL', 'mission-donation-platform' ) }
              value={ donateButtonUrl }
              onChange={ ( val ) => setAttributes( { donateButtonUrl: val } ) }
              type="url"
              placeholder="https://..."
            />
          ) }
          <ToggleControl
            label={ __( 'Show donors', 'mission-donation-platform' ) }
            checked={ showDonors }
            onChange={ ( val ) => setAttributes( { showDonors: val } ) }
          />
          <ToggleControl
            label={ __( 'Show days remaining', 'mission-donation-platform' ) }
            checked={ showDaysRemaining }
            onChange={ ( val ) => setAttributes( { showDaysRemaining: val } ) }
            help={
              campaign && ! campaign.date_end
                ? __(
                    'This campaign has no end date, so days remaining will be hidden automatically.',
                    'mission-donation-platform'
                  )
                : undefined
            }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>{ renderPreview() }</div>
    </>
  );
}
