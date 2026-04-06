import { useState, useEffect } from '@wordpress/element';

export const TOAST_DURATION = 3500;
const DISMISS_LEAD = 300; // matches CSS animation duration

export default function Toast( { notice, onDone } ) {
  const [ dismissing, setDismissing ] = useState( false );

  useEffect( () => {
    if ( ! notice ) {
      return;
    }
    const timer = setTimeout( () => setDismissing( true ), TOAST_DURATION );
    return () => clearTimeout( timer );
  }, [ notice ] );

  useEffect( () => {
    if ( ! dismissing ) {
      return;
    }
    const timer = setTimeout( onDone, DISMISS_LEAD );
    return () => clearTimeout( timer );
  }, [ dismissing, onDone ] );

  if ( ! notice ) {
    return null;
  }

  const icon =
    notice.type === 'success' ? (
      <svg
        width="16"
        height="16"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M3.5 8.5l3 3 6-7" />
      </svg>
    ) : (
      <svg
        width="16"
        height="16"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <circle cx="8" cy="8" r="6" />
        <path d="M8 5v3M8 10.5v.5" />
      </svg>
    );

  return (
    <div
      className={ `mission-toast is-${ notice.type }${
        dismissing ? ' is-dismissing' : ''
      }` }
    >
      <span className="mission-toast__icon">{ icon }</span>
      { notice.message }
    </div>
  );
}
