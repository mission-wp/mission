<?php
/**
 * Fundraiser registration service.
 *
 * Orchestrates the peer-to-peer signup flow: deciding which account branch an
 * email falls into, verifying email ownership with one-time codes, creating or
 * linking the donor account, and finally creating the fundraiser (and team).
 * Keeps this multi-model choreography out of the REST handler.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\DonorDashboard\DonorAuthService;
use MissionDP\DonorDashboard\OtpService;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates account resolution and fundraiser/team creation for signup.
 */
class FundraiserRegistrationService {

	public const BRANCH_AUTHENTICATED     = 'authenticated';
	public const BRANCH_PASSWORD_MISMATCH = 'password_mismatch';
	public const BRANCH_VERIFY_REQUIRED   = 'verify_required';

	private const PURPOSE_SIGNUP = 'signup';
	private const PURPOSE_RESET  = 'reset';

	/**
	 * Constructor.
	 *
	 * @param DonorAuthService $auth Donor auth/session service.
	 * @param OtpService       $otp  One-time-code service.
	 */
	public function __construct(
		private DonorAuthService $auth,
		private OtpService $otp,
	) {}

	/**
	 * Decide the account branch for an email + password on "Continue".
	 *
	 * Existing account → log in (a) or report a mismatch (b). Any other email
	 * (a prior donor, or brand new) → send a signup code (c+d). Branches c and
	 * d are deliberately indistinguishable so donor status never leaks. Branch
	 * b does reveal that a full account exists for the email — a deliberate
	 * trade-off of the combined login/signup screen (the user has to be told a
	 * password is required), consistent with core's own login behavior.
	 *
	 * @param string $email    Email address.
	 * @param string $password Password the user entered.
	 * @return string One of the BRANCH_* constants.
	 *
	 * @throws \RuntimeException If the password is too weak for a new account.
	 * @throws \MissionDP\DonorDashboard\OtpException If a code can't be sent.
	 */
	public function resolve_account( string $email, string $password ): string {
		$donor = Donor::find_by_email( $email );

		if ( $donor && $donor->user_id ) {
			try {
				$this->auth->login( $email, $password );
				return self::BRANCH_AUTHENTICATED;
			} catch ( \RuntimeException $e ) {
				return self::BRANCH_PASSWORD_MISMATCH;
			}
		}

		// New account: reject a weak password now, before mailing a code.
		$this->auth->validate_password( $password );
		$this->otp->send( $email, self::PURPOSE_SIGNUP );

		return self::BRANCH_VERIFY_REQUIRED;
	}

	/**
	 * Send (or resend) a code for a given purpose.
	 *
	 * @param string $email   Email address.
	 * @param string $purpose 'signup' or 'reset'.
	 * @return void
	 *
	 * @throws \MissionDP\DonorDashboard\OtpException If within the cooldown or over the cap.
	 */
	public function send_code( string $email, string $purpose ): void {
		if ( self::PURPOSE_RESET === $purpose ) {
			// Silently skip non-donor accounts so responses never reveal whether an
			// account exists; privileged users who donated must use core's reset flow.
			$donor = Donor::find_by_email( $email );
			if ( ! $donor || ! $donor->user_id || ! $this->auth->is_donor_user( $donor->user_id ) ) {
				return;
			}

			$this->otp->send( $email, self::PURPOSE_RESET );
			return;
		}

		$this->otp->send( $email, self::PURPOSE_SIGNUP );
	}

	/**
	 * Verify a signup code, then create-or-link the account and log in.
	 *
	 * @param string $email    Email address.
	 * @param string $code     Submitted code.
	 * @param string $first    First name (new donors only).
	 * @param string $last     Last name (new donors only).
	 * @param string $password Chosen password.
	 * @return Donor The logged-in donor.
	 *
	 * @throws \MissionDP\DonorDashboard\OtpException If the code is wrong/expired.
	 * @throws \RuntimeException If account creation fails.
	 */
	public function complete_signup( string $email, string $code, string $first, string $last, string $password ): Donor {
		$donor = Donor::find_by_email( $email );

		// Reject a bad password BEFORE burning the one-time code, so a failed
		// attempt doesn't consume the code.
		if ( ! $donor || ! $donor->user_id ) {
			$this->auth->validate_password( $password );
		}

		$this->otp->verify( $email, self::PURPOSE_SIGNUP, $code );

		// Already linked (e.g. concurrent signup): just log in.
		if ( $donor && $donor->user_id ) {
			return $this->auth->login_user( $donor->user_id );
		}

		if ( ! $donor ) {
			$donor = new Donor(
				[
					'email'      => $email,
					'first_name' => $first,
					'last_name'  => $last,
				]
			);
			$donor->save();
		}

		$this->auth->create_account( $donor, $password );
		$donor->update_meta( 'email_confirmed', '1' );

		return $this->auth->login_user( $donor->user_id );
	}

