/**
 * Edit component for the Recent Donors block.
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

const DONATE_BUTTON_OPTIONS = [
  {
    label: __( 'Scroll to donation form', 'missionwp-donation-platform' ),
    value: 'scroll',
  },
  { label: __( 'Custom URL', 'missionwp-donation-platform' ), value: 'url' },
  { label: __( 'Hide', 'missionwp-donation-platform' ), value: 'hide' },
];

const SAMPLE_DONORS = [
  {
    initials: 'KW',
    name: 'Karen W.',
    amount: 5000,
    time: '2 hours ago',
    dedication: null,
  },
  {
    initials: 'RH',
    name: 'Robert H.',
    amount: 10000,
    time: '5 hours ago',
    dedication: 'Keep up the great work!',
  },
  {
    initials: 'LM',
    name: 'Lisa M.',
    amount: 2500,
    time: 'Yesterday',
    dedication: null,
  },
];

export default function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    heading,
    showAvatars,
    showDedication,
    donateButtonAction,
    donateButtonUrl,
    numberOfDonors,
  } = attributes;

  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoadingList, setIsLoadingList ] = useState( true );
  const primaryColorVars = useMemo( () => {
    const color = window.missionBlockEditor?.primaryColor || '#2fa36b';
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
    apiFetch( { path: '/mission/v1/campaigns?per_page=100' } )
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
    currentPostType === 'mission_campaign' &&
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
        <div className="mission-recent-donors mission-rd-skeleton">
          <div
            className="mission-rd-skeleton-bar"
            style={ { width: '40%', height: 14 } }
          />
          { [ 1, 2, 3 ].map( ( i ) => (
            <div key={ i } className="mission-rd-skeleton-row">
              <div
                className="mission-rd-skeleton-bar"
                style={ { width: 36, height: 36, borderRadius: '50%' } }
              />
              <div style={ { flex: 1 } }>
                <div
                  className="mission-rd-skeleton-bar"
                  style={ { width: '60%', height: 14 } }
                />
              </div>
              <div
                className="mission-rd-skeleton-bar"
                style={ { width: 60, height: 14 } }
              />
            </div>
          ) ) }
        </div>
      );
    }

    if ( ! campaign ) {
      return (
        <div className="mission-recent-donors mission-rd-empty-editor">
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
            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
          </svg>
          <p>
            { __(
              'Select a campaign or place this block on a campaign page.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
      );
    }

    return (
      <div className="mission-recent-donors" style={ primaryColorVars }>
        <RichText
          tagName="h3"
          className="mission-donor-heading"
          value={ heading }
          onChange={ ( val ) => setAttributes( { heading: val } ) }
          placeholder={ __( 'Recent Donors', 'missionwp-donation-platform' ) }
          allowedFormats={ [] }
        />
        <ul className="mission-donor-list">
          { SAMPLE_DONORS.map( ( donor, index ) => (
            <li key={ index } className="mission-donor-item">
              <div className="mission-donor-item-left">
                { showAvatars && (
                  <span className="mission-donor-avatar">
                    { donor.initials }
                  </span>
                ) }
                <div className="mission-donor-info">
                  <span className="mission-donor-name">{ donor.name }</span>
                  <span className="mission-rd-time">{ donor.time }</span>
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
        </ul>
      </div>
    );
  }

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'missionwp-donation-platform' ) }>
          <ComboboxControl
            label={ __( 'Campaign', 'missionwp-donation-platform' ) }
            value={ campaignId || null }
            options={ campaignOptions }
            onChange={ onCampaignChange }
            onFilterValueChange={ setFilterValue }
            help={
              ! campaignId
                ? __(
                    'If empty, the campaign will be detected from the current post.',
                    'missionwp-donation-platform'
                  )
                : undefined
            }
          />
          <RangeControl
            label={ __( 'Number of donors', 'missionwp-donation-platform' ) }
            value={ numberOfDonors }
            onChange={ ( val ) => setAttributes( { numberOfDonors: val } ) }
            min={ 1 }
            max={ 25 }
            step={ 1 }
          />
          <SelectControl
            label={ __(
              'Donate button (empty state)',
              'missionwp-donation-platform'
            ) }
            value={ donateButtonAction }
            options={ DONATE_BUTTON_OPTIONS }
            onChange={ ( val ) => setAttributes( { donateButtonAction: val } ) }
          />
          { donateButtonAction === 'url' && (
            <TextControl
              label={ __( 'Donate URL', 'missionwp-donation-platform' ) }
              value={ donateButtonUrl }
              onChange={ ( val ) => setAttributes( { donateButtonUrl: val } ) }
              type="url"
              placeholder="https://..."
            />
          ) }
          <ToggleControl
            label={ __( 'Show avatars', 'missionwp-donation-platform' ) }
            checked={ showAvatars }
            onChange={ ( val ) => setAttributes( { showAvatars: val } ) }
          />
          <ToggleControl
            label={ __( 'Show dedications', 'missionwp-donation-platform' ) }
            checked={ showDedication }
            onChange={ ( val ) => setAttributes( { showDedication: val } ) }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>{ renderPreview() }</div>
    </>
  );
}
