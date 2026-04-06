export default function ToggleRow( { checked, onChange, label, hint, style } ) {
  return (
    <div className="mission-toggle-row" style={ style }>
      { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
      <label className="mission-toggle-switch" aria-label={ label }>
        <input
          type="checkbox"
          checked={ checked }
          onChange={ ( e ) => onChange( e.target.checked ) }
        />
        <span className="mission-toggle-switch__slider" />
      </label>
      { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
      <div
        onClick={ () => onChange( ! checked ) }
        style={ { cursor: 'pointer' } }
      >
        <div className="mission-toggle-row__label">{ label }</div>
        { hint && <div className="mission-toggle-row__hint">{ hint }</div> }
      </div>
    </div>
  );
}
