import {
  BaseControl,
  TextControl,
  __experimentalInputControl as InputControl,
  __experimentalVStack as VStack,
  __experimentalHStack as HStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getCurrencySymbol } from '@shared/currency';
import GoalTypePicker from '../../components/GoalTypePicker';

const GOAL_LABELS = {
  amount: __( 'Fundraising Goal', 'mission' ),
  donations: __( 'Donation Goal', 'mission' ),
  donors: __( 'Donor Goal', 'mission' ),
};

export default function CampaignCreateStepGoal( { data, onChange } ) {
  const goalType = data.goal_type || 'amount';

  return (
    <VStack spacing={ 4 }>
      <GoalTypePicker
        value={ goalType }
        onChange={ ( value ) => onChange( { goal_type: value } ) }
      />
      <BaseControl
        id="mission-goal-amount"
        label={ GOAL_LABELS[ goalType ] }
        help={ __( 'Enter a goal above zero', 'mission' ) }
        __nextHasNoMarginBottom
      >
        <InputControl
          id="mission-goal-amount"
          type="number"
          min={ 1 }
          step={ 1 }
          value={ data.goal_amount }
          onChange={ ( value ) => onChange( { goal_amount: value } ) }
          placeholder=""
          prefix={
            goalType === 'amount' ? (
              <Text
                style={ {
                  paddingLeft: '12px',
                  color: '#757575',
                } }
              >
                { getCurrencySymbol() }
              </Text>
            ) : undefined
          }
          __next40pxDefaultSize
        />
      </BaseControl>
      <HStack spacing={ 4 } alignment="top">
        <div style={ { flex: 1 } }>
          <TextControl
            label={ __( 'Start Date', 'mission' ) }
            type="date"
            value={ data.date_start }
            onChange={ ( value ) => onChange( { date_start: value } ) }
            help={ __( 'Leave blank to start immediately', 'mission' ) }
            __nextHasNoMarginBottom
            __next40pxDefaultSize
          />
        </div>
        <div style={ { flex: 1 } }>
          <TextControl
            label={ __( 'End Date', 'mission' ) }
            type="date"
            value={ data.date_end }
            onChange={ ( value ) => onChange( { date_end: value } ) }
            help={ __( 'Leave blank for an ongoing campaign', 'mission' ) }
            __nextHasNoMarginBottom
            __next40pxDefaultSize
          />
        </div>
      </HStack>
    </VStack>
  );
}
