/**
 * Team Story block — editor registration.
 *
 * The block resolves its team from the page it sits on (the team template), so
 * the editor shows self-describing placeholder copy rather than real data.
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
              'Shown when a team has not written a story yet.',
              'mission-donation-platform'
            ) }
            placeholder={ __(
              'We have teamed up because we can make a bigger difference together. Every donation to a team member brings us closer to our shared goal.',
              'mission-donation-platform'
            ) }
            value={ attributes.fallback || '' }
            onChange={ ( fallback ) => setAttributes( { fallback } ) }
            __nextHasNoMarginBottom
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps( { className: 'mission-ts-story' } ) }>
        <p>
          { __(
            "Your team's description appears here. Tell visitors who you are and why you're fundraising together.",
            'mission-donation-platform'
          ) }
        </p>
        <p>
          { __(
            'Invite others to join the team or chip in toward your shared goal.',
            'mission-donation-platform'
          ) }
        </p>
      </div>
    </>
  );
}

registerBlockType( metadata, { edit: Edit } );
