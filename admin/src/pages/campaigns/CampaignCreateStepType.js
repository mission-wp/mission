import { __ } from '@wordpress/i18n';
import {
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { BRAND_COLOR } from '@shared/color';

const TYPES = [
  {
    value: 'standard',
    label: __( 'Donation Form', 'mission-donation-platform' ),
    description: __(
      'A standard campaign with a donation form.',
      'mission-donation-platform'
    ),
  },
  {
    value: 'p2p',
    label: __( 'Peer-to-Peer', 'mission-donation-platform' ),
    description: __(
      'Supporters create personal fundraising pages that roll up into this campaign.',
      'mission-donation-platform'
    ),
  },
  {
    value: 'event',
    label: __( 'Ticketed Event', 'mission-donation-platform' ),
    description: __( 'Sell tickets to an event.', 'mission-donation-platform' ),
    comingSoon: true,
  },
];

/**
 * Campaign type picker, shown as the first step of the create flow. Type is
 * immutable after creation, so it is chosen up front. The event type is shown
 * as a disabled "coming soon" card.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Form data (reads data.type).
 * @param {Function} props.onChange Update callback.
 * @return {JSX.Element} The step.
 */
export default function CampaignCreateStepType( { data, onChange } ) {
  const selected = data.type || 'standard';

  return (
    <VStack spacing={ 3 }>
      <Text>
        { __(
          'What kind of campaign is this? This can’t be changed later.',
          'mission-donation-platform'
        ) }
      </Text>

      { TYPES.map( ( type ) => {
        const isSelected = ! type.comingSoon && selected === type.value;

        return (
          <button
            key={ type.value }
            type="button"
            disabled={ type.comingSoon }
            aria-pressed={ isSelected }
            onClick={ () =>
              ! type.comingSoon && onChange( { type: type.value } )
            }
            className="mission-type-card"
            style={ {
              textAlign: 'left',
              padding: '14px 16px',
              borderRadius: '6px',
              border: `1px solid ${ isSelected ? BRAND_COLOR : '#e0e0e6' }`,
              background: isSelected ? '#f3fbf6' : '#fff',
              cursor: type.comingSoon ? 'not-allowed' : 'pointer',
              opacity: type.comingSoon ? 0.55 : 1,
              width: '100%',
            } }
          >
            <div
              style={ {
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                fontWeight: 600,
                marginBottom: '2px',
              } }
            >
              { type.label }
              { type.comingSoon && (
                <span
                  style={ {
                    fontSize: '11px',
                    fontWeight: 500,
                    color: '#6b6b7b',
                    background: '#f0f0f5',
                    borderRadius: '2px',
                    padding: '1px 6px',
                  } }
                >
                  { __( 'Coming soon', 'mission-donation-platform' ) }
                </span>
              ) }
            </div>
            <Text variant="muted" size="small">
              { type.description }
            </Text>
          </button>
        );
      } ) }
    </VStack>
  );
}
