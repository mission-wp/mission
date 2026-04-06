/**
 * Donor Dashboard — Editor component.
 */
import { useBlockProps } from '@wordpress/block-editor';

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
            <div className="mission-dd-editor-nav-item active">Overview</div>
            <div className="mission-dd-editor-nav-item">Donation History</div>
            <div className="mission-dd-editor-nav-item">
              Recurring Donations
            </div>
            <div className="mission-dd-editor-nav-item">Annual Receipts</div>
            <div className="mission-dd-editor-nav-item">Profile</div>
          </div>
        </div>
        <div className="mission-dd-editor-content">
          <div className="mission-dd-editor-title">Donor Dashboard</div>
          <p className="mission-dd-editor-desc">
            Donors will see a self-service portal here with their donation
            history, recurring gifts, tax receipts, and profile settings.
          </p>
          <div className="mission-dd-editor-stats">
            <div className="mission-dd-editor-stat">
              <span className="mission-dd-editor-stat-value">--</span>
              <span className="mission-dd-editor-stat-label">Donations</span>
            </div>
            <div className="mission-dd-editor-stat">
              <span className="mission-dd-editor-stat-value">--</span>
              <span className="mission-dd-editor-stat-label">
                Lifetime Given
              </span>
            </div>
            <div className="mission-dd-editor-stat">
              <span className="mission-dd-editor-stat-value">--</span>
              <span className="mission-dd-editor-stat-label">
                Avg. Donation
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
