<?php
/**
 * Listens for peer-to-peer team events and emails captains and invitees.
 *
 * Sends an invitation email when a captain invites someone to a private team, a
 * "new member joined" email to the captain, and a "team approved" email to the
 * captain when a pending team goes live.
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
	}

	/**
	 * Email an invitee their invitation link and stamp the invitation as sent.
	 *
	 * @param TeamInvitation $invitation The pending invitation.
	 * @return void
	 */
	public function on_invitation_created( TeamInvitation $invitation ): void {
		if ( $this->is_test_mode() ) {
			return;
		}

		if ( ! $invitation->email || ! $this->email->is_email_enabled( 'p2p_team_invitation' ) ) {
			return;
		}

		$team = $invitation->team();
		if ( ! $team ) {
			return;
		}

		$org_name   = ( new SettingsService() )->get( 'org_name', get_bloginfo( 'name' ) );
		$accept_url = add_query_arg( 'team_invite', rawurlencode( $invitation->token ), (string) $team->get_url() );

		$data = [
			'team'         => $team,
			'organization' => $org_name,
			'accept_url'   => $accept_url,
		];

		$subject = sprintf(
			/* translators: %s: team name */
			__( "You're invited to join %s", 'mission-donation-platform' ),
			$team->name,
		);

		$custom_subject = $this->email->get_custom_subject( 'p2p_team_invitation' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{team_name}'    => $team->name,
					'{organization}' => $org_name,
				]
			);
		}

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
		if ( $this->is_test_mode() ) {
			return;
		}

		// The captain creating the team is not a "new member" to notify about.
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

		$subject = sprintf(
			/* translators: %s: team name */
			__( 'A new member joined %s', 'mission-donation-platform' ),
			$team->name,
		);

		$custom_subject = $this->email->get_custom_subject( 'p2p_team_member_joined' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{captain_name}' => $captain->first_name ?: __( 'Captain', 'mission-donation-platform' ),
					'{member_name}'  => $member_name,
					'{team_name}'    => $team->name,
					'{organization}' => $org_name,
				]
			);
		}

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
		if ( $this->is_test_mode() ) {
			return;
		}

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

		$subject = sprintf(
			/* translators: %s: team name */
			__( 'Your team %s has been approved', 'mission-donation-platform' ),
			$team->name,
		);

		$custom_subject = $this->email->get_custom_subject( 'p2p_team_approved' );
		if ( $custom_subject ) {
			$subject = $this->email->replace_subject_tags(
				$custom_subject,
				[
					'{captain_name}' => $captain->first_name ?: __( 'Captain', 'mission-donation-platform' ),
					'{team_name}'    => $team->name,
					'{organization}' => $org_name,
				]
			);
		}

		$html = $this->email->render_template( 'p2p-team-approved', array_merge( $data, [ 'subject' => $subject ] ) );
		$this->email->send( $captain->email, $subject, $html );
	}

	/**
	 * Whether the plugin is in test mode (no team management emails are sent).
	 *
	 * @return bool
	 */
	private function is_test_mode(): bool {
		return (bool) ( new SettingsService() )->get( 'test_mode' );
	}
}
