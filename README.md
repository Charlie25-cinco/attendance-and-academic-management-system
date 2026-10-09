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
4. Set `DEFAULT_NEW_USER_PASSWORD` to the approved shared first-login password.
5. Run `composer run database:setup-sql`.
6. Select the intended database and import the ignored `database/reset_and_setup.local.sql`.
7. Delete the generated SQL file after a successful import.
8. Start the local server with `composer run serve`.

The generated setup is intentionally destructive: it resets the selected database, creates the complete schema, loads baseline school/subject/RBAC data, and provisions the protected Admin and Principal accounts. Back up any database that must be preserved before importing it.

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
- `database/` - canonical schema, reset source, protected-account seeder, and ignored generated setup import.
- `deped/` - source-of-truth DepEd SF1/SF2 workbooks, ECR `.xlsx` template, and curriculum reference ZIP.
- `functions/` - bootstrap, helpers, grade logic, database helpers, and exporters.
- `includes/` - shared header, sidebar, footer, modals, and UI fragments.
- `parent/`, `student/`, `teacher/` - role-specific web modules.
- `principal/` - Principal grade verification, report-card endorsement, academic/attendance monitoring, activity logs, release register, and decision history.
- `resources/` - legacy/reference DepEd material.
- `site/` - public site entry point.
- `src/` - PSR-4 namespaced classes (`BshsAms\Database`, `BshsAms\Schedule`, `BshsAms\Grade`, `BshsAms\Export`, `BshsAms\Xlsx`).
- `storage/` - protected runtime/generated files, including durable learning materials; ignored by Git.
- `vendor/` - Composer dependencies; ignored by Git.

## Authentication And Security

### Security review fixes (v0.3.171)

This operational documentation applies ISO/IEC/IEEE 29148 clarity and traceability principles; it is not a complete requirements specification.

- `router.php` and the root Apache `.htaccess` restrict HTTP access to public entry points and assets. Apache requires `mod_rewrite` and overrides enabled; other servers must enforce the same boundary. Configuration, source helpers, dependencies, stored documents, and dotfiles are private.
- Development API secrets are stored in `storage/secrets`; existing `api/.api_secret` and `api/.api_sync_secret` files migrate there when used. Production continues to require environment secrets.
- Authenticated API routes enforce method-specific RBAC through `src/Security/ApiAccessPolicy.php`. Cookie-authenticated mutations require a session CSRF token in the header, query string, form body, or JSON body. JSON APIs require `Content-Type: application/json`; bearer-only requests do not require browser CSRF tokens.
- Offline teacher data is stored in account-specific IndexedDB databases and localStorage namespaces. Logout locks pending records and deletes private HTML caches. Only signing into the same account unlocks pending work; sync verifies its account owner on both client and server. The local lock expires with the configured idle timeout, capped at 24 hours, and is not an authentication credential or encryption.
- Old unscoped offline queues are not automatically assigned to a user because their ownership cannot be verified. Synchronize pending work before upgrading an existing installation. Legacy browser data is left untouched for deliberate recovery, but is no longer loaded by the application.
- Only the designated teacher offline workspaces may retain authenticated HTML in an account-specific cache. Other authenticated pages and login forms are not cached. JavaScript/CSS use network-first delivery with offline fallback.
- Run the browser-behavior simulation with `node tests/browser-security.cjs` as well as Composer lint/tests. Manual installed-PWA logout/account-switch testing remains necessary on desktop and mobile.

