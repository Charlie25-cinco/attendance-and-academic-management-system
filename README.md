# Balingasag SHS AMS

Attendance and Academic Management System for Balingasag Senior High School.

## Requirements

- PHP 8.2+
- MySQL or MariaDB
- Composer
- PHP extensions: PDO, mbstring, json, zip, dom, curl, openssl

## Setup

1. Copy `.env.example` to `.env`.
2. Install dependencies with `composer install`.
3. Configure database settings in `.env`.
4. Import `database/schema.sql`.
5. Optional: import seed data from `database/seed.sql` and `database/seed_ssms_g11_subjects.sql`.
6. Start the local server with `composer run serve`.

The development server runs from the project root with `router.php`:

```sh
composer run serve
```

The development server listens at `http://localhost:5000/`.

Open `http://localhost:5000`.

## Useful Commands

- `composer run serve` starts the local PHP development server.
- `composer run lint` runs PHP CodeSniffer on `functions/`, `config/`, and `api/`.
- `composer run test` runs PHPUnit.
- `composer run seed:admin` runs the admin seeder.
- `wasmer run .` tests the Wasmer PHP package locally on `http://localhost:8080/` after the Wasmer CLI is installed.

## Project Layout

- `admin/` - administrator dashboards and action handlers.
- `api/` - API front controller, support helpers, push notification helpers, and route files.
- `assets/` - CSS, JavaScript, images, bundled frontend vendor files, manifest, service worker, and uploads.
- `auth/` - web login, logout, password reset, and first-login password change pages.
- `config/` - constants, database connection, and session/security headers.
- `database/` - schema, seed SQL, and admin seeder.
- `deped/` - source-of-truth DepEd SF1/SF2 workbooks, ECR `.xlsx` template, and curriculum reference ZIP.
- `functions/` - bootstrap, helpers, grade logic, database helpers, and exporters.
- `includes/` - shared header, sidebar, footer, modals, and UI fragments.
- `parent/`, `student/`, `teacher/` - role-specific web modules.
- `resources/` - legacy/reference DepEd material.
- `site/` - public site entry point.
- `src/` - PSR-4 namespaced classes (`BshsAms\Database`, `BshsAms\Schedule`, `BshsAms\Grade`, `BshsAms\Export`, `BshsAms\Xlsx`).
- `storage/` - protected runtime/generated files, including durable learning materials; ignored by Git.
- `vendor/` - Composer dependencies; ignored by Git.

## Authentication And Security

- Web requests load `functions/bootstrap.php`, which loads Composer and starts sessions through `config/session.php`.
- Web forms use a session CSRF token.
- API bearer tokens are signed with `API_AUTH_SECRET`.
- API sync routes use `API_SYNC_SECRET`.
- Admin Audit Logs show important admin changes from `admin_audit_logs` and recent sign-in attempts from `auth_login_logs`.
- In production, set `APP_ENV=production`, `API_AUTH_SECRET`, `API_SYNC_SECRET`, and a trusted `API_ALLOWED_ORIGIN`.
- Set `APP_SESSION_DRIVER=database` in stateless hosting such as Wasmer so active PHP sessions are stored in the SQL database instead of local instance files.
- `APP_SESSION_LIFETIME` and `APP_SESSION_IDLE_TIMEOUT` control how long an active web/PWA session can survive after closing and reopening; the example uses 24 hours, while remember-me tokens keep trusted devices signed in longer.
- Create the first admin with `composer run seed:admin`, which runs `database/seed_admin.php`; the Wasmer database dashboard can alternatively import `database/seed_admin.sql` after `database/schema.sql`.
- `FIRST_RUN_ADMIN_PASSWORD` controls PHP-based first admin seeding, and `DEFAULT_NEW_USER_PASSWORD` controls newly created users.
- The API first-login password-change flow requires the `temp_token` returned by `POST /api/index.php?route=login` when `must_change_password` is true.
- Web login and remember-me auto-login both force password setup while a user's password still matches `DEFAULT_NEW_USER_PASSWORD`.
- Shared profile modal updates name, sex, email, and password through the profile API; password fields include visibility toggles and require the current password before changing.
- Default or first-run passwords must be changed before production.

## Role Modules

### Admin

- Users, sections, classes, enrollments, attendance, reports, archives, audit logs, announcements, grade approvals, SF1 import, and DepEd form exports from Reports.

