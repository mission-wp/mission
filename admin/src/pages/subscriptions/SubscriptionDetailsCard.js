import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDate, formatDateTime } from '@shared/date';
import ClickableRows from '@shared/components/ClickableRows';
import Pagination from '@shared/components/Pagination';
import {
  DetailRow,
  Chevron,
  ExternalLinkIcon,
  InfoTooltip,
  formatAddress,
} from '../shared/DetailComponents';
import { CampaignDropdown } from '../transactions/TransactionDetailsCard';

const FREQUENCY_LABELS = {
  weekly: __( 'Weekly', 'mission' ),
  monthly: __( 'Monthly', 'mission' ),
  quarterly: __( 'Quarterly', 'mission' ),
  annually: __( 'Annually', 'mission' ),
};

const FREQUENCY_SUFFIXES = {
  weekly: __( '/wk', 'mission' ),
  monthly: __( '/mo', 'mission' ),
  quarterly: __( '/qtr', 'mission' ),
  annually: __( '/yr', 'mission' ),
};

const STATUS_OPTIONS = [
  {
    value: 'active',
    label: __( 'Active', 'mission' ),
    backgroundColor: 'rgba(47, 163, 107, 0.12)',
    color: '#278f5c',
  },
  {
    value: 'pending',
    label: __( 'Pending', 'mission' ),
    backgroundColor: '#e4eff5',
    color: '#4a7a9b',
  },
  {
    value: 'past_due',
    label: __( 'Past Due', 'mission' ),
    backgroundColor: '#fdf8ef',
    color: '#b8860b',
  },
  {
    value: 'paused',
    label: __( 'Paused', 'mission' ),
    backgroundColor: '#ebebed',
    color: '#82828c',
  },
  {
    value: 'cancelled',
    label: __( 'Cancelled', 'mission' ),
    backgroundColor: '#f0eeeb',
    color: '#8a7e72',
  },
];

const TRANSACTION_STATUS_OPTIONS = [
  {
    value: 'completed',
    label: __( 'Completed', 'mission' ),
    backgroundColor: 'rgba(47, 163, 107, 0.12)',
    color: '#278f5c',
  },
  {
    value: 'pending',
    label: __( 'Pending', 'mission' ),
    backgroundColor: '#e4eff5',
    color: '#4a7a9b',
  },
  {
    value: 'refunded',
    label: __( 'Refunded', 'mission' ),
    backgroundColor: '#f5e8e8',
    color: '#b85c5c',
  },
  {
    value: 'failed',
    label: __( 'Failed', 'mission' ),
    backgroundColor: '#fce8e8',
    color: '#c0392b',
  },
  {
    value: 'cancelled',
    label: __( 'Cancelled', 'mission' ),
    backgroundColor: '#f0eeeb',
    color: '#8a7e72',
  },
];

function StatusBadge( { status, options = STATUS_OPTIONS } ) {
  const current = options.find( ( o ) => o.value === status ) || options[ 1 ];
  return (
    <span
      className="mission-status-badge"
      style={ {
        backgroundColor: current.backgroundColor,
        color: current.color,
      } }
    >
      { current.label }
    </span>
  );
}

function StatusDropdown( { status, onChange } ) {
  const [ isOpen, setIsOpen ] = useState( false );
  const ref = useRef();
  const current =
    STATUS_OPTIONS.find( ( o ) => o.value === status ) || STATUS_OPTIONS[ 1 ];

  useEffect( () => {
    if ( ! isOpen ) {
      return;
    }
    const close = ( e ) => {
      if ( ref.current && ! ref.current.contains( e.target ) ) {
        setIsOpen( false );
      }
    };
    document.addEventListener( 'click', close, true );
    return () => document.removeEventListener( 'click', close, true );
  }, [ isOpen ] );

  return (
    <div className="mission-detail-dropdown" ref={ ref }>
      <button
        className="mission-detail-dropdown__toggle"
        onClick={ () => setIsOpen( ! isOpen ) }
        style={ {
          backgroundColor: current.backgroundColor,
          color: current.color,
        } }
      >
        { current.label }
        <svg
          width="8"
          height="5"
          viewBox="0 0 8 5"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M1 1l3 3 3-3" />
        </svg>
      </button>
      { isOpen && (
        <div className="mission-detail-dropdown__menu">
          { STATUS_OPTIONS.map( ( opt ) => (
            <button
              key={ opt.value }
              className={ `mission-detail-dropdown__item${
                opt.value === status
                  ? ' mission-detail-dropdown__item--active'
                  : ''
              }` }
              onClick={ () => {
                onChange( opt.value );
                setIsOpen( false );
              } }
            >
              <span
                className="mission-detail-dropdown__dot"
                style={ { backgroundColor: opt.color } }
              />
              { opt.label }
            </button>
          ) ) }
        </div>
      ) }
    </div>
  );
}

