import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Colored status dot indicator.
 *
 * @param {Object} props
 * @param {string} props.status One of 'ok', 'warn', 'err'.
 */
function StatusDot( { status } ) {
  return <span className={ `mission-status-dot mission-status-${ status }` } />;
}

/**
 * A single status section card with a header and key/value table.
 *
 * @param {Object} props
 * @param {string} props.title Section title.
 * @param {Array}  props.rows  Array of { label, value } objects.
 */
function StatusSection( { title, rows } ) {
  return (
    <div className="mission-settings-card mission-status-section">
      <div className="mission-status-section__header">
        <h3 className="mission-status-section__title">{ title }</h3>
      </div>
      <table className="mission-status-table">
        <tbody>
          { rows.map( ( row ) => (
            <tr key={ row.label }>
              <td className="mission-status-label">{ row.label }</td>
              <td className="mission-status-value">{ row.value }</td>
            </tr>
          ) ) }
        </tbody>
      </table>
    </div>
  );
}

function SkeletonBar( { width = '60%', height = '13px' } ) {
  return (
    <span
      className="mission-skeleton"
      style={ {
        display: 'block',
        width,
        height,
        borderRadius: '4px',
        background: '#e2e4e9',
      } }
    />
  );
}

/**
 * Labels for sections whose rows are fully known ahead of time.
 * Database tables and plugins are dynamic, so those use skeleton labels.
 */
const SKELETON_SECTIONS = [
  {
    title: __( 'Mission Environment', 'mission-donation-platform' ),
    labels: [
      __( 'Mission version', 'mission-donation-platform' ),
      __( 'Database version', 'mission-donation-platform' ),
      __( 'Stripe connection', 'mission-donation-platform' ),
      __( 'Webhook endpoint', 'mission-donation-platform' ),
      __( 'Test mode', 'mission-donation-platform' ),
      __( 'Currency', 'mission-donation-platform' ),
      __( 'Active campaigns', 'mission-donation-platform' ),
      __( 'Total donors', 'mission-donation-platform' ),
      __( 'Total transactions', 'mission-donation-platform' ),
      __( 'Log directory', 'mission-donation-platform' ),
    ],
  },
  {
    title: __( 'WordPress Environment', 'mission-donation-platform' ),
    labels: [
      __( 'Site URL', 'mission-donation-platform' ),
      __( 'Home URL', 'mission-donation-platform' ),
      __( 'WordPress version', 'mission-donation-platform' ),
      __( 'Multisite', 'mission-donation-platform' ),
      __( 'Memory limit', 'mission-donation-platform' ),
      __( 'Debug mode', 'mission-donation-platform' ),
      __( 'Cron', 'mission-donation-platform' ),
      __( 'Language', 'mission-donation-platform' ),
      __( 'Timezone', 'mission-donation-platform' ),
    ],
  },
  {
    title: __( 'Server Environment', 'mission-donation-platform' ),
    labels: [
      __( 'Server software', 'mission-donation-platform' ),
      __( 'PHP version', 'mission-donation-platform' ),
      __( 'PHP memory limit', 'mission-donation-platform' ),
      __( 'PHP max execution time', 'mission-donation-platform' ),
      __( 'PHP max input vars', 'mission-donation-platform' ),
      __( 'PHP max upload size', 'mission-donation-platform' ),
      __( 'MySQL version', 'mission-donation-platform' ),
      __( 'cURL version', 'mission-donation-platform' ),
      __( 'fsockopen / cURL', 'mission-donation-platform' ),
      'DOMDocument',
      'GZip',
    ],
  },
  { title: __( 'Database', 'mission-donation-platform' ), rows: 7 },
  { title: __( 'Active Plugins', 'mission-donation-platform' ), rows: 3 },
  { title: __( 'Theme', 'mission-donation-platform' ), rows: 5 },
];

