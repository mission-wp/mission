=== Mission Donation Platform ===
Contributors: missionwp
Tags: donations, donate, fundraising, nonprofit, recurring donations
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.0
Stable Tag: 1.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free, modern donation plugin for nonprofits. Every feature included, no paid add-ons, ever — funded by optional donor tips.

== Description ==

Mission is a free, open-source WordPress donation plugin built for nonprofits. Accept one-time and recurring donations, manage donors and campaigns, give your supporters a self-service dashboard, and track everything from a modern admin — without paying for a single add-on.

Most donation plugins lock the features you actually need behind a paid tier. Recurring donations? Add-on. Custom fields? Add-on. Donor dashboards, fee recovery, exports? Add-on, add-on, add-on. Mission takes a different approach: every feature ships in the free plugin, and we're funded by an optional tip donors can choose to add at checkout. Donors can change the tip, lower it, or set it to zero. 100% of the donation amount always reaches your nonprofit.

The result is a donation platform that grows with your organization without surprise upgrade prompts. The plugin you install today is the plugin handling your donations next year, with no upsell screens between you and your supporters.

= Why Mission =

**No paid add-ons. Ever.**
Recurring donations, donor dashboards, campaign management, custom fields, tribute donations, fee recovery, exports, activity logs — all included in the free plugin. There is no "Mission Pro" version, no premium tier, and no upsell at checkout.

**Modern donation forms that convert.**
Multi-step forms with suggested amounts, custom amounts, tribute dedications, anonymous donations, optional fee recovery. Built to feel quick and trustworthy on every device.

**Built for performance.**
The donation form is built on the WordPress Interactivity API — no React on the public-facing site, no bloated JavaScript, fast page loads for your donors. Donor data lives in dedicated database tables, not post meta, so reporting stays fast as your donor list grows into the thousands.

= Powerful donation forms =

The Donation Form block can be dropped into any post, page, or campaign. Configure it once and it adapts to your campaign and your brand:

* Suggested amounts and an optional custom-amount field, configured per frequency
* One-time and recurring giving (weekly, monthly, quarterly, annually) — choose which frequencies to offer per form
* Multi-step layout that keeps the donor focused
* Cover-the-fees option — let donors absorb processing fees so 100% of their donation reaches your cause
* Anonymous donation toggle
* Tribute and memorial dedications, with an optional notification email to the honoree
* Custom fields per form — text, textarea, select, multiselect, radio, checkbox
* Built-in client and server-side validation
* Low-specificity CSS with no `!important` rules so themes can restyle freely

= Recurring donations included free =

Recurring donations are the single biggest revenue lever for nonprofits, and they're the feature most plugins charge extra for. Mission ships them free:

* Weekly, monthly, quarterly, and annual frequencies
* Automatic renewal handled by Stripe with retry on failure
* Donor self-service — pause, resume, or cancel from the donor dashboard
* Renewal history tracked per subscription
* Email notifications for renewals, cancellations, and failed payments
* Admin controls to pause, resume, cancel, or retry any subscription

= A donor dashboard your supporters will actually use =

Drop the Donor Dashboard block on any page and your supporters get a self-service portal. They can:

* See a complete donation history
* Manage recurring donations — pause, resume, or cancel without contacting you
* Download a receipt for any donation
* Update their profile and email address
* Sign in with a magic-link email — no password to forget

Every action your donors can take in the dashboard is one less email in your inbox.

= Campaign management =

Run a single ongoing campaign or dozens of named campaigns side by side:

* Set goals by total raised, donation count, or unique donor count
* Auto-generated campaign pages built on the WordPress block editor
* Start and end dates per campaign
* Active, draft, and archived statuses
* Real-time aggregates that update as donations complete

Mission ships eleven campaign and donation blocks: donation form, donate button, campaign card, campaign grid, campaign image, campaign progress bar, campaign statistic, donor wall, recent donors, top donors, and donor dashboard. Mix and match them to build campaign pages that match your brand.

= Reports and exports you can actually use =

The admin dashboard surfaces what fundraisers care about: total revenue, donation count, average donation, repeat donor count, top donor, and month-over-month growth — in test and live modes side by side.

Need the data outside WordPress? Export donors, transactions, campaigns, and subscriptions to CSV or JSON in a single click.

A built-in activity log records every donation, refund, subscription event, webhook, email, and admin action with a 90-day retention window so you can audit anything that happened on your site.

= Stripe payment processing, done right =

Mission processes donations through Stripe Connect with direct charges:

