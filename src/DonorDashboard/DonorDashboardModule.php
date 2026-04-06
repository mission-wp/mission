<?php
/**
 * Donor Dashboard module.
 *
 * Registers the donor role, redirects donors away from wp-admin,
 * and handles dashboard-specific hooks.
 *
 * @package Mission
 */

namespace Mission\DonorDashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Donor Dashboard module class.
 */
class DonorDashboardModule {

	/**
	 * Initialize the module.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'ensure_donor_role' ] );
		add_action( 'init', [ $this, 'register_block_template' ] );
		add_action( 'admin_init', [ $this, 'redirect_donor_from_admin' ] );
		add_filter( 'login_redirect', [ $this, 'redirect_donor_after_login' ], 10, 3 );
		add_action( 'delete_user', [ $this, 'unlink_donor_on_user_delete' ] );
		add_action( 'mission_donor_profile_updated', [ $this, 'sync_donor_email_to_wp_user' ] );
		add_action( 'profile_update', [ $this, 'sync_wp_user_email_to_donor' ], 10, 2 );
		add_filter( 'retrieve_password_message', [ $this, 'filter_password_reset_url' ], 10, 4 );
		add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_for_donors' ] );
		add_filter( 'display_post_states', [ $this, 'add_dashboard_post_state' ], 10, 2 );
		add_action( 'mission_settings_updated', [ $this, 'handle_portal_toggle' ], 10, 3 );
	}

	/**
	 * Ensure the mission_donor role exists.
	 *
	 * The role is created during plugin activation, but this serves as a
	 * safety net in case the role was removed or the plugin was updated
	 * without reactivation.
	 *
	 * @return void
	 */
	public function ensure_donor_role(): void {
		if ( ! get_role( 'mission_donor' ) ) {
			add_role( 'mission_donor', __( 'Mission Donor', 'mission' ), [] );
		}
	}

	/**
	 * Register the Donor Dashboard page template for the Site Editor.
	 *
	 * Same layout as a standard page but without the post title, since
	 * the dashboard block handles its own heading.
	 *
	 * @return void
	 */
	public function register_block_template(): void {
		\register_block_template(
			'mission//page-donor-dashboard',
			[
				'title'       => __( 'Donor Dashboard', 'mission' ),
				'description' => __( 'A page template without the page title, designed for the Donor Dashboard block.', 'mission' ),
				'post_types'  => [ 'page' ],
				'content'     => '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">

<!-- wp:post-content {"align":"wide","layout":{"type":"constrained"}} /-->

</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->',
			]
		);
	}

	/**
	 * Redirect donors away from wp-admin.
	 *
	 * @return void
	 */
	public function redirect_donor_from_admin(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user->ID || ! $this->is_donor_user( $user ) ) {
			return;
		}

