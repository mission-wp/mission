import { __ } from '@wordpress/i18n';

export default function StripeBanner() {
  const connectUrl = window.missionAdmin?.stripeConnectUrl || '#';

  return (
    <div className="mission-stripe-banner">
      <div className="mission-stripe-banner__content">
        <div className="mission-stripe-banner__icon">
          <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect
              x="2"
              y="6"
              width="24"
              height="16"
              rx="3"
              stroke="currentColor"
              strokeWidth="1.8"
            />
            <path d="M2 11h24" stroke="currentColor" strokeWidth="1.8" />
            <circle cx="8" cy="17" r="1.5" fill="currentColor" />
            <circle cx="13" cy="17" r="1.5" fill="currentColor" />
          </svg>
        </div>
        <div className="mission-stripe-banner__text">
          <h2>
            { __( 'Start accepting donations', 'missionwp-donation-platform' ) }
          </h2>
          <p>
            <a href={ connectUrl } className="mission-stripe-banner__link">
              { __(
                'Connect your Stripe account',
                'missionwp-donation-platform'
              ) }
            </a>{ ' ' }
            { __(
              "to begin processing donations securely. It only takes a couple of minutes and you'll be ready to receive your first gift.",
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-stripe-banner__actions">
          <a href={ connectUrl } className="mission-stripe-banner__btn">
            <svg width="18" height="18" viewBox="0 0 16 16" fill="none">
              <path
                d="M7.36 5.87c0-.67.55-.93 1.46-.93.97 0 2.2.3 3.17.82V2.82A8.6 8.6 0 0 0 8.82 2.3C6.34 2.3 4.63 3.6 4.63 5.7c0 3.24 4.46 2.73 4.46 4.12 0 .8-.69 1.05-1.66 1.05-1.43 0-2.76-.59-3.83-1.39v3a9.2 9.2 0 0 0 3.83.84c2.56 0 4.32-1.22 4.32-3.37C11.76 6.78 7.36 7.38 7.36 5.87Z"
                fill="currentColor"
              />
            </svg>
            { __( 'Connect Stripe', 'missionwp-donation-platform' ) }
          </a>
        </div>
      </div>
    </div>
  );
}