### Teacher

- Dashboard, advisory section, attendance, classes, grades, reports, announcements, archives, adviser-parent chat, and SF2 export from Reports.
- Created grade activities are visible to enrolled students and linked parents before scores are recorded.
- Attendance recording creates saved in-app notifications for students and linked parents.
- Teacher QR attendance scans are classified by `Asia/Manila` server time: scans before the scheduled start plus 15 minutes are present, while scans at or after that boundary are late. QR scanning is available only for today's attendance sheet.

### Student

- Dashboard, attendance, classes, announcements, QR, and report card views.
- Classes includes grade activity status and recorded scores when available.

### Parent

- Dashboard, linked student progress, report cards, announcements, and adviser chat.
- Progress includes grade activity status, recorded scores, and attendance notifications for linked students.

## Report Card Approval Pipeline

1. Subject teacher submits grades to admin: `grade_approvals.status = 'submitted'`.
2. Subject teacher grade activities and score edits are locked only while grades are `submitted`.
3. Subject teacher may recall while grades are `submitted`; rejected, verified, or final-released grades may be corrected by submitting again.
4. Admin verifies subject grades for adviser review or rejects them: `admin_verified` or `rejected`.
5. Admin may return verified subject grades to the teacher by marking them `rejected`, which unlocks teacher editing and resubmission.
6. Adviser submits compiled report cards to admin: `report_card_approvals.status = 'submitted_admin'`.
7. Adviser may recall while report cards are `submitted_admin` or `rejected`; final-approved report cards are locked from adviser recall.
8. Admin gives final report-card approval or rejection: `approved` or `rejected`.
9. Student and parent portals show grades only after final admin approval through `report_card_approvals.status = 'approved'`.
10. After final release, teachers may submit corrected subject grades again; the affected approved report cards are marked `rejected` so student and parent portals stop showing stale final grades until approval runs again.

## Chat

- Chat is limited to adviser-parent communication.
- Teacher chat lists only parents of students in the teacher's advisory section through `classes.teacher_id`.
- Parent chat lists only the adviser for each linked student section.
- Subject teachers must not be exposed as parent chat contacts through `class_subjects`.

## UI System

- Shared UI styling lives in `assets/css/main.css` and role-specific refinements live in `assets/css/role.css`.
- Pages should use the common card, table, button, form, badge, modal, header, and chat styles instead of one-off visual treatments.
- The current visual direction is simple, modern, professional, compact, and consistent across admin, teacher, student, and parent portals.
- Admin core pages share compact headers, stat cards, filter forms, tables, action buttons, pagination, and modal panel styling from the shared CSS layer.
- Teacher portal pages share compact heroes, KPI cards, filters, attendance status controls, grade tables, action bars, and chat surfaces from the shared CSS layer.
- Modal, helper text, empty-state, and note styles should preserve readable contrast on light and dark surfaces.
- Shared settings/profile modals and admin dashboard widgets should use the unified modal, card, chart-panel, list-row, and empty-state styling rather than inline page-only CSS.
- The public school website lives in `site/index.php` with styling in `assets/css/Site.css`, including the public-site scoped loading overlay styles; do not place public website copies inside `assets/uploads/`.
- Authentication pages use the shared `assets/css/auth.css` visual system; keep login, forgot password, reset password, and first-login password setup consistent while preserving CSRF, rate limit, token, and password-guidance behavior.
- Global page loading and navigation transitions are controlled by `assets/js/main.js` and styled in `assets/css/main.css`; keep loader behavior accessible, compact, dark-mode ready, and reduced-motion compatible.
- Multi-content workspace pages should provide compact in-page navigation so users can jump between major sections without manually scrolling through the whole page.
- Admin hero, metric, and approval-card text must keep readable contrast and enough icon/title spacing on both light and dark surfaces.

## PWA