- Web requests load `functions/bootstrap.php`, which loads Composer and starts sessions through `config/session.php`.
- Web forms use a session CSRF token.
- API bearer tokens are signed with `API_AUTH_SECRET` and carry an `api_token_version` claim; password changes bump that version, so previously issued tokens stop working immediately on all devices.
- Logout requires the session CSRF token, preventing cross-site drive-by logout links.
- API sync routes use `API_SYNC_SECRET`.
- Admin Audit Logs show role-aware critical activity from `activity_logs`, historical admin records from `admin_audit_logs`, and recent sign-in attempts from `auth_login_logs`. Sensitive details are redacted before new activity records are stored.
- Teacher, Student, and Parent portals provide a read-only My Activity page limited to the signed-in account's role-scoped `activity_logs` records. These pages never expose other users, sign-in diagnostics, IP addresses, credentials, tokens, or sensitive metadata.
- In production, set `APP_ENV=production`, `API_AUTH_SECRET`, `API_SYNC_SECRET`, and a trusted `API_ALLOWED_ORIGIN`.
- Set `APP_SESSION_DRIVER=database` in stateless hosting such as Wasmer so active PHP sessions are stored in the SQL database instead of local instance files.
- `APP_SESSION_LIFETIME` and `APP_SESSION_IDLE_TIMEOUT` control how long an active web/PWA session can survive after closing and reopening; the example uses 24 hours, while remember-me tokens keep trusted devices signed in longer.
- Create the protected Admin and Principal accounts with `composer run seed:admin`, which runs `database/seed_admin.php` using the same strong `DEFAULT_NEW_USER_PASSWORD` configured for every role.
- For a complete database-dashboard reset, run `composer run database:setup-sql`, import the ignored `database/reset_and_setup.local.sql`, then delete the generated file.
- `DEFAULT_NEW_USER_PASSWORD` controls first-login credentials for Admin, Principal, Teacher, Student, and Parent accounts. Admin cannot create, edit, reset, deactivate, or archive the deployment-owned Principal account.
- The API first-login password-change flow requires the `temp_token` returned by `POST /api/index.php?route=login` when `must_change_password` is true.
- Web login, remember-me auto-login, and API login force password setup while a user still has the configured default password.
- Shared profile modal updates name, sex, email, and password through the profile API; password fields include visibility toggles and require the current password before changing.
- Keep the deployed default password in local/hosting secrets rather than Git; each user must replace it during first login.
- Logical security is implemented at the web/PWA layer: `config/session.php` applies security headers, restricts camera and other device permissions by page, sends no-cache headers for authenticated pages, and recognizes forwarded HTTPS for hosted deployments.
- The app warns before refreshing or leaving dirty sensitive POST forms; browser security does not permit the PWA to disable operating-system controls such as `Ctrl+Alt+Del`, `Alt+Tab`, or startup programs.

## Role Modules

### Principal

- Verifies or returns subject-teacher grade submissions before adviser compilation.
- Endorses or returns compiled adviser report cards before Admin release.
- Monitors academic workflow, attendance summaries, Admin-released records, and privacy-filtered role activity through read-only pages.

### Admin

- Users, sections, classes, enrollments, attendance, reports, archives, audit logs, announcements, final report-card release, SF1 import, and DepEd form exports from Reports.
- Student accounts remain in the dedicated Admin Enrollments sidebar workspace rather than Manage Users; the eye action opens a read-only complete learner profile with identity, academic placement, address, family/guardian, linked-parent, enrollment, and account-activity information.
- Final release saves notifications for students, linked parents, the adviser, subject teachers, and the Principal before optional Web Push delivery.

### Teacher

- Dashboard, advisory section, attendance, classes, grades, reports, announcements, archives, self-only activity history, adviser-parent chat, and SF2 export from Reports.
- Created grade activities are visible to enrolled students and linked parents before scores are recorded.
- Attendance recording creates saved in-app notifications for students and linked parents.
- Teacher QR attendance scans are classified by `Asia/Manila` server time: scans before the scheduled start plus 15 minutes are present, while scans at or after that boundary are late. QR scanning is available only for today's attendance sheet.

### Student

- Dashboard, attendance, classes, announcements, QR, report card views, and self-only activity history.
- Classes includes grade activity status and recorded scores when available.

### Parent

- Dashboard, linked student progress, report cards, announcements, adviser chat, and self-only activity history.
- Progress includes grade activity status, recorded scores, and attendance notifications for linked students.

## Report Card Approval Pipeline

1. Subject teacher submits grades to the Principal: `grade_approvals.status = 'submitted'`.
2. Subject teacher grade activities and score edits are locked only while grades are `submitted`.
3. Subject teacher may recall while grades are `submitted`; rejected, verified, or final-released grades may be corrected by submitting again.
4. Principal verifies subject grades for adviser review or rejects them: `admin_verified` or `rejected` (the legacy storage name is retained for compatibility).
5. Principal may return verified subject grades to the teacher by marking them `rejected`, which unlocks teacher editing and resubmission.
6. Adviser submits compiled report cards to the Principal: `report_card_approvals.status = 'submitted_admin'` (the legacy storage name is retained for compatibility).
7. Adviser may recall while report cards are `submitted_admin`; Principal-returned cards may be corrected and resubmitted.
8. Principal endorses a report card to Admin as `pending`, or returns it as `rejected`.
9. Admin gives final release as `approved`, returns an endorsed card as `rejected`, or withdraws a previously released card to `rejected`.
10. Student and parent portals show grades only after Admin release through `report_card_approvals.status = 'approved'`.
11. After final release, teachers may submit corrected subject grades again; the affected approved report cards are marked `rejected` so student and parent portals stop showing stale final grades until approval runs again.

