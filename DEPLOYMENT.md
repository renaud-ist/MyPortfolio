# Cloudflare Worker deployment guide

This repository is the production backend for the MyPortfolio frontend. The verified architecture is:

- Frontend: GitHub Pages at https://renaud-ist.github.io/MyPortfolio/
- API: Cloudflare Worker at https://myportfolio-api-proxy.yangdarenaud893.workers.dev
- Database: Cloudflare D1 database named myportfolio-db
- Worker source: cloudflare/src/index.js
- Worker config: wrangler.toml

This document describes the verified production deployment flow. It intentionally does not expose secret values.

## Architecture summary

The static frontend is served by GitHub Pages. The frontend calls the external Cloudflare Worker API endpoints for contact submission, admin auth, conversations, notifications, and admin reply processing.

The Worker is configured in `wrangler.toml` and reads runtime values from the Cloudflare environment, including the D1 binding and non-secret variables.

Production database access is through D1, not a PHP/MySQL database. The current D1 database name is `myportfolio-db`.

## Prerequisites

- Node.js and npm available locally
- Wrangler installed or available via npx
- Cloudflare account with access to the target Worker and D1 database
- GitHub Pages frontend already published separately
- Brevo API key stored as a Worker secret
- Verified sender email configured as a non-secret Worker variable

## Worker configuration

The current Worker config is defined in `wrangler.toml` and includes:

- Worker name: `myportfolio-api-proxy`
- Entry point: `cloudflare/src/index.js`
- D1 binding: `DB`
- D1 database: `myportfolio-db`
- migration directory: `cloudflare/migrations`

The Worker uses the D1 binding as the source of truth for admin auth, contact data, notifications, and admin replies.

## Secrets and variables

Do not commit secret values. Keep secrets in the Cloudflare environment rather than source files.

Required secret:

- `BREVO_API_KEY` — used by the Worker to send admin replies through Brevo

Configured non-secret variable:

- `REPLY_FROM_EMAIL` — verified sender email used in outbound admin replies

Optional non-secret variable:

- `REPLY_FROM_NAME` — reply display name

Examples:

```bash
npx wrangler secret put BREVO_API_KEY
npx wrangler vars put REPLY_FROM_EMAIL
npx wrangler vars put REPLY_FROM_NAME
```

Do not print or commit the actual secret value.

## D1 database setup and migrations

The production database is a D1 database named `myportfolio-db`.

Create or update the database with Wrangler commands as needed:

```bash
npx wrangler d1 list
npx wrangler d1 create myportfolio-db
npx wrangler d1 migrations apply myportfolio-db --remote
```

Use the project migration directory:

- `cloudflare/migrations`

The project uses D1 migration behavior and stores migration state under D1-managed migration metadata; do not describe production as using `schema_migrations`.

## Deployment

Deploy the Worker with:

```bash
npx wrangler deploy
```

If a local preview is needed without changing production, use the local preview flow provided by Wrangler, but do not deploy the Worker during this task.

## Verified API routes

The production Worker exposes these authenticated or public routes:

- `POST /api/contact`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `GET /api/conversations`
- `GET /api/conversation?id=<id>`
- `PATCH /api/conversation?id=<id>`
- `DELETE /api/conversation?id=<id>`
- `POST /api/conversation/reply?id=<id>`
- `GET /api/notifications`
- `DELETE /api/notifications?id=<id>`

The frontend is the consumer of these routes.

## Smoke testing after deployment

After deployment, verify the live Worker and the frontend together:

1. Confirm the GitHub Pages site loads.
2. Submit a contact message through the public form.
3. Confirm the Worker responds successfully and data is stored in D1.
4. Confirm admin sign-in works against the Worker auth flow.
5. Confirm the admin inbox renders notifications and conversations.
6. Confirm admin replies persist and optional Brevo delivery status is handled without exposing provider details.
7. Confirm the frontend still behaves correctly when the backend is unreachable or returns an error response.

Useful examples:

```bash
curl -i -X POST "https://myportfolio-api-proxy.yangdarenaud893.workers.dev/api/contact" \
  -H "Origin: https://renaud-ist.github.io" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","message":"Smoke test"}'
```

Always treat credentials and secrets as sensitive; do not put them into source files, shell history, or documentation examples.

## Legacy notes

The older PHP/MySQL deployment model is not the current production architecture. It may remain in legacy notes for local compatibility testing only, but the authoritative production setup is the GitHub Pages frontend with the Cloudflare Worker and D1 database.
