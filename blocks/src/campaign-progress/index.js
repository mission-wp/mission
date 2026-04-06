/**
 * Campaign Progress block.
 *
 * Displays campaign fundraising progress with a progress bar,
 * stats, and an optional donate button.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit/Edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata, { edit: Edit } );
