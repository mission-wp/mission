/**
 * Shared Stripe helpers for the payment surfaces (donation form, sign-up
 * modal first gift, donor dashboard payment update).
 *
 * Stripe.js itself is loaded server-side as the external `mission-stripe-js`
 * classic script, so consumers read `window.Stripe`. Everything here is
 * stateless configuration and plumbing; flow control (generators, spinners,
 * error surfaces) stays with each consumer.
 */

// Deadlines keep submit spinners from spinning forever if a promise never
// settles. confirmPayment gets a soft "taking long" notice instead of a hard
// deadline: a 3D Secure challenge can legitimately sit open for minutes.
export const PRE_CHARGE_DEADLINE_MS = 30000;
export const CONFIRM_SLOW_NOTICE_MS = 30000;
export const RECORD_DONATION_DEADLINE_MS = 15000;

/**
 * Race a promise against a deadline that rejects with the given message.
 *
 * @param {Promise} promise The promise to guard.
 * @param {number}  ms      Deadline in milliseconds.
 * @param {string}  message Error message shown to the donor on timeout.
 * @return {Promise} The guarded promise.
 */
export function withDeadline( promise, ms, message ) {
  let timer;
  return Promise.race( [
    Promise.resolve( promise ).finally( () => clearTimeout( timer ) ),
    new Promise( ( _resolve, reject ) => {
      timer = setTimeout( () => reject( new Error( message ) ), ms );
    } ),
  ] );
}

/**
 * Build the Elements appearance object shared by all payment surfaces.
 *
 * @param {string} primaryColor       CSS color for colorPrimary ('' falls back to the brand green).
 * @param {Object} [customAppearance] Output of the mission_stripe_appearance filter.
 * @return {Object} Stripe Elements appearance option.
 */
export function buildAppearance( primaryColor, customAppearance = {} ) {
  return {
    theme: customAppearance.theme || 'stripe',
    variables: {
      colorPrimary: primaryColor || '#2FA36B',
      colorDanger: '#dc2626',
      fontFamily:
        '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
      borderRadius: '10px',
      ...( customAppearance.variables || {} ),
    },
    rules: {
      '.Input--invalid': {
        borderColor: '#dc2626',
        boxShadow: '0 0 0 3px rgba(220, 38, 38, 0.12)',
      },
      '.Error': {
        fontSize: '0.8125rem',
      },
      ...( customAppearance.rules || {} ),
    },
  };
}

/**
 * Build the options for a deferred-mode Elements instance.
 *
 * @param {Object}  options
 * @param {number}  options.amount      Total in minor units.
 * @param {string}  options.currency    ISO 4217 code (any case; '' falls back to USD).
 * @param {Object}  options.appearance  Appearance from buildAppearance().
 * @param {boolean} [options.recurring] Add setupFutureUsage for subscriptions.
 * @return {Object} stripe.elements() options.
 */
export function buildElementsOptions( {
  amount,
  currency,
  appearance,
  recurring = false,
} ) {
  return {
    mode: 'payment',
    amount,
    currency: ( currency || 'USD' ).toLowerCase(),
    paymentMethodTypes: [ 'card' ],
    ...( recurring && {
      setupFutureUsage: 'off_session',
    } ),
    appearance,
  };
}

/**
 * Build the options for a Payment Element whose name/email are collected
 * outside the element and passed at confirm time.
 *
 * @param {Object} [options]
 * @param {string} [options.address] Billing address collection: 'auto', or 'never' when a separate Address Element collects it.
 * @return {Object} elements.create( 'payment' ) options.
 */
export function buildPaymentElementOptions( { address = 'auto' } = {} ) {
  return {
    layout: 'tabs',
    fields: {
      billingDetails: {
        name: 'never',
        email: 'never',
        address,
      },
    },
    wallets: {
      link: 'never',
    },
  };
}

/**
 * Extract the PaymentIntent ID from a client secret.
 *
 * @param {string} clientSecret Stripe client secret (pi_..._secret_...).
 * @return {string} PaymentIntent ID.
 */
export function paymentIntentIdFrom( clientSecret ) {
  return clientSecret.split( '_secret_' )[ 0 ];
}

// Memoized payment-config request, so prefetching surfaces and multiple
// forms on one page share a single lookup.
let paymentConfigMemo = null;

/**
 * Fetch the public payment config (connected Stripe account), memoized.
 *
 * Never rejects: resolves with the config object, or null when the request
 * fails. A failed or unconnected lookup is evicted from the memo so a
 * transient error never permanently blocks payment within the session.
 *
 * @param {string} restUrl Trailing-slashed plugin REST base URL.
 * @return {Promise<?Object>} Resolves with the config, or null.
 */
export function getPaymentConfig( restUrl ) {
  if ( paymentConfigMemo && paymentConfigMemo.restUrl === restUrl ) {
    return paymentConfigMemo.promise;
  }

  const memo = { restUrl, promise: null };
  memo.promise = fetch( `${ restUrl }donations/payment-config` )
    .then( ( response ) => ( response.ok ? response.json() : null ) )
    .catch( () => null )
    .then( ( config ) => {
      if (
        ( ! config || ! config.connected_account_id ) &&
        paymentConfigMemo === memo
      ) {
        paymentConfigMemo = null;
      }
      return config;
    } );
  paymentConfigMemo = memo;

  return memo.promise;
}

/**
 * Clear the memoized payment config, forcing the next lookup to refetch
 * (e.g. after a campaign rebind that can change the Stripe account).
 */
export function resetPaymentConfigCache() {
  paymentConfigMemo = null;
}
