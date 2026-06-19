/**
 * Team Image block — editor registration.
 *
 * Server-rendered; the editor shows a representative cover-photo placeholder
 * with the same sizing controls as the Campaign Image block, since the real
 * photo comes from the team whose page the block sits on.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { ImageBlockEdit } from '@shared/ImageBlockEdit';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

registerBlockType( metadata, {
  edit: ( props ) => (
    <ImageBlockEdit
      { ...props }
      className="mission-ti-image"
      caption={ __( 'Cover photo', 'mission-donation-platform' ) }
    />
  ),
} );
