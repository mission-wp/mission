# Mission

The free WordPress donation plugin for nonprofits. Beautiful forms, recurring donations, campaign management, donor tracking, and more — all in one plugin, no paid add-ons.

**[Download the latest release](https://github.com/mission-wp/mission/releases/latest)**

## Why Mission?

Most WordPress donation plugins make you pay for basic features like recurring donations, custom fields, or donor management. Mission includes everything for free — funded by optional donor tips instead of upsells.

- **Modern donation forms** — built on the WordPress Interactivity API, not legacy shortcodes
- **Recurring donations** — monthly, quarterly, or annual giving with Stripe subscriptions
- **Campaign management** — set goals, track progress, and run time-limited fundraisers
- **Donor portal** — donors can log in, view history, download receipts, and manage recurring gifts
- **Custom fields** — collect additional info without buying an add-on
- **Email receipts** — automatic donation receipts and admin notifications
- **Data export** — export donors, transactions, and subscriptions to CSV or JSON
- **Developer friendly** — 65+ hooks, REST API, custom database tables, and modern PHP

## Installation

1. Download `mission.zip` from the [latest release](https://github.com/mission-wp/mission/releases/latest)
2. In WordPress, go to Plugins > Add New > Upload Plugin
3. Upload the zip file and activate
4. Connect your Stripe account under Mission > Settings

> **Note:** Do not download this repository directly — the plugin needs to be built first. Always use the zip from the [releases page](https://github.com/mission-wp/mission/releases/latest).

## Development

Want to contribute or build from source? You'll need Node.js 18+, PHP 8.0+, Composer, and Docker.

### Setup

```bash
composer install
npm install
npm run build
npm run env:start
```

This starts a WordPress site at `http://localhost:8888` with Mission installed.

- **Admin:** http://localhost:8888/wp-admin
- **Username:** `admin`
- **Password:** `password`

For development with hot reloading:

```bash
npm run start
```

### Commands

| Command | Description |
|---------|-------------|
| `npm run build` | Production build |
| `npm run start` | Development build with watch |
| `npm run env:start` | Start local WordPress environment |
| `npm run env:stop` | Stop local environment |
| `npm run lint:js` | Lint JavaScript |
| `npm run lint:css` | Lint SCSS |
| `npm run lint:php` | Lint PHP |
| `npm run test:php` | Run PHP tests |
| `npm run test:unit:js` | Run JS unit tests |
| `npm run test:e2e` | Run Playwright e2e tests |
| `npm run plugin-zip` | Build distributable zip |

## License

GPL-2.0-or-later