		wp_safe_redirect( $this->get_dashboard_url() );
		exit;
	}

	/**
	 * Filter the login redirect so donors go to the dashboard instead of wp-admin.
	 *
	 * @param string   $redirect_to           The redirect destination URL.
	 * @param string   $requested_redirect_to The requested redirect URL.
	 * @param \WP_User $user                  Logged-in user.
	 * @return string
	 */
	public function redirect_donor_after_login( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( $user instanceof \WP_User && $this->is_donor_user( $user ) ) {
			return $this->get_dashboard_url();
		}

		return $redirect_to;
	}

	/**
	 * Clear the donor's user_id when a WordPress user is deleted.
	 *
	 * This allows the donor to re-activate their account later.
	 *
	 * @param int $user_id The ID of the user being deleted.
	 * @return void
	 */
	public function unlink_donor_on_user_delete( int $user_id ): void {
		$donor = \Mission\Models\Donor::find_by_user_id( $user_id );

		if ( ! $donor ) {
			return;
		}

		$donor->user_id = null;
		$donor->save();
	}

	/**
	 * Sync a donor's email to their linked WordPress user.
	 *
	 * Fired when the donor profile is updated via REST.
	 *
	 * @param \Mission\Models\Donor $donor Updated donor.
	 * @return void
	 */
	public function sync_donor_email_to_wp_user( \Mission\Models\Donor $donor ): void {
		if ( ! $donor->user_id ) {
			return;
		}

		$user = get_userdata( $donor->user_id );

		if ( ! $user || $user->user_email === $donor->email ) {
			return;
		}

		wp_update_user(
			[
				'ID'         => $user->ID,
				'user_email' => $donor->email,
			]
		);
	}

	/**
	 * Sync a WordPress user's email change to the linked donor record.
	 *
	 * Fired on the `profile_update` action for users with the mission_donor role.
	 *
	 * @param int      $user_id       User ID.
	 * @param \WP_User $old_user_data User data before the update.
	 * @return void
	 */
	public function sync_wp_user_email_to_donor( int $user_id, \WP_User $old_user_data ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || ! $this->is_donor_user( $user ) ) {
			return;
		}

		// Only act if the email actually changed.
		if ( $user->user_email === $old_user_data->user_email ) {
			return;
		}

		$donor = \Mission\Models\Donor::find_by_user_id( $user_id );

		if ( ! $donor || $donor->email === $user->user_email ) {
			return;
		}

		$donor->email = $user->user_email;
		$donor->save();
	}

	/**
	 * Rewrite the password reset URL for donor users.
	 *
	 * If a donor triggers WordPress's native password reset flow
	 * (e.g. via wp-login.php), this replaces the wp-login.php URL
	 * with the donor dashboard URL so they stay on the frontend.
	 *
	 * @param string   $message    The email message.
	 * @param string   $key        The activation key.
	 * @param string   $user_login The username for the user.
	 * @param \WP_User $user_data  WP_User object.
	 * @return string Filtered message.
	 */
	public function filter_password_reset_url( string $message, string $key, string $user_login, \WP_User $user_data ): string {
		if ( ! $this->is_donor_user( $user_data ) ) {
			return $message;
		}

		$wp_reset_url = network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user_login ), 'login' );

		$dashboard_reset_url = add_query_arg(
			[
				'action' => 'reset-password',
				'key'    => $key,
				'login'  => rawurlencode( $user_login ),
			],
			$this->get_dashboard_url()
		);

		return str_replace( $wp_reset_url, $dashboard_reset_url, $message );
	}

	/**
	 * Hide the admin bar for donors on the frontend.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function hide_admin_bar_for_donors( bool $show ): bool {
		$user = wp_get_current_user();

		if ( $user->exists() && $this->is_donor_user( $user ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Add a post state label to the Donor Dashboard page in the Pages list.
	 *
	 * @param string[] $post_states Array of post state labels.
	 * @param \WP_Post $post       The current post.
	 * @return string[]
	 */
	public function add_dashboard_post_state( array $post_states, \WP_Post $post ): array {
		$dashboard_page_id = (int) get_option( 'mission_dashboard_page_id', 0 );

		if ( $dashboard_page_id && $post->ID === $dashboard_page_id ) {
			$post_states['mission_donor_dashboard'] = __( 'Donor Dashboard Page', 'mission' );
		}

		return $post_states;
	}

	/**
	 * Create or delete the dashboard page when the portal toggle changes.
	 *
	 * Listens to the `mission_settings_updated` action fired by SettingsService.
	 *
	 * @param array<string, mixed> $updated  Full settings after update.
	 * @param array<string, mixed> $values   Only the changed values.
	 * @param array<string, mixed> $current  Settings before update.
	 * @return void
	 */
	public function handle_portal_toggle( array $updated, array $values, array $current ): void {
		if ( ! array_key_exists( 'donor_portal_enabled', $values ) ) {
			return;
		}

		$was_enabled = $current['donor_portal_enabled'] ?? true;
		$now_enabled = $updated['donor_portal_enabled'] ?? true;

		if ( $was_enabled && ! $now_enabled ) {
			$this->delete_dashboard_page();
		} elseif ( ! $was_enabled && $now_enabled ) {
			self::create_dashboard_page();
		}
	}

	/**
	 * Create the donor dashboard page.
	 *
	 * Called during plugin activation and when the portal is toggled on.
	 *
	 * @return void
	 */
	public static function create_dashboard_page(): void {
		$page_id = (int) get_option( 'mission_dashboard_page_id', 0 );

		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return;
		}

		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Donor Dashboard', 'mission' ),
				'post_name'    => 'donor-dashboard',
				'post_content' => '<!-- wp:mission/donor-dashboard {"align":"wide"} /-->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'meta_input'   => [
					'_wp_page_template' => 'mission//page-donor-dashboard',
				],
			]
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'mission_dashboard_page_id', $page_id );
		}
	}

	/**
	 * Delete the donor dashboard page and clear the stored option.
	 *
	 * @return void
	 */
	private function delete_dashboard_page(): void {
		$page_id = (int) get_option( 'mission_dashboard_page_id', 0 );

		if ( $page_id ) {
			wp_delete_post( $page_id, true );
		}

		delete_option( 'mission_dashboard_page_id' );
	}

	/**
	 * Check if a user has only the mission_donor role.
	 *
	 * @param \WP_User $user WordPress user.
	 * @return bool
	 */
	private function is_donor_user( \WP_User $user ): bool {
		return in_array( 'mission_donor', $user->roles, true )
			&& count( $user->roles ) === 1;
	}

	/**
	 * Get the donor dashboard page URL.
	 *
	 * Falls back to the site home if no dashboard page is configured.
	 *
	 * @return string
	 */
	private function get_dashboard_url(): string {
		$page_id = (int) get_option( 'mission_dashboard_page_id', 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}
}
