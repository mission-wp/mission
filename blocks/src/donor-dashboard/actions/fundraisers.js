/**
 * Donor Dashboard — My Fundraisers panel and fundraiser detail view.
 *
 * Editing is scoped to a fundraiser the donor owns; the server re-checks
 * ownership (and that the campaign is still live) on every request.
 */
import { getContext } from '@wordpress/interactivity';
import { resizeImageFile } from '@shared/image-resize';
import { showToast } from '../utils/toast';

const GENERIC_ERROR = 'Something went wrong. Please try again.';

// Cover photo staged for upload on save. A File can't live in the reactive
// context, so it's held here; only its preview URL goes in context.
let stagedPhoto = null;

/**
 * Discard the staged cover photo and its local preview.
 *
 * @param {Object} fr Fundraisers context.
 */
function clearStagedPhoto( fr ) {
  if ( fr.photoPreviewUrl ) {
    URL.revokeObjectURL( fr.photoPreviewUrl );
  }
  stagedPhoto = null;
  fr.photoPreviewUrl = '';
}

/**
 * Find a fundraiser card by ID across the Active and Ended lists.
 *
 * @param {Object} ctx Interactivity context.
 * @param {number} id  Fundraiser ID.
 * @return {Object|undefined} The card, if any.
 */
export function findFundraiserCard( ctx, id ) {
  const fr = ctx.fundraisers;
  return (
    fr?.active?.find( ( card ) => card.id === id ) ||
    fr?.ended?.find( ( card ) => card.id === id )
  );
}

/**
 * Point the detail working objects at a card (drill-in or deep link).
 *
 * Ended pages don't embed their supporters, so the first page is fetched
 * here (skeleton rows show while it loads).
 *
 * @param {Object} ctx Interactivity context.
 * @param {number} id  Fundraiser ID.
 */
export function syncFundraiserDetail( ctx, id ) {
  const fr = ctx.fundraisers;
  const card = findFundraiserCard( ctx, id );
  if ( ! fr || ! card ) {
    return;
  }

  fr.detail = card;
  fr.edit.headline = card.headline;
  fr.edit.goal = card.goalMajor;
  fr.edit.story = card.story;
  fr.edit.tributeType = card.tributeType;
  fr.edit.tributeName = card.tributeName;
  fr.edit.saving = false;
  fr.edit.saved = false;
  fr.edit.error = '';
  fr.uploadError = '';
  fr.photoRemoved = false;
  clearStagedPhoto( fr );

  fr.supporters.items = card.supporters || [];
  fr.supporters.page = 1;
  fr.supporters.total = card.supportersTotal || 0;
  fr.supporters.totalPages = Math.ceil(
    ( card.supportersTotal || 0 ) / fr.supporters.perPage
  );
  fr.supporters.loading = false;

  if ( ! fr.supporters.items.length && fr.supporters.total > 0 ) {
    loadSupportersPage( ctx, card.id, 1 );
  }
}

/**
 * Fetch a page of supporters into the working object.
 *
 * @param {Object} ctx  Interactivity context.
 * @param {number} id   Fundraiser ID.
 * @param {number} page Page number (1-based).
 */
function loadSupportersPage( ctx, id, page ) {
  const supporters = ctx.fundraisers.supporters;
  supporters.loading = true;

  fetch(
    `${ ctx.restUrl }donor-dashboard/fundraisers/${ id }/donors?per_page=${ supporters.perPage }&page=${ page }`,
    {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': ctx.nonce },
    }
  )
    .then( ( response ) => {
      if ( ! response.ok ) {
        throw new Error( 'request_failed' );
      }
      const total = Number( response.headers.get( 'X-WP-Total' ) ) || 0;
      const totalPages =
        Number( response.headers.get( 'X-WP-TotalPages' ) ) || 0;
      return response.json().then( ( rows ) => {
        // Ignore stale responses after the user switched fundraisers.
        if ( ctx.fundraisers.detail?.id !== id ) {
          return;
        }
        supporters.total = total;
        supporters.totalPages = totalPages;
        supporters.items = rows.map( ( row ) => ( {
          name: row.name,
          initials: row.initials,
          amount: row.amount,
          timeAgo: row.time_ago,
          comment: row.comment || '',
          hasComment: !! row.comment,
        } ) );
        supporters.page = page;
        supporters.loading = false;
      } );
    } )
    .catch( () => {
      if ( ctx.fundraisers.detail?.id === id ) {
        supporters.loading = false;
      }
    } );
}

/**
 * Translated strings, read from context so the script module needs no
 * translation import.
 *
 * @return {Object} Label strings.
 */
