/**
 * Donor Dashboard — My Teams panel and team detail view.
 *
 * Captains manage the team; members can view the roster and leave. Every
 * request is re-checked server-side (captaincy, ownership, and campaign
 * lock), so the client state is presentation only.
 */
import { getContext } from '@wordpress/interactivity';
import { showToast } from '../utils/toast';
import { createPhotoFlow } from '../utils/photo-flow';
import { findFundraiserCard } from './fundraisers';

const GENERIC_ERROR = 'Something went wrong. Please try again.';

const photo = createPhotoFlow( 'teams', 'teams' );

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
  teams.membersItems = card.members || [];
  teams.membersTotal = card.membersTotal || 0;
  teams.membersPages = card.membersTotalPages || 0;
  teams.membersLoading = false;
  teams.uploadError = '';
  teams.photoRemoved = false;
  photo.clearStaged( teams );
}

/**
 * Fetch one page of the team roster from the server.
 *
 * @param {Object} ctx  Interactivity context.
 * @param {number} id   Team ID.
 * @param {number} page Page number to load.
 */
function loadMembersPage( ctx, id, page ) {
  const teams = ctx.teams;
  teams.membersLoading = true;

  fetch(
    `${ ctx.restUrl }donor-dashboard/teams/${ id }/members?per_page=${ teams.membersPerPage }&page=${ page }`,
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
        // Ignore stale responses after the user switched teams.
        if ( ctx.teams.detail?.id !== id ) {
          return;
        }
        teams.membersItems = rows;
        teams.membersTotal = total;
        teams.membersPages = totalPages;
        teams.membersPage = page;
        teams.membersLoading = false;
      } );
    } )
    .catch( () => {
      if ( ctx.teams.detail?.id === id ) {
        teams.membersLoading = false;
        showToast(
          ctx,
          ctx.teams?.i18n?.membersError ||
            "Couldn't load members. Please try again.",
          'error'
        );
      }
    } );
}

/**
 * Apply a fresh roster payload (first page plus counts) from a team response.
 *
 * @param {Object} teams Teams context slice.
 * @param {Object} card  Team card being updated.
 * @param {Object} data  Team payload from the REST API.
 */
function applyRoster( teams, card, data ) {
  card.members = data.members || [];
  card.memberCount = data.member_count || 0;
  card.membersTotal = data.member_count || 0;
  card.membersTotalPages = data.members_total_pages || 0;
  if ( data.member_count_label ) {
    card.memberCountLabel = data.member_count_label;
  }
  teams.membersPage = 1;
  teams.membersItems = card.members;
  teams.membersTotal = card.membersTotal;
  teams.membersPages = card.membersTotalPages;
  teams.membersLoading = false;
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
    return photo.coverSrc();
  },
  get teamHasCover() {
    return photo.hasCover();
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

  // ── Members pagination (server pages; page 1 is embedded in the card) ──
  get teamMembersPageItems() {
    return getContext().teams?.membersItems || [];
  },
  get teamMembersHasPages() {
    return ( getContext().teams?.membersPages || 0 ) > 1;
  },
  get teamMembersRangeLabel() {
    const teams = getContext().teams;
    const total = teams?.membersTotal || 0;
    if ( ! total ) {
      return '';
    }
    const first = ( teams.membersPage - 1 ) * teams.membersPerPage + 1;
    const last = Math.min(
      first + ( teams.membersItems?.length || 0 ) - 1,
      total
    );
    return teamStrings()
      .range.replace( '%1$s', first )
      .replace( '%2$s', last )
      .replace( '%3$s', total );
  },
  get teamMembersPrevDisabled() {
    const teams = getContext().teams;
    return !! teams?.membersLoading || ( teams?.membersPage || 1 ) <= 1;
  },
  get teamMembersNextDisabled() {
    const teams = getContext().teams;
    return (
      !! teams?.membersLoading ||
      ( teams?.membersPage || 1 ) >= ( teams?.membersPages || 0 )
    );
  },
};

export const teamActions = {
  /**
   * Drill into a team card. The hash drives panel state; the hashchange
   * listener syncs the panel and detail objects.
   */
  openTeam() {
    const ctx = getContext();
    const id = ctx.card?.id;
    if ( ! id ) {
      return;
    }
    window.location.hash = `team-${ id }`;
    ctx.sidebarOpen = false;
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
      if ( ! ( yield* photo.save( ctx, card ) ) ) {
        return;
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
      card.barWidth = data.bar_width;
      card.percentLabel = data.percent_label;
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

  triggerTeamPhotoUpload: photo.trigger,
  selectTeamPhoto: photo.select,
  removeTeamPhoto: photo.remove,

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
      applyRoster( teams, card, data );
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

      card.isCaptain = false;
      card.roleLabel = teams.i18n?.memberRole || 'Member';
      card.captainName = member.name;
      card.captainChipLabel = (
        teams.i18n?.captainChip || 'Captain: %s'
      ).replace( '%s', member.name );
      applyRoster( teams, card, data );
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
    const ctx = getContext();
    const teams = ctx.teams;
    if ( teams.membersPage > 1 && ! teams.membersLoading ) {
      loadMembersPage( ctx, teams.detail.id, teams.membersPage - 1 );
    }
  },

  teamMembersNext() {
    const ctx = getContext();
    const teams = ctx.teams;
    if ( teams.membersPage < teams.membersPages && ! teams.membersLoading ) {
      loadMembersPage( ctx, teams.detail.id, teams.membersPage + 1 );
    }
  },
};
