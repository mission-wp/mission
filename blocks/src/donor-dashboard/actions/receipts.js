/**
 * Donor Dashboard — Receipt download actions.
 *
 * Handles annual and per-transaction PDF receipt downloads.
 */
import { getContext } from '@wordpress/interactivity';

export const receiptsActions = {
  /**
   * Download an annual receipt PDF for the current year row.
   *
   * Context: called from within a `data-wp-each--receipt` loop,
   * so `ctx.receipt.year` is the year to download.
   */
  downloadAnnualReceipt() {
    const ctx = getContext();
    const { year } = ctx.receipt;
    const url = `${ ctx.restUrl }donor-dashboard/receipts/${ year }/pdf?_wpnonce=${ ctx.nonce }`;
    window.open( url, '_blank' );
  },

  /**
   * Download a single-transaction receipt PDF.
   *
   * Context: called from within a `data-wp-each--txn` loop,
   * so `ctx.txn.id` is the transaction ID.
   */
  downloadTransactionReceipt() {
    const ctx = getContext();
    const { id } = ctx.txn;
    const url = `${ ctx.restUrl }donor-dashboard/transactions/${ id }/receipt?_wpnonce=${ ctx.nonce }`;
    window.open( url, '_blank' );
  },
};