function fundraisersStrings() {
  const i18n = getContext().fundraisers?.i18n || {};
  return {
    save: i18n.save || 'Save changes',
    saving: i18n.saving || 'Saving…',
    saved: i18n.saved || 'Saved',
    range: i18n.range || '%1$s–%2$s of %3$s',
  };
}

export const fundraisersState = {
  get fundraisersSaveLabel() {
    const edit = getContext().fundraisers?.edit;
    if ( edit?.saved ) {
      return fundraisersStrings().saved;
    }
    if ( edit?.saving ) {
      return fundraisersStrings().saving;
    }
    return fundraisersStrings().save;
  },
  get fundraisersSaveDisabled() {
    return !! getContext().fundraisers?.edit?.saving;
  },
  get fundraiserCoverSrc() {
    const fr = getContext().fundraisers;
    if ( fr?.photoPreviewUrl ) {
      return fr.photoPreviewUrl;
    }
    return fr?.photoRemoved ? '' : fr?.detail?.coverImageUrl || '';
  },
  get fundraiserHasCover() {
    const fr = getContext().fundraisers;
    return !! (
      fr?.photoPreviewUrl ||
      ( fr?.detail?.hasCover && ! fr?.photoRemoved )
    );
  },

  // ── Supporters pagination ──
  get supportersNotEmpty() {
    const supporters = getContext().fundraisers?.supporters;
    return !! supporters && ( supporters.total > 0 || supporters.loading );
  },
  get supportersHasPages() {
    return ( getContext().fundraisers?.supporters?.totalPages || 0 ) > 1;
  },
  get supportersRangeLabel() {
    const supporters = getContext().fundraisers?.supporters;
    if ( ! supporters || ! supporters.total ) {
      return '';
    }
    const first = ( supporters.page - 1 ) * supporters.perPage + 1;
    const last = Math.min(
      supporters.page * supporters.perPage,
      supporters.total
    );
    return fundraisersStrings()
      .range.replace( '%1$s', first )
      .replace( '%2$s', last )
      .replace( '%3$s', supporters.total );
  },
  get supportersPrevDisabled() {
    const supporters = getContext().fundraisers?.supporters;
    return ! supporters || supporters.loading || supporters.page <= 1;
  },
  get supportersNextDisabled() {
    const supporters = getContext().fundraisers?.supporters;
    return (
      ! supporters ||
      supporters.loading ||
      supporters.page >= supporters.totalPages
    );
  },
};

