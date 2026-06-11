# Changelog

## 1.3.0 (2026-06-11)

* New: One-click migration from GiveWP, bringing over donors, donations, campaigns, and subscriptions with a pre-flight scan, live progress, and a full undo (Tools > Migration)
* New: Outgoing webhooks. Notify external services when donations, subscriptions, donors, or campaigns change, with signed payloads, automatic retries, and a delivery log (Tools > Webhooks)
* New: Choose the URL slug used for campaign pages (Settings > General). Fresh installs automatically avoid clashing with an existing "campaigns" page
* Enhancement: The donation form shortcode now inherits the campaign's configured form settings when campaign_id is set
* Fix: Mission now works on SQLite-based WordPress installs (WordPress Playground, WP Studio) by removing MySQL-only SQL from campaign, subscription, reporting, cleanup, and activity log queries
* Fix: The "delete test data" tools now also remove the meta rows belonging to deleted test transactions, donors, and subscriptions (the cleanup previously referenced the wrong meta column and silently skipped them)
* Tweak: Renamed all developer hooks (actions and filters) from missiondp_ to mission_. If you have custom code hooking into Mission, update the hook names. Scheduled task hooks, options, and database tables are unchanged.
* Tweak: REST API parameters that declare allowed values or numeric ranges now reject invalid input with a clear 400 error instead of silently accepting it

## 1.2.0 (2026-06-10)

* New: Added a data import tool for donors, transactions, campaigns, subscriptions, and dedications, with CSV and JSON support
* New: Preview and validate your file before importing, with per-row warnings and the choice to skip or update existing records
* New: Large imports run in the background so they don't time out
* New: Mission is now fully translatable and ships with a complete Spanish (es_ES) translation
* New: Every block now has a shortcode equivalent, so Mission can be used with page builders like Elementor and Bricks and in the Classic Editor
* Enhancement: Improved support for international currencies, with correct amounts, fees, and decimals for currencies like the Japanese yen and Kuwaiti dinar
* Enhancement: Donation minimums now follow Stripe's per-currency minimums, with clearer messages when an amount is too low
* Enhancement: The Dedications export now includes a Charge ID column so dedications can be matched back to their transactions when re-imported
* Enhancement: The activity log now shows who ran an import and how many records were imported or updated
* Enhancement: Improved the donation form layout on small screens and in narrow spaces like sidebars
* Tweak: Updated the currency list to match the currencies Stripe currently supports
* Tweak: Internal code quality and maintainability improvements

## 1.1.7 (2026-06-08)

* New: Connect multiple Stripe accounts, mark one as the default, and choose per donation form which account receives donations
* New: Added an "Edit Campaign" link to the admin bar when viewing a campaign on the frontend
* Enhancement: Tools > Status now lists every connected Stripe account instead of only the default one

## 1.1.6 (2026-06-07)

* Enhancement: Expanded the country dropdown to the full ISO 3166-1 list so any country is selectable
* Fix: Fixed the plugin update entry in the activity log to show the correct new version

## 1.1.5 (2026-06-05)

* New: Added a data export tool for transactions, donors, subscriptions, and campaigns
* New: Shows an admin notice when the site is running PHP below 8.0
* Enhancement: Auto-scrolls to the first newly loaded entry when expanding the logs list
* Fix: Donors can now sign in with their new email address after changing it on the donor dashboard
* Fix: Fixed donor dashboard form fields losing their class and id attributes
* Fix: Restored the database size display in System Status

## 1.1.4 (2026-06-02)

* Fix: No longer shows the empty-state shell on Subscriptions and Transactions when a search is active
* Fix: Fixed multi-word search on Donors, Transactions, and Subscriptions admin pages
* Fix: Fixed a fatal on subscription detail when a transaction had no recorded fee_amount
* Tweak: Updated screenshots and banners

## 1.1.3 (2026-05-23)

* Enhancement: API and webhook diagnostics now appear in Tools > Logs alongside other plugin activity
* Tweak: Tested with WordPress 7.0
* Tweak: Refreshed the admin menu icon
* Tweak: Internal code quality and maintainability improvements

## 1.1.2 (2026-05-15)

* Security: Converted ReportingService identifier interpolation to %i placeholders
* Fix: Fixed onboarding state select rendering taller than adjacent input fields
* Tweak: Updated @wordpress/dataviews to 14.3.0 and @wordpress/icons to 13.1.0
* Tweak: Wrapped PHP templates in IIFEs to scope file-local variables
* Tweak: Silenced Plugin Check false positives in the custom-table data layer
* Tweak: Prefixed $autoloader and silenced the core-hook invocation sniff

## 1.1.1 (2026-05-10)

* Enhancement: Donor login now goes through WordPress's standard auth pipeline for better compatibility with security plugins
* Tweak: Donor user accounts are no longer deleted automatically when the plugin is uninstalled

## 1.1.0 (2026-05-02)

* Security: Refactored DataStore queries and Cleanup IN-clauses to use wpdb::prepare() with proper placeholders
* Security: Escaped block render output through wp_kses with an allowlist that preserves SVG icons and Interactivity API directives
* Security: Moved security checks into permission_callback for state-changing public REST endpoints (donation/subscription confirm, email-change confirm)
* Fix: Fixed MISSIONDP_STRIPE_PK_TEST to match the live platform Stripe account
* Tweak: Renamed the plugin slug to mission-donation-platform and prefixed all PHP and JS identifiers with missiondp_ per WordPress.org review
* Tweak: Added a Source Code section to the readme linking to the public GitHub repo with build instructions
* Tweak: Disclosed Gravatar usage under External Services in the readme
* Tweak: Removed unused stripe/stripe-php dependency
* Tweak: Added WordPress.org directory assets (banners, icons, screenshots)

## 1.0.1 (2026-04-19)

* Tweak: Renamed plugin to Mission Donation Platform per WordPress.org review team guidance

## 1.0.0 (2026-04-06)

* New: Initial release
