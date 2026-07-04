/**
 * Donor Dashboard — My Teams panel and team detail view.
 *
 * Captains manage the team; members can view the roster and leave. Every
 * request is re-checked server-side (captaincy, ownership, and campaign
 * lock), so the client state is presentation only.
 */
import { getContext } from '@wordpress/interactivity';
import { resizeImageFile } from '@shared/image-resize';
import { showToast } from '../utils/toast';
import { findFundraiserCard } from './fundraisers';

const GENERIC_ERROR = 'Something went wrong. Please try again.';

// Team image staged for upload on save. A File can't live in the reactive
// context, so it's held here; only its preview URL goes in context.
let stagedTeamPhoto = null;

/**
 * Discard the staged team image and its local preview.
 *
 * @param {Object} teams Teams context.
 */
function clearStagedTeamPhoto( teams ) {
  if ( teams.photoPreviewUrl ) {
    URL.revokeObjectURL( teams.photoPreviewUrl );
  }
  stagedTeamPhoto = null;
  teams.photoPreviewUrl = '';
}

/**
 * Find a current-team card by ID.
 *
 * @param {Object} ctx Interactivity context.
 * @param {number} id  Team ID.
 * @return {Object|undefined} The card, if any.
 */
export function findTeamCard( ctx, id ) {
  return ctx.teams?.current?.find( ( card ) => card.id === id );
}

/**
 * Point the team detail working objects at a card (drill-in or deep link).
 *
 * @param {Object} ctx Interactivity context.
 * @param {number} id  Team ID.
 */
export function syncTeamDetail( ctx, id ) {
  const teams = ctx.teams;
  const card = findTeamCard( ctx, id );
  if ( ! teams || ! card ) {
    return;
  }

  teams.detail = card;
  teams.edit.name = card.name;
  teams.edit.goal = card.goalMajor;
  teams.edit.access = card.access;
  teams.edit.description = card.description;
  teams.edit.saving = false;
  teams.edit.saved = false;
  teams.edit.error = '';
  teams.invite.email = '';
  teams.invite.error = '';
  teams.invite.sending = false;
  teams.membersPage = 1;
  teams.uploadError = '';
  teams.photoRemoved = false;
  clearStagedTeamPhoto( teams );
}

/**
 * Map a member row from a REST response into the context shape.
 *
 * @param {Object} row Member row from the team endpoints.
 * @param {Object} ctx Interactivity context.
 * @return {Object} Member for the context.
 */
function mapMember( row, ctx ) {
  const progress = Math.min( 100, Math.round( row.progress || 0 ) );

  return {
    fundraiserId: row.fundraiser_id,
    donorId: row.donor_id,
    name: row.name,
    initials: row.initials,
    isCaptain: row.is_captain,
    isSelf: row.donor_id === ctx.teams?.donorId,
    progress: row.progress || 0,
    barWidth: `${ progress }%`,
    raisedOfGoalLabel: row.raised_of_goal_label,
  };
}

/**
 * Translated strings, read from context so the script module needs no
 * translation import.
 *
 * @return {Object} Label strings.
 */
function teamStrings() {
  const i18n = getContext().teams?.i18n || {};
  return {
    save: i18n.save || 'Save changes',
    saving: i18n.saving || 'Saving…',
    saved: i18n.saved || 'Saved',
    range: i18n.range || '%1$s–%2$s of %3$s',
  };
}

