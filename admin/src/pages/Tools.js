import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import CleanupPanel from './tools/CleanupPanel';
import ComingSoonPanel from './tools/ComingSoonPanel';
import ExportPanel from './tools/ExportPanel';
import LogsPanel from './tools/LogsPanel';
import StatusPanel from './tools/StatusPanel';

const TABS = [
  {
    id: 'export',
    label: __( 'Export', 'mission' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
        <polyline points="7 10 12 15 17 10" />
        <line x1="12" y1="15" x2="12" y2="3" />
      </svg>
    ),
  },
  {
    id: 'import',
    label: __( 'Import', 'mission' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
        <polyline points="17 8 12 3 7 8" />
        <line x1="12" y1="3" x2="12" y2="15" />
      </svg>
    ),
  },
  {
    id: 'migration',
    label: __( 'Migration', 'mission' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <polyline points="15 3 21 3 21 9" />
        <path d="M21 3l-7 7" />
        <polyline points="9 21 3 21 3 15" />
        <path d="M3 21l7-7" />
      </svg>
    ),
  },
  {
    id: 'features',
    label: __( 'Features', 'mission' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <rect x="14" y="14" width="7" height="7" rx="1" />
      </svg>
    ),
  },
  {
    id: 'logs',
    label: __( 'Logs', 'mission' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <polyline points="14 2 14 8 20 8" />
        <line x1="16" y1="13" x2="8" y2="13" />
        <line x1="16" y1="17" x2="8" y2="17" />
        <polyline points="10 9 9 9 8 9" />
      </svg>
    ),
  },
  {
    id: 'status',
    label: __( 'Status', 'mission' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <circle cx="12" cy="12" r="10" />
        <line x1="12" y1="8" x2="12" y2="12" />
        <line x1="12" y1="16" x2="12.01" y2="16" />
      </svg>
    ),
  },
  {
    id: 'cleanup',
    label: __( 'Cleanup', 'mission' ),
    icon: (
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <polyline points="3 6 5 6 21 6" />
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
        <path d="M10 11v6" />
        <path d="M14 11v6" />
        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
      </svg>
    ),
  },
];

const STORAGE_KEY = 'mission_tools_tab';

function getInitialTab() {
  try {
    const stored = window.localStorage.getItem( STORAGE_KEY );
    if ( stored && TABS.some( ( t ) => t.id === stored ) ) {
      return stored;
    }
  } catch {}
  return 'export';
}

export default function Tools() {
  const [ activeTab, setActiveTab ] = useState( getInitialTab );

  const handleTabChange = ( tabId ) => {
    setActiveTab( tabId );
    try {
      window.localStorage.setItem( STORAGE_KEY, tabId );
    } catch {}
  };

  return (
    <div className="mission-admin-page">
      <div style={ { marginBottom: '24px' } }>
        <h1 style={ { fontSize: '24px', fontWeight: 600, margin: 0 } }>
          { __( 'Tools', 'mission' ) }
        </h1>
        <p style={ { fontSize: '13px', color: '#9b9ba8', margin: '4px 0 0' } }>
          { __( 'Utilities and configuration for Mission', 'mission' ) }
        </p>
      </div>

      <div className="mission-settings-layout">
        <nav className="mission-settings-nav">
          <ul className="mission-settings-nav__list">
            { TABS.map( ( tab ) => (
              <li key={ tab.id }>
                <button
                  className={ `mission-settings-nav__item${
                    activeTab === tab.id ? ' is-active' : ''
                  }` }
                  onClick={ () => handleTabChange( tab.id ) }
                  type="button"
                >
                  { tab.icon }
                  { tab.label }
                </button>
              </li>
            ) ) }
          </ul>
        </nav>

        <div>
          { activeTab === 'export' && <ExportPanel /> }

          { [ 'import', 'migration', 'features' ].includes( activeTab ) && (
            <ComingSoonPanel tabId={ activeTab } />
          ) }

          { activeTab === 'logs' && <LogsPanel /> }

          { activeTab === 'status' && <StatusPanel /> }

          { activeTab === 'cleanup' && <CleanupPanel /> }
        </div>
      </div>
    </div>
  );
}
