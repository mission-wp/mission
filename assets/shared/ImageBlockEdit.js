/**
 * Shared editor component for the fundraiser/team image blocks.
 *
 * These blocks resolve their photo from the page they sit on (the fundraiser or
 * team template), so the editor cannot show a real image. It renders a
 * representative cover-photo placeholder that honors the same sizing, scale,
 * border, and shadow controls as the Campaign Image block, so those controls
 * still feel live while editing.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
  PanelBody,
  SelectControl,
  TextareaControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalUnitControl as UnitControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalToggleGroupControl as ToggleGroupControl,
  // eslint-disable-next-line @wordpress/no-unsafe-wp-apis
  __experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import {
  computeImageStyles,
  isWideAligned,
  showScaleControl,
} from '@shared/image-styles';
import {
  ASPECT_RATIO_OPTIONS,
  RESOLUTION_OPTIONS,
} from '@shared/image-controls';

/**
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {string}   props.className     Frontend wrapper class (e.g. 'mission-fi-image').
 * @param {string}   props.caption       Placeholder caption text.
 * @return {Element} Block editor markup.
 */
export function ImageBlockEdit( {
  attributes,
  setAttributes,
  className,
  caption,
} ) {
  const { alt, align, aspectRatio, width, height, scale, resolution } =
    attributes;

  const wideAligned = isWideAligned( align );
  const scaleVisible = showScaleControl( attributes, align );
  const imgStyle = computeImageStyles( attributes, align );

  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'mission-donation-platform' ) }>
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
      <figure
        { ...useBlockProps( { className, style: { boxShadow: undefined } } ) }
      >
        <div
          className="mission-image-placeholder"
          style={ { ...primaryColorVars, ...imgStyle } }
        >
          <svg
            width="32"
            height="32"
            viewBox="0 0 28 28"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <rect x="2" y="4" width="24" height="20" rx="3" />
            <circle cx="9" cy="11" r="3" />
            <path d="M26 18l-7-7L5 25" />
          </svg>
          <span className="mission-image-placeholder__caption">
            { caption }
          </span>
        </div>
      </figure>
    </>
  );
}
