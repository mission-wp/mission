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
	 * (a prior donor, or brand new) → send a signup code (c+d). The two are
	 * deliberately indistinguishable so donor status never leaks.
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
			// Only mail a reset code to a real account; silent otherwise.
			$donor = Donor::find_by_email( $email );
			if ( ! $donor || ! $donor->user_id ) {
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
	 * @param string $phone    Phone (optional).
	 * @param string $password Chosen password.
	 * @return Donor The logged-in donor.
	 *
	 * @throws \MissionDP\DonorDashboard\OtpException If the code is wrong/expired.
	 * @throws \RuntimeException If account creation fails.
	 */
	public function complete_signup( string $email, string $code, string $first, string $last, string $phone, string $password ): Donor {
		$this->otp->verify( $email, self::PURPOSE_SIGNUP, $code );

		$donor = Donor::find_by_email( $email );

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
					'phone'      => $phone,
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
	 *                           goal in minor units, story, dedicate, tribute_type, honoree_name).
	 * @return array{fundraiser: array, team: ?array} Result payload.
	 */
	public function register_fundraiser( Donor $donor, Campaign $campaign, array $input ): array {
		$settings = $campaign->p2p_settings();

		$existing = Fundraiser::query(
			[
				'campaign_id' => $campaign->id,
				'donor_id'    => $donor->id,
				'per_page'    => 1,
			]
		);

		if ( $existing ) {
			return $this->result( $existing[0], $existing[0]->team() );
		}

		$status = empty( $settings['approval_required'] ) ? Fundraiser::STATUS_ACTIVE : Fundraiser::STATUS_PENDING;

		$goal = (int) ( $input['goal'] ?? 0 );
		if ( $goal <= 0 ) {
			$goal = (int) $settings['default_fundraiser_goal'];
		}

		$fundraiser = Fundraiser::register( $campaign->id, $donor->id, $goal, (string) ( $input['story'] ?? '' ), '', $status );

		if ( ! empty( $input['dedicate'] ) && ! empty( $input['honoree_name'] ) ) {
			$fundraiser->update_meta( 'tribute_type', 'memory' === ( $input['tribute_type'] ?? '' ) ? 'memory' : 'honor' );
			$fundraiser->update_meta( 'tribute_name', $input['honoree_name'] );
		}

		$team = $this->resolve_team( $campaign, $fundraiser, $input, $settings );

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
	 * Attach the fundraiser to a team per the chosen mode (or fundraise solo).
	 *
	 * @param Campaign   $campaign   Parent campaign.
	 * @param Fundraiser $fundraiser The just-created fundraiser.
	 * @param array      $input      Setup fields.
	 * @param array      $settings   Campaign P2P settings.
	 * @return Team|null The team joined/created, or null for a solo fundraiser.
	 */
	private function resolve_team( Campaign $campaign, Fundraiser $fundraiser, array $input, array $settings ): ?Team {
		$mode = $input['team_mode'] ?? '';

		if ( 'create' === $mode ) {
			if ( empty( $settings['teams_enabled'] ) || empty( $settings['team_creation_enabled'] ) ) {
				return null;
			}

			$name = trim( (string) ( $input['team_name'] ?? '' ) );
			if ( '' === $name ) {
				return null;
			}

			$team_status = empty( $settings['team_approval_required'] ) ? Team::STATUS_ACTIVE : Team::STATUS_PENDING;
			$team        = Team::register( $campaign->id, $name, (int) $settings['default_team_goal'], Team::ACCESS_PUBLIC, $team_status );

			// Circular FK: fundraiser joins as captain, then the team records it.
			$fundraiser->join_team( $team, true );
			$team->set_captain( $fundraiser );

			return $team;
		}

		$team_id = (int) ( $input['team_id'] ?? 0 );
		if ( $team_id <= 0 || empty( $settings['teams_enabled'] ) ) {
			return null;
		}

		$team = Team::find( $team_id );

		// Public, active teams in this campaign only (private/invite is Phase 6).
		if ( ! $team || $team->campaign_id !== $campaign->id || Team::STATUS_ACTIVE !== $team->status || Team::ACCESS_PUBLIC !== $team->access ) {
			return null;
		}

		$fundraiser->join_team( $team, false );

		return $team;
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
