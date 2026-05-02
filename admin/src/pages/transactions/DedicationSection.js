import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const EMPTY_FORM = {
  tribute_type: 'in_honor',
  honoree_name: '',
  message: '',
  notify_enabled: false,
  notify_method: 'email',
  notify_name: '',
  notify_email: '',
  notify_address_1: '',
  notify_city: '',
  notify_state: '',
  notify_zip: '',
  notify_country: '',
};

function formFromTribute( tribute ) {
  if ( ! tribute ) {
    return EMPTY_FORM;
  }
  const hasNotify = !! (
    tribute.notify_name ||
    tribute.notify_email ||
    tribute.notify_address_1
  );
  return {
    tribute_type: tribute.tribute_type || 'in_honor',
    honoree_name: tribute.honoree_name || '',
    message: tribute.message || '',
    notify_enabled: hasNotify,
    notify_method: tribute.notify_method || 'email',
    notify_name: tribute.notify_name || '',
    notify_email: tribute.notify_email || '',
    notify_address_1: tribute.notify_address_1 || '',
    notify_city: tribute.notify_city || '',
    notify_state: tribute.notify_state || '',
    notify_zip: tribute.notify_zip || '',
    notify_country: tribute.notify_country || '',
  };
}

function TypeLabel( { type } ) {
  return type === 'in_memory'
    ? __( 'In memory of', 'mission-donation-platform' )
    : __( 'In honor of', 'mission-donation-platform' );
}

function NotifyStatusBadge( { tribute, transactionId, onTributeChange } ) {
  const [ isMarking, setIsMarking ] = useState( false );

  if (
    ! tribute.notify_name &&
    ! tribute.notify_email &&
    ! tribute.notify_address_1
  ) {
    return null;
  }

  const isEmail = tribute.notify_method === 'email' || tribute.notify_email;
  const isSent = !! tribute.notification_sent_at;

  const handleMarkSent = async () => {
    setIsMarking( true );
    try {
      const result = await apiFetch( {
        path: `/mission-donation-platform/v1/transactions/${ transactionId }/tribute`,
        method: 'PUT',
        data: {
          notification_sent_at: new Date()
            .toISOString()
            .slice( 0, 19 )
            .replace( 'T', ' ' ),
        },
      } );
      onTributeChange( result );
    } catch {
      // Could add error handling.
    } finally {
      setIsMarking( false );
    }
  };

  return (
    <div className="mission-dedication-notify-status">
      { isEmail && (
        <span>
          { __( 'Email notification sent to', 'mission-donation-platform' ) }{ ' ' }
          <strong>{ tribute.notify_name }</strong>
          { tribute.notify_email && ` (${ tribute.notify_email })` }
        </span>
      ) }
      { ! isEmail && (
        <div className="mission-dedication-mail-info">
          <div className="mission-dedication-mail-header">
            <span>
              { isSent
                ? __( 'Mail notification sent to', 'mission-donation-platform' )
                : __(
                    'Mail notification pending for',
                    'mission-donation-platform'
                  ) }{ ' ' }
              <strong>{ tribute.notify_name }</strong>
            </span>
            { ! isSent && (
              <button
                type="button"
                className="mission-dedication-mark-sent-btn"
                onClick={ handleMarkSent }
                disabled={ isMarking }
              >
                { isMarking
                  ? __( 'Marking\u2026', 'mission-donation-platform' )
                  : __( 'Mark as sent', 'mission-donation-platform' ) }
              </button>
            ) }
          </div>
          <div className="mission-dedication-mail-address">
            { tribute.notify_address_1 && (
              <span>{ tribute.notify_address_1 }</span>
            ) }
            <span>
              { [
                tribute.notify_city,
                tribute.notify_state,
                tribute.notify_zip,
              ]
                .filter( Boolean )
                .join( ', ' ) }
            </span>
            { tribute.notify_country && tribute.notify_country !== 'US' && (
              <span>{ tribute.notify_country }</span>
            ) }
          </div>
        </div>
      ) }
    </div>
  );
}

