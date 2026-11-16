/**
 * Donor Wall — Interactivity API store.
 *
 * Handles sort changes, load more pagination, and comment expand/collapse.
 */
import { store, getContext } from '@wordpress/interactivity';
import { formatAmount } from '@shared/currency';

const SORT_MAP = {
  recent: { orderby: 'date_completed', order: 'DESC' },
  highest: { orderby: 'amount', order: 'DESC' },
  earliest: { orderby: 'date_completed', order: 'ASC' },
};

/**
 * Recompute whether the footer (load more + count) should be hidden.
 *
 * Mirrors the server-side calculation in index.php; context rather than
 * derived state so the server can evaluate it when rendering.
 *
 * @param {Object} ctx Store context.
 */
function updateFooterHidden( ctx ) {
  ctx.footerHidden = ctx.total <= ctx.items.length && ctx.total <= ctx.perPage;
}

/**
 * Build the API URL with query parameters.
 *
 * @param {Object} ctx  Store context.
 * @param {number} page Page number.
 * @return {string} Full URL.
 */
function buildUrl( ctx, page ) {
  const params = SORT_MAP[ ctx.sort ] || SORT_MAP.recent;
  const url = new URL( ctx.restUrl, window.location.origin );
  url.searchParams.set( 'campaign_id', ctx.campaignId );
  url.searchParams.set( 'per_page', ctx.perPage );
  url.searchParams.set( 'page', page );
  url.searchParams.set( 'orderby', params.orderby );
  url.searchParams.set( 'order', params.order );
  url.searchParams.set( 'show_anonymous', ctx.showAnonymous ? '1' : '0' );
  return url.toString();
}

/**
 * Enrich items with computed display properties.
 *
 * @param {Array}  items         Raw API items.
 * @param {string} currency      Currency code.
 * @param {number} startIndex    Index offset for staggered animation delay.
 * @param {number} commentLength Max comment length before truncation.
 * @param {Object} i18n          Translated labels from the server.
 * @return {Array} Enriched items.
 */
function enrichItems(
  items,
  currency,
  startIndex = 0,
  commentLength = 150,
  i18n = {}
) {
  return items.map( ( item, i ) => {
    const isRecurring = item.type !== 'one_time';
    const suffix = isRecurring ? i18n.suffixes?.[ item.type ] || '' : '';
    const frequencyLabel = isRecurring
      ? i18n.frequencies?.[ item.type ] || ''
      : '';
    const comment = item.comment || '';
    const isTruncated = comment.length > commentLength;
    return {
      ...item,
      formattedAmount:
        formatAmount( item.amount, currency || 'USD', {
          stripZeroCents: true,
        } ) + suffix,
      formattedDate: item.formatted_date || '',
      frequencyLabel,
      gravatarSrc: item.gravatar_hash
        ? `https://www.gravatar.com/avatar/${ item.gravatar_hash }?s=96&d=blank`
        : '',
      animDelay: `${ ( ( startIndex + i ) * 0.05 ).toFixed( 2 ) }s`,
      displayComment: isTruncated
        ? comment.substring( 0, commentLength ) + '\u2026'
        : comment,
      isTruncated,
    };
  } );
}

const { state } = store( 'mission-donation-platform/donor-wall', {
  state: {
    get hasMore() {
      const ctx = getContext();
      return ctx.items.length < ctx.total;
    },
    get showingText() {
      const ctx = getContext();
      const showing = Math.min( ctx.items.length, ctx.total );
      return ( ctx.i18n?.showing || '' )
        .replace( '%1$s', showing )
        .replace( '%2$s', ctx.total );
    },
  },
  callbacks: {
    init() {
      const ctx = getContext();
      if ( ctx.items && ctx.items.length && ! ctx.items[ 0 ].formattedAmount ) {
        const currency = ctx.items[ 0 ]?.currency || 'USD';
        ctx.items = enrichItems(
          ctx.items,
          currency,
          0,
          ctx.commentLength,
          ctx.i18n
        );
      }
    },
  },
  actions: {
    *changeSort( event ) {
      const ctx = getContext();
      const newSort = event.target.value;

      if ( newSort === ctx.sort ) {
        return;
      }

      ctx.sort = newSort;
      ctx.isLoading = true;

      try {
        const url = buildUrl( ctx, 1 );
        const response = yield fetch( url, {
          headers: { 'X-WP-Nonce': ctx.nonce },
        } );
        const data = yield response.json();
        const currency =
          data.items[ 0 ]?.currency || ctx.items[ 0 ]?.currency || 'USD';

        ctx.items = enrichItems(
          data.items,
          currency,
          0,
          ctx.commentLength,
          ctx.i18n
        );
        ctx.total = data.total;
        ctx.page = 1;
        updateFooterHidden( ctx );
      } catch {
        // Silently fail — keep existing data.
      } finally {
        ctx.isLoading = false;
      }
    },
    *loadMore() {
      const ctx = getContext();

      if ( ctx.isLoading || ! state.hasMore ) {
        return;
      }

      ctx.isLoading = true;
      const nextPage = ctx.page + 1;

      try {
        const url = buildUrl( ctx, nextPage );
        const response = yield fetch( url, {
          headers: { 'X-WP-Nonce': ctx.nonce },
        } );
        const data = yield response.json();
        const currency =
          data.items[ 0 ]?.currency || ctx.items[ 0 ]?.currency || 'USD';
        const newItems = enrichItems(
          data.items,
          currency,
          0,
          ctx.commentLength,
          ctx.i18n
        );

        ctx.items = [ ...ctx.items, ...newItems ];
        ctx.total = data.total;
        ctx.page = nextPage;
        updateFooterHidden( ctx );
      } catch {
        // Silently fail — keep existing data.
      } finally {
        ctx.isLoading = false;
      }
    },
    toggleComment() {
      const ctx = getContext();
      const donor = ctx.donor;

      if ( ! donor.isTruncated ) {
        return;
      }

      if ( donor.displayComment === donor.comment ) {
        donor.displayComment =
          donor.comment.substring( 0, ctx.commentLength ) + '\u2026';
      } else {
        donor.displayComment = donor.comment;
      }
    },
  },
} );
