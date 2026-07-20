# AGENTS.md

## Workflow

Follow these phases for codebase work:

1. Plan: inspect the relevant files and propose the work.
2. Build: make approved changes only after the user accepts the plan.
3. Explain: summarize what changed, how it was checked, and any remaining risks.

Use "Accept or Reject" before moving from Plan to Build, and before making code or documentation edits.

## Project Expectations

- Keep changes small and focused.
- Update `README.md` and `AGENTS.md` when project behavior, setup, security posture, or workflow changes.
- Prefer existing project patterns over new abstractions.
- Do not commit local secrets, generated files, `vendor/`, `storage/`, or runtime uploads.
- Keep the project GitHub-ready by maintaining `.gitignore`, setup docs, and clear validation commands.
- Treat `deped/` as the source of truth for SF1, SF2, and ECR templates unless the user explicitly replaces a template.
- Runtime web pages should bootstrap through `functions/bootstrap.php`.
- Instructor-only requirements should be developed on a separate branch, not directly on `main`.

## Validation

Run focused checks after edits:

```sh
composer run lint
composer run test
```

If a check cannot be run, explain why in the final response.

For local manual testing, `composer run serve` starts the PHP development server at `http://localhost:5000/`.

## Security Notes

- Production must set `APP_ENV=production`.
- Production must provide strong `API_AUTH_SECRET` and `API_SYNC_SECRET` values.
- Production must set `API_ALLOWED_ORIGIN` to a trusted origin.
- First admin seeding must use `composer run seed:admin`, which runs `database/seed_admin.php`; do not hardcode admin password hashes in `database/seed.sql`.
- `FIRST_RUN_ADMIN_PASSWORD` controls first admin creation, and `DEFAULT_NEW_USER_PASSWORD` controls newly created users.
- API first-login password changes require the temporary token returned by the login endpoint.
- RBAC permission enforcement is centralized from `functions/bootstrap.php`; keep the script-to-permission map in `functions/app-helpers.php` current when adding protected pages or action handlers.
- Existing AJAX flows may pass CSRF by POST body, query string, or `X-CSRF-Token`; keep `requireCsrfToken()` compatible with all three unless those callers are migrated.

## DepEd Forms

- SF1 follows `deped/SF1_Senior_High_School.xlsx`.
- SF1 XLSX import must parse the official combined learner-name cell in columns C:F (`Last Name, First Name, Name Extension, Middle Name`) and the LRN from the merged A:B area.
- Student address storage should preserve SF1 separated columns: `house_street`, `barangay`, `municipality`, and `province`; keep combined `address` for backward compatibility.
- SF1 import may auto-create missing section records from the template grade level, section, and track; do not auto-create subject classes from SF1.
- SF2 follows `deped/SF2_Senior_High_School.xlsx`.
- ECR follows the three-term Strengthened SHS `.xlsx` template in `deped/ecr_template.xlsx` or `ECR_TEMPLATE_PATH`.
- SF1, SF2, and ECR imports/exports should only expose CSV and XLSX formats.
- Keep Admin SF1/SF2 export controls in Admin Reports and Teacher SF2 export controls in Teacher Reports.
- Teacher ECR preview, upload, import, and export routes live in `api/routes/12-ecr.php`; keep uploads `.xlsx` only and downloads `.csv`/`.xlsx`.
- Compatible three-term `.xlsm` ECR workbooks in `deped/` may be used as export templates, but exported downloads must remain `.xlsx`.
- ECR XLSX export should prefer an official compatible template and may fall back to a generated workbook if no template is available.
- Do not reintroduce admin portal pages for ECR template management or academic year settings unless explicitly requested.

## Grading And Attendance Workflows

