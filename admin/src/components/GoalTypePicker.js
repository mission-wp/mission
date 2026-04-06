import { __ } from '@wordpress/i18n';

const DollarIcon = () => (
  <svg
    width="24"
    height="24"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <line x1="12" y1="1" x2="12" y2="23" />
    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
  </svg>
);

const HeartIcon = () => (
  <svg
    width="24"
    height="24"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
  </svg>
);

const UsersIcon = () => (
  <svg
    width="24"
    height="24"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
    <circle cx="9" cy="7" r="4" />
    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
  </svg>
);

const GOAL_TYPES = [
  {
    value: 'amount',
    label: __( 'Amount Raised', 'mission' ),
    icon: DollarIcon,
  },
  {
    value: 'donations',
    label: __( 'Number of Donations', 'mission' ),
    icon: HeartIcon,
  },
  {
    value: 'donors',
    label: __( 'Number of Donors', 'mission' ),
    icon: UsersIcon,
  },
];

export default function GoalTypePicker( { value, onChange } ) {
  return (
    <div className="mission-goal-type-picker">
      { GOAL_TYPES.map( ( type ) => {
        const isActive = value === type.value;
        return (
          <button
            key={ type.value }
            type="button"
            className={ `mission-goal-type-picker__option${
              isActive ? ' is-active' : ''
            }` }
            onClick={ () => onChange( type.value ) }
          >
            <span className="mission-goal-type-picker__icon">
              <type.icon />
            </span>
            <span className="mission-goal-type-picker__label">
              { type.label }
            </span>
          </button>
        );
      } ) }
    </div>
  );
}
