import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDate } from '@shared/date';
import ClickableRows from '@shared/components/ClickableRows';

const STATUS_LABELS = {
  active: __( 'Active', 'mission' ),
  pending: __( 'Pending', 'mission' ),
  cancelled: __( 'Cancelled', 'mission' ),
  paused: __( 'Paused', 'mission' ),
  past_due: __( 'Past Due', 'mission' ),
};

const FREQUENCY_LABELS = {
  weekly: __( 'Weekly', 'mission' ),
  monthly: __( 'Monthly', 'mission' ),
  quarterly: __( 'Quarterly', 'mission' ),
  annually: __( 'Annually', 'mission' ),
};

export default function DonorSubscriptionsCard( { subscriptions } ) {
  const adminUrl = window.missionAdmin?.adminUrl || '';

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <h2 className="mission-card__heading">
        { __( 'Subscriptions', 'mission' ) }
      </h2>
      <ClickableRows>
        <div className="mission-detail-table__overflow">
          <table className="mission-detail-table">
            <thead>
              <tr>
                <th>{ __( 'ID', 'mission' ) }</th>
                <th>{ __( 'Amount', 'mission' ) }</th>
                <th>{ __( 'Frequency', 'mission' ) }</th>
                <th>{ __( 'Status', 'mission' ) }</th>
                <th>{ __( 'Created', 'mission' ) }</th>
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
