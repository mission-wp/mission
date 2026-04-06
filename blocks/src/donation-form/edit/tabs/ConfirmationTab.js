import { __ } from '@wordpress/i18n';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalLinkControl as LinkControl } from '@wordpress/block-editor';

export default function ConfirmationTab( { localState, updateField } ) {
  const options = [
    {
      value: 'message',
      label: __( 'Display a confirmation message', 'mission' ),
      hint: __(
        'Show a thank-you message after a successful donation',
        'mission'
      ),
    },
    {
      value: 'redirect',
      label: __( 'Redirect to a URL', 'mission' ),
      hint: __( 'Send donors to a specific page after donating', 'mission' ),
    },
  ];

  return (
    <div className="mission-confirmation-tab">
      <div className="mission-fees-tab__section">
        <span className="mission-field-label">
          { __( 'After Donation', 'mission' ) }
        </span>
        <p className="mission-fees-tab__section-hint">
          { __(
            'Choose what happens after a donor completes their donation.',
            'mission'
          ) }
        </p>
        <div className="mission-fees-tab__radio-group mission-fees-tab__radio-group--row">
          { options.map( ( option ) => (
            // eslint-disable-next-line jsx-a11y/label-has-associated-control
            <label
              key={ option.value }
              className={ `mission-fees-tab__radio-option${
                localState.confirmationType === option.value
                  ? ' is-selected'
                  : ''
              }` }
            >
              <input
                type="radio"
                name="confirmationType"
                value={ option.value }
                checked={ localState.confirmationType === option.value }
                onChange={ () =>
                  updateField( 'confirmationType', option.value )
                }
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

      { localState.confirmationType === 'message' && (
        <div className="mission-confirmation-tab__inline-hint">
          <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <circle
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              strokeWidth="2"
              fill="none"
            />
            <line
              x1="12"
              y1="16"
              x2="12"
              y2="12"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
            />
            <circle cx="12" cy="8" r="1" fill="currentColor" />
          </svg>
          { __(
            'Tip: Use the "Edit Confirmation" button in the editor to customize the message.',
            'mission'
          ) }
        </div>
      ) }

      { localState.confirmationType === 'redirect' && (
        <div className="mission-fees-tab__section">
          <div className="mission-field-group">
            <span className="mission-field-label">
              { __( 'Redirect URL', 'mission' ) }
            </span>
            <div className="mission-confirmation-tab__link-control">
              <LinkControl
                value={ {
                  url: localState.confirmationRedirectUrl || '',
                } }
                onChange={ ( { url } ) =>
                  updateField( 'confirmationRedirectUrl', url || '' )
                }
                settings={ [] }
                showInitialSuggestions
                searchInputPlaceholder={ __(
                  'Search or type a URL',
                  'mission'
                ) }
              />
            </div>
            <span className="mission-field-hint">
              { __(
                'Donors will be redirected to this page after a successful donation.',
                'mission'
              ) }
            </span>
          </div>
        </div>
      ) }
    </div>
  );
}
