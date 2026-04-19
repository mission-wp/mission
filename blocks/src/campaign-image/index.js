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
import metadata from './block.json';
import './style.scss';
import './editor.scss';

const ASPECT_RATIO_OPTIONS = [
  { label: __( 'Original', 'missionwp-donation-platform' ), value: '' },
  { label: __( 'Square - 1:1', 'missionwp-donation-platform' ), value: '1/1' },
  {
    label: __( 'Standard - 4:3', 'missionwp-donation-platform' ),
    value: '4/3',
  },
  {
    label: __( 'Portrait - 3:4', 'missionwp-donation-platform' ),
    value: '3/4',
  },
  { label: __( 'Classic - 3:2', 'missionwp-donation-platform' ), value: '3/2' },
  {
    label: __( 'Classic Portrait - 2:3', 'missionwp-donation-platform' ),
    value: '2/3',
  },
  { label: __( 'Wide - 16:9', 'missionwp-donation-platform' ), value: '16/9' },
  { label: __( 'Tall - 9:16', 'missionwp-donation-platform' ), value: '9/16' },
];

const RESOLUTION_OPTIONS = [
  {
    label: __( 'Thumbnail', 'missionwp-donation-platform' ),
    value: 'thumbnail',
  },
  { label: __( 'Medium', 'missionwp-donation-platform' ), value: 'medium' },
  { label: __( 'Large', 'missionwp-donation-platform' ), value: 'large' },
  { label: __( 'Full Size', 'missionwp-donation-platform' ), value: 'full' },
];

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

  // Get the current post context for auto-detect.
  const { currentPostType, currentPostId } = useSelect( ( select ) => {
    const editor = select( 'core/editor' );
    return {
      currentPostType: editor?.getCurrentPostType?.() || '',
      currentPostId: editor?.getCurrentPostId?.() || 0,
    };
  }, [] );

  // Fetch campaigns for the combobox.
  useEffect( () => {
    apiFetch( { path: '/mission/v1/campaigns?per_page=100' } )
      .then( ( response ) => {
        setCampaigns( Array.isArray( response ) ? response : [] );
      } )
      .catch( () => setCampaigns( [] ) )
      .finally( () => setIsLoadingList( false ) );
  }, [] );

  // Determine the effective campaign ID for preview.
  const preloaded = window.missionCampaignImage || null;
  let effectiveId = campaignId;
  if ( ! effectiveId ) {
    if ( preloaded?.campaignId ) {
      // Use preloaded campaign ID (campaign post edit or admin detail page).
      effectiveId = preloaded.campaignId;
    } else if (
      currentPostType === 'mission_campaign' &&
      currentPostId &&
      ! isLoadingList
    ) {
      // Fall back to matching by post ID from the campaigns list.
      const match = campaigns.find( ( c ) => c.post_id === currentPostId );
      effectiveId = match?.id || 0;
    }
  }

  // Use preloaded image URLs when they match, otherwise fall back to API data.
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

  // Campaign options filtered by the combobox input.
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

  // Build inline styles for the img element (matching core/image pattern).
  const isWideAligned = [ 'wide', 'full' ].includes( align );
  const showScaleControl =
    ! isWideAligned && ( aspectRatio || ( width && height ) );
  const imgStyle = {};
  if ( aspectRatio ) {
    imgStyle.aspectRatio = aspectRatio;
  }
  if ( showScaleControl ) {
    imgStyle.objectFit = scale;
  }
  if ( width ) {
    imgStyle.width = width.includes( 'px' ) ? width : width + 'px';
  }
  if ( height ) {
    imgStyle.height = height.includes( 'px' ) ? height : height + 'px';
  }

  // Border and shadow styles (skip-serialized, applied manually to img).
  const borderStyles = attributes?.style?.border || {};
  if ( borderStyles.radius ) {
    if ( typeof borderStyles.radius === 'object' ) {
      imgStyle.borderTopLeftRadius = borderStyles.radius.topLeft || 0;
      imgStyle.borderTopRightRadius = borderStyles.radius.topRight || 0;
      imgStyle.borderBottomLeftRadius = borderStyles.radius.bottomLeft || 0;
      imgStyle.borderBottomRightRadius = borderStyles.radius.bottomRight || 0;
    } else {
      imgStyle.borderRadius = borderStyles.radius;
    }
  }
  if ( borderStyles.width ) {
    imgStyle.borderWidth = borderStyles.width;
  }
  if ( borderStyles.style ) {
    imgStyle.borderStyle = borderStyles.style;
  }
  if ( borderStyles.color ) {
    const color = borderStyles.color.startsWith( 'var:preset|color|' )
      ? `var(--wp--preset--color--${ borderStyles.color.replace(
          'var:preset|color|',
          ''
        ) })`
      : borderStyles.color;
    imgStyle.borderColor = color;
  }
  const shadow = attributes?.style?.shadow || '';
  if ( shadow ) {
    imgStyle.boxShadow = shadow.startsWith( 'var:preset|shadow|' )
      ? `var(--wp--preset--shadow--${ shadow.replace(
          'var:preset|shadow|',
          ''
        ) })`
      : shadow;
  }

  const isLoading = ! preloaded && isLoadingList;

  function renderPreview() {
    if ( isLoading ) {
      return (
        <Placeholder
          icon="format-image"
          label={ __( 'Campaign Image', 'missionwp-donation-platform' ) }
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
            ? __( 'No campaign image set.', 'missionwp-donation-platform' )
            : __(
                'Select a campaign or place this block on a campaign page.',
                'missionwp-donation-platform'
              ) }
        </p>
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
          <TextareaControl
            label={ __( 'Alternative text', 'missionwp-donation-platform' ) }
            value={ alt }
            onChange={ ( val ) => setAttributes( { alt: val } ) }
            help={ __(
              'Describe the purpose of the image. Leave empty if decorative.',
              'missionwp-donation-platform'
            ) }
            rows={ 2 }
          />
          { ! isWideAligned && (
            <>
              <SelectControl
                label={ __( 'Aspect ratio', 'missionwp-donation-platform' ) }
                value={ aspectRatio }
                options={ ASPECT_RATIO_OPTIONS }
                onChange={ ( val ) => setAttributes( { aspectRatio: val } ) }
              />
              <div style={ { display: 'flex', gap: '8px' } }>
                <UnitControl
                  label={ __( 'Width', 'missionwp-donation-platform' ) }
                  value={ width }
                  onChange={ ( val ) => setAttributes( { width: val ?? '' } ) }
                  labelPosition="top"
                  min={ 0 }
                  placeholder={ __( 'Auto', 'missionwp-donation-platform' ) }
                  units={ [ { value: 'px', label: 'px' } ] }
                  size="__unstable-large"
                />
                <UnitControl
                  label={ __( 'Height', 'missionwp-donation-platform' ) }
                  value={ height }
                  onChange={ ( val ) => setAttributes( { height: val ?? '' } ) }
                  labelPosition="top"
                  min={ 0 }
                  placeholder={ __( 'Auto', 'missionwp-donation-platform' ) }
                  units={ [ { value: 'px', label: 'px' } ] }
                  size="__unstable-large"
                />
              </div>
              { showScaleControl && (
                <ToggleGroupControl
                  label={ __( 'Scale', 'missionwp-donation-platform' ) }
                  value={ scale }
                  onChange={ ( val ) => setAttributes( { scale: val } ) }
                  isBlock
                  help={
                    scale === 'cover'
                      ? __(
                          'Image covers the space evenly.',
                          'missionwp-donation-platform'
                        )
                      : __(
                          'Image is contained without distortion.',
                          'missionwp-donation-platform'
                        )
                  }
                >
                  <ToggleGroupControlOption
                    value="cover"
                    label={ __( 'Cover', 'missionwp-donation-platform' ) }
                  />
                  <ToggleGroupControlOption
                    value="contain"
                    label={ __( 'Contain', 'missionwp-donation-platform' ) }
                  />
                </ToggleGroupControl>
              ) }
            </>
          ) }
          <SelectControl
            label={ __( 'Resolution', 'missionwp-donation-platform' ) }
            value={ resolution }
            options={ RESOLUTION_OPTIONS }
            onChange={ ( val ) => setAttributes( { resolution: val } ) }
            help={ __(
              'Select the size of the source image.',
              'missionwp-donation-platform'
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