Attendance recording accepts only `present`, `absent`, and `late`. Additional absence context belongs in the remarks field.

## Chat

- Chat is limited to adviser-parent communication.
- Teacher chat lists only parents of students in the teacher's advisory section through `classes.teacher_id`.
- Parent chat lists only the adviser for each linked student section.
- Subject teachers must not be exposed as parent chat contacts through `class_subjects`.

## UI System

- Shared UI styling lives in `assets/css/main.css` and role-specific refinements live in `assets/css/role.css`.
- Pages should use the common card, table, button, form, badge, modal, header, and chat styles instead of one-off visual treatments.
- The current visual direction is simple, modern, professional, compact, and consistent across Principal, Admin, Teacher, Student, and Parent portals.
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
- The active service worker is root-scoped at `sw.js` so it can cover `/auth/`, `/principal/`, `/admin/`, `/teacher/`, `/student/`, `/parent/`, and `/site/`.
- `assets/push-sw.js` is kept only as a compatibility bridge for older browser registrations.
- Installed app icons are generated from the school seal with safe padding for normal, maskable, and Apple touch icon use; regenerate them with `php scripts/generate_pwa_icons.php` after replacing `assets/images/bshs-logo.jpg`.
- Install prompts are exposed through the shared header install button when the browser supports installation.
- Device/system push notifications use the root service worker, browser Push API subscriptions, and VAPID keys.
- Users enable or disable device notifications from the shared Settings modal. When permission is undecided, Settings displays **Allow Notifications** and **Not Now** actions; blocked permission includes instructions to reopen permission through browser or operating-system app settings.
- Generate standard Base64URL VAPID values with `composer run push:keys`, then configure the printed `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, and `PUSH_VAPID_SUBJECT` values in Wasmer app **Settings > Secrets** before expecting installed PWA popup notifications. Regenerating keys requires users to subscribe their devices again.
- Web Push sending requires PHP `curl` and `openssl`; failures are written to the PHP error log for deployment troubleshooting.
- School and class announcements, attendance, materials, grade activities and scores, and report-card releases are always saved as in-app notifications before push delivery is attempted.
- A subscribed device can receive Web Push while the installed PWA is closed when notification permission is granted, the device is online, and the operating system permits background notifications. Saved in-app notifications remain available on the next app open even when push delivery is unavailable.
- Portal JavaScript and CSS use versioned URLs plus network-first service-worker updates, so a newly deployed notification fix is loaded before an older cached asset while offline fallback remains available.
- Teacher attendance and grade-activity changes use teacher-owned IndexedDB queues with account-scoped localStorage fallback. Saves are acknowledged only after durable local persistence, then retried through Background Sync, reconnection, startup, or the teacher's manual retry action.
- The teacher offline-sync status panel shows pending and failed work, the last successful synchronization, Retry Sync, and confirmation-protected local-data clearing. Permanent server validation failures remain visible instead of retrying indefinitely.
- Manual attendance is available offline only for dates allowed by the cached class schedule. Date-specific snapshots prevent one attendance date from being displayed as another.
- Teacher QR attendance scanning requires HTTPS, an active connection, and browser camera permission because Present/Late classification uses authoritative `Asia/Manila` server time. Camera access is permitted only on `teacher/teacher_Attendance.php`; other application pages keep camera access disabled.
- Before production, test install/offline behavior on desktop and mobile browsers.

## Wasmer Deployment

- Wasmer Edge config lives in `app.yaml`, package config lives in `wasmer.toml`, and PHP settings live in `config/wasmer/php.ini`.
- GitHub deployment is configured in `.github/workflows/wasmer-deploy.yml`; it runs linting/tests with development dependencies, prunes to production dependencies with `composer install --no-dev`, and deploys via `wasmer deploy --non-interactive`. Deployment package payload size is minimized via `.wasmerignore` to prevent GraphQL timeout errors. Transient Wasmer registry failures are retried up to five attempts with bounded backoff.
- Set these GitHub repository secrets before using the workflow:
  - `WASMER_TOKEN`
  - `WASMER_OWNER`
  - `WASMER_APP_NAME`, for example `balingasagshs`; if omitted, the package default is `bshs-ams`
  - `APP_PUBLIC_BASE_URL`, for example `https://balingasagshs.wasmer.app`
  - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and either `DB_PASS` or `DB_PASSWORD`
  - `API_AUTH_SECRET` and `API_SYNC_SECRET`
  - `DEFAULT_NEW_USER_PASSWORD`, shared by every role for first login
  - `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, and optional `RESEND_FROM_NAME`
- Configure production secrets in Wasmer for database and API values:
  - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` or `DB_PASSWORD`
  - `DB_SSL_CA` or `DB_SSL_CA_CONTENT` only when the hosted MySQL provider requires TLS CA verification
  - `DB_SSL_VERIFY_SERVER_CERT` optional, defaults to `1`
  - `APP_SESSION_DRIVER=database`
  - `API_AUTH_SECRET`, `API_SYNC_SECRET`
  - `DEFAULT_NEW_USER_PASSWORD`, shared by every role for first login
  - `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, `RESEND_FROM_NAME` for password reset OTP email
  - `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, `PUSH_VAPID_SUBJECT` for installed PWA device notifications
  - SMTP secrets if email fallback is enabled
