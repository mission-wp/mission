<?php
/**
 * Email module - handles email sending and notifications.
 *
 * @package Mission
 */

namespace Mission\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Email module class.
 */
class EmailModule {

	/**
	 * Template directory path.
	 *
	 * @var string
	 */
	private string $template_dir;

	/**
	 * Initialize the email module.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->template_dir = plugin_dir_path( __FILE__ ) . 'templates/';
	}

	/**
	 * Render a partial template.
	 *
	 * @param string $partial Partial name (e.g., 'header', 'footer').
	 * @param array  $data Template data.
	 * @return string Rendered HTML.
	 */
	public function render_partial( string $partial, array $data = array() ): string {
		$partial_file = $this->template_dir . 'partials/' . $partial . '.php';

		if ( ! file_exists( $partial_file ) ) {
			return '';
		}

		ob_start();
		include $partial_file;
		return ob_get_clean();
	}

	/**
	 * Render a full email template (header + body + footer).
	 *
	 * @param string $template Template name (without .php extension).
	 * @param array  $data Template data.
	 * @return string Rendered HTML email.
	 */
	public function render_template( string $template, array $data = array() ): string {
		$template_file = $this->template_dir . $template . '.php';

		if ( ! file_exists( $template_file ) ) {
			return '';
		}

		$header_data = array_merge(
			$data,
			array(
				'subject' => $data['subject'] ?? '',
			)
		);
		$header      = $this->render_partial( 'header', $header_data );

		ob_start();
		include $template_file;
		$body = ob_get_clean();

		$footer = $this->render_partial( 'footer', $data );

		return $header . $body . $footer;
	}

	/**
	 * Get default email headers.
	 *
	 * @return array
	 */
	private function get_default_headers(): array {
		$from_name  = apply_filters( 'mission_email_from_name', get_bloginfo( 'name' ) );
		$from_email = apply_filters( 'mission_email_from_email', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		return apply_filters( 'mission_email_headers', $headers );
	}

	/**
	 * Send an email.
	 *
	 * @param string $to Email address.
	 * @param string $subject Email subject.
	 * @param string $message Email message (HTML).
	 * @param array  $headers Email headers (optional, will use defaults if not provided).
	 * @return bool
	 */
	public function send( string $to, string $subject, string $message, array $headers = array() ): bool {
		if ( empty( $headers ) ) {
			$headers = $this->get_default_headers();
		}

		$subject = apply_filters( 'mission_email_subject', $subject, $to );
		$message = apply_filters( 'mission_email_message', $message, $to );

		return wp_mail( $to, $subject, $message, $headers );
	}
}
