import { __ } from '@wordpress/i18n';

import { CheckIcon } from './icons';

const STEPS = [
  { num: '01', label: __( 'Scan', 'mission-donation-platform' ) },
  { num: '02', label: __( 'Migrate', 'mission-donation-platform' ) },
  { num: '03', label: __( 'Review', 'mission-donation-platform' ) },
];

export default function MigrationStepper( { current } ) {
  return (
    <div className="mission-migration-stepper">
      { STEPS.map( ( step, index ) => {
        const number = index + 1;
        let state = '';
        if ( number < current ) {
          state = ' is-done';
        } else if ( number === current ) {
          state = ' is-active';
        }
        return (
          <div
            key={ step.num }
            className={ `mission-migration-step${ state }` }
          >
            <span className="mission-migration-step__num">{ step.num }</span>
            <span className="mission-migration-step__label">
              { step.label }
            </span>
            <span className="mission-migration-step__check">
              <CheckIcon size={ 12 } />
            </span>
          </div>
        );
      } ) }
    </div>
  );
}
