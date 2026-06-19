/**
 * Team Story block — editor registration.
 *
 * The block resolves its team from the page it sits on (the team template), so
 * the editor shows self-describing placeholder copy rather than real data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit() {
  return (
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
  );
}

registerBlockType( metadata, { edit: Edit } );
