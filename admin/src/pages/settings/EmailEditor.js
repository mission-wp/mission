import { useState, useRef, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import SlidePanel from '../../components/SlidePanel';
import { highlightMergeTags } from './email-templates';
import HtmlCodeEditor from './HtmlCodeEditor';

export default function EmailEditor( {
  email,
  subject,
  body,
  onSubjectChange,
  onBodyChange,
  onClose,
} ) {
  const subjectRef = useRef( null );
  const [ mode, setMode ] = useState( 'preview' );
  const [ defaultBody, setDefaultBody ] = useState( '' );
  const [ emailHeader, setEmailHeader ] = useState( '' );
  const [ emailFooter, setEmailFooter ] = useState( '' );
  const [ loadingDefault, setLoadingDefault ] = useState( false );
  const [ testState, setTestState ] = useState( 'idle' ); // idle | sending | sent | error

  // Fetch default template parts from API when editor opens.
  useEffect( () => {
    if ( ! email ) {
      return;
    }

    setLoadingDefault( true );
    apiFetch( {
      path: `/mission-donation-platform/v1/email/template/${ email.id }`,
    } )
      .then( ( data ) => {
        setDefaultBody( data.body || '' );
        setEmailHeader( data.header || '' );
        setEmailFooter( data.footer || '' );
      } )
      .catch( () => {
        setDefaultBody( '' );
        setEmailHeader( '' );
        setEmailFooter( '' );
      } )
      .finally( () => {
        setLoadingDefault( false );
      } );
  }, [ email ] );

  const insertTag = ( tag ) => {
    const input = subjectRef.current;
    if ( ! input ) {
      return;
    }

    const start = input.selectionStart;
    const end = input.selectionEnd;
    const val = input.value;

    const newValue = val.substring( 0, start ) + tag + val.substring( end );
    onSubjectChange( newValue );

    window.requestAnimationFrame( () => {
      input.focus();
      const pos = start + tag.length;
      input.setSelectionRange( pos, pos );
    } );
  };

  const handleSendTest = async () => {
    setTestState( 'sending' );

    try {
      await apiFetch( {
        path: '/mission-donation-platform/v1/email/test',
        method: 'POST',
        data: { email_type: email.id },
      } );
      setTestState( 'sent' );
      setTimeout( () => setTestState( 'idle' ), 2000 );
    } catch {
      setTestState( 'error' );
      setTimeout( () => setTestState( 'idle' ), 3000 );
    }
  };

  const handleReset = () => {
    onBodyChange( '' );
  };

  if ( ! email ) {
    return null;
  }

  // The body to display: custom body if set, otherwise the API-fetched default.
  const displayBody = body || defaultBody;
  const hasCustomBody = !! body;

  // Full email preview: header + body (with highlighted tags) + footer.
  const previewHtml =
    emailHeader + highlightMergeTags( displayBody ) + emailFooter;

  return (
    <SlidePanel
      isOpen={ !! email }
      onClose={ onClose }
      className="mission-email-editor"
      label={ email.name }
    >
      { /* Header */ }
      <div className="mission-email-editor__header">
        <div className="mission-email-editor__title-group">
          <h2 className="mission-email-editor__title">{ email.name }</h2>
          <span className="mission-email-editor__subtitle">{ email.desc }</span>
        </div>
        <button
          className="mission-email-editor__close"
          onClick={ onClose }
          type="button"
          aria-label={ __( 'Close', 'mission-donation-platform' ) }
        >
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
            <line x1="12" y1="4" x2="4" y2="12" />
            <line x1="4" y1="4" x2="12" y2="12" />
          </svg>
        </button>
      </div>

      { /* Body */ }
      <div className="mission-email-editor__body">
        { /* Subject */ }
        <div className="mission-email-editor__section">
          <div className="mission-email-editor__section-label">
            { __( 'Subject line', 'mission-donation-platform' ) }
          </div>
          <div className="mission-email-editor__subject">
            <input
              ref={ subjectRef }
              type="text"
              className="mission-settings-field__input"
              value={ subject }
              onChange={ ( e ) => onSubjectChange( e.target.value ) }
              placeholder={ email.defaultSubject }
            />
            <div className="mission-email-editor__merge-tags">
              { email.mergeTags.map( ( mt ) => (
                <button
                  key={ mt.tag }
                  className="mission-email-editor__merge-tag"
                  onClick={ () => insertTag( mt.tag ) }
                  type="button"
                >
                  { mt.tag }
                </button>
              ) ) }
            </div>
          </div>
        </div>

        { /* Email Body — Preview / HTML toggle */ }
        <div className="mission-email-editor__section">
          <div
            style={ {
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              marginBottom: '12px',
            } }
          >
            <div
              className="mission-email-editor__section-label"
              style={ { marginBottom: 0 } }
            >
              { __( 'Email body', 'mission-donation-platform' ) }
            </div>
            <div className="mission-email-editor__toggle">
              <button
                className={ `mission-email-editor__toggle-btn${
                  mode === 'preview' ? ' is-active' : ''
                }` }
                onClick={ () => setMode( 'preview' ) }
                type="button"
              >
                { __( 'Preview', 'mission-donation-platform' ) }
              </button>
              <button
                className={ `mission-email-editor__toggle-btn${
                  mode === 'html' ? ' is-active' : ''
                }` }
                onClick={ () => setMode( 'html' ) }
                type="button"
              >
                { __( 'Edit HTML', 'mission-donation-platform' ) }
              </button>
            </div>
          </div>

          { mode === 'preview' && (
            <>
              { loadingDefault && ! displayBody ? (
                <div
                  style={ {
                    padding: '40px',
                    textAlign: 'center',
                    color: '#9b9ba8',
                    fontSize: '13px',
                  } }
                >
                  { __( 'Loading preview\u2026', 'mission-donation-platform' ) }
                </div>
              ) : (
                <div className="mission-email-editor__preview-frame">
                  <div className="mission-email-editor__preview-bar">
                    <div className="mission-email-editor__preview-dot" />
                    <div className="mission-email-editor__preview-dot" />
                    <div className="mission-email-editor__preview-dot" />
                  </div>
                  <iframe
                    className="mission-email-editor__preview-content"
                    sandbox=""
                    srcDoc={ previewHtml }
                    title={ __( 'Email preview', 'mission-donation-platform' ) }
                  />
                </div>
              ) }
            </>
          ) }

          <HtmlCodeEditor
            value={ body || defaultBody }
            onChange={ onBodyChange }
            visible={ mode === 'html' }
          />

          { hasCustomBody && (
            <button
              className="mission-email-editor__reset"
              onClick={ handleReset }
              type="button"
            >
              { __( 'Reset to default template', 'mission-donation-platform' ) }
            </button>
          ) }
        </div>
      </div>

      { /* Footer */ }
      <div className="mission-email-editor__footer">
        <button
          className={ `mission-email-editor__test-btn${
            testState === 'sent' ? ' mission-email-editor__test-btn--sent' : ''
          }` }
          onClick={ handleSendTest }
          disabled={ testState === 'sending' }
          type="button"
        >
          { testState === 'idle' && (
            <>
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <line x1="22" y1="2" x2="11" y2="13" />
                <polygon points="22 2 15 22 11 13 2 9 22 2" />
              </svg>
              { __( 'Send test email', 'mission-donation-platform' ) }
            </>
          ) }
          { testState === 'sending' &&
            __( 'Sending\u2026', 'mission-donation-platform' ) }
          { testState === 'sent' && (
            <>
              <svg
                width="14"
                height="14"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <polyline points="12 5 6.5 11 4 8.5" />
              </svg>
              { __( 'Sent!', 'mission-donation-platform' ) }
            </>
          ) }
          { testState === 'error' &&
            __( 'Failed to send', 'mission-donation-platform' ) }
        </button>

        <button
          className="mission-email-editor__done-btn"
          onClick={ onClose }
          type="button"
        >
          <svg
            width="14"
            height="14"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polyline points="12 5 6.5 11 4 8.5" />
          </svg>
          { __( 'Done', 'mission-donation-platform' ) }
        </button>
      </div>
    </SlidePanel>
  );
}
