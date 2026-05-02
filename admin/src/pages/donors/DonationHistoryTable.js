import { useState } from '@wordpress/element';
import { formatDate } from '@shared/date';
import ClickableRows from '@shared/components/ClickableRows';
import Pagination from '@shared/components/Pagination';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';

const PER_PAGE = 10;

export default function DonationHistoryTable( { transactions } ) {
  const [ currentPage, setCurrentPage ] = useState( 1 );

  const totalPages = Math.ceil( transactions.length / PER_PAGE );
  const startIndex = ( currentPage - 1 ) * PER_PAGE;
  const paginatedTxns = transactions.slice( startIndex, startIndex + PER_PAGE );

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <h2 className="mission-card__heading">
        { __( 'Donation History', 'mission-donation-platform' ) }
      </h2>
      { ! transactions.length ? (
        <p className="mission-detail-table__empty">
          { __( 'No donations yet.', 'mission-donation-platform' ) }
        </p>
      ) : (
        <>
          <ClickableRows>
            <div className="mission-detail-table__overflow">
              <table className="mission-detail-table">
                <thead>
                  <tr>
                    <th>{ __( 'ID', 'mission-donation-platform' ) }</th>
                    <th>{ __( 'Date', 'mission-donation-platform' ) }</th>
                    <th>{ __( 'Amount', 'mission-donation-platform' ) }</th>
                    <th>{ __( 'Campaign', 'mission-donation-platform' ) }</th>
                    <th>{ __( 'Type', 'mission-donation-platform' ) }</th>
                    <th>{ __( 'Status', 'mission-donation-platform' ) }</th>
                  </tr>
                </thead>
                <tbody>
                  { paginatedTxns.map( ( txn ) => {
                    const isRecurring = [
                      'monthly',
                      'quarterly',
                      'annually',
                    ].includes( txn.type );
                    return (
                      <tr key={ txn.id }>
                        <td>
                          <a
                            href={ `${
                              window.missiondpAdmin?.adminUrl || ''
                            }admin.php?page=mission-donation-platform-transactions&transaction_id=${
                              txn.id
                            }` }
                            className="mission-detail-table__id"
                          >
                            { `#${ txn.id }` }
                          </a>
                        </td>
                        <td className="mission-detail-table__muted">
                          { formatDate( txn.date_created ) }
                        </td>
                        <td>
                          <span className="mission-detail-table__amount">
                            { formatAmount( txn.amount ) }
                          </span>
                        </td>
                        <td className="mission-detail-table__muted">
                          { txn.campaign_title || '\u2014' }
                        </td>
                        <td>
                          <span
                            className={ `mission-detail-badge${
                              isRecurring ? ' is-recurring' : ''
                            }` }
                          >
                            { isRecurring
                              ? __( 'Recurring', 'mission-donation-platform' )
                              : __( 'One-time', 'mission-donation-platform' ) }
                          </span>
                        </td>
                        <td>
                          <span
                            className={ `mission-status-badge is-${ txn.status }` }
                          >
                            { txn.status }
                          </span>
                        </td>
                      </tr>
                    );
                  } ) }
                </tbody>
              </table>
            </div>
          </ClickableRows>
          { totalPages > 1 && (
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
    </div>
  );
}
