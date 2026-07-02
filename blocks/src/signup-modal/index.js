/**
 * Fundraiser Sign-up Modal block — editor registration.
 *
 * Inserter-hidden; placed in the peer-to-peer page templates. The editor shows
 * a simple placeholder since the modal is a fixed overlay on the live page.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

function Edit() {
  return createElement(
    'div',
    useBlockProps( { className: 'mission-su-editor' } ),
    __(
      'The fundraiser sign-up modal opens here when a visitor chooses to become a fundraiser.',
      'mission-donation-platform'
    )
  );
}

registerBlockType( metadata, { edit: Edit } );
