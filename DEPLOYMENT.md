# Frontend deployment guide

This repository is the GitHub Pages frontend for the MyPortfolio site. It is not a PHP application and it is not deployed as a standalone backend.

## Verified production model

The live production flow is:

```text
GitHub Pages frontend
        |
        v
Cloudflare Worker API
        |
        v
Cloudflare D1 database
```

The Worker handles the contact, auth, conversation, notification, and reply endpoints. The frontend calls those API routes from the browser and does not connect directly to a database.

## Deployment requirements

- GitHub Pages enabled for the repository
- Cloudflare Worker deployed and reachable from the frontend
- Cloudflare D1 database configured for the Worker
- Valid API base URL configuration in the frontend JavaScript
- HTTPS on the public site and API endpoints
- Secure handling of Worker secrets and provider credentials

## GitHub Pages setup

Upload or publish the repository content to GitHub Pages. This is a static hosting deployment for the public portfolio content.

The deployment is not a PHP deployment. There is no server-side PHP runtime or MySQL database in the public-facing frontend path.

## API configuration

The browser-side code calls the Cloudflare Worker endpoints. The Worker is the authoritative backend and should be considered the public API host for production behavior.

This means:

- the frontend is static and presentation-focused
- the API layer is external and separate from GitHub Pages
- contact submissions, admin authentication, inbox data, and replies are handled by the Worker and D1
- any environment-specific values, including provider secrets, remain in Worker config and secret storage rather than in frontend source

## Operational checks

After deployment, validate that:

1. the GitHub Pages site loads successfully
2. the contact form submits successfully through the Worker API
3. admin login works against the backend auth routes
4. the inbox and notifications render correctly
5. admin replies persist and status is reported accurately
6. CORS and API access rules remain limited to trusted origins

## Security and rollback

- Never publish secret values in the repository
- Keep Worker secrets in Cloudflare secret storage
- Keep the API base URL aligned with the deployed Worker
- Use HTTPS for all public routes
- If a frontend rollback is needed, restore the previous GitHub Pages publish state without altering the API or database configuration

## Important note

Older documentation in this repository describing PHP/MySQL hosting is outdated for the current production architecture. The correct public deployment model is GitHub Pages for the frontend and Cloudflare Worker + D1 for the application backend.