* Stripe Payment Element for credit and debit card donations
* Test and live modes side by side with isolated data
* Webhook-driven — donation status, refunds, and subscription renewals all sync automatically
* Per-form fee handling — organization absorbs, optional cover-the-fees, or required cover-the-fees
* Mission absorbs the incremental Stripe fee caused by tips, so adding a tip never costs your nonprofit more in processing fees

Stripe is currently the only supported gateway. We chose to build a single deep gateway integration before adding more.

= Who Mission is for =

Mission is built to fit any organization or individual raising money online:

* Nonprofits and charities
* Foundations, clubs, and NGOs
* Churches and faith communities
* Schools, PTAs, and education nonprofits
* Political campaigns and advocacy groups
* Community groups and mutual aid funds
* Individuals raising for a specific cause

= How the optional tip model works =

Mission is funded by donor tips, not by selling features. Here's exactly how that works:

* At checkout, donors see a small optional tip alongside their donation amount.
* The tip is preselected at a small percentage by default. Donors can change it, lower it, or set it to zero.
* Stripe processes the donation. Mission collects only the tip portion; 100% of the donation amount goes to your connected Stripe account.
* Prefer a flat platform fee instead of donor tips? You can switch to a fixed 3% platform fee per form.

= Designed for developers =

Mission is open source and built to be extended. Eighty-plus actions and filters let you hook into every major event, customize every output, and integrate with the rest of your stack. A few examples:

* `missiondp_donation_form_settings` — customize per-form configuration
* `missiondp_transaction_status_{from}_to_{to}` — react to donation status changes
* `missiondp_email_template_{type}` — customize email templates
* `missiondp_receipt_html` — customize receipt PDF output
* `missiondp_settings_updated` — react to setting changes

A REST API exposes every entity for headless integrations, and a model-based data layer (`Donor::find()`, `Campaign::query()`, `Transaction::create()`) makes integrations clean to write and easy to maintain.

