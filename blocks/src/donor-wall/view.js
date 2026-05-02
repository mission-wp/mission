/**
 * Donor Wall — Interactivity API store.
 *
 * Handles sort changes, load more pagination, and comment expand/collapse.
 */
/* global navigator */
import { store, getContext } from '@wordpress/interactivity';

const SORT_MAP = {
  recent: { orderby: 'date_completed', order: 'DESC' },
  highest: { orderby: 'amount', order: 'DESC' },
  earliest: { orderby: 'date_completed', order: 'ASC' },
};

const FREQUENCY_SUFFIX = {
  monthly: '/mo',
  quarterly: '/qtr',
  annually: '/yr',
};

const FREQUENCY_LABELS = {
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  annually: 'Annually',
};

/**
 * Format a minor-unit amount to a display string.
 *
 * @param {number} amount   Amount in minor units (cents).
 * @param {string} currency ISO currency code.
 * @return {string} Formatted amount.
 */
function formatAmount( amount, currency = 'USD' ) {
  const major = amount / 100;
  try {
    return new Intl.NumberFormat( navigator.language || 'en-US', {
      style: 'currency',
      currency: currency.toUpperCase(),
      minimumFractionDigits: Number.isInteger( major ) ? 0 : 2,
      maximumFractionDigits: 2,
    } ).format( major );
  } catch {
    return `$${ major.toFixed( 2 ) }`;
  }
}

/**
 * Format a date string to a relative or absolute display.
 *
 * @param {string} dateStr Date string.
 * @return {string} Formatted date.
 */
function formatDate( dateStr ) {
  if ( ! dateStr ) {
    return '';
  }

  const date = new Date( dateStr.replace( ' ', 'T' ) + 'Z' );
  const now = new Date();
  const diffMs = now - date;
  const diffHours = Math.floor( diffMs / ( 1000 * 60 * 60 ) );

  if ( diffHours < 1 ) {
    return 'Just now';
  }
  if ( diffHours < 24 ) {
    return `${ diffHours } hour${ diffHours !== 1 ? 's' : '' } ago`;
  }

  const diffDays = Math.floor( diffMs / ( 1000 * 60 * 60 * 24 ) );

  if ( diffDays < 7 ) {
    return `${ diffDays } day${ diffDays !== 1 ? 's' : '' } ago`;
  }

  return date.toLocaleDateString( navigator.language || 'en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  } );
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
 * @return {Array} Enriched items.
 */
function enrichItems( items, currency, startIndex = 0, commentLength = 150 ) {
  return items.map( ( item, i ) => {
    const suffix =
      item.type !== 'one_time' && FREQUENCY_SUFFIX[ item.type ]
        ? FREQUENCY_SUFFIX[ item.type ]
        : '';
    const frequencyLabel =
      item.type !== 'one_time' ? FREQUENCY_LABELS[ item.type ] || '' : '';
    const comment = item.comment || '';
    const isTruncated = comment.length > commentLength;
    return {
      ...item,
      formattedAmount: formatAmount( item.amount, currency || 'USD' ) + suffix,
      formattedDate: formatDate( item.date ),
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
      return `Showing ${ showing } of ${ ctx.total }`;
    },
  },
  callbacks: {
    init() {
      const ctx = getContext();
      // Enrich server-rendered items with computed properties.
      if ( ctx.items && ctx.items.length && ! ctx.items[ 0 ].formattedAmount ) {
        const currency = ctx.items[ 0 ]?.currency || 'USD';
        ctx.items = enrichItems( ctx.items, currency, 0, ctx.commentLength );
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

        ctx.items = enrichItems( data.items, currency, 0, ctx.commentLength );
        ctx.total = data.total;
        ctx.page = 1;
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
          ctx.commentLength
        );

        ctx.items = [ ...ctx.items, ...newItems ];
        ctx.total = data.total;
        ctx.page = nextPage;
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

      // Toggle between truncated and full comment.
      if ( donor.displayComment === donor.comment ) {
        donor.displayComment =
          donor.comment.substring( 0, ctx.commentLength ) + '\u2026';
      } else {
        donor.displayComment = donor.comment;
      }
    },
  },
} );
