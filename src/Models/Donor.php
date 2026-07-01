<?php
/**
 * Donor model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\DonorDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Donor model with relationship and meta access.
 */
class Donor extends Model {

	use HasMeta;

	public ?int $user_id;
	public string $email;
	public string $first_name;
	public string $last_name;
	public string $phone;
	public string $address_1;
	public string $address_2;
	public string $city;
	public string $state;
	public string $zip;
	public string $country;
	public int $total_donated;
	public int $total_tip;
	public int $transaction_count;
	public ?string $first_transaction;
	public ?string $last_transaction;
	public int $test_total_donated;
	public int $test_total_tip;
	public int $test_transaction_count;
	public ?string $test_first_transaction;
	public ?string $test_last_transaction;
	public string $date_created;
	public string $date_modified;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                     = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->user_id                = isset( $data['user_id'] ) ? (int) $data['user_id'] : null;
		$this->email                  = $data['email'] ?? '';
		$this->first_name             = $data['first_name'] ?? '';
		$this->last_name              = $data['last_name'] ?? '';
		$this->phone                  = $data['phone'] ?? '';
		$this->address_1              = $data['address_1'] ?? '';
		$this->address_2              = $data['address_2'] ?? '';
		$this->city                   = $data['city'] ?? '';
		$this->state                  = $data['state'] ?? '';
		$this->zip                    = $data['zip'] ?? '';
		$this->country                = $data['country'] ?? 'US';
		$this->total_donated          = (int) ( $data['total_donated'] ?? 0 );
		$this->total_tip              = (int) ( $data['total_tip'] ?? 0 );
		$this->transaction_count      = (int) ( $data['transaction_count'] ?? 0 );
		$this->first_transaction      = $data['first_transaction'] ?? null;
		$this->last_transaction       = $data['last_transaction'] ?? null;
		$this->test_total_donated     = (int) ( $data['test_total_donated'] ?? 0 );
		$this->test_total_tip         = (int) ( $data['test_total_tip'] ?? 0 );
		$this->test_transaction_count = (int) ( $data['test_transaction_count'] ?? 0 );
		$this->test_first_transaction = $data['test_first_transaction'] ?? null;
		$this->test_last_transaction  = $data['test_last_transaction'] ?? null;
		$this->date_created           = $data['date_created'] ?? current_time( 'mysql', true );
		$this->date_modified          = $data['date_modified'] ?? current_time( 'mysql', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new DonorDataStore();
	}

	/**
	 * Find a donor by email address.
	 *
	 * @param string $email Email address.
	 * @return self|null
	 */
	public static function find_by_email( string $email ): ?self {
		/** @var DonorDataStore $store */
		$store = static::store();
		return $store->find_by_email( $email );
	}

	/**
	 * Find a donor by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return self|null
	 */
	public static function find_by_user_id( int $user_id ): ?self {
		/** @var DonorDataStore $store */
		$store = static::store();
		return $store->find_by_user_id( $user_id );
	}

	/**
	 * Recompute a donor's lifetime aggregates from the transactions table.
	 *
	 * Used by bulk backfill paths (import, migration) that write rows silently
	 * and rebuild aggregates once at the end of the job.
	 *
	 * @param int $donor_id Donor ID.
	 */
	public static function recompute_aggregates( int $donor_id ): void {
		/** @var DonorDataStore $store */
		$store = static::store();
		$store->recompute_aggregates( $donor_id );
	}

	/**
	 * Create a WordPress user account for this donor and link it.
	 *
	 * @param string $password Plain-text password.
	 * @return int The new WordPress user ID.
	 *
	 * @throws \RuntimeException If the donor already has an account or user creation fails.
	 */
	public function create_user_account( string $password ): int {
		if ( $this->user_id ) {
			throw new \RuntimeException( esc_html__( 'This donor already has an account.', 'mission-donation-platform' ) );
		}

		// Use the donor email as the username.
		$user_id = wp_insert_user(
			[
				'user_login' => $this->email,
				'user_email' => $this->email,
				'user_pass'  => $password,
				'first_name' => $this->first_name,
				'last_name'  => $this->last_name,
				'role'       => 'missiondp_donor',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			throw new \RuntimeException( esc_html( $user_id->get_error_message() ) );
		}

		$this->user_id = $user_id;
		$this->save();

		return $user_id;
	}

	/**
	 * Change the donor's email address.
	 *
	 * Updates the donors table and the linked WordPress user's user_email.
	 * If the user's user_login matches the old email, it is also updated so
	 * the donor can keep signing in with their email. Otherwise user_login
	 * is left alone so existing WordPress users with arbitrary usernames
	 * (e.g. an admin or editor who also donated) can still log in.
	 *
	 * @param string $new_email The new email address.
	 */
	public function change_email( string $new_email ): void {
		global $wpdb;

		$old_email = $this->email;

		$this->email = $new_email;
		$this->save();

		if ( ! $this->user_id ) {
			return;
		}

		$user = get_userdata( $this->user_id );
		if ( ! $user ) {
			return;
		}

		$sync_user_login = strcasecmp( $user->user_login, $old_email ) === 0;

		wp_update_user(
			[
				'ID'         => $this->user_id,
				'user_email' => $new_email,
			]
		);

		if ( $sync_user_login ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WordPress API exists for updating user_login.
			$wpdb->update(
				$wpdb->users,
				[ 'user_login' => $new_email ],
				[ 'ID' => $this->user_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		clean_user_cache( $this->user_id );
	}

	/**
	 * Get the donor's full name.
	 *
	 * @return string
	 */
	public function full_name(): string {
		return trim( $this->first_name . ' ' . $this->last_name );
	}

	/**
	 * Get the Gravatar hash for this donor.
	 *
	 * @return string
	 */
	public function gravatar_hash(): string {
		return $this->email ? md5( strtolower( trim( $this->email ) ) ) : '';
	}

	/**
	 * Get the transactions for this donor.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Transaction[]
	 */
	public function transactions( array $args = [] ): array {
		return Transaction::query( array_merge( $args, [ 'donor_id' => $this->id ] ) );
	}

	/**
	 * Get the subscriptions for this donor.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Subscription[]
	 */
	public function subscriptions( array $args = [] ): array {
		return Subscription::query( array_merge( $args, [ 'donor_id' => $this->id ] ) );
	}

	/**
	 * Get the fundraisers (campaign participations) for this donor.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Fundraiser[]
	 */
	public function fundraisers( array $args = [] ): array {
		return Fundraiser::query( array_merge( $args, [ 'donor_id' => $this->id ] ) );
	}

	/**
	 * Get the notes for this donor.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 * @return Note[]
	 */
	public function notes( array $args = [] ): array {
		return Note::query(
			array_merge(
				$args,
				[
					'object_type' => 'donor',
					'object_id'   => $this->id,
				]
			)
		);
	}

	/**
	 * Delete the donor, cascading to their fundraiser records.
	 *
	 * A fundraiser record is one person's participation in one campaign; without
	 * the person it has no meaning, and its shell post would otherwise stay
	 * published. Model-level deletes so each shell post and detach logic runs.
	 *
	 * @return bool
	 */
	public function delete(): bool {
		foreach ( $this->fundraisers() as $fundraiser ) {
			$fundraiser->delete();
		}

		return parent::delete();
	}
}
