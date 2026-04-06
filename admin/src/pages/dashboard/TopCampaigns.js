import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import EmptyState from '../../components/EmptyState';

const BAR_COLORS = [ '#2fa36b', '#5a7a64', '#4a7a9b', '#8b6e4e', '#7a5a8e' ];

const ChartIcon = () => (
  <svg
    width="40"
    height="40"
    viewBox="0 0 40 40"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <rect x="6" y="20" width="6" height="14" rx="1" />
    <rect x="17" y="12" width="6" height="22" rx="1" />
    <rect x="28" y="6" width="6" height="28" rx="1" />
  </svg>
);

export default function TopCampaigns( { campaigns, isLoading } ) {
  const campaignsUrl = window.missionAdmin?.adminUrl
    ? `${ window.missionAdmin.adminUrl }admin.php?page=mission-campaigns`
    : '#';

  return (
    <div className="mission-dashboard-card">
      <div className="mission-dashboard-card__header">
        <h2>{ __( 'Top Campaigns', 'mission' ) }</h2>
        <span className="mission-dashboard-card__badge">
          { __( 'By amount raised', 'mission' ) }
        </span>
      </div>

      { ! isLoading && ( ! campaigns || campaigns.length === 0 ) ? (
        <EmptyState
          icon={ <ChartIcon /> }
          text={ __( 'No campaigns yet', 'mission' ) }
          hint={ __(
            'Create a campaign to start tracking donations.',
            'mission'
          ) }
        />
      ) : (
        <div>
          { ( isLoading ? Array( 3 ).fill( null ) : campaigns ).map(
            ( campaign, i ) => {
              if ( isLoading ) {
                return (
                  <div key={ i } className="mission-campaign-bar">
                    <div className="mission-campaign-bar__header">
                      <span
                        className="mission-skeleton"
                        style={ {
                          width: '120px',
                          height: '14px',
                          display: 'inline-block',
                          borderRadius: '4px',
                          background: '#eee9e3',
                        } }
                      />
                      <span
                        className="mission-skeleton"
                        style={ {
                          width: '60px',
                          height: '14px',
                          display: 'inline-block',
                          borderRadius: '4px',
                          background: '#eee9e3',
                        } }
                      />
                    </div>
                    <div className="mission-campaign-bar__track">
                      <div
                        className="mission-skeleton"
                        style={ {
                          width: '60%',
                          height: '100%',
                          borderRadius: '4px',
                          background: '#eee9e3',
                        } }
                      />
                    </div>
                  </div>
                );
              }

              const progress = campaign.goal_progress ?? campaign.total_raised;
              const pct = campaign.goal_amount
                ? Math.min(
                    Math.round( ( progress / campaign.goal_amount ) * 100 ),
                    100
                  )
                : 0;

              return (
                <div key={ campaign.id } className="mission-campaign-bar">
                  <div className="mission-campaign-bar__header">
                    <span className="mission-campaign-bar__name">
                      { campaign.title }
                    </span>
                    <span className="mission-campaign-bar__amount">
                      { formatAmount( campaign.total_raised ) }
                    </span>
                  </div>
                  <div className="mission-campaign-bar__track">
                    <div
                      className="mission-campaign-bar__fill"
                      style={ {
                        '--bar-width': `${ pct }%`,
                        background: BAR_COLORS[ i % BAR_COLORS.length ],
                      } }
                    />
                  </div>
                </div>
              );
            }
          ) }
        </div>
      ) }

      <div className="mission-campaigns-footer">
        <a href={ campaignsUrl } className="mission-campaigns-footer__btn">
          { __( 'View all campaigns', 'mission' ) }
        </a>
      </div>
    </div>
  );
}
