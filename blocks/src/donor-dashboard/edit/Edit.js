/**
 * Donor Dashboard — Editor component.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit() {
  const blockProps = useBlockProps( {
    className: 'mission-donor-dashboard-editor',
  } );

  return (
    <div { ...blockProps }>
      <div className="mission-dd-editor-preview">
        <div className="mission-dd-editor-sidebar">
          <div className="mission-dd-editor-avatar">
            <svg
              width="24"
              height="24"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
              <circle cx="12" cy="7" r="4" />
            </svg>
          </div>
          <div className="mission-dd-editor-nav">
            <div className="mission-dd-editor-nav-item active">
              { __( 'Overview', 'mission-donation-platform' ) }
            </div>
            <div className="mission-dd-editor-nav-item">
              { __( 'Donation History', 'mission-donation-platform' ) }
            </div>
            <div className="mission-dd-editor-nav-item">
              { __( 'Recurring Donations', 'mission-donation-platform' ) }
            </div>
            <div className="mission-dd-editor-nav-item">
              { __( 'Annual Receipts', 'mission-donation-platform' ) }
            </div>
            <div className="mission-dd-editor-nav-item">
              { __( 'Profile', 'mission-donation-platform' ) }
            </div>
          </div>
        </div>
        <div className="mission-dd-editor-content">
          <div className="mission-dd-editor-title">
            { __( 'Donor Dashboard', 'mission-donation-platform' ) }
          </div>
          <p className="mission-dd-editor-desc">
            { __(
              'Donors will see a self-service portal here with their donation history, recurring gifts, tax receipts, and profile settings.',
              'mission-donation-platform'
            ) }
          </p>
          <div className="mission-dd-editor-stats">
            <div className="mission-dd-editor-stat">
              <span className="mission-dd-editor-stat-value">--</span>
              <span className="mission-dd-editor-stat-label">
                { __( 'Donations', 'mission-donation-platform' ) }
              </span>
            </div>
            <div className="mission-dd-editor-stat">
              <span className="mission-dd-editor-stat-value">--</span>
              <span className="mission-dd-editor-stat-label">
                { __( 'Lifetime Given', 'mission-donation-platform' ) }
              </span>
            </div>
            <div className="mission-dd-editor-stat">
              <span className="mission-dd-editor-stat-value">--</span>
              <span className="mission-dd-editor-stat-label">
                { __( 'Avg. Donation', 'mission-donation-platform' ) }
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
