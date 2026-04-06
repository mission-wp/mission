export function DetailRow( { label, value, isLast } ) {
  return (
    <div
      className="mission-detail-row"
      style={ isLast ? { borderBottom: 'none' } : undefined }
    >
      <span className="mission-detail-row__label">{ label }</span>
      <span className="mission-detail-row__value">{ value || '\u2014' }</span>
    </div>
  );
}

export function Chevron() {
  return (
    <svg
      className="mission-detail-section__chevron"
      width="12"
      height="12"
      viewBox="0 0 12 12"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M3 5l3 3 3-3" />
    </svg>
  );
}

export function ExternalLinkIcon() {
  return (
    <svg
      width="10"
      height="10"
      viewBox="0 0 14 14"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
      style={ { marginLeft: '4px', verticalAlign: 'middle' } }
    >
      <path d="M11 7.5v4a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h4" />
      <path d="M7 7L12.5 1.5M10 1h3v3" />
    </svg>
  );
}

export function InfoTooltip( { text } ) {
  return (
    <span className="mission-info-tooltip" data-tip={ text }>
      <svg
        width="13"
        height="13"
        viewBox="0 0 14 14"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <circle cx="7" cy="7" r="6" />
        <path d="M7 9.5V7M7 4.5h0" />
      </svg>
    </span>
  );
}

export function formatAddress( donor ) {
  if ( ! donor ) {
    return null;
  }

  const parts = [];

  if ( donor.address_1 ) {
    parts.push( donor.address_1 );
  }
  if ( donor.address_2 ) {
    parts.push( donor.address_2 );
  }

  const cityLine = [ donor.city, donor.state ].filter( Boolean ).join( ', ' );
  if ( cityLine && donor.zip ) {
    parts.push( `${ cityLine } ${ donor.zip }` );
  } else if ( cityLine || donor.zip ) {
    parts.push( cityLine || donor.zip );
  }

  if ( donor.country ) {
    const regionNames = new Intl.DisplayNames( undefined, { type: 'region' } );
    try {
      parts.push( regionNames.of( donor.country ) );
    } catch {
      parts.push( donor.country );
    }
  }

  return parts.length > 0 ? parts : null;
}