- Grade approval flow is subject teacher to admin, admin verification to adviser, adviser submission to admin, then final admin approval.
- Subject teacher grade submissions use `grade_approvals.status = submitted`; teacher recall is needed only while a submission is pending admin review.
- Teacher grade activity creation, score editing, finishing, deleting, and restoring must be blocked only while the matching grading period is submitted.
- Admin subject-grade verification uses `grade_approvals.status = admin_verified`, which unlocks adviser report-card submission.
- Admin may return verified subject grades to teachers by setting `grade_approvals.status = rejected`; this unlocks teacher editing and resubmission.
- Adviser report-card submissions use `report_card_approvals.status = submitted_admin`; adviser recall is allowed only before final admin approval.
- Student and parent grade/report-card visibility must require `report_card_approvals.status = approved`.
- After final release, teachers may submit corrected subject grades again; affected approved report cards should be marked `rejected` so student and parent portals stop showing stale final grades until approval runs again.
- Teacher-created grade activities must be visible to enrolled students and linked parents before scores are recorded.
- Grade activity creation and score recording should create saved in-app notifications for student and parent recipients.
- Attendance recording from teacher web, API, or sync paths should create saved in-app notifications for student and linked parent recipients.

## Chat

- Chat is adviser-parent only.
- Teacher chat contacts must come from the teacher's advisory section through `classes.teacher_id`.
- Parent chat contacts must be the adviser for each linked student's grade level and section.
- Do not use `class_subjects.teacher_id` to expose subject teachers as parent chat contacts.

## UI System

- Keep shared visual behavior in `assets/css/main.css` and role-specific refinements in `assets/css/role.css`.
- Prefer common card, table, button, form, badge, modal, header, and chat styles over page-only custom styling.
- UI should stay simple, modern, professional, compact, and consistent across all portals.
- Admin core pages should reuse compact headers, stat cards, filter forms, tables, action buttons, pagination, and modal panel styling from the shared CSS layer.
- Teacher portal pages should reuse compact heroes, KPI cards, filters, attendance status controls, grade tables, action bars, and chat surfaces from the shared CSS layer.
- Modal, helper text, empty-state, and note styles must keep readable contrast on light and dark surfaces.
- Shared settings/profile modals and admin dashboard widgets should use the unified modal, card, chart-panel, list-row, and empty-state styling instead of inline page-only CSS.
- The public school website should stay in `site/index.php` with styling in `assets/css/Site.css`; `assets/uploads/` must not contain public website copies.
- Authentication pages should use the shared `assets/css/auth.css` visual system; keep login, forgot password, reset password, and first-login password setup consistent while preserving CSRF, rate limit, token, and password-guidance behavior.
- Global page loading and navigation transitions should stay in `assets/js/main.js` with styling in `assets/css/main.css`; keep loader behavior accessible, compact, dark-mode ready, and reduced-motion compatible.
- Multi-content workspace pages should provide compact in-page navigation so users can jump between major sections without manually scrolling through the whole page.
- Admin hero, metric, and approval-card text must keep readable contrast and enough icon/title spacing on both light and dark surfaces.

## PWA

- PWA metadata should stay in `assets/manifest.json`.
- The active service worker should stay root-scoped at `sw.js` so it can cover auth, role portals, and the public site.
- Keep `assets/push-sw.js` only as a compatibility bridge for older browser registrations.
- Do not change service worker cache paths back to legacy `src/` paths.
- Teacher attendance offline mode queues submissions in `localStorage` and retries them against `teacher_Action.php?action=submit_attendance` when connectivity returns.
- Test install/offline behavior on desktop and mobile before production release.

## Wasmer Deployment

- Wasmer Edge config should stay in `app.yaml`, package config in `wasmer.toml`, and PHP settings in `config/wasmer/php.ini`.
- GitHub deployment should use `.github/workflows/wasmer-deploy.yml`.
- The workflow must install production Composer dependencies before `wasmer deploy` because `vendor/` is not committed.
- Do not commit real Wasmer owner names, tokens, database credentials, API secrets, or production URLs unless they are intentionally public.
- Configure GitHub secrets for `WASMER_TOKEN`, `WASMER_OWNER`, optional `WASMER_APP_NAME`, and `APP_PUBLIC_BASE_URL`.
- Configure Wasmer app secrets for database credentials, `API_AUTH_SECRET`, `API_SYNC_SECRET`, and optional mail/SMS/push credentials.
- Treat Wasmer app instances as stateless; use configured volumes for `/app/storage` and `/app/assets/uploads`, and keep durable school records in MySQL/MariaDB.
