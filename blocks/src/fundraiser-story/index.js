/**
 * Fundraiser Story block — editor registration.
 *
 * Server-rendered; the editor shows a placeholder since the story comes from
 * the fundraiser whose page the block sits on.
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
    useBlockProps( { className: 'mission-fs-story' } ),
    __(
      "This fundraiser's story appears here on the live page.",
      'mission-donation-platform'
    )
  );
}

registerBlockType( metadata, { edit: Edit } );
