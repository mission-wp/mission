import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const FREQUENCY_OPTIONS = [
	{ value: 'monthly', label: __( 'Monthly', 'mission' ) },
	{ value: 'quarterly', label: __( 'Quarterly', 'mission' ) },
	{ value: 'annually', label: __( 'Annually', 'mission' ) },
];

export default function CampaignCreateStepDonation( { data, onChange } ) {
	const [ newAmount, setNewAmount ] = useState( '' );

	const addAmount = () => {
		const cents = Math.round( Number( newAmount ) * 100 );
		if ( cents > 0 && ! data.amounts.includes( cents ) ) {
			onChange( {
				amounts: [ ...data.amounts, cents ].sort( ( a, b ) => a - b ),
			} );
		}
		setNewAmount( '' );
	};

	const removeAmount = ( cents ) => {
		onChange( { amounts: data.amounts.filter( ( a ) => a !== cents ) } );
	};

	const toggleFrequency = ( freq, checked ) => {
		const updated = checked
			? [ ...data.recurring_frequencies, freq ]
			: data.recurring_frequencies.filter( ( f ) => f !== freq );
		onChange( { recurring_frequencies: updated } );
	};

	return (
		<VStack spacing={ 4 }>
			<div>
				<Text as="label" weight="600" size="small">
					{ __( 'Suggested Amounts', 'mission' ) }
				</Text>
				<HStack spacing={ 2 } wrap style={ { marginTop: '8px' } }>
					{ data.amounts.map( ( cents ) => (
						<Button
							key={ cents }
							variant="secondary"
							size="compact"
							onClick={ () => removeAmount( cents ) }
							title={ __( 'Remove', 'mission' ) }
						>
							{ '$' + ( cents / 100 ).toFixed( 2 ) } &times;
						</Button>
					) ) }
				</HStack>
				<HStack spacing={ 2 } style={ { marginTop: '8px' } }>
					<TextControl
						type="number"
						min="1"
						step="1"
						value={ newAmount }
						onChange={ setNewAmount }
						placeholder={ __( 'Amount ($)', 'mission' ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								addAmount();
							}
						} }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						variant="secondary"
						onClick={ addAmount }
						__next40pxDefaultSize
					>
						{ __( 'Add', 'mission' ) }
					</Button>
				</HStack>
			</div>

			<ToggleControl
				label={ __( 'Allow custom amount', 'mission' ) }
				checked={ data.custom_amount }
				onChange={ ( value ) => onChange( { custom_amount: value } ) }
				__nextHasNoMarginBottom
			/>

			<TextControl
				label={ __( 'Minimum Amount ($)', 'mission' ) }
				type="number"
				min="1"
				step="1"
				value={ data.minimum_amount }
				onChange={ ( value ) => onChange( { minimum_amount: value } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<ToggleControl
				label={ __( 'Enable recurring donations', 'mission' ) }
				checked={ data.recurring_enabled }
				onChange={ ( value ) =>
					onChange( { recurring_enabled: value } )
				}
				__nextHasNoMarginBottom
			/>

			{ data.recurring_enabled && (
				<VStack spacing={ 2 } style={ { paddingLeft: '16px' } }>
					<Text weight="600" size="small">
						{ __( 'Frequencies', 'mission' ) }
					</Text>
					{ FREQUENCY_OPTIONS.map( ( opt ) => (
						<CheckboxControl
							key={ opt.value }
							label={ opt.label }
							checked={ data.recurring_frequencies.includes(
								opt.value
							) }
							onChange={ ( checked ) =>
								toggleFrequency( opt.value, checked )
							}
							__nextHasNoMarginBottom
						/>
					) ) }
					<SelectControl
						label={ __( 'Default Selection', 'mission' ) }
						value={ data.recurring_default }
						options={ [
							{
								value: 'one_time',
								label: __( 'One-Time', 'mission' ),
							},
							...FREQUENCY_OPTIONS.filter( ( opt ) =>
								data.recurring_frequencies.includes( opt.value )
							),
						] }
						onChange={ ( value ) =>
							onChange( { recurring_default: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</VStack>
			) }

			<ToggleControl
				label={ __( 'Enable fee recovery', 'mission' ) }
				checked={ data.fee_recovery }
				onChange={ ( value ) => onChange( { fee_recovery: value } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