const TXN_PER_PAGE = 10;

function PaymentHistorySection( {
  transactions,
  currency,
  totalGiven,
  adminUrl,
  currentPage,
  onPageChange,
} ) {
  const totalPages = Math.ceil( transactions.length / TXN_PER_PAGE );
  const startIndex = ( currentPage - 1 ) * TXN_PER_PAGE;
  const paginated = transactions.slice( startIndex, startIndex + TXN_PER_PAGE );

  return (
    <details className="mission-detail-section" open>
      <summary className="mission-detail-section__header">
        <h3 className="mission-detail-section__title">
          { __( 'Payment History', 'mission' ) }
          <span className="mission-detail-section__badge">
            { formatAmount( totalGiven, currency ) }{ ' ' }
            { __( 'total', 'mission' ) }
          </span>
        </h3>
        <Chevron />
      </summary>
      <ClickableRows>
        <div className="mission-detail-table__overflow">
          <table className="mission-detail-table">
            <thead>
              <tr>
                <th>{ __( 'Date', 'mission' ) }</th>
                <th>{ __( 'Amount', 'mission' ) }</th>
                <th>{ __( 'Transaction', 'mission' ) }</th>
                <th>{ __( 'Status', 'mission' ) }</th>
              </tr>
            </thead>
            <tbody>
              { paginated.map( ( txn ) => (
                <tr key={ txn.id }>
                  <td className="mission-detail-table__muted">
                    { formatDate( txn.date_completed || txn.date_created ) }
                  </td>
                  <td>
                    <span className="mission-detail-table__amount">
                      { formatAmount( txn.amount, txn.currency || currency ) }
                    </span>
                  </td>
                  <td>
                    <a
                      href={ `${ adminUrl }admin.php?page=mission-transactions&transaction_id=${ txn.id }` }
                      className="mission-detail-table__id"
                    >
                      #{ txn.id }
                    </a>
                  </td>
                  <td>
                    <StatusBadge
                      status={ txn.status }
                      options={ TRANSACTION_STATUS_OPTIONS }
                    />
                  </td>
                </tr>
              ) ) }
            </tbody>
          </table>
        </div>
      </ClickableRows>
      { totalPages > 1 && (
        <Pagination
          currentPage={ currentPage }
          totalPages={ totalPages }
          totalItems={ transactions.length }
          perPage={ TXN_PER_PAGE }
          onChange={ onPageChange }
        />
      ) }
    </details>
  );
}

