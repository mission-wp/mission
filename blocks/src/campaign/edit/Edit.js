/**
 * Edit component for the Campaign Card block.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
  ComboboxControl,
  PanelBody,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
  getDaysRemaining,
  getTag,
  getProgressDisplay,
  getTimeText,
} from '@shared/campaign-card-helpers';

export default function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    showImage,
    showTag,
    showDescription,
    showProgressBar,
    showDonorCount,
    buttonText,
  } = attributes;

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

  const daysRemaining = campaign ? getDaysRemaining( campaign.date_end ) : null;

  function renderPreview() {
    if ( isLoadingList ) {
      return (
        <div className="mission-cc mission-cc-skeleton">
          <div
            className="mission-cc-skeleton-bar"
            style={ { width: '100%', height: 220 } }
          />
          <div style={ { padding: '20px 20px 24px' } }>
            <div
              className="mission-cc-skeleton-bar"
              style={ { width: '70%', height: 20, marginBottom: 8 } }
            />
            <div
              className="mission-cc-skeleton-bar"
              style={ { width: '100%', height: 14, marginBottom: 4 } }
            />
            <div
              className="mission-cc-skeleton-bar"
              style={ { width: '80%', height: 14, marginBottom: 20 } }
            />
            <div
              className="mission-cc-skeleton-bar"
              style={ {
                width: '100%',
                height: 8,
                borderRadius: 4,
                marginBottom: 16,
              } }
            />
            <div
              className="mission-cc-skeleton-bar"
              style={ { width: '100%', height: 42, borderRadius: 4 } }
            />
          </div>
        </div>
      );
    }

    if ( ! campaign ) {
      return (
        <div className="mission-cc mission-cc-empty">
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
            <path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" />
            <path d="m15 5 4 4" />
          </svg>
          <p>
            { __(
              'Select a campaign or place this block on a campaign page.',
              'mission'
            ) }
          </p>
        </div>
      );
    }

    const imageUrl =
      campaign.image_urls?.large ||
      campaign.image_urls?.medium ||
      campaign.image_urls?.full;
    const tag = showTag ? getTag( campaign, daysRemaining ) : null;
    const hasGoal = campaign.goal_amount > 0;
    const { raisedText, goalText, percentage } = getProgressDisplay( campaign );
    const barPercent = hasGoal ? Math.min( 100, percentage ) : 0;
    const timeText = getTimeText( campaign, daysRemaining );
    const isEnded = campaign.status === 'ended';
    const cardClasses = `mission-cc${ isEnded ? ' mission-cc--ended' : '' }`;

    return (
      <div className={ cardClasses }>
        { showImage && (
          <span className="mission-cc-image-wrap">
            { tag && (
              <span className={ `mission-cc-tag ${ tag.className }` }>
                { tag.text }
              </span>
            ) }
            { imageUrl ? (
              <img src={ imageUrl } alt={ campaign.title } />
            ) : (
              <span className="mission-cc-placeholder" />
            ) }
          </span>
        ) }
        <div className="mission-cc-body">
          <h3 className="mission-cc-title">{ campaign.title }</h3>
          { showDescription && (
            <p className="mission-cc-description">{ campaign.description }</p>
          ) }
          { showProgressBar && hasGoal && (
            <div className="mission-cc-progress">
              <div className="mission-cc-progress-header">
                <span className="mission-cc-raised">{ raisedText }</span>
                { goalText && (
                  <span className="mission-cc-goal">{ goalText }</span>
                ) }
              </div>
              <div className="mission-cc-bar">
                <div
                  className="mission-cc-bar__fill"
                  style={ { '--bar-width': barPercent + '%' } }
                />
              </div>
              <div className="mission-cc-meta">
                { showDonorCount && (
                  <span className="mission-cc-donors">
                    <strong>
                      { ( campaign.donor_count || 0 ).toLocaleString() }
                    </strong>{ ' ' }
                    { __( 'donors', 'mission' ) }
                  </span>
                ) }
                <span className="mission-cc-time">{ timeText }</span>
              </div>
            </div>
          ) }
        </div>
        <div className="mission-cc-footer">
          <span className="mission-cc-btn">
            { buttonText || __( 'View Campaign', 'mission' ) }
          </span>
        </div>
      </div>
    );
  }

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'mission' ) }>
          <ComboboxControl
            label={ __( 'Campaign', 'mission' ) }
            value={ campaignId || null }
            options={ campaignOptions }
            onChange={ onCampaignChange }
            onFilterValueChange={ setFilterValue }
            help={
              ! campaignId
                ? __(
                    'If empty, the campaign will be detected from the current post.',
                    'mission'
                  )
                : undefined
            }
          />
          <ToggleControl
            label={ __( 'Show image', 'mission' ) }
            checked={ showImage }
            onChange={ ( val ) => setAttributes( { showImage: val } ) }
          />
          { showImage && (
            <ToggleControl
              label={ __( 'Show status tag', 'mission' ) }
              checked={ showTag }
              onChange={ ( val ) => setAttributes( { showTag: val } ) }
            />
          ) }
          <ToggleControl
            label={ __( 'Show description', 'mission' ) }
            checked={ showDescription }
            onChange={ ( val ) => setAttributes( { showDescription: val } ) }
          />
          <ToggleControl
            label={ __( 'Show progress bar', 'mission' ) }
            checked={ showProgressBar }
            onChange={ ( val ) => setAttributes( { showProgressBar: val } ) }
          />
          { showProgressBar && (
            <ToggleControl
              label={ __( 'Show donor count', 'mission' ) }
              checked={ showDonorCount }
              onChange={ ( val ) => setAttributes( { showDonorCount: val } ) }
            />
          ) }
          <TextControl
            label={ __( 'Button text', 'mission' ) }
            value={ buttonText }
            onChange={ ( val ) => setAttributes( { buttonText: val } ) }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>{ renderPreview() }</div>
    </>
  );
}
