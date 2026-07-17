# Balingasag SHS AMS

Attendance and Academic Management System for Balingasag Senior High School.

## Requirements

- PHP 8.2+
- MySQL or MariaDB
- Composer
- PHP extensions: PDO, mbstring, json, zip, dom

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
- `storage/` - runtime/generated files; ignored by Git.
- `vendor/` - Composer dependencies; ignored by Git.

## Authentication And Security

- Web requests load `functions/bootstrap.php`, which loads Composer and starts sessions through `config/session.php`.
- Web forms use a session CSRF token.
- API bearer tokens are signed with `API_AUTH_SECRET`.
- API sync routes use `API_SYNC_SECRET`.
- In production, set `APP_ENV=production`, `API_AUTH_SECRET`, `API_SYNC_SECRET`, and a trusted `API_ALLOWED_ORIGIN`.
- The API first-login password-change flow requires the `temp_token` returned by `POST /api/index.php?route=login` when `must_change_password` is true.
- Default or first-run passwords must be changed before production.

## Role Modules

### Admin

- Users, sections, classes, enrollments, attendance, reports, archives, announcements, grade approvals, SF1 import, and DepEd form exports from Reports.

### Teacher

- Dashboard, advisory section, attendance, classes, grades, reports, announcements, archives, chat, and SF2 export from Reports.

### Student

- Dashboard, attendance, classes, announcements, QR, and report card views.

### Parent

- Dashboard, linked student progress, report cards, announcements, and chat.

## Report Card Approval Pipeline

1. Subject teacher submits grades: `grade_approvals.status = 'submitted'`.
2. Admin verifies or rejects subject grades: `admin_verified` or `rejected`.
3. Adviser submits compiled report cards: `report_card_approvals.status = 'submitted_admin'`.
4. Admin approves or rejects report cards: `approved` or `rejected`.

## Instructor RBAC Branch

- The `instructor-rbac-requirements` branch contains the instructor-specific RBAC requirement work and should stay separate from `main`.
- RBAC tables are defined in `database/rbac_migration.sql` and default permissions are in `database/rbac_seed.sql`.
- Runtime helpers also auto-create and seed RBAC tables when the RBAC control panel or protected pages are loaded.
- Permission checks are enforced through `functions/bootstrap.php` using the current script-to-permission map in `functions/app-helpers.php`.
- The Admin RBAC Control Panel is available from the admin sidebar.

## DepEd Templates

- Active SF1, SF2, and ECR templates live in `deped/`.
- SF1 uses `deped/SF1_Senior_High_School.xlsx`.
- SF2 uses `deped/SF2_Senior_High_School.xlsx`.
- ECR XLSX export requires a compatible three-term Strengthened SHS `.xlsx` template at `deped/ecr_template.xlsx` or `ECR_TEMPLATE_PATH`.
- If `deped/ecr_template.xlsx` is missing, ECR CSV export can still work but ECR XLSX export should report that a compatible template is required.
- ECR teacher imports accept `.xlsx` only.
- SF1, SF2, and ECR user-facing exports should be CSV or XLSX only.
- SF1 import is handled through Admin Enrollments.
- Admin SF1 and SF2 exports are available from Admin Reports.
- Teacher SF2 export is available from Teacher Reports.
- Teacher ECR preview, upload, import, and export are served by `api/routes/12-ecr.php`; ECR file uploads must be `.xlsx`, and ECR downloads may be `.csv` or `.xlsx`.
- Legacy `.xlsm` ECR workbooks are not accepted as export templates or uploads.
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
