import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import './style.scss';
import './editor.scss';

registerBlockType( 'mission/donation-form', {
	edit() {
		return (
			<div className="mission-donation-form">
				<p>{ __( 'Donation Form', 'mission' ) }</p>
				<p>
					{ __(
						'This block outputs the donation form on the frontend.',
						'mission'
					) }
				</p>
			</div>
		);
	},
	save() {
		return null; // Use PHP render
	},
} );