export default function SubscriptionDetailsCard( {
  subscription,
  campaigns,
  onCampaignChange,
  onStatusChange,
} ) {
  const [ txnPage, setTxnPage ] = useState( 1 );
  const adminUrl = window.missionAdmin?.adminUrl || '';
  const s = subscription;
  const donor = s.donor;
  const campaign = s.campaign;

  const donorName = donor
    ? [ donor.first_name, donor.last_name ].filter( Boolean ).join( ' ' )
    : null;

  const addressParts = formatAddress( donor );

  const gatewayLabel = ( () => {
    if ( s.payment_method_brand && s.payment_method_last4 ) {
      const brandName =
        s.payment_method_brand.charAt( 0 ).toUpperCase() +
        s.payment_method_brand.slice( 1 );
      return `${ brandName } ending in ${ s.payment_method_last4 }`;
    }
    return s.payment_gateway
      ? s.payment_gateway.charAt( 0 ).toUpperCase() +
          s.payment_gateway.slice( 1 )
      : '\u2014';
  } )();

  const freqSuffix = FREQUENCY_SUFFIXES[ s.frequency ] || '';

  // Payment history calculations.
  const completedTxns = ( s.transactions || [] ).filter(
    ( t ) => t.status === 'completed'
  );
  const totalGiven = completedTxns.reduce(
    ( sum, t ) => sum + ( t.total_amount || 0 ),
    0
  );

  // Fee summary calculations (mirrors transaction Payment Breakdown).
  const totalDonation = completedTxns.reduce(
    ( sum, t ) => sum + ( t.amount || 0 ),
    0
  );
  const totalProcessingFees = completedTxns.reduce(
    ( sum, t ) => sum + ( t.processing_fee || 0 ),
    0
  );
  const totalFeesRecovered = completedTxns.reduce(
    ( sum, t ) => sum + ( t.fee_recovered || 0 ),
    0
  );
  const totalTips = completedTxns.reduce(
    ( sum, t ) => sum + ( t.adjusted_tip || 0 ),
    0
  );
  const netAmount = totalDonation - totalProcessingFees + totalFeesRecovered;

  const stripeDashboard = `https://dashboard.stripe.com/${
    s.is_test ? 'test/' : ''
  }`;

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      { /* Subscription Details */ }
      <details className="mission-detail-section" open>
        <summary className="mission-detail-section__header">
          <h3 className="mission-detail-section__title">
            { __( 'Subscription Details', 'mission' ) }
          </h3>
          <Chevron />
        </summary>
        <div className="mission-detail-list">
          <DetailRow
            label={ __( 'Donor', 'mission' ) }
            value={
              donor ? (
                <a
                  href={ `${ adminUrl }admin.php?page=mission-donors&donor_id=${ donor.id }` }
                  style={ { color: '#2FA36B', textDecoration: 'none' } }
                >
                  { donorName }
                </a>
              ) : (
                __( 'Anonymous', 'mission' )
              )
            }
          />
          <DetailRow
            label={ __( 'Email', 'mission' ) }
            value={ donor?.email }
          />
          <DetailRow
            label={ __( 'Campaign', 'mission' ) }
            value={
              <CampaignDropdown
                campaign={ campaign }
                campaigns={ campaigns }
                onChange={ onCampaignChange }
              />
            }
          />
          <DetailRow
            label={ __( 'Frequency', 'mission' ) }
            value={ FREQUENCY_LABELS[ s.frequency ] || s.frequency }
          />
          <DetailRow
            label={ __( 'Amount', 'mission' ) }
            value={ `${ formatAmount( s.amount, s.currency ) }${ freqSuffix }` }
          />
          <DetailRow
            label={ __( 'Started', 'mission' ) }
            value={ formatDateTime( s.date_created ) }
          />
          { s.date_next_renewal && (
            <DetailRow
              label={ __( 'Next payment', 'mission' ) }
              value={ formatDate( s.date_next_renewal ) }
            />
          ) }
          <DetailRow
            label={ __( 'Payment method', 'mission' ) }
            value={ gatewayLabel }
          />
          { addressParts && (
            <DetailRow
              label={ __( 'Billing address', 'mission' ) }
              value={ addressParts.map( ( line, i ) => (
                <span key={ i }>
                  { i > 0 && <br /> }
                  { line }
                </span>
              ) ) }
            />
          ) }
          <DetailRow
            label={ __( 'Status', 'mission' ) }
            value={
              <StatusDropdown status={ s.status } onChange={ onStatusChange } />
            }
            isLast
          />
        </div>
      </details>

      { /* Payment History */ }
      { s.transactions && s.transactions.length > 0 && (
        <PaymentHistorySection
          transactions={ s.transactions }
          currency={ s.currency }
          totalGiven={ totalGiven }
          adminUrl={ adminUrl }
          currentPage={ txnPage }
          onPageChange={ setTxnPage }
        />
      ) }

      { /* Fee Summary */ }
      { completedTxns.length > 0 && (
        <details className="mission-detail-section">
          <summary className="mission-detail-section__header">
            <h3 className="mission-detail-section__title">
              { __( 'Fee Summary', 'mission' ) }
            </h3>
            <Chevron />
          </summary>
          <div className="mission-detail-list">
            <DetailRow
              label={ __( 'Donation amount', 'mission' ) }
              value={ formatAmount( totalDonation, s.currency ) }
            />
            { totalProcessingFees > 0 && (
              <DetailRow
                label={
                  <>
                    { __( 'Processing fees', 'mission' ) }
                    <InfoTooltip
                      text={ __(
                        'Stripe fees deducted from payments',
                        'mission'
                      ) }
                    />
                  </>
                }
                value={
                  <span style={ { color: '#dc2626' } }>
                    -{ formatAmount( totalProcessingFees, s.currency ) }
                  </span>
                }
              />
            ) }
            { totalFeesRecovered > 0 && (
              <DetailRow
                label={
                  <>
                    { __( 'Fees recovered', 'mission' ) }
                    <InfoTooltip
                      text={ __(
                        'The donor covered processing fees so you receive the full donation.',
                        'mission'
                      ) }
                    />
                  </>
                }
                value={
                  <span style={ { color: '#2FA36B' } }>
                    +{ formatAmount( totalFeesRecovered, s.currency ) }
                  </span>
                }
              />
            ) }
            { totalTips > 0 && (
              <DetailRow
                label={
                  <>
                    { __( 'Mission tips', 'mission' ) }
                    <InfoTooltip
                      text={ __(
                        "Optional tips from the donor to support the Mission platform. Doesn't affect your payout.",
                        'mission'
                      ) }
                    />
                  </>
                }
                value={
                  <span style={ { color: '#9b9ba8' } }>
                    { formatAmount( totalTips, s.currency ) }
                  </span>
                }
              />
            ) }
            <div
              className="mission-detail-row"
              style={ {
                borderBottom: 'none',
                borderTop: '2px solid #e8e6e1',
                fontWeight: 600,
              } }
            >
              <span
                className="mission-detail-row__label"
                style={ { fontWeight: 600 } }
              >
                { __( 'Net amount', 'mission' ) }
              </span>
              <span
                className="mission-detail-row__value"
                style={ { fontWeight: 600 } }
              >
                { formatAmount( netAmount, s.currency ) }
              </span>
            </div>
          </div>
        </details>
      ) }

      { /* Payment Data */ }
      <details className="mission-detail-section">
        <summary className="mission-detail-section__header">
          <h3 className="mission-detail-section__title">
            { __( 'Payment Data', 'mission' ) }
          </h3>
          <Chevron />
        </summary>
        <div className="mission-detail-list">
          { s.gateway_subscription_id && (
            <DetailRow
              label={ __( 'Stripe subscription', 'mission' ) }
              value={
                <a
                  href={ `${ stripeDashboard }subscriptions/${ s.gateway_subscription_id }` }
                  target="_blank"
                  rel="noopener noreferrer"
                  style={ { fontFamily: 'monospace', fontSize: '12px' } }
                >
                  { s.gateway_subscription_id }
                  <ExternalLinkIcon />
                </a>
              }
            />
          ) }
          { s.gateway_customer_id && (
            <DetailRow
              label={ __( 'Stripe customer', 'mission' ) }
              value={
                <a
                  href={ `${ stripeDashboard }customers/${ s.gateway_customer_id }` }
                  target="_blank"
                  rel="noopener noreferrer"
                  style={ { fontFamily: 'monospace', fontSize: '12px' } }
                >
                  { s.gateway_customer_id }
                  <ExternalLinkIcon />
                </a>
              }
            />
          ) }
          { !! s.source_post_id && (
            <DetailRow
              label={ __( 'Source', 'mission' ) }
              value={
                s.source_url ? (
                  <a
                    href={ s.source_url }
                    target="_blank"
                    rel="noopener noreferrer"
                    style={ { color: '#2FA36B', textDecoration: 'none' } }
                  >
                    { s.source_title || __( 'View page', 'mission' ) }
                    <ExternalLinkIcon />
                  </a>
                ) : (
                  s.source_title
                )
              }
            />
          ) }
          { s.is_test && (
            <DetailRow
              label={ __( 'Mode', 'mission' ) }
              value={
                <span
                  style={ {
                    display: 'inline-block',
                    padding: '2px 8px',
                    borderRadius: '2px',
                    fontSize: '12px',
                    fontWeight: 500,
                    backgroundColor: '#fef3c7',
                    color: '#92400e',
                  } }
                >
                  { __( 'Test', 'mission' ) }
                </span>
              }
              isLast
            />
          ) }
        </div>
      </details>
    </div>
  );
}
