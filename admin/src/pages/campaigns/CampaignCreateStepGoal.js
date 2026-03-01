import {
	BaseControl,
	TextControl,
	__experimentalInputControl as InputControl,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getCurrencySymbol } from '@shared/currency';

export default function CampaignCreateStepGoal( { data, onChange } ) {
	return (
		<VStack spacing={ 4 }>
			<BaseControl
				id="mission-goal-amount"
				label={ __( 'Goal Amount', 'mission' ) }
				help={ __( 'Leave empty or set to 0 for no goal.', 'mission' ) }
				__nextHasNoMarginBottom
			>
				<InputControl
					id="mission-goal-amount"
					type="number"
					min={ 0 }
					step={ 1 }
					value={ data.goal_amount }
					onChange={ ( value ) => onChange( { goal_amount: value } ) }
					prefix={
						<Text
							style={ {
								paddingLeft: '12px',
								color: '#757575',
							} }
						>
							{ getCurrencySymbol() }
						</Text>
					}
					__next40pxDefaultSize
				/>
			</BaseControl>
			<TextControl
				label={ __( 'Start Date', 'mission' ) }
				type="date"
				value={ data.date_start }
				onChange={ ( value ) => onChange( { date_start: value } ) }
				help={ __( 'Leave blank to start immediately.', 'mission' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'End Date', 'mission' ) }
				type="date"
				value={ data.date_end }
				onChange={ ( value ) => onChange( { date_end: value } ) }
				help={ __( 'Leave blank to run indefinitely.', 'mission' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</VStack>
	);
}
