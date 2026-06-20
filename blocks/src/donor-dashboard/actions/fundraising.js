/**
 * Donor Dashboard — Fundraising panel state and actions.
 *
 * Editing is scoped to the fundraiser the donor owns; the server re-checks
 * ownership on every request. Saving never changes a fundraiser's status.
 */
import { getContext } from '@wordpress/interactivity';
import { showToast } from '../utils/toast';

/**
 * Find the active fundraiser record in the list.
 *
 * @param {Object} ctx Interactivity context.
 * @return {Object|undefined} The active fundraiser, if any.
 */
export function activeFundraiser( ctx ) {
  const fr = ctx.fundraising;
  return fr?.list?.find( ( item ) => item.id === fr.activeId );
}

/**
 * Copy the active fundraiser's data into the working view + edit objects.
 *
 * @param {Object} ctx Interactivity context.
 */
function syncActive( ctx ) {
  const item = activeFundraiser( ctx );
  if ( ! item ) {
    return;
  }

  ctx.fundraising.view = item;
  ctx.fundraising.edit.headline = item.headline;
  ctx.fundraising.edit.story = item.story;
  ctx.fundraising.edit.goal = item.goalMajor;
  ctx.fundraising.edit.error = '';
  ctx.fundraising.edit.saved = false;
  // The captain sub-section follows the active fundraiser's team (or null).
  ctx.fundraising.captain = item.captain || null;
}

export const fundraisingState = {
  get fundraisingSaveLabel() {
    const fr = getContext().fundraising;
    if ( fr?.edit?.saved ) {
      return fundraisingStrings().saved;
    }
    if ( fr?.edit?.saving ) {
      return fundraisingStrings().saving;
    }
    return fundraisingStrings().save;
  },
  get fundraisingSaveDisabled() {
    return !! getContext().fundraising?.edit?.saving;
  },
  get fundraisingBarWidth() {
    const view = getContext().fundraising?.view;
    return `${ Math.min( 100, Math.round( view?.progress || 0 ) ) }%`;
  },
  get fundraiserTabActive() {
    const ctx = getContext();
    return ctx.f?.id === ctx.fundraising?.activeId;
  },
};

/**
 * Translated button labels, read from context so the script module needs no
 * translation import.
 *
 * @return {Object} Label strings.
 */
function fundraisingStrings() {
  const fr = getContext().fundraising;
  return {
    save: fr?.i18n?.save || 'Save changes',
    saving: fr?.i18n?.saving || 'Saving…',
    saved: fr?.i18n?.saved || 'Saved',
  };
}

export const fundraisingActions = {
  selectFundraiser() {
    const ctx = getContext();
    const id = ctx.f?.id;
    if ( ! id || id === ctx.fundraising.activeId ) {
      return;
    }
    ctx.fundraising.activeId = id;
    syncActive( ctx );
  },

  editHeadline( event ) {
    getContext().fundraising.edit.headline = event.target.value;
  },

  editStory( event ) {
    getContext().fundraising.edit.story = event.target.value;
  },

  editGoal( event ) {
    getContext().fundraising.edit.goal = event.target.value;
  },

  *saveFundraiser() {
    const ctx = getContext();
    const fr = ctx.fundraising;
    const item = activeFundraiser( ctx );
    if ( ! item ) {
      return;
    }

    fr.edit.saving = true;
    fr.edit.saved = false;
    fr.edit.error = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/fundraisers/${ item.id }`,
        {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            headline: fr.edit.headline,
            story: fr.edit.story,
            // Send the goal in major units; the server converts to minor.
            goal: Number( fr.edit.goal ) || 0,
          } ),
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        fr.edit.error = data.message || 'Could not save. Please try again.';
        fr.edit.saving = false;
        return;
      }

      const data = yield response.json();

      // Update the working view + the list record from the server's values
      // (already converted and formatted server-side).
      item.headline = data.headline;
      item.story = data.story;
      item.goalMinor = data.goal;
      item.goalMajor = data.goal_major;
      item.hasGoal = data.goal > 0;
      item.goalDisplay = data.goal_display;
      item.progress = data.progress;
      item.progressLabel = data.progress_label;
      item.raisedDisplay = data.raised_display;
      fr.view = item;

      fr.edit.saving = false;
      fr.edit.saved = true;
      showToast(
        ctx,
        ctx.fundraising.i18n?.savedToast || 'Fundraiser updated'
      );

      setTimeout( () => {
        fr.edit.saved = false;
      }, 2000 );
    } catch {
      fr.edit.error = 'Something went wrong. Please try again.';
      fr.edit.saving = false;
    }
  },

  triggerPhotoUpload( event ) {
    const input = event?.target
      ?.closest( '.mission-fd-cover' )
      ?.querySelector( 'input[type="file"]' );
    if ( input ) {
      input.click();
    }
  },

  *uploadPhoto( event ) {
    const ctx = getContext();
    const fr = ctx.fundraising;
    const file = event?.target?.files?.[ 0 ];
    if ( ! file ) {
      return;
    }

    const item = activeFundraiser( ctx );
    if ( ! item ) {
      return;
    }

    fr.uploading = true;
    fr.uploadError = '';

    const body = new FormData();
    body.append( 'file', file );

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/fundraisers/${ item.id }/photo`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ctx.nonce },
          body,
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        fr.uploadError =
          data.message || 'Could not upload the image. Please try again.';
        fr.uploading = false;
        return;
      }

      const data = yield response.json();
      item.coverImageUrl = data.cover_image_url;
      item.hasCover = !! data.cover_image_url;
      fr.view = item;
      fr.uploading = false;
      showToast(
        ctx,
        ctx.fundraising.i18n?.photoToast || 'Cover photo updated'
      );
    } catch {
      fr.uploadError = 'Something went wrong. Please try again.';
      fr.uploading = false;
    } finally {
      // Reset the input so the same file can be re-selected.
      if ( event?.target ) {
        event.target.value = '';
      }
    }
  },

  copyShareUrl() {
    const ctx = getContext();
    const url = ctx.fundraising?.view?.url;
    if ( ! url ) {
      return;
    }
    window.navigator.clipboard
      ?.writeText( url )
      .then( () =>
        showToast( ctx, ctx.fundraising.i18n?.copied || 'Link copied' )
      )
      .catch( () => {} );
  },

  copyEmbed() {
    const ctx = getContext();
    const embed = ctx.fundraising?.view?.embed;
    if ( ! embed ) {
      return;
    }
    window.navigator.clipboard
      ?.writeText( embed )
      .then( () =>
        showToast(
          ctx,
          ctx.fundraising.i18n?.copiedEmbed || 'Embed code copied'
        )
      )
      .catch( () => {} );
  },
};
