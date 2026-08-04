# Changelog

Project changes follow Semantic Versioning: MAJOR for breaking changes, MINOR for backward-compatible features, and PATCH for backward-compatible fixes.

## v0.1.38 — 2026-08-04

### Fixed

- Updated top loading progress bar to use `100vw` (viewport width) so it physically sweeps across the full screen and touches the right display edge before fading out.
- Adjusted completion animation timing (`320ms` width sweep + `320ms` hold at `100vw`) to ensure the bar remains visible touching the right screen border before triggering fade out.

## v0.1.37 — 2026-08-04


### Fixed

- Added `sessionStorage` navigation state persistence (`app_page_navigating`) so top progress loading bars seamlessly paint at 75% on new page load and sweep to **100% full** before fading out.
- Injected critical progress bar CSS and early head navigation painter into `pwaHeadHtml()` in `config/constants.php` for instant, flicker-free rendering across multi-page navigation.

## v0.1.36 — 2026-08-04


### Fixed

- Resolved top loading progress bar flickering by ensuring 0ms instant click/submit response and guaranteed 100% full progress hold before fading out.
- Refined progress bar CSS transitions in `assets/css/main.css` for smooth cubic-bezier width expansion and flicker-free page transitions.

## v0.1.35 — 2026-08-04


### Added

- Added single merged 1-click database setup script `database/wasmer_full_setup.sql` combining complete schema definitions, performance composite indexes, system RBAC roles/permissions, and first admin account seed (`A341227-1` / `password`).

## v0.1.34 — 2026-08-04


### Fixed

- Made `database/performance_indexes.sql` compatible with MySQL 5.7, 8.0, TiDB, and MariaDB using a universal `AddIndexIfNotExist` stored procedure, eliminating MySQL Error 1064.
- Added browser migration page to `database/run_performance_indexes.php` allowing admins to execute database index migrations directly from their web browser.

## v0.1.33 — 2026-08-04


### Fixed

- Made `database/performance_indexes.sql` idempotent using `DROP INDEX IF EXISTS` statements before creating indexes to resolve MySQL Error 1061 (`Duplicate key name`).
- Added automated `database/run_performance_indexes.php` PHP runner script that inspects existing table indexes via PDO before executing index additions.

## v0.1.32 — 2026-08-04


### Fixed

- Corrected column reference `class_id` on `grade_items` and target table `grade_item_scores` in `database/performance_indexes.sql` and `database/schema_tidb.sql` to resolve MySQL Error 1072.

## v0.1.31 — 2026-08-04


### Added

- Added `database/performance_indexes.sql` migration script containing composite indexes for `users`, `classes`, `enrollments`, `attendance`, `grades`, `grade_items`, and `grade_scores`.
- Updated `database/schema_tidb.sql` base schema to include performance composite indexes.
- Added `PerformanceIndexTest.php` to validate database index definitions and `SchemaCache` memory operations.

## v0.1.30 — 2026-08-04


### Added

- Added `GradeCalculatorTest.php` to unit-test subject category weighting, DepEd transmutation, SF9 descriptors, grading system term counts, and combined subject calculations.
- Added `EcrParserTest.php` to validate ECR template resolution, fallback XLSX sheet reading, and term string normalization.
- Added `GradeApprovalFlowTest.php` to test multi-stage approval statuses (`submitted`, `admin_verified`, `rejected`, `submitted_admin`, `approved`) and grade locking rules.
- Added `Sf2ExporterTest.php` to test SF2 template resolution and fallback sheet reading.
- Added favicon, shortcut icon, and PWA logo tab icons to public website (`site/index.php`) and app portals.

### Fixed

- Made `SshsGradeCalculator::normalizeTerm` case-insensitive for reliable term mapping.
- Corrected default admin password bcrypt hash in `database/seed_admin.sql`.
- Updated `database/seed_admin.php` to update password of existing admin accounts rather than skipping them.
- Updated default database name fallback in `config/db.php` and `.env.example` to `balingasagshs`.
- Resolved dynamic asset URL resolution in `pwaHeadHtml()` using `appAssetPath()`.


## v0.1.29 — 2026-08-04


### Changed

- Updated Wasmer validation and documentation to accept either `DB_PASS` or Wasmer-provided `DB_PASSWORD` for MySQL database connections.
- Documented the safe migration path from TiDB to Wasmer MySQL while keeping TiDB as a backup.
- Bumped the Wasmer package version to `0.1.29`.

