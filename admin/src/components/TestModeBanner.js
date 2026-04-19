import { __ } from '@wordpress/i18n';
import { Icon, info } from '@wordpress/icons';

export default function TestModeBanner() {
  if ( ! window.missionAdmin?.testMode ) {
    return null;
  }

  const isSettingsPage =
    window.missionAdmin?.page === 'missionwp_page_mission-settings';
  const settingsUrl = `${ window.missionAdmin.adminUrl }admin.php?page=mission-settings`;

  return (
    <div className="mission-test-banner">
      <Icon icon={ info } size={ 20 } />
      <div className="mission-test-banner__text">
        <strong>{ __( 'Test Mode', 'missionwp-donation-platform' ) }</strong>
        { ' — ' }
        { __(
          'No real charges are being made. Stats shown are based on test transactions.',
          'missionwp-donation-platform'
        ) }
      </div>
      { ! isSettingsPage && (
        <a href={ settingsUrl } className="mission-test-banner__link">
          { __( 'Go Live', 'missionwp-donation-platform' ) }
        </a>
      ) }
    </div>
  );
}
