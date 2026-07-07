<?php
/**
 * Tests for the EmailModule.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Email;

use MissionDP\Email\EmailModule;
use MissionDP\Models\Donor;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * Email module test class.
 */
class EmailModuleTest extends WP_UnitTestCase {

	/**
	 * Module under test.
	 *
	 * @var EmailModule
	 */
	private EmailModule $module;

	/**
	 * Set up a fresh module and mailer before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		reset_phpmailer_instance();

		$this->module = new EmailModule();
		$this->module->init();
	}

	/**
	 * Clean up settings after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );
		delete_option( 'missiondp_dashboard_page_id' );
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * Store email settings for a single email type.
	 *
	 * @param string               $email_type Email type key.
	 * @param array<string, mixed> $values     Settings for the type (enabled, subject, body).
	 * @return void
	 */
	private function set_email_settings( string $email_type, array $values ): void {
		update_option( SettingsService::OPTION_NAME, [ 'emails' => [ $email_type => $values ] ] );
	}

	// -------------------------------------------------------------------------
	// is_email_enabled() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test email types default to enabled when no settings are stored.
	 */
	public function test_is_email_enabled_defaults_to_true(): void {
		$this->assertTrue( $this->module->is_email_enabled( 'donation_receipt' ) );
		$this->assertTrue( $this->module->is_email_enabled( 'some_unknown_type' ) );
	}

	/**
	 * Test a disabled email type returns false.
	 */
	public function test_is_email_enabled_returns_false_when_disabled(): void {
		$this->set_email_settings( 'donation_receipt', [ 'enabled' => false ] );

		$this->assertFalse( $this->module->is_email_enabled( 'donation_receipt' ) );
	}

	/**
	 * Test disabling one email type does not affect another.
	 */
	public function test_is_email_enabled_types_are_independent(): void {
		$this->set_email_settings( 'donation_receipt', [ 'enabled' => false ] );

		$this->assertFalse( $this->module->is_email_enabled( 'donation_receipt' ) );
		$this->assertTrue( $this->module->is_email_enabled( 'renewal_receipt' ) );
	}

	// -------------------------------------------------------------------------
	// get_custom_subject() / get_custom_body() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test custom subject is empty when none is stored.
	 */
	public function test_get_custom_subject_empty_by_default(): void {
		$this->assertSame( '', $this->module->get_custom_subject( 'donation_receipt' ) );
	}

	/**
	 * Test custom subject returns the stored value.
	 */
	public function test_get_custom_subject_returns_stored_value(): void {
		$this->set_email_settings( 'donation_receipt', [ 'subject' => 'Thanks {donor_name}!' ] );

		$this->assertSame( 'Thanks {donor_name}!', $this->module->get_custom_subject( 'donation_receipt' ) );
	}

	/**
	 * Test subject() falls back to the default subject and replaces its tags.
	 */
	public function test_subject_uses_default_with_tags(): void {
		$subject = $this->module->subject( 'donation_receipt', [ '{amount}' => '$25.00' ] );

		$this->assertSame( 'Thank you for your $25.00 donation', $subject );
	}

	/**
	 * Test subject() prefers a stored custom subject over the default.
	 */
	public function test_subject_prefers_custom_subject(): void {
		$this->set_email_settings( 'donation_receipt', [ 'subject' => 'Thanks {donor_name}!' ] );

		$subject = $this->module->subject( 'donation_receipt', [ '{donor_name}' => 'Sam' ] );

		$this->assertSame( 'Thanks Sam!', $subject );
	}

	/**
	 * Test subject() returns an empty string for an unknown type.
	 */
	public function test_subject_empty_for_unknown_type(): void {
		$this->assertSame( '', $this->module->subject( 'nonexistent_type' ) );
	}

	/**
	 * Test custom body is empty when none is stored.
	 */
	public function test_get_custom_body_empty_by_default(): void {
		$this->assertSame( '', $this->module->get_custom_body( 'donation_receipt' ) );
	}

