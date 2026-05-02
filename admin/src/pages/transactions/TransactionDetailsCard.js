import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import { formatDateTime } from '@shared/date';
import {
  DetailRow,
  Chevron,
  ExternalLinkIcon,
  InfoTooltip,
  formatAddress,
} from '../shared/DetailComponents';
import DedicationSection from './DedicationSection';

const STATUS_OPTIONS = [
  {
    value: 'completed',
    label: __( 'Completed', 'mission-donation-platform' ),
    backgroundColor: 'rgba(47, 163, 107, 0.12)',
    color: '#278f5c',
  },
  {
    value: 'pending',
    label: __( 'Pending', 'mission-donation-platform' ),
    backgroundColor: '#e4eff5',
    color: '#4a7a9b',
  },
  {
    value: 'refunded',
    label: __( 'Refunded', 'mission-donation-platform' ),
    backgroundColor: '#f5e8e8',
    color: '#b85c5c',
  },
  {
    value: 'cancelled',
    label: __( 'Cancelled', 'mission-donation-platform' ),
    backgroundColor: '#f0eeeb',
    color: '#8a7e72',
  },
  {
    value: 'failed',
    label: __( 'Failed', 'mission-donation-platform' ),
    backgroundColor: '#fce8e8',
    color: '#c0392b',
  },
];

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

const CAMPAIGN_DROPDOWN_LIMIT = 10;

export function CampaignDropdown( { campaign, campaigns, onChange } ) {
  const [ isOpen, setIsOpen ] = useState( false );
  const [ search, setSearch ] = useState( '' );
  const ref = useRef();
  const inputRef = useRef();

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

  useEffect( () => {
    if ( isOpen && inputRef.current ) {
      inputRef.current.focus();
    }
    if ( ! isOpen ) {
      setSearch( '' );
    }
  }, [ isOpen ] );

  const needle = search.toLowerCase();
  const filtered = needle
    ? campaigns.filter( ( c ) => c.title.toLowerCase().includes( needle ) )
    : campaigns.slice( 0, CAMPAIGN_DROPDOWN_LIMIT );

  const label = campaign
    ? campaign.title
    : __( 'No campaign', 'mission-donation-platform' );

  return (
    <div className="mission-detail-dropdown" ref={ ref }>
      <button
        type="button"
        className="detail-clickable"
        onClick={ () => setIsOpen( ! isOpen ) }
      >
        { label }
      </button>
      { isOpen && (
        <div className="mission-detail-dropdown__menu">
          { campaigns.length > CAMPAIGN_DROPDOWN_LIMIT && (
            <div className="mission-detail-dropdown__search">
              <input
                ref={ inputRef }
                type="text"
                placeholder={ __(
                  'Search campaigns\u2026',
                  'mission-donation-platform'
                ) }
                value={ search }
                onChange={ ( e ) => setSearch( e.target.value ) }
              />
            </div>
          ) }
          { ! needle && (
            <button
              className={ `mission-detail-dropdown__item${
                ! campaign ? ' mission-detail-dropdown__item--active' : ''
              }` }
              onClick={ () => {
                onChange( null );
                setIsOpen( false );
              } }
            >
              { __( 'No campaign', 'mission-donation-platform' ) }
            </button>
          ) }
          { filtered.map( ( c ) => (
            <button
              key={ c.id }
              className={ `mission-detail-dropdown__item${
                campaign?.id === c.id
                  ? ' mission-detail-dropdown__item--active'
                  : ''
              }` }
              onClick={ () => {
                onChange( c );
                setIsOpen( false );
              } }
            >
              { c.title }
            </button>
          ) ) }
          { filtered.length === 0 && needle && (
            <span
              className="mission-detail-dropdown__item"
              style={ { color: '#9b9ba8', cursor: 'default' } }
            >
              { __( 'No results', 'mission-donation-platform' ) }
            </span>
          ) }
        </div>
      ) }
    </div>
  );
}

