/**
 * Fundraiser Story block — editor registration.
 *
 * The block resolves its fundraiser from the page it sits on (the fundraiser
 * template), so the editor shows self-describing placeholder copy rather than
 * real data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit( { attributes, setAttributes } ) {
  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'mission-donation-platform' ) }>
          <TextareaControl
            label={ __( 'Fallback text', 'mission-donation-platform' ) }
            help={ __(
              'Shown when a fundraiser has not written a story yet.',
              'mission-donation-platform'
            ) }
            placeholder={ __(
              'I am raising money for a cause that means a lot to me. Every donation, big or small, helps me get closer to my goal. Thank you for your support!',
              'mission-donation-platform'
            ) }
            value={ attributes.fallback || '' }
            onChange={ ( fallback ) => setAttributes( { fallback } ) }
            __nextHasNoMarginBottom
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps( { className: 'mission-fs-story' } ) }>
        <p>
          { __(
            "Your fundraiser's story appears here. Share why this cause matters to you and what donations will make possible.",
            'mission-donation-platform'
          ) }
        </p>
        <p>
          { __(
            'Supporters read this before they give, so make it personal.',
            'mission-donation-platform'
          ) }
        </p>
      </div>
    </>
  );
}

registerBlockType( metadata, { edit: Edit } );