- PWA metadata lives in `assets/manifest.json`.
- The active service worker is root-scoped at `sw.js` so it can cover `/auth/`, `/admin/`, `/teacher/`, `/student/`, `/parent/`, and `/site/`.
- `assets/push-sw.js` is kept only as a compatibility bridge for older browser registrations.
- Installed app icons are generated from the school seal with safe padding for normal, maskable, and Apple touch icon use; regenerate them with `php scripts/generate_pwa_icons.php` after replacing `assets/images/bshs-logo.jpg`.
- Install prompts are exposed through the shared header install button when the browser supports installation.
- Device/system push notifications use the root service worker, browser Push API subscriptions, and VAPID keys.
- Users enable or disable device notifications from the shared Settings modal. When permission is undecided, Settings displays **Allow Notifications** and **Not Now** actions; blocked permission includes instructions to reopen permission through browser or operating-system app settings.
- Generate standard Base64URL VAPID values with `composer run push:keys`, then configure the printed `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, and `PUSH_VAPID_SUBJECT` values in Wasmer app **Settings > Secrets** before expecting installed PWA popup notifications. Regenerating keys requires users to subscribe their devices again.
- Web Push sending requires PHP `curl` and `openssl`; failures are written to the PHP error log for deployment troubleshooting.
- School and class announcements, attendance, materials, grade activities and scores, and report-card releases are always saved as in-app notifications before push delivery is attempted.
- A subscribed device can receive Web Push while the installed PWA is closed when notification permission is granted, the device is online, and the operating system permits background notifications. Use **Settings > Test Notification** after subscribing to verify the full server-to-device path. Saved in-app notifications remain available on the next app open even when push delivery is unavailable.
- Portal JavaScript and CSS use versioned URLs plus network-first service-worker updates, so a newly deployed notification fix is loaded before an older cached asset while offline fallback remains available.
- Teacher attendance submissions can be queued in `localStorage` while offline and retried against `teacher_Action.php?action=submit_attendance` when the browser comes back online.
- Teacher QR attendance scanning requires HTTPS and browser camera permission. Camera access is permitted only on `teacher/teacher_Attendance.php`; other application pages keep camera access disabled.
- Before production, test install/offline behavior on desktop and mobile browsers.

## Wasmer Deployment

- Wasmer Edge config lives in `app.yaml`, package config lives in `wasmer.toml`, and PHP settings live in `config/wasmer/php.ini`.
- GitHub deployment is configured in `.github/workflows/wasmer-deploy.yml`; it installs production Composer dependencies before running `wasmer deploy --non-interactive`. Only the external deploy operation retries transient Wasmer registry failures, up to four attempts with bounded backoff.
- Set these GitHub repository secrets before using the workflow:
  - `WASMER_TOKEN`
  - `WASMER_OWNER`
  - `WASMER_APP_NAME`, for example `balingasagshs`; if omitted, the package default is `bshs-ams`
  - `APP_PUBLIC_BASE_URL`, for example `https://balingasagshs.wasmer.app`
  - `DEFAULT_NEW_USER_PASSWORD` and optional `FIRST_RUN_ADMIN_PASSWORD`
  - `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, and optional `RESEND_FROM_NAME`
- Configure production secrets in Wasmer for database and API values:
  - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` or `DB_PASSWORD`
  - `DB_SSL_CA` or `DB_SSL_CA_CONTENT` only when the hosted MySQL provider requires TLS CA verification
  - `DB_SSL_VERIFY_SERVER_CERT` optional, defaults to `1`
  - `APP_SESSION_DRIVER=database`
  - `DEFAULT_NEW_USER_PASSWORD`, and `FIRST_RUN_ADMIN_PASSWORD` if first-admin seeding will run in that environment
  - `API_AUTH_SECRET`, `API_SYNC_SECRET`
  - `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, `RESEND_FROM_NAME` for password reset OTP email
  - `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, `PUSH_VAPID_SUBJECT` for installed PWA device notifications
  - SMTP or SMS secrets if those fallback/features are enabled
