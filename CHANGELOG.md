# Changelog

Project changes follow Semantic Versioning: MAJOR for breaking changes, MINOR for backward-compatible features, and PATCH for backward-compatible fixes.

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
