export function getInitials( firstName, lastName ) {
  const first = firstName?.charAt( 0 ) || '';
  const last = lastName?.charAt( 0 ) || '';
  return ( first + last ).toUpperCase() || '?';
}

export default function DonorAvatar( {
  firstName,
  lastName,
  gravatarHash,
  size,
} ) {
  const initials = getInitials( firstName, lastName );
  const sizeClass = size ? `mission-donor-avatar--${ size }` : '';
  const baseClass = `mission-donor-avatar ${ sizeClass }`.trim();

  if ( ! gravatarHash ) {
    return <span className={ baseClass }>{ initials }</span>;
  }

  const gravatarUrl = `https://www.gravatar.com/avatar/${ gravatarHash }?s=80&d=blank`;

  return (
    <span className={ baseClass } aria-hidden="true">
      { initials }
      <img className="mission-donor-avatar__img" src={ gravatarUrl } alt="" />
    </span>
  );
}
