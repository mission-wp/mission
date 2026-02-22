# Mission - Donation Forms & Fundraising

The free donation plugin for nonprofits. Powerful features, modern forms, no add-ons required.

## Requirements

- PHP 8.0+
- WordPress 6.5+
- Node.js 18+
- Docker (for local development)
- Composer

## Getting Started

### 1. Install dependencies

```bash
composer install
npm install
```

### 2. Build blocks

```bash
npm run build
```

For development with hot reloading:

```bash
npm run start
```

### 3. Start the local environment

```bash
npm run env:start
```

This spins up a WordPress site at http://localhost:8888 with the Mission plugin installed.

- **WordPress admin:** http://localhost:8888/wp-admin
- **Username:** `admin`
- **Password:** `password`

### 4. Activate the plugin

```bash
npm run env:cli -- wp plugin activate mission
```

## Development Commands

| Command | Description |
|---------|-------------|
| `npm run build` | Build blocks for production |
| `npm run start` | Build blocks with hot reloading |
| `npm run env:start` | Start wp-env |
| `npm run env:stop` | Stop wp-env |
| `npm run env:destroy` | Destroy wp-env (removes data) |
| `npm run lint:js` | Lint JavaScript |
| `npm run lint:css` | Lint CSS/SCSS |
| `npm run test:php` | Run PHP unit tests (requires wp-env) |
| `npm run test:unit:js` | Run JS unit tests |
| `npm run test:e2e` | Run Playwright E2E tests |