=== Mission - Donation Plugin for WordPress - Fundraising & Recurring Donations ===
Contributors: missionwp
Tags: donations, fundraising, recurring donations, nonprofit, stripe
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.0
Stable Tag: 1.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept donations, manage recurring giving, easy donor management, and grow fundraising with Mission, a free WordPress donation plugin for nonprofits

== Description ==

Mission is a free WordPress donation plugin built for nonprofits. Accept one-time and recurring donations, manage donors and campaigns, give your supporters a self-service dashboard, and track everything from a modern admin, without paying for a single add-on.

Most donation plugins lock the features you actually need behind a paid tier. Recurring donations? Add-on. Custom fields? Add-on. Donor dashboards, fee recovery, exports? Add-on, add-on, add-on. Mission takes a different approach: every feature ships in the free plugin, and we're funded by an optional tip donors can choose to add at checkout. Donors can change the tip, lower it, or set it to zero. 100% of the donation amount always reaches your nonprofit.

The result is a donation platform that grows with your organization without surprise upgrade prompts. The plugin you install today is the plugin handling your donations next year, with no upsell screens between you and your supporters.

=== ✨ Why Mission ===

**No paid add-ons. Ever.**
Recurring donations, donor dashboards, campaign management, custom fields, tribute donations, fee recovery, exports, activity logs. All included in the free plugin. There is no "Mission Pro" version, no premium tier, and no upsell at checkout.

**Modern donation forms that convert.**
Multi-step forms with suggested amounts, custom amounts, tribute dedications, anonymous donations, optional fee recovery. Built to feel quick and trustworthy on every device.

**Built for performance.**
The donation form is built on the WordPress Interactivity API, so there's no React on the public-facing site, no bloated JavaScript, and fast page loads for your donors. Donor data lives in dedicated database tables, not post meta, so reporting stays fast as your donor list grows into the thousands.

=== 💝 Powerful donation forms ===

The Donation Form block can be dropped into any post, page, or campaign. Configure it once and it adapts to your campaign and your brand:

* Suggested amounts and an optional custom-amount field, configured per frequency
* One-time and recurring giving (weekly, monthly, quarterly, annually), with per-form control over which frequencies to offer
* Multi-step layout that keeps the donor focused
* Cover-the-fees option lets donors absorb processing fees so 100% of their donation reaches your cause
* Anonymous donation toggle
* Tribute and memorial dedications, with an optional notification email to the honoree
* Custom fields per form: text, textarea, select, multiselect, radio, checkbox
* Built-in client and server-side validation
* Low-specificity CSS with no `!important` rules so themes can restyle freely

=== 🔁 Recurring donations included free ===

Recurring donations are the single biggest revenue lever for nonprofits, and they're the feature other plugins charge extra for. Mission ships them free:

* Weekly, monthly, quarterly, and annual frequencies
* Automatic renewal with retry on failure
* Donor self-service: pause, resume, or cancel from the donor dashboard
* Renewal history tracked per subscription
* Email notifications for renewals, cancellations, and failed payments
* Admin controls to pause, resume, cancel, or retry any subscription

=== 👥 A donor dashboard your supporters will actually use ===

Drop the Donor Dashboard block on any page and your supporters get a self-service portal. They can:

* See a complete donation history
* Manage recurring donations: pause, resume, or cancel without contacting you
* Download a receipt for any donation
* Update their profile and email address
* Sign in with a magic-link email, so there's no password to forget

Every action your donors can take in the dashboard is one less email in your inbox.

=== 🎯 Campaign management ===

Run a single ongoing campaign or dozens of named campaigns side by side:

* Set goals by total raised, donation count, or unique donor count
* Auto-generated campaign pages built on the WordPress block editor
* Start and end dates per campaign
* Active, draft, and archived statuses
* Real-time aggregates that update as donations complete

Mission ships eleven campaign and donation blocks: donation form, donate button, campaign card, campaign grid, campaign image, campaign progress bar, campaign statistic, donor wall, recent donors, top donors, and donor dashboard. Mix and match them to build campaign pages that match your brand. Not using the block editor? Every block has a shortcode equivalent that works in page builders like Elementor and Bricks.

=== 📊 Reports and exports you can actually use ===

The admin dashboard surfaces what fundraisers care about: total revenue, donation count, average donation, repeat donor count, top donor, and month-over-month growth, in test and live modes side by side.

Need the data outside WordPress? Export donors, transactions, campaigns, and subscriptions to CSV or JSON in a single click.

A built-in activity log records every donation, refund, subscription event, webhook, email, and admin action with a 90-day retention window so you can audit anything that happened on your site.

=== 🧑‍🤝‍🧑 Who Mission is for ===