	/**
	 * Test custom body returns the stored value.
	 */
	public function test_get_custom_body_returns_stored_value(): void {
		$this->set_email_settings( 'donation_receipt', [ 'body' => '<p>Custom body</p>' ] );

		$this->assertSame( '<p>Custom body</p>', $this->module->get_custom_body( 'donation_receipt' ) );
	}

	// -------------------------------------------------------------------------
	// replace_subject_tags() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test all known tags in a subject are replaced.
	 */
	public function test_replace_subject_tags_replaces_all_tags(): void {
		$result = $this->module->replace_subject_tags(
			'Thanks {donor_name} for {amount} to {campaign}',
			[
				'{donor_name}' => 'Jane',
				'{amount}'     => '$50.00',
				'{campaign}'   => 'Clean Water',
			]
		);

		$this->assertSame( 'Thanks Jane for $50.00 to Clean Water', $result );
	}

	/**
	 * Test unknown tags are left intact.
	 */
	public function test_replace_subject_tags_leaves_unknown_tags_intact(): void {
		$result = $this->module->replace_subject_tags(
			'Hello {donor_name}, ref {unknown_tag}',
			[ '{donor_name}' => 'Jane' ]
		);

		$this->assertSame( 'Hello Jane, ref {unknown_tag}', $result );
	}

	// -------------------------------------------------------------------------
	// build_merge_tags() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a fully populated data array produces the expected tag map.
	 */
	public function test_build_merge_tags_with_full_data(): void {
		update_option( SettingsService::OPTION_NAME, [ 'org_name' => 'Test Org' ] );

		$donor = new Donor( [ 'first_name' => 'Jane' ] );

		$tags = $this->module->build_merge_tags(
			[
				'donor'                  => $donor,
				'amount_formatted'       => '$50.00',
				'date_formatted'         => 'June 12, 2026',
				'campaign_name'          => 'Clean Water',
				'frequency_label'        => 'Monthly',
				'next_renewal_formatted' => 'July 12, 2026',
				'honoree_name'           => 'Grandma',
				'message'                => 'Miss you',
				'tribute_type_label'     => 'in memory of',
			]
		);

		$this->assertSame( 'Jane', $tags['{donor_name}'] );
		$this->assertSame( 'Test Org', $tags['{organization}'] );
		$this->assertSame( '$50.00', $tags['{amount}'] );
		$this->assertSame( 'June 12, 2026', $tags['{date}'] );
		$this->assertSame( 'Clean Water', $tags['{campaign}'] );
		$this->assertSame( 'Monthly', $tags['{frequency}'] );
		$this->assertSame( 'July 12, 2026', $tags['{next_renewal_date}'] );
		$this->assertSame( 'Grandma', $tags['{honoree_name}'] );
		$this->assertSame( 'Miss you', $tags['{message}'] );
		$this->assertSame( 'in memory of', $tags['{tribute_type_label}'] );
		$this->assertSame( home_url(), $tags['{site_url}'] );
	}

	/**
	 * Test missing data fields fall back to safe defaults.
	 */
	public function test_build_merge_tags_defaults_for_missing_data(): void {
		$tags = $this->module->build_merge_tags( [] );

		$this->assertSame( 'Friend', $tags['{donor_name}'] );
		$this->assertSame( '', $tags['{amount}'] );
		$this->assertSame( '', $tags['{campaign}'] );
		$this->assertSame( '', $tags['{receipt_id}'] );
		$this->assertSame( '', $tags['{note_content}'] );
		$this->assertSame( '', $tags['{team_name}'] );
		$this->assertSame( '', $tags['{giver_name}'] );
	}

