# Changelog

Project changes follow Semantic Versioning: MAJOR for breaking changes, MINOR for backward-compatible features, and PATCH for backward-compatible fixes.

## v0.1.5 — 2026-08-01

### Fixed

- Fixed Wasmer/Anybuild Composer install failures by removing source-folder autoload scans and loading project files through `functions/bootstrap.php`.

## v0.1.4 — 2026-08-01

### Fixed

- Made page content render immediately while delaying navigation loader display for faster-feeling page transitions.
- Restored a circular animated loading indicator.

## v0.1.3 — 2026-08-01

### Fixed

- Reduced perceived page-load delay by making the global loader non-blocking and removing artificial navigation delays.

## v0.1.2 — 2026-08-01

### Changed

- Replaced broad PSR2 linting with focused deployability checks to avoid legacy style-only churn.
- Removed deprecated procedural ZIP fallback code from the XLSX parser now that `ext-zip` is required.

### Added

- Added PHPUnit smoke tests for SF1 birthdate parsing and material storage path helpers.

## v0.1.1 — 2026-08-01

### Fixed

- Improved SF1 import failure messages for online deployments, including upload limit and server extension issues.
- Added XLSX runtime checks for PHP zip and SimpleXML support before parsing official SF1 workbooks.
- Normalized Excel serial birthdates from official SF1 `.xlsx` files during import.
