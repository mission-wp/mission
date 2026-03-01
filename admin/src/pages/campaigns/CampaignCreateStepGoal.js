import {
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function CampaignCreateStepGoal( { data, onChange } ) {
	return (
		<VStack spacing={ 4 }>
			<TextControl
				label={ __( 'Goal Amount ($)', 'mission' ) }
				type="number"
				min="0"
				step="1"
				value={ data.goal_amount }
				onChange={ ( value ) => onChange( { goal_amount: value } ) }
				help={ __( 'Leave empty or set to 0 for no goal.', 'mission' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Start Date', 'mission' ) }
				type="date"
				value={ data.date_start }
				onChange={ ( value ) => onChange( { date_start: value } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'End Date', 'mission' ) }
				type="date"
				value={ data.date_end }
				onChange={ ( value ) => onChange( { date_end: value } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</VStack>
	);
}
