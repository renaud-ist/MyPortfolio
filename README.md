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
ADMIN_PASS=use-a-password_hash-value
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
GET  /api/notifications.php       List authenticated-admin notifications
POST /assets/php/contact.php   Save a contact message
GET  /assets/php/csrf.php      Create a contact-form token
GET  /assets/php/health.php    Check database readiness
GET  /assets/php/admin.php     Open the administrator dashboard
```

The dashboard requires `ADMIN_USER` and `ADMIN_PASS`. `ADMIN_PASS` must be a PHP password hash created with `password_hash()`; plaintext administrator passwords are not accepted.

The API authentication endpoints use short-lived opaque bearer tokens. Tokens are sent as `Authorization: Bearer <token>` and are never stored in plaintext. Configure their lifetime with `API_AUTH_TOKEN_TTL_SECONDS` (default 1800 seconds). Login attempts use the existing rate-limit table with `API_AUTH_LOGIN_RATE_LIMIT_SECONDS` (default 10 seconds).

Admin replies are persisted before any optional email transport attempt. Email delivery is disabled by default with `REPLY_EMAIL_ENABLED=false`; enabling it requires a valid `REPLY_FROM_EMAIL` and a verified PHP mail transport on the host. No notification record is created for replies in this stage.

Notifications use the existing `recipient_type=admin`, `notification_type=new_contact_message`, and `status=pending` conventions. `GET /api/notifications.php` is authenticated and paginated. The current schema has no notification read-state or per-admin owner field, so Stage 7 does not expose read/unread mutation or claim per-admin notification ownership.

## Validation

Run the smoke checks against a running PHP server:

```powershell
php tests/smoke.php http://127.0.0.1:8080
```

The checks cover the portfolio entry point, health endpoint, CSRF endpoint, admin route, manifest, robots file, contact validation, and CSRF rejection.

## Deployment guide

### Requirements

- PHP 8.1 or newer with PDO, PDO MySQL, JSON, Filter, OpenSSL, and Session.
- MySQL 8.0 or newer.
- Apache 2.4 with `mod_headers` and `mod_rewrite`, or Nginx with PHP-FPM.
- HTTPS in production.
- A protected `.env` or equivalent environment-variable configuration.
- A restricted application database user. Do not use the administrative MySQL account at runtime.

The application has no file uploads, cron jobs, background workers, or application-owned writable directories. File fallback is disabled by default.

### Hosting model

The current application must run on PHP-capable hosting:

```text
https://your-domain.example/
        |
        v
PHP application -> PDO -> MySQL
```

GitHub Pages cannot execute PHP or access MySQL. It may host a separate display-only frontend, but the current contact form, admin authentication, CSRF, and messaging features require the PHP application and database. A split frontend/API deployment would require deliberate CORS, API URL, cookie, and CSRF changes and is not the current architecture.

### Database setup

Use an administrative MySQL account only to create the database and restricted application user:

```sql
CREATE DATABASE portfolio_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portfolio_app'@'localhost' IDENTIFIED BY 'replace-with-a-secret';
GRANT SELECT, INSERT, UPDATE, DELETE ON portfolio_db.* TO 'portfolio_app'@'localhost';
FLUSH PRIVILEGES;
```

Import `setup.sql`, create `.env` from `.env.example`, and configure the restricted user. Never commit production credentials.

Run the migration and verification commands from the project root:

```powershell
php database/migrate.php status
php database/migrate.php migrate
php database/migrate.php verify
php tests/migrations.php
```

Migrations preserve the legacy `contact_messages` table and copy existing records into the conversation tables without duplicating them on a safe rerun.

### Deployment steps

1. Upload or clone the project to the PHP host.
2. Point the web document root at the application directory or an equivalent configured public directory.
3. Create the restricted MySQL user and import `setup.sql`.
4. Create `.env` with deployment-specific values.
5. Set `APP_ENV=production` and `APP_DEBUG=false`.
6. Set `ADMIN_USER` and a `password_hash()` value in `ADMIN_PASS`.
7. Keep `.htaccess` enabled under Apache. Under Nginx, deny access to `.env`, `setup.sql`, `resume`, `tests`, `extract_cv.py`, and repository metadata.
8. Enable HTTPS and redirect HTTP to HTTPS.
9. Back up MySQL and test restoring a backup before launch.

### Notifications and messaging

Contact records are persisted in MySQL before optional notification delivery. `REPLY_EMAIL_ENABLED=false` is the default. The current optional contact notification uses PHP `mail()` when `CONTACT_NOTIFICATION_EMAIL` is configured; SMTP variables are not consumed by the current application. Reliable production email delivery is therefore not verified and requires a working mail transport or mail library.

Admin replies are stored in `conversation_messages` before the optional email attempt. Authentication uses short-lived opaque bearer tokens; raw tokens are returned once at login and are not stored in the database.

### Verification

After deployment, verify the homepage, health endpoint, CSRF endpoint, admin route, contact submission, and protected messaging flow:

```text
GET /index.php
GET /assets/php/health.php
GET /assets/php/csrf.php
GET /assets/php/admin.php
POST /assets/php/contact.php
```

Run the smoke checks against the deployed host:

```powershell
php tests/smoke.php https://your-deployed-host.example
```

Confirm HTTPS, database readiness, contact persistence, admin authentication, conversation access, reply persistence, CSRF rejection, and generic production error responses.

### Security and rollback

- Keep `.env` private; it is ignored by the project configuration.
- Use HTTPS and secure cookies.
- Keep CORS restricted to explicitly trusted origins.
- Keep credentials out of source control.
- Review message retention and privacy requirements.
- Keep the previous release available and deploy new code to a versioned directory.
- Run health checks before switching the document root.
- Roll back application code first when the schema remains compatible; restore the database backup only when a schema change must be reversed.

The portfolio describes verified academic and service experience. Add repositories, screenshots, live demos, employment history, or certifications only when their details can be verified.
