# AGENTS.md

## Workflow

Follow these phases for codebase work:

1. Plan: inspect the relevant files and propose the work.
2. Build: make approved changes only after the user accepts the plan.
3. Explain: summarize what changed, how it was checked, and any remaining risks.

Use "Accept or Reject" before moving from Plan to Build, and before making code or documentation edits.

## Project Expectations

- Keep changes small and focused.
- `database/schema.sql` is the single source of truth for MySQL structure: do not add new runtime `CREATE TABLE` or `ALTER TABLE` statements to application code. Existing exceptions are the SQLite test fixtures (`functions/app-helpers.php`, `config/constants.php`) and the legacy upgrade paths (`ensureAuthLoginLogsTable`, `ensureReportNotesTables`, `ensureStrengthenedShsColumns`). `tests/RuntimeDdlGuardTest.php` enforces this boundary; any new table or column ships in `schema.sql` first.
- Update `README.md` and `AGENTS.md` when project behavior, setup, security posture, or workflow changes.
- Prefer existing project patterns over new abstractions.
- Do not commit local secrets, generated files, `vendor/`, `storage/`, or runtime uploads.
- Keep the project GitHub-ready by maintaining `.gitignore`, setup docs, and clear validation commands.
- Treat `deped/` as the source of truth for SF1, SF2, and ECR templates unless the user explicitly replaces a template.
- Runtime web pages should bootstrap through `functions/bootstrap.php`.
- Principal sidebar destinations are distinct, independently rendered routes: `principal.php`, `principal_Pending.php`, `principal_Released.php`, and `principal_History.php`. Keep their shared read model in `BshsAms\Grade\PrincipalReportCardQuery`, preserve page-specific content and controls, and keep all mutations in the CSRF-protected `principal_Action.php` endpoint.
- New shared classes should be created under `src/` using the `BshsAms\` PSR-4 namespace (`BshsAms\Database`, `BshsAms\Schedule`, `BshsAms\Grade`, `BshsAms\Export`, `BshsAms\Xlsx`). Maintain legacy `class_alias()` definitions when refactoring existing global classes.
- Instructor-only requirements should be developed on a separate branch, not directly on `main`.

## Documentation Map (§6 ISO/IEC/IEEE 29148 Standard)

This document acts as the primary entry point for AI agents and developers. Dedicated documentation is organized in `docs/`:

- [README.md](file:///c:/laragon/www/attendance-and-academic-management-system/README.md) — Standard project documentation, environment setup, and CLI usage.
- [AGENTS.md](file:///c:/laragon/www/attendance-and-academic-management-system/AGENTS.md) — Operational guidelines, coding standards, and security constraints.
- [docs/PRODUCT_SPEC.md](file:///c:/laragon/www/attendance-and-academic-management-system/docs/PRODUCT_SPEC.md) — Product specifications, functional requirements (REQ-001..REQ-010), and user persona workflows.
- [docs/SYSTEM_FEATURES.md](file:///c:/laragon/www/attendance-and-academic-management-system/docs/SYSTEM_FEATURES.md) — Comprehensive system features, DepEd compliance breakdown, and research paper technical specifications.
- [docs/ARCHITECTURE.md](file:///c:/laragon/www/attendance-and-academic-management-system/docs/ARCHITECTURE.md) — Technical architecture, PSR-4 namespaces, security model, RBAC matrix, and Wasmer deployment setup.
- [CHANGELOG.md](file:///c:/laragon/www/attendance-and-academic-management-system/CHANGELOG.md) — Semantic versioning changelog history.

## Validation

Run focused checks after edits:

```sh
composer run lint
composer run test
```

If a check cannot be run, explain why in the final response.

For local manual testing, `composer run serve` starts the PHP development server at `http://localhost:5000/`.

## Security Notes

### Review remediation constraints (v0.3.171)

These operational notes apply ISO/IEC/IEEE 29148 clarity and traceability principles, with full requirements metadata simplified because this is an agent guide rather than a requirements specification.