export default function DedicationSection( {
  transactionId,
  tribute,
  onTributeChange,
} ) {
  // States: 'view' | 'edit' | 'remove' | 'empty'
  const hasTribute = !! tribute;
  const [ mode, setMode ] = useState( hasTribute ? 'view' : 'empty' );
  const [ form, setForm ] = useState( () => formFromTribute( tribute ) );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ isRemoving, setIsRemoving ] = useState( false );
  const [ isAdding, setIsAdding ] = useState( false );

  const updateField = ( key, value ) => {
    setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );
  };

  const handleEdit = () => {
    setForm( formFromTribute( tribute ) );
    setIsAdding( false );
    setMode( 'edit' );
  };

  const handleAdd = () => {
    setForm( EMPTY_FORM );
    setIsAdding( true );
    setMode( 'edit' );
  };

  const handleCancel = () => {
    setMode( hasTribute ? 'view' : 'empty' );
  };

  const handleSave = async () => {
    setIsSaving( true );
    try {
      const data = {
        tribute_type: form.tribute_type,
        honoree_name: form.honoree_name,
        message: form.message,
        notify_name: form.notify_enabled ? form.notify_name : '',
        notify_email:
          form.notify_enabled && form.notify_method === 'email'
            ? form.notify_email
            : '',
        notify_method: form.notify_enabled ? form.notify_method : '',
        notify_address_1:
          form.notify_enabled && form.notify_method === 'mail'
            ? form.notify_address_1
            : '',
        notify_city:
          form.notify_enabled && form.notify_method === 'mail'
            ? form.notify_city
            : '',
        notify_state:
          form.notify_enabled && form.notify_method === 'mail'
            ? form.notify_state
            : '',
        notify_zip:
          form.notify_enabled && form.notify_method === 'mail'
            ? form.notify_zip
            : '',
        notify_country:
          form.notify_enabled && form.notify_method === 'mail'
            ? form.notify_country
            : '',
      };
      const result = await apiFetch( {
        path: `/mission-donation-platform/v1/transactions/${ transactionId }/tribute`,
        method: 'PUT',
        data,
      } );
      onTributeChange( result );
      setMode( 'view' );
    } catch {
      // Could add error toast here.
    } finally {
      setIsSaving( false );
    }
  };

  const handleRemove = async () => {
    setIsRemoving( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/transactions/${ transactionId }/tribute`,
        method: 'DELETE',
      } );
      onTributeChange( null );
      setMode( 'empty' );
    } catch {
      // Could add error toast here.
    } finally {
      setIsRemoving( false );
    }
  };

  return (
    <details className="mission-detail-section">
      <summary className="mission-detail-section__header">
        <h3 className="mission-detail-section__title">
          { __( 'Dedication', 'mission-donation-platform' ) }
        </h3>
        { mode === 'view' && (
          <div className="mission-dedication-header-actions">
            <button
              type="button"
              className="mission-dedication-action-link"
              onClick={ ( e ) => {
                e.preventDefault();
                handleEdit();
              } }
            >
              { __( 'Edit', 'mission-donation-platform' ) }
            </button>
            <button
              type="button"
              className="mission-dedication-action-link mission-dedication-action-link--danger"
              onClick={ ( e ) => {
                e.preventDefault();
                setMode( 'remove' );
              } }
            >
              { __( 'Remove', 'mission-donation-platform' ) }
            </button>
          </div>
        ) }
        <svg
          className="mission-detail-section__chevron"
          width="12"
          height="12"
          viewBox="0 0 12 12"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M3 5l3 3 3-3" />
        </svg>
      </summary>

      { /* View State */ }
      { mode === 'view' && tribute && (
        <div className="mission-dedication-view">
          <div className="mission-dedication-display">
            <div className="mission-dedication-tribute">
              <div className="mission-dedication-tribute-text">
                <span className="mission-dedication-type">
                  <TypeLabel type={ tribute.tribute_type } />
                </span>
                <span className="mission-dedication-honoree">
                  { tribute.honoree_name }
                </span>
              </div>
            </div>
            { tribute.message && (
              <blockquote className="mission-dedication-message">
                { tribute.message }
              </blockquote>
            ) }
            <NotifyStatusBadge
              tribute={ tribute }
              transactionId={ transactionId }
              onTributeChange={ onTributeChange }
            />
          </div>
        </div>
      ) }

      { /* Edit State */ }
      { mode === 'edit' && (
        <div className="mission-dedication-edit">
          { /* eslint-disable jsx-a11y/label-has-associated-control -- Form labels are visual row labels adjacent to inputs */ }
          <div className="mission-dedication-form">
            <div className="mission-dedication-form-row">
              <label className="mission-dedication-form-label">
                { __( 'Type', 'mission-donation-platform' ) }
              </label>
              <select
                className="mission-dedication-form-select"
                value={ form.tribute_type }
                onChange={ ( e ) =>
                  updateField( 'tribute_type', e.target.value )
                }
              >
                <option value="in_honor">
                  { __( 'In honor of', 'mission-donation-platform' ) }
                </option>
                <option value="in_memory">
                  { __( 'In memory of', 'mission-donation-platform' ) }
                </option>
              </select>
            </div>
            <div className="mission-dedication-form-row">
              <label className="mission-dedication-form-label">
                { __( 'Honoree', 'mission-donation-platform' ) }
              </label>
              <input
                type="text"
                className="mission-dedication-form-input"
                value={ form.honoree_name }
                onChange={ ( e ) =>
                  updateField( 'honoree_name', e.target.value )
                }
              />
            </div>
            <div className="mission-dedication-form-row">
              <label className="mission-dedication-form-label">
                { __( 'Message', 'mission-donation-platform' ) }
              </label>
              <textarea
                className="mission-dedication-form-textarea"
                value={ form.message }
                onChange={ ( e ) => updateField( 'message', e.target.value ) }
                rows={ 2 }
              />
            </div>
            <div className="mission-dedication-form-row">
              <label className="mission-dedication-form-label">
                { __( 'Notification', 'mission-donation-platform' ) }
              </label>
              <div className="mission-dedication-notify-fields">
                <div className="mission-dedication-notify-toggle-row">
                  { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
                  <label className="mission-toggle-sm">
                    <input
                      type="checkbox"
                      checked={ form.notify_enabled }
                      onChange={ ( e ) =>
                        updateField( 'notify_enabled', e.target.checked )
                      }
                    />
                    <span className="mission-toggle-sm__slider" />
                  </label>
                  <span className="mission-dedication-notify-toggle-label">
                    { __( 'Send notification', 'mission-donation-platform' ) }
                  </span>
                </div>
                { form.notify_enabled && (
                  <div className="mission-dedication-notify-details">
                    <div className="mission-dedication-method-bar">
                      <button
                        type="button"
                        className={ `mission-dedication-method-btn${
                          form.notify_method === 'email' ? ' is-active' : ''
                        }` }
                        onClick={ () =>
                          updateField( 'notify_method', 'email' )
                        }
                      >
                        <svg
                          width="12"
                          height="12"
                          viewBox="0 0 16 16"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        >
                          <rect x="1.5" y="3" width="13" height="10" rx="1.5" />
                          <path d="M1.5 4.5L8 9l6.5-4.5" />
                        </svg>
                        { __( 'Email', 'mission-donation-platform' ) }
                      </button>
                      <button
                        type="button"
                        className={ `mission-dedication-method-btn${
                          form.notify_method === 'mail' ? ' is-active' : ''
                        }` }
                        onClick={ () => updateField( 'notify_method', 'mail' ) }
                      >
                        <svg
                          width="12"
                          height="12"
                          viewBox="0 0 16 16"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        >
                          <path d="M14 5L8 9 2 5" />
                          <rect x="1" y="2" width="14" height="11" rx="2" />
                          <path d="M2 10h3M2 7.5h2" />
                        </svg>
                        { __( 'Mail', 'mission-donation-platform' ) }
                      </button>
                    </div>
                    { form.notify_method === 'email' && (
                      <div className="mission-dedication-notify-panel">
                        <div className="mission-dedication-form-row-inline">
                          <div className="mission-dedication-form-field">
                            <label className="mission-dedication-form-sublabel">
                              { __(
                                'Recipient name',
                                'mission-donation-platform'
                              ) }
                            </label>
                            <input
                              type="text"
                              className="mission-dedication-form-input"
                              value={ form.notify_name }
                              onChange={ ( e ) =>
                                updateField( 'notify_name', e.target.value )
                              }
                            />
                          </div>
                          <div className="mission-dedication-form-field">
                            <label className="mission-dedication-form-sublabel">
                              { __(
                                'Email address',
                                'mission-donation-platform'
                              ) }
                            </label>
                            <input
                              type="email"
                              className="mission-dedication-form-input"
                              value={ form.notify_email }
                              onChange={ ( e ) =>
                                updateField( 'notify_email', e.target.value )
                              }
                            />
                          </div>
                        </div>
                      </div>
                    ) }
                    { form.notify_method === 'mail' && (
                      <div className="mission-dedication-notify-panel">
                        <div
                          className="mission-dedication-form-field"
                          style={ { marginBottom: 8 } }
                        >
                          <label className="mission-dedication-form-sublabel">
                            { __(
                              'Recipient name',
                              'mission-donation-platform'
                            ) }
                          </label>
                          <input
                            type="text"
                            className="mission-dedication-form-input"
                            value={ form.notify_name }
                            onChange={ ( e ) =>
                              updateField( 'notify_name', e.target.value )
                            }
                          />
                        </div>
                        <div
                          className="mission-dedication-form-field"
                          style={ { marginBottom: 8 } }
                        >
                          <label className="mission-dedication-form-sublabel">
                            { __(
                              'Street address',
                              'mission-donation-platform'
                            ) }
                          </label>
                          <input
                            type="text"
                            className="mission-dedication-form-input"
                            value={ form.notify_address_1 }
                            onChange={ ( e ) =>
                              updateField( 'notify_address_1', e.target.value )
                            }
                          />
                        </div>
                        <div className="mission-dedication-form-row-inline">
                          <div className="mission-dedication-form-field">
                            <label className="mission-dedication-form-sublabel">
                              { __( 'City', 'mission-donation-platform' ) }
                            </label>
                            <input
                              type="text"
                              className="mission-dedication-form-input"
                              value={ form.notify_city }
                              onChange={ ( e ) =>
                                updateField( 'notify_city', e.target.value )
                              }
                            />
                          </div>
                          <div
                            className="mission-dedication-form-field"
                            style={ { flex: '0 0 80px' } }
                          >
                            <label className="mission-dedication-form-sublabel">
                              { __( 'State', 'mission-donation-platform' ) }
                            </label>
                            <input
                              type="text"
                              className="mission-dedication-form-input"
                              value={ form.notify_state }
                              onChange={ ( e ) =>
                                updateField( 'notify_state', e.target.value )
                              }
                            />
                          </div>
                          <div
                            className="mission-dedication-form-field"
                            style={ { flex: '0 0 100px' } }
                          >
                            <label className="mission-dedication-form-sublabel">
                              { __(
                                'Postal code',
                                'mission-donation-platform'
                              ) }
                            </label>
                            <input
                              type="text"
                              className="mission-dedication-form-input"
                              value={ form.notify_zip }
                              onChange={ ( e ) =>
                                updateField( 'notify_zip', e.target.value )
                              }
                            />
                          </div>
                        </div>
                      </div>
                    ) }
                  </div>
                ) }
              </div>
            </div>
            <div className="mission-dedication-form-actions">
              <button
                type="button"
                className="mission-btn-primary mission-btn-sm"
                onClick={ handleSave }
                disabled={ isSaving || ! form.honoree_name.trim() }
              >
                { isSaving &&
                  __( 'Saving\u2026', 'mission-donation-platform' ) }
                { ! isSaving &&
                  isAdding &&
                  __( 'Add Dedication', 'mission-donation-platform' ) }
                { ! isSaving &&
                  ! isAdding &&
                  __( 'Save Changes', 'mission-donation-platform' ) }
              </button>
              <button
                type="button"
                className="mission-btn-text"
                onClick={ handleCancel }
                disabled={ isSaving }
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </button>
            </div>
            { /* eslint-enable jsx-a11y/label-has-associated-control */ }
          </div>
        </div>
      ) }

      { /* Remove Confirmation */ }
      { mode === 'remove' && (
        <div className="mission-dedication-remove-confirm">
          <div className="mission-dedication-remove-prompt">
            <svg
              width="18"
              height="18"
              viewBox="0 0 18 18"
              fill="none"
              stroke="#b85c5c"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <circle cx="9" cy="9" r="8" />
              <path d="M9 5.5v4M9 12.5h.01" />
            </svg>
            <div className="mission-dedication-remove-prompt-text">
              <strong>
                { __( 'Remove this dedication?', 'mission-donation-platform' ) }
              </strong>
              <span>
                { __(
                  'This will permanently remove the dedication from this transaction.',
                  'mission-donation-platform'
                ) }
              </span>
            </div>
          </div>
          <div className="mission-dedication-remove-actions">
            <button
              type="button"
              className="mission-btn-danger mission-btn-sm"
              onClick={ handleRemove }
              disabled={ isRemoving }
            >
              { isRemoving
                ? __( 'Removing\u2026', 'mission-donation-platform' )
                : __( 'Remove Dedication', 'mission-donation-platform' ) }
            </button>
            <button
              type="button"
              className="mission-btn-text"
              onClick={ () => setMode( 'view' ) }
              disabled={ isRemoving }
            >
              { __( 'Cancel', 'mission-donation-platform' ) }
            </button>
          </div>
        </div>
      ) }

      { /* Empty State */ }
      { mode === 'empty' && (
        <div className="mission-dedication-empty">
          <div className="mission-dedication-empty-inner">
            <svg
              className="mission-dedication-empty-icon"
              width="28"
              height="28"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
            </svg>
            <p className="mission-dedication-empty-text">
              { __(
                'No dedication on this donation',
                'mission-donation-platform'
              ) }
            </p>
            <button
              type="button"
              className="mission-dedication-add-btn"
              onClick={ handleAdd }
            >
              <svg
                width="14"
                height="14"
                viewBox="0 0 14 14"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="M7 2v10M2 7h10" />
              </svg>
              { __( 'Add Dedication', 'mission-donation-platform' ) }
            </button>
          </div>
        </div>
      ) }
    </details>
  );
}
