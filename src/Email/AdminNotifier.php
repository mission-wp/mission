<?php
/**
 * Central service for sending admin notification emails.
 *
 * Handles recipient resolution, enabled checks, and dispatching
 * for all admin notification types.
 *
 * @package MissionDP
 */

namespace MissionDP\Email;

use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Admin notifier class.
 */
class AdminNotifier {

	/**
	 * Email module instance.
	 *
	 * @var EmailModule
	 */
	private EmailModule $email;

	/**
	 * Settings service instance.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Constructor.
	 *
	 * @param EmailModule     $email    Email module.
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct( EmailModule $email, SettingsService $settings ) {
		$this->email    = $email;
		$this->settings = $settings;
	}

	/**
	 * Send an admin notification.
	 *
	 * @param string $type    Admin email type key (e.g. 'admin_new_donation').
	 * @param string $subject Email subject line.
	 * @param array  $data    Template data array.
	 * @return bool Whether at least one email was sent successfully.
	 */
	public function notify( string $type, string $subject, array $data = [] ): bool {
		if ( ! $this->is_enabled( $type ) ) {
			return false;
		}

		/**
		 * Filters whether an admin notification should be sent.
		 *
		 * Return false to suppress the notification. Useful for conditional
		 * logic like minimum donation amounts:
		 *
		 *     add_filter( 'missiondp_should_send_admin_notification', function ( $send, $type, $data ) {
		 *         if ( 'admin_new_donation' === $type && isset( $data['transaction'] ) ) {
		 *             return $data['transaction']->amount >= 10000; // $100+ only (amount is in cents).
		 *         }
		 *         return $send;
		 *     }, 10, 3 );
		 *
		 * @param bool   $send Whether to send the notification. Default true.
		 * @param string $type Notification type key (e.g. 'admin_new_donation').
		 * @param array  $data Template data including transaction/subscription objects.
		 */
		if ( ! apply_filters( 'missiondp_should_send_admin_notification', true, $type, $data ) ) {
			return false;
		}

		$recipients = $this->get_recipients( $type );
		if ( empty( $recipients ) ) {
			return false;
		}

		$template = str_replace( '_', '-', $type );
		$html     = $this->email->render_template( $template, array_merge( $data, [ 'subject' => $subject ] ) );

		if ( ! $html ) {
			return false;
		}

		$sent = false;
		foreach ( $recipients as $to ) {
			if ( $this->email->send( $to, $subject, $html ) ) {
				$sent = true;
			}
		}

		if ( $sent ) {
			/**
			 * Fires after an admin notification is sent successfully.
			 *
			 * @param string   $type       Notification type key.
			 * @param string[] $recipients Email addresses that were sent to.
			 * @param array    $data       Template data.
			 */
			do_action( 'missiondp_admin_notification_sent', $type, $recipients, $data );
		}

		return $sent;
	}

	/**
	 * Get the resolved recipients for an admin email type.
	 *
	 * Falls back to the WordPress admin email if no recipients are configured.
	 *
	 * @param string $type Admin email type key.
	 * @return string[] Email addresses.
	 */
	public function get_recipients( string $type ): array {
		$emails     = $this->settings->get( 'emails', [] );
		$recipients = $emails[ $type ]['recipients'] ?? [];

		if ( empty( $recipients ) ) {
			$admin_email = get_option( 'admin_email' );
			$recipients  = $admin_email ? [ $admin_email ] : [];
		}

		/**
		 * Filters the recipients for an admin notification.
		 *
		 * @param string[] $recipients Email addresses.
		 * @param string   $type       Notification type key.
		 */
		return apply_filters( 'missiondp_admin_notification_recipients', $recipients, $type );
	}

	/**
	 * Check if an admin notification type is enabled.
	 *
	 * @param string $type Admin email type key.
	 * @return bool
	 */
	public function is_enabled( string $type ): bool {
		return $this->email->is_email_enabled( $type );
	}
}
