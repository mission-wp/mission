export default function EmptyState( { icon, text, hint, action } ) {
  return (
    <div className="mission-empty-state">
      { icon && <div className="mission-empty-state__icon">{ icon }</div> }
      <p className="mission-empty-state__text">{ text }</p>
      { hint && <p className="mission-empty-state__hint">{ hint }</p> }
      { action && (
        <div className="mission-empty-state__action">{ action }</div>
      ) }
    </div>
  );
}