export const teamState = {
  get teamSaveLabel() {
    const edit = getContext().teams?.edit;
    if ( edit?.saved ) {
      return teamStrings().saved;
    }
    if ( edit?.saving ) {
      return teamStrings().saving;
    }
    return teamStrings().save;
  },
  get teamSaveDisabled() {
    return !! getContext().teams?.edit?.saving;
  },
  get teamCoverSrc() {
    const teams = getContext().teams;
    if ( teams?.photoPreviewUrl ) {
      return teams.photoPreviewUrl;
    }
    return teams?.photoRemoved ? '' : teams?.detail?.coverImageUrl || '';
  },
  get teamHasCover() {
    const teams = getContext().teams;
    return !! (
      teams?.photoPreviewUrl ||
      ( teams?.detail?.hasCover && ! teams?.photoRemoved )
    );
  },
  get hasInvitations() {
    return ( getContext().teams?.detail?.invitations?.length || 0 ) > 0;
  },
  get teamPendingNoticeVisible() {
    const detail = getContext().teams?.detail;
    return !! detail?.isCaptain && !! detail?.isPending;
  },
  get memberActionsHidden() {
    const ctx = getContext();
    return (
      ! ctx.teams?.detail?.isCaptain ||
      !! ctx.member?.isCaptain ||
      !! ctx.member?.isSelf
    );
  },

  // ── Members pagination (client-side; the full roster is in context) ──
  get teamMembersPageItems() {
    const teams = getContext().teams;
    const members = teams?.detail?.members || [];
    const start = ( ( teams?.membersPage || 1 ) - 1 ) * teams.membersPerPage;
    return members.slice( start, start + teams.membersPerPage );
  },
  get teamMembersHasPages() {
    const teams = getContext().teams;
    return (
      ( teams?.detail?.members?.length || 0 ) > ( teams?.membersPerPage || 5 )
    );
  },
  get teamMembersRangeLabel() {
    const teams = getContext().teams;
    const total = teams?.detail?.members?.length || 0;
    if ( ! total ) {
      return '';
    }
    const first = ( teams.membersPage - 1 ) * teams.membersPerPage + 1;
    const last = Math.min( teams.membersPage * teams.membersPerPage, total );
    return teamStrings()
      .range.replace( '%1$s', first )
      .replace( '%2$s', last )
      .replace( '%3$s', total );
  },
  get teamMembersPrevDisabled() {
    return ( getContext().teams?.membersPage || 1 ) <= 1;
  },
  get teamMembersNextDisabled() {
    const teams = getContext().teams;
    const total = teams?.detail?.members?.length || 0;
    return teams?.membersPage >= Math.ceil( total / teams?.membersPerPage );
  },
};