## v0.1.28 — 2026-08-04

### Fixed

- Preserved the official SF1 XLSX subheader row and started exported learner rows at row 12 to match the imported DepEd sample workbook.
- Bumped the Wasmer package version to `0.1.28`.

## v0.1.27 — 2026-08-03

### Fixed

- Removed raw Wasmer app config output from the diagnostics workflow to avoid exposing environment secrets in GitHub Actions logs.
- Bumped the Wasmer package version to `0.1.27`.

## v0.1.26 — 2026-08-03

### Added

- Added a manual Wasmer diagnostics workflow to inspect CLI identity, app state, committed app config, and live hostname response without modifying deployments.
- Bumped the Wasmer package version to `0.1.26`.

## v0.1.25 — 2026-08-03

### Fixed

- Committed the public Wasmer app target in `app.yaml` so Wasmer Git deployments no longer read placeholder owner or URL values.
- Changed CI validation to verify committed Wasmer config matches GitHub deployment secrets.
- Bumped the Wasmer package version to `0.1.25`.

## v0.1.24 — 2026-08-03

### Fixed

- Changed the Wasmer validation workflow to install Composer development dependencies so lint and test commands can find PHPCS and PHPUnit.
- Bumped the Wasmer package version to `0.1.24`.

## v0.1.23 — 2026-08-03

### Changed

- Replaced the GitHub Actions Wasmer CLI deploy step with deployment-readiness validation for Wasmer Git-connected deployments.
- Kept production dependency installation, Wasmer config input checks, linting, and tests in CI while avoiding the disabled-app CLI deploy path.
- Bumped the Wasmer package version to `0.1.23`.

## v0.1.22 — 2026-08-02

### Changed

- Added a generated deployment marker to Wasmer deploys so GitHub Actions only reports success when the live site serves the current commit.
- Changed disabled-app deploy handling to verify the live marker instead of passing a stale deployment as successful.
- Bumped the Wasmer package version to `0.1.22`.

## v0.1.21 — 2026-08-02

### Changed

- Rolled back experimental Wasmer private-package metadata while keeping the earlier package-publishing deploy flow.
- Updated the disabled-app deploy error message to avoid incorrectly blaming GitHub secrets when Wasmer app info resolves correctly.
- Bumped the Wasmer package version to `0.1.21`.

## v0.1.20 — 2026-08-02

### Changed

- Added Wasmer deploy diagnostics for CLI identity, generated app/package metadata, app info, and package dry-run output before deployment.
- Bumped the Wasmer package version to `0.1.20`.

## v0.1.19 — 2026-08-02

### Changed

- Marked the Wasmer package as private and made the deploy workflow preserve that setting to match Wasmer's package visibility requirements.
- Bumped the Wasmer package version to `0.1.19`.

## v0.1.18 — 2026-08-02

### Changed

- Rewrote the Wasmer package name during GitHub Actions deploy to use the active `WASMER_OWNER/WASMER_APP_NAME` target instead of the stale local `bshs-ams` package name.
- Bumped the Wasmer package version to `0.1.18`.

## v0.1.17 — 2026-08-02

### Changed

- Removed package publishing from the Wasmer deploy workflow so GitHub Actions deploys the active app target without modifying the package publish record.
- Bumped the Wasmer package version to `0.1.17`.

## v0.1.16 — 2026-08-02

### Changed

- Required the `WASMER_APP_NAME` GitHub secret during deploy configuration to avoid silently targeting a stale default app.
- Added non-secret Wasmer deploy target logging and a clearer disabled-app failure message in GitHub Actions.
- Bumped the Wasmer package version to `0.1.16`.

## v0.1.15 — 2026-08-02

### Fixed

- Changed SF1 XLSX export to preserve and fill the official DepEd SF1 template format used by SF1 imports.
- Added fallback XLSX parser support for inline string cells written during template-safe exports.
- Bumped the Wasmer package version to `0.1.15`.

## v0.1.14 — 2026-08-02

### Fixed

- Added a Wasmer-safe XLSX writer for SF1 exports so downloads do not fail through ZipStream CRC handling.
- Prevented SF1 and SF2 download actions from leaving the top navigation progress bar stuck.
- Bumped the Wasmer package version to `0.1.14`.

## v0.1.13 — 2026-08-02

