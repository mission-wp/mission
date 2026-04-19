import { __ } from '@wordpress/i18n';

const FREQUENCIES = [
  { id: 'one_time', label: __( 'One-time', 'missionwp-donation-platform' ) },
  { id: 'weekly', label: __( 'Weekly', 'missionwp-donation-platform' ) },
  { id: 'monthly', label: __( 'Monthly', 'missionwp-donation-platform' ) },
  { id: 'quarterly', label: __( 'Quarterly', 'missionwp-donation-platform' ) },
  { id: 'annually', label: __( 'Annually', 'missionwp-donation-platform' ) },
];

export default function FrequencyChips( { selected, onChange } ) {
  const toggle = ( freqId ) => {
    if ( selected.includes( freqId ) ) {
      if ( selected.length <= 1 ) {
        return;
      }
      onChange( selected.filter( ( f ) => f !== freqId ) );
    } else {
      onChange( [ ...selected, freqId ] );
    }
  };

  return (
    <div className="mission-frequency-options">
      { FREQUENCIES.map( ( freq ) => (
        <button
          key={ freq.id }
          type="button"
          className={ `mission-frequency-chip${
            selected.includes( freq.id ) ? ' is-selected' : ''
          }` }
          onClick={ () => toggle( freq.id ) }
        >
          { freq.label }
        </button>
      ) ) }
    </div>
  );
}

export { FREQUENCIES };
