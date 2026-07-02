/**
 * Campaign Description block — editor registration.
 *
 * Server-rendered; the editor shows a placeholder since the description comes
 * from the campaign the page belongs to.
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
              'Shown when the campaign has no description.',
              'mission-donation-platform'
            ) }
            placeholder={ __(
              "This page is part of a larger campaign. Every donation made here counts toward the campaign's overall goal.",
              'mission-donation-platform'
            ) }
            value={ attributes.fallback || '' }
            onChange={ ( fallback ) => setAttributes( { fallback } ) }
            __nextHasNoMarginBottom
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps( { className: 'mission-cd-description' } ) }>
        { __(
          "The campaign's description appears here on the live page.",
          'mission-donation-platform'
        ) }
      </div>
    </>
  );
}

registerBlockType( metadata, { edit: Edit } );
