import { __ } from '@wordpress/i18n';
import { formatDate } from '@shared/date';
import { formatAmount } from '@shared/currency';
import DetailCard from '../../components/DetailCard';
import {
  DetailRow,
  ExternalLinkIcon,
  dedicationLabel,
} from '../shared/DetailComponents';

/**
 * Fundraiser "Details" card: linked relations, page URL, goal, dedication.
 *
 * @param {Object} props            Component props.
 * @param {Object} props.fundraiser Fundraiser detail object.
 * @return {JSX.Element} The card.
 */
export default function FundraiserDetailsCard( { fundraiser } ) {
  const adminUrl = window.missiondpAdmin?.adminUrl || '';

  const dedication = dedicationLabel( fundraiser.dedication );

  return (
    <DetailCard title={ __( 'Details', 'mission-donation-platform' ) }>
      <div className="mission-detail-list">
        <DetailRow
          label={ __( 'Fundraiser', 'mission-donation-platform' ) }
          value={
            fundraiser.donor_id ? (
              <a
                href={ `${ adminUrl }admin.php?page=mission-donation-platform-donors&donor_id=${ fundraiser.donor_id }` }
                className="mission-table-link"
              >
                { fundraiser.donor_name }
              </a>
            ) : (
              fundraiser.donor_name
            )
          }
        />
        <DetailRow
          label={ __( 'Email', 'mission-donation-platform' ) }
          value={ fundraiser.donor_email }
        />
        <DetailRow
          label={ __( 'Campaign', 'mission-donation-platform' ) }
          value={
            fundraiser.campaign_id ? (
              <a
                href={ `${ adminUrl }admin.php?page=mission-donation-platform-campaigns&campaign=${ fundraiser.campaign_id }` }
                className="mission-table-link"
              >
                { fundraiser.campaign_title }
              </a>
            ) : null
          }
        />
        <DetailRow
          label={ __( 'Team', 'mission-donation-platform' ) }
          value={
            fundraiser.team_id ? (
              <>
                <a
                  href={ `${ adminUrl }admin.php?page=mission-donation-platform-teams&team_id=${ fundraiser.team_id }` }
                  className="mission-table-link"
                >
                  { fundraiser.team_name }
                </a>{ ' ' }
                { fundraiser.is_team_captain && (
                  <span className="mission-role-badge">
                    { __( 'Captain', 'mission-donation-platform' ) }
                  </span>
                ) }
              </>
            ) : null
          }
        />
        <DetailRow
          label={ __( 'Page URL', 'mission-donation-platform' ) }
          value={
            fundraiser.page_url ? (
              <a
                href={ fundraiser.page_url }
                target="_blank"
                rel="noreferrer"
                className="mission-table-link"
              >
                { fundraiser.page_url.replace( /^https?:\/\//, '' ) }
                <ExternalLinkIcon />
              </a>
            ) : null
          }
        />
        <DetailRow
          label={ __( 'Goal', 'mission-donation-platform' ) }
          value={ fundraiser.goal ? formatAmount( fundraiser.goal ) : null }
        />
        <DetailRow
          label={ __( 'Dedication', 'mission-donation-platform' ) }
          value={ dedication }
        />
        <DetailRow
          label={ __( 'Created', 'mission-donation-platform' ) }
          value={ formatDate( fundraiser.date_created ) }
          isLast
        />
      </div>
    </DetailCard>
  );
}
