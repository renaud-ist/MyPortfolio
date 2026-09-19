# MyPortfolio backend API

This repository is the production backend for the MyPortfolio frontend. The currently verified architecture is:

- Frontend: GitHub Pages
- API: Cloudflare Worker
- Database: Cloudflare D1
- Email delivery: Brevo via Worker secret configuration

The Worker is the authoritative backend. The older PHP/MySQL implementation is legacy or local-only context and is not the current production architecture.

## Current production architecture

```text
GitHub Pages frontend
        |
        v
Cloudflare Worker API
        |
        v
Cloudflare D1 database
```

The Worker source is in `cloudflare/src/index.js`, and the runtime configuration lives in `wrangler.toml`.

## Repository structure

```text
cloudflare/src/index.js     Worker implementation
cloudflare/migrations/      D1 migration files
wrangler.toml               Worker and D1 configuration
README.md                   Project overview
DEPLOYMENT.md               Deployment guidance for current environment
```

## Runtime responsibilities

The Worker handles:

- contact submissions
- admin authentication
- conversation retrieval and mutation
- admin replies
- notification queries
- optional email delivery through Brevo

The Worker reads the D1 binding from `wrangler.toml` and does not rely on PHP or MySQL for the verified production path.

## Environment values

The production environment contains sensitive values such as:

- `BREVO_API_KEY`
- any admin credential material in the deployed environment

These must remain in secure Cloudflare environment storage and must not be committed to source control or documentation.

## Local development

Use Wrangler to run or test the Worker locally when needed:

```bash
npx wrangler dev
```

If a local preview is required, keep it separate from the production environment and do not apply D1 migrations or secrets into the live database without explicit approval.

## Verified API routes

The current Worker provides these routes:

```text
POST   /api/contact
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me
GET    /api/conversations
GET    /api/conversation?id=<id>
PATCH  /api/conversation?id=<id>
DELETE /api/conversation?id=<id>
POST   /api/conversation/reply?id=<id>
GET    /api/notifications
DELETE /api/notifications?id=<id>
```

These are the routes the GitHub Pages frontend consumes.

## Security and deployment guidance

- Do not store secrets in source files or migration files
- Do not modify production D1 state without explicit authorization
- Keep admin credentials and provider keys in secure Worker environment configuration
- Use the Worker and D1 only as the authoritative production backend
- Treat older PHP or MySQL instructions as historical context only

## Notes

The legacy PHP-driven documentation and MySQL setup files are not the current production design. The authoritative deployment documentation for this backend is the Cloudflare Worker + D1 model described in this README and the project deployment guide.

The portfolio describes verified academic and service experience. Add repositories, screenshots, live demos, employment history, or certifications only when their details can be verified.
