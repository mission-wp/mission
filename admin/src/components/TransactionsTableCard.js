import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { formatDate } from '@shared/date';
import { formatAmount } from '@shared/currency';
import ClickableRows from '@shared/components/ClickableRows';
import Pagination from '@shared/components/Pagination';
import DetailCard from './DetailCard';
import StatusBadge from './StatusBadge';
import { isRecurringType } from '../constants';

const PER_PAGE = 10;

const adminUrl = () => window.missiondpAdmin?.adminUrl || '';

// Column registry keyed by the identifiers accepted in the `columns` prop.
const COLUMNS = {
  id: {
    header: () => __( 'ID', 'mission-donation-platform' ),
    render: ( txn ) => (
      <a
        href={ `${ adminUrl() }admin.php?page=mission-donation-platform-transactions&transaction_id=${
          txn.id
        }` }
        className="mission-detail-table__id"
      >
        { `#${ txn.id }` }
      </a>
    ),
  },
  date: {
    header: () => __( 'Date', 'mission-donation-platform' ),
    render: ( txn ) => (
      <span className="mission-detail-table__muted">
        { formatDate( txn.date_created ) }
      </span>
    ),
  },
  donor: {
    header: () => __( 'Donor', 'mission-donation-platform' ),
    render: ( txn ) =>
      txn.donor_id ? (
        <a
          href={ `${ adminUrl() }admin.php?page=mission-donation-platform-donors&donor_id=${
            txn.donor_id
          }` }
          className="mission-table-link"
        >
          { txn.donor_name }
        </a>
      ) : (
        <span className="mission-detail-table__muted">
          { txn.donor_name || __( 'Anonymous', 'mission-donation-platform' ) }
        </span>
      ),
  },
  campaign: {
    header: () => __( 'Campaign', 'mission-donation-platform' ),
    render: ( txn ) => (
      <span className="mission-detail-table__muted">
        { txn.campaign_title || '—' }
      </span>
    ),
  },
  fundraiser: {
    header: () => __( 'To', 'mission-donation-platform' ),
    render: ( txn ) =>
      txn.fundraiser_id ? (
        <a
          href={ `${ adminUrl() }admin.php?page=mission-donation-platform-fundraisers&fundraiser_id=${
            txn.fundraiser_id
          }` }
          className="mission-table-link"
        >
          { txn.fundraiser_name }
        </a>
      ) : (
        <span className="mission-detail-badge">
          { __( 'Direct to team', 'mission-donation-platform' ) }
        </span>
      ),
  },
  amount: {
    header: () => __( 'Amount', 'mission-donation-platform' ),
    render: ( txn ) => (
      <span className="mission-detail-table__amount">
        { formatAmount( txn.amount ) }
      </span>
    ),
  },
  type: {
    header: () => __( 'Type', 'mission-donation-platform' ),
    render: ( txn ) => {
      const isRecurring = isRecurringType( txn.type );
      return (
        <span
          className={ `mission-detail-badge${
            isRecurring ? ' is-recurring' : ''
          }` }
        >
          { isRecurring
            ? __( 'Recurring', 'mission-donation-platform' )
            : __( 'One-time', 'mission-donation-platform' ) }
        </span>
      );
    },
  },
  status: {
    header: () => __( 'Status', 'mission-donation-platform' ),
    render: ( txn ) => <StatusBadge status={ txn.status } />,
  },
};

/**
 * Transactions table card shared by donor, fundraiser, and team detail pages.
 *
 * With `collapsedCount`, the table shows the first N rows and a "Show all"
 * footer button; expanding switches to the full paginated list. Without it,
 * the table is always paginated.
 *
 * @param {Object}   props                Component props.
 * @param {string}   props.title          Card title.
 * @param {Array}    props.transactions   Transaction list items.
 * @param {string[]} props.columns        Column keys from the registry.
 * @param {number}   props.collapsedCount Optional initial row count before "Show all".
 * @param {string}   props.badge          Optional header count badge text.
 * @param {string}   props.emptyText      Optional empty-state text.
 * @return {JSX.Element} The card.
 */
export default function TransactionsTableCard( {
  title,
  transactions,
  columns,
  collapsedCount,
  badge,
  emptyText,
} ) {
  const [ currentPage, setCurrentPage ] = useState( 1 );
  const [ isExpanded, setIsExpanded ] = useState( ! collapsedCount );

  const isCollapsed = ! isExpanded && transactions.length > collapsedCount;
  const totalPages = Math.ceil( transactions.length / PER_PAGE );
  const startIndex = ( currentPage - 1 ) * PER_PAGE;
  const visibleTxns = isCollapsed
    ? transactions.slice( 0, collapsedCount )
    : transactions.slice( startIndex, startIndex + PER_PAGE );

  const cols = columns.map( ( key ) => COLUMNS[ key ] ).filter( Boolean );

  return (
    <DetailCard
      title={ title }
      badge={ badge }
      footer={
        isCollapsed ? (
          <button
            type="button"
            className="mission-card__footer-btn"
            onClick={ () => setIsExpanded( true ) }
          >
            { sprintf(
              /* translators: %d: total number of donations */
              __( 'Show all %d donations', 'mission-donation-platform' ),
              transactions.length
            ) }
          </button>
        ) : null
      }
    >
      { ! transactions.length ? (
        <p className="mission-detail-table__empty">
          { emptyText ||
            __( 'No donations yet.', 'mission-donation-platform' ) }
        </p>
      ) : (
        <>
          <ClickableRows>
            <div className="mission-detail-table__overflow">
              <table className="mission-detail-table">
                <thead>
                  <tr>
                    { cols.map( ( col, i ) => (
                      // eslint-disable-next-line react/no-array-index-key
                      <th key={ i }>{ col.header() }</th>
                    ) ) }
                  </tr>
                </thead>
                <tbody>
                  { visibleTxns.map( ( txn ) => (
                    <tr key={ txn.id }>
                      { cols.map( ( col, i ) => (
                        // eslint-disable-next-line react/no-array-index-key
                        <td key={ i }>{ col.render( txn ) }</td>
                      ) ) }
                    </tr>
                  ) ) }
                </tbody>
              </table>
            </div>
          </ClickableRows>
          { ! isCollapsed && totalPages > 1 && (
            <Pagination
              currentPage={ currentPage }
              totalPages={ totalPages }
              totalItems={ transactions.length }
              perPage={ PER_PAGE }
              onChange={ setCurrentPage }
            />
          ) }
        </>
      ) }
    </DetailCard>
  );
}
