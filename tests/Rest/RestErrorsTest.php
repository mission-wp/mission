<?php
/**
 * Tests for the RestErrors factory.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest;

use MissionDP\Rest\RestErrors;
use WP_UnitTestCase;

/**
 * RestErrors test class.
 */
class RestErrorsTest extends WP_UnitTestCase {

	/**
	 * Test the generic factory.
	 */
	public function test_not_found(): void {
		$error = RestErrors::not_found( 'thing_not_found', 'Thing not found.' );

		$this->assertWPError( $error );
		$this->assertSame( 'thing_not_found', $error->get_error_code() );
		$this->assertSame( 'Thing not found.', $error->get_error_message() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
	}

	/**
	 * Test every named factory produces its entity-specific code, message, and a 404.
	 *
	 * @dataProvider entity_provider
	 *
	 * @param string $method  Factory method name.
	 * @param string $code    Expected error code.
	 * @param string $message Expected message.
	 */
	public function test_entity_factories( string $method, string $code, string $message ): void {
		$error = RestErrors::$method();

		$this->assertSame( $code, $error->get_error_code() );
		$this->assertSame( $message, $error->get_error_message() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
	}

	/**
	 * Entity factory expectations.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function entity_provider(): array {
		return [
			'donor'        => [ 'donor_not_found', 'donor_not_found', 'Donor not found.' ],
			'transaction'  => [ 'transaction_not_found', 'transaction_not_found', 'Transaction not found.' ],
			'subscription' => [ 'subscription_not_found', 'subscription_not_found', 'Subscription not found.' ],
			'campaign'     => [ 'campaign_not_found', 'campaign_not_found', 'Campaign not found.' ],
			'webhook'      => [ 'webhook_not_found', 'webhook_not_found', 'Webhook not found.' ],
			'note'         => [ 'note_not_found', 'note_not_found', 'Note not found.' ],
			'account'      => [ 'account_not_found', 'account_not_found', 'Stripe account not found.' ],
		];
	}
}
