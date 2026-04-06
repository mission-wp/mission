<?php
/**
 * Tests for the HasMeta trait across all models that use it.
 *
 * @package Mission
 */

namespace Mission\Tests\Models;

use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use Mission\Models\Transaction;
use WP_UnitTestCase;

/**
 * HasMeta trait test class.
 *
 * Uses a data provider to run every meta operation against Campaign, Donor,
 * and Transaction models, verifying the trait works identically across all three.
 */
class HasMetaTraitTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up all relevant tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Data provider: all models that use HasMeta.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function model_provider(): array {
		return [
			'Campaign'     => [ Campaign::class ],
			'Donor'        => [ Donor::class ],
			'Subscription' => [ Subscription::class ],
			'Transaction'  => [ Transaction::class ],
		];
	}

	/**
	 * Create and save a model instance.
	 *
	 * @param class-string $class Model class name.
	 * @return Campaign|Donor|Subscription|Transaction
	 */
	private function create_model( string $class ): Campaign|Donor|Subscription|Transaction {
		return match ( $class ) {
			Campaign::class     => $this->create_campaign(),
			Donor::class        => $this->create_donor(),
			Subscription::class => $this->create_subscription(),
			Transaction::class  => $this->create_transaction(),
		};
	}

	/**
	 * Create and save a campaign.
	 *
	 * @return Campaign
	 */
	private function create_campaign(): Campaign {
		$campaign = new Campaign( [
			'title'       => 'Meta Test Campaign',
			'description' => 'Testing meta.',
		] );
		$campaign->save();

		return $campaign;
	}

	/**
	 * Create and save a donor.
	 *
	 * @return Donor
	 */
	private function create_donor(): Donor {
		$donor = new Donor( [
			'email'      => 'meta-test@example.com',
			'first_name' => 'Meta',
			'last_name'  => 'Test',
		] );
		$donor->save();

		return $donor;
	}

	/**
	 * Create and save a subscription.
	 *
	 * @return Subscription
	 */
	private function create_subscription(): Subscription {
		$donor = $this->create_donor();

		$subscription = new Subscription( [
			'status'          => 'active',
			'donor_id'        => $donor->id,
			'amount'          => 2500,
			'total_amount'    => 2500,
			'payment_gateway' => 'stripe',
		] );
		$subscription->save();

		return $subscription;
	}

	/**
	 * Create and save a transaction.
	 *
	 * @return Transaction
	 */
	private function create_transaction(): Transaction {
		$donor = $this->create_donor();

		$transaction = new Transaction( [
			'status'         => 'completed',
			'donor_id'       => $donor->id,
			'source_post_id' => 1,
			'amount'         => 5000,
			'total_amount'   => 5000,
		] );
		$transaction->save();

		return $transaction;
	}

	// -------------------------------------------------------------------------
	// add_meta() tests.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider model_provider
	 */
	public function test_add_meta_creates_entry( string $class ): void {
		$model   = $this->create_model( $class );
		$meta_id = $model->add_meta( 'color', 'green' );

		$this->assertIsInt( $meta_id );
		$this->assertGreaterThan( 0, $meta_id );
		$this->assertSame( 'green', $model->get_meta( 'color' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_add_meta_allows_duplicate_keys( string $class ): void {
		$model = $this->create_model( $class );

		$model->add_meta( 'tag', 'alpha' );
		$model->add_meta( 'tag', 'beta' );

		$values = $model->get_meta( 'tag', false );

		$this->assertIsArray( $values );
		$this->assertCount( 2, $values );
		$this->assertContains( 'alpha', $values );
		$this->assertContains( 'beta', $values );
	}

	// -------------------------------------------------------------------------
	// get_meta() tests.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider model_provider
	 */
	public function test_get_meta_retrieves_value( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'color', 'blue' );

		$this->assertSame( 'blue', $model->get_meta( 'color' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_get_meta_returns_empty_string_for_nonexistent_key( string $class ): void {
		$model = $this->create_model( $class );

		$this->assertSame( '', $model->get_meta( 'nonexistent' ) );
	}

	// -------------------------------------------------------------------------
	// update_meta() tests.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider model_provider
	 */
	public function test_update_meta_updates_existing_value( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'color', 'red' );
		$model->update_meta( 'color', 'blue' );

		$this->assertSame( 'blue', $model->get_meta( 'color' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_update_meta_creates_if_key_missing( string $class ): void {
		$model = $this->create_model( $class );

		$result = $model->update_meta( 'brand_new', 'fresh' );

		$this->assertTrue( $result );
		$this->assertSame( 'fresh', $model->get_meta( 'brand_new' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_update_meta_normalizes_true_to_string( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'flag', true );

		$this->assertSame( '1', $model->get_meta( 'flag' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_update_meta_normalizes_false_to_string( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'flag', false );

		$this->assertSame( '0', $model->get_meta( 'flag' ) );
	}

	// -------------------------------------------------------------------------
	// delete_meta() tests.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider model_provider
	 */
	public function test_delete_meta_removes_entry( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'temp', 'value' );
		$result = $model->delete_meta( 'temp' );

		$this->assertTrue( $result );
		$this->assertSame( '', $model->get_meta( 'temp' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_delete_meta_returns_false_for_nonexistent_key( string $class ): void {
		$model = $this->create_model( $class );

		$this->assertFalse( $model->delete_meta( 'never_set' ) );
	}

	// -------------------------------------------------------------------------
	// get_all_meta() tests.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider model_provider
	 */
	public function test_get_all_meta_returns_key_value_map( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'key_a', 'val_a' );
		$model->update_meta( 'key_b', 'val_b' );
		$model->update_meta( 'key_c', 'val_c' );

		$all = $model->get_all_meta();

		$this->assertIsArray( $all );
		$this->assertSame( 'val_a', $all['key_a'] );
		$this->assertSame( 'val_b', $all['key_b'] );
		$this->assertSame( 'val_c', $all['key_c'] );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_get_all_meta_returns_empty_array_when_none( string $class ): void {
		$model = $this->create_model( $class );

		// Campaign auto-creates milestones meta on save, so skip it.
		if ( Campaign::class === $class ) {
			$model->delete_meta( 'milestones' );
		}

		$this->assertSame( [], $model->get_all_meta() );
	}

	// -------------------------------------------------------------------------
	// Type handling tests.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider model_provider
	 */
	public function test_meta_stores_serialized_arrays( string $class ): void {
		$model = $this->create_model( $class );
		$data  = [ 'alpha', 'beta', 'gamma' ];

		$model->update_meta( 'list', $data );

		$this->assertSame( $data, $model->get_meta( 'list' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_meta_stores_numeric_values_as_strings( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'count', 42 );

		$this->assertSame( '42', $model->get_meta( 'count' ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_meta_stores_null_value( string $class ): void {
		$model = $this->create_model( $class );

		$model->update_meta( 'nullable', 'something' );
		$model->update_meta( 'nullable', null );

		$this->assertSame( '', $model->get_meta( 'nullable' ) );
	}

	// -------------------------------------------------------------------------
	// Duplicate-key interaction tests.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider model_provider
	 */
	public function test_delete_meta_removes_all_duplicate_entries( string $class ): void {
		$model = $this->create_model( $class );

		$model->add_meta( 'tag', 'alpha' );
		$model->add_meta( 'tag', 'beta' );

		$model->delete_meta( 'tag' );

		$this->assertSame( '', $model->get_meta( 'tag' ) );
		$this->assertSame( [], $model->get_meta( 'tag', false ) );
	}

	/**
	 * @dataProvider model_provider
	 */
	public function test_get_all_meta_with_duplicate_keys( string $class ): void {
		$model = $this->create_model( $class );

		// Campaign auto-creates milestones meta on save, so remove it.
		if ( Campaign::class === $class ) {
			$model->delete_meta( 'milestones' );
		}

		$model->add_meta( 'tag', 'alpha' );
		$model->add_meta( 'tag', 'beta' );
		$model->update_meta( 'color', 'red' );

		$all = $model->get_all_meta();

		// get_all_meta returns a key => single-value map, so duplicates collapse.
		$this->assertArrayHasKey( 'tag', $all );
		$this->assertArrayHasKey( 'color', $all );
		$this->assertSame( 'red', $all['color'] );
	}
}
