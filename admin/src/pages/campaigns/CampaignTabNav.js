import { __ } from '@wordpress/i18n';

const TABS = [
  { id: 'overview', label: __( 'Overview', 'mission-donation-platform' ) },
  { id: 'edit-page', label: __( 'Edit Page', 'mission-donation-platform' ) },
  { id: 'settings', label: __( 'Settings', 'mission-donation-platform' ) },
];

export default function CampaignTabNav( { activeTab, onTabChange } ) {
  return (
    <nav className="mission-tab-nav">
      { TABS.map( ( tab ) => (
        <button
          key={ tab.id }
          className={ `mission-tab-nav__item${
            activeTab === tab.id ? ' is-active' : ''
          }` }
          onClick={ () => onTabChange( tab.id ) }
          type="button"
        >
          { tab.label }
        </button>
      ) ) }
    </nav>
  );
}
