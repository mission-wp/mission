/**
 * Fundraiser Image block — editor registration.
 *
 * Server-rendered; the editor shows a placeholder since the cover photo comes
 * from the fundraiser whose page the block sits on.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit() {
  return createElement(
    'div',
    useBlockProps( { className: 'mission-fi-image' } ),
    __(
      "This fundraiser's cover photo appears here on the live page.",
      'mission-donation-platform'
    )
  );
}

registerBlockType( metadata, { edit: Edit } );