	/**
	 * Test the P2P tags offered by the template editor resolve from listener data.
	 */
	public function test_build_merge_tags_p2p_data(): void {
		$captain = new Donor( [ 'first_name' => 'Cap' ] );

		$tags = $this->module->build_merge_tags(
			[
				'donor'            => $captain,
				'team'             => (object) [ 'name' => 'Runners' ],
				'member_name'      => 'Jane Doe',
				'giver_name'       => 'Generous Gil',
				'milestone_label'  => '50%',
				'raised_formatted' => '$500.00',
				'goal_formatted'   => '$1,000.00',
				'page_url'         => 'https://example.com/fundraiser/jane',
				'accept_url'       => 'https://example.com/team/runners?team_invite=abc',
			]
		);

		$this->assertSame( 'Runners', $tags['{team_name}'] );
		$this->assertSame( 'Cap', $tags['{captain_name}'] );
		$this->assertSame( 'Jane Doe', $tags['{member_name}'] );
		$this->assertSame( 'Generous Gil', $tags['{giver_name}'] );
		$this->assertSame( '50%', $tags['{milestone}'] );
		$this->assertSame( '$500.00', $tags['{amount}'] );
		$this->assertSame( '$1,000.00', $tags['{goal}'] );
		$this->assertSame( 'https://example.com/fundraiser/jane', $tags['{page_url}'] );
		$this->assertSame( 'https://example.com/team/runners?team_invite=abc', $tags['{accept_url}'] );
	}

	/**
	 * Test a customized body has P2P merge tags replaced when the email renders.
	 */
	public function test_render_template_replaces_p2p_tags_in_custom_body(): void {
		$this->set_email_settings( 'p2p_team_member_joined', [ 'body' => '<p>{member_name} joined {team_name}!</p>' ] );

		$module = new EmailModule();
		$module->init();

		$html = $module->render_template(
			'p2p-team-member-joined',
			[
				'donor'       => new Donor( [ 'first_name' => 'Cap' ] ),
				'team'        => (object) [ 'name' => 'Runners' ],
				'member_name' => 'Jane Doe',
				'subject'     => 'x',
			]
		);

		$this->assertStringContainsString( 'Jane Doe joined Runners!', $html );
	}

	// -------------------------------------------------------------------------
	// Formatting helper tests.
	// -------------------------------------------------------------------------

	/**
	 * Test format_amount handles decimal and zero-decimal currencies.
	 */
	public function test_format_amount(): void {
		$this->assertSame( '$50.00', $this->module->format_amount( 5000, 'usd' ) );
		$this->assertSame( '¥500', $this->module->format_amount( 500, 'jpy' ) );
	}

	/**
	 * Test the donor greeting uses the first name with a fallback.
	 */
	public function test_format_donor_greeting(): void {
		$jane = new Donor( [ 'first_name' => 'Jane' ] );
		$anon = new Donor();

		$this->assertSame( 'Hi Jane,', $this->module->format_donor_greeting( $jane ) );
		$this->assertSame( 'Hi Friend,', $this->module->format_donor_greeting( $anon ) );
	}

	// -------------------------------------------------------------------------
	// Template rendering tests.
	// -------------------------------------------------------------------------

	/**
	 * Test an unknown partial renders to an empty string.
	 */
	public function test_render_partial_unknown_returns_empty_string(): void {
		$this->assertSame( '', $this->module->render_partial( 'does-not-exist' ) );
	}

