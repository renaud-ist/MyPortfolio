# YRD Portfolio

A personal portfolio for Yangda Renaud Dimanche, a Master's graduate in Information Systems and Technology. The site presents academic work, technical interests, systems thinking, and a practical way to make contact.

## What the site contains

- A responsive portfolio interface built with HTML and CSS
- Progressive JavaScript interactions and reduced-motion support
- CV-based education, projects, service experience, technologies, languages, and interests
- Project category filtering
- Downloadable CV
- Social and professional profile links
- A PHP contact API backed by MySQL
- CSRF protection, input validation, honeypot protection, and rate limiting
- A protected administrator dashboard for managing contact messages
- Health checks, CSV export, archive controls, and smoke tests
- Web app metadata, icons, crawler rules, and Apache hardening

## Main files

```text
index.html                 Public portfolio page
index.php                  PHP entry point for the portfolio
assets/css/style.css       Visual design and responsive layout
assets/js/script.js        Interface behavior and contact requests
assets/php/db.php          PDO database bootstrap
assets/php/contact.php     Contact form API
assets/php/csrf.php        Session token endpoint
assets/php/health.php      Database health endpoint
assets/php/admin.php       Protected message dashboard
setup.sql                  MySQL schema
resume/CV.docx             Downloadable CV
tests/smoke.php            Live endpoint checks
```

## Local configuration

Copy `.env.example` to `.env` and set the real local values. Keep `.env` private.

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=portfolio_db
DB_USER=root
DB_PASS=your-mysql-password
CONTACT_RATE_LIMIT_SECONDS=60
CONTACT_ALLOWED_ORIGINS=https://renaud-ist.github.io,http://localhost:8080,http://127.0.0.1:8080
API_AUTH_TOKEN_TTL_SECONDS=1800
API_AUTH_LOGIN_RATE_LIMIT_SECONDS=10
REPLY_EMAIL_ENABLED=false
REPLY_FROM_EMAIL=
CONTACT_NOTIFICATION_EMAIL=
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_ENCRYPTION=tls
ADMIN_USER=admin
ADMIN_PASS=use-a-long-random-password
```

The application reads `.env` through `assets/php/db.php`. Production deployments should use a dedicated MySQL account instead of `root`.

## Database setup

Create the database and tables with MySQL:

```powershell
mysql -u root -p -e "source setup.sql"
```

The schema contains the contact-message table and the database-backed rate-limit table. Contact records support read and archive states for dashboard management.

### Backend foundation migrations

Run migrations from the project root on the PHP/MySQL host:

```powershell
php database/migrate.php status
php database/migrate.php migrate
php database/migrate.php verify
```

The migration runner uses the existing PDO configuration from `assets/php/db.php`. It creates `schema_migrations`, adds the backend foundation tables, and copies existing `contact_messages` into conversations and visitor messages without modifying or deleting the legacy table. Re-running `migrate` is safe.

Run the migration checks after applying the migrations:

```powershell
php tests/migrations.php
```

## Run locally

Use a PHP-enabled server from the project directory:

```powershell
php -S 127.0.0.1:8080
```

Open:

```text
http://127.0.0.1:8080/index.php
```

A static server can display the page, but it cannot execute the PHP API or connect to MySQL.

## Backend routes

```text
POST /api/contact.php         Save a JSON contact message in the backend foundation
POST /api/auth/login.php      Authenticate an admin and issue a bearer token
POST /api/auth/logout.php     Revoke the current bearer token
GET  /api/auth/me.php         Return the authenticated admin identity
POST /api/conversation/reply.php?id=<id>  Store an authenticated admin reply
POST /assets/php/contact.php   Save a contact message
GET  /assets/php/csrf.php      Create a contact-form token
GET  /assets/php/health.php    Check database readiness
GET  /assets/php/admin.php     Open the administrator dashboard
```

The dashboard requires `ADMIN_USER` and `ADMIN_PASS`. The password may be a PHP hash created with `password_hash()`.

The API authentication endpoints use short-lived opaque bearer tokens. Tokens are sent as `Authorization: Bearer <token>` and are never stored in plaintext. Configure their lifetime with `API_AUTH_TOKEN_TTL_SECONDS` (default 1800 seconds). Login attempts use the existing rate-limit table with `API_AUTH_LOGIN_RATE_LIMIT_SECONDS` (default 10 seconds).

Admin replies are persisted before any optional email transport attempt. Email delivery is disabled by default with `REPLY_EMAIL_ENABLED=false`; enabling it requires a valid `REPLY_FROM_EMAIL` and a verified PHP mail transport on the host. No notification record is created for replies in this stage.

## Validation

Run the smoke checks against a running PHP server:

```powershell
php tests/smoke.php http://127.0.0.1:8080
```

The checks cover the portfolio entry point, health endpoint, CSRF endpoint, admin route, manifest, robots file, contact validation, and CSRF rejection.

## Deployment notes

For production:

- Use HTTPS.
- Use Apache or Nginx with PHP support.
- Keep `.env` outside public access where possible.
- Keep `.htaccess` enabled when using Apache.
- Use a dedicated database user with limited permissions.
- Configure SMTP through a mail library such as PHPMailer for reliable notifications.
- Set a strong administrator password hash.
- Back up the MySQL database and test restoration.
- Review contact-message retention and privacy requirements.

The portfolio describes verified academic and service experience. Project repositories, screenshots, live demos, employment history, and certifications should only be added when their details can be verified.

## Production deployment

### Requirements

- PHP 8.1 or newer. The code uses typed return declarations and `str_starts_with()`.
- PHP extensions: PDO, PDO MySQL, JSON, Filter, and OpenSSL.
- MySQL 8.0 or newer is the tested production target.
- Apache 2.4 with `mod_headers` and `mod_rewrite`, or Nginx configured to route PHP files to PHP-FPM.
- HTTPS for production. The application uses secure session cookies automatically when HTTPS is detected.

There are no file uploads, cron jobs, background workers, or application-owned writable directories. File fallback is disabled by default. If explicitly enabled for local development, it writes to the operating system temporary directory.

### Recommended deployment shape

The current application must run on a PHP-capable host:

```text
https://your-domain.example/
	|
	v
