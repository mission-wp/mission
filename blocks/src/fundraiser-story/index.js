/**
 * Fundraiser Story block — editor registration.
 *
 * The block resolves its fundraiser from the page it sits on (the fundraiser
 * template), so the editor shows self-describing placeholder copy rather than
 * real data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit() {
  return (
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
  );
}

registerBlockType( metadata, { edit: Edit } );