Mission is built to fit any organization or individual raising money online:

* Nonprofits and charities
* Foundations, clubs, and NGOs
* Churches and faith communities
* Schools, PTAs, and education nonprofits
* Political campaigns and advocacy groups
* Community groups and mutual aid funds
* Individuals raising for a specific cause

=== 🫙 How the optional tip model works ===

Mission is funded by donor tips, not by selling features. Here's exactly how that works:

* At checkout, donors see a small optional tip alongside their donation amount.
* The tip is preselected at a small percentage by default. Donors can change it, lower it, or set it to zero.
* 100% of the donation amount goes to your connected Stripe account.
* Prefer a flat platform fee instead of donor tips? You can switch to a fixed 3% platform fee per form.

== Source Code ==

The full, unminified source code for this plugin is publicly available on GitHub at https://github.com/mission-wp/mission

See the README in the repository for full development setup, contribution guidelines, and a description of the build pipeline.

== Installation ==

1. Upload the `mission-donation-platform` folder to `/wp-content/plugins/`, or install Mission directly from the WordPress.org plugin directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the new **Mission** menu and click **Connect Stripe** to link your Stripe account. Onboarding takes about two minutes through Stripe Connect.
4. Create your first campaign under **Mission → Campaigns**, set a goal, and customize the donation form.
5. Add the Donation Form block (or any of Mission's eleven blocks) to a page, and you are ready to accept donations.

== Frequently Asked Questions ==

= Is Mission really free? =

Yes. Every feature ships in the free plugin. There is no Pro tier, no premium add-ons, and no plan to introduce one. Mission is funded by optional tips that donors can choose to add at checkout. They can change the tip, lower it, or set it to zero, and 100% of the donation amount always reaches your nonprofit.

= Will I ever be asked to pay for a feature? =

No. There is no upgrade prompt, no upsell screen, and no premium version. The plugin you install today includes every feature Mission ships, and that is not going to change.

= Which payment gateways are supported? =

Mission currently supports Stripe via Stripe Connect for credit and debit card donations. We focused on building a deep Stripe integration before adding more gateways.

= Does Mission support recurring donations? =

Yes. Recurring donations are included free, with no add-on required. Donors can choose weekly, monthly, quarterly, or annual frequencies (configurable per form). Renewals are processed automatically via Stripe with retry on failure, and donors can pause, resume, or cancel their own subscriptions from the donor dashboard.

= Do donors need an account to donate? =

No. The donation form is fully public and requires no account. If a donor wants to manage their recurring donations or download receipts later, they can sign in to the donor dashboard with a magic link sent to the email address they used when donating.

= Can I customize the donation form? =

Yes. Every form has its own settings: amounts, frequencies, fields, fee handling, color, anonymous donation toggle, tribute support, and custom fields (text, textarea, select, radio, checkbox). Layout, spacing, and typography use WordPress's native block controls, and themes can restyle the form freely thanks to low-specificity CSS, `--mission-*` custom properties, no `!important` declarations, and no inline styles.

= Can I use Mission with Elementor, Bricks, or the Classic Editor? =

Yes. Every Mission block has a shortcode equivalent that works anywhere shortcodes do, including page builders like Elementor, Bricks, and Divi. The available shortcodes are `[mission_donation_form]`, `[mission_donate_button]`, `[mission_campaign]`, `[mission_campaign_grid]`, `[mission_campaign_image]`, `[mission_campaign_progress]`, `[mission_campaign_statistics]`, `[mission_donor_wall]`, `[mission_recent_donors]`, `[mission_top_donors]`, and `[mission_donor_dashboard]`. Attributes mirror the block settings in snake_case, for example: `[mission_donation_form campaign_id="12" amounts="10,25,50" default_amount="25"]`.

= How is Mission different from GiveWP or Charitable? =

The headline difference is the business model. GiveWP and Charitable both run on a freemium model where the most useful features (recurring donations, fee recovery, custom fields, advanced reports, peer-to-peer fundraising) are paid add-ons that stack into a meaningful yearly cost. Mission flips this: every feature is free, and the platform is funded by optional donor tips instead.

If you want a single open-source plugin without surprise costs, and Stripe handles your payments, Mission is the simplest path to launch.

= Where can I get support? =

Free community support lives on the [WordPress.org support forum](https://wordpress.org/support/plugin/mission-donation-platform/) and on [GitHub issues](https://github.com/mission-wp/mission/issues). Feature requests, bug reports, and questions are all welcome there.

= How do I report a security issue? =

Please report security issues privately by emailing hello@missionwp.com rather than opening a public GitHub issue. We will respond promptly and credit you in the changelog if you would like.

== Screenshots ==

1. The Mission admin dashboard with revenue, donation, donor, and campaign metrics in test and live modes.
2. The donation form with suggested amounts, frequency picker, and tribute support.
3. The donor-facing dashboard for viewing history, managing recurring donations, and downloading receipts.
4. The transaction detail screen with payment info, donor details, and quick actions like refund, resend receipt, and PDF export.
5. The donor profile in the admin with donation history, recurring subscriptions, and contact details.
6. The campaign detail screen in the admin with stats, progress bar, goal info, and campaign image.
7. Public-facing campaign page built from blocks for progress tracking, donor wall, and an inline donation form.

== External Services ==

This plugin connects to the following third-party services:

**Stripe** processes donations. Payment data is sent from the donor's browser to Stripe via Stripe.js, and Stripe sends webhook notifications back for payment, refund, and subscription events. See Stripe's [Terms](https://stripe.com/legal) and [Privacy Policy](https://stripe.com/privacy).

**Mission API** proxies Stripe Connect onboarding, payment requests, and webhook forwarding to your site. If you opt in to feature notifications under Tools > Features, your email is also sent here. See Mission's [Terms](https://missionwp.com/terms) and [Privacy Policy](https://missionwp.com/privacy).

**Gravatar** supplies donor avatars in the admin and on the Donor Wall block. Email addresses are hashed before being sent. See Gravatar's [Terms](https://wordpress.com/tos/) and [Privacy Policy](https://automattic.com/privacy/).

== Changelog ==

= 1.3.1 =
* Enhancement: Faster dashboard and reports on sites with many donations, thanks to new compound database indexes on the transactions and subscriptions tables
* Enhancement: New developer action hooks fire for completed imports, exports, and cleanup operations, and clearing the activity log now leaves an audit entry recording when it was cleared and how many entries were removed
* Enhancement: Subscription cancel, pause, resume, and update errors now report the real cause
* Fix: Campaigns that end with more than 50 active recurring donations no longer skip cancelling (or redirecting) some of them
* Fix: The donor wall now renders donor cards instead of a single empty card and raw markup
* Fix: Improved PHP 8.0 compatibility by aligning all plugin code and bundled dependencies with Mission's minimum supported PHP version
* Fix: Weekly recurring donations now show as recurring instead of one-time in admin donation lists, and weekly is accepted by the REST API frequency filter
* Tweak: Refactored the data importer into focused components and added model-level save_silent() and recompute_aggregates() methods for developers, so all data access flows through the model layer
* Tweak: REST API not-found errors now use consistent entity-specific error codes (e.g. campaign_not_found instead of the generic rest_not_found)
* Tweak: The subscriptions and donor wall list endpoints now validate page and per_page ranges like all other endpoints, rejecting out-of-range values with a 400 error instead of silently clamping them

= 1.3.0 =
* New: One-click migration from GiveWP, bringing over donors, donations, campaigns, and subscriptions with a pre-flight scan, live progress, and a full undo (Tools > Migration)
* New: Outgoing webhooks. Notify external services when donations, subscriptions, donors, or campaigns change, with signed payloads, automatic retries, and a delivery log (Tools > Webhooks)
* New: Choose the URL slug used for campaign pages (Settings > General). Fresh installs automatically avoid clashing with an existing "campaigns" page
* Enhancement: The donation form shortcode now inherits the campaign's configured form settings when campaign_id is set
* Fix: Mission now works on SQLite-based WordPress installs (WordPress Playground, WP Studio) by removing MySQL-only SQL from campaign, subscription, reporting, cleanup, and activity log queries
* Fix: The "delete test data" tools now also remove the meta rows belonging to deleted test transactions, donors, and subscriptions (the cleanup previously referenced the wrong meta column and silently skipped them)
* Tweak: Renamed all developer hooks (actions and filters) from missiondp_ to mission_. If you have custom code hooking into Mission, update the hook names. Scheduled task hooks, options, and database tables are unchanged.
* Tweak: REST API parameters that declare allowed values or numeric ranges now reject invalid input with a clear 400 error instead of silently accepting it

= 1.2.0 =
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

= 1.1.7 =
* New: Connect multiple Stripe accounts, mark one as the default, and choose per donation form which account receives donations
* New: Added an "Edit Campaign" link to the admin bar when viewing a campaign on the frontend
* Enhancement: Tools > Status now lists every connected Stripe account instead of only the default one

= 1.1.6 =
* Enhancement: Expanded the country dropdown to the full ISO 3166-1 list so any country is selectable
* Fix: Fixed the plugin update entry in the activity log to show the correct new version

The full changelog for earlier releases is available [on GitHub](https://github.com/mission-wp/mission/blob/main/CHANGELOG.md).
