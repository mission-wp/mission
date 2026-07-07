<?php
/**
 * Listens for peer-to-peer team events and emails captains and invitees.
 *
 * Sends an invitation email when a captain invites someone to a private team, a
 * "new member joined" email to the captain, and a "team approved" email to the
 * captain when a pending team goes live. Invitations to a still-pending team
 * are held (sent_at stays NULL, so their page link would 404) and flushed when
 * the team is approved.
 *
 * @package MissionDP
 */

namespace MissionDP\Email;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Team email listener class.
 */
class TeamEmailListener {

	/**
	 * Email module instance.
	 *
	 * @var EmailModule
	 */
	private EmailModule $email;

	/**
	 * Initialize the listener with event hooks.
	 *
	 * @param EmailModule $email Email module.
	 * @return void
	 */
	public function init( EmailModule $email ): void {
		$this->email = $email;

		add_action( 'mission_team_invitation_created', [ $this, 'on_invitation_created' ] );
		add_action( 'mission_team_joined', [ $this, 'on_member_joined' ], 10, 2 );
		add_action( 'mission_team_approved', [ $this, 'on_team_approved' ] );
		add_action( 'mission_team_approved', [ $this, 'flush_pending_invitations' ] );
	}

	/**
	 * Email an invitee their invitation link and stamp the invitation as sent.
	 *
	 * @param TeamInvitation $invitation The pending invitation.
	 * @return void
	 */
	public function on_invitation_created( TeamInvitation $invitation ): void {
		if ( ! $invitation->email || ! $this->email->is_email_enabled( 'p2p_team_invitation' ) ) {
			return;
		}

		$team = $invitation->team();
		if ( ! $team ) {
			return;
		}

		// A pending team's page isn't publicly visible yet, so the accept link
		// would 404. Hold the email; flush_pending_invitations() sends it on approval.
		if ( Team::STATUS_ACTIVE !== $team->status ) {
			return;
		}

		$org_name   = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );
		$accept_url = add_query_arg( 'team_invite', rawurlencode( $invitation->token ), (string) $team->get_url() );

		$data = [
			'team'         => $team,
			'organization' => $org_name,
			'accept_url'   => $accept_url,
		];

		$subject = $this->email->subject(
			'p2p_team_invitation',
			[
				'{team_name}'    => $team->name,
				'{organization}' => $org_name,
			]
		);

		$html = $this->email->render_template( 'p2p-team-invitation', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $invitation->email, $subject, $html );

		$invitation->sent_at = current_time( 'mysql', true );
		$invitation->save();
	}

	/**
	 * Email the captain when a new member joins their team.
	 *
	 * @param Fundraiser $fundraiser The fundraiser that joined.
	 * @param Team       $team       The team they joined.
	 * @return void
	 */
	public function on_member_joined( Fundraiser $fundraiser, Team $team ): void {
		// Don't notify the captain about their own founding join.
		if ( (int) $fundraiser->id === (int) $team->captain_id ) {
			return;
		}

		if ( ! $this->email->is_email_enabled( 'p2p_team_member_joined' ) ) {
			return;
		}

		$captain = $team->captain()?->donor();
		if ( ! $captain?->email ) {
			return;
		}

		$org_name    = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );
		$member_name = trim( (string) ( $fundraiser->donor()?->full_name() ?? '' ) ) ?: __( 'A new member', 'mission-donation-platform' );

		$data = [
			'team'         => $team,
			'donor'        => $captain,
			'member_name'  => $member_name,
			'organization' => $org_name,
			'page_url'     => $team->get_url(),
		];

		$subject = $this->email->subject(
			'p2p_team_member_joined',
			[
				'{captain_name}' => $captain->first_name ?: __( 'Captain', 'mission-donation-platform' ),
				'{member_name}'  => $member_name,
				'{team_name}'    => $team->name,
				'{organization}' => $org_name,
			]
		);

		$html = $this->email->render_template( 'p2p-team-member-joined', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $captain->email, $subject, $html );
	}

	/**
	 * Email the captain when their team is approved.
	 *
	 * @param Team $team The approved team.
	 * @return void
	 */
	public function on_team_approved( Team $team ): void {
		if ( ! $this->email->is_email_enabled( 'p2p_team_approved' ) ) {
			return;
		}

		$captain = $team->captain()?->donor();
		if ( ! $captain?->email ) {
			return;
		}

		$org_name = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );

		$data = [
			'team'         => $team,
			'donor'        => $captain,
			'organization' => $org_name,
			'page_url'     => $team->get_url(),
		];

		$subject = $this->email->subject(
			'p2p_team_approved',
			[
				'{captain_name}' => $captain->first_name ?: __( 'Captain', 'mission-donation-platform' ),
				'{team_name}'    => $team->name,
				'{organization}' => $org_name,
			]
		);

		$html = $this->email->render_template( 'p2p-team-approved', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $captain->email, $subject, $html );
	}

	/**
	 * Send the invitations that were held while the team awaited approval.
	 *
	 * Runs on mission_team_approved independently of the captain email, so
	 * disabling the team-approved email doesn't strand held invitations.
	 *
	 * @param Team $team The just-approved team.
	 * @return void
	 */
	public function flush_pending_invitations( Team $team ): void {
		foreach ( $team->invitations(
			[
				'status'   => TeamInvitation::STATUS_PENDING,
				'per_page' => -1,
			]
		) as $invitation ) {
			if ( null === $invitation->sent_at ) {
				$this->on_invitation_created( $invitation );
			}
		}
	}
}
