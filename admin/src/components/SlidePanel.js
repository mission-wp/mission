import { useEffect, useState, useRef, createPortal } from '@wordpress/element';

export default function SlidePanel( {
  isOpen,
  onClose,
  className,
  label,
  children,
} ) {
  const panelRef = useRef( null );
  const [ mounted, setMounted ] = useState( false );
  const [ visible, setVisible ] = useState( false );

  // Mount first, then trigger the CSS transition on the next frame.
  useEffect( () => {
    if ( isOpen ) {
      setMounted( true );
      window.requestAnimationFrame( () => {
        window.requestAnimationFrame( () => setVisible( true ) );
      } );
    } else {
      setVisible( false );
    }
  }, [ isOpen ] );

  // Unmount after the slide-out transition ends.
  function handleTransitionEnd( e ) {
    if ( e.target === panelRef.current && ! isOpen ) {
      setMounted( false );
    }
  }

  // Close on Escape key.
  useEffect( () => {
    if ( ! isOpen ) {
      return;
    }

    function handleKeyDown( e ) {
      if ( e.key === 'Escape' ) {
        onClose();
      }
    }

    document.addEventListener( 'keydown', handleKeyDown );
    return () => document.removeEventListener( 'keydown', handleKeyDown );
  }, [ isOpen, onClose ] );

  // Prevent body scroll when open.
  useEffect( () => {
    if ( isOpen ) {
      document.body.style.overflow = 'hidden';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [ isOpen ] );

  if ( ! mounted ) {
    return null;
  }

  return createPortal(
    <>
      { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
      <div
        className={ `mission-drawer-backdrop${ visible ? ' is-open' : '' }` }
        onClick={ onClose }
      />
      <div
        ref={ panelRef }
        className={ `${ className }${ visible ? ' is-open' : '' }` }
        role="dialog"
        aria-label={ label }
        onTransitionEnd={ handleTransitionEnd }
      >
        { children }
      </div>
    </>,
    document.body
  );
}
