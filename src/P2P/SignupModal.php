<?php
/**
 * The fundraiser sign-up modal shell.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Currency\Currency;
use MissionDP\Helpers\Kses;
use MissionDP\Helpers\Sharing;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the singleton sign-up dialog shell and builds the campaign payloads
 * that bind it.
 *
 * The modal is not a block: any block that shows a sign-up CTA appends
 * `SignupModal::render()` to its output (the shell renders once per page) and
 * embeds `SignupModal::payload()` in its own Interactivity context, which
 * `openSignup()` passes to the modal store's `open( payload )`. That way a CTA
 * works on any page, and two CTAs for different campaigns on one page each
 * open the modal bound to their own campaign.
 */
class SignupModal {

	private const MODULE_ID    = '@mission-donation-platform/signup-modal';
	private const STYLE_HANDLE = 'mission-signup-modal';

	/**
	 * Whether the shell was already rendered this request.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Per-request payload cache, keyed "campaignId:teamId".
	 *
	 * @var array<string, array>
	 */
	private static array $payload_cache = [];

	/**
	 * Render the sign-up modal shell, once per request.
	 *
	 * The first call returns the dialog markup (seeded with the given campaign
	 * as the default payload, used by non-click entry points like invite links)
	 * and enqueues the view module and stylesheet. Subsequent calls return an
	 * empty string, so every CTA block can call this unconditionally.
	 *
	 * @param Campaign  $campaign         Campaign the shell defaults to.
	 * @param Team|null $preselected_team Team to preselect (team pages).
	 * @return string Dialog markup, or '' when already rendered or not applicable.
	 */
	public static function render( Campaign $campaign, ?Team $preselected_team = null ): string {
		if ( self::$rendered || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}

		if ( ! $campaign->is_registration_open() ) {
			return '';
		}

		self::$rendered = true;

		$payload = self::payload( $campaign, $preselected_team );

		// Resolve a signed-in donor inline (cheap; avoids booting the auth service).
		$current_user  = wp_get_current_user();
		$current_donor = ( $current_user->ID && in_array( 'missiondp_donor', (array) $current_user->roles, true ) )
			? Donor::find_by_user_id( $current_user->ID )
			: null;

		// The default payload seeds the store's global state so the shell's SSR
		// markup (teams list included) hydrates as-is; open( payload ) rebinds it.
		wp_interactivity_state(
			'mission-donation-platform/p2p-signup',
			$payload + [
				'signedIn'   => (bool) $current_donor,
				'donorName'  => $current_donor ? trim( $current_donor->first_name . ' ' . $current_donor->last_name ) : '',
				'donorEmail' => $current_donor ? $current_donor->email : '',
				'goal'       => $payload['defaultGoal'],
			]
		);

		$share_networks = Sharing::networks( 'signup-modal' );

		// Campaign-independent, request-scoped data lives on the shell's own context.
		$context = [
			'restUrl'        => trailingslashit( get_rest_url( null, 'mission-donation-platform/v1' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'shareTemplates' => array_intersect_key( Sharing::INTENT_TEMPLATES, array_flip( $share_networks ) ),
			// Translated strings for view.js (script modules can't import @wordpress/i18n).
			'i18n'           => [
				'genericError'     => __( 'Something went wrong. Please try again.', 'mission-donation-platform' ),
				'checkFields'      => __( 'Please check the highlighted fields.', 'mission-donation-platform' ),
				'copy'             => __( 'Copy', 'mission-donation-platform' ),
				'copied'           => __( 'Copied', 'mission-donation-platform' ),
				'copyFailed'       => __( 'Copy failed', 'mission-donation-platform' ),
				'enterCode'        => __( 'Enter the 6-digit code.', 'mission-donation-platform' ),
				'enterNewPassword' => __( 'Enter a new password.', 'mission-donation-platform' ),
			],
		];

		self::enqueue_assets();

		ob_start();
		include __DIR__ . '/templates/signup-modal.php';
		$output = ob_get_clean();

		/**
		 * Filters the sign-up modal shell output.
		 *
		 * @param string    $output           Dialog markup.
		 * @param Campaign  $campaign         Campaign the shell defaults to.
		 * @param Team|null $preselected_team Preselected team, when rendered on a team page.
		 */
		return wp_kses( apply_filters( 'mission_signup_modal_output', $output, $campaign, $preselected_team ), Kses::block_allowed_html() );
	}

	/**
	 * Build the sign-up payload that binds the modal to a campaign.
	 *
	 * Used both as the shell's default state and inside each CTA block's own
	 * Interactivity context (under the `signup` key). Memoized per request, so
	 * several same-campaign blocks on one page share one teams query.
	 *
	 * @param Campaign  $campaign         Campaign to bind.
	 * @param Team|null $preselected_team Team to preselect (team pages).
	 * @return array Payload for the modal store's `open( payload )`.
	 */
	public static function payload( Campaign $campaign, ?Team $preselected_team = null ): array {
		$cache_key = $campaign->id . ':' . ( $preselected_team->id ?? 0 );
		if ( isset( self::$payload_cache[ $cache_key ] ) ) {
			return self::$payload_cache[ $cache_key ];
		}

		$settings = $campaign->p2p_settings();

		$teams_enabled    = ! empty( $settings['teams_enabled'] );
		$creation_enabled = $teams_enabled && ! empty( $settings['team_creation_enabled'] );
		$currency         = $campaign->currency ?: 'USD';

		// Only public teams are browseable; private teams are joined via invitation.
		$teams = $teams_enabled ? Team::query(
			[
				'campaign_id' => $campaign->id,
				'status'      => Team::STATUS_ACTIVE,
				'access'      => Team::ACCESS_PUBLIC,
			]
		) : [];

		$brandline = $preselected_team
			/* translators: %s: team name */
			? sprintf( __( 'Join %s', 'mission-donation-platform' ), $preselected_team->name )
			: $campaign->title;

		$payload = [
			'campaignId'          => (int) $campaign->id,
			'brandline'           => $brandline,
			'currencySymbol'      => Currency::get_symbol( $currency ),
			'defaultGoal'         => Currency::minor_to_major( (int) ( $settings['default_fundraiser_goal'] ?? 0 ), $currency ),
			'teamCreationEnabled' => $creation_enabled,
			// Derived here (not a JS getter) so the shell's server-rendered
			// visibility is right and open( payload ) can plain-assign it.
			'showTeamChooser'     => $teams_enabled && ! $preselected_team,
			'preselectedTeamId'   => $preselected_team ? (int) $preselected_team->id : 0,
			'preselectedTeamName' => $preselected_team->name ?? '',
			'teams'               => array_map(
				static fn( Team $team ): array => [
					'id'   => (string) $team->id,
					'name' => $team->name,
				],
				$teams
			),
			'storyPlaceholder'    => (string) ( $settings['story_placeholder'] ?? '' ),

			/**
			 * Filters the heading on the sign-up success screen.
			 *
			 * @param string   $title    Default heading.
			 * @param Campaign $campaign The campaign being fundraised for.
			 */
			'successTitle'        => apply_filters( 'mission_signup_success_title', __( "You're a fundraiser!", 'mission-donation-platform' ), $campaign ),

			/**
			 * Filters the message on the sign-up success screen.
			 *
			 * @param string   $message  Default message.
			 * @param Campaign $campaign The campaign being fundraised for.
			 */
			'successMessage'      => apply_filters( 'mission_signup_success_message', __( 'Your page is live. Share it with friends and family to start raising funds.', 'mission-donation-platform' ), $campaign ),

			/**
			 * Filters the heading on the sign-up success screen when approval is pending.
			 *
			 * @param string   $title    Default heading.
			 * @param Campaign $campaign The campaign being fundraised for.
			 */
			'pendingTitle'        => apply_filters( 'mission_signup_pending_title', __( "You're almost there!", 'mission-donation-platform' ), $campaign ),

			/**
			 * Filters the message on the sign-up success screen when approval is pending.
			 *
			 * @param string   $message  Default message.
			 * @param Campaign $campaign The campaign being fundraised for.
			 */
			'pendingMessage'      => apply_filters( 'mission_signup_pending_message', __( "Your fundraising page has been submitted for review. We'll email you as soon as it's approved and ready to share.", 'mission-donation-platform' ), $campaign ),
		];

		self::$payload_cache[ $cache_key ] = $payload;

		return $payload;
	}

	/**
	 * Reset the render-once flag and payload cache. Tests only.
	 */
	public static function reset(): void {
		self::$rendered      = false;
		self::$payload_cache = [];
	}

	/**
	 * Enqueue the modal's view script module and stylesheet.
	 *
	 * The modal is not a registered block, so its `viewScriptModule` is never
	 * auto-enqueued; the build output under blocks/build/signup-modal/ is
	 * enqueued here instead.
	 */
	private static function enqueue_assets(): void {
		$build_dir  = MISSIONDP_PATH . 'blocks/build/signup-modal/';
		$build_url  = MISSIONDP_URL . 'blocks/build/signup-modal/';
		$asset_file = $build_dir . 'view.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script_module(
			self::MODULE_ID,
			$build_url . 'view.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? MISSIONDP_VERSION
		);

		wp_register_style(
			self::STYLE_HANDLE,
			$build_url . 'style-view.css',
			[],
			$asset['version'] ?? MISSIONDP_VERSION
		);
		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );
		wp_enqueue_style( self::STYLE_HANDLE );
	}
}
