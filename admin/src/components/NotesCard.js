import { useState, useEffect, useCallback } from '@wordpress/element';
import { formatUtcDate } from '@shared/date';
import {
  Button,
  Modal,
  Spinner,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Shared notes card for transactions, donors, and subscriptions.
 *
 * @param {Object} props
 * @param {string} props.objectType          REST route segment ('transactions', 'donors', 'subscriptions').
 * @param {number} props.objectId            Parent object ID.
 * @param {string} [props.type]              Note type filter ('internal' or 'donor'). Omit for all.
 * @param {string} props.title               Card heading.
 * @param {string} [props.hint]              Subtitle text below the heading.
 * @param {Object} [props.confirmBeforeSave] If set, shows a confirmation modal before saving ({ title, message, confirmLabel }).
 */
export default function NotesCard( {
  objectType,
  objectId,
  type,
  title,
  hint,
  confirmBeforeSave,
} ) {
  const [ notes, setNotes ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ isAdding, setIsAdding ] = useState( false );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ newNote, setNewNote ] = useState( '' );
  const [ deletingId, setDeletingId ] = useState( null );
  const [ showConfirm, setShowConfirm ] = useState( false );

  const basePath = `/mission-donation-platform/v1/${ objectType }/${ objectId }/notes`;

  const fetchNotes = useCallback( async () => {
    try {
      const path = type ? `${ basePath }?type=${ type }` : basePath;
      const data = await apiFetch( { path } );
      setNotes( data );
    } catch {
      // Silently fail — card will show empty state.
    } finally {
      setIsLoading( false );
    }
  }, [ basePath, type ] );

  useEffect( () => {
    fetchNotes();
  }, [ fetchNotes ] );

  const handleSave = async () => {
    if ( ! newNote.trim() ) {
      return;
    }

    setIsSaving( true );

    try {
      const postData = { content: newNote };
      if ( type ) {
        postData.type = type;
      }
      await apiFetch( {
        path: basePath,
        method: 'POST',
        data: postData,
      } );
      setNewNote( '' );
      setIsAdding( false );
      await fetchNotes();
    } catch {
      // Could add error handling here.
    } finally {
      setIsSaving( false );
    }
  };

  const handleDelete = async ( noteId ) => {
    setDeletingId( noteId );

    try {
      await apiFetch( {
        path: `${ basePath }/${ noteId }`,
        method: 'DELETE',
      } );
      await fetchNotes();
    } catch {
      // Could add error handling here.
    } finally {
      setDeletingId( null );
    }
  };

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      { hint ? (
        <div className="mission-card__header">
          <h2 className="mission-card__heading--plain">{ title }</h2>
          <p className="mission-card__subtitle">{ hint }</p>
        </div>
      ) : (
        <h2 className="mission-card__heading">{ title }</h2>
      ) }

      { isLoading ? (
        <div style={ { padding: '24px', textAlign: 'center' } }>
          <Spinner />
        </div>
      ) : (
        <>
          { notes.length === 0 && ! isAdding && (
            <Text
              variant="muted"
              style={ {
                textAlign: 'center',
                padding: '8px 16px',
                display: 'block',
                color: '#9b9ba8',
              } }
            >
              { __( 'No notes yet.', 'mission-donation-platform' ) }
            </Text>
          ) }

          { notes.length > 0 && (
            <div className="mission-notes-list">
              { notes.map( ( note ) => (
                <div key={ note.id } className="mission-note">
                  <div className="mission-note__content">{ note.content }</div>
                  <HStack justify="space-between" alignment="center">
                    <span className="mission-note__meta">
                      { note.author_name }
                      { note.author_name && ' \u00B7 ' }
                      { formatUtcDate( note.date_created ) }
                    </span>
                    <button
                      className="mission-note__delete"
                      onClick={ () => handleDelete( note.id ) }
                      disabled={ deletingId === note.id }
                      aria-label={ __(
                        'Delete note',
                        'mission-donation-platform'
                      ) }
                    >
                      { deletingId === note.id ? (
                        <Spinner
                          style={ {
                            width: '14px',
                            height: '14px',
                          } }
                        />
                      ) : (
                        <svg
                          width="14"
                          height="14"
                          viewBox="0 0 14 14"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        >
                          <path d="M3 3.5l8 8M11 3.5l-8 8" />
                        </svg>
                      ) }
                    </button>
                  </HStack>
                </div>
              ) ) }
            </div>
          ) }

          { isAdding ? (
            <div className="mission-note-form">
              <textarea
                className="mission-note-form__textarea"
                placeholder={ __(
                  'Write a note\u2026',
                  'mission-donation-platform'
                ) }
                value={ newNote }
                onChange={ ( e ) => setNewNote( e.target.value ) }
                rows={ 3 }
              />
              <HStack spacing={ 2 } justify="flex-end">
                <button
                  className="mission-note-footer__btn"
                  onClick={ () => {
                    setIsAdding( false );
                    setNewNote( '' );
                  } }
                  disabled={ isSaving }
                >
                  { __( 'Cancel', 'mission-donation-platform' ) }
                </button>
                <button
                  className="mission-note-footer__btn mission-note-footer__btn--primary"
                  onClick={
                    confirmBeforeSave
                      ? () => setShowConfirm( true )
                      : handleSave
                  }
                  disabled={ isSaving || ! newNote.trim() }
                >
                  { isSaving
                    ? __( 'Saving\u2026', 'mission-donation-platform' )
                    : __( 'Save', 'mission-donation-platform' ) }
                </button>
              </HStack>
            </div>
          ) : (
            <div className="mission-note-footer">
              <button
                className="mission-note-footer__btn"
                onClick={ () => setIsAdding( true ) }
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
                { __( 'Add a note', 'mission-donation-platform' ) }
              </button>
            </div>
          ) }
        </>
      ) }

      { showConfirm && confirmBeforeSave && (
        <Modal
          title={ confirmBeforeSave.title }
          onRequestClose={ () => setShowConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>{ confirmBeforeSave.message }</Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowConfirm( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isBusy={ isSaving }
                disabled={ isSaving }
                onClick={ () => {
                  setShowConfirm( false );
                  handleSave();
                } }
                style={ {
                  backgroundColor: '#2FA36B',
                  borderColor: '#2FA36B',
                } }
                __next40pxDefaultSize
              >
                { confirmBeforeSave.confirmLabel }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }
    </div>
  );
}
