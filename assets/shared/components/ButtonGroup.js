/**
 * Segmented button group — a set of mutually exclusive options
 * rendered as connected pill buttons.
 *
 * @param {Object}   props             Component props.
 * @param {Array}    props.options     Array of { value, label }.
 * @param {string}   props.value       Currently selected value.
 * @param {Function} props.onChange    Called with the new value.
 * @param {string}   [props.className] Additional class name.
 */
export default function ButtonGroup( {
  options,
  value,
  onChange,
  className = '',
} ) {
  return (
    <div className={ `mission-button-group ${ className }`.trim() }>
      { options.map( ( opt ) => (
        <button
          key={ opt.value }
          type="button"
          className={ `mission-button-group__btn${
            value === opt.value ? ' is-active' : ''
          }` }
          onClick={ () => onChange( opt.value ) }
        >
          { opt.label }
        </button>
      ) ) }
    </div>
  );
}