	/**
	 * Test the header partial renders the subject into the document.
	 */
	public function test_render_partial_header_renders_subject(): void {
		$html = $this->module->render_partial( 'header', [ 'subject' => 'My Subject Line' ] );

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'My Subject Line', $html );
	}

	/**
	 * Test an unknown template renders to an empty string.
	 */
	public function test_render_template_unknown_returns_empty_string(): void {
		$this->assertSame( '', $this->module->render_template( 'does-not-exist' ) );
	}

	/**
	 * Test a known template renders header, body, and footer with injected data.
	 */
	public function test_render_template_renders_template_with_data(): void {
		$donor = new Donor( [ 'first_name' => 'Jane' ] );

		$html = $this->module->render_template(
			'donation-receipt',
			[
				'subject'          => 'Receipt subject',
				'donor'            => $donor,
				'amount_formatted' => '$50.00',
				'date_formatted'   => 'June 12, 2026',
				'campaign_name'    => 'Clean Water',
			]
		);

		// Header, body, footer all present.
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Hi Jane,', $html );
		$this->assertStringContainsString( '$50.00', $html );
		$this->assertStringContainsString( 'Clean Water', $html );
		$this->assertStringContainsString( '</html>', $html );
	}

	/**
	 * Test a stored custom body replaces the template file and resolves merge tags.
	 */
	public function test_render_template_uses_custom_body_with_merge_tags(): void {
		$this->set_email_settings( 'donation_receipt', [ 'body' => '<p>Custom thanks {donor_name} for {amount}</p>' ] );

		$donor = new Donor( [ 'first_name' => 'Jane' ] );

		$html = $this->module->render_template(
			'donation-receipt',
			[
				'donor'            => $donor,
				'amount_formatted' => '$50.00',
				'date_formatted'   => 'June 12, 2026',
				'campaign_name'    => '',
			]
		);

		$this->assertStringContainsString( 'Custom thanks Jane for $50.00', $html );
		// The default template body is not used.
		$this->assertStringNotContainsString( 'Your donation has been received successfully.', $html );
	}

	/**
	 * Test render_template_body returns the body without header or footer.
	 */
	public function test_render_template_body_returns_body_only(): void {
		$donor = new Donor( [ 'first_name' => 'Jane' ] );

		$html = $this->module->render_template_body(
			'donation-receipt',
			[
				'donor'            => $donor,
				'amount_formatted' => '$50.00',
				'date_formatted'   => 'June 12, 2026',
				'campaign_name'    => '',
			]
		);

		$this->assertStringContainsString( 'Hi Jane,', $html );
		$this->assertStringNotContainsString( '<!DOCTYPE html>', $html );
	}

	// -------------------------------------------------------------------------
	// send() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test send delivers via wp_mail with HTML content type and configured From.
	 */
	public function test_send_delivers_email_with_default_headers(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'org_name'           => 'Test Org',
				'email_from_name'    => 'Mission Sender',
				'email_from_address' => 'from@example.org',
			]
		);

		$result = $this->module->send( 'donor@example.com', 'Test subject', '<p>Hello</p>' );

		$this->assertTrue( $result );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent( 0 );

		$this->assertSame( 'donor@example.com', $sent->to[0][0] );
		$this->assertSame( 'Test subject', $sent->subject );
		$this->assertStringContainsString( '<p>Hello</p>', $sent->body );
		$this->assertStringContainsString( 'Content-Type: text/html', $sent->header );
		$this->assertStringContainsString( 'From: Mission Sender <from@example.org>', $sent->header );
	}

	/**
	 * Test send includes a Reply-To header when configured.
	 */
	public function test_send_includes_reply_to_when_configured(): void {
		update_option( SettingsService::OPTION_NAME, [ 'email_reply_to' => 'reply@example.org' ] );

		$this->module->send( 'donor@example.com', 'Test subject', '<p>Hello</p>' );

		$sent = tests_retrieve_phpmailer_instance()->get_sent( 0 );

		$this->assertStringContainsString( 'Reply-To: reply@example.org', $sent->header );
	}

	/**
	 * Test send fires the mission_email_sent action on success.
	 */
	public function test_send_fires_mission_email_sent_action(): void {
		$fired = [];
		add_action(
			'mission_email_sent',
			static function ( $to, $subject ) use ( &$fired ) {
				$fired[] = [ $to, $subject ];
			},
			10,
			2
		);

		$this->module->send( 'donor@example.com', 'Test subject', '<p>Hello</p>' );

		$this->assertSame( [ [ 'donor@example.com', 'Test subject' ] ], $fired );
	}
}