- Keep `src/Security/HttpAccessPolicy.php`, `router.php`, and root Apache `.htaccess` aligned: only public entry points/assets are served; private source, dotfiles, configuration, dependencies, and document uploads remain blocked.
- Development API secrets belong in `storage/secrets`; preserve migration/removal of legacy public-directory secret files. Production uses environment secrets.
- Every authenticated API route/method must be mapped in `src/Security/ApiAccessPolicy.php`; unknown mappings fail closed. Enforce permissions for bearer and session users. Session mutations validate CSRF centrally; JSON routes validate content type.
- Offline data uses teacher-owned namespaces. Preserve pending work under its owner on logout, but lock all local access, purge private HTML caches, and compare the owner again at the server before syncing. Do not import unowned legacy queues automatically.
- The readable offline-account cookie is only a local UI/data lock, never authorization. Expire it on logout; other-account and expired documents must not reopen private workspaces.
- Cache only explicitly designated teacher workspaces under the active account; never globally cache authenticated pages or login forms. Keep assets network-first and cache version references synchronized.
- `node tests/browser-security.cjs` executes actual service-worker/offline-storage code with browser API doubles; it supplements, rather than replaces, desktop/mobile PWA checks.

- Production must set `APP_ENV=production`.
- Production must provide strong `API_AUTH_SECRET` and `API_SYNC_SECRET` values.
- Production must set `API_ALLOWED_ORIGIN` to a trusted origin.
- API bearer tokens carry an `api_token_version` claim matched against `users.api_token_version`; every password change or reset path must call `bumpUserApiTokenVersion()` so old tokens are revoked immediately.
- The script-to-permission map in `permissionForScript()` must cover every portal page; `tests/RbacScriptCoverageTest.php` and `tests/CsrfHandlerCoverageTest.php` enforce RBAC map and CSRF coverage and must keep passing when adding pages or handlers.
- Logout validates the session CSRF token from the query string before destroying the session; keep the header logout link tokenized.
- Stateless production hosts such as Wasmer must set `APP_SESSION_DRIVER=database` so PHP sessions are stored in SQL instead of instance-local files.
- Installed PWA login persistence depends on database sessions plus `APP_SESSION_LIFETIME` and `APP_SESSION_IDLE_TIMEOUT`; keep the trusted-device remember option available and checked by default unless the user requests stricter login behavior.
- Hosted MySQL deployments may require `DB_SSL_CA` or `DB_SSL_CA_CONTENT`; keep `DB_SSL_VERIFY_SERVER_CERT` enabled unless a trusted deployment explicitly disables it.
- Protected system-account repair may use `composer run seed:admin`. Fresh or reset database-dashboard setup must use `composer run database:setup-sql` and import the ignored `database/reset_and_setup.local.sql`; it combines reset, canonical schema, baseline data, and Admin/Principal provisioning. Delete it after use and never commit generated hashes.
- `DEFAULT_NEW_USER_PASSWORD` controls the initial password for every role and must be strong, stored only in local/hosting secrets, and replaced by each user during first login.
- Admin may view the protected Principal account but must never create, edit, reset, activate/deactivate, or archive it through web or API user-management paths. Principal profile/password changes remain self-service, with deployment-operator reseeding reserved for recovery.
- API first-login password changes require the temporary token returned by the login endpoint.
- Web login, remember-me auto-login, and API login must force password setup while a user still matches `DEFAULT_NEW_USER_PASSWORD`.
- Shared profile modal updates must persist email/session-visible fields through the profile API and keep password visibility toggles available on password inputs.
- Admin Audit Logs must remain read-only and combine current `activity_logs`, historical `admin_audit_logs`, and `auth_login_logs`. New activity details must be privacy-sanitized and must not store passwords, secrets, tokens, full contact details, or notification message content.
- RBAC permission enforcement is centralized from `functions/bootstrap.php`; keep the script-to-permission map in `functions/app-helpers.php` current when adding protected pages or action handlers.
- Existing AJAX flows may pass CSRF by POST body, query string, or `X-CSRF-Token`; keep `requireCsrfToken()` compatible with all three unless those callers are migrated.
- Browser camera access must remain limited to `teacher_Attendance.php` for QR attendance scanning; all other pages must keep camera access disabled through `Permissions-Policy`.
- Logical security controls are web/PWA-scoped: `config/session.php` owns security headers, no-cache headers for authenticated pages, forwarded-HTTPS detection, and device permission restrictions; `assets/js/main.js` may warn on dirty sensitive forms but must not claim to disable OS shortcuts or startup programs.

