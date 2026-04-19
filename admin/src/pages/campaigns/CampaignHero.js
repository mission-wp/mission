import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { external } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { StatusBadge } from './CampaignList';
import { formatAmount } from '@shared/currency';

export default function CampaignHero( { campaign, hasCampaignPage = true } ) {
  const [ copied, setCopied ] = useState( false );
  const hasPrettyUrl = campaign.url && ! campaign.url.includes( '?' );

  const goalProgress = campaign.goal_progress ?? campaign.total_raised;
  const gType = campaign.goal_type || 'amount';
  const isAmount = gType === 'amount';
  const percentage =
    campaign.goal_amount > 0
      ? Math.min(
          Math.round( ( goalProgress / campaign.goal_amount ) * 100 ),
          100
        )
      : 0;

  const handleCopyUrl = () => {
    if ( campaign.url ) {
      window.navigator.clipboard.writeText( campaign.url );
      setCopied( true );
      setTimeout( () => setCopied( false ), 2000 );
    }
  };

  return (
    <section className="mission-campaign-hero">
      <div className="mission-campaign-hero__layout">
        <div className="mission-campaign-hero__left">
          <div className="mission-campaign-hero__title">
            <h1>{ campaign.title }</h1>
            <StatusBadge status={ campaign.status } />
          </div>
          { campaign.excerpt && (
            <p className="mission-campaign-hero__desc">
              { campaign.excerpt.replace( /<[^>]+>/g, '' ) }
            </p>
          ) }
          { hasPrettyUrl && hasCampaignPage && (
            <div className="mission-campaign-hero__url">
              <span>{ campaign.url.replace( /^https?:\/\//, '' ) }</span>
              { copied ? (
                <span className="mission-campaign-hero__copied">
                  { __( 'Copied!', 'missionwp-donation-platform' ) }
                </span>
              ) : (
                <button
                  className="mission-campaign-hero__icon-btn"
                  onClick={ handleCopyUrl }
                  title={ __( 'Copy URL', 'missionwp-donation-platform' ) }
                  type="button"
                >
                  <svg
                    width="14"
                    height="14"
                    viewBox="0 0 14 14"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  >
                    <rect x="4.5" y="4.5" width="8" height="8" rx="1.5" />
                    <path d="M9.5 4.5V3a1.5 1.5 0 0 0-1.5-1.5H3A1.5 1.5 0 0 0 1.5 3v5A1.5 1.5 0 0 0 3 9.5h1.5" />
                  </svg>
                </button>
              ) }
            </div>
          ) }
        </div>
        <div className="mission-campaign-hero__right">
          { hasPrettyUrl && hasCampaignPage && (
            <Button
              variant="secondary"
              href={ campaign.url }
              target="_blank"
              rel="noopener noreferrer"
              icon={ external }
              style={ { textDecoration: 'none', flexShrink: 0 } }
            >
              { __( 'View Campaign Page', 'missionwp-donation-platform' ) }
            </Button>
          ) }
          { campaign.goal_amount > 0 && (
            <div className="mission-campaign-hero__progress">
              <div className="mission-campaign-hero__progress-raised">
                <span className="mission-campaign-hero__progress-amount">
                  { isAmount
                    ? formatAmount( goalProgress )
                    : goalProgress.toLocaleString() }
                </span>
                <span className="mission-campaign-hero__progress-goal">
                  { __( 'of', 'missionwp-donation-platform' ) }{ ' ' }
                  { isAmount
                    ? formatAmount( campaign.goal_amount )
                    : `${ campaign.goal_amount.toLocaleString() } ${
                        gType === 'donors'
                          ? __( 'donors', 'missionwp-donation-platform' )
                          : __( 'donations', 'missionwp-donation-platform' )
                      }` }
                </span>
              </div>
              <div className="mission-campaign-hero__progress-bar">
                <div
                  className="mission-campaign-hero__progress-fill"
                  style={ { '--bar-width': `${ percentage }%` } }
                />
              </div>
              <span className="mission-campaign-hero__progress-pct">
                { `${ percentage }% ${ __(
                  'funded',
                  'missionwp-donation-platform'
                ) }` }
              </span>
            </div>
          ) }
        </div>
      </div>
    </section>
  );
}
