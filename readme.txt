=== MissionWP Donation Platform ===
Contributors: missionwp
Tags: donations, fundraising, nonprofit, stripe, donation form
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.0
Stable Tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The free donation plugin for nonprofits. Powerful features, modern forms, no add-ons required.

== Description ==

MissionWP Donation Platform is a modern, open-source WordPress donation plugin built for nonprofits. It provides beautiful donation forms, Stripe payment processing, campaign management, donor tracking, and more — all without paid add-ons.

= Features =

* Modern donation forms powered by the WordPress Interactivity API
* Stripe Connect payment processing
* Campaign management with goals and progress tracking
* Donor management and history
* Recurring donations (subscriptions)
* Customizable form fields
* Activity feed for tracking events
* Built with performance and scalability in mind

== Installation ==

1. Upload the `missionwp-donation-platform` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Connect your Stripe account under MissionWP > Settings
4. Create your first campaign and start accepting donations

= Development =

Source code, build instructions, and contribution guidelines are available on [GitHub](https://github.com/mission-wp/mission).

== Frequently Asked Questions ==

= How is MissionWP Donation Platform free? =

MissionWP is funded by optional donor tips, not paid add-ons. All features are included for free.

= What payment gateways are supported? =

MissionWP currently supports Stripe via Stripe Connect with direct charges.

= What are the minimum requirements? =

WordPress 6.7+, PHP 8.0+, and an active Stripe account.

== External Services ==

This plugin connects to the following third-party services:

= Stripe =

MissionWP uses [Stripe](https://stripe.com) to process donations. When a donor submits a donation form, payment data is sent directly from the donor's browser to Stripe's servers via Stripe.js. The plugin also receives webhook notifications from Stripe for payment confirmations, refunds, and subscription updates.

* [Stripe Terms of Service](https://stripe.com/legal)
* [Stripe Privacy Policy](https://stripe.com/privacy)

= MissionWP API =

MissionWP connects to [api.missionwp.com](https://api.missionwp.com) (operated by the plugin author) for the following:

* **Stripe Connect onboarding** — When you connect your Stripe account, the OAuth flow is handled through the MissionWP API as a proxy.
* **Payment processing** — Donation and subscription requests are routed through the MissionWP API to your connected Stripe account.
* **Webhook forwarding** — Stripe webhook events are forwarded from the MissionWP API to your WordPress site.
* **Feature signup** — If you opt in to notifications about upcoming features (under Tools > Features), your email is sent to the MissionWP API.

* [MissionWP Terms of Service](https://missionwp.com/terms)
* [MissionWP Privacy Policy](https://missionwp.com/privacy)

== Changelog ==

= 1.0.1 =
* Renamed plugin to MissionWP Donation Platform per WordPress.org review team guidance

= 1.0.0 =
* Initial release
