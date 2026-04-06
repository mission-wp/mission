import { __ } from '@wordpress/i18n';

const checkIcon = (
  <svg
    width="12"
    height="12"
    viewBox="0 0 14 14"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="3,7 6,10 11,4" />
  </svg>
);

const warnIcon = (
  <svg
    width="12"
    height="12"
    viewBox="0 0 14 14"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <path d="M7 1.2L1 12.5h12L7 1.2z" />
    <path d="M7 5.5v3M7 10.5h.005" />
  </svg>
);

function ChecklistItem( { success, label, linkText, onLink } ) {
  return (
    <div className="mission-onboarding-checklist-item">
      <div
        className={ `mission-onboarding-checklist-icon ${
          success ? 'is-success' : 'is-warning'
        }` }
      >
        { success ? checkIcon : warnIcon }
      </div>
      <span className="mission-onboarding-checklist-label">{ label }</span>
      { ! success && linkText && (
        <button
          type="button"
          className="mission-onboarding-checklist-link"
          onClick={ onLink }
        >
          { linkText }
        </button>
      ) }
    </div>
  );
}

export default function StepDone( {
  data,
  stripeConnected,
  onGoToStep,
  onComplete,
} ) {
  const hasAddress =
    data.org_street.trim() ||
    data.org_city.trim() ||
    data.org_state.trim() ||
    data.org_zip.trim();

  const hasCampaign = data.campaign_name.trim();

  return (
    <div className="mission-onboarding-completion">
      <div className="mission-onboarding-completion-icon">
        <svg
          width="28"
          height="28"
          viewBox="0 0 32 32"
          fill="none"
          stroke="currentColor"
          strokeWidth="2.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <polyline points="8,16 14,22 24,10" />
        </svg>
      </div>

      <h1 className="mission-onboarding-step__heading">
        { __( 'You\u2019re all set!', 'mission' ) }
      </h1>
      <p className="mission-onboarding-step__subheading">
        { __(
          'Mission is configured and ready to accept donations. Here\u2019s a summary of your setup.',
          'mission'
        ) }
      </p>

      <div className="mission-onboarding-checklist">
        <div className="mission-onboarding-checklist__title">
          { __( 'Configuration Summary', 'mission' ) }
        </div>

        <ChecklistItem
          success={ !! data.org_name.trim() }
          label={
            <>
              <strong>{ __( 'Organization:', 'mission' ) }</strong>{ ' ' }
              { data.org_name || __( 'Not set', 'mission' ) }
            </>
          }
        />

        <ChecklistItem
          success={ !! hasAddress }
          label={
            hasAddress ? (
              <>
                <strong>{ __( 'Address', 'mission' ) }</strong>{ ' ' }
                { __( 'configured', 'mission' ) }
              </>
            ) : (
              <>
                <strong>{ __( 'Address', 'mission' ) }</strong>{ ' ' }
                { __( 'not configured', 'mission' ) }
              </>
            )
          }
          linkText={ __( 'Add now', 'mission' ) }
          onLink={ () => onGoToStep( 2 ) }
        />

        <ChecklistItem
          success={ stripeConnected }
          label={
            stripeConnected ? (
              <>
                <strong>{ __( 'Stripe', 'mission' ) }</strong>{ ' ' }
                { __( 'connected', 'mission' ) }
              </>
            ) : (
              <>
                <strong>{ __( 'Stripe', 'mission' ) }</strong>{ ' ' }
                { __( 'not connected', 'mission' ) }
              </>
            )
          }
          linkText={ __( 'Connect now', 'mission' ) }
          onLink={ () => onGoToStep( 3 ) }
        />

        <ChecklistItem
          success={ !! hasCampaign }
          label={
            hasCampaign ? (
              <>
                <strong>{ __( 'Campaign:', 'mission' ) }</strong>{ ' ' }
                { data.campaign_name }
              </>
            ) : (
              <>
                <strong>{ __( 'Campaign', 'mission' ) }</strong>{ ' ' }
                { __( 'not created', 'mission' ) }
              </>
            )
          }
          linkText={ __( 'Create now', 'mission' ) }
          onLink={ () => onGoToStep( 4 ) }
        />
      </div>

      <button
        type="button"
        className="mission-onboarding-completion-cta"
        onClick={ onComplete }
      >
        { __( 'Go to Dashboard', 'mission' ) }
        <svg
          width="16"
          height="16"
          viewBox="0 0 16 16"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M3 8h10M9 4l4 4-4 4" />
        </svg>
      </button>
    </div>
  );
}
