/**
 * Edit component for the Campaign Statistics block.
 */
import {
  useBlockProps,
  InspectorControls,
  BlockControls,
  AlignmentControl,
  PanelColorSettings,
  RichText,
} from '@wordpress/block-editor';
import {
  ComboboxControl,
  FontSizePicker,
  PanelBody,
  SelectControl,
  ToggleControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalToggleGroupControl as ToggleGroupControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
  formatUppercase,
  formatLowercase,
  formatCapitalize,
} from '@wordpress/icons';
import { useSelect } from '@wordpress/data';
import {
  useState,
  useEffect,
  useCallback,
  useRef,
  useMemo,
} from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import apiFetch from '@wordpress/api-fetch';
import { formatAmount } from '@shared/currency';

const STAT_DEFAULTS = {
  total_raised: {
    heading: __( 'Total Raised', 'mission-donation-platform' ),
    sample: 125000,
    isCurrency: true,
  },
  donation_count: {
    heading: __( 'Number of Donations', 'mission-donation-platform' ),
    sample: 42,
    isCurrency: false,
  },
  donor_count: {
    heading: __( 'Number of Donors', 'mission-donation-platform' ),
    sample: 28,
    isCurrency: false,
  },
  average_donation: {
    heading: __( 'Average Donation', 'mission-donation-platform' ),
    sample: 7500,
    isCurrency: true,
  },
  top_donation: {
    heading: __( 'Top Donation', 'mission-donation-platform' ),
    sample: 50000,
    isCurrency: true,
  },
};

const STAT_TYPE_OPTIONS = [
  {
    label: __( 'Total Raised', 'mission-donation-platform' ),
    value: 'total_raised',
  },
  {
    label: __( 'Number of Donations', 'mission-donation-platform' ),
    value: 'donation_count',
  },
  {
    label: __( 'Number of Donors', 'mission-donation-platform' ),
    value: 'donor_count',
  },
  {
    label: __( 'Average Donation', 'mission-donation-platform' ),
    value: 'average_donation',
  },
  {
    label: __( 'Top Donation', 'mission-donation-platform' ),
    value: 'top_donation',
  },
];

const HEADING_FONT_SIZES = [
  {
    name: __( 'Small', 'mission-donation-platform' ),
    slug: 'small',
    size: '12px',
  },
  {
    name: __( 'Medium', 'mission-donation-platform' ),
    slug: 'medium',
    size: '14px',
  },
  {
    name: __( 'Large', 'mission-donation-platform' ),
    slug: 'large',
    size: '18px',
  },
  {
    name: __( 'Extra Large', 'mission-donation-platform' ),
    slug: 'x-large',
    size: '22px',
  },
];

/**
 * Get the formatted stat value from real campaign data when available.
 *
 * @param {Object} campaign Campaign object from the REST response.
 * @param {string} statType The selected stat type key.
 * @return {string} Formatted value.
 */
function getStatValue( campaign, statType ) {
  switch ( statType ) {
    case 'total_raised':
      return formatAmount( campaign.total_raised );
    case 'donation_count':
      return campaign.transaction_count.toLocaleString();
    case 'donor_count':
      return campaign.donor_count.toLocaleString();
    case 'average_donation':
      return campaign.transaction_count > 0
        ? formatAmount(
            Math.round( campaign.total_raised / campaign.transaction_count )
          )
        : formatAmount( 0 );
    case 'top_donation':
      // Not available in the campaign list response; show sample.
      return formatAmount( STAT_DEFAULTS.top_donation.sample );
    default:
      return formatAmount( 0 );
  }
}