- `app.yaml` contains the public school deployment owner, app name, and URL. The GitHub workflow validates that these committed public values match `WASMER_OWNER`, `WASMER_APP_NAME`, and `APP_PUBLIC_BASE_URL`; private database and API values remain secrets.
- Composer runtime platform checks are disabled in `composer.json` because Wasmer's PHP/WASI runtime can report a non-64-bit platform even though the application can still boot and serve normal web requests.
- Wasmer app instances are stateless; runtime files should use the configured Wasmer volumes for `/app/storage` and `/app/assets/uploads`, while durable school data and PHP sessions should live in Wasmer Attached Database.
- Teacher learning-material uploads are stored under `/app/storage/materials` on Wasmer and downloaded only through authenticated teacher/student handlers. `MATERIAL_STORAGE_PATH` may override this location for a trusted deployment.
- Teacher Classes uses a learning-material upload modal whose primary Chromium picker starts in Documents, avoiding a stalled Downloads folder. Standard-picker compatibility, drag-and-drop, and clipboard paste remain available; browser and server validation restrict uploads to supported file types and 10 MB without previewing or reading file contents before upload.
- For installed PWA use, set `APP_SESSION_DRIVER=database`, `APP_SESSION_LIFETIME=86400`, and `APP_SESSION_IDLE_TIMEOUT=86400`; users can stay signed in longer through the checked-by-default trusted-device option on login.
- Wasmer Attached Database uses hosted MySQL; import `database/schema.sql` into an empty hosted database. For an explicitly approved full reset, generate `database/reset_and_setup.local.sql` locally with `composer run database:setup-sql`, import it into the selected local or hosted database, and delete it afterward; the ignored file contains destructive table drops and reusable credential hashes and must never be committed.
- Use `DB_SSL_CA` for a CA file path, or `DB_SSL_CA_CONTENT` when the CA PEM is stored directly as a GitHub/Wasmer secret.
- Password reset uses a 6-digit OTP sent through Resend when configured, with SMTP as fallback; reset codes are hashed in `auth_password_resets.token_hash` and expire after 10 minutes.
- `database/schema.sql` is the canonical structure and baseline-data source. It is hosted-MySQL-compatible and contains no `DROP DATABASE`, `CREATE DATABASE`, or `USE` statements.
- If migrating existing hosted data into Wasmer Attached Database, import `database/schema.sql` first, import the data dump, then update Wasmer app environment variables to the Wasmer database values. Keep the previous database as backup until all login, SF1, SF2, attendance, and grading flows are verified.

## RBAC

- RBAC tables, default roles, permissions, and role mappings are included in `database/schema.sql`; runtime helpers only preserve compatibility and idempotently confirm those rows.
- After updating an existing database for v3.1.0, reset and import the canonical schema (or apply its `activity_logs.view_own` permission and role mappings) before testing My Activity access.
- Permission checks are enforced through `functions/bootstrap.php` using page mappings from `permissionForScript()` and handler-action mappings from `permissionForScriptAction()` in `functions/app-helpers.php`.
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