	/**
	 * Verify a reset code and mint a single-use grant for setting a password.
	 *
	 * @param string $email Email address.
	 * @param string $code  Submitted code.
	 * @return string The reset grant token.
	 *
	 * @throws \MissionDP\DonorDashboard\OtpException If the code is wrong/expired.
	 */
	public function verify_reset_code( string $email, string $code ): string {
		$this->otp->verify( $email, self::PURPOSE_RESET, $code );

		return $this->otp->grant_reset( $email );
	}

	/**
	 * Set a new password using a verified reset grant, then log in.
	 *
	 * @param string $email    Email address.
	 * @param string $grant    Reset grant token from verify_reset_code().
	 * @param string $password New password.
	 * @return Donor The logged-in donor.
	 *
	 * @throws \RuntimeException If the grant is invalid/expired or the account is missing.
	 */
	public function set_password( string $email, string $grant, string $password ): Donor {
		if ( ! $this->otp->consume_reset_grant( $email, $grant ) ) {
			throw new \RuntimeException( esc_html__( 'This reset session is invalid or has expired. Please start over.', 'mission-donation-platform' ) );
		}

		$donor = Donor::find_by_email( $email );

		if ( ! $donor || ! $donor->user_id ) {
			throw new \RuntimeException( esc_html__( 'This reset session is invalid or has expired. Please start over.', 'mission-donation-platform' ) );
		}

		$this->auth->set_password( $donor->user_id, $password );

		return $this->auth->login_user( $donor->user_id );
	}

	/**
	 * Create the fundraiser (and optionally a team) for a verified donor.
	 *
	 * Idempotent per (campaign, donor): a second submission returns the existing
	 * fundraiser instead of creating a duplicate.
	 *
	 * @param Donor    $donor    The participant.
	 * @param Campaign $campaign The parent P2P campaign.
	 * @param array    $input    Sanitized setup fields (team_mode, team_id, team_name,
	 *                           team_access, goal in minor units, story, dedicate, tribute_type,
	 *                           honoree_name, invite_token).
	 * @return array{fundraiser: array, team: ?array}|WP_Error Result payload, or an error when the row can't be created.
	 */
	public function register_fundraiser( Donor $donor, Campaign $campaign, array $input ): array|WP_Error {
		$settings = $campaign->p2p_settings();

		$existing = Fundraiser::find_by_campaign_donor( (int) $campaign->id, (int) $donor->id );

		if ( $existing ) {
			$fundraiser = $existing;

			// Only a token mapping to a real pending invite on this campaign re-runs
			// team resolution; a junk token must not create or join a team via team_mode.
			$token = (string) ( $input['invite_token'] ?? '' );

			if ( ! $fundraiser->team_id && '' !== $token ) {
				$invitation   = TeamInvitation::find_by_token( $token );
				$invited_team = $invitation && $invitation->is_pending() ? Team::find( (int) $invitation->team_id ) : null;

				if ( ! $invited_team || $invited_team->campaign_id !== $campaign->id ) {
					return $this->invitation_error();
				}

				$join_input = array_merge(
					$input,
					[
						'team_mode' => 'join',
						'team_id'   => $invited_team->id,
					]
				);

				$choice = $this->resolve_team_choice( $campaign, $donor, $join_input, $settings );
				if ( is_wp_error( $choice ) ) {
					return $choice;
				}

				$team = $this->attach_team( $campaign, $fundraiser, $join_input, $settings, $choice );

				return $this->result( $fundraiser, $team ?? $fundraiser->team() );
			}

			return $this->result( $fundraiser, $fundraiser->team() );
		}

		// Validate the team choice before creating anything, so a failed join
		// or create never silently produces a solo page the user didn't want.
		$choice = $this->resolve_team_choice( $campaign, $donor, $input, $settings );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}