// Varied widths so skeleton values don't all look the same.
const SKELETON_VALUE_WIDTHS = [
  '80px',
  '180px',
  '220px',
  '100px',
  '60px',
  '70px',
  '50px',
  '90px',
  '150px',
  '200px',
  '120px',
];

/**
 * Skeleton loading state for the status panel.
 *
 * Static text (headings, labels) renders immediately. Only dynamic
 * values show skeleton bars.
 */
function StatusSkeleton() {
  return (
    <div className="mission-settings-panel">
      { /* Report bar — fully static */ }
      <div className="mission-settings-card">
        <div className="mission-status-report-bar">
          <div>
            <div className="mission-settings-card__title">
              { __( 'System Status', 'mission-donation-platform' ) }
            </div>
            <p
              className="mission-settings-card__desc"
              style={ { marginTop: '2px' } }
            >
              { __(
                'Copy this report when contacting support.',
                'mission-donation-platform'
              ) }
            </p>
          </div>
          <button
            className="mission-settings-save-bar__btn"
            type="button"
            disabled
          >
            <svg
              width="14"
              height="14"
              viewBox="0 0 16 16"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <rect x="5" y="5" width="9" height="9" rx="1.5" />
              <path d="M3.5 11H3a1.5 1.5 0 0 1-1.5-1.5v-7A1.5 1.5 0 0 1 3 1h7a1.5 1.5 0 0 1 1.5 1.5V3" />
            </svg>
            { __( 'Copy system report', 'mission-donation-platform' ) }
          </button>
        </div>
      </div>

      { /* Section skeletons */ }
      { SKELETON_SECTIONS.map( ( section, i ) => {
        const hasLabels = Array.isArray( section.labels );
        const rowCount = hasLabels ? section.labels.length : section.rows;

        return (
          <div
            key={ i }
            className="mission-settings-card mission-status-section"
          >
            <div className="mission-status-section__header">
              <h3 className="mission-status-section__title">
                { section.title }
              </h3>
            </div>
            <table className="mission-status-table">
              <tbody>
                { Array.from( { length: rowCount }, ( _, j ) => (
                  <tr key={ j }>
                    <td className="mission-status-label">
                      { hasLabels ? (
                        section.labels[ j ]
                      ) : (
                        <SkeletonBar
                          width={ `${
                            80 + ( ( i * 7 + j * 13 ) % 6 ) * 15
                          }px` }
                        />
                      ) }
                    </td>
                    <td className="mission-status-value">
                      <SkeletonBar
                        width={
                          SKELETON_VALUE_WIDTHS[
                            ( i + j ) % SKELETON_VALUE_WIDTHS.length
                          ]
                        }
                      />
                    </td>
                  </tr>
                ) ) }
              </tbody>
            </table>
          </div>
        );
      } ) }
    </div>
  );
}

/**
 * Build the sections array from API data for rendering.
 *
 * @param {Object} data API response.
 * @return {Array} Array of { title, rows, key } objects.
 */
