import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDate } from '@shared/date';
import ClickableRows from '@shared/components/ClickableRows';

const STATUS_LABELS = {
  active: __( 'Active', 'missionwp-donation-platform' ),
  pending: __( 'Pending', 'missionwp-donation-platform' ),
  cancelled: __( 'Cancelled', 'missionwp-donation-platform' ),
  paused: __( 'Paused', 'missionwp-donation-platform' ),
  past_due: __( 'Past Due', 'missionwp-donation-platform' ),
};

const FREQUENCY_LABELS = {
  weekly: __( 'Weekly', 'missionwp-donation-platform' ),
  monthly: __( 'Monthly', 'missionwp-donation-platform' ),
  quarterly: __( 'Quarterly', 'missionwp-donation-platform' ),
  annually: __( 'Annually', 'missionwp-donation-platform' ),
};

export default function DonorSubscriptionsCard( { subscriptions } ) {
  const adminUrl = window.missionAdmin?.adminUrl || '';

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <h2 className="mission-card__heading">
        { __( 'Subscriptions', 'missionwp-donation-platform' ) }
      </h2>
      <ClickableRows>
        <div className="mission-detail-table__overflow">
          <table className="mission-detail-table">
            <thead>
              <tr>
                <th>{ __( 'ID', 'missionwp-donation-platform' ) }</th>
                <th>{ __( 'Amount', 'missionwp-donation-platform' ) }</th>
                <th>{ __( 'Frequency', 'missionwp-donation-platform' ) }</th>
                <th>{ __( 'Status', 'missionwp-donation-platform' ) }</th>
                <th>{ __( 'Created', 'missionwp-donation-platform' ) }</th>
              </tr>
            </thead>
            <tbody>
              { subscriptions.map( ( sub ) => (
                <tr key={ sub.id }>
                  <td>
                    <a
                      href={ `${ adminUrl }admin.php?page=mission-subscriptions&subscription_id=${ sub.id }` }
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
