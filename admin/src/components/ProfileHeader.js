import SkeletonBar from '@shared/components/SkeletonBar';

/**
 * Profile header card shared by donor, fundraiser, and team detail pages.
 *
 * Left side: optional avatar, name, subtitle, and a row of badge/chip nodes.
 * Right side: up to a few big stats. `isLoading` renders skeleton shapes
 * matching the layout.
 *
 * @param {Object}      props           Component props.
 * @param {JSX.Element} props.avatar    Optional avatar node.
 * @param {string}      props.name      Heading text.
 * @param {string}      props.subtitle  Secondary line under the name.
 * @param {JSX.Element} props.badges    Badge/chip nodes for the tag row.
 * @param {Array}       props.stats     Stats: array of { value, label }.
 * @param {boolean}     props.isLoading Render skeleton placeholders.
 * @return {JSX.Element} The profile header.
 */
export default function ProfileHeader( {
  avatar,
  name,
  subtitle,
  badges,
  stats = [],
  isLoading,
} ) {
  if ( isLoading ) {
    return (
      <div className="mission-profile">
        <div className="mission-profile__main">
          { avatar && (
            <SkeletonBar
              width="72px"
              height="72px"
              style={ { borderRadius: '50%' } }
            />
          ) }
          <div className="mission-profile__info">
            <SkeletonBar width="220px" height="24px" />
            <div style={ { marginTop: '8px' } }>
              <SkeletonBar width="150px" height="14px" />
            </div>
          </div>
        </div>
        <div className="mission-profile__stats">
          { [ 0, 1, 2 ].map( ( i ) => (
            <div key={ i } className="mission-profile__stat">
              <SkeletonBar width="64px" height="24px" />
            </div>
          ) ) }
        </div>
      </div>
    );
  }

  return (
    <div className="mission-profile">
      <div className="mission-profile__main">
        { avatar }
        <div className="mission-profile__info">
          <h1 className="mission-profile__name">{ name }</h1>
          { subtitle && (
            <p className="mission-profile__subtitle">{ subtitle }</p>
          ) }
          { badges && <div className="mission-profile__tags">{ badges }</div> }
        </div>
      </div>
      { stats.length > 0 && (
        <div className="mission-profile__stats">
          { stats.map( ( stat ) => (
            <div key={ stat.label } className="mission-profile__stat">
              <span className="mission-profile__stat-value">
                { stat.value }
              </span>
              <span className="mission-profile__stat-label">
                { stat.label }
              </span>
            </div>
          ) ) }
        </div>
      ) }
    </div>
  );
}
