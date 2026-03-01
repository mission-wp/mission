import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit() {
		return (
			<div { ...useBlockProps() }>
				<p>
					<strong>
						{ __( 'Mission Donation Form', 'mission' ) }
					</strong>
				</p>
				<p>
					{ __(
						'The donation form will be displayed here on the frontend.',
						'mission'
					) }
				</p>
			</div>
		);
	},
} );
