/**
 * Edit component for the Top Fundraisers block.
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
import { MEDAL_SVGS } from '@shared/components/medals';

const CTA_OPTIONS = [
  {
    label: __( 'Open the sign-up modal', 'mission-donation-platform' ),
    value: 'signup',
  },
  { label: __( 'Custom URL', 'mission-donation-platform' ), value: 'url' },
  { label: __( 'Hide', 'mission-donation-platform' ), value: 'hide' },
];

const SAMPLE_FUNDRAISERS = [
  { initials: 'AR', name: 'Alex Rivera', amount: 320000, team: 'Team Sunrise' },
  { initials: 'JC', name: 'Jamie Chen', amount: 185000, team: '' },
  { initials: 'PK', name: 'Priya Kapoor', amount: 96000, team: 'Trailblazers' },
];

export default function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    heading,
    showAvatars,
    showRibbons,
    showTeam,
    ctaAction,
    ctaUrl,
    numberOfFundraisers,
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
        <div className="mission-top-fundraisers mission-tf-skeleton">
          <div
            className="mission-tf-skeleton-bar"
            style={ { width: '40%', height: 14 } }
          />
          { [ 1, 2, 3 ].map( ( i ) => (
            <div key={ i } className="mission-tf-skeleton-row">
              <div
                className="mission-tf-skeleton-bar"
                style={ { width: 36, height: 36, borderRadius: '50%' } }
              />
              <div style={ { flex: 1 } }>
                <div
                  className="mission-tf-skeleton-bar"
                  style={ { width: '60%', height: 14 } }
                />
              </div>
              <div
                className="mission-tf-skeleton-bar"
                style={ { width: 60, height: 14 } }
              />
            </div>
          ) ) }
        </div>
      );
    }

    if ( ! campaign ) {
      return (
        <div className="mission-top-fundraisers mission-tf-empty-editor">
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
            <circle cx="12" cy="8" r="6" />
            <path d="M8.5 13.5 7 22l5-3 5 3-1.5-8.5" />
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
      <div className="mission-top-fundraisers" style={ primaryColorVars }>
        <RichText
          tagName="h3"
          className="mission-donor-heading"
          value={ heading }
          onChange={ ( val ) => setAttributes( { heading: val } ) }
          placeholder={ __( 'Top Fundraisers', 'mission-donation-platform' ) }
          allowedFormats={ [] }
        />
        <ol className="mission-donor-list">
          { SAMPLE_FUNDRAISERS.map( ( fundraiser, index ) => (
            <li key={ index } className="mission-donor-item">
              <div className="mission-donor-item-left">
                { showRibbons && index < 3 ? (
                  <span className="mission-tf-medal">
                    { MEDAL_SVGS[ index ] }
                  </span>
                ) : (
                  <span className="mission-tf-rank">{ index + 1 }.</span>
                ) }
                { showAvatars && (
                  <span className="mission-donor-avatar">
                    { fundraiser.initials }
                  </span>
                ) }
                <div className="mission-donor-info">
                  <span className="mission-donor-name">
                    { fundraiser.name }
                  </span>
                  { showTeam && (
                    <span className="mission-donor-dedication">
                      { fundraiser.team ||
                        __(
                          'Individual fundraiser',
                          'mission-donation-platform'
                        ) }
                    </span>
                  ) }
                </div>
              </div>
              <span className="mission-donor-amount">
                { formatAmount( fundraiser.amount ) }
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
            label={ __( 'Number of fundraisers', 'mission-donation-platform' ) }
            value={ numberOfFundraisers }
            onChange={ ( val ) =>
              setAttributes( { numberOfFundraisers: val } )
            }
            min={ 1 }
            max={ 25 }
            step={ 1 }
          />
          <SelectControl
            label={ __( 'Button (empty state)', 'mission-donation-platform' ) }
            value={ ctaAction }
            options={ CTA_OPTIONS }
            onChange={ ( val ) => setAttributes( { ctaAction: val } ) }
          />
          { ctaAction === 'url' && (
            <TextControl
              label={ __( 'Button URL', 'mission-donation-platform' ) }
              value={ ctaUrl }
              onChange={ ( val ) => setAttributes( { ctaUrl: val } ) }
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
            label={ __( 'Show team', 'mission-donation-platform' ) }
            checked={ showTeam }
            onChange={ ( val ) => setAttributes( { showTeam: val } ) }
          />
          <ToggleControl
            label={ __( 'Show ribbons', 'mission-donation-platform' ) }
            checked={ showRibbons }
            onChange={ ( val ) => setAttributes( { showRibbons: val } ) }
            help={ __(
              'Gold, silver, and bronze badges for the top 3 fundraisers.',
              'mission-donation-platform'
            ) }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>{ renderPreview() }</div>
    </>
  );
}