- `app.yaml` intentionally contains placeholder owner/public URL values that the GitHub workflow replaces from secrets.
- Composer runtime platform checks are disabled in `composer.json` because Wasmer's PHP/WASI runtime can report a non-64-bit platform even though the application can still boot and serve normal web requests.
- Wasmer app instances are stateless; runtime files should use the configured Wasmer volumes for `/app/storage` and `/app/assets/uploads`, while durable school data and PHP sessions should live in Wasmer Attached Database.
- Teacher learning-material uploads are stored under `/app/storage/materials` on Wasmer and downloaded only through authenticated teacher/student handlers. `MATERIAL_STORAGE_PATH` may override this location for a trusted deployment.
- Teacher Classes uses a learning-material upload modal with an unfiltered system picker and a drag-and-drop fallback. Browser and server validation still restrict uploads to supported file types and 10 MB, without previewing or reading file contents before upload.
- For installed PWA use, set `APP_SESSION_DRIVER=database`, `APP_SESSION_LIFETIME=86400`, and `APP_SESSION_IDLE_TIMEOUT=86400`; users can stay signed in longer through the checked-by-default trusted-device option on login.
- Wasmer Attached Database uses hosted MySQL; import `database/schema.sql` first so `app_sessions` and authentication tables exist before production traffic.
- Use `DB_SSL_CA` for a CA file path, or `DB_SSL_CA_CONTENT` when the CA PEM is stored directly as a GitHub/Wasmer secret.
- Password reset uses a 6-digit OTP sent through Resend when configured, with SMTP as fallback; reset codes are hashed in `auth_password_resets.token_hash` and expire after 10 minutes.
- For both Laragon and Wasmer Attached Database imports, use `database/schema.sql`. It is hosted-MySQL-compatible and does not include local-only `DROP DATABASE`, `CREATE DATABASE`, or `USE school_db` statements.
- If migrating existing hosted data into Wasmer Attached Database, import `database/schema.sql` first, import the data dump, then update Wasmer app environment variables to the Wasmer database values. Keep the previous database as backup until all login, SF1, SF2, attendance, and grading flows are verified.

## RBAC

- RBAC tables and default permissions are included in `database/schema.sql`.
- Runtime helpers also auto-create and seed RBAC tables when the RBAC control panel or protected pages are loaded.
- Permission checks are enforced through `functions/bootstrap.php` using the current script-to-permission map in `functions/app-helpers.php`.
- The Admin RBAC Control Panel is available from the admin sidebar.

## DepEd Templates

- Active SF1, SF2, and ECR templates live in `deped/`.
- SF1 uses `deped/SF1_Senior_High_School.xlsx`.
- SF1 XLSX import reads the official DepEd layout: LRN from the merged A:B area and learner name in merged columns C:F as `Last Name, First Name, Name Extension, Middle Name`.
- Student addresses follow SF1 separated columns: house/street, barangay, municipality/city, and province, while retaining the combined `address` value for older views.
- SF1 import auto-creates missing section records from the template grade level, section, and track, but it does not create subject classes.
- SF2 uses `deped/SF2_Senior_High_School.xlsx`, fills the official merged school/month fields and Mon-Sat attendance slots, uses blank/X/upper-half marks for present/absent/late, preserves the footer, and adds complete template sheets when learners exceed the male or female rows on one sheet.
- ECR XLSX export uses a compatible three-term Strengthened SHS template in `deped/`, preferring `deped/ecr_template.xlsx`, `deped/ecr_template.xlsm`, the uploaded TeachPinas SSHS three-term `.xlsm`, or `ECR_TEMPLATE_PATH`.
- If no compatible template is available, ECR XLSX export falls back to a generated workbook while CSV export remains available.
- ECR teacher imports accept `.xlsx` only.
- SF1, SF2, and ECR user-facing exports should be CSV or XLSX only.
- SF1 import is handled through Admin Enrollments.
- Admin SF1 and SF2 exports are available from Admin Reports.
- Teacher SF2 export is available from Teacher Reports.
- Teacher ECR preview, upload, import, and export are served by `api/routes/12-ecr.php`; ECR file uploads must be `.xlsx`, and ECR downloads may be `.csv` or `.xlsx`.
- Compatible `.xlsm` ECR workbooks may be used as export templates, but downloads are still served as `.xlsx`; uploads remain `.xlsx` only.
- The admin portal does not expose ECR template management or academic year settings for the strengthened SHS rollout.

## GitHub Readiness

- `.gitignore` excludes local secrets, Composer dependencies, generated storage, and upload outputs.
- Do not commit `.env`, local config files, `vendor/`, `storage/`, or generated uploads.
- Recommended branches:
  - `main` for stable releases.
  - `dev` for active development and testing.
- Before opening a pull request, run:

```sh
composer run lint
composer run test
```

This workspace was reviewed as a plain folder. Initialize Git before pushing if `.git/` is not present:

```sh
git init
git add .
git commit -m "Initial project import"
```
