import { __ } from '@wordpress/i18n';
import { Icon, info } from '@wordpress/icons';

export default function TestModeBanner() {
  if ( ! window.missiondpAdmin?.testMode ) {
    return null;
  }

  const isSettingsPage =
    window.missiondpAdmin?.page ===
    'mission_page_mission-donation-platform-settings';
  const settingsUrl = `${ window.missiondpAdmin.adminUrl }admin.php?page=mission-donation-platform-settings`;

  return (
    <div className="mission-test-banner">
      <Icon icon={ info } size={ 20 } />
      <div className="mission-test-banner__text">
        <strong>{ __( 'Test Mode', 'mission-donation-platform' ) }</strong>
        { ' — ' }
        { __(
          'No real charges are being made. Stats shown are based on test transactions.',
          'mission-donation-platform'
        ) }
      </div>
      { ! isSettingsPage && (
        <a href={ settingsUrl } className="mission-test-banner__link">
          { __( 'Go Live', 'mission-donation-platform' ) }
        </a>
      ) }
    </div>
  );
}
