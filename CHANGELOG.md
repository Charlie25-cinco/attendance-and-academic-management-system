# Changelog

Project changes follow Semantic Versioning: MAJOR for breaking changes, MINOR for backward-compatible features, and PATCH for backward-compatible fixes.

## v0.3.66 — 2026-08-20

### Fixed

- Added dynamic `BASE_PATH` calculation and `resolvePath()` helper to `sw.js` so offline shell assets and fallback navigation routes resolve seamlessly across subfolder deployments (e.g. Laragon `/attendance-and-academic-management-system/`) and root domains alike.
- Converted hardcoded root asset paths in `offline.html` to relative URLs (`assets/...`) to guarantee reliable asset loading in any directory hierarchy.

## v0.3.65 — 2026-08-20

### Added

- Created dedicated PWA offline shell [`offline.html`](file:///c:/laragon/www/attendance-and-academic-management-system/offline.html) with branded BSHS styling, live connection pulse indicator, automatic reconnection reload, and local storage offline attendance sync queue inspection.
- Pre-cached `/offline.html` in `sw.js` under cache `bshs-ams-v14` and updated navigation fetch routing to serve cached pages or fall back gracefully to the offline shell instead of a raw plaintext string.

## v0.3.64 — 2026-08-18

### Added

- Added student **Reference Code**, **LRN**, and **Grade & Section** badges to `parent/Parent_Progress.php` and `parent/Parent_Report_Card.php` header banners and child switcher pills for clear student identification in the Parent Portal.

### Changed

- Removed the unused **Email Notifications** toggle from the Settings modal in `includes/modals.php` and `assets/js/main.js`, keeping the modal focused exclusively on **Dark Mode** and **Push Notifications**.

## v0.3.63 — 2026-08-18

### Added

- Added End-to-End Workflow Integration Test Suite in `tests/WorkflowIntegrationTest.php` validating:
  - Full multi-role Grade Approval and Publication lifecycle (Subject Teacher score entry $\rightarrow$ Submission $\rightarrow$ Admin Verification $\rightarrow$ Adviser Report Card Submission $\rightarrow$ Admin Approval $\rightarrow$ Student/Parent visibility unlock and SMS alerts).
  - Routine Attendance lifecycle (Teacher attendance recording $\rightarrow$ In-app notification creation for students and linked parents $\rightarrow$ KPI summary metrics $\rightarrow$ zero routine attendance SMS guard).
  - Database-level unique constraint invariants for enrollments, class-subjects, and grade records.

## v0.3.62 — 2026-08-18

### Fixed

- Fixed notification dropdown toggle state in `assets/css/main.css` by hiding `.header-notification-menu` by default (`display: none`) and only applying `display: flex !important` when Bootstrap's `.show` class is active, allowing the menu to open and close normally on click.

## v0.3.61 — 2026-08-18

### Fixed

- Locked the notification dropdown header ("Notifications | Read all | Delete all") and footer ("View all notifications") in `includes/header.php` and `assets/css/main.css` so they remain permanently fixed while only the notification items scroll in the middle.
- Fixed `.header` mobile and tablet padding in `assets/css/main.css` across responsive breakpoints ($\le 991.98\text{px}$) from `0px` left padding to balanced `14px-16px` padding, ensuring the mobile hamburger menu button no longer clings tightly to the left edge of the screen.

## v0.3.60 — 2026-08-18

### Fixed

- Wrapped the header notification button and dropdown in `includes/header.php` inside a dedicated `.dropdown` container, eliminating improper Popper.js positioning calculations and preventing the menu from shifting excessively to the left on mobile viewports.
- Enhanced notification dropdown styling in `assets/css/main.css` with responsive mobile positioning, `overscroll-behavior: contain`, `-webkit-overflow-scrolling: touch`, and custom thin scrollbars to prevent scrolling conflicts.
- Standardized all 36 portal pages across `admin/`, `teacher/`, `student/`, and `parent/` to load CSS and JavaScript assets using `appAssetPath()`, guaranteeing automatic `?v=timestamp` cache-busting across all non-dashboard pages.
- Bumped root Service Worker cache to `bshs-ams-v13` to invalidate stale browser asset caches.

## v0.3.59 — 2026-08-18

### Changed

- Removed `PDO::ATTR_PERSISTENT` in `BshsAms\Database\Database` to optimize connection lifecycle for stateless Wasmer edge container instances.
- Aligned technical architecture documentation in `docs/ARCHITECTURE.md` with current codebase implementation (corrected session table name to `app_sessions`, documented `Notification` and `Storage` namespaces).
- Added database-level `UNIQUE` constraints in `database/schema.sql` on `class_subjects(class_id, subject_id)`, `enrollments(student_id, class_id, academic_year)`, and `grades(student_id, class_subject_id, term, academic_year)` to prevent duplicate records.

### Security

- Added `api/.api_secret`, `api/.api_sync_secret`, `config/push_keys.php`, and `config/push_keys.json` to `.gitignore` to prevent accidental staging or commit of runtime secret files.

## v0.3.58 — 2026-08-18

### Added

- Added PhilSMS notification provider integration via `BshsAms\Notification\SmsService` supporting mobile text alerts via `https://app.philsms.com/api/v3/sms/send`.
- Added `smsNotifyGradePublication()` triggered exclusively upon official Admin approval and release of student report cards (`report_card_approvals.status = 'approved'`).
- Added Philippine mobile number normalization supporting `09XXXXXXXXX`, `+639XXXXXXXXX`, `639XXXXXXXXX`, and hyphenated/spaced formats.
- Added `sms_logs` database table in `database/schema.sql` to track SMS deliveries, status, and gateway responses.
- Added unit and integration tests in `tests/SmsServiceTest.php`.
- Pre-cached `networkSync.js` in `sw.js` under cache version `bshs-ams-v12`.

### Changed

- Updated `config/constants.php` to default SMS provider to PhilSMS and delegate SMS sending to `BshsAms\Notification\SmsService`.
- Removed legacy attendance SMS triggers in `teacher/teacher_Action.php` so SMS is exclusively sent upon official Admin grade approval.
- Modernized `assets/js/networkSync.js` to process offline queues via asynchronous `fetch()` without locking the browser UI thread.
- Updated `teacher/teacher_Attendance.php` to load `networkSync.js` via `appAssetPath()`.

### Fixed

- Fixed notification text overlapping in header dropdown menu across mobile and desktop screens by removing nowrap constraints and adding word-break/flex-shrink rules.
- Enhanced popup toast notifications (`.notification-toast`) with responsive mobile screen widths, word-break wrapping, and dark mode contrast.
- Fixed student reference code visibility on the Parent Dashboard by featuring the selected child's reference code / LRN prominently in the hero chips, child switcher buttons, and monitoring header.

## v0.3.57 — 2026-08-11

### Added

- Added in-app notification and Web Push delivery for chat messages between teachers and parents via `appDispatchNotification()`.
- Added dynamic unread message count badges to the "Messages" link in the sidebar navigation for teachers and parents.

## v0.3.56 — 2026-08-11

### Fixed

- Fixed HTTP 500 fatal error in `teacher_Reports_Action.php` during CSV export by removing duplicate declarations of `normalizeInt()` and `normalizeText()` which collided with `functions/app-helpers.php`.
- Updated aggregate report ordering in `functions/report-aggregates.php` to use explicit `student_name ASC` and `c.class_name ASC` aliases for consistent cross-database execution.

## v0.3.55 — 2026-08-11

### Fixed

- Fixed Top Attendance, Class Summary, and At-risk Attendance aggregate calculation and preview across Admin and Teacher portals by removing rigid enrollment subquery constraints that prevented recorded attendance from populating report rows.
- Fixed Report Notes journal responses by ensuring all non-export endpoints in `admin_Reports_Action.php` and `teacher_Reports_Action.php` return structured JSON payloads.
- Added automatic `report_type` `ENUM` migration in `ensureReportNotesTables` so existing installations properly support `top_attendance`, `class_summary`, and `at_risk` note types.
- Enhanced `readJsonResponse` in `admin_Reports.php` and `teacher_Reports.php` to handle response format normalization and surface server-provided error messages clearly.

## v0.3.54 — 2026-08-11

### Fixed

- Fixed Wasmer Edge deployment `operation timed out` errors on `https://registry.wasmer.io/graphql` by optimizing the deployment package payload from >65 MB to <10 MB.
- Expanded `.wasmerignore` to exclude non-runtime documentation, database schemas/seeds, CLI scripts, samples, dynamic storage/upload volumes, and macro workbooks (`resources/`, `database/`, `scripts/`, `examples/`, `storage/`, `assets/uploads/`, `deped/*.xlsm`).
- Added a production dependency pruning step (`composer install --no-dev`) prior to `wasmer deploy` in `.github/workflows/wasmer-deploy.yml`.
- Recompressed `deped/ecr_template.xlsx` using standard Zip Deflate, reducing template file size from 7.82 MB to 1.38 MB while preserving all worksheet data, formulas, and styles.

## v0.3.53 — 2026-08-11

### Fixed

- Fixed "Report notes returned an invalid response." error by ensuring `admin_Reports_Action.php` and `teacher_Reports_Action.php` clear output buffers and return clean JSON via `adminReportsJsonExit` and `teacherReportsJsonExit`.
- Fixed CSV file downloads for "Top Attendance", "Class Summary", and "At-risk Attendance" reports by enforcing output buffer cleanup before setting CSV response headers and writing to output streams.
- Expanded `admin_report_notes` allowed types to include `top_attendance`, `class_summary`, and `at_risk`.
- Fixed Wasmer Edge deployment timeouts by creating `.wasmerignore` to exclude `.git` and development artifacts from Wasmer package uploads, adding CLI pre-authentication (`wasmer login`), and expanding retries with 30s progressive backoff.

## v0.3.52 — 2026-08-11

### Removed

- Removed the manual Settings test-notification button and `web-push-test` route while keeping real in-app and device push notifications for school events.

## v0.3.51 — 2026-08-10

### Added

- Added centralized logical security headers for application, network, endpoint, and data protection, including stricter CSP directives, no-cache headers for authenticated pages, forwarded-HTTPS detection, and expanded permission restrictions.
- Added shared app-level dirty-form guards that warn before refreshing or leaving sensitive POST forms, while keeping OS-level shortcut and startup-control behavior out of scope.

## v0.3.50 — 2026-08-08

### Fixed

- Fixed invisible welcome text on the Parent dashboard across all CSS cascade layers by switching base `.welcome-title` and `.welcome-meta` colors in `main.css` to readable theme variables and enforcing `!important` text colors on `#dashboardGreeting` and role hero headings in `role.css`.
- Added database user name lookup fallback in `Parent.php` when session first name is empty so greetings never display blank or unformatted names.
- Added versioned stylesheet queries (`?v=...`) to `Parent.php` and bumped Service Worker cache to `bshs-ams-v11` for immediate client cache invalidation.

## v0.3.49 — 2026-08-08

### Fixed

- Bumped root Service Worker cache key to `bshs-ams-v10` so installed PWA clients immediately purge obsolete asset caches and fetch updated stylesheets.

## v0.3.48 — 2026-08-08

### Fixed

- Fixed invisible welcome text on the Parent dashboard by expanding CSS rules for `.welcome-title` and heading elements under `.parent-hero`, `.student-hero`, `.teacher-hero`, and `.admin-hero` to ensure readable dark text on light backgrounds and adapt to dark mode.

## v0.3.47 — 2026-08-08

### Fixed

- Granted the Parent role default `messages.view` and `messages.send` permissions, and ensured existing RBAC databases receive missing built-in defaults without overwriting disabled custom mappings.
- Improved Parent dashboard readability by keeping the selected child reference visible on active buttons despite shared muted-text overrides and giving long profile names a wider, stable header area.

## v0.3.46 — 2026-08-08

### Fixed

- Changed the primary Chromium/PWA learning-material picker to start in Documents instead of reopening a stalled Windows Downloads folder.
- Added clipboard file paste while retaining drag-and-drop and the standard input fallback for browsers without the File System Access API.

## v0.3.45 — 2026-08-08

### Fixed

- Restored the teacher learning-material upload modal while removing the native picker's custom extension filter that could leave the Windows file dialog unresponsive.
- Added drag-and-drop file selection as an alternative to the system picker while preserving browser and server type and size validation.

## v0.3.44 — 2026-08-08

### Fixed

- Replaced the modal learning-material picker with a responsive inline upload panel to avoid installed mobile PWA crashes when returning from the system file picker.
- Added metadata-only file type and size validation with accessible selected-file feedback before upload.

## v0.3.43 — 2026-08-08

### Fixed

- Restored teacher learning-material upload and update requests by including the required CSRF token and displaying actionable server errors.
- Moved new learning-material files to protected durable storage on the Wasmer `/app/storage` volume while retaining lookup for legacy upload paths.
- Unified authorized teacher/student downloads with safe filenames, correct content types, enrollment checks, and direct-storage access blocking.

## v0.3.42 — 2026-08-08

### Fixed

- Corrected SF2 attendance marks to follow the official form legend: blank for present, `X` for absent, and an upper-half mark for late/tardy while preserving monthly totals.

## v0.3.41 — 2026-08-08

### Changed

- Added explicit Allow Notifications and Not Now actions in Settings, with readiness and blocked-permission guidance for installed PWA users.

### Fixed

- Corrected SF2 XLSX school details, month, grade, section, track, calendar days, and attendance marks to the official DepEd template coordinates.
- Preserved the official SF2 footer and added full template overflow sheets so larger male and female learner groups are not omitted.
- Refreshed the PWA cache so installed apps receive the updated notification permission controls.

## v0.3.40 — 2026-08-07

### Fixed

- Replaced unversioned portal JavaScript references and made PWA JavaScript/CSS network-first so installed apps load current notification controls instead of stale cached code.
- Refreshed server and device push status whenever the Settings modal opens.

## v0.3.39 — 2026-08-07

### Fixed

- Added bounded retries and a workflow timeout for transient Wasmer registry failures during GitHub deployment without retrying project validation failures.

## v0.3.38 — 2026-08-07

### Fixed

- Made VAPID key generation use Laragon's explicit OpenSSL configuration when PHP cannot discover it during startup, while validating generated keys before display.

## v0.3.37 — 2026-08-07

### Fixed

- Removed the optional PHP Calendar-extension dependency from teacher SF2 XLSX exports so month calculations work on Wasmer, including leap years.

## v0.3.36 — 2026-08-07

### Fixed

- Replaced the custom Web Push sender with standards-compatible VAPID delivery and extended notification retention to 24 hours.
- Added server readiness, device subscription, and test-send feedback to Settings for closed-PWA notification troubleshooting.
- Made notification clicks update unread badges immediately and open the exact linked announcement with a visible focus highlight.

## v0.3.35 — 2026-08-07

### Fixed

- Made teacher QR attendance scans use the `Asia/Manila` server time and class schedule, marking scans at least 15 minutes after the scheduled start as late.
- Restricted time-based QR attendance scanning to today's attendance sheet and validated teacher ownership plus active class enrollment on the server.

## v0.3.34 — 2026-08-07

### Fixed

- Unified school and class announcements, attendance, materials, grade activity and score events, and report-card releases across saved in-app, browser Web Push, and legacy mobile notifications.
- Added working read, read-all, delete, and delete-all actions for header notifications with per-user authorization.
- Corrected PWA notification icons and role-specific click destinations, including delivery while the installed PWA is closed when Web Push is configured and enabled.

## v0.3.33 — 2026-08-07

### Fixed

- Enabled browser camera access only on Teacher Attendance and added actionable QR scanner permission errors with a retry control.

## v0.3.32 — 2026-08-07

### Fixed

- Corrected student portal filename casing so dashboard, attendance, classes, report card, announcements, QR code, and action routes resolve on Wasmer's case-sensitive filesystem.

## v0.3.31 — 2026-08-07

### Fixed

- Corrected parent portal filename casing so Progress, Report Card, Announcements, Messages, and dashboard routes resolve on Wasmer's case-sensitive filesystem.

## v0.3.30 — 2026-08-07

### Fixed

- Made the Admin Announcements Post button functional by initializing its Bootstrap modals only after the shared JavaScript dependencies have loaded.

## v0.3.29 — 2026-08-07

### Fixed

- Restored admin and teacher announcement posting by supplying teacher CSRF tokens, upgrading legacy website-visibility columns, and preventing optional push failures from invalidating successful posts.

## v0.3.28 — 2026-08-07

### Fixed

- Made the Grade 11 subject seed run against the currently selected database and report the inserted strengthened SHS subject count after import.

## v0.3.27 — 2026-08-07

### Fixed

- Fixed official ECR XLSX exports to use the real template score columns, fill activity highest possible scores, and write calculated totals, weighted scores, transmuted grades, and letter grades.

## v0.3.26 — 2026-08-07

### Added

- Added `database/reset_database.sql` for safely clearing all application tables before reimporting the canonical schema for demo resets.

## v0.3.25 — 2026-08-07

### Fixed

- Fixed fresh Wasmer database imports by replacing an invalid `grade_items` performance index that referenced nonexistent `term` and `academic_year` columns.

## v0.3.24 — 2026-08-07

### Changed

- Consolidated database setup into a single hosted-compatible `database/schema.sql` for local Laragon and Wasmer Attached Database imports.
- Updated database setup documentation and schema tests to point at the single canonical schema file.

### Removed

- Removed redundant RBAC migration/seed SQL files, the older all-in-one Wasmer setup SQL, the extra hosted schema SQL, and the standalone performance index SQL/runner.

## v0.3.23 — 2026-08-07

### Fixed

- Fixed Teacher SF2 XLSX export on hosted PHP runtimes without `ZipArchive` by adding an official-template fallback writer.
- Hardened report notes requests to clear accidental output and request JSON explicitly so invalid-response messages are avoided.

## v0.3.22 — 2026-08-07

### Fixed

- Fixed report notes session and database failures to return JSON so the reports UI can show a clear message instead of an invalid-response error.

## v0.3.21 — 2026-08-07

### Added

- Added Teacher Attendance page SF2 export buttons for XLSX and CSV using the selected class and attendance date month.

## v0.3.20 — 2026-08-06

### Fixed

- Hardened report notes actions to return JSON on database failures and show backend error messages in the reports UI.
- Improved runtime report notes table creation with a hosted-database fallback and corrected the admin notes `general` report type.

## v0.3.19 — 2026-08-06

### Fixed

- Fixed report notes loading on deployed databases by creating missing admin and teacher report notes tables at runtime before notes actions query them.

## v0.3.18 — 2026-08-06

### Fixed

- Fixed ECR official template header mapping so division, school ID, school year, subject, and subject type export into the correct merged cells.

## v0.3.17 — 2026-08-06

### Fixed

- Fixed ECR `.xlsx` template export to preserve the official workbook sheets and layout instead of falling back to a one-sheet generated workbook when PHP `ZipArchive` is unavailable.

## v0.3.16 — 2026-08-06

### Added

- Added a converted macro-free `deped/ecr_template.xlsx` so ECR export can prefer an `.xlsx` template over the original `.xlsm` workbook.

## v0.3.15 — 2026-08-06

### Fixed

- Clarified the Grade Entry ECR notice when official template editing is unavailable so users know generated `.xlsx` ECR export remains available.

## v0.3.14 — 2026-08-06

### Fixed

- Improved ECR XLSX export reliability by falling back to a generated workbook when the official template cannot be edited and by returning template diagnostics to the ECR UI.

## v0.3.13 — 2026-08-06

### Fixed

- Fixed class card spacing so subject titles no longer overlap the floating header icon on Manage Classes and related card views.

## v0.3.12 — 2026-08-06

### Fixed

- Fixed official SF1 XLSX export to keep separate male and female template sections, preserve the bottom legend and summary rows, and populate registered learner counts.

## v0.3.11 — 2026-08-06

### Fixed

- Fixed official SF1 XLSX export row placement so learner records start on row 11 like the DepEd template and imported SF1 workbooks.

## v0.3.10 — 2026-08-06

### Fixed

- Fixed SF1 XLSX export date handling so namespaced exporter code resolves PHP date classes correctly in production.

## v0.3.9 — 2026-08-06

### Fixed

- Fixed SF1 XLSX import runtime loading by registering shared legacy export, grade, and XLSX helper aliases from `functions/bootstrap.php`.

## v0.3.8 — 2026-08-06

### Fixed

- Improved the shared Profile password flow by separating in-profile password changes from forgot-password recovery, pre-filling recovery email links, and revoking remember-me tokens after successful profile password updates.

## v0.3.7 — 2026-08-06

### Fixed

- Fixed production login bootstrap by loading legacy database/helper aliases and shared session/asset helpers before auth pages render.

## v0.3.6 — 2026-08-06

### Fixed

- Hardened sync endpoints by preventing admin account provisioning through sync, preserving existing user roles during sync updates, and validating attendance `recorded_by` attribution against active admins or assigned teachers.
- Fixed remember-me login to include password hashes when enforcing first-login default-password changes.
- Updated protected-page RBAC bootstrap enforcement to fail closed when permission checks cannot be completed.

## v0.3.5 — 2026-08-06

### Added

- Added `pushNotifyAttendanceEvent()` and `pushNotifyGradePublication()` helpers to `config/constants.php` to dispatch VAPID Web Push payloads and in-app notifications upon attendance marking and official report card release (`REQ-008`).
- Added Web Push notification trigger calls to `teacher/teacher_Action.php` (attendance upsert) and `admin/admin_Grade_Approvals_Action.php` (report card approvals).
- Added `tests/WebPushNotificationTest.php` unit test suite covering Base64Url helper encoding, push subscription creation/deletion, and event notification payload generation.
- Updated `pushEnsureSubscriptionsTable()` and `pushSaveSubscription()` in `config/constants.php` with SQLite database driver compatibility for testing runtimes.
- Added live password strength guidance checklist, real-time password match indicator, and inline modal alert container to the shared Profile Settings modal in `includes/modals.php`.
- Added global `.password-guidance` styling in `assets/css/main.css` supporting light and dark modes across all role portals.

### Fixed

- Removed legacy `classmap` array from `composer.json` to resolve Wasmer Edge Anybuild Docker container build failures.

## v0.3.4 — 2026-08-05

### Fixed

- Updated `profileApiUrl()` in `includes/modals.php` to dynamically detect subfolder relative routes (`/admin/`, `/teacher/`, `/student/`, `/parent/`, `/auth/`) for seamless modal profile updates across all portal surfaces.
- Updated `resetProfilePasswordBtn` click handler in `includes/modals.php` to validate input fields before triggering password updates or fallback password reset redirection.

### Added

- Added `tests/PasswordResetTest.php` validating password strength criteria, 6-digit OTP formatting regex, SHA-256 OTP token hash consistency, and token invalidation queries.

## v0.3.3 — 2026-08-05

### Added

- Updated `src/Export/Sf9Exporter.php` to format official DepEd MATATAG / Strengthened SHS 3-Term 2-page report card booklet layout, with clean cell bottom borders, 12-box LRN header grid, bold labels with non-bold metadata values, and exact table border closing.
- Added standard `deped/SF9_Senior_High_School.xlsx` and `deped/SF5_Senior_High_School.xlsx` template files to `deped/` matching SF1 and SF2 naming conventions.
- Updated `src/Export/Sf5Exporter.php` template resolution to use `deped/SF5_Senior_High_School.xlsx` as default primary template.

## v0.3.2 — 2026-08-05

### Added

- Updated `src/Export/Sf9Exporter.php` to format official DepEd SF9-SHS 2-page / 4-panel booklet layout matching official DepEd guidelines (`depedph.com`), including 11-month attendance grid, parent signature blocks, Certificate of Transfer, Semester 1 & 2 subject tables, Observed Values ratings (*Maka-Diyos*, *Makatao*, *Makakalikasan*, *Makabansa*), and Grading Scale legend.
- Regenerated `deped/SF9_Senior_High_School_Template.xlsx` workbook.

## v0.3.1 — 2026-08-05

### Changed

- Updated system specifications ([docs/PRODUCT_SPEC.md](file:///c:/laragon/www/attendance-and-academic-management-system/docs/PRODUCT_SPEC.md)), technical architecture ([docs/ARCHITECTURE.md](file:///c:/laragon/www/attendance-and-academic-management-system/docs/ARCHITECTURE.md)), and agent operational rules ([AGENTS.md](file:///c:/laragon/www/attendance-and-academic-management-system/AGENTS.md)) to specify Wasmer Attached Database (Wasmer MySQL) as the primary cloud database platform.

## v0.3.0 — 2026-08-05

### Added

- Added `docs/PRODUCT_SPEC.md` providing an ISO/IEC/IEEE 29148 compliant system specification (REQ-001 through REQ-010), user workflows, and role requirements.
- Added `docs/ARCHITECTURE.md` documenting system architecture, PSR-4 namespaces, security model, RBAC authorization matrix, and deployment configurations.
- Added Documentation Map to `AGENTS.md` per AI Engineering Standard v1.2.0 (§6).

### Fixed

- Updated `tests/Sf1ParserTest.php` fallback path to bundled `deped/SF1_Senior_High_School.xlsx` template, achieving 100% clean execution across all 26 PHPUnit tests.

## v0.2.0 — 2026-08-05

### Changed

- Organized 15 core shared classes into PSR-4 namespaced classes under `src/` (`BshsAms\Database`, `BshsAms\Schedule`, `BshsAms\Grade`, `BshsAms\Export`, `BshsAms\Xlsx`).
- Added `BshsAms\` PSR-4 autoloader mapping in `composer.json`.
- Maintained backward compatibility for all existing call sites via root-level `class_alias()` definitions in legacy file locations.

## v0.1.40 — 2026-08-04

### Fixed

- Fixed GitHub Action deployment step in `.github/workflows/wasmer-deploy.yml` by using official Wasmer CLI installation (`curl https://get.wasmer.io -sSfL | sh`) and non-interactive `wasmer deploy`.

## v0.1.39 — 2026-08-04


### Added

- Added 32x32 (`favicon.png`) and 16x16 (`favicon-16.png`) browser favicon generation to `scripts/generate_pwa_icons.php`.
- Added standard 32x32 and 16x16 `<link rel="icon">` tags to `pwaHeadHtml()` in `config/constants.php` for desktop browser web tab icon rendering.
- Added `wasmer-io/wasmer-deploy-action@v1` deployment step to `.github/workflows/wasmer-deploy.yml` to automatically publish main branch commits to Wasmer Edge (`balingasagshs.wasmer.app`).

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
