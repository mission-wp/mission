import { __ } from '@wordpress/i18n';

const PERIODS = [
  { value: 'today', label: __( 'Today', 'mission' ) },
  { value: 'week', label: __( 'Week', 'mission' ) },
  { value: 'month', label: __( 'Month', 'mission' ) },
];

export default function PeriodToggle( { period, onChange } ) {
  return (
    <div className="mission-period-toggle">
      { PERIODS.map( ( { value, label } ) => (
        <button
          key={ value }
          className={ `mission-period-toggle__btn${
            period === value ? ' is-active' : ''
          }` }
          onClick={ () => onChange( value ) }
        >
          { label }
        </button>
      ) ) }
    </div>
  );
}
