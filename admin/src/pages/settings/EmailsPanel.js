import { useState, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import SaveBar from './SaveBar';
import EmailEditor from './EmailEditor';
import {
  DONATION_EMAILS,
  ACCOUNT_EMAILS,
  ADMIN_EMAILS,
  EMAIL_ICONS,
} from './email-templates';

function EmailRow( { email, enabled, onToggle, onEdit } ) {
  const icon = EMAIL_ICONS[ email.id ];

  return (
    <div className="mission-settings-email-row">
      <div
        className={ `mission-settings-email-row__icon mission-settings-email-row__icon--${ email.iconType }` }
      >
        { icon }
      </div>
      <div className="mission-settings-email-row__info">
        <div className="mission-settings-email-row__name">{ email.name }</div>
        <div className="mission-settings-email-row__desc">{ email.desc }</div>
      </div>
      <div className="mission-settings-email-row__actions">
        <button
          className="mission-settings-email-row__edit"
          onClick={ onEdit }
          type="button"
        >
          { __( 'Edit', 'mission-donation-platform' ) }
        </button>
        { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
        <label className="mission-toggle-sm" aria-label={ email.name }>
          <input
            type="checkbox"
            checked={ enabled }
            onChange={ ( e ) => onToggle( e.target.checked ) }
          />
          <span className="mission-toggle-sm__slider" />
        </label>
      </div>
    </div>
  );
}

const MAX_PREVIEW_RECIPIENTS = 3;

function AdminEmailRow( {
  email,
  enabled,
  recipients,
  onToggle,
  onRecipientsChange,
} ) {
  const icon = EMAIL_ICONS[ email.id ];
  const [ expanded, setExpanded ] = useState( false );
  const [ inputValue, setInputValue ] = useState( '' );
  const inputRef = useRef( null );

  const defaultEmail = window.missiondpAdmin?.adminEmail || '';
  const fallbackRecipients = defaultEmail ? [ defaultEmail ] : [];
  const displayRecipients = recipients.length ? recipients : fallbackRecipients;

  const handleAddRecipient = () => {
    const value = inputValue.trim().toLowerCase();
    if ( ! value || ! value.includes( '@' ) ) {
      return;
    }
    if ( displayRecipients.includes( value ) ) {
      setInputValue( '' );
      return;
    }
    // If we only had the default, start fresh list with default + new.
    const newList =
      recipients.length === 0 && defaultEmail
        ? [ defaultEmail, value ]
        : [ ...recipients, value ];
    onRecipientsChange( newList );
    setInputValue( '' );
  };

  const handleRemoveRecipient = ( emailToRemove ) => {
    const newList = displayRecipients.filter( ( e ) => e !== emailToRemove );
    onRecipientsChange( newList );
  };

  const handleKeyDown = ( e ) => {
    if ( e.key === 'Enter' ) {
      e.preventDefault();
      handleAddRecipient();
    }
    // Backspace on empty input removes last recipient.
    if (
      e.key === 'Backspace' &&
      inputValue === '' &&
      displayRecipients.length > 0
    ) {
      handleRemoveRecipient(
        displayRecipients[ displayRecipients.length - 1 ]
      );
    }
  };

  const previewRecipients = displayRecipients.slice(
    0,
    MAX_PREVIEW_RECIPIENTS
  );
  const overflowCount = displayRecipients.length - MAX_PREVIEW_RECIPIENTS;

  return (
    <div
      className={ `mission-admin-email-row${ expanded ? ' is-expanded' : '' }` }
    >
      { /* Collapsed summary — click to expand */ }
      <div
        className="mission-admin-email-row__summary"
        onClick={ () => setExpanded( ! expanded ) }
        role="button"
        tabIndex={ 0 }
        onKeyDown={ ( e ) => {
          if ( e.key === 'Enter' || e.key === ' ' ) {
            e.preventDefault();
            setExpanded( ! expanded );
          }
        } }
      >
        <div
          className={ `mission-settings-email-row__icon mission-settings-email-row__icon--${ email.iconType }` }
        >
          { icon }
        </div>
        <div className="mission-settings-email-row__info">
          <div className="mission-settings-email-row__name">{ email.name }</div>
          <div className="mission-settings-email-row__desc">{ email.desc }</div>
          { displayRecipients.length > 0 && (
            <div className="mission-admin-email-row__preview">
              { previewRecipients.map( ( addr ) => (
                <span
                  key={ addr }
                  className="mission-admin-email-row__preview-tag"
                >
                  { addr }
                </span>
              ) ) }
              { overflowCount > 0 && (
                <span className="mission-admin-email-row__preview-more">
                  { sprintf(
                    /* translators: %d: number of additional recipients */
                    __( 'and %d others', 'mission-donation-platform' ),
                    overflowCount
                  ) }
                </span>
              ) }
            </div>
          ) }
        </div>
        <svg
          className="mission-admin-email-row__chevron"
          width="14"
          height="14"
          viewBox="0 0 14 14"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M5 3l4 4-4 4" />
        </svg>
        { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
        <div
          className="mission-settings-email-row__actions"
          onClick={ ( e ) => e.stopPropagation() }
        >
          { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
          <label className="mission-toggle-sm" aria-label={ email.name }>
            <input
              type="checkbox"
              checked={ enabled }
              onChange={ ( e ) => onToggle( e.target.checked ) }
            />
            <span className="mission-toggle-sm__slider" />
          </label>
        </div>
      </div>

      { /* Expanded detail — recipient editor */ }
      <div className="mission-admin-email-row__detail">
        <div className="mission-admin-email-row__detail-inner">
          { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
          <div
            className="mission-admin-email-row__tags"
            onClick={ () => inputRef.current?.focus() }
          >
            { displayRecipients.map( ( addr ) => (
              <span key={ addr } className="mission-admin-email-row__tag">
                { addr }
                { addr === defaultEmail && recipients.length === 0 && (
                  <span className="mission-admin-email-row__tag-default">
                    { __( 'default', 'mission-donation-platform' ) }
                  </span>
                ) }
                <button
                  type="button"
                  className="mission-admin-email-row__tag-remove"
                  onClick={ ( e ) => {
                    e.stopPropagation();
                    handleRemoveRecipient( addr );
                  } }
                  aria-label={ sprintf(
                    /* translators: %s: email address */
                    __( 'Remove %s', 'mission-donation-platform' ),
                    addr
                  ) }
                >
                  <svg
                    width="10"
                    height="10"
                    viewBox="0 0 10 10"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                  >
                    <path d="M2.5 2.5l5 5M7.5 2.5l-5 5" />
                  </svg>
                </button>
              </span>
            ) ) }
            <input
              ref={ inputRef }
              type="email"
              className="mission-admin-email-row__input"
              placeholder={ __( 'Add email…', 'mission-donation-platform' ) }
              value={ inputValue }
              onChange={ ( e ) => setInputValue( e.target.value ) }
              onKeyDown={ handleKeyDown }
              onBlur={ handleAddRecipient }
            />
          </div>
          <span className="mission-admin-email-row__hint">
            { __(
              'Press Enter to add. Default is your WordPress admin email.',
              'mission-donation-platform'
            ) }
          </span>
        </div>
      </div>
    </div>
  );
}

export default function EmailsPanel( {
  settings,
  setSettings,
  updateField,
  saving,
  isDirty,
  handleSave,
} ) {
  const [ editingEmailId, setEditingEmailId ] = useState( null );

  const emailSettings = settings.emails || {};

  const getEmailEnabled = ( id ) => emailSettings[ id ]?.enabled ?? true;
  const getEmailSubject = ( id ) => emailSettings[ id ]?.subject ?? '';
  const getEmailBody = ( id ) => emailSettings[ id ]?.body ?? '';

  const updateEmailSetting = ( emailId, key, value ) => {
    setSettings( ( prev ) => ( {
      ...prev,
      emails: {
        ...prev.emails,
        [ emailId ]: {
          ...prev.emails?.[ emailId ],
          [ key ]: value,
        },
      },
    } ) );
  };

  const getEmailRecipients = ( id ) => emailSettings[ id ]?.recipients ?? [];

  const updateEmailRecipients = ( emailId, recipients ) => {
    setSettings( ( prev ) => ( {
      ...prev,
      emails: {
        ...prev.emails,
        [ emailId ]: {
          ...prev.emails?.[ emailId ],
          recipients,
        },
      },
    } ) );
  };

  const editingEmail =
    editingEmailId &&
    [ ...DONATION_EMAILS, ...ACCOUNT_EMAILS ].find(
      ( e ) => e.id === editingEmailId
    );

  return (
    <div className="mission-settings-panel" key="emails">
      { /* Sender Details */ }
      <div className="mission-settings-card">
        <div className="mission-settings-card__header">
          <h2 className="mission-settings-card__title">
            { __( 'Sender Details', 'mission-donation-platform' ) }
          </h2>
          <p className="mission-settings-card__desc">
            { __(
              'Used as the \u201cfrom\u201d address on all outgoing emails.',
              'mission-donation-platform'
            ) }
          </p>
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-email-from-name"
          >
            { __( 'From name', 'mission-donation-platform' ) }
          </label>
          <input
            type="text"
            id="mission-email-from-name"
            className="mission-settings-field__input"
            value={ settings.email_from_name }
            onChange={ ( e ) =>
              updateField( 'email_from_name', e.target.value )
            }
          />
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-email-from-address"
          >
            { __( 'From email', 'mission-donation-platform' ) }
          </label>
          <input
            type="email"
            id="mission-email-from-address"
            className="mission-settings-field__input"
            value={ settings.email_from_address }
            onChange={ ( e ) =>
              updateField( 'email_from_address', e.target.value )
            }
          />
        </div>
        <div className="mission-settings-field">
          <label
            className="mission-settings-field__label"
            htmlFor="mission-email-reply-to"
          >
            { __( 'Reply-to email', 'mission-donation-platform' ) }
          </label>
          <input
            type="email"
            id="mission-email-reply-to"
            className="mission-settings-field__input"
            value={ settings.email_reply_to }
            onChange={ ( e ) =>
              updateField( 'email_reply_to', e.target.value )
            }
          />
          <span className="mission-settings-field__hint">
            { __(
              'Leave blank to use the from email address.',
              'mission-donation-platform'
            ) }
          </span>
        </div>
      </div>

      { /* Donation Emails */ }
      <h3 className="mission-settings-email-group-title">
        { __( 'Donation Emails', 'mission-donation-platform' ) }
      </h3>
      <div className="mission-settings-email-list">
        { DONATION_EMAILS.map( ( email ) => (
          <EmailRow
            key={ email.id }
            email={ email }
            enabled={ getEmailEnabled( email.id ) }
            onToggle={ ( val ) =>
              updateEmailSetting( email.id, 'enabled', val )
            }
            onEdit={ () => setEditingEmailId( email.id ) }
          />
        ) ) }
      </div>

      { /* Account Emails */ }
      <h3 className="mission-settings-email-group-title">
        { __( 'Account Emails', 'mission-donation-platform' ) }
      </h3>
      <div className="mission-settings-email-list">
        { ACCOUNT_EMAILS.map( ( email ) => (
          <EmailRow
            key={ email.id }
            email={ email }
            enabled={ getEmailEnabled( email.id ) }
            onToggle={ ( val ) =>
              updateEmailSetting( email.id, 'enabled', val )
            }
            onEdit={ () => setEditingEmailId( email.id ) }
          />
        ) ) }
      </div>

      { /* Admin Notifications */ }
      <h3 className="mission-settings-email-group-title">
        { __( 'Admin Notifications', 'mission-donation-platform' ) }
      </h3>
      <div className="mission-settings-email-list">
        { ADMIN_EMAILS.map( ( email ) => (
          <AdminEmailRow
            key={ email.id }
            email={ email }
            enabled={ getEmailEnabled( email.id ) }
            recipients={ getEmailRecipients( email.id ) }
            onToggle={ ( val ) =>
              updateEmailSetting( email.id, 'enabled', val )
            }
            onRecipientsChange={ ( val ) =>
              updateEmailRecipients( email.id, val )
            }
          />
        ) ) }
      </div>

      <SaveBar saving={ saving } isDirty={ isDirty } onSave={ handleSave } />

      { /* Email Editor Slide-over */ }
      { editingEmail && (
        <EmailEditor
          email={ editingEmail }
          subject={ getEmailSubject( editingEmailId ) }
          body={ getEmailBody( editingEmailId ) }
          onSubjectChange={ ( val ) =>
            updateEmailSetting( editingEmailId, 'subject', val )
          }
          onBodyChange={ ( val ) =>
            updateEmailSetting( editingEmailId, 'body', val )
          }
          onClose={ () => setEditingEmailId( null ) }
        />
      ) }
    </div>
  );
}
