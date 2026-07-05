import { __, sprintf } from '@wordpress/i18n';
import { formatDate } from '@shared/date';
import { formatAmount } from '@shared/currency';
import DetailCard from '../../components/DetailCard';
import { DetailRow, ExternalLinkIcon } from '../shared/DetailComponents';

/**
 * Team "Details" card: campaign, captain, goal, visibility, URL, rank.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.team Team detail object.
 * @return {JSX.Element} The card.
 */
export default function TeamDetailsCard( { team } ) {
  const adminUrl = window.missiondpAdmin?.adminUrl || '';

  return (
    <DetailCard title={ __( 'Details', 'mission-donation-platform' ) }>
      <div className="mission-detail-list">
        <DetailRow
          label={ __( 'Campaign', 'mission-donation-platform' ) }
          value={
            team.campaign_id ? (
              <a
                href={ `${ adminUrl }admin.php?page=mission-donation-platform-campaigns&campaign=${ team.campaign_id }` }
                className="mission-table-link"
              >
                { team.campaign_title }
              </a>
            ) : null
          }
        />
        <DetailRow
          label={ __( 'Captain', 'mission-donation-platform' ) }
          value={
            team.captain_id ? (
              <a
                href={ `${ adminUrl }admin.php?page=mission-donation-platform-fundraisers&fundraiser_id=${ team.captain_id }` }
                className="mission-table-link"
              >
                { team.captain_name }
              </a>
            ) : null
          }
        />
        <DetailRow
          label={ __( 'Team goal', 'mission-donation-platform' ) }
          value={ team.goal ? formatAmount( team.goal ) : null }
        />
        <DetailRow
          label={ __( 'Visibility', 'mission-donation-platform' ) }
          value={
            team.access === 'private'
              ? __( 'Private', 'mission-donation-platform' )
              : __( 'Public', 'mission-donation-platform' )
          }
        />
        <DetailRow
          label={ __( 'Team URL', 'mission-donation-platform' ) }
          value={
            team.page_url ? (
              <a
                href={ team.page_url }
                target="_blank"
                rel="noreferrer"
                className="mission-table-link"
              >
                { team.page_url.replace( /^https?:\/\//, '' ) }
                <ExternalLinkIcon />
              </a>
            ) : null
          }
        />
        <DetailRow
          label={ __( 'Leaderboard', 'mission-donation-platform' ) }
          value={
            team.rank
              ? sprintf(
                  /* translators: 1: leaderboard rank, 2: total number of teams */
                  __( '#%1$d of %2$d teams', 'mission-donation-platform' ),
                  team.rank,
                  team.rank_total
                )
              : null
          }
        />
        <DetailRow
          label={ __( 'Created', 'mission-donation-platform' ) }
          value={ formatDate( team.date_created ) }
          isLast
        />
      </div>
    </DetailCard>
  );
}
