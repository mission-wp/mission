/**
 * Edit component for the Top Donors block.
 */
import {
  useBlockProps,
  InspectorControls,
  RichText,
} from '@wordpress/block-editor';
import {
  ComboboxControl,
  PanelBody,
  RangeControl,
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

const MEDAL_SVGS = [
  <svg key="gold" width="20" height="20" viewBox="0 0 24 24" fill="none">
    <circle
      cx="12"
      cy="9"
      r="7"
      fill="#D4A843"
      stroke="#C4962F"
      strokeWidth="1"
    />
    <circle
      cx="12"
      cy="9"
      r="5"
      fill="none"
      stroke="#E8C96A"
      strokeWidth="0.75"
      opacity="0.6"
    />
    <text
      x="12"
      y="12.5"
      textAnchor="middle"
      fontSize="8"
      fontWeight="700"
      fill="#7A5C1F"
    >
      1
    </text>
    <path
      d="M7.5 15L6 22l6-3 6 3-1.5-7"
      fill="#D4A843"
      stroke="#C4962F"
      strokeWidth="0.75"
      strokeLinejoin="round"
    />
  </svg>,
  <svg key="silver" width="20" height="20" viewBox="0 0 24 24" fill="none">
    <circle
      cx="12"
      cy="9"
      r="7"
      fill="#B0B4BC"
      stroke="#9CA0A8"
      strokeWidth="1"
    />
    <circle
      cx="12"
      cy="9"
      r="5"
      fill="none"
      stroke="#D0D4DC"
      strokeWidth="0.75"
      opacity="0.6"
    />
    <text
      x="12"
      y="12.5"
      textAnchor="middle"
      fontSize="8"
      fontWeight="700"
      fill="#5C5F66"
    >
      2
    </text>
    <path
      d="M7.5 15L6 22l6-3 6 3-1.5-7"
      fill="#B0B4BC"
      stroke="#9CA0A8"
      strokeWidth="0.75"
      strokeLinejoin="round"
    />
  </svg>,
  <svg key="bronze" width="20" height="20" viewBox="0 0 24 24" fill="none">
    <circle
      cx="12"
      cy="9"
      r="7"
      fill="#C68E5B"
      stroke="#B07A48"
      strokeWidth="1"
    />
    <circle
      cx="12"
      cy="9"
      r="5"
      fill="none"
      stroke="#DAA872"
      strokeWidth="0.75"
      opacity="0.6"
    />
    <text
      x="12"
      y="12.5"
      textAnchor="middle"
      fontSize="8"
      fontWeight="700"
      fill="#6B4420"
    >
      3
    </text>
    <path
      d="M7.5 15L6 22l6-3 6 3-1.5-7"
      fill="#C68E5B"
      stroke="#B07A48"
      strokeWidth="0.75"
      strokeLinejoin="round"
    />
  </svg>,
];

const DONATE_BUTTON_OPTIONS = [
  {
    label: __( 'Scroll to donation form', 'mission-donation-platform' ),
    value: 'scroll',
  },
  { label: __( 'Custom URL', 'mission-donation-platform' ), value: 'url' },
  { label: __( 'Hide', 'mission-donation-platform' ), value: 'hide' },
];

const SAMPLE_DONORS = [
  {
    initials: 'MR',
    name: 'Margaret R.',
    amount: 250000,
    dedication: 'In honor of Mom',
  },
  { initials: 'JD', name: 'James D.', amount: 150000, dedication: null },
  {
    initials: 'SK',
    name: 'Sarah K.',
    amount: 100000,
    dedication: 'In memory of Dad',
  },
];

export default function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    heading,
    showAvatars,
    showDedication,
    showRibbons,
    donateButtonAction,
    donateButtonUrl,
    numberOfDonors,
  } = attributes;

  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoadingList, setIsLoadingList ] = useState( true );
  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

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

  function renderPreview() {
    if ( isLoadingList ) {
      return (
        <div className="mission-top-donors mission-td-skeleton">
          <div
            className="mission-td-skeleton-bar"
            style={ { width: '40%', height: 14 } }
          />
          { [ 1, 2, 3 ].map( ( i ) => (
            <div key={ i } className="mission-td-skeleton-row">
              <div
                className="mission-td-skeleton-bar"
                style={ { width: 36, height: 36, borderRadius: '50%' } }
              />
              <div style={ { flex: 1 } }>
                <div
                  className="mission-td-skeleton-bar"
                  style={ { width: '60%', height: 14 } }
                />
              </div>
              <div
                className="mission-td-skeleton-bar"
                style={ { width: 60, height: 14 } }
              />
            </div>
          ) ) }
        </div>
      );
    }

    if ( ! campaign ) {
      return (
        <div className="mission-top-donors mission-td-empty-editor">
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
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
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

    return (
      <div className="mission-top-donors" style={ primaryColorVars }>
        <RichText
          tagName="h3"
          className="mission-donor-heading"
          value={ heading }
          onChange={ ( val ) => setAttributes( { heading: val } ) }
          placeholder={ __( 'Top Donors', 'mission-donation-platform' ) }
          allowedFormats={ [] }
        />
        <ol className="mission-donor-list">
          { SAMPLE_DONORS.map( ( donor, index ) => (
            <li key={ index } className="mission-donor-item">
              <div className="mission-donor-item-left">
                { showRibbons && index < 3 ? (
                  <span className="mission-td-medal">
                    { MEDAL_SVGS[ index ] }
                  </span>
                ) : (
                  <span className="mission-td-rank">{ index + 1 }.</span>
                ) }
                { showAvatars && (
                  <span className="mission-donor-avatar">
                    { donor.initials }
                  </span>
                ) }
                <div className="mission-donor-info">
                  <span className="mission-donor-name">{ donor.name }</span>
                  { showDedication && donor.dedication && (
                    <span className="mission-donor-dedication">
                      { donor.dedication }
                    </span>
                  ) }
                </div>
              </div>
              <span className="mission-donor-amount">
                { formatAmount( donor.amount ) }
              </span>
            </li>
          ) ) }
        </ol>
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
          <RangeControl
            label={ __( 'Number of donors', 'mission-donation-platform' ) }
            value={ numberOfDonors }
            onChange={ ( val ) => setAttributes( { numberOfDonors: val } ) }
            min={ 1 }
            max={ 25 }
            step={ 1 }
          />
          <SelectControl
            label={ __(
              'Donate button (empty state)',
              'mission-donation-platform'
            ) }
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
            label={ __( 'Show avatars', 'mission-donation-platform' ) }
            checked={ showAvatars }
            onChange={ ( val ) => setAttributes( { showAvatars: val } ) }
          />
          <ToggleControl
            label={ __( 'Show dedications', 'mission-donation-platform' ) }
            checked={ showDedication }
            onChange={ ( val ) => setAttributes( { showDedication: val } ) }
          />
          <ToggleControl
            label={ __( 'Show ribbons', 'mission-donation-platform' ) }
            checked={ showRibbons }
            onChange={ ( val ) => setAttributes( { showRibbons: val } ) }
            help={ __(
              'Gold, silver, and bronze badges for the top 3 donors.',
              'mission-donation-platform'
            ) }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>{ renderPreview() }</div>
    </>
  );
}
