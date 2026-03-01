import { TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function CampaignCreateStepBasics( { data, onChange } ) {
	return (
		<>
			<TextControl
				label={ __( 'Campaign Title', 'mission' ) }
				value={ data.title }
				onChange={ ( value ) => onChange( { title: value } ) }
				help={ __( 'You can always change this later.', 'mission' ) }
				required
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextareaControl
				label={ __( 'Description', 'mission' ) }
				value={ data.excerpt }
				onChange={ ( value ) => onChange( { excerpt: value } ) }
				help={ __(
					'A short summary shown in campaign listings.',
					'mission'
				) }
				__nextHasNoMarginBottom
			/>
		</>
	);
}
