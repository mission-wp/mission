export function CheckIcon( { size = 11 } ) {
  return (
    <svg
      width={ size }
      height={ size }
      viewBox="0 0 12 12"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M2.5 6.4 5 8.8 9.5 3.6" />
    </svg>
  );
}

export function InfoIcon( { size = 16 } ) {
  return (
    <svg
      width={ size }
      height={ size }
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <circle cx="8" cy="8" r="6.5" />
      <path d="M8 10.5V8M8 5.5h.005" />
    </svg>
  );
}

export function WarningIcon( { size = 16 } ) {
  return (
    <svg
      width={ size }
      height={ size }
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M8 1.6L1 14h14L8 1.6z" />
      <path d="M8 6v3.5M8 12h.005" />
    </svg>
  );
}

export function ArrowRightIcon( { size = 14 } ) {
  return (
    <svg
      width={ size }
      height={ size }
      viewBox="0 0 14 14"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M3 7h8M7 3l4 4-4 4" />
    </svg>
  );
}

export function SourceIcon( { source, size = 40 } ) {
  const pluginUrl = window.missiondpAdmin?.pluginUrl ?? '';

  return (
    <span
      className={ `mission-migration-source-icon${
        source === 'donorbox' ? ' is-square' : ''
      }` }
      style={ { width: size, height: size } }
    >
      <img
        src={ `${ pluginUrl }assets/img/icon-${ source }.svg` }
        alt=""
        width={ size }
        height={ size }
      />
    </span>
  );
}