export const fundraisersActions = {
  /**
   * Drill into a fundraiser card. The hash drives panel state; the
   * hashchange listener re-syncs the detail objects.
   */
  openFundraiser() {
    const ctx = getContext();
    const id = ctx.card?.id;
    if ( ! id ) {
      return;
    }
    window.location.hash = `fundraiser-${ id }`;
    ctx.activePanel = `fundraiser-${ id }`;
    ctx.sidebarOpen = false;
    syncFundraiserDetail( ctx, id );
  },

  editHeadline( event ) {
    getContext().fundraisers.edit.headline = event.target.value;
  },

  editStory( event ) {
    getContext().fundraisers.edit.story = event.target.value;
  },

  editGoal( event ) {
    getContext().fundraisers.edit.goal = event.target.value;
  },

  editTributeType( event ) {
    getContext().fundraisers.edit.tributeType = event.target.value;
  },

  editTributeName( event ) {
    getContext().fundraisers.edit.tributeName = event.target.value;
  },

  *saveFundraiser() {
    const ctx = getContext();
    const fr = ctx.fundraisers;
    const card = fr.detail;
    if ( ! card ) {
      return;
    }

    fr.edit.saving = true;
    fr.edit.saved = false;
    fr.edit.error = '';

    try {
      if ( stagedPhoto ) {
        const body = new FormData();
        body.append( 'file', stagedPhoto );

        const photoResponse = yield fetch(
          `${ ctx.restUrl }donor-dashboard/fundraisers/${ card.id }/photo`,
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': ctx.nonce },
            body,
          }
        );

        if ( ! photoResponse.ok ) {
          const data = yield photoResponse.json();
          fr.edit.error =
            data.message || 'Could not upload the image. Please try again.';
          fr.edit.saving = false;
          return;
        }

        const photoData = yield photoResponse.json();
        card.coverImageUrl = photoData.cover_image_url;
        card.hasCover = !! photoData.cover_image_url;
        clearStagedPhoto( fr );
      } else if ( fr.photoRemoved && card.hasCover ) {
        const photoResponse = yield fetch(
          `${ ctx.restUrl }donor-dashboard/fundraisers/${ card.id }/photo`,
          {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': ctx.nonce },
          }
        );

        if ( ! photoResponse.ok ) {
          const data = yield photoResponse.json();
          fr.edit.error =
            data.message || 'Could not remove the image. Please try again.';
          fr.edit.saving = false;
          return;
        }

        card.coverImageUrl = '';
        card.hasCover = false;
        fr.photoRemoved = false;
      }

      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/fundraisers/${ card.id }`,
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
            tribute_type: fr.edit.tributeType,
            tribute_name: fr.edit.tributeName,
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

      // The detail object IS the card in the list, so the card updates too
      // (already converted and formatted server-side).
      card.headline = data.headline;
      card.story = data.story;
      card.goalMajor = data.goal_major;
      card.hasGoal = data.goal > 0;
      card.goalDisplay = data.goal_display;
      card.progress = data.progress;
      card.barWidth = `${ Math.min( 100, Math.round( data.progress || 0 ) ) }%`;
      card.percentLabel =
        data.goal > 0
          ? `${ Math.min( 100, Math.round( data.progress || 0 ) ) }%`
          : '';
      card.progressLabel = data.progress_label;
      card.raisedDisplay = data.raised_display;
      card.tributeType = data.tribute_type;
      card.tributeName = data.tribute_name;
      card.dedicationLabel = data.dedication_label;

      fr.edit.saving = false;
      fr.edit.saved = true;
      showToast( ctx, fr.i18n?.savedToast || 'Fundraiser updated' );

      setTimeout( () => {
        fr.edit.saved = false;
      }, 2000 );
    } catch {
      fr.edit.error = GENERIC_ERROR;
      fr.edit.saving = false;
    }
  },

  triggerPhotoUpload( event ) {
    const input = event?.target
      ?.closest( '.mission-dd-cover' )
      ?.querySelector( 'input[type="file"]' );
    if ( input ) {
      input.click();
    }
  },

  /**
   * Stage a selected cover photo for upload on save, previewing it locally.
   * Large photos are downscaled in the browser so they fit the upload limit.
   *
   * @param {Event} event Change event from the file input.
   */
  *selectPhoto( event ) {
    const fr = getContext().fundraisers;
    const original = event?.target?.files?.[ 0 ];

    // Reset the input so the same file can be re-selected.
    if ( event?.target ) {
      event.target.value = '';
    }

    if ( ! fr?.detail || ! original ) {
      return;
    }

    const file = yield resizeImageFile( original, {
      maxBytes: fr.maxPhotoBytes,
    } );

    if ( fr.maxPhotoBytes && file.size > fr.maxPhotoBytes ) {
      fr.uploadError = fr.i18n?.photoTooLarge || 'The image is too large.';
      return;
    }

    clearStagedPhoto( fr );
    fr.uploadError = '';
    fr.photoRemoved = false;
    stagedPhoto = file;
    fr.photoPreviewUrl = URL.createObjectURL( file );
  },

  /**
   * Stage removal of the cover photo; applied on save. With only a staged
   * photo (no saved cover), this just discards the staged photo.
   */
  removePhoto() {
    const fr = getContext().fundraisers;
    if ( ! fr?.detail ) {
      return;
    }
    clearStagedPhoto( fr );
    fr.uploadError = '';
    fr.photoRemoved = !! fr.detail.hasCover;
  },

  /**
   * Copy the URL carried by the clicked element's data-url attribute.
   *
   * @param {Event} event Click event.
   */
  copyLink( event ) {
    const url = event?.target?.closest( '[data-url]' )?.dataset?.url;
    if ( ! url ) {
      return;
    }
    const ctx = getContext();
    window.navigator.clipboard
      ?.writeText( url )
      .then( () =>
        showToast( ctx, ctx.fundraisers?.i18n?.copied || 'Link copied' )
      )
      .catch( () => {} );
  },

  supportersPrev() {
    const ctx = getContext();
    const supporters = ctx.fundraisers.supporters;
    if ( supporters.page > 1 && ! supporters.loading ) {
      loadSupportersPage( ctx, ctx.fundraisers.detail.id, supporters.page - 1 );
    }
  },

  supportersNext() {
    const ctx = getContext();
    const supporters = ctx.fundraisers.supporters;
    if ( supporters.page < supporters.totalPages && ! supporters.loading ) {
      loadSupportersPage( ctx, ctx.fundraisers.detail.id, supporters.page + 1 );
    }
  },
};
