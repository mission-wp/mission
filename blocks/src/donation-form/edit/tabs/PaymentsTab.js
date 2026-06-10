import { __, sprintf } from '@wordpress/i18n';
import { getCurrencySymbol } from '@shared/currency';
import { minorToMajor } from '@shared/currencies';
import { defaultFixedFee } from '@shared/fees';

export default function PaymentsTab( { localState, updateField } ) {
  const currency = window.missiondpFeeSettings?.currency || 'USD';
  const feePercent = window.missiondpFeeSettings?.stripeFeePercent ?? 2.9;
  const feeFixed =
    window.missiondpFeeSettings?.stripeFeeFixed ?? defaultFixedFee( currency );
  const stripeAccounts = window.missiondpFeeSettings?.stripeAccounts ?? [];
  const symbol = getCurrencySymbol( currency );
  const fixedDisplay = minorToMajor( feeFixed, currency );

  const selectedAccountId = localState.stripeAccountId ?? '';
  const selectedAccountExists =
    selectedAccountId === '' ||
    stripeAccounts.some( ( a ) => a.account_id === selectedAccountId );
  const defaultAccount = stripeAccounts.find( ( a ) => a.is_default );
  const defaultLabel = defaultAccount
    ? sprintf(
        /* translators: %s: Stripe account display name */
        __( 'Default account (%s)', 'mission-donation-platform' ),
        defaultAccount.display_name ||
          defaultAccount.account_id ||
          __( 'unnamed', 'mission-donation-platform' )
      )
    : __( 'Default account', 'mission-donation-platform' );

  const feeOptions = [
    {
      value: 'none',
      label: __( 'Organization absorbs fees', 'mission-donation-platform' ),
      hint: __(
        'Your organization covers all processing fees',
        'mission-donation-platform'
      ),
    },
    {
      value: 'optional',
      label: __(
        'Give donors the option to cover fees',
        'mission-donation-platform'
      ),
      hint: __(
        'Donors can choose to add processing fees to their donation',
        'mission-donation-platform'
      ),
    },
    {
      value: 'required',
      label: __( 'Require donors to cover fees', 'mission-donation-platform' ),
      hint: __(
        'Processing fees are automatically included in every donation',
        'mission-donation-platform'
      ),
    },
  ];

  const platformOptions = [
    {
      value: true,
      label: __( 'Optional donor tip', 'mission-donation-platform' ),
      hint: __(
        'Donors can leave an optional tip to support the Mission platform',
        'mission-donation-platform'
      ),
    },
    {
      value: false,
      label: __( 'Flat platform fee (3%)', 'mission-donation-platform' ),
      hint: __(
        'A flat 3% of each donation goes to support Mission',
        'mission-donation-platform'
      ),
    },
  ];

  return (
    <div className="mission-fees-tab">
      { /* Stripe Account */ }
      { stripeAccounts.length > 0 && (
        <div className="mission-fees-tab__section">
          <span className="mission-field-label">
            { __( 'Stripe account', 'mission-donation-platform' ) }
          </span>
          <p className="mission-fees-tab__section-hint">
            { __(
              'Choose which connected Stripe account receives donations from this form.',
              'mission-donation-platform'
            ) }
          </p>
          <select
            className="mission-fees-tab__select"
            value={ selectedAccountId }
            onChange={ ( e ) =>
              updateField( 'stripeAccountId', e.target.value )
            }
          >
            <option value="">{ defaultLabel }</option>
            { stripeAccounts.map( ( account ) => (
              <option key={ account.account_id } value={ account.account_id }>
                { account.display_name || account.account_id }
                { account.is_default
                  ? ` — ${ __( 'default', 'mission-donation-platform' ) }`
                  : '' }
              </option>
            ) ) }
          </select>
          { ! selectedAccountExists && (
            <p
              className="mission-fees-tab__section-hint"
              style={ { color: '#b85c5c', marginTop: '8px' } }
            >
              { __(
                'The previously selected account is no longer connected. Donations from this form will use the default account.',
                'mission-donation-platform'
              ) }
            </p>
          ) }
        </div>
      ) }

      { /* Processing Fees */ }
      <div className="mission-fees-tab__section">
        <span className="mission-field-label">
          { __( 'Processing Fees', 'mission-donation-platform' ) }
        </span>
        <p className="mission-fees-tab__section-hint">
          { sprintf(
            /* translators: 1: fee percentage, 2: currency symbol, 3: fixed fee amount */
            __(
              'Stripe charges %1$s%% + %2$s%3$s per transaction for payment processing.',
              'mission-donation-platform'
            ),
            feePercent,
            symbol,
            fixedDisplay
          ) }
        </p>
        <div className="mission-fees-tab__radio-group">
          { feeOptions.map( ( option ) => (
            // eslint-disable-next-line jsx-a11y/label-has-associated-control
            <label
              key={ option.value }
              className={ `mission-fees-tab__radio-option${
                localState.feeMode === option.value ? ' is-selected' : ''
              }` }
            >
              <input
                type="radio"
                name="feeMode"
                value={ option.value }
                checked={ localState.feeMode === option.value }
                onChange={ () => updateField( 'feeMode', option.value ) }
              />
              <div>
                <div className="mission-fees-tab__radio-label">
                  { option.label }
                </div>
                <div className="mission-fees-tab__radio-hint">
                  { option.hint }
                </div>
              </div>
            </label>
          ) ) }
        </div>
      </div>

      { /* Platform Fee */ }
      <div className="mission-fees-tab__section">
        <span className="mission-field-label">
          { __( 'Platform Fee', 'mission-donation-platform' ) }
        </span>
        <p className="mission-fees-tab__section-hint">
          { __(
            'Mission is funded by optional donor tips or a small platform fee — your choice.',
            'mission-donation-platform'
          ) }
        </p>
        <div className="mission-fees-tab__radio-group mission-fees-tab__radio-group--row">
          { platformOptions.map( ( option ) => (
            // eslint-disable-next-line jsx-a11y/label-has-associated-control
            <label
              key={ String( option.value ) }
              className={ `mission-fees-tab__radio-option${
                localState.tipEnabled === option.value ? ' is-selected' : ''
              }` }
            >
              <input
                type="radio"
                name="tipEnabled"
                value={ String( option.value ) }
                checked={ localState.tipEnabled === option.value }
                onChange={ () => updateField( 'tipEnabled', option.value ) }
              />
              <div>
                <div className="mission-fees-tab__radio-label">
                  { option.label }
                </div>
                <div className="mission-fees-tab__radio-hint">
                  { option.hint }
                </div>
              </div>
            </label>
          ) ) }
        </div>
      </div>
    </div>
  );
}
