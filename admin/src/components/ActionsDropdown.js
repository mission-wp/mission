import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * "Actions" dropdown shared by detail screens.
 *
 * Owns the open state and outside-click dismissal; callers supply the menu
 * as a flat list of items. An item is either an action:
 * `{ label, icon, onClick, isDanger, disabled }` (icon is an optional JSX
 * node) or a separator: `{ divider: true }`.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label Toggle label (defaults to "Actions").
 * @param {Array}  props.items Menu items.
 * @return {JSX.Element} The dropdown.
 */
export default function ActionsDropdown( { label, items } ) {
  const [ isOpen, setIsOpen ] = useState( false );
  const ref = useRef();

  useEffect( () => {
    if ( ! isOpen ) {
      return;
    }
    const close = ( e ) => {
      if ( ref.current && ! ref.current.contains( e.target ) ) {
        setIsOpen( false );
      }
    };
    document.addEventListener( 'click', close, true );
    return () => document.removeEventListener( 'click', close, true );
  }, [ isOpen ] );

  return (
    <div className="mission-dropdown" ref={ ref }>
      <button
        className="mission-dropdown__toggle"
        onClick={ () => setIsOpen( ! isOpen ) }
      >
        { label || __( 'Actions', 'mission-donation-platform' ) }
        <svg
          width="10"
          height="6"
          viewBox="0 0 10 6"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M1 1l4 4 4-4" />
        </svg>
      </button>
      { isOpen && (
        <div className="mission-dropdown__menu">
          { items.map( ( item, index ) =>
            item.divider ? (
              <div
                key={ `divider-${ index }` }
                className="mission-dropdown__divider"
              />
            ) : (
              <button
                key={ item.label }
                className={
                  item.isDanger
                    ? 'mission-dropdown__item mission-dropdown__item--danger'
                    : 'mission-dropdown__item'
                }
                disabled={ item.disabled }
                onClick={ () => {
                  setIsOpen( false );
                  item.onClick();
                } }
              >
                { item.icon }
                { item.label }
              </button>
            )
          ) }
        </div>
      ) }
    </div>
  );
}