		$status = empty( $settings['approval_required'] ) ? Fundraiser::STATUS_ACTIVE : Fundraiser::STATUS_PENDING;

		$goal = (int) ( $input['goal'] ?? 0 );
		if ( $goal <= 0 ) {
			$goal = (int) $settings['default_fundraiser_goal'];
		}

		$result = Fundraiser::register_or_existing( $campaign->id, $donor->id, $goal, (string) ( $input['story'] ?? '' ), '', $status );

		if ( ! $result['created'] ) {
			// The idempotent answer to a lost create race is the row the other
			// request created.
			if ( $result['fundraiser'] ) {
				return $this->result( $result['fundraiser'], $result['fundraiser']->team() );
			}

			return new WP_Error( 'registration_failed', __( 'We could not create your fundraising page. Please try again.', 'mission-donation-platform' ), [ 'status' => 500 ] );
		}

		$fundraiser = $result['fundraiser'];

		if ( ! empty( $input['dedicate'] ) && ! empty( $input['honoree_name'] ) ) {
			// An empty type would clear; null lets the model default it to 'honor'.
			$fundraiser->set_dedication( ( $input['tribute_type'] ?? '' ) ?: null, (string) $input['honoree_name'] );
		}

		$team = $this->attach_team( $campaign, $fundraiser, $input, $settings, $choice );

