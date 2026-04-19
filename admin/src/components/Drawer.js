import { Button } from '@wordpress/components';
import { close as closeIcon } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

import SlidePanel from './SlidePanel';

export default function Drawer( {
  title,
  onClose,
  children,
  footer,
  isOpen,
  bodyRef,
} ) {
  return (
    <SlidePanel
      isOpen={ isOpen }
      onClose={ onClose }
      className="mission-drawer-panel"
      label={ title }
    >
      <div className="mission-drawer-header">
        <span style={ { fontSize: '16px', fontWeight: 600 } }>{ title }</span>
        <Button
          icon={ closeIcon }
          label={ __( 'Close', 'missionwp-donation-platform' ) }
          onClick={ onClose }
        />
      </div>
      <div ref={ bodyRef } className="mission-drawer-body">
        { children }
      </div>
      { footer && <div className="mission-drawer-footer">{ footer }</div> }
    </SlidePanel>
  );
}