### Removed

- Removed the duplicate SF1 export shortcut from Admin Enrollments because SF1 export remains available from Admin Reports.
- Bumped the Wasmer package version to `0.1.13`.

## v0.1.12 — 2026-08-02

### Fixed

- Added a reusable responsive filter-form pattern for Bootstrap filter rows across admin, teacher, student, and parent pages.
- Fixed Manage Classes filter actions so Apply and Reset no longer overflow the card at laptop and tablet widths.
- Bumped the Wasmer package version to `0.1.12`.

## v0.1.11 — 2026-08-02

### Fixed

- Improved shared mobile responsiveness for page headers, action bars, filters, modal footers, dropdown text, and wide tables.
- Bumped the Wasmer package version to `0.1.11`.

## v0.1.10 — 2026-08-02

### Fixed

- Added a service-worker update handshake so deployed PWA updates activate and reload once instead of requiring manual hard refreshes across app pages.
- Bumped the service-worker cache to `bshs-ams-v5` and the Wasmer package version to `0.1.10`.

## v0.1.9 — 2026-08-02

### Changed

- Cached shared header profile, settings, teacher-role, and RBAC permission reads for short intervals to reduce repeated database work during page navigation.
- Updated the service worker to cache static assets only and always fetch dynamic PHP pages from the network.
- Bumped the Wasmer package version to `0.1.9` for the next immutable deployment.

## v0.1.8 — 2026-08-01

### Fixed

- Added a pure-PHP XLSX ZIP reader fallback for SF1 imports when Wasmer PHP does not provide `ZipArchive`.
- Relaxed SF1 XLSX runtime checks to allow `ZipArchive`, procedural zip, or zlib-based fallback readers.
- Bumped the Wasmer package version to `0.1.8` for the next immutable deployment.

## v0.1.7 — 2026-08-01

### Fixed

- Made app-only JavaScript initialization safe on the public site so the top navigation progress bar can run there too.
- Bumped the Wasmer package version to `0.1.7` for the next immutable deployment.

## v0.1.6 — 2026-08-01

### Changed

- Replaced looping top progress animation with one-way controlled progress that advances toward 92% and completes once the next page is ready.
- Bumped the Wasmer package version to `0.1.6` for the next immutable deployment.

## v0.1.5 — 2026-08-01

### Fixed

- Removed stale public-site loader CSS that hid site content after the full-screen loader JavaScript was removed.
- Bumped the Wasmer package version to `0.1.5` for the recovery deployment.

## v0.1.4 — 2026-08-01

### Added

- Added opt-in request timing logs through `APP_PERF_LOG=1` to identify slow server-rendered pages without logging sensitive data.
- Added a slim non-blocking top progress bar for navigation and form submissions.

### Changed

- Deferred automatic PWA push re-registration so it does not compete with initial page rendering.
- Bumped the Wasmer package version to `0.1.4` for the next immutable deployment.

## v0.1.3 — 2026-08-01

### Removed

- Removed the global full-screen page loader and sidebar navigation delay so pages render and navigate immediately.

### Changed

- Bumped the Wasmer package version to `0.1.3` for the next immutable deployment.

## v0.1.2 — 2026-08-01

### Fixed

- Prevented GitHub Actions from failing Wasmer deploys when deployment succeeds but Wasmer's post-deploy reachability wait times out.
- Bumped the Wasmer package version to `0.1.2` for the next immutable deployment.

## v0.1.1 — 2026-08-01

### Fixed

- Made page content render immediately and delayed the navigation loader so page transitions feel faster.
- Restored the page loader spinner animation during navigation and form submissions.
- Added a visible SF1 export shortcut from Admin Enrollments to the Admin Reports SF1 export panel.
- Improved online SF1 XLSX import errors for upload-limit, zip, and SimpleXML server issues.
- Restored Excel serial birthdate parsing for official SF1 workbooks.
- Restored focused Composer lint/test verification for deployment-critical files.
- Enabled the Wasmer deploy workflow for `main` and `instructor`, and bumped the Wasmer package version to `0.1.1`.
## v0.1.30 - 2026-08-04

- Fixed: Merged RBAC tables into `database/schema_tidb.sql` so hosted MySQL imports only need one schema file.
- Added: Created `database/seed_admin.sql` for dashboard-only first admin seeding when PHP CLI seeding is unavailable.
