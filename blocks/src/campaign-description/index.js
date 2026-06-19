/**
 * Campaign Description block — editor registration.
 *
 * Server-rendered; the editor shows a placeholder since the description comes
 * from the campaign the page belongs to.
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
    useBlockProps( { className: 'mission-cd-description' } ),
    __(
      "The campaign's description appears here on the live page.",
      'mission-donation-platform'
    )
  );
}

registerBlockType( metadata, { edit: Edit } );
