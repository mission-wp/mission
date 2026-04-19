import { Fragment, useState } from '@wordpress/element';
import {
  Button,
  Modal,
  Notice,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { majorToMinor } from '@shared/currencies';
import { getCurrencyCode } from '@shared/currency';
import CampaignCreateStepBasics from './CampaignCreateStepBasics';
import CampaignCreateStepGoal from './CampaignCreateStepGoal';
import CampaignCreateStepImage from './CampaignCreateStepImage';

const BRAND_COLOR = '#2FA36B';
const TOTAL_STEPS = 3;

const STEPS = [
  { number: 1, label: __( 'Details', 'missionwp-donation-platform' ) },
  { number: 2, label: __( 'Goal & Timeline', 'missionwp-donation-platform' ) },
  { number: 3, label: __( 'Image', 'missionwp-donation-platform' ) },
];

const DEFAULT_DATA = {
  title: '',
  excerpt: '',
  goal_amount: '',
  goal_type: 'amount',
  date_start: '',
  date_end: '',
  image: null,
  image_url: '',
};

function StepIndicator( { current } ) {
  return (
    <div className="mission-step-indicator">
      { STEPS.map( ( s, i ) => (
        <Fragment key={ s.number }>
          { i > 0 && (
            <div
              className={ `mission-step-indicator__line${
                s.number <= current ? ' is-active' : ''
              }` }
            />
          ) }
          <div className="mission-step-indicator__step">
            <div
              className={ `mission-step-indicator__circle${
                s.number <= current ? ' is-active' : ''
              }` }
            >
              { s.number }
            </div>
            <span
              className={ `mission-step-indicator__label${
                s.number <= current ? ' is-active' : ''
              }` }
            >
              { s.label }
            </span>
          </div>
        </Fragment>
      ) ) }
    </div>
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
    if ( step === 2 ) {
      return Number( formData.goal_amount ) > 0;
    }
    return true;
  };

  const handleSubmit = async () => {
    setIsSubmitting( true );
    setError( null );

    const body = {
      title: formData.title,
      excerpt: formData.excerpt,
    };

    const goalType = formData.goal_type || 'amount';
    body.goal_type = goalType;

    const goalValue = Number( formData.goal_amount );
    if ( goalValue > 0 ) {
      body.goal_amount =
        goalType === 'amount'
          ? majorToMinor( goalValue, getCurrencyCode() )
          : goalValue;
    }

    if ( formData.date_start ) {
      body.date_start = formData.date_start;
    }
    if ( formData.date_end ) {
      body.date_end = formData.date_end;
    }
    if ( formData.image ) {
      body.image = formData.image;
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
          __(
            'Something went wrong. Please try again.',
            'missionwp-donation-platform'
          )
      );
      setIsSubmitting( false );
    }
  };

  const stepContent = () => {
    switch ( step ) {
      case 1:
        return (
          <CampaignCreateStepBasics data={ formData } onChange={ updateData } />
        );
      case 2:
        return (
          <CampaignCreateStepGoal data={ formData } onChange={ updateData } />
        );
      case 3:
        return (
          <CampaignCreateStepImage data={ formData } onChange={ updateData } />
        );
    }
  };

  return (
    <Modal
      title={ __( 'Create Campaign', 'missionwp-donation-platform' ) }
      onRequestClose={ onClose }
      size="medium"
    >
      <VStack spacing={ 4 }>
        <StepIndicator current={ step } />

        { error && (
          <Notice status="error" isDismissible={ false }>
            { error }
          </Notice>
        ) }

        { stepContent() }

        <HStack justify="space-between" style={ { marginTop: '8px' } }>
          <div>
            { step > 1 && (
              <Button
                variant="tertiary"
                onClick={ () => setStep( step - 1 ) }
                disabled={ isSubmitting }
                __next40pxDefaultSize
              >
                { __( 'Back', 'missionwp-donation-platform' ) }
              </Button>
            ) }
          </div>
          <HStack spacing={ 2 } justify="flex-end">
            <Button
              variant="tertiary"
              onClick={ onClose }
              disabled={ isSubmitting }
              __next40pxDefaultSize
            >
              { __( 'Cancel', 'missionwp-donation-platform' ) }
            </Button>
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
                { __( 'Continue', 'missionwp-donation-platform' ) }
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
                { __( 'Create Campaign', 'missionwp-donation-platform' ) }
              </Button>
            ) }
          </HStack>
        </HStack>
      </VStack>
    </Modal>
  );
}