PHP application -> PDO -> MySQL
```

GitHub Pages cannot execute PHP. It may host a separate static copy of the frontend, but the current same-origin session, CSRF, and contact API design is not a GitHub Pages deployment. A split GitHub Pages frontend plus external PHP API would require CORS, cross-origin cookie, CSRF, and API URL changes, so it is intentionally not implemented.

### Database and application user

Use an administrative MySQL account only to create the database and restricted application user. Do not put the administrative account in the application environment.

```sql
CREATE DATABASE portfolio_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portfolio_app'@'localhost' IDENTIFIED BY 'replace-with-a-secret';
GRANT SELECT, INSERT, UPDATE, DELETE ON portfolio_db.* TO 'portfolio_app'@'localhost';
FLUSH PRIVILEGES;
```

Import `setup.sql` after creating the database, then configure the application with the restricted user:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=portfolio_db
DB_USER=portfolio_app
DB_PASS=the-secret-held-by-your-host
```

Never commit those values. The repository contains no production credentials.

### Deployment steps

1. Upload or clone the project outside any unrelated public directory.
2. Set the web document root to the project directory, or to a configured public directory that can serve `index.php` and the `assets` folder.
3. Create the restricted MySQL user and import `setup.sql`.
4. Create `.env` from `.env.example` using deployment-specific values.
5. Set `APP_ENV=production` and `APP_DEBUG=false`.
6. Configure `ADMIN_USER` and a `password_hash()` value in `ADMIN_PASS`.
7. Keep `.htaccess` enabled under Apache. Under Nginx, reproduce its protections by denying access to `.env`, `setup.sql`, `resume`, `tests`, `extract_cv.py`, and repository metadata.
8. Enable HTTPS and redirect HTTP to HTTPS at the web server.
9. Configure backups for MySQL and test restoring one backup before launch.

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