		return $this->result( $fundraiser, $team );
	}

	/**
	 * The resend cooldown in seconds, for the REST response.
	 *
	 * @return int
	 */
	public function resend_cooldown(): int {
		return $this->otp->resend_cooldown();
	}

	/**
	 * Validate a requested team join/create and resolve what it refers to.
	 *
	 * Run before the fundraiser row is created: when the user explicitly asked
	 * for a team, a doomed request must fail with a clear error instead of
	 * completing as a solo registration. The resolved team and invitation are
	 * carried in the returned choice so attach_team() never re-runs these rules.
	 *
	 * @param Campaign $campaign Parent campaign.
	 * @param Donor    $donor    The registering donor.
	 * @param array    $input    Setup fields.
	 * @param array    $settings Campaign P2P settings.
	 * @return array{mode: string, team: ?Team, invitation: ?TeamInvitation}|WP_Error
	 *         The validated choice, or an error when it can't succeed.
	 */
	private function resolve_team_choice( Campaign $campaign, Donor $donor, array $input, array $settings ): array|WP_Error {
		$mode = $input['team_mode'] ?? '';

		if ( 'create' === $mode ) {
			if ( empty( $settings['teams_enabled'] ) || empty( $settings['team_creation_enabled'] ) ) {
				return new WP_Error( 'team_creation_disabled', __( 'Team creation is not available for this campaign.', 'mission-donation-platform' ), [ 'status' => 400 ] );
			}

			if ( '' === trim( (string) ( $input['team_name'] ?? '' ) ) ) {
				return new WP_Error( 'team_name_required', __( 'Please enter a team name.', 'mission-donation-platform' ), [ 'status' => 400 ] );
			}

			return [
				'mode'       => 'create',
				'team'       => null,
				'invitation' => null,
			];
		}

		$team_id = (int) ( $input['team_id'] ?? 0 );

		if ( $team_id <= 0 ) {
			return [
				'mode'       => 'solo',
				'team'       => null,
				'invitation' => null,
			];
		}

		if ( empty( $settings['teams_enabled'] ) ) {
			return new WP_Error( 'teams_disabled', __( 'Teams are not available for this campaign.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$team = Team::find( $team_id );

		if ( ! $team || $team->campaign_id !== $campaign->id || Team::STATUS_ACTIVE !== $team->status ) {
			return new WP_Error( 'team_unavailable', __( 'This team is no longer accepting new members.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$invitation = null;

		if ( Team::ACCESS_PUBLIC !== $team->access ) {
			$invitation = $this->locate_invitation( $team, $donor, (string) ( $input['invite_token'] ?? '' ) );

			if ( $invitation instanceof WP_Error ) {
				return $invitation;
			}
		}

		return [
			'mode'       => 'join',
			'team'       => $team,
			'invitation' => $invitation,
		];
	}

	/**
	 * Commit a validated team choice for the just-created fundraiser.
	 *
	 * All rules ran in resolve_team_choice(); this only performs the side
	 * effects: create the team, accept the invitation, join.
	 *
	 * @param Campaign   $campaign   Parent campaign.
	 * @param Fundraiser $fundraiser The just-created fundraiser.
	 * @param array      $input      Setup fields.
	 * @param array      $settings   Campaign P2P settings.
	 * @param array      $choice     Validated choice from resolve_team_choice().
	 * @return Team|null The team joined/created, or null for a solo fundraiser.
	 */
	private function attach_team( Campaign $campaign, Fundraiser $fundraiser, array $input, array $settings, array $choice ): ?Team {
		if ( 'create' === $choice['mode'] ) {
			$team_status = empty( $settings['team_approval_required'] ) ? Team::STATUS_ACTIVE : Team::STATUS_PENDING;
			$team_access = in_array( $input['team_access'] ?? '', Team::ACCESS_LEVELS, true )
				? $input['team_access']
				: Team::ACCESS_PUBLIC;

			// A failed create or captain join returns null; the registration
			// completes solo instead of attaching a phantom team.
			return Team::register_with_captain(
				[
					'campaign_id' => $campaign->id,
					'name'        => trim( (string) ( $input['team_name'] ?? '' ) ),
					'goal'        => (int) $settings['default_team_goal'],
					'access'      => $team_access,
					'status'      => $team_status,
				],
				$fundraiser
			);
		}

		if ( 'join' !== $choice['mode'] ) {
			return null;
		}

		if ( $choice['invitation'] ) {
			$choice['invitation']->status = TeamInvitation::STATUS_ACCEPTED;
			$choice['invitation']->save();
		}

		$fundraiser->join_team( $choice['team'], false );

		return $choice['team'];
	}

	/**
	 * Resolve an invitation token to a usable pending invitation.
	 *
	 * The token is the only trusted input: the matching row supplies the team and
	 * email, never the client. A pending invite past its TTL is retired as expired.
	 *
	 * @param Team       $team  The team being joined.
	 * @param Donor|null $donor The accepting donor.
	 * @param string     $token The bearer token from the invite link.
	 * @return TeamInvitation|WP_Error The invitation, or why it can't be used.
	 */
	private function locate_invitation( Team $team, ?Donor $donor, string $token ): TeamInvitation|WP_Error {
		if ( ! $donor || '' === $token ) {
			return $this->invitation_error();
		}

		$invitation = TeamInvitation::find_by_token( $token );

		if ( ! $invitation || ! $invitation->is_pending() || (int) $invitation->team_id !== (int) $team->id ) {
			return $this->invitation_error();
		}

		if ( $invitation->is_expired() ) {
			$invitation->status = TeamInvitation::STATUS_EXPIRED;
			$invitation->save();
			return $this->invitation_error();
		}

		if ( strtolower( $invitation->email ) !== strtolower( (string) $donor->email ) ) {
			return new WP_Error(
				'invitation_email_mismatch',
				__( 'This invitation was sent to a different email address. Please sign up with the address that received it.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		return $invitation;
	}

	/**
	 * The generic unusable-invitation error.
	 *
	 * @return WP_Error
	 */
	private function invitation_error(): WP_Error {
		return new WP_Error(
			'invitation_invalid',
			__( 'This invitation is invalid or has expired. Please ask your team captain to send a new one.', 'mission-donation-platform' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Build the result payload for a fundraiser/team pair.
	 *
	 * @param Fundraiser $fundraiser The fundraiser.
	 * @param Team|null  $team       The team, if any.
	 * @return array{fundraiser: array, team: ?array}
	 */
	private function result( Fundraiser $fundraiser, ?Team $team ): array {
		return [
			'fundraiser' => [
				'id'     => $fundraiser->id,
				'url'    => $fundraiser->get_url(),
				'status' => $fundraiser->status,
			],
			'team'       => $team
				? [
					'id'   => $team->id,
					'name' => $team->name,
					'url'  => $team->get_url(),
				]
				: null,
		];
	}
}