## DepEd Forms

- SF1 follows `deped/SF1_Senior_High_School.xlsx`.
- SF1 XLSX import must parse the official combined learner-name cell in columns C:F (`Last Name, First Name, Name Extension, Middle Name`) and the LRN from the merged A:B area.
- Student address storage should preserve SF1 separated columns: `house_street`, `barangay`, `municipality`, and `province`; keep combined `address` for backward compatibility.
- SF1 import may auto-create missing section records from the template grade level, section, and track; do not auto-create subject classes from SF1.
- SF2 follows `deped/SF2_Senior_High_School.xlsx`.
- SF2 XLSX export must write into the official merged header and irregular Mon-Sat day-slot anchors, preserve rows 60-89, and clone the complete template for learner overflow.
- SF2 attendance marks must follow the form legend: blank for present, `X` for absent, and an upper-half block for late/tardy.
- ECR follows the three-term Strengthened SHS `.xlsx` template in `deped/ecr_template.xlsx` or `ECR_TEMPLATE_PATH`.
- SF1, SF2, and ECR imports/exports should only expose CSV and XLSX formats.
- Keep Admin SF1/SF2 export controls in Admin Reports and Teacher SF2 export controls in Teacher Reports.
- Teacher ECR preview, upload, import, and export routes live in `api/routes/12-ecr.php`; keep uploads `.xlsx` only and downloads `.csv`/`.xlsx`.
- Compatible three-term `.xlsm` ECR workbooks in `deped/` may be used as export templates, but exported downloads must remain `.xlsx`.
- ECR XLSX export should prefer an official compatible template and may fall back to a generated workbook if no template is available.
- Do not reintroduce admin portal pages for ECR template management or academic year settings unless explicitly requested.

## Grading And Attendance Workflows

- Grade approval flow is Subject Teacher to Principal verification, Adviser compilation to Principal endorsement, Admin final release, then Student/Parent viewing.
- Subject teacher grade submissions use `grade_approvals.status = submitted`; teacher recall is available only while a submission is pending Principal review.
- Teacher grade activity creation, score editing, finishing, deleting, and restoring must be blocked only while the matching grading period is submitted.
- Principal subject-grade verification uses the legacy-compatible `grade_approvals.status = admin_verified`, which unlocks adviser report-card submission.
- Principal may return verified subject grades to teachers by setting `grade_approvals.status = rejected`; this unlocks teacher editing and resubmission.
- Adviser report-card submissions use the legacy-compatible `report_card_approvals.status = submitted_admin`; adviser recall is allowed only before Principal endorsement.
- Principal endorsement uses `report_card_approvals.status = pending`; Admin alone may change endorsed cards to `approved` for release or `rejected` for correction.
- Student and parent grade/report-card visibility must require `report_card_approvals.status = approved`.
- Only an active Admin may make the final report-card release decision. Release (`report_card_approvals.status = approved`) saves in-app notifications for students, parents, the adviser, subject teachers, and Principal before Web Push is attempted. SMS is not part of the system.
- After final release, teachers may submit corrected subject grades again; affected approved report cards should be marked `rejected` so student and parent portals stop showing stale final grades until approval runs again.
- Teacher-created grade activities must be visible to enrolled students and linked parents before scores are recorded.
- Grade activity creation and score recording should create saved in-app notifications for student and parent recipients.
- Attendance recording from teacher web, API, or sync paths should create saved in-app notifications for student and linked parent recipients.
- Teacher QR attendance classification must remain server-authoritative in `Asia/Manila`; scans at or after the class start plus 15 minutes are late, and QR time classification is limited to the current date.
- Attendance status is limited to `present`, `absent`, and `late`; do not reintroduce Cutting as a status, control, summary, or SF2 mark.

## Chat

- Chat is adviser-parent only.
- Parent role defaults must include `messages.view` and `messages.send` because the Parent portal exposes the Messages page.
- Teacher chat contacts must come from the teacher's advisory section through `classes.teacher_id`.
- Parent chat contacts must be the adviser for each linked student's grade level and section.
- Do not use `class_subjects.teacher_id` to expose subject teachers as parent chat contacts.