function buildSections( data ) {
  const { mission, wordpress, server, database, plugins, theme } = data;

  // --- Mission Environment ---
  const stripeStatus = mission.stripe_connected ? (
    <>
      <StatusDot status="ok" />
      { ' ' + __( 'Connected', 'mission-donation-platform' ) }
      <span className="mission-status-muted">
        { ` — ${ mission.stripe_account_id } (${ mission.stripe_mode } ${ __(
          'mode',
          'mission-donation-platform'
        ) })` }
      </span>
    </>
  ) : (
    <>
      <StatusDot status="err" />
      { ' ' + __( 'Not connected', 'mission-donation-platform' ) }
    </>
  );

  const webhookStatus = mission.stripe_webhook_configured ? (
    <>
      <StatusDot status="ok" />
      { ' ' + __( 'Active', 'mission-donation-platform' ) }
      <span className="mission-status-muted">{ ` — ${ mission.webhook_url }` }</span>
    </>
  ) : (
    <>
      <StatusDot status="err" />
      { ' ' + __( 'Not configured', 'mission-donation-platform' ) }
    </>
  );

  const missionRows = [
    {
      label: __( 'Mission version', 'mission-donation-platform' ),
      value: <code>{ mission.version }</code>,
    },
    {
      label: __( 'Database version', 'mission-donation-platform' ),
      value: <code>{ mission.db_version }</code>,
    },
    {
      label: __( 'Stripe connection', 'mission-donation-platform' ),
      value: stripeStatus,
    },
    {
      label: __( 'Webhook endpoint', 'mission-donation-platform' ),
      value: webhookStatus,
    },
    {
      label: __( 'Test mode', 'mission-donation-platform' ),
      value: mission.test_mode ? (
        <>
          <StatusDot status="warn" />{ ' ' }
          { __( 'Enabled', 'mission-donation-platform' ) }
        </>
      ) : (
        __( 'Disabled', 'mission-donation-platform' )
      ),
    },
    {
      label: __( 'Currency', 'mission-donation-platform' ),
      value: `${ mission.currency } (${ mission.currency_symbol })`,
    },
    {
      label: __( 'Active campaigns', 'mission-donation-platform' ),
      value: String( mission.active_campaigns ),
    },
    {
      label: __( 'Total donors', 'mission-donation-platform' ),
      value: Number( mission.total_donors ).toLocaleString(),
    },
    {
      label: __( 'Total transactions', 'mission-donation-platform' ),
      value: Number( mission.total_transactions ).toLocaleString(),
    },
  ];

  // --- WordPress Environment ---
  const wpRows = [
    {
      label: __( 'Site URL', 'mission-donation-platform' ),
      value: <code>{ wordpress.site_url }</code>,
    },
    {
      label: __( 'Home URL', 'mission-donation-platform' ),
      value: <code>{ wordpress.home_url }</code>,
    },
    {
      label: __( 'WordPress version', 'mission-donation-platform' ),
      value: (
        <>
          <StatusDot status="ok" /> { wordpress.version }
        </>
      ),
    },
    {
      label: __( 'Multisite', 'mission-donation-platform' ),
      value: wordpress.multisite
        ? __( 'Yes', 'mission-donation-platform' )
        : __( 'No', 'mission-donation-platform' ),
    },
    {
      label: __( 'Memory limit', 'mission-donation-platform' ),
      value: (
        <>
          <StatusDot status="ok" /> { wordpress.memory_limit }
        </>
      ),
    },
    {
      label: __( 'Debug mode', 'mission-donation-platform' ),
      value: wordpress.debug_mode
        ? __( 'Enabled', 'mission-donation-platform' )
        : __( 'Disabled', 'mission-donation-platform' ),
    },
    {
      label: __( 'Cron', 'mission-donation-platform' ),
      value: wordpress.cron ? (
        <>
          <StatusDot status="ok" />{ ' ' }
          { __( 'Enabled', 'mission-donation-platform' ) }
        </>
      ) : (
        <>
          <StatusDot status="err" />{ ' ' }
          { __( 'Disabled', 'mission-donation-platform' ) }
        </>
      ),
    },
    {
      label: __( 'Language', 'mission-donation-platform' ),
      value: wordpress.language,
    },
    {
      label: __( 'Timezone', 'mission-donation-platform' ),
      value: `${ wordpress.timezone } (${ wordpress.utc_offset })`,
    },
  ];

  // --- Server Environment ---
  const boolAvailable = ( val ) =>
    val ? (
      <>
        <StatusDot status="ok" />{ ' ' }
        { __( 'Available', 'mission-donation-platform' ) }
      </>
    ) : (
      <>
        <StatusDot status="err" />{ ' ' }
        { __( 'Not available', 'mission-donation-platform' ) }
      </>
    );

  const serverRows = [
    {
      label: __( 'Server software', 'mission-donation-platform' ),
      value: server.software,
    },
    {
      label: __( 'PHP version', 'mission-donation-platform' ),
      value: (
        <>
          <StatusDot status="ok" /> { server.php_version }
        </>
      ),
    },
    {
      label: __( 'PHP memory limit', 'mission-donation-platform' ),
      value: server.php_memory_limit,
    },
    {
      label: __( 'PHP max execution time', 'mission-donation-platform' ),
      value: `${ server.php_max_execution_time } ${ __(
        'seconds',
        'mission-donation-platform'
      ) }`,
    },
    {
      label: __( 'PHP max input vars', 'mission-donation-platform' ),
      value: String( server.php_max_input_vars ),
    },
    {
      label: __( 'PHP max upload size', 'mission-donation-platform' ),
      value: server.php_max_upload_size,
    },
    {
      label: __( 'MySQL version', 'mission-donation-platform' ),
      value: server.mysql_version,
    },
    {
      label: __( 'cURL version', 'mission-donation-platform' ),
      value: server.curl_version,
    },
    {
      label: __( 'fsockopen / cURL', 'mission-donation-platform' ),
      value: boolAvailable( server.fsockopen || server.curl ),
    },
    { label: 'DOMDocument', value: boolAvailable( server.domdocument ) },
    { label: 'GZip', value: boolAvailable( server.gzip ) },
  ];

  // --- Database ---
  const dbRows = [
    {
      label: __( 'Database prefix', 'mission-donation-platform' ),
      value: <code>{ database.prefix }</code>,
    },
    {
      label: __( 'Total database size', 'mission-donation-platform' ),
      value: `${ database.total_size } MB`,
    },
    {
      label: __( 'Mission tables size', 'mission-donation-platform' ),
      value: `${ database.mission_size } MB`,
    },
    ...database.tables.map( ( t ) => ( {
      label: t.name,
      value: (
        <span className="mission-status-muted">
          { `Data: ${ t.data_size } MB + Index: ${ t.index_size } MB` }
        </span>
      ),
    } ) ),
  ];

  // --- Active Plugins ---
  const pluginRows = plugins.map( ( p ) => ( {
    label: p.name,
    value: (
      <>
        { `${ __( 'by', 'mission-donation-platform' ) } ${ p.author } — ` }
        <code>{ p.version }</code>
      </>
    ),
  } ) );

  // --- Theme ---
  const themeRows = [
    { label: __( 'Name', 'mission-donation-platform' ), value: theme.name },
    {
      label: __( 'Version', 'mission-donation-platform' ),
      value: <code>{ theme.version }</code>,
    },
    {
      label: __( 'Author', 'mission-donation-platform' ),
      value: theme.author,
    },
    {
      label: __( 'Child theme', 'mission-donation-platform' ),
      value: theme.child_theme
        ? __( 'Yes', 'mission-donation-platform' )
        : __( 'No', 'mission-donation-platform' ),
    },
    {
      label: __( 'Block theme', 'mission-donation-platform' ),
      value: theme.block_theme ? (
        <>
          <StatusDot status="ok" /> { __( 'Yes', 'mission-donation-platform' ) }
        </>
      ) : (
        __( 'No', 'mission-donation-platform' )
      ),
    },
  ];

  return [
    {
      key: 'mission_donation_platform',
      title: __(
        'Mission Donation Platform Environment',
        'mission-donation-platform'
      ),
      rows: missionRows,
    },
    {
      key: 'wordpress',
      title: __( 'WordPress Environment', 'mission-donation-platform' ),
      rows: wpRows,
    },
    {
      key: 'server',
      title: __( 'Server Environment', 'mission-donation-platform' ),
      rows: serverRows,
    },
    {
      key: 'database',
      title: __( 'Database', 'mission-donation-platform' ),
      rows: dbRows,
    },
    {
      key: 'plugins',
      title: `${ __( 'Active Plugins', 'mission-donation-platform' ) } (${
        plugins.length
      })`,
      rows: pluginRows,
    },
    {
      key: 'theme',
      title: __( 'Theme', 'mission-donation-platform' ),
      rows: themeRows,
    },
  ];
}

