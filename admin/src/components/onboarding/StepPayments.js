import { __ } from '@wordpress/i18n';

export default function StepPayments( {
  stripeConnected,
  stripeDisplayName,
  connectError,
} ) {
  const connectUrl = window.missionAdmin?.stripeConnectUrlDashboard;

  return (
    <div className="mission-onboarding-stripe-hero">
      <div className="mission-onboarding-stripe-icon">
        <svg width="34" height="34" viewBox="0 0 38 38" fill="none">
          <path
            d="M17.6 14.7c0-1.15.95-1.6 2.52-1.6 2.25 0 5.1.68 7.35 1.9V9.15C25.12 8.25 22.85 7.8 20.55 7.8c-5.45 0-9.07 2.85-9.07 7.6 0 7.42 10.2 6.23 10.2 9.43 0 1.37-1.18 1.8-2.83 1.8-2.45 0-5.58-.98-8.07-2.35v5.95c2.75 1.18 5.52 1.87 8.07 1.87 5.58 0 9.4-2.75 9.4-7.58-.03-8.02-10.25-6.58-10.25-9.82z"
            fill="white"
          />
        </svg>
      </div>

      <h1 className="mission-onboarding-step__heading">
        { __(
          'Connect your payment processor',
          'missionwp-donation-platform'
        ) }
      </h1>
      <p className="mission-onboarding-step__subheading">
        { __(
          'Stripe handles all payment processing securely. Connect your existing Stripe account or create a new one \u2014 it only takes a minute.',
          'missionwp-donation-platform'
        ) }
      </p>

      { stripeConnected ? (
        <div className="mission-onboarding-stripe-btn is-connected">
          <svg
            width="16"
            height="16"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polyline points="3,8 7,12 13,4" />
          </svg>
          { stripeDisplayName
            ? `${ __(
                'Connected',
                'missionwp-donation-platform'
              ) } \u2014 ${ stripeDisplayName }`
            : __( 'Connected', 'missionwp-donation-platform' ) }
        </div>
      ) : (
        <>
          { connectError && (
            <p className="mission-onboarding-stripe-error">{ connectError }</p>
          ) }

          { connectUrl && (
            <a href={ connectUrl } className="mission-onboarding-stripe-btn">
              <svg
                width="18"
                height="18"
                viewBox="0 0 18 18"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="M8 3v12M3 8h12" />
              </svg>
              { __( 'Connect with Stripe', 'missionwp-donation-platform' ) }
            </a>
          ) }

          <p className="mission-onboarding-stripe-note">
            <svg
              width="14"
              height="14"
              viewBox="0 0 14 14"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.3"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <circle cx="7" cy="7" r="6" />
              <path d="M7 9.5V7M7 4.5h.005" />
            </svg>
            { __(
              'You\u2019ll be redirected to Stripe to connect or create an account, then returned here automatically.',
              'missionwp-donation-platform'
            ) }
          </p>

          <div className="mission-onboarding-stripe-warning">
            <p>
              <svg
                width="16"
                height="16"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.3"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="M8 1.6L1 14h14L8 1.6z" />
                <path d="M8 6v3.5M8 12h.005" />
              </svg>
              { __(
                'You can skip this for now, but you won\u2019t be able to accept donations until Stripe is connected.',
                'missionwp-donation-platform'
              ) }
            </p>
          </div>
        </>
      ) }
    </div>
  );
}
