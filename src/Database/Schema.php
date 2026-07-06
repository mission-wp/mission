<?php
/**
 * Database schema definitions.
 *
 * @package MissionDP
 */

namespace MissionDP\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table data adapter; direct $wpdb is required.

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
  fundraiser_id bigint(20) unsigned DEFAULT NULL,
  team_id bigint(20) unsigned DEFAULT NULL,
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
  import_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
  KEY fundraiser_id (fundraiser_id),
  KEY team_id (team_id),
  KEY gateway_transaction_id (gateway_transaction_id),
  KEY date_created (date_created),
  KEY is_test (is_test),
  KEY import_job_id (import_job_id),
  KEY status_test_currency_date (status, is_test, currency, date_created),
  KEY status_test_completed (status, is_test, date_completed)
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
  fundraiser_id bigint(20) unsigned DEFAULT NULL,
  team_id bigint(20) unsigned DEFAULT NULL,
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
  KEY fundraiser_id (fundraiser_id),
  KEY team_id (team_id),
  KEY gateway_subscription_id (gateway_subscription_id),
  KEY date_created (date_created),
  KEY date_next_renewal (date_next_renewal),
  KEY is_test (is_test),
  KEY donor_status_test (donor_id, status, is_test)
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
  type varchar(20) NOT NULL DEFAULT 'standard',
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
  KEY status (status),
  KEY type (type)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Fundraisers (peer-to-peer participants)
			// ----------------------------------------------------------------
			"{$prefix}fundraisers"         => "CREATE TABLE {$prefix}fundraisers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  team_id bigint(20) unsigned DEFAULT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  goal bigint(20) unsigned NOT NULL DEFAULT 0,
  headline varchar(255) NOT NULL DEFAULT '',
  story text NOT NULL,
  cover_image varchar(255) NOT NULL DEFAULT '',
  profile_image varchar(255) NOT NULL DEFAULT '',
  total_raised bigint(20) NOT NULL DEFAULT 0,
  transaction_count int(10) unsigned NOT NULL DEFAULT 0,
  donor_count int(10) unsigned NOT NULL DEFAULT 0,
  test_total_raised bigint(20) NOT NULL DEFAULT 0,
  test_transaction_count int(10) unsigned NOT NULL DEFAULT 0,
  test_donor_count int(10) unsigned NOT NULL DEFAULT 0,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_donor (campaign_id, donor_id),
  KEY donor_id (donor_id),
  KEY team_id (team_id),
  KEY status (status),
  UNIQUE KEY post_id (post_id)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Fundraiser Meta
			// ----------------------------------------------------------------
			"{$prefix}fundraisermeta"      => "CREATE TABLE {$prefix}fundraisermeta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  missiondp_fundraiser_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY missiondp_fundraiser_id (missiondp_fundraiser_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Teams (peer-to-peer fundraising teams)
			// ----------------------------------------------------------------
			"{$prefix}teams"               => "CREATE TABLE {$prefix}teams (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  captain_id bigint(20) unsigned DEFAULT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(200) NOT NULL DEFAULT '',
  description text NOT NULL,
  goal bigint(20) unsigned NOT NULL DEFAULT 0,
  cover_image varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  access varchar(20) NOT NULL DEFAULT 'public',
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY campaign_id (campaign_id),
  KEY status (status),
  UNIQUE KEY post_id (post_id)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Team Meta
			// ----------------------------------------------------------------
			"{$prefix}teammeta"            => "CREATE TABLE {$prefix}teammeta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  missiondp_team_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (meta_id),
  KEY missiondp_team_id (missiondp_team_id),
  KEY meta_key (meta_key(191))
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Team Invitations
			// ----------------------------------------------------------------
			"{$prefix}team_invitations"    => "CREATE TABLE {$prefix}team_invitations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  team_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(255) NOT NULL DEFAULT '',
  token varchar(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY team_id (team_id),
  KEY email (email)
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
			// Import Jobs
			// ----------------------------------------------------------------
			"{$prefix}import_jobs"         => "CREATE TABLE {$prefix}import_jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT 'donors',
  duplicate_strategy varchar(20) NOT NULL DEFAULT 'skip',
  status varchar(20) NOT NULL DEFAULT 'queued',
  file_path text NOT NULL,
  original_filename varchar(255) NOT NULL DEFAULT '',
  total_rows int(10) unsigned NOT NULL DEFAULT 0,
  processed_rows int(10) unsigned NOT NULL DEFAULT 0,
  imported int(10) unsigned NOT NULL DEFAULT 0,
  skipped int(10) unsigned NOT NULL DEFAULT 0,
  updated int(10) unsigned NOT NULL DEFAULT 0,
  errors int(10) unsigned NOT NULL DEFAULT 0,
  error_details longtext,
  last_error text,
  started_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY job_id (job_id),
  KEY user_status (user_id, status),
  KEY status (status),
  KEY date_created (date_created)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Migration Phases
			// ----------------------------------------------------------------
			"{$prefix}migration_phases"    => "CREATE TABLE {$prefix}migration_phases (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(50) NOT NULL DEFAULT '',
  job_type varchar(20) NOT NULL DEFAULT 'migrate',
  entity varchar(20) NOT NULL DEFAULT '',
  phase_order int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'queued',
  options longtext,
  total_items int(10) unsigned NOT NULL DEFAULT 0,
  processed_items int(10) unsigned NOT NULL DEFAULT 0,
  imported int(10) unsigned NOT NULL DEFAULT 0,
  skipped int(10) unsigned NOT NULL DEFAULT 0,
  errors int(10) unsigned NOT NULL DEFAULT 0,
  error_details longtext,
  last_error text,
  last_source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  started_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY job_id (job_id),
  KEY status (status),
  KEY job_phase (job_id, phase_order)
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

			// ----------------------------------------------------------------
			// Outgoing Webhooks
			// ----------------------------------------------------------------
			"{$prefix}outgoing_webhooks"   => "CREATE TABLE {$prefix}outgoing_webhooks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL DEFAULT '',
  url varchar(2048) NOT NULL DEFAULT '',
  secret varchar(64) NOT NULL DEFAULT '',
  events longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  health varchar(20) NOT NULL DEFAULT 'healthy',
  failure_count int(10) unsigned NOT NULL DEFAULT 0,
  failing_since datetime DEFAULT NULL,
  last_delivery_at datetime DEFAULT NULL,
  last_response_code smallint(5) unsigned DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status),
  KEY health (health)
) {$charset_collate};",

			// ----------------------------------------------------------------
			// Webhook Deliveries
			// ----------------------------------------------------------------
			"{$prefix}webhook_deliveries"  => "CREATE TABLE {$prefix}webhook_deliveries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  webhook_id bigint(20) unsigned NOT NULL,
  event varchar(100) NOT NULL DEFAULT '',
  event_id varchar(50) NOT NULL DEFAULT '',
  url varchar(2048) NOT NULL DEFAULT '',
  request_headers longtext,
  request_body longtext,
  response_code smallint(5) unsigned DEFAULT NULL,
  response_headers longtext,
  response_body longtext,
  duration_ms int(10) unsigned DEFAULT NULL,
  attempt int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'pending',
  error_message varchar(500) DEFAULT NULL,
  date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY webhook_id (webhook_id),
  KEY event_id (event_id),
  KEY status (status),
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

	/**
	 * Drop every custom plugin table. Called during uninstall.
	 */
	public function drop_all_tables(): void {
		global $wpdb;

		foreach ( $this->get_table_names() as $table_name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
		}
	}
}
