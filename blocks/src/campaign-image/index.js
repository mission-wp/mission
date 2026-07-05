/**
 * Campaign Image block.
 *
 * Displays the image associated with a campaign, with controls
 * modeled after the core Image block.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
  ComboboxControl,
  PanelBody,
  SelectControl,
  TextareaControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalUnitControl as UnitControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalToggleGroupControl as ToggleGroupControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalToggleGroupControlOption as ToggleGroupControlOption,
  Placeholder,
  Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
  computeImageStyles,
  isWideAligned,
  showScaleControl,
} from '@shared/image-styles';
import {
  ASPECT_RATIO_OPTIONS,
  RESOLUTION_OPTIONS,
} from '@shared/image-controls';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

/**
 * Edit component for the Campaign Image block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Block editor markup.
 */
function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    alt,
    align,
    aspectRatio,
    width,
    height,
    scale,
    resolution,
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
    apiFetch( { path: '/mission-donation-platform/v1/campaigns?per_page=100' } )
      .then( ( response ) => {
        setCampaigns( Array.isArray( response ) ? response : [] );
      } )
      .catch( () => setCampaigns( [] ) )
      .finally( () => setIsLoadingList( false ) );
  }, [] );

  const preloaded = window.missiondpCampaignImage || null;
  let effectiveId = campaignId;
  if ( ! effectiveId ) {
    if ( preloaded?.campaignId ) {
      effectiveId = preloaded.campaignId;
    } else if (
      currentPostType === 'missiondp_campaign' &&
      currentPostId &&
      ! isLoadingList
    ) {
      const match = campaigns.find( ( c ) => c.post_id === currentPostId );
      effectiveId = match?.id || 0;
    }
  }

  let imageSizes = {};
  if ( preloaded?.campaignId && preloaded.campaignId === effectiveId ) {
    imageSizes = preloaded.imageUrls || {};
  } else {
    const activeCampaign = effectiveId
      ? campaigns.find( ( c ) => c.id === effectiveId )
      : null;
    imageSizes = activeCampaign?.image_urls || {};
  }
  const imageUrl =
    imageSizes[ resolution || 'large' ] || imageSizes.full || null;

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

  const wideAligned = isWideAligned( align );
  const scaleVisible = showScaleControl( attributes, align );
  const imgStyle = computeImageStyles( attributes, align );

  const isLoading = ! preloaded && isLoadingList;

  function renderPreview() {
    if ( isLoading ) {
      return (
        <Placeholder
          icon="format-image"
          label={ __( 'Campaign Image', 'mission-donation-platform' ) }
        >
          <Spinner />
        </Placeholder>
      );
    }

    if ( imageUrl ) {
      return (
        <figure className="mission-campaign-image">
          <img src={ imageUrl } alt={ alt } style={ imgStyle } />
        </figure>
      );
    }

    return (
      <div className="mission-campaign-image-empty">
        <svg
          width="28"
          height="28"
          viewBox="0 0 28 28"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <rect x="2" y="4" width="24" height="20" rx="3" />
          <circle cx="9" cy="11" r="3" />
          <path d="M26 18l-7-7L5 25" />
        </svg>
        <p>
          { effectiveId
            ? __( 'No campaign image set.', 'mission-donation-platform' )
            : __(
                'Select a campaign or place this block on a campaign page.',
                'mission-donation-platform'
              ) }
        </p>
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
          <TextareaControl
            label={ __( 'Alternative text', 'mission-donation-platform' ) }
            value={ alt }
            onChange={ ( val ) => setAttributes( { alt: val } ) }
            help={ __(
              'Describe the purpose of the image. Leave empty if decorative.',
              'mission-donation-platform'
            ) }
            rows={ 2 }
          />
          { ! wideAligned && (
            <>
              <SelectControl
                label={ __( 'Aspect ratio', 'mission-donation-platform' ) }
                value={ aspectRatio }
                options={ ASPECT_RATIO_OPTIONS }
                onChange={ ( val ) => setAttributes( { aspectRatio: val } ) }
              />
              <div style={ { display: 'flex', gap: '8px' } }>
                <UnitControl
                  label={ __( 'Width', 'mission-donation-platform' ) }
                  value={ width }
                  onChange={ ( val ) => setAttributes( { width: val ?? '' } ) }
                  labelPosition="top"
                  min={ 0 }
                  placeholder={ __( 'Auto', 'mission-donation-platform' ) }
                  units={ [ { value: 'px', label: 'px' } ] }
                  size="__unstable-large"
                />
                <UnitControl
                  label={ __( 'Height', 'mission-donation-platform' ) }
                  value={ height }
                  onChange={ ( val ) => setAttributes( { height: val ?? '' } ) }
                  labelPosition="top"
                  min={ 0 }
                  placeholder={ __( 'Auto', 'mission-donation-platform' ) }
                  units={ [ { value: 'px', label: 'px' } ] }
                  size="__unstable-large"
                />
              </div>
              { scaleVisible && (
                <ToggleGroupControl
                  label={ __( 'Scale', 'mission-donation-platform' ) }
                  value={ scale }
                  onChange={ ( val ) => setAttributes( { scale: val } ) }
                  isBlock
                  help={
                    scale === 'cover'
                      ? __(
                          'Image covers the space evenly.',
                          'mission-donation-platform'
                        )
                      : __(
                          'Image is contained without distortion.',
                          'mission-donation-platform'
                        )
                  }
                >
                  <ToggleGroupControlOption
                    value="cover"
                    label={ __( 'Cover', 'mission-donation-platform' ) }
                  />
                  <ToggleGroupControlOption
                    value="contain"
                    label={ __( 'Contain', 'mission-donation-platform' ) }
                  />
                </ToggleGroupControl>
              ) }
            </>
          ) }
          <SelectControl
            label={ __( 'Resolution', 'mission-donation-platform' ) }
            value={ resolution }
            options={ RESOLUTION_OPTIONS }
            onChange={ ( val ) => setAttributes( { resolution: val } ) }
            help={ __(
              'Select the size of the source image.',
              'mission-donation-platform'
            ) }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps( { style: { boxShadow: undefined } } ) }>
        { renderPreview() }
      </div>
    </>
  );
}

registerBlockType( metadata, { edit: Edit } );
