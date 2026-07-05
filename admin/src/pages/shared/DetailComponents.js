import { __, sprintf } from '@wordpress/i18n';

export function DetailRow( { label, value, addLabel, onAdd, isLast } ) {
  let content;
  if ( value ) {
    content = <span className="mission-detail-row__value">{ value }</span>;
  } else if ( addLabel ) {
    content = (
      <button
        type="button"
        className="mission-detail-row__add"
        onClick={ onAdd }
      >
        { addLabel }
      </button>
    );
  } else {
    content = <span className="mission-detail-row__value">{ '\u2014' }</span>;
  }

  return (
    <div
      className="mission-detail-row"
      style={ isLast ? { borderBottom: 'none' } : undefined }
    >
      <span className="mission-detail-row__label">{ label }</span>
      { content }
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

/**
 * 14x14 stroke icon used by dropdown menu items.
 *
 * @param {Object}      props          Component props.
 * @param {JSX.Element} props.children SVG path content.
 * @return {JSX.Element} The icon.
 */
export function MenuIcon( { children } ) {
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 14 14"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      { children }
    </svg>
  );
}

export function MailIcon() {
  return (
    <MenuIcon>
      <path d="M1 3.5l6 4 6-4" />
      <rect x="1" y="2" width="12" height="10" rx="1.5" />
    </MenuIcon>
  );
}

export function RefundIcon() {
  return (
    <MenuIcon>
      <path d="M2 7h8M6 3l-4 4 4 4" />
    </MenuIcon>
  );
}

export function DownloadIcon() {
  return (
    <MenuIcon>
      <path d="M7 1v9M4 7l3 3 3-3" />
      <path d="M1 11v1.5a.5.5 0 0 0 .5.5h11a.5.5 0 0 0 .5-.5V11" />
    </MenuIcon>
  );
}

export function FileIcon() {
  return (
    <MenuIcon>
      <path d="M8 1H3.5A1.5 1.5 0 0 0 2 2.5v9A1.5 1.5 0 0 0 3.5 13h7a1.5 1.5 0 0 0 1.5-1.5V5L8 1z" />
      <path d="M8 1v4h4" />
    </MenuIcon>
  );
}

export function TrashIcon() {
  return (
    <MenuIcon>
      <path d="M2 3.5h10M4.5 3.5V2.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v1M10 3.5l-.5 8.5a1 1 0 0 1-1 1H5.5a1 1 0 0 1-1-1L4 3.5" />
    </MenuIcon>
  );
}

export function CopyIcon() {
  return (
    <MenuIcon>
      <rect x="5" y="5" width="8" height="8" rx="1.5" />
      <path d="M9 5V2.5A1.5 1.5 0 0 0 7.5 1h-5A1.5 1.5 0 0 0 1 2.5v5A1.5 1.5 0 0 0 2.5 9H5" />
    </MenuIcon>
  );
}

export function EyeOffIcon() {
  return (
    <MenuIcon>
      <path d="M1 7s2-4.5 6-4.5S13 7 13 7s-2 4.5-6 4.5S1 7 1 7z" />
      <path d="M2 2l10 10" />
    </MenuIcon>
  );
}

export function CheckIcon() {
  return (
    <MenuIcon>
      <path d="M2 7.5L5.5 11 12 3" />
    </MenuIcon>
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

/**
 * Human-readable dedication line ("In memory of Jane Smith").
 *
 * @param {Object|null} dedication Dedication: { type: 'honor'|'memory', name }.
 * @return {string} The label, or '' without a dedication.
 */
export function dedicationLabel( dedication ) {
  if ( ! dedication?.name ) {
    return '';
  }

  if ( dedication.type === 'memory' ) {
    return sprintf(
      /* translators: %s: honoree name */
      __( 'In memory of %s', 'mission-donation-platform' ),
      dedication.name
    );
  }

  return sprintf(
    /* translators: %s: honoree name */
    __( 'In honor of %s', 'mission-donation-platform' ),
    dedication.name
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
