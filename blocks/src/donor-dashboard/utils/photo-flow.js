/**
 * Donor Dashboard — shared staged-photo flow.
 *
 * The fundraiser and team detail views stage a cover image locally and apply
 * it on save: upload (or delete) the image first, then let the caller send
 * its PUT. Each flow instance is bound to one context slice and endpoint.
 */
import { getContext } from '@wordpress/interactivity';
import { resizeImageFile } from '@shared/image-resize';

/**
 * Create the staged-photo state getters and actions for one detail view.
 *
 * @param {string} slice Context slice name and REST base under donor-dashboard/
 *                       ('fundraisers' or 'teams').
 * @return {Object} Photo flow members to expose from the store.
 */
export function createPhotoFlow( slice ) {
  // Image staged for upload on save. A File can't live in the reactive
  // context, so it's held here; only its preview URL goes in context.
  let staged = null;

  /**
   * Discard the staged image and its local preview.
   *
   * @param {Object} s The slice context.
   */
  function clearStaged( s ) {
    if ( s.photoPreviewUrl ) {
      URL.revokeObjectURL( s.photoPreviewUrl );
    }
    staged = null;
    s.photoPreviewUrl = '';
  }

  return {
    clearStaged,

    /** @return {string} The cover preview: staged image, else the saved cover unless removed. */
    coverSrc() {
      const s = getContext()[ slice ];
      if ( s?.photoPreviewUrl ) {
        return s.photoPreviewUrl;
      }
      return s?.photoRemoved ? '' : s?.detail?.coverImageUrl || '';
    },

    /** @return {boolean} Whether a cover (staged or saved) should show. */
    hasCover() {
      const s = getContext()[ slice ];
      return !! (
        s?.photoPreviewUrl ||
        ( s?.detail?.hasCover && ! s?.photoRemoved )
      );
    },

    /**
     * Open the file picker behind the styled upload button.
     *
     * @param {Event} event Click event.
     */
    trigger( event ) {
      const input = event?.target
        ?.closest( '.mission-dd-cover' )
        ?.querySelector( 'input[type="file"]' );
      if ( input ) {
        input.click();
      }
    },

    /**
     * Stage a selected image for upload on save, previewing it locally.
     * Large images are downscaled in the browser so they fit the upload limit.
     *
     * @param {Event} event Change event from the file input.
     */
    *select( event ) {
      const s = getContext()[ slice ];
      const original = event?.target?.files?.[ 0 ];

      // Reset the input so the same file can be re-selected.
      if ( event?.target ) {
        event.target.value = '';
      }

      if ( ! s?.detail || ! original ) {
        return;
      }

      const file = yield resizeImageFile( original, {
        maxBytes: s.maxPhotoBytes,
      } );

      if ( s.maxPhotoBytes && file.size > s.maxPhotoBytes ) {
        s.uploadError = s.i18n?.photoTooLarge || 'The image is too large.';
        return;
      }

      clearStaged( s );
      s.uploadError = '';
      s.photoRemoved = false;
      staged = file;
      s.photoPreviewUrl = URL.createObjectURL( file );
    },

    /**
     * Stage removal of the image; applied on save. With only a staged image
     * (no saved one), this just discards the staged image.
     */
    remove() {
      const s = getContext()[ slice ];
      if ( ! s?.detail ) {
        return;
      }
      clearStaged( s );
      s.uploadError = '';
      s.photoRemoved = !! s.detail.hasCover;
    },

    /**
     * Apply the staged photo change (upload or delete) ahead of a save PUT.
     * Delegate to this with yield* inside the save action.
     *
     * @param {Object} ctx  Interactivity context.
     * @param {Object} card The detail card being saved.
     * @return {boolean} False when a request failed; edit.error and
     *                   edit.saving are already set so the caller just bails.
     */
    *save( ctx, card ) {
      const s = ctx[ slice ];

      if ( staged ) {
        const body = new FormData();
        body.append( 'file', staged );

        const photoResponse = yield fetch(
          `${ ctx.restUrl }donor-dashboard/${ slice }/${ card.id }/photo`,
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': ctx.nonce },
            body,
          }
        );

        if ( ! photoResponse.ok ) {
          const data = yield photoResponse.json();
          s.edit.error =
            data.message || 'Could not upload the image. Please try again.';
          s.edit.saving = false;
          return false;
        }

        const photoData = yield photoResponse.json();
        card.coverImageUrl = photoData.cover_image_url;
        card.hasCover = !! photoData.cover_image_url;
        clearStaged( s );
      } else if ( s.photoRemoved && card.hasCover ) {
        const photoResponse = yield fetch(
          `${ ctx.restUrl }donor-dashboard/${ slice }/${ card.id }/photo`,
          {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': ctx.nonce },
          }
        );

        if ( ! photoResponse.ok ) {
          const data = yield photoResponse.json();
          s.edit.error =
            data.message || 'Could not remove the image. Please try again.';
          s.edit.saving = false;
          return false;
        }

        card.coverImageUrl = '';
        card.hasCover = false;
        s.photoRemoved = false;
      }

      return true;
    },
  };
}