The full source — including all build sources — lives at [github.com/mission-wp/mission](https://github.com/mission-wp/mission).

== Installation ==

1. Upload the `mission-donation-platform` folder to `/wp-content/plugins/`, or install Mission directly from the WordPress.org plugin directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the new **Mission** menu and click **Connect Stripe** to link your Stripe account. Onboarding takes about two minutes through Stripe Connect.
4. Create your first campaign under **Mission → Campaigns**, set a goal, and customize the donation form.
5. Add the Donation Form block (or any of Mission's eleven blocks) to a page, and you are ready to accept donations.

== Frequently Asked Questions ==

= Is Mission really free? =

Yes. Every feature ships in the free plugin — there is no Pro tier, no premium add-ons, and no plan to introduce one. Mission is funded by optional tips that donors can choose to add at checkout. They can change the tip, lower it, or set it to zero, and 100% of the donation amount always reaches your nonprofit.

= Will I ever be asked to pay for a feature? =

No. There is no upgrade prompt, no upsell screen, and no premium version. The plugin you install today includes every feature Mission ships, and that is not going to change.

= Which payment gateways are supported? =

Mission currently supports Stripe via Stripe Connect for credit and debit card donations. We focused on building a deep Stripe integration before adding more gateways.

= Does Mission support recurring donations? =

Yes — included free, with no add-on required. Donors can choose weekly, monthly, quarterly, or annual frequencies (configurable per form). Renewals are processed automatically via Stripe with retry on failure, and donors can pause, resume, or cancel their own subscriptions from the donor dashboard.

= Do donors need an account to donate? =

No. The donation form is fully public and requires no account. If a donor wants to manage their recurring donations or download receipts later, they can sign in to the donor dashboard with a magic link sent to the email address they used when donating.

= Can I customize the donation form? =

Yes. Every form has its own settings: amounts, frequencies, fields, fee handling, color, anonymous donation toggle, tribute support, and custom fields (text, textarea, select, radio, checkbox). Layout, spacing, and typography use WordPress's native block controls, and themes can restyle the form freely thanks to low-specificity CSS, `--mission-*` custom properties, no `!important` declarations, and no inline styles.

= How is Mission different from GiveWP or Charitable? =

The headline difference is the business model. GiveWP and Charitable both run on a freemium model where the most useful features — recurring donations, fee recovery, custom fields, advanced reports, peer-to-peer fundraising — are paid add-ons that stack into a meaningful yearly cost. Mission flips this: every feature is free, and the platform is funded by optional donor tips instead.

If you want a single open-source plugin without surprise costs, and Stripe handles your payments, Mission is the simplest path to launch.

= Where can I get support? =

Free community support lives on the [WordPress.org support forum](https://wordpress.org/support/plugin/mission-donation-platform/) and on [GitHub issues](https://github.com/mission-wp/mission/issues). Feature requests, bug reports, and questions are all welcome there.

= How do I report a security issue? =

Please report security issues privately by emailing hello@missionwp.com rather than opening a public GitHub issue. We will respond promptly and credit you in the changelog if you would like.

== Screenshots ==

1. The Mission admin dashboard with revenue, donation, donor, and campaign metrics in test and live modes.
2. The donation form with suggested amounts, frequency picker, and tribute support.
3. The donor-facing dashboard for viewing history, managing recurring donations, and downloading receipts.

== External Services ==

This plugin connects to the following third-party services:

= Stripe =

Mission uses [Stripe](https://stripe.com) to process donations. When a donor submits a donation form, payment data is sent directly from the donor's browser to Stripe's servers via Stripe.js. The plugin also receives webhook notifications from Stripe for payment confirmations, refunds, and subscription updates.

* [Stripe Terms of Service](https://stripe.com/legal)
* [Stripe Privacy Policy](https://stripe.com/privacy)

= Mission API =

Mission connects to [api.missionwp.com](https://api.missionwp.com) (operated by the plugin author) for the following:

* **Stripe Connect onboarding** — When you connect your Stripe account, the OAuth flow is handled through the Mission API as a proxy.
* **Payment processing** — Donation and subscription requests are routed through the Mission API to your connected Stripe account.
* **Webhook forwarding** — Stripe webhook events are forwarded from the Mission API to your WordPress site.
* **Feature signup** — If you opt in to notifications about upcoming features (under Tools > Features), your email is sent to the Mission API.

* [Mission Terms of Service](https://missionwp.com/terms)
* [Mission Privacy Policy](https://missionwp.com/privacy)

= Gravatar =

Mission uses [Gravatar](https://gravatar.com) to display profile images for donors in the admin dashboard (Donors and Transactions screens) and on the public-facing Donor Wall block. When one of these views is rendered, the visitor's browser requests an avatar image from `https://www.gravatar.com/avatar/{hash}` where `{hash}` is an MD5 hash of the donor's email address. Donor email addresses themselves are never sent to Gravatar — only the hash. If a donor has no Gravatar account, a blank placeholder is returned. No request is made if a donor record has no email on file.

* [Gravatar Terms of Service](https://wordpress.com/tos/)
* [Gravatar Privacy Policy](https://automattic.com/privacy/)

== Source Code ==

The full, unminified source code for this plugin is publicly available on GitHub at https://github.com/mission-wp/mission

The repository contains the original `.js`, `.jsx`, and `.scss` files. To build the plugin from source:

`composer install`
`npm install`
`npm run build`

See the README in the repository for full development setup, contribution guidelines, and a description of the build pipeline.

== Changelog ==

= 1.1.2 =
* Updated @wordpress/dataviews to 14.3.0 and @wordpress/icons to 13.1.0
* Fixed onboarding state select rendering taller than adjacent input fields
* Wrapped PHP templates in IIFEs to scope file-local variables
* Converted ReportingService identifier interpolation to %i placeholders
* Silenced Plugin Check false positives in custom-table data layer
* Prefixed $autoloader and silenced core-hook invocation sniff

= 1.1.1 =
* Donor login now goes through WordPress's standard auth pipeline for better compatibility with security plugins
* Donor user accounts are no longer deleted automatically when the plugin is uninstalled

= 1.1.0 =
* Renamed plugin slug to mission-donation-platform and prefixed all PHP/JS identifiers with missiondp_ per WordPress.org review
* Refactored DataStore queries and Cleanup IN-clauses to use wpdb::prepare() with proper placeholders
* Escaped block render output through wp_kses with an allowlist that preserves SVG icons and Interactivity API directives
* Moved security checks into permission_callback for state-changing public REST endpoints (donation/subscription confirm, email-change confirm)
* Added a Source Code section to the readme linking to the public GitHub repo with build instructions
* Disclosed Gravatar usage under External Services in the readme
* Removed unused stripe/stripe-php dependency
* Added WordPress.org directory assets (banners, icons, screenshots)
* Fixed MISSIONDP_STRIPE_PK_TEST to match the live platform Stripe account

= 1.0.1 =
* Renamed plugin to Mission Donation Platform per WordPress.org review team guidance

= 1.0.0 =
* Initial release