## Learning Materials

- New learning-material files must use `BshsAms\Storage\MaterialStorage` and default to `storage/materials`, which maps to Wasmer's durable `/app/storage` volume.
- Material downloads must remain behind teacher ownership or active student enrollment checks; never expose stored files through a direct public URL.
- Keep legacy material-directory lookup for existing files unless a migration explicitly moves every stored material and updates its database record.
- Keep the Teacher Classes primary material picker on `showOpenFilePicker()` with `startIn: 'documents'`, retain an unfiltered standard input for compatibility, and preserve drag-and-drop plus clipboard paste; validate type and size without previewing or reading file contents before upload.

## UI System

- Keep shared visual behavior in `assets/css/main.css` and role-specific refinements in `assets/css/role.css`.
- Prefer common card, table, button, form, badge, modal, header, and chat styles over page-only custom styling.
- UI should stay simple, modern, professional, compact, and consistent across all portals.
- Admin core pages should reuse compact headers, stat cards, filter forms, tables, action buttons, pagination, and modal panel styling from the shared CSS layer.
- Teacher portal pages should reuse compact heroes, KPI cards, filters, attendance status controls, grade tables, action bars, and chat surfaces from the shared CSS layer.
- Modal, helper text, empty-state, and note styles must keep readable contrast on light and dark surfaces.
- Shared settings/profile modals and admin dashboard widgets should use the unified modal, card, chart-panel, list-row, and empty-state styling instead of inline page-only CSS.
- The public school website should stay in `site/index.php` with styling in `assets/css/Site.css`, including public-site scoped loading overlay styles; `assets/uploads/` must not contain public website copies.
- Authentication pages should use the shared `assets/css/auth.css` visual system; keep login, forgot password, reset password, and first-login password setup consistent while preserving CSRF, rate limit, token, and password-guidance behavior.
- Global page loading and navigation transitions should stay in `assets/js/main.js` with styling in `assets/css/main.css`; keep loader behavior accessible, compact, dark-mode ready, and reduced-motion compatible.
- Multi-content workspace pages should provide compact in-page navigation so users can jump between major sections without manually scrolling through the whole page.
- Admin hero, metric, and approval-card text must keep readable contrast and enough icon/title spacing on both light and dark surfaces.

## PWA

