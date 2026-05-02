import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDate } from '@shared/date';
import ClickableRows from '@shared/components/ClickableRows';

const STATUS_LABELS = {
  active: __( 'Active', 'mission-donation-platform' ),
  pending: __( 'Pending', 'mission-donation-platform' ),
  cancelled: __( 'Cancelled', 'mission-donation-platform' ),
  paused: __( 'Paused', 'mission-donation-platform' ),
  past_due: __( 'Past Due', 'mission-donation-platform' ),
};

const FREQUENCY_LABELS = {
  weekly: __( 'Weekly', 'mission-donation-platform' ),
  monthly: __( 'Monthly', 'mission-donation-platform' ),
  quarterly: __( 'Quarterly', 'mission-donation-platform' ),
  annually: __( 'Annually', 'mission-donation-platform' ),
};

export default function DonorSubscriptionsCard( { subscriptions } ) {
  const adminUrl = window.missiondpAdmin?.adminUrl || '';

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <h2 className="mission-card__heading">
        { __( 'Subscriptions', 'mission-donation-platform' ) }
      </h2>
      <ClickableRows>
        <div className="mission-detail-table__overflow">
          <table className="mission-detail-table">
            <thead>
              <tr>
                <th>{ __( 'ID', 'mission-donation-platform' ) }</th>
                <th>{ __( 'Amount', 'mission-donation-platform' ) }</th>
                <th>{ __( 'Frequency', 'mission-donation-platform' ) }</th>
                <th>{ __( 'Status', 'mission-donation-platform' ) }</th>
                <th>{ __( 'Created', 'mission-donation-platform' ) }</th>
              </tr>
            </thead>
            <tbody>
              { subscriptions.map( ( sub ) => (
                <tr key={ sub.id }>
                  <td>
                    <a
                      href={ `${ adminUrl }admin.php?page=mission-donation-platform-subscriptions&subscription_id=${ sub.id }` }
                      className="mission-detail-table__id"
                    >
                      #{ sub.id }
                    </a>
                  </td>
                  <td>
                    <span className="mission-detail-table__amount">
                      { formatAmount( sub.amount, sub.currency ) }
                    </span>
                  </td>
                  <td>
                    { FREQUENCY_LABELS[ sub.frequency ] || sub.frequency }
                  </td>
                  <td>
                    <span
                      className={ `mission-status-badge is-${ sub.status }` }
                    >
                      { STATUS_LABELS[ sub.status ] || sub.status }
                    </span>
                  </td>
                  <td className="mission-detail-table__muted">
                    { formatDate( sub.date_created ) }
                  </td>
                </tr>
              ) ) }
            </tbody>
          </table>
        </div>
      </ClickableRows>
    </div>
  );
}
