# Project Review — Balingasag SHS AMS

**Date:** 2026-08-24
**Scope:** Full-project read-only review of architecture, security, tests, database schema, CI/CD, and documentation at commit `8909713` (`v0.3.88`).
**Working tree state:** Clean except untracked `docs/DEMO_TEMP_SETUP.md`.
**Verification status:** `composer run lint` / `composer run test` were NOT RUN during this review (read-only session; PHPUnit writes cache files). Both are wired into the deploy workflow (`.github/workflows/wasmer-deploy.yml`) and run there on every push to `main`.

---

## Overview

| Aspect | Summary |
|---|---|
| Stack | Plain PHP 8.2+ (no framework), MySQL/MariaDB via PDO, PHPUnit 11, PHPCS |
| Front end | PWA (root-scoped `sw.js`, manifest, Web Push), shared CSS layer |
| Hosting | Wasmer Edge (stateless instances + Attached Database), Laragon locally |
| Size | ~18 PSR-4 classes in `src/`, 5 role portals, 13 API route files, 23 test classes |
| Version | `v0.3.88` (2026-08-24), disciplined semver changelog |

---

## Strengths

1. **Documentation discipline** — `README.md`, `AGENTS.md`, `docs/PRODUCT_SPEC.md` (ISO/IEC/IEEE 29148-style REQ-001..REQ-010 with acceptance criteria), `docs/ARCHITECTURE.md`, and a maintained semver `CHANGELOG.md`.
2. **Centralized security model**
   - RBAC enforced from `functions/bootstrap.php` via `enforceScriptPermission()` on every web request; script→permission map lives in `functions/app-helpers.php`.
   - Session CSRF tokens compared with `hash_equals()` (`functions/app-helpers.php:51-59`), accepting POST body, query string, or `X-CSRF-Token`.
   - DB-backed rate limiting with hashed identifiers and lockout windows (`auth/login.php:44-76`, `api/routes/02-auth.php`).
   - HMAC-SHA256 signed API bearer tokens keyed by `API_AUTH_SECRET`; CORS fails closed in production.
   - Security headers centralized in `config/session.php`: CSP, HSTS, COOP/CORP, no-cache for authenticated pages, camera locked to `teacher_attendance.php` via Permissions-Policy.
   - Production guards reject known-default secrets and default passwords.