function TypeBadge( { type } ) {
  const isRecurring = [ 'monthly', 'quarterly', 'annually' ].includes( type );
  const className = isRecurring
    ? 'mission-detail-badge is-recurring'
    : 'mission-detail-badge';

  return (
    <span className={ className }>
      { isRecurring
        ? __( 'Recurring', 'mission-donation-platform' )
        : __( 'One-time', 'mission-donation-platform' ) }
    </span>
  );
}

export default function TransactionDetailsCard( {
  transaction,
  onStatusChange,
  onAnonymousChange,
  onCampaignChange,
  onTributeChange,
  campaigns,
} ) {
  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  const donor = transaction.donor;
  const campaign = transaction.campaign;

  const donorName = donor
    ? [ donor.first_name, donor.last_name ].filter( Boolean ).join( ' ' )
    : null;

  const gatewayLabel = ( () => {
    const brand = transaction.meta?.payment_method_brand;
    const last4 = transaction.meta?.payment_method_last4;

    if ( brand && last4 ) {
      const brandName = brand.charAt( 0 ).toUpperCase() + brand.slice( 1 );
      return `${ brandName } ending in ${ last4 }`;
    }

    return transaction.payment_gateway
      ? transaction.payment_gateway.charAt( 0 ).toUpperCase() +
          transaction.payment_gateway.slice( 1 )
      : '\u2014';
  } )();

  const addressParts = formatAddress( transaction.billing_address || donor );

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      { /* Transaction Details */ }
      <details className="mission-detail-section" open>
        <summary className="mission-detail-section__header">
          <h3 className="mission-detail-section__title">
            { __( 'Transaction Details', 'mission-donation-platform' ) }
          </h3>
          <Chevron />
        </summary>
        <div className="mission-detail-list">
          <DetailRow
            label={ __( 'Donor', 'mission-donation-platform' ) }
            value={
              donor ? (
                <a
                  href={ `${ adminUrl }admin.php?page=mission-donation-platform-donors&donor_id=${ donor.id }` }
                  style={ { color: '#2FA36B', textDecoration: 'none' } }
                >
                  { donorName }
                </a>
              ) : (
                __( 'Anonymous', 'mission-donation-platform' )
              )
            }
          />
          <DetailRow
            label={ __( 'Email', 'mission-donation-platform' ) }
            value={ donor?.email }
          />
          <DetailRow
            label={ __( 'Campaign', 'mission-donation-platform' ) }
            value={
              <CampaignDropdown
                campaign={ campaign }
                campaigns={ campaigns }
                onChange={ onCampaignChange }
              />
            }
          />
          <DetailRow
            label={ __( 'Date', 'mission-donation-platform' ) }
            value={ formatDateTime( transaction.date_created ) }
          />
          <DetailRow
            label={ __( 'Payment method', 'mission-donation-platform' ) }
            value={ gatewayLabel }
          />
          <DetailRow
            label={ __( 'Billing address', 'mission-donation-platform' ) }
            value={
              addressParts
                ? addressParts.map( ( line, i ) => (
                    <span key={ i }>
                      { i > 0 && <br /> }
                      { line }
                    </span>
                  ) )
                : null
            }
          />
          <DetailRow
            label={ __( 'Type', 'mission-donation-platform' ) }
            value={ <TypeBadge type={ transaction.type } /> }
          />
          <DetailRow
            label={ __( 'Status', 'mission-donation-platform' ) }
            value={
              <StatusDropdown
                status={ transaction.status }
                onChange={ onStatusChange }
              />
            }
          />
          <div
            className="mission-detail-row"
            style={ { borderBottom: 'none', alignItems: 'center' } }
          >
            <span className="mission-detail-row__label">
              { __( 'Anonymous', 'mission-donation-platform' ) }
            </span>
            <span className="mission-detail-row__value">
              <label
                className="mission-toggle-sm"
                htmlFor="txn-anonymous-toggle"
              >
                <span className="screen-reader-text">
                  { __( 'Anonymous', 'mission-donation-platform' ) }
                </span>
                <input
                  id="txn-anonymous-toggle"
                  type="checkbox"
                  checked={ !! transaction.is_anonymous }
                  onChange={ () =>
                    onAnonymousChange( ! transaction.is_anonymous )
                  }
                />
                <span className="mission-toggle-sm__slider" />
              </label>
            </span>
          </div>
        </div>
      </details>

      { /* Payment Breakdown (hidden for manual transactions) */ }
      { transaction.payment_gateway !== 'manual' && (
        <details className="mission-detail-section">
          <summary className="mission-detail-section__header">
            <h3 className="mission-detail-section__title">
              { __( 'Payment Breakdown', 'mission-donation-platform' ) }
            </h3>
            <Chevron />
          </summary>
          <div className="mission-detail-list">
            <DetailRow
              label={ __( 'Donation amount', 'mission-donation-platform' ) }
              value={ formatAmount( transaction.amount, transaction.currency ) }
            />
            { transaction.processing_fee > 0 && (
              <DetailRow
                label={
                  <>
                    { __( 'Processing fee', 'mission-donation-platform' ) }
                    <InfoTooltip
                      text={ __(
                        'Stripe fees deducted from this payment',
                        'mission-donation-platform'
                      ) }
                    />
                  </>
                }
                value={
                  <span style={ { color: '#dc2626' } }>
                    -
                    { formatAmount(
                      transaction.processing_fee,
                      transaction.currency
                    ) }
                  </span>
                }
              />
            ) }
            { transaction.fee_recovered > 0 && (
              <DetailRow
                label={
                  <>
                    { __( 'Fee recovered', 'mission-donation-platform' ) }
                    <InfoTooltip
                      text={ __(
                        'The donor covered processing fees so you receive the full donation.',
                        'mission-donation-platform'
                      ) }
                    />
                  </>
                }
                value={
                  <span style={ { color: '#2FA36B' } }>
                    +
                    { formatAmount(
                      transaction.fee_recovered,
                      transaction.currency
                    ) }
                  </span>
                }
              />
            ) }
            { transaction.adjusted_tip > 0 && (
              <DetailRow
                label={
                  <>
                    { __( 'Mission tip', 'mission-donation-platform' ) }
                    <InfoTooltip
                      text={ __(
                        "Optional tip from the donor to support the Mission platform. Doesn't affect your payout.",
                        'mission-donation-platform'
                      ) }
                    />
                  </>
                }
                value={
                  <span style={ { color: '#9b9ba8' } }>
                    { formatAmount(
                      transaction.adjusted_tip,
                      transaction.currency
                    ) }
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
                { __( 'Net amount', 'mission-donation-platform' ) }
              </span>
              <span
                className="mission-detail-row__value"
                style={ { fontWeight: 600 } }
              >
                { formatAmount( transaction.net_amount, transaction.currency ) }
              </span>
            </div>
          </div>
        </details>
      ) }

      { /* Dedication */ }
      <DedicationSection
        transactionId={ transaction.id }
        tribute={ transaction.tribute }
        onTributeChange={ onTributeChange }
      />

      { /* Additional Info */ }
      <details className="mission-detail-section">
        <summary className="mission-detail-section__header">
          <h3 className="mission-detail-section__title">
            { __( 'Additional Info', 'mission-donation-platform' ) }
          </h3>
          <Chevron />
        </summary>
        <div className="mission-detail-list">
          { ( () => {
            const meta = transaction.meta || {};
            let config = [];
            try {
              config = JSON.parse( meta.custom_fields_config || '[]' );
            } catch {
              // Invalid JSON — fall through to empty state.
            }

            if ( ! config.length ) {
              return (
                <div
                  className="mission-detail-row"
                  style={ { borderBottom: 'none', justifyContent: 'center' } }
                >
                  <span
                    className="mission-detail-row__label"
                    style={ { color: '#9b9ba8' } }
                  >
                    { __( 'No additional info', 'mission-donation-platform' ) }
                  </span>
                </div>
              );
            }

            return config.map( ( field, idx ) => {
              const raw = meta[ `custom_field_${ field.id }` ];
              let display = raw || '\u2014';

              if ( field.type === 'checkbox' ) {
                display =
                  raw === '1'
                    ? __( 'Yes', 'mission-donation-platform' )
                    : __( 'No', 'mission-donation-platform' );
              } else if ( field.type === 'multiselect' && raw ) {
                try {
                  display = JSON.parse( raw ).join( ', ' );
                } catch {
                  display = raw;
                }
              }

              return (
                <DetailRow
                  key={ field.id }
                  label={ field.label || field.id }
                  value={ display }
                  isLast={ idx === config.length - 1 }
                />
              );
            } );
          } )() }
        </div>
      </details>

      { /* Payment Data (hidden for manual transactions) */ }
      { transaction.payment_gateway !== 'manual' && (
        <details className="mission-detail-section">
          <summary className="mission-detail-section__header">
            <h3 className="mission-detail-section__title">
              { __( 'Payment Data', 'mission-donation-platform' ) }
            </h3>
            <Chevron />
          </summary>
          <div className="mission-detail-list">
            <DetailRow
              label={ __( 'Payment gateway', 'mission-donation-platform' ) }
              value={ gatewayLabel }
            />
            { transaction.gateway_transaction_id && (
              <DetailRow
                label={ __( 'Stripe payment', 'mission-donation-platform' ) }
                value={
                  <a
                    href={ `https://dashboard.stripe.com/${
                      transaction.is_test ? 'test/' : ''
                    }payments/${ transaction.gateway_transaction_id }` }
                    target="_blank"
                    rel="noopener noreferrer"
                    style={ { fontFamily: 'monospace', fontSize: '12px' } }
                  >
                    { transaction.gateway_transaction_id }
                    <ExternalLinkIcon />
                  </a>
                }
              />
            ) }
            { !! transaction.gateway_customer_id && (
              <DetailRow
                label={ __( 'Stripe customer', 'mission-donation-platform' ) }
                value={
                  <a
                    href={ `https://dashboard.stripe.com/${
                      transaction.is_test ? 'test/' : ''
                    }customers/${ transaction.gateway_customer_id }` }
                    target="_blank"
                    rel="noopener noreferrer"
                    style={ { fontFamily: 'monospace', fontSize: '12px' } }
                  >
                    { transaction.gateway_customer_id }
                    <ExternalLinkIcon />
                  </a>
                }
              />
            ) }
            { !! transaction.source_post_id && (
              <DetailRow
                label={ __( 'Source', 'mission-donation-platform' ) }
                value={
                  transaction.source_url ? (
                    <a
                      href={ transaction.source_url }
                      target="_blank"
                      rel="noopener noreferrer"
                      style={ { color: '#2FA36B', textDecoration: 'none' } }
                    >
                      { transaction.source_title ||
                        __( 'View page', 'mission-donation-platform' ) }
                      <ExternalLinkIcon />
                    </a>
                  ) : (
                    transaction.source_title
                  )
                }
              />
            ) }
            { transaction.donor_ip && (
              <DetailRow
                label={ __( 'IP address', 'mission-donation-platform' ) }
                value={
                  <span style={ { fontFamily: 'monospace', fontSize: '12px' } }>
                    { transaction.donor_ip }
                  </span>
                }
              />
            ) }
            { transaction.is_test && (
              <DetailRow
                label={ __( 'Mode', 'mission-donation-platform' ) }
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
                    { __( 'Test', 'mission-donation-platform' ) }
                  </span>
                }
                isLast
              />
            ) }
          </div>
        </details>
      ) }
    </div>
  );
}