- PWA metadata should stay in `assets/manifest.json`.
- The active service worker should stay root-scoped at `sw.js` so it can cover auth, role portals, and the public site.
- Keep `assets/push-sw.js` only as a compatibility bridge for older browser registrations.
- Do not change service worker cache paths back to legacy `src/` paths.
- Installed app icons should keep the school seal visible inside safe padding for normal, maskable, and Apple touch icon use; regenerate icon assets with `php scripts/generate_pwa_icons.php` when the source school seal changes.
- Installed PWA device notifications require browser Push API subscription code, saved `push_subscriptions`, root `sw.js` push handling, and configured `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, and `PUSH_VAPID_SUBJECT`.
- The shared Settings modal controls browser push subscription; enabling push should request permission and save the current device subscription, while disabling it should remove the browser subscription.
- Settings should expose an explicit user-initiated permission action while browser permission is undecided and explain how to unblock notifications when permission is denied.
- Web Push delivery depends on PHP `curl` and `openssl`; keep these Composer platform requirements and log failed push sends for deployment debugging.
- Web Push delivery uses `minishlink/web-push` with standard Base64URL VAPID keys generated by `composer run push:keys`; do not restore the custom PEM-based sender.
- Saved notification links should be root-relative and use `school_announcement_ID` or `class_announcement_ID` source keys to target exact announcement-card anchors. Mutation APIs must return the authoritative unread count.
- Core user events must use `appDispatchNotification()` so in-app records are saved before browser Web Push and legacy mobile delivery are attempted; push failures must not roll back the primary event.
- Teacher attendance offline mode queues submissions in `localStorage` and retries them against `teacher_Action.php?action=submit_attendance` when connectivity returns.
- Test install/offline behavior on desktop and mobile before production release.
- Portal pages must load shared JavaScript through `appAssetPath()`; keep JavaScript and CSS network-first in `sw.js` with cached offline fallback so installed PWAs do not retain obsolete notification behavior.

## Wasmer Deployment

- Wasmer Edge config should stay in `app.yaml`, package config in `wasmer.toml`, and PHP settings in `config/wasmer/php.ini`.
- GitHub deployment should use `.github/workflows/wasmer-deploy.yml`.
- The workflow must install production Composer dependencies (`composer install --no-dev`) before `wasmer deploy` because `vendor/` is not committed.
- Keep `.wasmerignore` comprehensive (excluding docs, `resources/`, `database/`, `scripts/`, `examples/`, `storage/`, `assets/uploads/`, and non-runtime files) to minimize upload payload and prevent GraphQL request timeouts on package upload.
- Keep bounded retries around only the external `wasmer deploy` command so transient registry timeouts do not rerun or conceal validation, lint, and test failures.
- Do not commit real Wasmer owner names, tokens, database credentials, API secrets, or production URLs unless they are intentionally public.
- Configure GitHub secrets for `WASMER_TOKEN`, `WASMER_OWNER`, `WASMER_APP_NAME`, `APP_PUBLIC_BASE_URL`, `DEFAULT_NEW_USER_PASSWORD`, `RESEND_API_KEY`, and `RESEND_FROM_EMAIL`; use `WASMER_APP_NAME=balingasagshs` and `APP_PUBLIC_BASE_URL=https://balingasagshs.wasmer.app` for the school deployment.
- Configure Wasmer app secrets for Wasmer Attached Database credentials (`DB_PASS` or Wasmer-provided `DB_PASSWORD` are both supported), optional `DB_SSL_CA` or `DB_SSL_CA_CONTENT`, `APP_SESSION_DRIVER=database`, `API_AUTH_SECRET`, `API_SYNC_SECRET`, `DEFAULT_NEW_USER_PASSWORD`, Resend OTP email secrets, `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, `PUSH_VAPID_SUBJECT`, and optional SMTP credentials.
- `DEFAULT_NEW_USER_PASSWORD` controls first-login provisioning for every role, including the protected Admin and Principal accounts.
- API first-login password changes require the temporary token returned by the login endpoint.
- Web login, remember-me auto-login, and API login must force password setup while a user's password matches `DEFAULT_NEW_USER_PASSWORD`.
- Shared profile modal updates must persist email/session-visible fields through the profile API and keep password visibility toggles available on password inputs.
- Admin Audit Logs must remain read-only and combine current `activity_logs`, historical `admin_audit_logs`, and `auth_login_logs` to show actor, action, target, details, and timestamp records.
- RBAC permission enforcement is centralized from `functions/bootstrap.php`; keep the script-to-permission map in `functions/app-helpers.php` current when adding protected pages or action handlers.
- Existing AJAX flows may pass CSRF by POST body, query string, or `X-CSRF-Token`; keep `requireCsrfToken()` compatible with all three unless those callers are migrated.
- Logical security controls are web/PWA-scoped: `config/session.php` owns security headers, no-cache headers for authenticated pages, forwarded-HTTPS detection, and device permission restrictions; `assets/js/main.js` may warn on dirty sensitive forms but must not claim to disable OS shortcuts or startup programs.
- Keep Composer `platform-check` disabled for Wasmer unless the runtime is confirmed to provide a 64-bit PHP build.
- Treat Wasmer app instances as stateless; use configured volumes for `/app/storage` and `/app/assets/uploads`, and keep durable school records plus active PHP sessions in Wasmer Attached Database.
- Production PWA deployments should use database sessions and PWA-friendly session lifetimes, for example `APP_SESSION_LIFETIME=86400` and `APP_SESSION_IDLE_TIMEOUT=86400`.
- Password reset uses 6-digit OTP codes sent through Resend when configured, with SMTP fallback. Store only hashed reset codes in `auth_password_resets.token_hash`.
- `database/schema.sql` is the single committed source for MySQL structure and non-secret baseline data. `database/reset_database.sql` is the only other committed SQL source. Both omit database-selection statements and remain hosted-MySQL-compatible.