3. **Clean domain layer** — 18 PSR-4 `BshsAms\` classes under `src/` (Export/SF1/SF2/SF5/SF9/ECR, Grade, Xlsx, Storage, Notification, Schedule) with legacy `class_alias()` shims in `functions/` for backward compatibility.
4. **CI/CD** — deploy workflow validates required GitHub secrets, runs lint + tests, prunes to production dependencies, then deploys with bounded retries (5 attempts); `.wasmerignore` minimizes payload against GraphQL timeouts.
5. **Test suite exists** — 23 test classes covering SF1 parsing, SF2 export, ECR parser, QR late classification (Asia/Manila server-authoritative), grade calculator and approval flow, password reset, SMS, Web Push, material storage, PWA asset freshness, security headers, and deployment workflow.

---

## Issues Found (prioritized)

Status as of v0.3.92 remediation: Issues **1, 2, 3, 5, 7, and 8** are fixed; Issue **4** was corrected during planning — role/status are already re-validated against the database on every API request (`api/apisupport.php`), so only stolen-token revocation remained, which is now implemented via `users.api_token_version`. Issues **6 and 9** remain deferred (see Follow-Ups).

| # | Severity | Issue | Where | Status |
|---|----------|-------|-------|--------|
| 1 | High | 13 tables defined twice (~180 redundant lines). Harmless due to `IF NOT EXISTS`, but edits to one copy can silently miss the other. The first copy of `rbac_role_permissions` also carried a stray copy-pasted index (`idx_admin_audit_logs_created_at`) instead of its proper UNIQUE KEY + FK constraints. | `database/schema.sql` | Fixed in v0.3.90 |
| 2 | High | RBAC map drift: scripts missing from `permissionForScript()` return `''` and silently pass with no permission check (`admin.php`, `teacher.php`, `Student_QR.php` were unmapped). | `functions/app-helpers.php` | Fixed in v0.3.90 |
| 3 | Medium-High | CSRF is caller-opt-in: `requireCsrfToken()` protects only endpoints that explicitly call it. Audit found all mutating handlers gated except logout. | All portal `*_Action.php` handlers | Fixed in v0.3.90 |
| 4 | Medium | API bearer tokens valid up to 7 days with no revocation mechanism for stolen tokens. Correction to the original finding: `apiAuthUser()` already re-validates `role` and `status` per request, so role changes take effect immediately; the real gap is revocation only. | `api/apisupport.php` | Fixed in v0.3.90 |
| 5 | Medium | Runtime DDL scattered across ~14 files (~30 `CREATE TABLE IF NOT EXISTS` sites), duplicating schema definitions in a third place beyond `schema.sql` and seeder files. | `api/index.php`, `functions/app-helpers.php`, portals, even `src/Notification/SmsService.php` | Fixed in v0.3.92 (20 pure duplicates removed; SQLite fixtures, documented RBAC bootstrap, and side-effect migrations kept; guard test added) |
| 6 | Medium | God files: `teacher_Action.php` (3,181 lines / ~26 switch actions), `admin_Users.php` (1,774), `EcrExporter.php` (1,126), `app-helpers.php` (1,124 / 66 global functions), `admin_Reports.php` (1,105), `admin_Enrollments.php` (1,097). | Portals, `src/Export`, `functions` | Deferred |
| 7 | Medium | Duplicated logic: fuzzy section-matching SQL fragment copy-pasted across 13 files / 34 occurrences in two shapes (column-vs-placeholder and column-vs-column JOINs); parallel `apiValidPersonName` / `apiHasMinimumLetters` / `apiNormalizeDate` / `apiGetTeacherRoles` re-implement existing globals instead of calling them. Correction: `apiParseSchedule` is not a duplicate — it returns a differently shaped result via a different `ScheduleParser` method. | 13 files, `api/index.php`, `api/apisupport.php`, portals | Fixed in v0.3.91 |
| 8 | Low-Med | Thin verification tooling: phpcs.xml runs syntax checks on only 4 files; many tests grep source text rather than execute code; web rate limiting degrades silently if `rate_limits` table is absent. | `phpcs.xml`, `tests/`, `phpunit.xml`, `auth/login.php` | Partially fixed in v0.3.90 (full-codebase syntax lint, RBAC/CSRF guard tests, rate-limit warning) |
| 9 | Low | Naming inconsistency across portals: snake_case (`admin/`, `teacher/`) vs PascalCase (`student/`, `parent/`) vs kebab-case (`auth/`); namespaced and legacy-global class usage coexist inconsistently at 6 call sites. | Repo-wide | Deferred |

---

## Recommended Follow-Ups

Small, independently verifiable increments, ordered by risk reduction per effort:

1. ~~De-dupe `database/schema.sql`~~ — done in v0.3.90.
2. ~~Add RBAC map and CSRF coverage guard tests~~ — done in v0.3.90 (`tests/RbacScriptCoverageTest.php`, `tests/CsrfHandlerCoverageTest.php`).
3. ~~Extract the fuzzy section-match SQL fragment and de-duplicate API/global helper pairs~~ — done in v0.3.91 (`sectionMatchSql()` + `tests/SectionMatchSqlTest.php`).
4. ~~Consolidate runtime DDL into a documented migration path~~ — done in v0.3.92 (`tests/RuntimeDdlGuardTest.php` + AGENTS.md convention; SQLite fixtures and side-effect migrations retained by design).
5. **Later:** split god files (#6); document the portal naming convention instead of renaming files (#9).

---

## Conclusion

The project is in good health overall: strong documentation habits, a genuinely centralized security posture, a clean PSR-4 domain layer, working CI/CD, and a real test suite. The outstanding debt is concentrated in schema duplication, two mechanically closable enforcement gaps (RBAC map drift, opt-in CSRF), and a handful of oversized files that will make future maintenance progressively harder if left unsplit.
