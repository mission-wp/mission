<?php
/**
 * Deactivation survey modal on the plugins screen.
 *
 * @package MissionDP
 */

namespace MissionDP\Admin;

use MissionDP\Rest\Endpoints\DeactivationSurveyEndpoint;
use MissionDP\Rest\RestModule;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation survey class.
 *
 * Intercepts the Deactivate link on plugins.php with an anonymous,
 * skippable feedback modal. All user-facing strings are rendered here in
 * PHP so the companion script needs no translations.
 */
class DeactivationSurvey {

	/**
	 * Initialize the deactivation survey.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_footer-plugins.php', [ $this, 'render_modal' ] );
	}

	/**
	 * Enqueue the survey script and styles on the plugins screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$asset_file = MISSIONDP_PATH . 'admin/build/mission-deactivation.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'mission-deactivation',
			MISSIONDP_URL . 'admin/build/mission-deactivation.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'mission-deactivation',
			MISSIONDP_URL . 'admin/build/style-mission-deactivation.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'mission-deactivation',
			'missiondpDeactivation',
			[
				'restUrl'   => rest_url( RestModule::NAMESPACE . '/deactivation-survey' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Print the survey modal markup in the plugins screen footer.
	 *
	 * @return void
	 */
	public function render_modal(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<dialog id="mission-deactivation-survey" class="mission-deactivation-survey" aria-labelledby="mission-ds-title">
			<form>
				<header class="mission-ds__header">
					<img src="<?php echo esc_url( MISSIONDP_URL . 'assets/img/icon-mission.svg' ); ?>" alt="" width="40" height="40" />
					<h2 id="mission-ds-title"><?php esc_html_e( 'Quick feedback before you go', 'mission-donation-platform' ); ?></h2>
				</header>
				<div class="mission-ds__body">
					<p class="mission-ds__intro"><?php esc_html_e( 'If you have a moment, tell us why you are deactivating Mission. Responses are anonymous and help us improve the plugin.', 'mission-donation-platform' ); ?></p>
					<fieldset class="mission-ds__reasons">
						<legend class="screen-reader-text"><?php esc_html_e( 'Reason for deactivating', 'mission-donation-platform' ); ?></legend>
						<?php foreach ( $this->get_reasons() as $key => $reason ) : ?>
							<label class="mission-ds__reason">
								<input
									type="radio"
									name="mission_ds_reason"
									value="<?php echo esc_attr( $key ); ?>"
									<?php if ( ! empty( $reason['followup'] ) ) : ?>
										data-followup="<?php echo esc_attr( $reason['followup'] ); ?>"
									<?php endif; ?>
									<?php if ( isset( $reason['send'] ) && ! $reason['send'] ) : ?>
										data-no-send="1"
									<?php endif; ?>
								/>
								<span><?php echo esc_html( $reason['label'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<div class="mission-ds__followup" hidden>
						<?php foreach ( $this->get_reasons() as $key => $reason ) : ?>
							<?php if ( ! empty( $reason['help'] ) ) : ?>
								<p class="mission-ds__help" data-reason="<?php echo esc_attr( $key ); ?>" hidden>
									<?php
									echo wp_kses(
										$reason['help'],
										[
											'a' => [
												'href'   => [],
												'target' => [],
												'rel'    => [],
											],
										]
									);
									?>
								</p>
							<?php endif; ?>
						<?php endforeach; ?>
						<label class="screen-reader-text" for="mission-ds-feedback"><?php esc_html_e( 'Additional feedback', 'mission-donation-platform' ); ?></label>
						<textarea id="mission-ds-feedback" name="mission_ds_feedback" rows="2" maxlength="1000"></textarea>
					</div>
				</div>
				<footer class="mission-ds__footer">
					<a href="#" class="mission-ds__skip"><?php esc_html_e( 'Skip & Deactivate', 'mission-donation-platform' ); ?></a>
					<div class="mission-ds__actions">
						<button type="button" class="mission-ds__cancel"><?php esc_html_e( 'Cancel', 'mission-donation-platform' ); ?></button>
						<button type="button" class="mission-ds__submit" disabled data-busy-label="<?php esc_attr_e( 'Submitting…', 'mission-donation-platform' ); ?>"><?php esc_html_e( 'Submit & Deactivate', 'mission-donation-platform' ); ?></button>
					</div>
				</footer>
			</form>
		</dialog>
		<?php
	}

	/**
	 * Get the survey reasons configuration.
	 *
	 * Keys come from DeactivationSurveyEndpoint::REASONS so the markup and
	 * REST validation cannot drift apart.
	 *
	 * Reasons with 'send' => false (e.g. a temporary deactivation while
	 * troubleshooting) deactivate immediately without reporting to the API.
	 *
	 * @return array<string, array{label: string, followup: string, help?: string, send?: bool}>
	 */
	private function get_reasons(): array {
		$labels = [
			'temporary'           => [
				'label'    => __( "It's a temporary deactivation, I'm troubleshooting", 'mission-donation-platform' ),
				'followup' => '',
				'send'     => false,
			],
			'no-longer-needed'    => [
				'label'    => __( 'I no longer need the plugin', 'mission-donation-platform' ),
				'followup' => '',
			],
			'found-better-plugin' => [
				'label'    => __( 'I found a better plugin', 'mission-donation-platform' ),
				'followup' => __( 'Which plugin did you switch to?', 'mission-donation-platform' ),
			],
			'missing-feature'     => [
				'label'    => __( "It's missing a feature I need", 'mission-donation-platform' ),
				'followup' => __( 'What feature is missing?', 'mission-donation-platform' ),
			],
			'not-working'         => [
				'label'    => __( "Something isn't working", 'mission-donation-platform' ),
				'followup' => __( 'What went wrong? Any detail helps.', 'mission-donation-platform' ),
				'help'     => sprintf(
					/* translators: %s: link to the support forum. */
					__( 'Sorry about that! Most issues can be fixed quickly. %s and we\'ll take a look right away.', 'mission-donation-platform' ),
					'<a href="https://wordpress.org/support/plugin/mission-donation-platform/#new-topic-0" target="_blank" rel="noopener noreferrer">' . __( 'Open a support topic', 'mission-donation-platform' ) . '</a>'
				),
			],
			'too-complicated'     => [
				'label'    => __( "It's too complicated to set up", 'mission-donation-platform' ),
				'followup' => __( 'What was confusing or hard?', 'mission-donation-platform' ),
				'help'     => sprintf(
					/* translators: %s: hello@missionwp.com mailto link. */
					__( 'Email us at %s and we\'ll personally help you set it up.', 'mission-donation-platform' ),
					'<a href="mailto:hello@missionwp.com">hello@missionwp.com</a>'
				),
			],
			'other'               => [
				'label'    => __( 'Other', 'mission-donation-platform' ),
				'followup' => __( 'Tell us more (optional)', 'mission-donation-platform' ),
			],
		];

		$reasons = array_intersect_key( $labels, array_flip( DeactivationSurveyEndpoint::REASONS ) );

		/**
		 * Filters the deactivation survey reasons shown in the modal.
		 *
		 * Note: reason keys must be present in DeactivationSurveyEndpoint::REASONS
		 * or the REST endpoint will reject the submission.
		 *
		 * @param array $reasons Reason key => [ label, followup ] pairs.
		 */
		return apply_filters( 'mission_deactivation_survey_reasons', $reasons );
	}
}
