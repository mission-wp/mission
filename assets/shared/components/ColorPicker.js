/**
 * Color picker with swatch preview and hex text input.
 *
 * @param {Object}   props
 * @param {string}   props.value    Current hex color value (e.g. "#2fa36b").
 * @param {Function} props.onChange Called with the new hex string on change.
 * @param {string=}  props.id       Optional ID for the hex text input.
 */
export default function ColorPicker( { value, onChange, id } ) {
  const color = value || '#2fa36b';

  return (
    <div className="mission-color-picker">
      <div
        className="mission-color-picker__swatch"
        style={ { background: color } }
      >
        <input
          type="color"
          value={ color }
          onChange={ ( e ) => onChange( e.target.value ) }
        />
      </div>
      <input
        type="text"
        className="mission-color-picker__hex"
        id={ id }
        value={ color }
        onChange={ ( e ) => onChange( e.target.value ) }
        maxLength={ 7 }
      />
    </div>
  );
}
