<?php
/**
 * Tests for model relationship methods.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Note;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * Model relationships test class.
 */
class ModelRelationshipsTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Truncate all tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save a donor.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		$donor = new Donor( array_merge(
			[
				'email'      => 'test@example.com',
				'first_name' => 'Test',
				'last_name'  => 'Donor',
			],
			$overrides
		) );
		$donor->save();

		return $donor;
	}

	/**
	 * Create and save a campaign.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Campaign
	 */
	private function create_campaign( array $overrides = [] ): Campaign {
		$campaign = new Campaign( array_merge(
			[
				'title'       => 'Test Campaign',
				'description' => 'A test campaign.',
			],
			$overrides
		) );
		$campaign->save();

		return $campaign;
	}

	/**
	 * Create and save a transaction.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Transaction
	 */
	private function create_transaction( array $overrides = [] ): Transaction {
		$transaction = new Transaction( array_merge(
			[
				'status'         => 'completed',
				'donor_id'       => 1,
				'source_post_id' => 1,
				'amount'         => 5000,
				'total_amount'   => 5000,
			],
			$overrides
		) );
		$transaction->save();

		return $transaction;
	}

	/**
	 * Create and save a subscription.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Subscription
	 */
	private function create_subscription( array $overrides = [] ): Subscription {
		$subscription = new Subscription( array_merge(
			[
				'status'         => 'active',
				'donor_id'       => 1,
				'source_post_id' => 1,
				'amount'         => 2500,
				'total_amount'   => 2500,
				'frequency'      => 'monthly',
			],
			$overrides
		) );
		$subscription->save();

		return $subscription;
	}

	/**
	 * Transaction::donor() returns the correct Donor instance.
	 */
	public function test_transaction_donor_returns_correct_donor(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$result = $transaction->donor();

		$this->assertInstanceOf( Donor::class, $result );
		$this->assertSame( $donor->id, $result->id );
		$this->assertSame( 'test@example.com', $result->email );
	}

	/**
	 * Transaction::donor() returns null when donor_id is invalid.
	 */
	public function test_transaction_donor_returns_null_for_invalid_id(): void {
		$transaction = $this->create_transaction( [ 'donor_id' => 99999 ] );

		$this->assertNull( $transaction->donor() );
	}

	/**
	 * Transaction::campaign() returns the correct Campaign instance.
	 */
	public function test_transaction_campaign_returns_correct_campaign(): void {
		$donor       = $this->create_donor();
		$campaign    = $this->create_campaign();
		$transaction = $this->create_transaction( [
			'donor_id'    => $donor->id,
			'campaign_id' => $campaign->id,
		] );

		$result = $transaction->campaign();

		$this->assertInstanceOf( Campaign::class, $result );
		$this->assertSame( $campaign->id, $result->id );
	}

	/**
	 * Transaction::campaign() returns null when campaign_id is null.
	 */
	public function test_transaction_campaign_returns_null_when_campaign_id_is_null(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id'    => $donor->id,
			'campaign_id' => null,
		] );

		$this->assertNull( $transaction->campaign() );
	}

	/**
	 * Transaction::subscription() returns the correct Subscription instance.
	 */
	public function test_transaction_subscription_returns_correct_subscription(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );
		$transaction  = $this->create_transaction( [
			'donor_id'        => $donor->id,
			'subscription_id' => $subscription->id,
		] );

		$result = $transaction->subscription();

		$this->assertInstanceOf( Subscription::class, $result );
		$this->assertSame( $subscription->id, $result->id );
	}

	/**
	 * Donor::transactions() returns all transactions for the donor.
	 */
	public function test_donor_transactions_returns_all_transactions(): void {
		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id, 'amount' => 1000 ] );
		$this->create_transaction( [ 'donor_id' => $donor->id, 'amount' => 2000 ] );
		$this->create_transaction( [ 'donor_id' => $donor->id, 'amount' => 3000 ] );

		// Create a transaction for a different donor to ensure filtering works.
		$other_donor = $this->create_donor( [ 'email' => 'other@example.com' ] );
		$this->create_transaction( [ 'donor_id' => $other_donor->id ] );

		$transactions = $donor->transactions();

		$this->assertCount( 3, $transactions );
		$this->assertContainsOnlyInstancesOf( Transaction::class, $transactions );

		foreach ( $transactions as $transaction ) {
			$this->assertSame( $donor->id, $transaction->donor_id );
		}
	}

	/**
	 * Donor::subscriptions() returns all subscriptions for the donor.
	 */
	public function test_donor_subscriptions_returns_all_subscriptions(): void {
		$donor = $this->create_donor();
		$this->create_subscription( [ 'donor_id' => $donor->id, 'frequency' => 'monthly' ] );
		$this->create_subscription( [ 'donor_id' => $donor->id, 'frequency' => 'yearly' ] );

		// Create a subscription for a different donor.
		$other_donor = $this->create_donor( [ 'email' => 'other@example.com' ] );
		$this->create_subscription( [ 'donor_id' => $other_donor->id ] );

		$subscriptions = $donor->subscriptions();

		$this->assertCount( 2, $subscriptions );
		$this->assertContainsOnlyInstancesOf( Subscription::class, $subscriptions );

		foreach ( $subscriptions as $subscription ) {
			$this->assertSame( $donor->id, $subscription->donor_id );
		}
	}

	/**
	 * Donor::notes() returns all notes for the donor.
	 */
	public function test_donor_notes_returns_all_notes(): void {
		$donor = $this->create_donor();

		$note1 = new Note( [
			'object_type' => 'donor',
			'object_id'   => $donor->id,
			'content'     => 'First note',
		] );
		$note1->save();

		$note2 = new Note( [
			'object_type' => 'donor',
			'object_id'   => $donor->id,
			'content'     => 'Second note',
		] );
		$note2->save();

		// Create a note for a different donor.
		$other_donor = $this->create_donor( [ 'email' => 'other@example.com' ] );
		$other_note  = new Note( [
			'object_type' => 'donor',
			'object_id'   => $other_donor->id,
			'content'     => 'Other donor note',
		] );
		$other_note->save();

		$notes = $donor->notes();

		$this->assertCount( 2, $notes );
		$this->assertContainsOnlyInstancesOf( Note::class, $notes );

		foreach ( $notes as $note ) {
			$this->assertSame( $donor->id, $note->object_id );
		}
	}

	/**
	 * Campaign::transactions() returns all transactions for the campaign.
	 */
	public function test_campaign_transactions_returns_all_transactions(): void {
		$donor    = $this->create_donor();
		$campaign = $this->create_campaign();
		$this->create_transaction( [ 'donor_id' => $donor->id, 'campaign_id' => $campaign->id ] );
		$this->create_transaction( [ 'donor_id' => $donor->id, 'campaign_id' => $campaign->id ] );

		// Create a transaction for a different campaign.
		$other_campaign = $this->create_campaign( [ 'title' => 'Other Campaign' ] );
		$this->create_transaction( [ 'donor_id' => $donor->id, 'campaign_id' => $other_campaign->id ] );

		$transactions = $campaign->transactions();

		$this->assertCount( 2, $transactions );
		$this->assertContainsOnlyInstancesOf( Transaction::class, $transactions );

		foreach ( $transactions as $transaction ) {
			$this->assertSame( $campaign->id, $transaction->campaign_id );
		}
	}

	/**
	 * Subscription::donor() returns the correct Donor instance.
	 */
	public function test_subscription_donor_returns_correct_donor(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$result = $subscription->donor();

		$this->assertInstanceOf( Donor::class, $result );
		$this->assertSame( $donor->id, $result->id );
		$this->assertSame( 'test@example.com', $result->email );
	}

	/**
	 * Subscription::campaign() returns the correct Campaign instance.
	 */
	public function test_subscription_campaign_returns_correct_campaign(): void {
		$donor        = $this->create_donor();
		$campaign     = $this->create_campaign();
		$subscription = $this->create_subscription( [
			'donor_id'    => $donor->id,
			'campaign_id' => $campaign->id,
		] );

		$result = $subscription->campaign();

		$this->assertInstanceOf( Campaign::class, $result );
		$this->assertSame( $campaign->id, $result->id );
	}

	/**
	 * Subscription::transactions() returns all renewal transactions.
	 */
	public function test_subscription_transactions_returns_all_renewals(): void {
		$donor        = $this->create_donor();
		$subscription = $this->create_subscription( [ 'donor_id' => $donor->id ] );

		$this->create_transaction( [
			'donor_id'        => $donor->id,
			'subscription_id' => $subscription->id,
			'type'            => 'recurring',
		] );
		$this->create_transaction( [
			'donor_id'        => $donor->id,
			'subscription_id' => $subscription->id,
			'type'            => 'recurring',
		] );

		// Create a transaction not linked to this subscription.
		$this->create_transaction( [ 'donor_id' => $donor->id ] );

		$transactions = $subscription->transactions();

		$this->assertCount( 2, $transactions );
		$this->assertContainsOnlyInstancesOf( Transaction::class, $transactions );

		foreach ( $transactions as $transaction ) {
			$this->assertSame( $subscription->id, $transaction->subscription_id );
		}
	}

}
