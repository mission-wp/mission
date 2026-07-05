/**
 * Card shell shared by detail-page sections.
 *
 * Renders a flush (padding-less) card with a title header, an optional count
 * badge or action node on the right of the header, and an optional footer
 * region (e.g. a "Show all N" button).
 *
 * @param {Object}      props          Component props.
 * @param {string}      props.title    Card title.
 * @param {string}      props.badge    Optional count badge text.
 * @param {JSX.Element} props.action   Optional header action (e.g. icon button).
 * @param {JSX.Element} props.footer   Optional footer content.
 * @param {JSX.Element} props.children Card body.
 * @return {JSX.Element} The card.
 */
export default function DetailCard( {
  title,
  badge,
  action,
  footer,
  children,
} ) {
  const hasHeaderSide = Boolean( badge || action );

  return (
    <div className="mission-card mission-card--flush">
      { hasHeaderSide ? (
        <div className="mission-card__header-row">
          <h2>{ title }</h2>
          <div className="mission-card__header-side">
            { badge && (
              <span className="mission-card__count-badge">{ badge }</span>
            ) }
            { action }
          </div>
        </div>
      ) : (
        <h2 className="mission-card__heading">{ title }</h2>
      ) }
      { children }
      { footer && <div className="mission-card__footer">{ footer }</div> }
    </div>
  );
}
