# Changelog

Project changes follow Semantic Versioning: MAJOR for breaking changes, MINOR for backward-compatible features, and PATCH for backward-compatible fixes.

## v0.1.1 — 2026-08-01

### Fixed

- Improved SF1 import failure messages for online deployments, including upload limit and server extension issues.
- Added XLSX runtime checks for PHP zip and SimpleXML support before parsing official SF1 workbooks.
- Normalized Excel serial birthdates from official SF1 `.xlsx` files during import.

