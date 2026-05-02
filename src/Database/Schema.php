<?php
/**
 * Database schema definitions.
 *
 * @package MissionDP
 */

namespace MissionDP\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Schema class.
 */
class Schema {

	/**
	 * Get all table schemas.
	 *
	 * Each SQL statement is dbDelta-compatible (two spaces before column
	 * definitions, KEY definitions inline, no trailing commas).
	 *
	 * @return array<string, string> Array of table names to SQL CREATE statements.
	 */
	public function get_table_schemas(): array {
		global $wpdb;

		$charset_collate = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
		$prefix          = $wpdb->prefix . 'missiondp_';

		return [
			// ----------------------------------------------------------------
			// Transactions
			// ----------------------------------------------------------------
			"{$prefix}transactions"        => "CREATE TABLE {$prefix}transactions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'pending',
  type varchar(20) NOT NULL DEFAULT 'one_time',
  donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subscription_id bigint(20) unsigned DEFAULT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned DEFAULT NULL,
  amount bigint(20) NOT NULL DEFAULT 0,
  fee_amount bigint(20) NOT NULL DEFAULT 0,
  tip_amount bigint(20) NOT NULL DEFAULT 0,
  total_amount bigint(20) NOT NULL DEFAULT 0,
  amount_refunded bigint(20) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'usd',
  payment_gateway varchar(50) NOT NULL DEFAULT '',
  gateway_transaction_id varchar(255) DEFAULT NULL,
  gateway_subscription_id varchar(255) DEFAULT NULL,
  gateway_customer_id varchar(255) DEFAULT '' NOT NULL,
  is_anonymous tinyint(1) NOT NULL DEFAULT 0,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  donor_ip varchar(45) NOT NULL DEFAULT '',
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_completed datetime DEFAULT NULL,
  date_refunded datetime DEFAULT NULL,
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status),
  KEY donor_id (donor_id),
  KEY subscription_id (subscription_id),
  KEY campaign_id (campaign_id),
  KEY gateway_transaction_id (gateway_transaction_id),
  KEY date_created (date_created),
  KEY is_test (is_test)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Transaction Meta
			// ----------------------------------------------------------------
			"{$prefix}transactionmeta"     => "CREATE TABLE {$prefix}transactionmeta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  missiondp_transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY missiondp_transaction_id (missiondp_transaction_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Donors
			// ----------------------------------------------------------------
			"{$prefix}donors"              => "CREATE TABLE {$prefix}donors (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned DEFAULT NULL,
  email varchar(255) NOT NULL DEFAULT '',
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  phone varchar(30) NOT NULL DEFAULT '',
  address_1 varchar(255) NOT NULL DEFAULT '',
  address_2 varchar(255) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  state varchar(100) NOT NULL DEFAULT '',
  zip varchar(20) NOT NULL DEFAULT '',
  country varchar(2) NOT NULL DEFAULT 'US',
  total_donated bigint(20) NOT NULL DEFAULT 0,
  total_tip bigint(20) NOT NULL DEFAULT 0,
  transaction_count int(10) unsigned NOT NULL DEFAULT 0,
  first_transaction datetime DEFAULT NULL,
  last_transaction datetime DEFAULT NULL,
  test_total_donated bigint(20) NOT NULL DEFAULT 0,
  test_total_tip bigint(20) NOT NULL DEFAULT 0,
  test_transaction_count int(10) unsigned NOT NULL DEFAULT 0,
  test_first_transaction datetime DEFAULT NULL,
  test_last_transaction datetime DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY user_id (user_id)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Donor Meta
			// ----------------------------------------------------------------
			"{$prefix}donormeta"           => "CREATE TABLE {$prefix}donormeta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  missiondp_donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY missiondp_donor_id (missiondp_donor_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Subscriptions
			// ----------------------------------------------------------------
			"{$prefix}subscriptions"       => "CREATE TABLE {$prefix}subscriptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'pending',
  donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned DEFAULT NULL,
  initial_transaction_id bigint(20) unsigned DEFAULT NULL,
  amount bigint(20) NOT NULL DEFAULT 0,
  fee_amount bigint(20) NOT NULL DEFAULT 0,
  tip_amount bigint(20) NOT NULL DEFAULT 0,
  total_amount bigint(20) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'usd',
  frequency varchar(20) NOT NULL DEFAULT 'monthly',
  payment_gateway varchar(50) NOT NULL DEFAULT '',
  gateway_subscription_id varchar(255) DEFAULT NULL,
  gateway_customer_id varchar(255) DEFAULT NULL,
  renewal_count int(10) unsigned NOT NULL DEFAULT 0,
  total_renewed bigint(20) NOT NULL DEFAULT 0,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_next_renewal datetime DEFAULT NULL,
  date_cancelled datetime DEFAULT NULL,
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status),
  KEY donor_id (donor_id),
  KEY campaign_id (campaign_id),
  KEY gateway_subscription_id (gateway_subscription_id),
  KEY date_created (date_created),
  KEY date_next_renewal (date_next_renewal),
  KEY is_test (is_test)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Subscription Meta
			// ----------------------------------------------------------------
			"{$prefix}subscriptionmeta"    => "CREATE TABLE {$prefix}subscriptionmeta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  missiondp_subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY missiondp_subscription_id (missiondp_subscription_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Campaign Meta
			// ----------------------------------------------------------------
			"{$prefix}campaignmeta"        => "CREATE TABLE {$prefix}campaignmeta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  missiondp_campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY missiondp_campaign_id (missiondp_campaign_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Campaigns
			// ----------------------------------------------------------------
			"{$prefix}campaigns"           => "CREATE TABLE {$prefix}campaigns (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(200) NOT NULL DEFAULT '',
  description text NOT NULL,
  goal_amount bigint(20) NOT NULL DEFAULT 0,
  goal_type varchar(20) NOT NULL DEFAULT 'amount',
  total_raised bigint(20) NOT NULL DEFAULT 0,
  transaction_count int(10) unsigned NOT NULL DEFAULT 0,
  donor_count int(10) unsigned NOT NULL DEFAULT 0,
  test_total_raised bigint(20) NOT NULL DEFAULT 0,
  test_transaction_count int(10) unsigned NOT NULL DEFAULT 0,
  test_donor_count int(10) unsigned NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'usd',
  show_in_listings tinyint(1) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'active',
  date_start datetime DEFAULT NULL,
  date_end datetime DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY post_id (post_id),
  KEY status (status)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Notes (unified — transactions, donors, subscriptions)
			// ----------------------------------------------------------------
			"{$prefix}notes"               => "CREATE TABLE {$prefix}notes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT 'internal',
  content longtext NOT NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY object_type_id (object_type, object_id),
  KEY type (type),
  KEY date_created (date_created)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Transaction History
			// ----------------------------------------------------------------
			"{$prefix}transaction_history" => "CREATE TABLE {$prefix}transaction_history (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transaction_id bigint(20) unsigned NOT NULL,
  event_type varchar(50) NOT NULL,
  actor_type varchar(20) DEFAULT NULL,
  actor_id bigint(20) unsigned DEFAULT NULL,
  context longtext,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY idx_transaction_id (transaction_id),
  KEY idx_event_type (event_type),
  KEY idx_created_at (created_at)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Tributes
			// ----------------------------------------------------------------
			"{$prefix}tributes"            => "CREATE TABLE {$prefix}tributes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tribute_type varchar(20) NOT NULL DEFAULT 'in_honor',
  honoree_name varchar(255) NOT NULL DEFAULT '',
  notify_name varchar(255) NOT NULL DEFAULT '',
  notify_email varchar(255) NOT NULL DEFAULT '',
  notify_address_1 varchar(255) NOT NULL DEFAULT '',
  notify_address_2 varchar(255) NOT NULL DEFAULT '',
  notify_city varchar(100) NOT NULL DEFAULT '',
  notify_state varchar(100) NOT NULL DEFAULT '',
  notify_zip varchar(20) NOT NULL DEFAULT '',
  notify_country varchar(2) NOT NULL DEFAULT '',
  notify_method varchar(10) NOT NULL DEFAULT '',
  message text NOT NULL,
  notification_sent_at datetime DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY transaction_id (transaction_id),
  KEY tribute_type (tribute_type)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Activity Log
			// ----------------------------------------------------------------
			"{$prefix}activity_log"        => "CREATE TABLE {$prefix}activity_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(50) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(100) NOT NULL DEFAULT '',
  actor_id bigint(20) unsigned DEFAULT NULL,
  data longtext,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  level varchar(10) NOT NULL DEFAULT 'info',
  category varchar(20) NOT NULL DEFAULT 'system',
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY object_type_id (object_type, object_id),
  KEY event (event),
  KEY is_test (is_test),
  KEY level_category (level, category),
  KEY date_created (date_created)
) {$charset_collate};",
		];
	}

	/**
	 * Get all custom table names (fully prefixed).
	 *
	 * @return string[] Array of table names.
	 */
	public function get_table_names(): array {
		return array_keys( $this->get_table_schemas() );
	}
}
