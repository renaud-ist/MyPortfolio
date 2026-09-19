# YRD Portfolio

A personal portfolio for Yangda Renaud Dimanche, a Master's graduate in Information Systems and Technology. The site presents academic work, technical interests, systems thinking, and a practical way to make contact.

## Current production architecture

This project is currently deployed as a static frontend hosted on GitHub Pages, with the application logic and persistent data handled by a Cloudflare Worker and Cloudflare D1.

The verified runtime path is:

```text
GitHub Pages frontend
        |
        v
Cloudflare Worker API
        |
        v
Cloudflare D1 database
```

This repository contains the frontend source. Backend API and database behavior live in the separate backend workspace and Worker configuration that are currently used for production.

## What the site contains

- A responsive portfolio interface built with HTML and CSS
- Progressive JavaScript interactions and reduced-motion support
- CV-based education, projects, service experience, technologies, languages, and interests
- Project category filtering
- Downloadable CV
- Social and professional profile links
- Contact form and admin inbox flows driven by the external Worker API
- Authenticated dashboard behavior using Worker-managed session tokens and D1-backed records
- Web app metadata, icons, crawler rules, and static hosting support

## Main project files

```text
index.html                 Public portfolio page
assets/css/style.css       Visual design and responsive layout
assets/js/script.js        Frontend behavior and API calls
site.webmanifest           Web app metadata
robots.txt                 Crawler rules
resume/                    Downloadable CV and supporting files
```

## Local frontend workflow

The site can be opened as a static site locally, but it depends on the live Worker API for contact, auth, inbox, and reply operations.

To preview locally:

```powershell
python -m http.server 8000
```

Then open:

```text
http://127.0.0.1:8000/
```

The frontend is designed to call the remote Worker endpoints; it is not a standalone PHP application.

## Production deployment model

The current deployment model is:

- GitHub Pages hosts the public portfolio frontend
- Cloudflare Worker handles `/api/*` routes and business logic
- Cloudflare D1 stores contact, admin, conversation, and notification records
- Brevo is used for optional outbound admin reply email delivery

The frontend should not be described as a PHP/MySQL application in production documentation.

## Security notes

- Keep secrets only in the Cloudflare Worker environment or secure provider configuration
- Do not expose API keys in frontend JavaScript or committed source files
- Keep any public-facing CORS config limited to trusted origins
- Treat admin credentials and provider keys as sensitive data

## Documentation status

This repository is a static frontend source for the verified GitHub Pages deployment. Backend runtime configuration and database access are handled by the dedicated Cloudflare Worker and D1 project, not by PHP or MySQL in the current production architecture.

### Notifications

Contact records are persisted in MySQL first. The current optional notification hook uses PHP `mail()` when `CONTACT_NOTIFICATION_EMAIL` is configured; SMTP variables are not consumed by the current application. Reliable SMTP delivery is therefore **not verified** and requires a mail library and provider configuration before production notification guarantees can be made.

### Verification checklist

After deployment, verify:

```text
GET /index.php
GET /assets/php/health.php
GET /assets/php/csrf.php
GET /assets/php/admin.php
POST /assets/php/contact.php
```

Confirm the homepage loads over HTTPS, the health endpoint reports connected tables, the contact form creates a MySQL row, the admin login and message controls work, invalid CSRF requests are rejected, and server errors are logged without being displayed to visitors.

The local smoke command remains:

```powershell
php tests/smoke.php https://your-deployed-host.example
```

### Rollback

Keep the previous application release available, deploy new code to a versioned directory, and switch the web-server document root only after health checks pass. Before schema changes, take a database backup. Roll back application code first if the schema remains compatible; restore the database backup only when a schema change itself must be reversed.
