/**
 * Donor Dashboard — Donation History state and actions.
 *
 * Handles history filters, pagination, and REST fetching.
 */
import { getContext } from '@wordpress/interactivity';
import { formatAmount } from '@shared/currency';

/**
 * Format a date string from the REST API into a short display format.
 *
 * @param {Object} txn Transaction object from REST.
 * @return {string} Formatted date string (e.g. "Mar 25, 2026").
 */
function formatTxnDate( txn ) {
  const dateStr = txn.date_completed || txn.date_created;
  if ( ! dateStr ) {
    return '';
  }
  const date = new Date( dateStr );
  return date.toLocaleDateString( undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  } );
}

/**
 * Map a REST transaction object to the shape used by the template context.
 *
 * @param {Object} txn      Raw transaction from REST.
 * @param {string} currency Currency code.
 * @return {Object} Prepared transaction for context.
 */
function prepareTxn( txn, currency ) {
  const isRecurring = txn.type !== 'one_time';
  return {
    id: txn.id,
    formattedAmount: formatAmount( txn.amount, currency ),
    formattedDate: formatTxnDate( txn ),
    campaignName: txn.campaign_name,
    status: txn.status,
    statusLabel: txn.status.charAt( 0 ).toUpperCase() + txn.status.slice( 1 ),
    isRecurring,
    typeLabel: isRecurring ? 'Recurring' : 'One-time',
  };
}

/**
 * Fetch history transactions from the REST API and update context.
 *
 * @param {Object} ctx The Interactivity API context.
 */
async function fetchHistory( ctx ) {
  const { history } = ctx;
  history.loading = true;

  const params = new URLSearchParams( {
    page: history.page,
    per_page: history.perPage,
  } );

  if ( history.filterYear ) {
    params.set( 'year', history.filterYear );
  }
  if ( history.filterCampaign ) {
    params.set( 'campaign_id', history.filterCampaign );
  }
  if ( history.filterType ) {
    params.set( 'type', history.filterType );
  }

  try {
    const response = await fetch(
      `${ ctx.restUrl }donor-dashboard/transactions?${ params }`,
      {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': ctx.nonce },
      }
    );

    if ( response.ok ) {
      const data = await response.json();
      const currency =
        data[ 0 ]?.currency?.toUpperCase() ||
        history.transactions[ 0 ]?.currency ||
        'USD';

      history.transactions = data.map( ( txn ) => prepareTxn( txn, currency ) );
      history.total = parseInt( response.headers.get( 'X-WP-Total' ) ) || 0;
      history.totalPages =
        parseInt( response.headers.get( 'X-WP-TotalPages' ) ) || 0;
    }
  } catch {
    // Silently fail — keep previous data visible.
  }

  history.loading = false;
}

export const historyState = {
  get historyIsEmpty() {
    const ctx = getContext();
    return ! ctx.history?.loading && ctx.history?.transactions?.length === 0;
  },
  get historyHasOnePage() {
    return ( getContext().history?.totalPages ?? 0 ) <= 1;
  },
  get historyIsFirstPage() {
    return ( getContext().history?.page ?? 1 ) <= 1;
  },
  get historyIsLastPage() {
    const ctx = getContext();
    return ( ctx.history?.page ?? 1 ) >= ( ctx.history?.totalPages ?? 1 );
  },
  get historyPaginationLabel() {
    const ctx = getContext();
    const page = ctx.history?.page ?? 1;
    const totalPages = ctx.history?.totalPages ?? 1;
    return `Page ${ page } of ${ totalPages }`;
  },

  // Per-row transaction status (used inside data-wp-each--txn).
  get txnIsCompleted() {
    return getContext().txn?.status === 'completed';
  },
  get txnIsPending() {
    return getContext().txn?.status === 'pending';
  },
  get txnIsRefunded() {
    return getContext().txn?.status === 'refunded';
  },
  get txnIsFailed() {
    return getContext().txn?.status === 'failed';
  },
};

export const historyActions = {
  // ── Filters ──
  *changeHistoryYear( event ) {
    const ctx = getContext();
    ctx.history.filterYear = event.target.value;
    ctx.history.page = 1;
    yield fetchHistory( ctx );
  },

  *changeHistoryCampaign( event ) {
    const ctx = getContext();
    ctx.history.filterCampaign = event.target.value;
    ctx.history.page = 1;
    yield fetchHistory( ctx );
  },

  *changeHistoryType( event ) {
    const ctx = getContext();
    ctx.history.filterType = event.target.value;
    ctx.history.page = 1;
    yield fetchHistory( ctx );
  },

  // ── Pagination ──
  *historyPrevPage() {
    const ctx = getContext();
    if ( ctx.history.page > 1 ) {
      ctx.history.page--;
      yield fetchHistory( ctx );
    }
  },

  *historyNextPage() {
    const ctx = getContext();
    if ( ctx.history.page < ctx.history.totalPages ) {
      ctx.history.page++;
      yield fetchHistory( ctx );
    }
  },
};