export default function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    statType,
    heading,
    showHeading,
    textAlign,
    headingFontSize,
    headingColor,
    headingTextTransform,
  } = attributes;

  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoadingList, setIsLoadingList ] = useState( true );
  const [ filterValue, setFilterValue ] = useState( '' );
  const prevStatType = useRef( statType );

  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

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

  const onStatTypeChange = useCallback(
    ( newType ) => {
      const oldDefault = STAT_DEFAULTS[ prevStatType.current ]?.heading || '';
      const updates = { statType: newType };

      // Auto-update heading if it still matches the previous default.
      if ( heading === oldDefault ) {
        updates.heading = STAT_DEFAULTS[ newType ]?.heading || newType;
      }

      setAttributes( updates );
      prevStatType.current = newType;
    },
    [ heading, setAttributes ]
  );

  const stat = STAT_DEFAULTS[ statType ] || STAT_DEFAULTS.total_raised;

  // Pass heading styles as CSS custom properties on the wrapper.
  const customProperties = {};
  if ( headingFontSize ) {
    customProperties[ '--mission-cs-heading-size' ] = headingFontSize;
  }
  if ( headingColor ) {
    customProperties[ '--mission-cs-heading-color' ] = headingColor;
  }
  if ( headingTextTransform && headingTextTransform !== 'uppercase' ) {
    customProperties[ '--mission-cs-heading-case' ] = headingTextTransform;
  }

  const blockProps = useBlockProps( {
    className: textAlign ? `has-text-align-${ textAlign }` : undefined,
    style: Object.keys( customProperties ).length
      ? customProperties
      : undefined,
  } );

  function renderPreview() {
    if ( isLoadingList ) {
      return (
        <div
          className="mission-campaign-stat mission-cs-skeleton"
          style={ primaryColorVars }
        >
          <div
            className="mission-cs-skeleton-bar"
            style={ { width: '40%', height: 12 } }
          />
          <div
            className="mission-cs-skeleton-bar"
            style={ { width: '55%', height: 28 } }
          />
        </div>
      );
    }

    if ( ! campaign ) {
      return (
        <div className="mission-cs-setup">
          <div className="mission-cs-setup__icon">
            <svg
              width="28"
              height="28"
              viewBox="0 0 24 24"
              fill="none"
              stroke="#2fa36b"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <line x1="18" y1="20" x2="18" y2="10" />
              <line x1="12" y1="20" x2="12" y2="4" />
              <line x1="6" y1="20" x2="6" y2="14" />
            </svg>
          </div>
          <h3 className="mission-cs-setup__title">
            { __( 'Campaign Statistic', 'mission-donation-platform' ) }
          </h3>
          <div className="mission-cs-setup__campaign">
            <ComboboxControl
              label={ __( 'Select a Campaign', 'mission-donation-platform' ) }
              value={ campaignId || null }
              options={ campaignOptions }
              onChange={ onCampaignChange }
              onFilterValueChange={ setFilterValue }
            />
          </div>
        </div>
      );
    }

    return (
      <div className="mission-campaign-stat" style={ primaryColorVars }>
        { showHeading && (
          <RichText
            tagName="p"
            className="mission-cs-heading"
            value={ heading }
            onChange={ ( val ) => setAttributes( { heading: val } ) }
            placeholder={ stat.heading }
            allowedFormats={ [] }
          />
        ) }
        <span className="mission-cs-value">
          { getStatValue( campaign, statType ) }
        </span>
      </div>
    );
  }

  return (
    <>
      <BlockControls group="block">
        <AlignmentControl
          value={ textAlign }
          onChange={ ( val ) => setAttributes( { textAlign: val } ) }
        />
      </BlockControls>
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
            label={ __( 'Statistic', 'mission-donation-platform' ) }
            value={ statType }
            options={ STAT_TYPE_OPTIONS }
            onChange={ onStatTypeChange }
          />
          <ToggleControl
            label={ __( 'Show heading', 'mission-donation-platform' ) }
            checked={ showHeading }
            onChange={ ( val ) => setAttributes( { showHeading: val } ) }
          />
        </PanelBody>
        { showHeading && (
          <PanelColorSettings
            title={ __( 'Heading', 'mission-donation-platform' ) }
            initialOpen={ false }
            colorSettings={ [
              {
                value: headingColor,
                onChange: ( val ) =>
                  setAttributes( { headingColor: val || '' } ),
                label: __( 'Color', 'mission-donation-platform' ),
              },
            ] }
          >
            <FontSizePicker
              fontSizes={ HEADING_FONT_SIZES }
              value={ headingFontSize || undefined }
              onChange={ ( val ) =>
                setAttributes( { headingFontSize: val || '' } )
              }
              withReset
            />
            <div style={ { marginTop: 24 } }>
              <ToggleGroupControl
                __nextHasNoMarginBottom
                label={ __( 'Letter case', 'mission-donation-platform' ) }
                value={
                  headingTextTransform === 'none'
                    ? undefined
                    : headingTextTransform || 'uppercase'
                }
                onChange={ ( val ) =>
                  setAttributes( { headingTextTransform: val || 'none' } )
                }
                isDeselectable
                isBlock
              >
                <ToggleGroupControlOptionIcon
                  value="uppercase"
                  label={ __( 'Uppercase', 'mission-donation-platform' ) }
                  icon={ formatUppercase }
                />
                <ToggleGroupControlOptionIcon
                  value="lowercase"
                  label={ __( 'Lowercase', 'mission-donation-platform' ) }
                  icon={ formatLowercase }
                />
                <ToggleGroupControlOptionIcon
                  value="capitalize"
                  label={ __( 'Capitalize', 'mission-donation-platform' ) }
                  icon={ formatCapitalize }
                />
              </ToggleGroupControl>
            </div>
          </PanelColorSettings>
        ) }
      </InspectorControls>
      <div { ...blockProps }>{ renderPreview() }</div>
    </>
  );
}
