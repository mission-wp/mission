import { useState } from '@wordpress/element';
import {
  Button,
  Modal,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatDate } from '@shared/date';
import { formatAmount } from '@shared/currency';
import ClickableRows from '@shared/components/ClickableRows';
import DetailCard from '../../components/DetailCard';
import DonorAvatar from '../../components/DonorAvatar';

function MemberProgress( { raised, goal } ) {
  const percent =
    goal > 0 ? Math.min( 100, Math.round( ( raised / goal ) * 100 ) ) : 0;

  return (
    <div className="mission-progress-bar mission-progress-bar--wide">
      <span className="mission-progress-bar__track">
        <span
          className="mission-progress-bar__fill"
          style={ { width: `${ percent }%` } }
        />
      </span>
      <span className="mission-progress-bar__text">
        { goal > 0
          ? sprintf(
              /* translators: 1: amount raised, 2: goal amount */
              __( '%1$s of %2$s', 'mission-donation-platform' ),
              formatAmount( raised ),
              formatAmount( goal )
            )
          : formatAmount( raised ) }
      </span>
    </div>
  );
}

/**
 * Team members table with per-member progress and management actions.
 *
 * @param {Object}   props           Component props.
 * @param {Array}    props.members   Member fundraisers from the API.
 * @param {Function} props.onPromote Promotes a member to captain.
 * @param {Function} props.onRemove  Removes a member from the team.
 * @return {JSX.Element} The card.
 */
export default function TeamMembersCard( { members, onPromote, onRemove } ) {
  const [ confirmRemove, setConfirmRemove ] = useState( null );
  const [ isRemoving, setIsRemoving ] = useState( false );
  const [ confirmPromote, setConfirmPromote ] = useState( null );
  const [ isPromoting, setIsPromoting ] = useState( false );

  const adminUrl = window.missiondpAdmin?.adminUrl || '';

  const handleConfirmRemove = async () => {
    setIsRemoving( true );
    try {
      await onRemove( confirmRemove );
      setConfirmRemove( null );
    } finally {
      setIsRemoving( false );
    }
  };

  const handleConfirmPromote = async () => {
    setIsPromoting( true );
    try {
      await onPromote( confirmPromote );
      setConfirmPromote( null );
    } finally {
      setIsPromoting( false );
    }
  };

  return (
    <DetailCard
      title={ __( 'Members', 'mission-donation-platform' ) }
      badge={ sprintf(
        /* translators: %d: number of team members */
        _n(
          '%d fundraiser',
          '%d fundraisers',
          members.length,
          'mission-donation-platform'
        ),
        members.length
      ) }
    >
      { ! members.length ? (
        <p className="mission-detail-table__empty">
          { __( 'No members yet.', 'mission-donation-platform' ) }
        </p>
      ) : (
        <ClickableRows>
          <div className="mission-detail-table__overflow">
            <table className="mission-detail-table">
              <thead>
                <tr>
                  <th>{ __( 'Member', 'mission-donation-platform' ) }</th>
                  <th>{ __( 'Progress', 'mission-donation-platform' ) }</th>
                  <th>{ __( 'Donations', 'mission-donation-platform' ) }</th>
                  <th>{ __( 'Joined', 'mission-donation-platform' ) }</th>
                </tr>
              </thead>
              <tbody>
                { members.map( ( member ) => {
                  const nameParts = ( member.donor_name || '' ).split( ' ' );
                  return (
                    <tr key={ member.id }>
                      <td>
                        <span className="mission-donor-cell">
                          <DonorAvatar
                            firstName={ nameParts[ 0 ] }
                            lastName={ nameParts.slice( 1 ).join( ' ' ) }
                          />
                          <span className="mission-member-cell">
                            <span className="mission-member-cell__name">
                              <a
                                href={ `${ adminUrl }admin.php?page=mission-donation-platform-fundraisers&fundraiser_id=${ member.id }` }
                                className="mission-table-link"
                              >
                                { member.donor_name }
                              </a>
                              { member.is_team_captain && (
                                <span className="mission-role-badge">
                                  { __(
                                    'Captain',
                                    'mission-donation-platform'
                                  ) }
                                </span>
                              ) }
                            </span>
                            { ! member.is_team_captain && (
                              <span className="mission-row-actions">
                                <button
                                  type="button"
                                  className="mission-row-action"
                                  onClick={ () => setConfirmPromote( member ) }
                                >
                                  { __(
                                    'Make captain',
                                    'mission-donation-platform'
                                  ) }
                                </button>
                                <button
                                  type="button"
                                  className="mission-row-action mission-row-action--danger"
                                  onClick={ () => setConfirmRemove( member ) }
                                >
                                  { __(
                                    'Remove',
                                    'mission-donation-platform'
                                  ) }
                                </button>
                              </span>
                            ) }
                          </span>
                        </span>
                      </td>
                      <td>
                        <MemberProgress
                          raised={ member.raised }
                          goal={ member.goal }
                        />
                      </td>
                      <td>{ member.transaction_count }</td>
                      <td className="mission-detail-table__muted">
                        { formatDate( member.date_created ) }
                      </td>
                    </tr>
                  );
                } ) }
              </tbody>
            </table>
          </div>
        </ClickableRows>
      ) }

      { confirmPromote && (
        <Modal
          title={ __( 'Make Team Captain', 'mission-donation-platform' ) }
          onRequestClose={ () => setConfirmPromote( null ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { sprintf(
                /* translators: %s: member name */
                __(
                  'Make %s the team captain? The current captain becomes a regular member.',
                  'mission-donation-platform'
                ),
                confirmPromote.donor_name
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setConfirmPromote( null ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isBusy={ isPromoting }
                disabled={ isPromoting }
                onClick={ handleConfirmPromote }
                __next40pxDefaultSize
              >
                { __( 'Make Captain', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }

      { confirmRemove && (
        <Modal
          title={ __( 'Remove Team Member', 'mission-donation-platform' ) }
          onRequestClose={ () => setConfirmRemove( null ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { sprintf(
                /* translators: %s: member name */
                __(
                  'Remove %s from this team? Their fundraising page stays active, but their totals no longer count toward the team.',
                  'mission-donation-platform'
                ),
                confirmRemove.donor_name
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setConfirmRemove( null ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                isBusy={ isRemoving }
                disabled={ isRemoving }
                onClick={ handleConfirmRemove }
                __next40pxDefaultSize
              >
                { __( 'Remove', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }
    </DetailCard>
  );
}
