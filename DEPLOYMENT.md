# Deployment Guide

This repository is a PHP + MySQL portfolio application. It is not a GitHub Pages-only project and it is not a static-site deployment.

## Deployment requirements

| Requirement | Current state | Production requirement |
| --- | --- | --- |
| PHP | Verified locally | PHP version required by the project; production host must support PHP and the required extensions |
| MySQL | Verified locally | Compatible production MySQL instance |
| PDO MySQL | Verified locally | Required for database access |
| Web server | Local PHP server tested | Apache, Nginx, or another PHP-capable host |
| HTTPS | Local only | Required for production |
| Database user | Local development credentials only | Restricted production database user |
| SMTP | Not implemented or not verified in the current code path | Required only if email notification is enabled |
| Environment variables | Local `.env` file | Secure production environment variables or protected `.env` |
| Domain | Not configured | Optional initially, but required for a public production URL |
| Backups | Not configured | Required before production use |

## GitHub Pages limitation

The repository includes a PHP backend and MySQL-backed contact logic. GitHub Pages cannot execute PHP code or access a MySQL database. The recommended architecture is therefore:

Browser
↓
PHP-capable hosting
↓
PHP application
↓
PDO
↓
MySQL

GitHub Pages may host a separate static frontend only if the frontend is designed to call an externally hosted PHP API. That is not the current application architecture and it is not the recommended deployment shape for this repository.

## Step 1 — Hosting

The production host must support:

- PHP
- PDO MySQL
- MySQL
- HTTPS
- environment variables or a protected `.env`
- outbound SMTP if email notification is enabled

The app does not require a custom framework or a new backend. It should run on any PHP-capable hosting environment that supports MySQL and HTTPS.

## Step 2 — Database

The administrator should:

1. Create the production database.
2. Create a restricted application database user.
3. Grant only the permissions required by the application.
4. Import `setup.sql`.
5. Verify that the expected tables exist.

A typical pattern is:

```sql
CREATE DATABASE portfolio_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portfolio_app'@'localhost' IDENTIFIED BY 'replace-with-a-secret';
GRANT SELECT, INSERT, UPDATE, DELETE ON portfolio_db.* TO 'portfolio_app'@'localhost';
FLUSH PRIVILEGES;
```

Do not commit real passwords. The repository must not store production credentials in source control.

## Step 3 — Application

1. Upload or clone the repository to the target PHP host.
2. Configure `.env` from `.env.example` using only non-production values until the host is ready.
3. Ensure the web document root points to the application directory or an equivalent public directory.
4. Enable the required PHP extensions: PDO, PDO MySQL, JSON, Filter, OpenSSL, and Session.
5. Configure the web server to route PHP files correctly.
6. Enable HTTPS and redirect HTTP traffic to HTTPS.
7. Ensure sensitive files remain inaccessible via the web server.

The current application expects:

- `APP_ENV`
- `APP_DEBUG`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `CONTACT_RATE_LIMIT_SECONDS`
- `CONTACT_NOTIFICATION_EMAIL`
- `CONTACT_ALLOW_FILE_FALLBACK`
- `ADMIN_USER`
- `ADMIN_PASS`

## Step 4 — Verification

The actual project routes and checks are:

- `GET /index.php` — homepage
- `GET /assets/php/health.php` — database readiness
- `GET /assets/php/csrf.php` — CSRF token generation
- `GET /assets/php/admin.php` — admin login page
- `POST /assets/php/contact.php` — contact form
- `GET /site.webmanifest` — manifest
- `GET /robots.txt` — crawler rules

The local smoke test is:

```powershell
php tests/smoke.php http://127.0.0.1:8080
```

The application should be checked for:

- homepage availability
- health endpoint status
- contact form validation and CSRF rejection
- database persistence when MySQL is available
- admin authentication and authorization
- HTTPS behavior
- secure error handling without stack traces to visitors

## Step 5 — Rollback

1. Keep the previous application version available.
2. Deploy the new version to a separate directory or release path.
3. Run health checks before switching the document root.
4. If the schema remains compatible, roll back application code first.
5. If a schema change must be reversed, restore the database backup.
6. Re-enable the previous document root only after verification succeeds.

## Security configuration summary

The repository includes the following deployment-aware protections:

- `.env` is ignored by the project configuration
- `.htaccess` denies access to sensitive files
- PHP error display is disabled in production mode
- `APP_DEBUG=false` is the expected production setting
- secure cookies are used when HTTPS is present
- CORS is not configured permissively for this app
- credentials are not hard-coded in source files
- the application recommends a dedicated database user rather than root
- stack traces are not returned to visitors

## SMTP and notification status

The contact processing code includes an optional email notification path using PHP `mail()` when `CONTACT_NOTIFICATION_EMAIL` is set. This is not a verified production SMTP setup. It requires a working mail configuration and provider support. The current repository does not include a verified production SMTP configuration.

## Fresh database initialization

The current `setup.sql` file is suitable for creating the required tables in a fresh database. However, fresh production database initialization was not verified against an isolated disposable database in this environment. This requires a real isolated production or disposable environment before it can be confirmed.

## Local validation status

The project has been validated locally with the existing smoke test suite and PHP syntax checks.

- PHP syntax: passed
- database connection: passed locally
- schema verification: passed locally
- smoke tests: passed locally
- contact validation: passed locally
- CSRF: passed locally
- health endpoint: passed locally
- admin route: passed locally
- manifest and robots: passed locally

## Final deployment readiness

This repository is prepared for real deployment as a PHP + MySQL application once a PHP-capable production host, database, and environment configuration are available. It is not ready to claim a public deployment without those external production details.
