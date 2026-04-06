/**
 * Donate Button block.
 *
 * A standalone donate button with full visual customization
 * that scrolls to a donation form or links to a custom URL.
 */
import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit/Edit';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

registerBlockType( metadata, { edit: Edit } );
