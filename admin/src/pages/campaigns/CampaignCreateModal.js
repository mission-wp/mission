import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import CampaignCreateStepBasics from './CampaignCreateStepBasics';
import CampaignCreateStepGoal from './CampaignCreateStepGoal';
import CampaignCreateStepDonation from './CampaignCreateStepDonation';

const BRAND_COLOR = '#2FA36B';
const TOTAL_STEPS = 3;

const DEFAULT_DATA = {
	title: '',
	excerpt: '',
	goal_amount: '',
	date_start: '',
	date_end: '',
	amounts: [ 1000, 2500, 5000, 10000 ],
	custom_amount: true,
	minimum_amount: '5',
	recurring_enabled: true,
	recurring_frequencies: [ 'monthly', 'quarterly', 'annually' ],
	recurring_default: 'one_time',
	fee_recovery: true,
};

function StepIndicator( { current, total } ) {
	return (
		<HStack
			spacing={ 2 }
			justify="center"
			style={ { marginBottom: '16px' } }
		>
			{ Array.from( { length: total }, ( _, i ) => (
				<span
					key={ i }
					style={ {
						width: '8px',
						height: '8px',
						borderRadius: '50%',
						backgroundColor:
							i + 1 <= current ? BRAND_COLOR : '#ddd',
					} }
				/>
			) ) }
			<span
				style={ {
					fontSize: '12px',
					color: '#757575',
					marginLeft: '8px',
				} }
			>
				{ `${ current } / ${ total }` }
			</span>
		</HStack>
	);
}

export default function CampaignCreateModal( { onClose, onCreated } ) {
	const [ step, setStep ] = useState( 1 );
	const [ formData, setFormData ] = useState( DEFAULT_DATA );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	const updateData = ( updates ) => {
		setFormData( ( prev ) => ( { ...prev, ...updates } ) );
	};

	const canAdvance = () => {
		if ( step === 1 ) {
			return formData.title.trim().length > 0;
		}
		return true;
	};

	const handleSubmit = async () => {
		setIsSubmitting( true );
		setError( null );

		const body = {
			title: formData.title,
			excerpt: formData.excerpt,
			amounts: formData.amounts,
			custom_amount: formData.custom_amount,
			minimum_amount: Math.round(
				Number( formData.minimum_amount ) * 100
			),
			recurring_enabled: formData.recurring_enabled,
			recurring_frequencies: formData.recurring_frequencies,
			recurring_default: formData.recurring_default,
			fee_recovery: formData.fee_recovery,
		};

		const goalDollars = Number( formData.goal_amount );
		if ( goalDollars > 0 ) {
			body.goal_amount = Math.round( goalDollars * 100 );
		}

		if ( formData.date_start ) {
			body.date_start = formData.date_start;
		}
		if ( formData.date_end ) {
			body.date_end = formData.date_end;
		}

		try {
			const result = await apiFetch( {
				path: '/mission/v1/campaigns',
				method: 'POST',
				data: body,
			} );
			onCreated( result.id );
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Something went wrong. Please try again.', 'mission' )
			);
			setIsSubmitting( false );
		}
	};

	const stepContent = () => {
		switch ( step ) {
			case 1:
				return (
					<CampaignCreateStepBasics
						data={ formData }
						onChange={ updateData }
					/>
				);
			case 2:
				return (
					<CampaignCreateStepGoal
						data={ formData }
						onChange={ updateData }
					/>
				);
			case 3:
				return (
					<CampaignCreateStepDonation
						data={ formData }
						onChange={ updateData }
					/>
				);
		}
	};

	return (
		<Modal
			title={ __( 'Create a Campaign', 'mission' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<VStack spacing={ 4 }>
				<StepIndicator current={ step } total={ TOTAL_STEPS } />

				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ stepContent() }

				<HStack justify="flex-end" style={ { marginTop: '8px' } }>
					{ step > 1 && (
						<Button
							variant="tertiary"
							onClick={ () => setStep( step - 1 ) }
							disabled={ isSubmitting }
							__next40pxDefaultSize
						>
							{ __( 'Back', 'mission' ) }
						</Button>
					) }
					{ step < TOTAL_STEPS ? (
						<Button
							variant="primary"
							onClick={ () => setStep( step + 1 ) }
							disabled={ ! canAdvance() }
							style={ {
								backgroundColor: BRAND_COLOR,
								borderColor: BRAND_COLOR,
							} }
							__next40pxDefaultSize
						>
							{ __( 'Next', 'mission' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							onClick={ handleSubmit }
							disabled={ ! canAdvance() || isSubmitting }
							isBusy={ isSubmitting }
							style={ {
								backgroundColor: BRAND_COLOR,
								borderColor: BRAND_COLOR,
							} }
							__next40pxDefaultSize
						>
							{ __( 'Create Campaign', 'mission' ) }
						</Button>
					) }
				</HStack>
			</VStack>
		</Modal>
	);
}
