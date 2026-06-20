/**
 * Donor Dashboard — Team captain controls (sub-section of the Fundraising panel).
 *
 * Only shown when the active fundraiser leads a team. Every request is
 * captain-scoped; the server re-checks captaincy on each call.
 */
import { getContext } from '@wordpress/interactivity';
import { showToast } from '../utils/toast';
import { activeFundraiser } from './fundraising';

const GENERIC_ERROR = 'Something went wrong. Please try again.';

/**
 * The captain working object for the active fundraiser, if any.
 *
 * @param {Object} ctx Interactivity context.
 * @return {Object|null} The captain block, or null.
 */
function captainBlock( ctx ) {
  return ctx.fundraising?.captain || null;
}

export const teamState = {
  get isCaptain() {
    return !! getContext().fundraising?.captain;
  },
  get teamSaveLabel() {
    const cap = getContext().fundraising?.captain;
    const i18n = getContext().fundraising?.i18n || {};
    if ( cap?.saved ) {
      return i18n.saved || 'Saved';
    }
    if ( cap?.saving ) {
      return i18n.saving || 'Saving…';
    }
    return i18n.save || 'Save changes';
  },
  get teamSaveDisabled() {
    return !! getContext().fundraising?.captain?.saving;
  },
  get hasInvitations() {
    return ( getContext().fundraising?.captain?.invitations?.length || 0 ) > 0;
  },
};

export const teamActions = {
  editTeamName( event ) {
    const cap = captainBlock( getContext() );
    if ( cap ) {
      cap.name = event.target.value;
    }
  },

  editTeamDescription( event ) {
    const cap = captainBlock( getContext() );
    if ( cap ) {
      cap.description = event.target.value;
    }
  },

  editTeamGoal( event ) {
    const cap = captainBlock( getContext() );
    if ( cap ) {
      cap.goal = event.target.value;
    }
  },

  editInviteEmail( event ) {
    const cap = captainBlock( getContext() );
    if ( cap ) {
      cap.inviteEmail = event.target.value;
    }
  },

  *saveTeam() {
    const ctx = getContext();
    const cap = captainBlock( ctx );
    if ( ! cap ) {
      return;
    }

    cap.saving = true;
    cap.saved = false;
    cap.error = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ cap.teamId }`,
        {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            name: cap.name,
            description: cap.description,
            // Send the goal in major units; the server converts to minor.
            goal: Number( cap.goal ) || 0,
          } ),
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        cap.error = data.message || 'Could not save. Please try again.';
        cap.saving = false;
        return;
      }

      const data = yield response.json();
      cap.name = data.name;
      cap.description = data.description;
      cap.goal = data.goal_major;
      cap.saving = false;
      cap.saved = true;
      showToast( ctx, ctx.fundraising.i18n?.teamToast || 'Team updated' );

      setTimeout( () => {
        cap.saved = false;
      }, 2000 );
    } catch {
      cap.error = GENERIC_ERROR;
      cap.saving = false;
    }
  },

  triggerTeamPhotoUpload( event ) {
    const input = event?.target
      ?.closest( '.mission-dd-team-cover' )
      ?.querySelector( 'input[type="file"]' );
    if ( input ) {
      input.click();
    }
  },

  *uploadTeamPhoto( event ) {
    const ctx = getContext();
    const cap = captainBlock( ctx );
    const file = event?.target?.files?.[ 0 ];
    if ( ! cap || ! file ) {
      return;
    }

    cap.uploading = true;
    cap.uploadError = '';

    const body = new FormData();
    body.append( 'file', file );

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ cap.teamId }/photo`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ctx.nonce },
          body,
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        cap.uploadError =
          data.message || 'Could not upload the image. Please try again.';
        cap.uploading = false;
        return;
      }

      const data = yield response.json();
      cap.coverImageUrl = data.cover_image_url;
      cap.hasCover = !! data.cover_image_url;
      cap.uploading = false;
      showToast( ctx, ctx.fundraising.i18n?.teamPhoto || 'Team image updated' );
    } catch {
      cap.uploadError = GENERIC_ERROR;
      cap.uploading = false;
    } finally {
      if ( event?.target ) {
        event.target.value = '';
      }
    }
  },

  *inviteMember() {
    const ctx = getContext();
    const cap = captainBlock( ctx );
    if ( ! cap ) {
      return;
    }

    const email = ( cap.inviteEmail || '' ).trim();
    if ( ! email ) {
      return;
    }

    cap.inviting = true;
    cap.inviteError = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ cap.teamId }/invite`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( { email } ),
        }
      );

      const data = yield response.json();

      if ( ! response.ok ) {
        cap.inviteError = data.message || GENERIC_ERROR;
        cap.inviting = false;
        return;
      }

      cap.invitations = data.invitations || [];
      cap.inviteEmail = '';
      cap.inviting = false;
      showToast( ctx, ctx.fundraising.i18n?.inviteToast || 'Invitation sent' );
    } catch {
      cap.inviteError = GENERIC_ERROR;
      cap.inviting = false;
    }
  },

  *removeMember() {
    const ctx = getContext();
    const cap = captainBlock( ctx );
    const memberId = ctx.member?.fundraiserId;
    if ( ! cap || ! memberId ) {
      return;
    }

    const confirmMsg = ctx.fundraising?.i18n?.confirmRemove;
    // eslint-disable-next-line no-alert
    if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
      return;
    }

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ cap.teamId }/members/${ memberId }/remove`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ctx.nonce },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        cap.error = data.message || GENERIC_ERROR;
        return;
      }

      const data = yield response.json();
      cap.members = data.members || [];
      showToast( ctx, ctx.fundraising.i18n?.removeToast || 'Member removed' );
    } catch {
      cap.error = GENERIC_ERROR;
    }
  },

  *promoteMember() {
    const ctx = getContext();
    const cap = captainBlock( ctx );
    const memberId = ctx.member?.fundraiserId;
    if ( ! cap || ! memberId ) {
      return;
    }

    const confirmMsg = ctx.fundraising?.i18n?.confirmPromote;
    // eslint-disable-next-line no-alert
    if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
      return;
    }

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ cap.teamId }/members/${ memberId }/promote`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ctx.nonce },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        cap.error = data.message || GENERIC_ERROR;
        return;
      }

      // The acting donor is no longer captain; drop the management section.
      const item = activeFundraiser( ctx );
      if ( item ) {
        item.captain = null;
      }
      ctx.fundraising.captain = null;
      showToast( ctx, ctx.fundraising.i18n?.promoteToast || 'New captain set' );
    } catch {
      cap.error = GENERIC_ERROR;
    }
  },
};