/**
 * Build a plain-text report string from API data for clipboard copy.
 *
 * @param {Object} data API response.
 * @return {string} Plain text report.
 */
function buildPlainTextReport( data ) {
  const sections = buildSections( data );
  let report = '### Mission System Status Report ###\n\n';

  for ( const section of sections ) {
    report += `--- ${ section.title } ---\n`;

    for ( const row of section.rows ) {
      // Extract text content from React elements.
      const valueText = extractText( row.value );
      report += `${ row.label }: ${ valueText }\n`;
    }

    report += '\n';
  }

  return report.trim();
}

/**
 * Recursively extract text from a React element or string.
 *
 * @param {*} node React node.
 * @return {string} Plain text.
 */
function extractText( node ) {
  if ( node === null || node === undefined || typeof node === 'boolean' ) {
    return '';
  }
  if ( typeof node === 'string' || typeof node === 'number' ) {
    return String( node );
  }
  if ( Array.isArray( node ) ) {
    return node.map( extractText ).join( '' );
  }
  if ( node.props ) {
    // Skip status dot elements — they're visual only.
    if (
      node.props.className &&
      typeof node.props.className === 'string' &&
      node.props.className.includes( 'mission-status-dot' )
    ) {
      return '';
    }
    return extractText( node.props.children );
  }
  return '';
}

