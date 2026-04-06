import { useCallback, useRef } from '@wordpress/element';

export default function ClickableRows( { children } ) {
  const ref = useRef( null );

  const handleClick = useCallback( ( e ) => {
    const row = e.target.closest( 'tbody tr' );
    if ( ! row ) {
      return;
    }
    const link = row.querySelector( 'a[href]' );
    if ( ! link || e.target.closest( 'a, button' ) ) {
      return;
    }
    window.location.href = link.href;
  }, [] );

  return (
    // eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
    <div ref={ ref } onClick={ handleClick } className="mission-clickable-rows">
      { children }
    </div>
  );
}
