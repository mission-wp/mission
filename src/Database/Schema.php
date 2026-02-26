<?php
/**
 * Database schema definitions.
 *
 * @package Mission
 */

namespace Mission\Database;

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
		$prefix          = $wpdb->prefix . 'mission_';

		return array(
			// ----------------------------------------------------------------
			// Donations
			// ----------------------------------------------------------------
			"{$prefix}donations"     => "CREATE TABLE {$prefix}donations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'pending',
  type varchar(20) NOT NULL DEFAULT 'one_time',
  donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subscription_id bigint(20) unsigned DEFAULT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned DEFAULT NULL,
  amount bigint(20) NOT NULL DEFAULT 0,
  fee_amount bigint(20) NOT NULL DEFAULT 0,
  tip_amount bigint(20) NOT NULL DEFAULT 0,
  total_amount bigint(20) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'usd',
  payment_gateway varchar(50) NOT NULL DEFAULT '',
  gateway_transaction_id varchar(255) DEFAULT NULL,
  gateway_subscription_id varchar(255) DEFAULT NULL,
  is_anonymous tinyint(1) NOT NULL DEFAULT 0,
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
  KEY date_created (date_created)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Donation Meta
			// ----------------------------------------------------------------
			"{$prefix}donation_meta" => "CREATE TABLE {$prefix}donation_meta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  donation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY donation_id (donation_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Donors
			// ----------------------------------------------------------------
			"{$prefix}donors"        => "CREATE TABLE {$prefix}donors (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned DEFAULT NULL,
  email varchar(255) NOT NULL DEFAULT '',
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  name_prefix varchar(20) NOT NULL DEFAULT '',
  phone varchar(30) NOT NULL DEFAULT '',
  total_donated bigint(20) NOT NULL DEFAULT 0,
  total_tip bigint(20) NOT NULL DEFAULT 0,
  donation_count int(10) unsigned NOT NULL DEFAULT 0,
  first_donation datetime DEFAULT NULL,
  last_donation datetime DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY user_id (user_id)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Donor Meta
			// ----------------------------------------------------------------
			"{$prefix}donor_meta"    => "CREATE TABLE {$prefix}donor_meta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY donor_id (donor_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Subscriptions
			// ----------------------------------------------------------------
			"{$prefix}subscriptions" => "CREATE TABLE {$prefix}subscriptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'pending',
  donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned DEFAULT NULL,
  initial_donation_id bigint(20) unsigned DEFAULT NULL,
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
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_next_renewal datetime DEFAULT NULL,
  date_cancelled datetime DEFAULT NULL,
  date_expired datetime DEFAULT NULL,
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status),
  KEY donor_id (donor_id),
  KEY gateway_subscription_id (gateway_subscription_id),
  KEY date_next_renewal (date_next_renewal)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Campaigns
			// ----------------------------------------------------------------
			"{$prefix}campaigns"     => "CREATE TABLE {$prefix}campaigns (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'draft',
  title varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  description text,
  goal_amount bigint(20) NOT NULL DEFAULT 0,
  total_raised bigint(20) NOT NULL DEFAULT 0,
  donation_count int(10) unsigned NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'usd',
  date_start datetime DEFAULT NULL,
  date_end datetime DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status (status)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Campaign Meta
			// ----------------------------------------------------------------
			"{$prefix}campaign_meta" => "CREATE TABLE {$prefix}campaign_meta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY campaign_id (campaign_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",
		);
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
