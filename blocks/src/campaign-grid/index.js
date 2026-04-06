import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit/Edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata, { edit: Edit } );
