<?php
/**
 * Block Name: Donor Dashboard
 * Description: A self-service portal for donors.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

// Primary color.
$mission_settings = get_option( 'missiondp_settings', [] );
$global_primary   = $mission_settings['primary_color'] ?? '#2fa36b';
$primary_color    = ! empty( $attributes['primaryColor'] ) ? $attributes['primaryColor'] : $global_primary;
$color_style      = \MissionDP\DonorDashboard\PrimaryColorResolver::inline_style( $primary_color );

// Check if the current user is a logged-in donor.
$is_donor = false;
$donor    = null;
$wp_user  = wp_get_current_user();

if ( $wp_user->ID && in_array( 'missiondp_donor', $wp_user->roles, true ) ) {
	// Look up the donor record linked to this WP user.
	$donor = \MissionDP\Models\Donor::find_by_user_id( $wp_user->ID );
	if ( $donor ) {
		$is_donor = true;
	}
}

// Check for an activation token in the URL (email verification link).
$activation_token       = isset( $_GET['activation_token'] ) ? sanitize_text_field( wp_unslash( $_GET['activation_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$activation_email       = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$activation_token_valid = false;
$activation_token_error = '';

if ( $activation_token && $activation_email && ! $is_donor ) {
	$auth_service           = new \MissionDP\DonorDashboard\DonorAuthService();
	$activation_token_valid = null !== $auth_service->validate_activation_token( $activation_email, $activation_token );

	if ( ! $activation_token_valid ) {
		$activation_token_error = __( 'This activation link is invalid or has expired. Please request a new one.', 'mission-donation-platform' );
	}
}

// Check for a password reset key in the URL.
$reset_action    = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reset_key       = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reset_login     = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reset_key_valid = false;
$reset_key_error = '';

if ( 'reset-password' === $reset_action && $reset_key && $reset_login && ! $is_donor ) {
	$auth_service    = $auth_service ?? new \MissionDP\DonorDashboard\DonorAuthService();
	$reset_key_valid = null !== $auth_service->validate_reset_key( $reset_login, $reset_key );

	if ( ! $reset_key_valid ) {
		$reset_key_error = __( 'This password reset link is invalid or has expired. Please request a new one.', 'mission-donation-platform' );
	}
}

ob_start();

if ( ! $is_donor ) :
	// ── Logged-out state: auth forms ──
	$initial_view  = 'login';
	$initial_error = '';

	if ( $activation_token_valid ) {
		$initial_view = 'set-password';
	} elseif ( $reset_key_valid ) {
		$initial_view = 'reset-password';
	} elseif ( $activation_token_error ) {
		$initial_view  = 'activate';
		$initial_error = $activation_token_error;
	} elseif ( $reset_key_error ) {
		$initial_view  = 'forgot-password';
		$initial_error = $reset_key_error;
	}

	$auth_context = [
		'authView'         => $initial_view,
		'authEmail'        => $activation_email,
		'authPassword'     => '',
		'authRemember'     => false,
		'authError'        => $initial_error,
		'authLoading'      => false,
		'passwordVisible'  => false,
		'passwordStrength' => '',
		'strengthLabel'    => '',
		'activationToken'  => $activation_token_valid ? $activation_token : '',
		'activationEmail'  => $activation_token_valid ? $activation_email : '',
		'resetKey'         => $reset_key_valid ? $reset_key : '',
		'resetLogin'       => $reset_key_valid ? $reset_login : '',
		'restUrl'          => rest_url( 'mission-donation-platform/v1/' ),
		'nonce'            => wp_create_nonce( 'wp_rest' ),
		'dashboardUrl'     => get_permalink(),
	];

	// Server-side initial values for derived state — JS getters take over on hydration.
	wp_interactivity_state(
		'mission-donation-platform/donor-dashboard',
		[
			'isLoginView'              => 'login' === $initial_view,
			'isActivateView'           => 'activate' === $initial_view,
			'isActivateSentView'       => false,
			'isSetPasswordView'        => 'set-password' === $initial_view,
			'isForgotPasswordView'     => 'forgot-password' === $initial_view,
			'isForgotPasswordSentView' => false,
			'isResetPasswordView'      => 'reset-password' === $initial_view,
			'passwordInputType'        => 'password',
			'passwordToggleLabel'      => __( 'Show password', 'mission-donation-platform' ),
		]
	);

	require __DIR__ . '/parts/auth.php';
else :
	// ── Logged-in state: dashboard ──

	// ── Panels and navigation ──
	$panels = [
		'overview'  => [
			'label' => __( 'Overview', 'mission-donation-platform' ),
			'icon'  => 'grid',
			'file'  => __DIR__ . '/parts/overview.php',
		],
		'history'   => [
			'label' => __( 'Donation History', 'mission-donation-platform' ),
			'icon'  => 'clock',
			'file'  => __DIR__ . '/parts/history.php',
		],
		'recurring' => [
			'label' => __( 'Recurring Donations', 'mission-donation-platform' ),
			'icon'  => 'refresh',
			'file'  => __DIR__ . '/parts/recurring.php',
		],
		'receipts'  => [
			'label' => __( 'Annual Receipts', 'mission-donation-platform' ),
			'icon'  => 'receipt',
			'file'  => __DIR__ . '/parts/receipts.php',
		],
		'profile'   => [
			'label' => __( 'Profile', 'mission-donation-platform' ),
			'icon'  => 'user',
			'file'  => __DIR__ . '/parts/profile.php',
		],
	];

	// Remove panels disabled in Settings > Donor Portal > Portal Features.
	$portal_features  = $mission_settings['portal_features'] ?? [];
	$feature_to_panel = [
		'donation_history'   => 'history',
		'manage_recurring'   => 'recurring',
		'annual_tax_summary' => 'receipts',
		'profile_editing'    => 'profile',
	];

	foreach ( $feature_to_panel as $feature_key => $panel_key ) {
		if ( isset( $portal_features[ $feature_key ] ) && ! $portal_features[ $feature_key ] ) {
			unset( $panels[ $panel_key ] );
		}
	}

	// Whether to show the "Update Payment Method" button in the recurring panel.
	$show_update_payment = $portal_features['update_payment'] ?? true;

	/**
	 * Filters the available donor dashboard panels.
	 *
	 * Each panel is a keyed array with 'label', 'icon', and 'file' keys.
	 * Keys are the panel ID used in hash routing (e.g. 'overview', 'history').
	 *
	 * @param array                $panels Panel definitions keyed by panel ID.
	 * @param \MissionDP\Models\Donor $donor  The current donor.
	 */
	$panels = apply_filters( 'missiondp_donor_dashboard_panels', $panels, $donor );

	$panel_labels = array_combine(
		array_keys( $panels ),
		array_column( $panels, 'label' )
	);

	$nav_items = [];
	foreach ( $panels as $panel_id => $panel ) {
		$nav_items[] = [
			'id'    => $panel_id,
			'label' => $panel['label'],
			'icon'  => $panel['icon'],
		];
	}

	/**
	 * Filters the donor dashboard navigation items.
	 *
	 * Each item has 'id', 'label', and 'icon' keys.
	 *
	 * @param array                $nav_items Navigation items.
	 * @param \MissionDP\Models\Donor $donor     The current donor.
	 */
	$nav_items = apply_filters( 'missiondp_donor_dashboard_nav_items', $nav_items, $donor );

	// Build context and state via the context builder.
	$builder  = new \MissionDP\DonorDashboard\DashboardContextBuilder( $donor, $mission_settings );
	$result   = $builder->build( $panels, $panel_labels );
	$context  = $result['context'];
	$initials = $context['donor']['initials'];

	// Make preferences available to profile template for server-side checked() calls.
	$preferences = [
		'email_receipts'         => $context['profile']['preferences']['emailReceipts'],
		'email_campaign_updates' => $context['profile']['preferences']['emailCampaignUpdates'],
		'email_annual_reminder'  => $context['profile']['preferences']['emailAnnualReminder'],
	];

	// Server-side initial values for derived state — JS getters take over on hydration.
	wp_interactivity_state( 'mission-donation-platform/donor-dashboard', $result['state'] );
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-donor-dashboard' ] ) ); ?>
		data-wp-interactive="mission-donation-platform/donor-dashboard"
		<?php echo wp_kses_post( wp_interactivity_data_wp_context( $context ) ); ?>
		data-wp-init="callbacks.init"
		data-wp-on-document--keydown="actions.handleGlobalKeydown"
		style="<?php echo esc_attr( $color_style ); ?>"
	>
		<div class="mission-dd-wrapper">
			<!-- Toast notification -->
			<div
				class="mission-dd-toast"
				data-wp-bind--hidden="!context.toast.visible"
				data-wp-class--mission-dd-toast-success="state.toastIsSuccess"
				data-wp-class--mission-dd-toast-error="state.toastIsError"
				data-wp-class--mission-dd-toast-dismissing="context.toast.dismissing"
				role="status"
				aria-live="polite"
			>
				<span class="mission-dd-toast-icon" aria-hidden="true">
					<span class="mission-dd-icon mission-dd-icon-check" data-wp-bind--hidden="!state.toastIsSuccess"></span>
					<span class="mission-dd-icon mission-dd-icon-alert" data-wp-bind--hidden="state.toastIsSuccess"></span>
				</span>
				<span data-wp-text="context.toast.message"></span>
			</div>

			<div class="mission-dd-layout" data-wp-class--sidebar-open="context.sidebarOpen">

				<?php require __DIR__ . '/parts/sidebar.php'; ?>

				<!-- Overlay (mobile drawer) -->
				<div class="mission-dd-overlay" data-wp-on--click="actions.closeSidebar"></div>

				<!-- Content -->
				<main class="mission-dd-content">
					<!-- Mobile toggle (visible ≤600px via container query) -->
					<button class="mission-dd-mobile-toggle" data-wp-on--click="actions.openSidebar" aria-label="<?php esc_attr_e( 'Open menu', 'mission-donation-platform' ); ?>">
						<span class="mission-dd-icon mission-dd-icon-menu" aria-hidden="true"></span>
						<span data-wp-text="state.panelTitle"></span>
					</button>

					<h1 class="mission-dd-page-title" data-wp-text="state.panelTitle" tabindex="-1"></h1>

					<?php
					foreach ( $panels as $panel_id => $panel ) {
						if ( ! empty( $panel['file'] ) && file_exists( $panel['file'] ) ) {
							require $panel['file'];
						}
					}
					?>
				</main>

			</div>
		</div>
	</div>
	<?php
endif;

$output = ob_get_clean();

/**
 * Filters the donor dashboard block output.
 *
 * @param string $output     HTML output.
 * @param array  $attributes Block attributes.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped above, filter consumers are responsible for their additions.
echo apply_filters( 'missiondp_donor_dashboard_output', $output, $attributes );
