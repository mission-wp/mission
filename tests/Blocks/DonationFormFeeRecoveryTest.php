<?php
/**
 * Tests that the donation form only adds a processing fee the donor can see.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Blocks;

use WP_UnitTestCase;

/**
 * Donation form fee recovery test class.
 */
class DonationFormFeeRecoveryTest extends WP_UnitTestCase {

	/**
	 * Use live mode for every test.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'missiondp_settings', [ 'test_mode' => false, 'currency' => 'USD' ] );
	}

	/**
	 * Render the donation form, failing on CI (skipping locally) when it is not built.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Rendered HTML with entities decoded.
	 */
	private function render( array $attributes ): string {
		$name = 'mission-donation-platform/donation-form';

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			if ( getenv( 'CI' ) ) {
				$this->fail( "{$name} is not registered. Run npm run build before the PHP suite in CI." );
			}

			$this->markTestSkipped( "{$name} is not built/registered in this environment." );
		}

		$output = do_blocks( sprintf( '<!-- wp:%s %s /-->', $name, wp_json_encode( (object) $attributes ) ) );

		return html_entity_decode( $output, ENT_QUOTES );
	}

	/**
	 * A form whose organization absorbs fees starts with no fee on the total.
	 */
	public function test_absorbed_fees_are_not_added_to_the_total(): void {
		$output = $this->render( [ 'feeRecovery' => false, 'feeMode' => 'none' ] );

		$this->assertStringContainsString( '"feeRecoveryChecked":false', $output );
		$this->assertStringNotContainsString( 'mission-df-fee-recovery', $output );
	}

	/**
	 * Optional fee recovery starts covered and shows the fee line.
	 */
	public function test_optional_fee_recovery_starts_covered(): void {
		$output = $this->render( [ 'feeRecovery' => true, 'feeMode' => 'optional' ] );

		$this->assertStringContainsString( '"feeRecoveryChecked":true', $output );
		$this->assertStringContainsString( 'mission-df-fee-recovery', $output );
	}

	/**
	 * Required fee recovery is always covered.
	 */
	public function test_required_fee_recovery_is_covered(): void {
		$output = $this->render( [ 'feeRecovery' => true, 'feeMode' => 'required' ] );

		$this->assertStringContainsString( '"feeRecoveryChecked":true', $output );
	}

	/**
	 * A form with no saved fee settings keeps the optional default.
	 */
	public function test_default_form_offers_fee_recovery(): void {
		$output = $this->render( [] );

		$this->assertStringContainsString( '"feeRecoveryChecked":true', $output );
	}
}
