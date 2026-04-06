import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const STEPS = [
  { number: 1, label: __( 'Basics', 'mission' ) },
  { number: 2, label: __( 'Details', 'mission' ) },
  { number: 3, label: __( 'Payments', 'mission' ) },
  { number: 4, label: __( 'Campaign', 'mission' ) },
  { number: 5, label: __( 'Done', 'mission' ) },
];

const checkSvg = (
  <svg
    width="12"
    height="12"
    viewBox="0 0 12 12"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="2,6 5,9 10,3" />
  </svg>
);

export default function StepNav( { currentStep } ) {
  return (
    <nav className="mission-onboarding-steps-nav">
      { STEPS.map( ( s, i ) => (
        <Fragment key={ s.number }>
          { i > 0 && (
            <div
              className={ `mission-onboarding-connector${
                s.number <= currentStep ? ' is-filled' : ''
              }` }
            />
          ) }
          <div
            className={ `mission-onboarding-step-item${
              s.number === currentStep ? ' is-active' : ''
            }${ s.number < currentStep ? ' is-completed' : '' }` }
          >
            <div className="mission-onboarding-step-dot">
              { s.number < currentStep ? checkSvg : s.number }
            </div>
            <span className="mission-onboarding-step-label">{ s.label }</span>
          </div>
        </Fragment>
      ) ) }
    </nav>
  );
}