export const teamActions = {
  /**
   * Drill into a team card. The hash drives panel state; the hashchange
   * listener re-syncs the detail objects.
   */
  openTeam() {
    const ctx = getContext();
    const id = ctx.card?.id;
    if ( ! id ) {
      return;
    }
    window.location.hash = `team-${ id }`;
    ctx.activePanel = `team-${ id }`;
    ctx.sidebarOpen = false;
    syncTeamDetail( ctx, id );
  },

  editTeamName( event ) {
    getContext().teams.edit.name = event.target.value;
  },

  editTeamDescription( event ) {
    getContext().teams.edit.description = event.target.value;
  },

  editTeamGoal( event ) {
    getContext().teams.edit.goal = event.target.value;
  },

  editTeamAccess( event ) {
    getContext().teams.edit.access = event.target.value;
  },

  editInviteEmail( event ) {
    getContext().teams.invite.email = event.target.value;
  },

  *saveTeam() {
    const ctx = getContext();
    const teams = ctx.teams;
    const card = teams.detail;
    if ( ! card ) {
      return;
    }

    teams.edit.saving = true;
    teams.edit.saved = false;
    teams.edit.error = '';

    try {
      // Upload the staged team image first; a failure aborts the save so
      // the captain can fix the image and try again.
      if ( stagedTeamPhoto ) {
        const body = new FormData();
        body.append( 'file', stagedTeamPhoto );

        const photoResponse = yield fetch(
          `${ ctx.restUrl }donor-dashboard/teams/${ card.id }/photo`,
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': ctx.nonce },
            body,
          }
        );

        if ( ! photoResponse.ok ) {
          const data = yield photoResponse.json();
          teams.edit.error =
            data.message || 'Could not upload the image. Please try again.';
          teams.edit.saving = false;
          return;
        }

        const photoData = yield photoResponse.json();
        card.coverImageUrl = photoData.cover_image_url;
        card.hasCover = !! photoData.cover_image_url;
        clearStagedTeamPhoto( teams );
      } else if ( teams.photoRemoved && card.hasCover ) {
        const photoResponse = yield fetch(
          `${ ctx.restUrl }donor-dashboard/teams/${ card.id }/photo`,
          {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': ctx.nonce },
          }
        );

        if ( ! photoResponse.ok ) {
          const data = yield photoResponse.json();
          teams.edit.error =
            data.message || 'Could not remove the image. Please try again.';
          teams.edit.saving = false;
          return;
        }

        card.coverImageUrl = '';
        card.hasCover = false;
        teams.photoRemoved = false;
      }

      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ card.id }`,
        {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ctx.nonce,
          },
          body: JSON.stringify( {
            name: teams.edit.name,
            description: teams.edit.description,
            // Send the goal in major units; the server converts to minor.
            goal: Number( teams.edit.goal ) || 0,
            access: teams.edit.access,
          } ),
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        teams.edit.error = data.message || 'Could not save. Please try again.';
        teams.edit.saving = false;
        return;
      }

      const data = yield response.json();

      // The detail object IS the card in the list, so the card updates too.
      card.name = data.name;
      card.description = data.description;
      card.goalMajor = data.goal_major;
      card.goalDisplay = data.goal_display;
      card.hasGoal = data.goal > 0;
      card.access = data.access;
      card.isPrivate = data.access === 'private';
      card.progress = data.progress;
      card.barWidth = `${ Math.min( 100, Math.round( data.progress || 0 ) ) }%`;
      card.percentLabel =
        data.goal > 0
          ? `${ Math.min( 100, Math.round( data.progress || 0 ) ) }%`
          : '';
      card.raisedDisplay = data.raised_display;

      teams.edit.saving = false;
      teams.edit.saved = true;
      showToast( ctx, teams.i18n?.teamToast || 'Team updated' );

      setTimeout( () => {
        teams.edit.saved = false;
      }, 2000 );
    } catch {
      teams.edit.error = GENERIC_ERROR;
      teams.edit.saving = false;
    }
  },

  triggerTeamPhotoUpload( event ) {
    const input = event?.target
      ?.closest( '.mission-dd-cover' )
      ?.querySelector( 'input[type="file"]' );
    if ( input ) {
      input.click();
    }
  },

  /**
   * Stage a selected team image for upload on save, previewing it locally.
   * Large images are downscaled in the browser so they fit the upload limit.
   *
   * @param {Event} event Change event from the file input.
   */
  *selectTeamPhoto( event ) {
    const teams = getContext().teams;
    const original = event?.target?.files?.[ 0 ];

    // Reset the input so the same file can be re-selected.
    if ( event?.target ) {
      event.target.value = '';
    }

    if ( ! teams?.detail || ! original ) {
      return;
    }

    const file = yield resizeImageFile( original, {
      maxBytes: teams.maxPhotoBytes,
    } );

    if ( teams.maxPhotoBytes && file.size > teams.maxPhotoBytes ) {
      teams.uploadError =
        teams.i18n?.photoTooLarge || 'The image is too large.';
      return;
    }

    clearStagedTeamPhoto( teams );
    teams.uploadError = '';
    teams.photoRemoved = false;
    stagedTeamPhoto = file;
    teams.photoPreviewUrl = URL.createObjectURL( file );
  },

  /**
   * Stage removal of the team image; applied on save. With only a staged
   * image (no saved one), this just discards the staged image.
   */
  removeTeamPhoto() {
    const teams = getContext().teams;
    if ( ! teams?.detail ) {
      return;
    }
    clearStagedTeamPhoto( teams );
    teams.uploadError = '';
    teams.photoRemoved = !! teams.detail.hasCover;
  },

  *inviteMember() {
    const ctx = getContext();
    const teams = ctx.teams;
    const card = teams.detail;
    const email = ( teams.invite.email || '' ).trim();
    if ( ! card || ! email ) {
      return;
    }

    teams.invite.sending = true;
    teams.invite.error = '';

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ card.id }/invite`,
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
        teams.invite.error = data.message || GENERIC_ERROR;
        teams.invite.sending = false;
        return;
      }

      card.invitations = data.invitations || [];
      teams.invite.email = '';
      teams.invite.sending = false;
      showToast( ctx, teams.i18n?.inviteToast || 'Invitation sent' );
    } catch {
      teams.invite.error = GENERIC_ERROR;
      teams.invite.sending = false;
    }
  },

  *removeMember() {
    const ctx = getContext();
    const teams = ctx.teams;
    const card = teams.detail;
    const memberId = ctx.member?.fundraiserId;
    if ( ! card || ! memberId ) {
      return;
    }

    const confirmMsg = teams.i18n?.confirmRemove;
    // eslint-disable-next-line no-alert
    if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
      return;
    }

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ card.id }/members/${ memberId }/remove`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ctx.nonce },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        teams.edit.error = data.message || GENERIC_ERROR;
        return;
      }

      const data = yield response.json();
      card.members = ( data.members || [] ).map( ( row ) =>
        mapMember( row, ctx )
      );
      card.memberCount = card.members.length;
      teams.membersPage = 1;
      showToast( ctx, teams.i18n?.removeToast || 'Member removed' );
    } catch {
      teams.edit.error = GENERIC_ERROR;
    }
  },

  *promoteMember() {
    const ctx = getContext();
    const teams = ctx.teams;
    const card = teams.detail;
    const member = ctx.member;
    if ( ! card || ! member?.fundraiserId ) {
      return;
    }

    const confirmMsg = teams.i18n?.confirmPromote;
    // eslint-disable-next-line no-alert
    if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
      return;
    }

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/teams/${ card.id }/members/${ member.fundraiserId }/promote`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ctx.nonce },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        teams.edit.error = data.message || GENERIC_ERROR;
        return;
      }

      const data = yield response.json();

      // The acting donor is no longer captain; the management tools drop away.
      card.isCaptain = false;
      card.roleLabel = teams.i18n?.memberRole || 'Member';
      card.captainName = member.name;
      card.captainChipLabel = (
        teams.i18n?.captainChip || 'Captain: %s'
      ).replace( '%s', member.name );
      card.members = ( data.members || [] ).map( ( row ) =>
        mapMember( row, ctx )
      );
      card.invitations = [];
      showToast( ctx, teams.i18n?.promoteToast || 'New captain set' );
    } catch {
      teams.edit.error = GENERIC_ERROR;
    }
  },

  *leaveTeam() {
    const ctx = getContext();
    const teams = ctx.teams;
    const card = teams.detail;
    if ( ! card || teams.leaving ) {
      return;
    }

    const confirmMsg = teams.i18n?.confirmLeave;
    // eslint-disable-next-line no-alert
    if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
      return;
    }

    teams.leaving = true;

    try {
      const response = yield fetch(
        `${ ctx.restUrl }donor-dashboard/fundraisers/${ card.myFundraiserId }/leave-team`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ctx.nonce },
        }
      );

      if ( ! response.ok ) {
        const data = yield response.json();
        teams.leaving = false;
        showToast( ctx, data.message || GENERIC_ERROR, 'error' );
        return;
      }

      // Drop the team from the list and detach it from the fundraiser card.
      const index = teams.current.findIndex( ( row ) => row.id === card.id );
      if ( index > -1 ) {
        teams.current.splice( index, 1 );
      }
      teams.hasCurrent = teams.current.length > 0;
      teams.ids = teams.current.map( ( row ) => row.id );

      const fundraiserCard = findFundraiserCard( ctx, card.myFundraiserId );
      if ( fundraiserCard ) {
        fundraiserCard.onTeam = false;
        fundraiserCard.teamId = null;
        fundraiserCard.teamName = '';
        fundraiserCard.teamUrl = '';
        fundraiserCard.roleLabel = '';
      }

      teams.leaving = false;
      window.location.hash = 'teams';
      ctx.activePanel = 'teams';
      showToast( ctx, teams.i18n?.leaveToast || 'You left the team' );
    } catch {
      teams.leaving = false;
      showToast( ctx, GENERIC_ERROR, 'error' );
    }
  },

  teamMembersPrev() {
    const teams = getContext().teams;
    if ( teams.membersPage > 1 ) {
      teams.membersPage--;
    }
  },

  teamMembersNext() {
    const teams = getContext().teams;
    const total = teams.detail?.members?.length || 0;
    if ( teams.membersPage < Math.ceil( total / teams.membersPerPage ) ) {
      teams.membersPage++;
    }
  },
};