export default function StatusPanel() {
  const [ data, setData ] = useState( null );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ copied, setCopied ] = useState( false );
  const copyTimeoutRef = useRef( null );

  useEffect( () => {
    apiFetch( { path: '/mission-donation-platform/v1/system-status' } )
      .then( ( response ) => setData( response ) )
      .finally( () => setIsLoading( false ) );
  }, [] );

  useEffect( () => {
    return () => {
      if ( copyTimeoutRef.current ) {
        clearTimeout( copyTimeoutRef.current );
      }
    };
  }, [] );

  const handleCopy = () => {
    if ( ! data ) {
      return;
    }

    const report = buildPlainTextReport( data );

    window.navigator.clipboard.writeText( report ).then( () => {
      setCopied( true );
      copyTimeoutRef.current = setTimeout( () => setCopied( false ), 2000 );
    } );
  };

  if ( isLoading ) {
    return <StatusSkeleton />;
  }

  if ( ! data ) {
    return null;
  }

  const sections = buildSections( data );

  return (
    <div className="mission-settings-panel">
      { /* Copy system report bar */ }
      <div className="mission-settings-card">
        <div className="mission-status-report-bar">
          <div>
            <div className="mission-settings-card__title">
              { __( 'System Status', 'mission-donation-platform' ) }
            </div>
            <p
              className="mission-settings-card__desc"
              style={ { marginTop: '2px' } }
            >
              { __(
                'Copy this report when contacting support.',
                'mission-donation-platform'
              ) }
            </p>
          </div>
          <button
            className="mission-settings-save-bar__btn"
            type="button"
            onClick={ handleCopy }
          >
            { copied ? (
              <svg
                width="14"
                height="14"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <polyline points="3.5 8.5 6.5 11.5 12.5 4.5" />
              </svg>
            ) : (
              <svg
                width="14"
                height="14"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <rect x="5" y="5" width="9" height="9" rx="1.5" />
                <path d="M3.5 11H3a1.5 1.5 0 0 1-1.5-1.5v-7A1.5 1.5 0 0 1 3 1h7a1.5 1.5 0 0 1 1.5 1.5V3" />
              </svg>
            ) }
            { copied
              ? __( 'Copied!', 'mission-donation-platform' )
              : __( 'Copy system report', 'mission-donation-platform' ) }
          </button>
        </div>
      </div>

      { /* Status sections */ }
      { sections.map( ( section ) => (
        <StatusSection
          key={ section.key }
          title={ section.title }
          rows={ section.rows }
        />
      ) ) }
    </div>
  );
}
