# Changelog

Project changes follow Semantic Versioning: MAJOR for breaking changes, MINOR for backward-compatible features, and PATCH for backward-compatible fixes.

## v0.3.139 — 2026-09-02

### Fixed

- **Reliable SF1 Section Resolver & UI-Created Section Reuse**:
  - **Narrowly Scoped Section Normalizer (`sf1NormalizeSectionName()`)**: Added `sf1NormalizeSectionName()` in [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php) to strip standard DepEd formatting artifacts (grade prefixes such as `Grade 11 - `, `11 - `, `G11 - `, parenthetical track descriptors like `(Academic)`, `(STEM)`, and dash suffixes like `- STEM`) while preserving legitimate section names.
  - **Context-Strict Candidate Resolution**: Updated `ensureSf1Section()` in [`admin/admin_SF1_Import_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_SF1_Import_Action.php) to evaluate candidate sections for the exact `grade_level` and `track` using exact name matching first and normalized matching second, resolving UI-created sections (`HUMILITY`) when importing SF1 files formatted as `Grade 11 - HUMILITY` without creating duplicate section records.
  - **Safe Word-Boundary Track Parsing**: Enhanced `sf1GetTrack()` with `\b` word boundary regex to recognize TVL/TechPro and Academic strand abbreviations accurately without false-positive matches on unrelated text.
  - **End-to-End Regression Testing**: Added `testSf1ImportResolvesUiCreatedSectionWithGradePrefixAndEnrollsInExistingClass` in [`tests/Sf1SubjectRosterEnrollmentTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1SubjectRosterEnrollmentTest.php) verifying that an SF1 import containing `Grade 11 - HUMILITY` reuses a UI-created `HUMILITY` section, enrolls the student into the existing class roster, creates zero duplicate sections, and maintains Grade 12 / TechPro isolation.

## v0.3.138 — 2026-09-02

### Fixed

- **SF1 Re-Import Section Resolution & Explicit Enrollment Error Tracking**:
  - **Strict Case-Insensitive Track Matching in `ensureSf1Section()`**: Updated `ensureSf1Section()` in [`admin/admin_SF1_Import_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_SF1_Import_Action.php) to match track strictly using normalized `LOWER(TRIM(track)) = LOWER(TRIM(?))` without blank/NULL fallback, ensuring `Grade 11 + Academic + Humility` resolves deterministically to the existing section regardless of track casing while rejecting mismatched tracks.
  - **Connection-Scoped Section Cache**: Scoped `$resolvedCache` by PDO connection object ID (`spl_object_id($db)`) in `ensureSf1Section()` to prevent cross-connection cache pollution in multi-step workflows and automated testing.
  - **Deterministic Existing LRN Flow & Explicit Error Tracking**: Refined existing student handling in `commitSf1Students()` so an existing LRN is guaranteed never to fall through into `INSERT INTO users`. If `syncStudentEnrollments()` succeeds, the row is marked `skipped` and `skipped++` is incremented; if enrollment synchronization fails, the actual error message is recorded in `results['rows']`, `errors++` is incremented, and the batch is marked failed instead of claiming success.
  - **Regression Testing**: Added tests in [`tests/Sf1SubjectRosterEnrollmentTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1SubjectRosterEnrollmentTest.php) validating that different or blank tracks do not resolve as `academic`, and that enrollment sync failures for existing LRNs are recorded as explicit errors.

## v0.3.137 — 2026-09-02

### Fixed

- **SF1 Section Resolution & Idempotent Roster Synchronization**:
  - **Context-Strict Section Resolution in `ensureSf1Section()`**: Enhanced `ensureSf1Section()` in [`admin/admin_SF1_Import_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_SF1_Import_Action.php) to resolve existing sections by normalized section name (`sectionMatchSql('name')` and whitespace/case normalization) strictly bound to the student's `grade_level` and `track`, avoiding duplicate section insertions and preventing cross-grade/cross-track false positives.
  - **Narrow `PDOException` Unique-Constraint Recovery**: Refined exception handling in `ensureSf1Section()` to catch only `PDOException` and recover exclusively on unique-constraint violations (`23000` / duplicate key errors), re-throwing all other database exceptions.
  - **Modular `commitSf1Students()` Function**: Extracted the production student-write loop into `commitSf1Students()` while keeping the exact HTTP action contract and result structure intact.
  - **Explicit Resolution Result & Idempotent Re-Import**: Updated `ensureSf1Section()` to return a structured resolution result (`['id' => $secId, 'name' => $canonicalName, 'created' => $wasCreated]`) so the commit flow deterministically uses the canonical section name for student profile assignment and runs `syncStudentEnrollments()` for both new and existing students upon re-import.
  - **End-to-End Regression Testing**: Added `testSf1CommitResolvesExistingSectionWithoutDuplicationAndEnrollsStudentsInClassRoster` in [`tests/Sf1SubjectRosterEnrollmentTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1SubjectRosterEnrollmentTest.php) parsing a real temporary CSV with `parseSf1UploadedFile()` and running `commitSf1Students()`, verifying that an existing section is resolved without duplication, the student is enrolled in the matching class, and re-importing the same SF1 data remains strictly idempotent.

## v0.3.136 — 2026-09-02

### Fixed

- **Filipino Subject Roster Showing 0 Students**:
  - **Root Cause**: The actual backend endpoints (`fetchClassStudents()` and `fetchGrades()` in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php)) that the UI calls to render the class student list and grade roster had no enrollment synchronization, so existing SF1-imported students with 0 enrollment rows were never visible.
  - **Eliminated Duplicate Implementation**: Removed the standalone `syncClassEnrollmentsForClass()` SQL implementation and replaced it with a thin wrapper that delegates to the existing canonical `syncClassEnrollmentsByGradeSection()`, now moved to [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php) for shared access across admin and teacher code paths.
  - **Explicit Helper Loading**: Added explicit `require_once` statements for [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php) inside [`functions/bootstrap.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/bootstrap.php) and [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php) to ensure all helper functions are reliably available before execution without depending on implicit autoload paths.
  - **Post-Success Per-Request Sync Caching**: Deferred the `$syncedInRequest` per-connection cache assignment in `syncClassEnrollmentsForClass()` until after `syncClassEnrollmentsByGradeSection()` completes successfully, allowing subsequent retries within the same request if a transient failure occurs.
  - **Fixed Real UI Endpoints**: Added `syncClassEnrollmentsForClass()` calls to `fetchClassStudents()` and `fetchGrades()` in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php) — the actual AJAX handlers that serve the teacher class roster and grade entry views.
  - **Regression Test Against Real Query**: Updated [`tests/Sf1SubjectRosterEnrollmentTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1SubjectRosterEnrollmentTest.php) with tests exercising the exact `fetchClassStudents()` SQL query, verifying the `class_subjects` mapping, confirming idempotency, validating explicit helper inclusion, and checking deferred sync caching.

## v0.3.135 — 2026-09-02

### Admin & Core

- **On-Demand Class Roster Synchronization for Existing SF1 Students**:
  - **Explicit On-Demand Synchronization Helper**: Implemented `syncClassEnrollmentsForClass()` in [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php) with in-request per-connection caching to atomically and idempotently enroll active section students into specific active classes when requested.
  - **Integrated Class Roster Loading Paths**: Wired `syncClassEnrollmentsForClass()` into [`admin/admin_Class_Detail.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Detail.php), [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php), [`teacher/teacher_Enrollment_Helper.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Enrollment_Helper.php), and [`teacher/teacher_Grades.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Grades.php), ensuring existing students with matching Grade & Section data (e.g. Grade 11 Humility) who lack enrollment rows are automatically repaired and visible in subject rosters (such as Filipino) without requiring SF1 re-import.
  - **Automated Regression Test Suite**: Added `testExistingSf1ImportedStudentWithZeroEnrollmentsIsRepairedOnRosterLoadAndRemainsUnchangedOnSecondSync` in [`tests/Sf1SubjectRosterEnrollmentTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1SubjectRosterEnrollmentTest.php) verifying zero-enrollment student repair on roster load and strict idempotency on second synchronization (6 tests, 31 assertions; all 187 tests passing in full suite).

## v0.3.134 — 2026-09-02

### Admin & Core

- **Fixed Subject/Class Roster Recognition for SF1 Imported Students**:
  - **Flexible Section & Track Resolution in `syncStudentEnrollments()`**: Enhanced `syncStudentEnrollments()` in [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php) to use `sectionMatchSql()` and flexible track matching `(track IS NULL OR TRIM(track) = '' OR LOWER(TRIM(track)) = LOWER(TRIM(?)))`, ensuring imported students are automatically and accurately enrolled into all active subject classes of their assigned section.
  - **Non-Destructive & Idempotent Enrollment Persistence**: Eliminated destructive deletions in `syncStudentEnrollments()`, checking existing records by `(student_id, class_id, academic_year)` to preserve existing enrollment IDs, reactivate inactive records when appropriate, and ensure re-imports do not generate duplicate enrollment records.
  - **Synchronized Class Offering Enrollment**: Updated `syncClassEnrollmentsByGradeSection()` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) with normalized section and track matching so newly assigned subjects immediately enroll all active students of the matching grade and section.
  - **Automated Regression Suite**: Created [`tests/Sf1SubjectRosterEnrollmentTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1SubjectRosterEnrollmentTest.php) verifying Grade 11 Humility SF1 import into Filipino class subject roster, multi-subject section enrollment, idempotent re-import without duplicates, and empty class section handling (5 tests, 19 assertions; all 186 tests passing in full suite).

## v0.3.133 — 2026-09-02

### Admin & Core

- **Clarified Schedule Conflict Messages with Specific Grade and Section**:
  - **Contextual Schedule Conflict Reporting**: Updated `checkScheduleConflicts()` and `checkScheduleConflictsForNewTeacher()` in [`admin/admin_Users_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users_Action.php) via `formatClassScheduleLabel()` to include the exact `Grade <X>` and `Section <Y>` details for both conflicting classes (e.g., `Schedule conflict: Life And Career Skills (Grade 7 - Section A) overlaps with Life And Career Skills (Grade 7 - Section B)`).
  - **Consistent Suffix Formatting in Subject Conflicts**: Updated `getTeacherSubjectConflicts()` in [`src/User/UserValidationHelper.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/User/UserValidationHelper.php) to consistently format grade and section labels with standard `(Grade <X> - Section <Y>)` notation.
  - **Automated Regression Suite**: Created [`tests/TeacherScheduleConflictMessageTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/TeacherScheduleConflictMessageTest.php) verifying message format precision across new-teacher assignment, existing-teacher assignment, non-overlapping schedules, and subject conflicts (all 181 tests in full suite passing).

## v0.3.132 — 2026-09-02

### Admin & UI/UX

- **Hot-Fix Select All Checkbox Scoping & State Sync in Admin Classes**:
  - **Scoped Checkbox Extraction**: Implemented `getEligibleSectionCheckboxes()` and `getCheckedEligibleSections()` in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php) to strictly target `#sectionCheckboxesContainer .section-checkbox:not(:disabled)`, preventing disabled, hidden, or stale checkboxes across the DOM from distorting selection counts or tab rendering.
  - **Reactive Select All Button State**: Added `updateSelectAllButtonState()` and `onSectionCheckboxChange()`, dynamically updating `#selectAllSectionsBtn` text to `"Deselect All"` when all eligible sections are selected and `"Select All"` otherwise.
  - **Deterministic Multi-Section Form Payload**: Refactored `saveClass()`, `copyFirstSectionScheduleToAll()`, and `staggerSectionSchedules()` to use `getCheckedEligibleSections()`, ensuring every checked section is correctly bundled into the `sections[]` array.
  - **Automated Regression Test Suite**: Added `testSelectAllAndDeselectAllToggleLogic` and `testCreateClassWithMultipleSelectedSectionsCreatesAllSectionsAtomically` in [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) verifying 3-section selection, deselect-all toggle, disabled checkbox exclusion, and atomic creation of all 3 sections in SQLite (14 tests, 172 assertions in MultiSectionTest; all 177 tests in suite passing).

## v0.3.131 — 2026-09-02

### Admin & UI/UX

- **Hot-Fix Add Section Button & Group Modal Wiring**:
  - **Resolved Missing Helper Errors**: Removed undefined `setupTimeInputFormatters(rowDiv)` call in `addSecScheduleRow()` and added in-scope `escapeHtml()` helper in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php).
  - **Robust Bootstrap Modal Transitions**: Utilized `bootstrap.Modal.getOrCreateInstance()` across `#classGroupSectionsModal` and `#addSectionToGroupModal` to reliably toggle between the section list and the dedicated Add Section form without modal collision or backdrop freezing.
  - **Immediate Section Dropdown Hydration**: Synchronously populated `#addSecModalSectionSelect` from `serverSections` with exact-group section exclusion and background API refresh.
  - **Comprehensive Event Delegation & Verification**: Added input event formatting on `#addSectionToGroupModal` and validated full UI flow and event wiring with DOM simulation and PHPUnit suite (175 tests, 1,331 assertions passing).

## v0.3.130 — 2026-09-02

### Admin & Core

- **Dedicated Section-Focused Add Section Modal & Fixed Grade/Section Edit Protection**:
  - **Dedicated `#addSectionToGroupModal`**: Replaced generic `#addClassModal` delegation when adding sections to an existing subject offering in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php). Features an inherited read-only subject context banner (Subject Name, Grade Level, Category, and Track) and collects section-specific fields: Target Section, Teacher, Schedule (builder vs TBA), and Room.
  - **Exact Subject Group Identity Filtering**: Filtered target sections selector against the active group's exact identity (`class_name`, `grade_level`, `subject_category`, `track`, `program`, `status`): only sections already assigned to this exact subject group are excluded, keeping sections assigned to other subjects selectable.
  - **Fixed Grade Level & Section Protection in Edit**: Rendered `Grade Level` and `Section` as fixed, disabled/read-only context in [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php). Enforced in `updateClass()` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) by locking `grade_level` and `section` to the database record's existing values for the targeted `$classId`, preventing tampered client payloads from altering grade or section.
  - **Automated Regression Test Suite**: Updated [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) and [`tests/AdminClassEditRegressionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassEditRegressionTest.php) with tests verifying dedicated modal structure, group-identity section filtering, backend duplicate prevention, tamper-proof fixed grade/section enforcement, and strict section edit isolation (12 tests, 135 assertions in MultiSectionTest; all 175 tests passing).

## v0.3.129 — 2026-09-02

### Admin & Core

- **Centralized Grouped Class Section Management & Strictly Section-Specific Edit**:
  - **Add Section via Grouped Modal**: Added an **Add Section** action button to `#classGroupSectionsModal` in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php), pre-populating `#addClassModal` with the selected group's class/subject identity, grade level, category, and track, while disabling existing sections in that group.
  - **Strict Section-Specific Edit**: Removed "Also Apply to Other Sections" selector and form logic from [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php) and removed cross-section propagation loops from `updateClass()` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php), guaranteeing that editing one section modifies only that exact record `WHERE id = ?`.
  - **Clickable Cards for Modal Access**: Enabled card header and title click triggers on all class cards (single-section and multi-section) to access `#classGroupSectionsModal` and section management actions.
  - **Automated Regression Test Suite**: Updated [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) and [`tests/AdminClassEditRegressionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassEditRegressionTest.php) to verify Add Section workflow, edit isolation, atomicity, and duplicate rejection (9 tests, 109 assertions in MultiSectionTest; 172 tests total).

## v0.3.128 — 2026-09-02

### Admin & Presentation

- **Consolidated Multi-Section Class Card Presentation & Section Selection Modal**:
  - **Presentation Layer Grouping**: Grouped compatible section-specific class records by subject identity (`class_name`, `grade_level`, `subject_category`, `track`, `program`, `status`) in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php) while preserving 100% separated database records per section.
  - **Clean Multi-Section Card UI**: Rendered multi-section cards with consolidated section names (`Sections: Ruby, Emerald, Sapphire`), section count pills, summarized total student enrollment, and unified `View & Manage Sections (N)` action.
  - **Interactive Section Modal (`#classGroupSectionsModal`)**: Clicking a grouped card or action button opens a dedicated modal detailing each section with its enrolled student count, assigned teacher, schedule, and room, complete with section-specific `View Details` (`admin_Class_Detail.php?id=<id>`), `Edit` (`admin_Class_Edit.php?id=<id>`), and `Delete` actions.
  - **Preserved Single-Section Direct Actions**: Single-section cards preserve direct `View`, `Edit`, and `Delete` actions.
  - **Automated Regression Suite**: Added `testGroupedClassPresentationAndSectionSpecificNavigation` to [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) (8 tests, 99 assertions).

## v0.3.127 — 2026-09-02

### Admin & Core

- **Fixed Multi-Section Class Creation with Pre-Validation and Atomic Transactions**:
  - **Pre-Validation Across All Sections**: Refactored `createClass()` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) to validate schedule formats, duplicate classes (`class_name` + `grade_level` + `section`), and section schedule conflicts across all selected sections _before_ making any database modifications.
  - **Eliminated Silent Partial Skips**: Removed the loop skipping behavior that created partial subsets and returned false `success: true` messages; now returns a clear, actionable error whenever an individual section cannot be created.
  - **Atomic Database Transactions**: Wrapped batch multi-section creation inside `$db->beginTransaction()` and `$db->commit()` with rollback on exception, ensuring all valid sections are created together or none are left in a partial state.
  - **Automated Regression Test Suite**: Added `testAdminClassesActionAtomicMultiSectionPreValidation` in [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) (7 tests, 68 assertions).

## v0.3.126 — 2026-09-02

### Admin & UI/UX

- **Fixed Section Selector & Schedule Time Fields Visibility on Manage Classes**:
  - **Scoped Sidebar Link Styling**: Scoped global `.nav-link` selectors in [`assets/css/main.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/main.css) to `.sidebar .nav-link` and `.sidebar-nav .nav-link`, eliminating white-on-white text collisions on modal nav pills and `#sectionSchedTabs`.
  - **Explicit Dropdown Option Contrast**: Added dedicated `.form-select option` and `select option` rules across light and dark modes in [`assets/css/main.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/main.css) to guarantee crisp text and background readability in normal and opened states.
  - **Schedule Time Controls Readability**: Enhanced `.schedule-time-group`, centering `HH` and `MM` inputs with high-contrast placeholders (`#98a2b3` / `#667085`), bold typography, and expanding `AM/PM` dropdown width to 76px to eliminate icon clipping.
  - **Section Pills & Tab Panes Theme Support**: Added `.app-section-pill` styles ensuring target section checkboxes and schedule tab panes render with high-contrast text and surfaces across light and dark modes in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php) and [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php).
  - **Automated Regression Suite**: Added `testSectionSelectorAndScheduleTimeFieldsVisibility` to [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) (6 tests, 60 assertions).

## v0.3.125 — 2026-09-02

### Admin & Bug Fixes

- **Fixed Class Card Action Buttons Clickability on Manage Classes**:
  - **Eliminated Script Syntax Error**: Removed redundant `const addClassModalEl` declaration in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php) line 1133 that was causing an `Uncaught SyntaxError` and halting script execution on page load.
  - **Restored Global Button Handlers**: Verified that `viewClass()`, `editClass()`, `deleteClass()`, `handleDelete()`, and `saveClass()` initialize and attach to `window`, restoring responsiveness for all card buttons across desktop and mobile.
  - **Regression Test**: Added `testAdminClassesCardButtonsAndScriptIntegrity` to [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) to prevent script syntax regressions.

## v0.3.124 — 2026-09-02

### Core & Notifications

- **Fixed Live Notification Dropdown Refresh & Poller Consolidation**:
  - **Eliminated Duplicate Override**: Removed trailing duplicate stub block from [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) that was overriding the complete `renderLiveNotifications()` with a no-op function on non-empty items.
  - **Dynamic Dropdown HTML Rendering**: Ensured `renderLiveNotifications()` seamlessly updates `.header-notification-scroll-body` and `#headerNotificationList` with full DepEd portal styling, unread/read opacity classes (`opacity-75`), icons, timestamps (`data-event-at`), target URLs, and trash buttons.
  - **Automatic Action Re-binding**: Calls `initHeaderNotificationActions()` on every live render to attach mark-as-read and delete handlers to newly injected elements without requiring a page reload.
  - **Expanded Regression Tests**: Added `testRenderLiveNotificationsMarkupGeneration` in [`tests/RealtimeNotificationPollingTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/RealtimeNotificationPollingTest.php) (4 tests, 39 assertions) covering full markup verification and empty/non-empty states.

## v0.3.123 — 2026-09-02

### Admin & Bug Fixes

- **Fixed Edit Class Regression & Update Pipeline Integrity**:
  - **Corrected Initialization Call**: Replaced the undefined `buildSchedule()` call in [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php) with `populateScheduleFields()`, eliminating the JavaScript `ReferenceError` on page load that prevented schedule fields from populating.
  - **Prevented Early Form Submission Abort**: Fixed the condition where empty schedule rows caused `updateClass()` to prematurely stop before dispatching the AJAX request.
  - **Section Selection Fallback**: Enhanced `updateSections()` to preserve and fallback to the class's original section if it is not present in the dynamic sections cache, avoiding accidental blank selection.
  - **Schedule Mode Dynamic Population**: Added automatic schedule field population when switching from TBA to `Time Slots` (`specific`) mode if the container is empty.
  - **Database Compatibility**: Parameterized datetime values in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) across `class_subjects`, `class_schedules`, and `admin_audit_logs` for robust cross-database support.
  - **Automated Regression Test Suite**: Added [`tests/AdminClassEditRegressionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassEditRegressionTest.php) (2 tests, 21 assertions) verifying updates for subject, teacher, section, schedule slots, TBA mode, room, category, grading weights, and optional multi-section sync (`apply_to_sections`).

## v0.3.122 — 2026-09-02

### Core & Real-Time Sync

- **Real-Time Notification Polling & Non-Blocking Updates (Without Full Page Reload)**:
  - **Live Poller (`assets/js/main.js`)**: Implemented `LiveNotificationPoller` with a 20-second background polling cycle against `api/notifications`.
  - **Tab Visibility & Offline Lifecycle**: Automatically pauses polling when the tab is hidden (`document.hidden`) or offline, and immediately resumes / polls on tab focus (`visibilitychange`) or reconnection (`online`).
  - **Overlapping Request Guard & AbortController**: Prevents stacked requests using an `isPolling` lock and a 10s `AbortController` timeout with exponential backoff on network failures.
  - **Deduplication & Badge Animation**: Seeded `seenNotificationIds` on initial load to avoid duplicate toast alerts, and added `@keyframes badgePulse` in [`assets/css/main.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/main.css) for smooth visual feedback upon new incoming alerts.
  - **Modular Custom Event**: Dispatches `ams:notificationReceived` with updated notification items for targeted, non-disruptive widget updates across pages.
  - **Preserved Security & Isolation**: Fully retains CSRF headers, session auth, user data isolation (`WHERE user_id = ?`), 60s initial render caching, and PWA offline-first attendance recording.
  - **Automated Tests**: Added [`tests/RealtimeNotificationPollingTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/RealtimeNotificationPollingTest.php) (3 tests, 19 assertions) and verified with 164 passing tests across the entire test suite.

## v0.3.121 — 2026-09-02

### Performance & Core Optimization

- **Server-Side Request Acceleration & Database Load Reduction**:
  - **Streamlined `functions/bootstrap.php`**: Replaced eager `require_once`s for heavy export and grading engines with a lazy PSR-4 autoloader alias registry, reducing cold PHP opcode parsing by over 40%.
  - **Secure RBAC Caching & Safe DB Fallback**: Optimized `enforceScriptPermission()` and `hasPermission()` to validate session-cached permissions against `user_id`, `role`, and TTL (300s), eliminating pre-page database connections while falling back safely to the database on missing/expired cache.
  - **Decoupled Notification Caching**: Added 60s session caching for notification counts and preview items in [`includes/header.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/header.php), with automated cache invalidation upon dispatch (`appNotifyUsers`) and mark-as-read actions.
  - **Eliminated Redundant Dashboard Queries**: Removed duplicate archives query in [`admin/admin.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin.php) (reusing announcement results) and added a 30s transient cache for expensive aggregate stats while keeping operational user and announcement tables real-time fresh.
  - **Optimized Schema Discovery (`SchemaCache`)**: Added static known schema registry in [`src/Database/SchemaCache.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/Database/SchemaCache.php) for core tables and columns from `schema.sql`, eliminating runtime `SHOW TABLES` and `SHOW COLUMNS` queries on standard page requests.
  - **Added Regression Test Suite**: Created [`tests/PerformanceOptimizationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PerformanceOptimizationTest.php) (4 tests, 26 assertions) covering lazy loading, RBAC validation/fallback, SchemaCache, and notification invalidation.
  - **Measured Performance Gains**: Admin Dashboard total latency reduced from **180.90 ms** to **105.44 ms** (**41.7% faster**), with memory peak reduced from **4.3 MB** to **2.65 MB** (**38.3% less memory**).

## v0.3.120 — 2026-09-02

### Admin & Feature

- **Hybrid Schedule Modes (Per-Section Schedules, Uniform, & TBA Mode)**:
  - Added dedicated **Per Section**, **Same for All**, and **Set Later (TBA)** schedule modes in the Admin "Add Class" modal ([`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php)).
  - Implemented dynamic per-section schedule tabs and rooms with **"Copy 1st Tab to All"** and **"Stagger Times (+1 hr)"** quick shortcuts to easily schedule consecutive sections without time conflicts.
  - Added **"Set to TBA"** quick toggle in [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php) to allow clearing or delaying timetable configuration without breaking enrollment sync.
  - Enhanced `createClass` and `updateClass` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) to process per-section schedules map (`section_schedules`) and TBA mode without false schedule conflicts.
  - Expanded unit test assertions in [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) (36 assertions).

## v0.3.119 — 2026-09-02

### Admin & Feature

- **Edit Class Subject Autocomplete & Multi-Section Apply/Sync**:
  - Connected Subject Name input in [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php) to `<datalist id="registeredSubjectsList">` populated with official subjects from the DepEd `subjects` registry table, auto-populating subject category, track, and weights on selection.
  - Added an interactive **"Also Apply to Other Sections"** multi-section selector with a **"Select All"** toggle on the Edit Class page, allowing admins to optionally sync or copy class updates (teacher, schedule, room, category, and grading weights) across other sections in one action.
  - Enhanced `updateClass` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) to update the primary class and apply/insert matching classes across any selected additional sections, keeping schedules and enrollments synchronized.
  - Added unit test cases to [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) (total test suite at 162 tests).

## v0.3.118 — 2026-09-02

### Admin & Feature

- **Multi-Section Batch Class Creation & Subject Registry Autocomplete**:
  - Implemented multi-section selection with an instant **"Select All"** toggle in the Admin "Add Class" modal ([`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php)), enabling administrators to create a subject offering for multiple sections simultaneously in one click.
  - Connected Subject Name input to DepEd subject registry suggestions via `<datalist id="registeredSubjectsList">`, auto-populating subject category, track, and official grading weights.
  - Enhanced `createClass` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) to batch-insert classes, assign teachers, configure schedules, sync student enrollments, and report detailed created/skipped summaries.
  - Added unit test suite in [`tests/AdminClassMultiSectionTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/AdminClassMultiSectionTest.php) (total test suite at 160 tests).

## v0.3.117 — 2026-09-02

### UI & Refinement

- **Header Profile Display Name Formatting (First and Last Name Only)**:
  - Updated `$displayName` in [`includes/header.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/header.php) to concatenate only `$displayFirstName` and `last_name`, cleanly omitting middle names from top navigation headers.
  - Synchronized live profile update handler in [`includes/modals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/modals.php) to update `.header-profile-name` and `#headerProfileName` with first and last name only.
  - Added unit test in [`tests/ProfileNameCapitalizationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/ProfileNameCapitalizationTest.php) (total test suite at 158 tests).

## v0.3.116 — 2026-09-02

### Feature & UI

- **Profile Name Auto-Capitalization & Title Case Normalization**:
  - Added `autocapitalize="words"` and autocomplete hints (`given-name`, `additional-name`, `family-name`) to First Name, Middle Name, and Last Name fields in [`includes/modals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/modals.php).
  - Implemented live JavaScript `toTitleCase` formatters on `input` and `blur` events so names are automatically formatted as users type.
  - Added Title Case server-side normalization (`mb_convert_case` with `MB_CASE_TITLE`) in [`api/routes/03-profile.php`](file:///c:/laragon/www/attendance-and-academic-management-system/api/routes/03-profile.php) to ensure consistency across the database and session headers.
  - Added test suite in [`tests/ProfileNameCapitalizationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/ProfileNameCapitalizationTest.php) (total test suite at 157 tests).

## v0.3.115 — 2026-09-02

### Performance & UI

- **Purged Legacy Modal Loaders & Optimized Site/Auth Fonts**:
  - Removed render-blocking `@import` font declarations from [`assets/css/Site.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/Site.css) and [`assets/css/auth.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/auth.css), replacing them with native `system-ui` fallback stacks.
  - Unified asynchronous font loading in `pwaHeadHtml()` in [`config/constants.php`](file:///c:/laragon/www/attendance-and-academic-management-system/config/constants.php) for both `Manrope` and `Poppins` fonts.
  - Bumped PWA service worker cache version to `bshs-ams-v37` across [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js), [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js), and [`assets/js/offlineStorage.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/offlineStorage.js) to automatically purge legacy public site modal overlays across all clients.
  - Expanded unit test coverage in [`tests/PerformanceOptimizationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PerformanceOptimizationTest.php) and [`tests/PwaAssetFreshnessTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PwaAssetFreshnessTest.php) (total test suite at 153 tests).

## v0.3.114 — 2026-09-02

### Performance & Optimization

- **Eliminated Render-Blocking CSS `@import` Chains & Accelerated Database Connection**:
  - Removed synchronous blocking `@import` statements for Bootstrap, Bootstrap Icons, and remote Google Fonts from [`assets/css/main.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/main.css), bundling `other.css` component styles directly into `main.css` to eliminate nested HTTP round-trips.
  - Added Google Font `preconnect` hints and asynchronous stylesheet loading in `pwaHeadHtml()` within [`config/constants.php`](file:///c:/laragon/www/attendance-and-academic-management-system/config/constants.php) with instant `system-ui` fallback rendering.
  - Streamlined `getConnection()` in [`src/Database/Database.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/Database/Database.php) to return the active shared PDO instance directly, eliminating repeated `SELECT 1` ping queries on every call (reduced PHP execution latency from ~485ms to ~156ms).
  - Added unit test suite in [`tests/PerformanceOptimizationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PerformanceOptimizationTest.php) verifying 0 blocking imports and font preconnect headers (total test suite at 152 tests).

## v0.3.113 — 2026-09-02

### Fixed

- **PWA Service Worker Initial Load & ControllerChange Reload Guard**:
  - Added `hadPreviousController` guard to `navigator.serviceWorker.addEventListener('controllerchange')` in [`config/constants.php`](file:///c:/laragon/www/attendance-and-academic-management-system/config/constants.php), preventing `clients.claim()` on initial visits from triggering an immediate second page reload.
  - Synchronized `window._CACHE_NAME` across inline header scripts to match `sw.js` (`bshs-ams-v36`).
  - Added test in [`tests/PwaAssetFreshnessTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PwaAssetFreshnessTest.php) verifying `hadPreviousController` guard and cache version alignment (total test suite at 150 tests).

## v0.3.112 — 2026-09-01

### Fixed

- **In-App Notification Live Polling & Toast Normalization**:
  - Unified JSON response format in [`api/routes/05-notifications.php`](file:///c:/laragon/www/attendance-and-academic-management-system/api/routes/05-notifications.php) to provide both `notifications` and `items` keys for backward and forward compatibility.
  - Updated `pollNotificationsLive()` in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) to resolve `data.notifications || data.items || []`, preventing real-time background polling from resetting the dropdown or dropping live in-app toasts.
  - Enhanced [`tests/NotificationDeliveryTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/NotificationDeliveryTest.php) to verify payload compatibility and client polling contracts.

## v0.3.111 — 2026-08-31

### Fixed

- **Enrollment Report Date Filter Whitelist & Strict Exception Handling**:
  - Registered `e.enrolled_at` into `ReportFilterHelper::ALLOWED_DATE_COLUMNS`, ensuring Admin Enrollment reports with date filtering generate `DATE(e.enrolled_at) >= ?` and `DATE(e.enrolled_at) <= ?` without regressions.
  - Replaced silent fallback to `'date'` with explicit `\InvalidArgumentException` throw on unregistered date columns, preventing subtle SQL generation bugs.
  - Added regression test suite in [`tests/ReportAndUserHelpersTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/ReportAndUserHelpersTest.php) verifying all 6 caller date columns and exception rejection (total test suite now at 149 tests).

## v0.3.110 — 2026-08-31

### Added

- **Automated Verification for Logic Helpers**:
  - Added [`tests/ReportAndUserHelpersTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/ReportAndUserHelpersTest.php) testing `ReportFilterHelper` and `UserValidationHelper` (bringing automated test count to 148 tests).

### Security

- **Strict Column Whitelisting in Report Filter Builder**:
  - Implemented explicit compile-time column whitelist in `ReportFilterHelper::appendDateFilter` ensuring dynamic date filters only target pre-approved column identifiers (`a.date`, `sg.recorded_at`, `g.created_at`, `e.created_at`, `u.created_at`, `c.created_at`, etc.).

## v0.3.109 — 2026-08-31

### Refactored

- **Report Filter & Query Building Logic Centralization**:
  - Created [`src/Report/ReportFilterHelper.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/Report/ReportFilterHelper.php) (`BshsAms\Report\ReportFilterHelper`) to encapsulate dynamic report filters, date range filters, filter parameter builders, and export formatters.
  - Replaced duplicate filter and query builder implementations in [`admin/admin_Reports.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Reports.php) and [`admin/admin_Reports_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Reports_Action.php).
- **User Validation & Conflict Checking Centralization**:
  - Created [`src/User/UserValidationHelper.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/User/UserValidationHelper.php) (`BshsAms\User\UserValidationHelper`) to encapsulate teacher advisory collision checks, teacher subject conflict detection, student validity checks, and parent-student duplicate linkages.
  - Refactored [`admin/admin_Users_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users_Action.php) and [`api/routes/06-admin.php`](file:///c:/laragon/www/attendance-and-academic-management-system/api/routes/06-admin.php) to use the centralized validation rules.

## v0.3.108 — 2026-08-31

### Refactored

- **Monolithic User Management Modal Extraction**:
  - Extracted 3 large modal definitions (`#addUserModal`, `#viewUserModal`, `#editUserModal`) from [`admin/admin_Users.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users.php) into [`includes/modals/user_modals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/modals/user_modals.php).
  - Reduced `admin_Users.php` file size from ~109 KB down to ~76 KB while preserving all DOM IDs, inputs, and JavaScript bindings.

## v0.3.107 — 2026-08-31

### Refactored

- **Monolithic Enrollments Modal Extraction**:
  - Extracted 4 large modal definitions (`#addStudentModal`, `#editStudentModal`, `#viewStudentModal`, `#importModal`) from [`admin/admin_Enrollments.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Enrollments.php) into [`includes/modals/enrollment_modals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/modals/enrollment_modals.php).
  - Reduced `admin_Enrollments.php` file size from ~96 KB down to ~48 KB while preserving all DOM IDs, inputs, and JavaScript bindings.
- **Session Layer & Anti-Flicker Dark Mode Streamlining**:
  - Removed regex-based HTML buffer rewrites (`ob_start`) from [`config/session.php`](file:///c:/laragon/www/attendance-and-academic-management-system/config/session.php).
  - Integrated synchronous anti-flicker dark mode scripts directly in `pwaHeadHtml()` within [`config/constants.php`](file:///c:/laragon/www/attendance-and-academic-management-system/config/constants.php), eliminating buffer interference while keeping theme transitions instant.

## v0.3.106 — 2026-08-31

### Changed

- **Login "Keep Me Signed In" Default State**:
  - Changed the "Keep me signed in on this device" checkbox in [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php) to be **unchecked (off) by default**, giving users explicit control over persistent device login vs. standard single-session login.
- **PWA Device Notification Onboarding Prompt**:
  - Refined `initPwaPushFirstOpenPrompt()` in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) to prompt for device notifications smoothly when installed, added a 24-hour grace window for "Later" dismissals, and exposed `window.showDeviceNotificationPrompt()` for manual invocation.
  - Bumped PWA service worker cache version to `bshs-ams-v36`.

## v0.3.105 — 2026-08-31

### Added

- **Live Real-Time Notification Polling & Background Sync**:
  - Added `initLiveNotificationPoller()` and `pollNotificationsLive()` in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) (polls every 15s when active).
  - Dynamically updates header badge count and notification dropdown list, pops instant toast notifications for new arrivals, and emits `ams:notificationReceived` event.
  - Added live event listeners on [`admin/admin_Grade_Approvals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Grade_Approvals.php) to automatically refresh approval cards when submissions arrive without manual reload.

- **Automated Workflow Notifications (Adviser, Admin, & Teacher)**:
  - Added automated in-app notification dispatch to Admin users when an Adviser submits consolidated section report cards in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php).
  - Added automated in-app notification dispatch to Admin users when a Subject Teacher submits subject grades in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php).
  - Added automated in-app notification dispatch to Section Advisers and Teachers when Admin verifies subject grades or returns grades/report cards for correction in [`admin/admin_Grade_Approvals_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Grade_Approvals_Action.php).

### Changed

- **Clarified Grade Submission & Approval Workflow UI**:
  - Added a 4-step visual DepEd grade pipeline progress guide in [`admin/admin_Grade_Approvals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Grade_Approvals.php).
  - Added Step 1 Subject Grade Submission guidance banner in [`teacher/teacher_Grades.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Grades.php).
  - Added Step 2 Advisory Report Card Compilation guidance banner in [`teacher/teacher_Advisory.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Advisory.php).
  - Bumped PWA service worker cache version to `bshs-ams-v35`.

## v0.3.104 — 2026-08-30

### Added

- **Installed-Only PWA Login Device Notification Modal (Allow, Deny, Later)**:
  - Added `#pushPromptModal` to [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php) with three explicit choices: **Allow Notifications**, **Deny**, and **Later**.
  - Implemented `isInstalledPwa()` in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) to ensure the onboarding notification prompt appears strictly when running as an installed standalone app on the device and is suppressed in regular browser tabs.
  - Added the **Deny** (`#pushPromptDenyBtn`) option across [`includes/modals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/modals.php) and [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php) to remember user refusal in `localStorage`.
  - Hardened Settings push notifications save handling in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) to avoid false-positive error alerts when subscribing.
  - Added automated unit tests in [`tests/PwaNotificationPromptTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PwaNotificationPromptTest.php) and updated [`tests/PwaAssetFreshnessTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PwaAssetFreshnessTest.php).

### Fixed

- **PWA Offline Navigation & Interactive Workspace Access**:
  - Incremented Service Worker cache to `bshs-ams-v30` in [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js) and included core teacher subpages (`/teacher/teacher.php`, `/teacher/teacher_Attendance.php`, `/teacher/teacher_Classes.php`, `/teacher/teacher_Grades.php`) in `APP_SHELL_URLS` for reliable offline availability.
  - Upgraded Service Worker offline fallback notice from a dead-end "Go Back" link to an interactive Offline Workspaces card with direct launch links to **Offline Attendance**, **Offline Classes & Grades**, and **Teacher Dashboard**.
  - Synchronized cache name resolution and teacher session caching (`bshs_cached_teacher`) in [`assets/js/offlineStorage.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/offlineStorage.js).
  - Enhanced [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php) with an immediate, interactive **Teacher Offline Workspace** launchpad when the app is opened offline.
  - Added automated test suite in [`tests/PwaOfflineModeTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PwaOfflineModeTest.php).

- **Acronym Preservation and Extended Auto-Capitalization**:
  - Fixed subject and room normalization in [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php), [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php), and [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php) so uppercase acronyms such as `PE`, `MAPEH`, `TVL`, and `STEM` are preserved instead of forcefully lowercased into title case.
  - Added auto-capitalization to Section Name inputs in [`admin/admin_Sections.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Sections.php).
  - Added auto-capitalization to Announcement Title inputs in [`admin/admin_Announcements.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Announcements.php) and [`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php).
  - Added auto-capitalization to Grade Activity Titles and Material Titles in [`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php).
  - Added auto-capitalization to Student Address fields (House/Street, Barangay, Municipality, Province) in [`admin/admin_Enrollments.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Enrollments.php).
- **Instant Page Loading & Database Connection Reuse**:
  - Implemented static PDO connection sharing per request lifecycle in [`src/Database/Database.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/Database/Database.php) (`$sharedConnection`), eliminating redundant TCP handshakes and MySQL connection authentications across `bootstrap.php`, pages, and partials.
  - Upgraded [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js) asset caching strategy to **Stale-While-Revalidate** across all static stylesheets, JavaScript bundles, fonts, and images, serving cached assets in **0ms** and updating caches in the background.
  - Bumped Service Worker cache version to `bshs-ams-v32` across [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js), [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js), and [`assets/js/offlineStorage.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/offlineStorage.js).
  - Added automated unit test suite in [`tests/PerformanceOptimizationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PerformanceOptimizationTest.php).
- **Interactive Editable SF1 Preview & Verification Before Import**:
  - Upgraded the DepEd SF1 Student Bulk Import in [`admin/admin_Enrollments.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Enrollments.php) and [`admin/admin_SF1_Import_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_SF1_Import_Action.php) into a two-stage verification workflow.
  - **Stage 1 (Parse & Preview):** Uploading an official DepEd SF1 `.xlsx` or `.csv` spreadsheet extracts all learner records, verifies LRNs, cross-checks for existing student accounts in the database, and displays an editable spreadsheet grid in a wide (`modal-xl`) modal dialog.
  - **Stage 2 (Inline Edit & Metadata Customization):** Administrators can edit learner names, 12-digit LRNs, sex, birthdates, addresses (House/Street, Barangay, Municipality, Province), contact numbers, and parent/guardian names directly in the table. Administrators can also add new learner rows, exclude/delete individual rows, and adjust section/grade level/track classification before committing.
  - **Stage 3 (Commit & Parent Linkage):** The system commits only the verified learner records, automatically creates missing section entries, generates student reference codes and emails, creates parent user accounts (`role = 'parent'`), establishes parent-student linkages, and logs full audit trail events.
  - Added automated test suite in [`tests/Sf1ImportPreviewTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1ImportPreviewTest.php).

- **Admin School Information, Official Seal Upload & DepEd Settings**:
  - Added dedicated Admin School Settings page in [`admin/admin_School_Settings.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_School_Settings.php) and handler in [`admin/admin_School_Settings_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_School_Settings_Action.php) allowing administrators to configure official DepEd public school information (School Name, 6-digit DepEd School ID, Principal / School Head, Region, Schools Division Office, District, Campus Address, Official Email, Contact Number, Office Hours, Hero Headline, Hero Tagline, Announcements Notice, and About School Overview).
  - Added **School Seal & Brand Logo Upload** feature allowing administrators to upload a new official school logo (PNG, JPG, or WEBP), auto-converting to standard image assets and automatically regenerating PWA mobile icons and browser favicons.
  - Added School Settings item with icon `bi-building-gear` to the Admin sidebar menu in [`includes/sidebar.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/sidebar.php), contained long school names from overflowing via `-webkit-line-clamp: 2` in [`assets/css/main.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/main.css), and added a hover tooltip.
  - Resolved desktop sidebar collapse button dual-invocation by eliminating inline `onclick` from [`includes/sidebar.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/sidebar.php) and binding a dedicated event listener in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js).
  - Connected dynamic database school settings into DepEd **SF1**, **SF2** ([`src/Export/Sf2Exporter.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/Export/Sf2Exporter.php)), and **ECR** ([`api/routes/12-ecr.php`](file:///c:/laragon/www/attendance-and-academic-management-system/api/routes/12-ecr.php)) Excel exports.
  - Updated the public website navbar brand kicker in [`site/index.php`](file:///c:/laragon/www/attendance-and-academic-management-system/site/index.php) to `"Academic Management System"`, and dynamically synchronized customizable hero, announcements, about, and contact sections with `website_content`.
  - Registered `admin_school_settings.php` and `admin_school_settings_action.php` in `permissionForScript()` in [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php).
  - Fixed `recordAdminAuditLog()` invocation signature and cross-driver SQLite/MySQL timestamps, and added a GET method redirect guard in [`admin/admin_School_Settings_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_School_Settings_Action.php).
  - Added automated unit test suite in [`tests/SchoolSettingsManagementTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/SchoolSettingsManagementTest.php).

## v0.3.103 — 2026-08-29

### Added

- **Teacher Assignment Option in Add & Edit Class Forms**:
  - Added an "Assigned Teacher" selector dropdown to the "Add New Class" modal in [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php) populated with active teachers from the system.
  - Added the same "Assigned Teacher" selector to [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php) so administrators can reassign or change teachers when modifying class records.
  - Enhanced `createClass()` and `updateClass()` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) to store `classes.teacher_id` and automatically sync the `class_subjects` mapping for immediate visibility in the assigned teacher's portal.
  - Added automated unit tests in [`tests/ClassTeacherAssignmentTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/ClassTeacherAssignmentTest.php).
- **Single-Letter Middle Initial Support**:
  - Enhanced `isValidMiddleName()` in [`functions/app-helpers.php`](file:///c:/laragon/www/attendance-and-academic-management-system/functions/app-helpers.php) and [`api/index.php`](file:///c:/laragon/www/attendance-and-academic-management-system/api/index.php) to accept single-letter middle names/initials (e.g., `"M"`, `"M."`, `"D"`, `"D."`) in addition to full middle names and blank entries.
  - Added automated unit tests in [`tests/MiddleNameValidationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/MiddleNameValidationTest.php).

## v0.3.102 — 2026-08-29

### Added

- **Automatic Parent Account Creation and Student Linking on SF1 Import**:
  - Implemented `autoLinkSf1Parent()` in [`admin/admin_SF1_Import_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_SF1_Import_Action.php) to automatically create parent user accounts (`role = 'parent'`) from Father, Mother, or Guardian information in imported SF1 spreadsheets.
  - Automatically links each newly enrolled learner to their parent/guardian record in the `parent_students` table.
  - Reuses existing parent accounts for enrolled siblings sharing the same parent name or mobile contact number, avoiding duplicate parent account proliferation.
  - Updated the SF1 Import Results summary dialog in [`admin/admin_Enrollments.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Enrollments.php) to display real-time parent creation and linking counts alongside student enrollments.
  - Added automated unit and integration tests in [`tests/Sf1ParentAutoCreationTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf1ParentAutoCreationTest.php).

## v0.3.101 — 2026-08-25

### Fixed

- **Admin Manage Users Modal, View/Edit Details & Buttons Restoration**:
  - Fixed missing SQL prepare/execute in `getUser()` within [`admin/admin_Users_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users_Action.php) that caused database errors when clicking the eye (View) icon and prevented user fields from populating in the Edit modal.
  - Repaired Add User and Edit User modal markup and JavaScript syntax in [`admin/admin_Users.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users.php) (`onRoleChange`, `hydrateSectionsCache`, `filterParentStudentList`, `clearParentSearch`).
  - Restored full functionality to "Add User", "View User", "Edit User", "Delete User", and "Reset Password" buttons and fixed dynamic form controls for Teacher and Parent role selection.
  - Added full support for `contact_number` in Add, View, and Edit modals as well as `createUser()` and `updateUser()` backend persistence with searchable student linking for parent accounts.

### Added

- **Universal Search Bar Enhancements Across ALL Portals**:
  - Upgraded search bars across Admin, Teacher, Parent, and Student portals with modern search icons, dynamic quick-clear (`x`) buttons, and live filtering / instant reset:
    - **Admin**: `admin_Users.php` (User table + Add/Edit Parent link student searches), `admin_Enrollments.php` (debounced live search), `admin_Attendance.php`, `admin_Announcements.php`, `admin_Audit_Logs.php`, `admin_Archives.php`.
    - **Teacher**: `teacher_Announcements.php`, `teacher_Attendance.php`, `teacher_Advisory.php`, `teacher_Classes.php`, `teacher_SF9_Export.php`.
    - **Parent**: `Parent_Announcements.php`.
    - **Student**: `Student_Announcements.php`.

## v0.3.100 — 2026-08-25

### Added

- **Admin Grade Reversion / Recall Notifications to Student & Parent**:
  - Implemented `pushNotifyGradeRecall()` in [`config/constants.php`](file:///c:/laragon/www/attendance-and-academic-management-system/config/constants.php) and wired it into `return_released_report_card_batch` and `review_report_card` in [`admin/admin_Grade_Approvals_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Grade_Approvals_Action.php).
  - When the school administration recalls or reverts a released report card for correction, affected students and linked parents immediately receive an in-app and push notification explaining that the report card was recalled for administrative review/correction so they are aware why it is temporarily unavailable.

### Changed

- **Delta-Only Attendance Notifications**:
  - Updated `submitAttendance()` in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php) and `10-teacher.php` API route to compare submitted student attendance against existing records before sending notifications.
  - When saving or editing attendance (for current or past dates), notifications are dispatched **only** to students whose records are new or whose attendance status/remarks actually changed. Unchanged student records generate zero repeat duplicate notifications.

## v0.3.99 — 2026-08-25

### Fixed

- **Grade Term Isolation & Normalization Bug**:
  - Fixed `normalizeTerm()` in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php) by delegating to `SshsGradeCalculator::normalizeTerm()`, eliminating strict casing mismatch where `Term2`, `Term3`, and quarters defaulted to `Term1`.
  - Prevents Term 1 grades and activities from bleeding into or overwriting Term 2 and Term 3.
- **Transmuted Quarterly Grade Storage in Submissions**:
  - Updated `submitGrades()` in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php) to apply `transmuteQuarterlyGrade()` before saving to `grades.final_grade`, ensuring Report Cards, ECR exports, and student/parent portals display official transmuted DepEd grades (e.g. `64`) instead of raw initial weighted scores (e.g. `17.50`).
- **Parent Account Linking & Student Checklist Activation**:
  - Restored `onRoleChange()` and `filterParentStudentList()` in [`admin/admin_Users.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users.php) so selecting Role = "Parent" immediately opens the Parent Link checklist with searchable students upon account creation.
- **Contact Number (Mobile) in User Creation & Editing**:
  - Added `Contact Number (Mobile)` input fields to Add and Edit User modals in [`admin/admin_Users.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users.php) and implemented backend persistence in `getUser()`, `createUser()`, and `updateUser()` in [`admin/admin_Users_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users_Action.php).

### Added

- **Global Search Bar Upgrades Across Portals**:
  - Added enhanced search UI with real-time keyword highlighting and quick-clear (`x`) buttons across **SF9 Progress Report Card Generator** ([`teacher/teacher_SF9_Export.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_SF9_Export.php)), **Teacher Attendance** ([`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php)), and **Enrolled Class Students** modal ([`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php) and [`teacher/teacher_Advisory.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Advisory.php)).

## v0.3.98 — 2026-08-25

### Added

- **YouTube-Style Live Student Autocomplete Search (Teacher Advisory)**:
  - Implemented interactive floating suggestions dropdown in [`teacher/teacher_Advisory.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Advisory.php) that live-filters students by name or LRN as you type, highlights matching query terms, supports Arrow Up/Down keyboard navigation, and automatically loads report cards upon 1-click or Enter selection.

### Changed

- **Compact Subject Category Dropdown**:
  - Simplified Subject Category dropdown options across [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php) and [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php) to clean, concise labels without verbose track or percentage clutter.
- **Fixed Student Card Left Alignment Gap**:
  - Wrapped KPI card elements in `.card-icon` and `.dashboard-card-info` in [`teacher/teacher_Advisory.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Advisory.php), eliminating the empty grid column gap on the left of student names.

## v0.3.97 — 2026-08-25

### Added

- **DepEd Order No. 8, s. 2015 Auto & Manual Class Grading Weights**:
  - Implemented automatic grading weight configuration across Academic Track (Core: 25/50/25, Specialized/Elective: 25/45/30, Research: 40/60/0, Immersion: 20/60/20) and Technical-Professional Track (TechPro Specialized: 20/60/20, TechPro Immersion/Apprenticeship: 20/80/0) in [`src/Grade/SshsGradeCalculator.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/Grade/SshsGradeCalculator.php), [`admin/admin_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes.php), and [`admin/admin_Class_Edit.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Class_Edit.php).
  - Preserved full manual editing on Written Work (WW), Performance Task (PT), and Assessment (QA) inputs with real-time visual total sum validation badge (`Total: 100%`).

## v0.3.96 — 2026-08-25

### Added

- **Parent-Student Linking Search Filter (Admin Users)**:
  - Added real-time client-side search inputs (`#parentStudentSearch` and `#editParentStudentSearch`) with `filterParentStudentList()` in [`admin/admin_Users.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Users.php) for instant filtering of students by name or reference code/LRN in Add/Edit Parent modals.
- **Self-Service Contact Number in Profile & Settings Modal**:
  - Added editable `Contact Number (Mobile)` input with Philippine mobile format validation to the shared Profile modal ([`includes/modals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/modals.php)), session state ([`includes/header.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/header.php)), and Profile API ([`api/routes/03-profile.php`](file:///c:/laragon/www/attendance-and-academic-management-system/api/routes/03-profile.php)) so parents, teachers, and students can update their phone numbers anytime for SMS alerts.
- **Teacher Report Card Student Search Filter**:
  - Added real-time student search and instant dropdown selection in Advisory Report Cards ([`teacher/teacher_Advisory.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Advisory.php)) and SF9 Progress Report Card Generator ([`teacher/teacher_SF9_Export.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_SF9_Export.php)).

## v0.3.95 — 2026-08-25

### Fixed

- **Live Grade Activity Computation & Term Dropdown Auto-Reload**:
  - Updated `computeQuarterSummaryFromItems()` in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php) to include active activities alongside finished activities (`gi.status IN ('active', 'finished')`), ensuring entered scores calculate into the Grade Entry table immediately without requiring a manual "Finish" step.
  - Added `onchange` handlers to Class, Term, Academic Year, and Sex filter dropdowns in [`teacher/teacher_Grades.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Grades.php) for instant automatic reloading upon changing selection.

## v0.3.94 — 2026-08-25

### Fixed

- **Service Worker Offline Fallback Icon**:
  - Replaced corrupted SVG vector path in [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js) with clean Bootstrap `bi-wifi-off` SVG icon, removing distorted crossing lines on the offline notice page.
- **Grade Entry Load Animation & Layout Stability**:
  - Replaced button text replacement with CSS in-place rotation (`.spin`) on the reload icon in [`teacher/teacher_Grades.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Grades.php) and [`assets/css/main.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/main.css), eliminating button resizing and layout shifts.
  - Suppressed toast notification flash during initial automated page load on `DOMContentLoaded`.

## v0.3.93 — 2026-08-25

### Fixed

- **DepEd SF2 Export Subject-Teacher Access & Enrollment Resolution**:
  - Expanded class access authorization in [`teacher/teacher_SF2_Export.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_SF2_Export.php) to permit subject teachers assigned via `class_subjects` in addition to section advisers (`classes.teacher_id`), resolving unauthorized export errors on subject classes.
  - Made student enrollment lookups in [`teacher/teacher_SF2_Export.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_SF2_Export.php) and [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) resilient to academic year variations, preventing empty roster exports when filtering across different calendar months.
  - Replaced `cal_days_in_month()` with standard `date('t')` in [`admin/admin_Classes_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/admin/admin_Classes_Action.php) to remove `ext-calendar` runtime dependency.
  - Removed duplicate `const statusCycle` declaration in [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php), fixing the JavaScript SyntaxError that prevented `exportAttendanceSf2()` from loading.
  - Enhanced term and class switching in [`teacher/teacher_Grades.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Grades.php) with button loading spinners, toast notifications, dynamic hero chip updates, and URL synchronization.
  - Added automated test assertions in [`tests/Sf2ExporterTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf2ExporterTest.php).

## v0.3.92 — 2026-08-24

### Changed

- **Single Source of Truth for MySQL Schema (Runtime DDL Cleanup)**:
  - Removed 20 pure-duplicate runtime `CREATE TABLE` definitions and their call sites across 11 files (API auth/settings/notifications routes, teacher attendance actions, admin grade-approval pages, parent/student report-card pages, teacher advisory, class audit-log helper, push notifications, and the database-session bootstrap).
  - `database/schema.sql` is now the only place defining MySQL structure outside three documented exceptions.
- **Kept deliberately**:
  - SQLite test fixtures (`functions/app-helpers.php`, `config/constants.php`, `src/Notification/SmsService.php`) used by the PHPUnit suite.
  - The RBAC auto-create/seed bootstrap, which README documents as intended behavior.
  - Legacy upgrade paths with side effects: `ensureAuthLoginLogsTable` (column migrations), `ensureReportNotesTables` (ENUM widening), `ensureStrengthenedShsColumns` (curriculum backfills).
- **Note**: deployments that skip importing `database/schema.sql` after this release must import it; runtime self-creation no longer covers removed duplicates.

### Added

- **Guard Test**:
  - [`tests/RuntimeDdlGuardTest.php`](tests/RuntimeDdlGuardTest.php) fails when any non-allowlisted file contains runtime `CREATE TABLE` statements and verifies every runtime-created table exists in `schema.sql`.
- **Convention**: `AGENTS.md` now states that new tables/columns ship in `schema.sql` first.

## v0.3.91 — 2026-08-24

### Changed

- **Single Source of Truth for Section Matching**:
  - Added `sectionMatchSql()` in [`functions/app-helpers.php`](functions/app-helpers.php), generating the exact-match OR fuzzy-substring clause for both placeholder and column-comparison (JOIN) modes, with SQL identifier validation.
  - Replaced all 34 copy-pasted occurrences of the fuzzy section-matching SQL fragment across 13 files (API advisory/report helpers, teacher attendance/advisory/enrollment/reports helpers, admin grade approval pages, and adviser-parent chat matching).
- **De-duplicated API Validation Helpers**:
  - `apiValidPersonName`, `apiHasMinimumLetters`, `apiNormalizeDate` ([`api/index.php`](api/index.php)) and `apiGetTeacherRoles` ([`api/apisupport.php`](api/apisupport.php)) now delegate to their canonical global implementations instead of duplicating logic. No behavior change.

### Added

- **Guard Test**:
  - [`tests/SectionMatchSqlTest.php`](tests/SectionMatchSqlTest.php) verifies clause generation for both modes, rejects invalid identifiers, enforces that the raw SQL fragment exists only inside `functions/app-helpers.php`, and asserts the API helpers remain thin delegators.

## v0.3.90 — 2026-08-24

### Fixed

- **Deduplicated `database/schema.sql`**:
  - Removed a duplicated block of 13 table definitions (`rate_limits`, `auth_remember_tokens`, `app_sessions`, `auth_password_resets`, `mobile_push_tokens`, `messages`, `website_content`, `attendance_sync_queue`, `school_settings`, `academic_year_settings`, and the three RBAC tables).
  - Kept the correct `rbac_role_permissions` definition, which also repairs a latent defect where the first copy carried a stray `idx_admin_audit_logs_created_at` index instead of its UNIQUE KEY and foreign keys.
- **RBAC Fail-Open Gaps Closed**:
  - Added missing entries to the script-to-permission map in [`functions/app-helpers.php`](functions/app-helpers.php): `admin.php`, `teacher.php`, `Student_QR.php` (learner PII page), plus the dead `teacher_SF5_Export.php` / `teacher_SF9_Export.php` stubs.
- **Logout CSRF Protection**:
  - The logout link in [`includes/header.php`](includes/header.php) now appends the session CSRF token, and [`auth/logout.php`](auth/logout.php) validates it before revoking remember-tokens or destroying the session, preventing cross-site drive-by logout.

### Added

- **API Bearer Token Revocation**:
  - New `users.api_token_version` column (in schema and via runtime ensure-column) embedded as the `ver` claim in API tokens; [`api/apisupport.php`](api/apisupport.php) rejects tokens whose version no longer matches the database, so changing a password instantly invalidates previously issued tokens on all devices.
- **Guard Tests**:
  - `tests/RbacScriptCoverageTest.php` fails when any portal page lacks an RBAC mapping or the map contains stale keys.
  - `tests/CsrfHandlerCoverageTest.php` fails when any portal action handler or auth POST handler lacks CSRF enforcement.
- **Rate-Limit Visibility**:
  - Web login now logs a `[SECURITY WARNING]` to the PHP error log when the `rate_limits` table is missing instead of silently disabling lockout.

### Changed

- **Lint Scope**:
  - `phpcs.xml` now runs PHP syntax checks across all runtime source directories instead of only four files; mixed-line-endings tokenizer warnings are silenced so exit status reflects real syntax errors.

### Documentation

- Updated [`docs/PROJECT_REVIEW_2026-08-24.md`](docs/PROJECT_REVIEW_2026-08-24.md) with remediation statuses and a correction to Issue #4 (role/status were already re-validated per API request).

## v0.3.89 — 2026-08-24

### Added

- **Project Review Document**:
  - Added [`docs/PROJECT_REVIEW_2026-08-24.md`](docs/PROJECT_REVIEW_2026-08-24.md), a read-only full-project review covering architecture, security, tests, database schema, CI/CD, and documentation, with a prioritized issues list and recommended follow-ups.
  - Documentation only; no runtime behavior changed.

## v0.3.88 — 2026-08-24

### Fixed

- **Web Push VAPID Key Loading in Local & PWA Environments**:
  - Updated [`config/constants.php`](file:///c:/laragon/www/attendance-and-academic-management-system/config/constants.php) to resolve `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, and `PUSH_VAPID_SUBJECT` via `appEnvValue()` instead of raw `getenv()`.
  - Ensures device push subscriptions and settings modals properly detect configured VAPID server keys from `.env`.

## v0.3.87 — 2026-08-24

### Added

- **Real-Time Password Match Indicator on Setup & Reset**:
  - Added live matching validation to the "Confirm Password" field across initial password setup ([`auth/change-password.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/change-password.php)) and password reset ([`auth/reset-password.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/reset-password.php)).
  - Provides real-time visual feedback (`✓ Passwords match` in green / `✗ Passwords do not match` in red) on keystroke input and blur events with accessible styling in [`assets/css/auth.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/auth.css).

## v0.3.86 — 2026-08-22

### Changed

- **Attendance Action Labeling Refinement**:
  - Updated primary teacher attendance action button, loading state transitions, and feedback notifications from **"Submit Attendance"** to **"Save Attendance"** across [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php), [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php), and [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js).
  - Clarifies that attendance records are directly editable and saveable at any time by the subject teacher without confusion of a multi-tier lock.

## v0.3.85 — 2026-08-22

### Added

- **DepEd School Form 2 (SF2) Summary Statistics & Remarks Automation**:
  - Automated calculation and population of the **Official Summary Statistics Table (Rows 60–89)** in [`src/Export/Sf2Exporter.php`](file:///c:/laragon/www/attendance-and-academic-management-system/src/Export/Sf2Exporter.php), including Enrolment (1st Friday), End-of-Month Registered Learners, Percentage of Enrolment, Average Daily Attendance (ADA), Percentage of Attendance (% PA), 5-consecutive-day absences counter, and NLS/NLPA/transfer placeholders across Male, Female, and Combined totals.
  - Added automated calculation and population of **Remarks (Column `AV`)** for learners with 5+ consecutive absences, cutting class records, or custom learner remarks.
  - Populated Adviser and School Head / Principal signature lines in cells `AS80` and `AS85` alongside report month (`AR61`) and school days count (`AU61`).
  - Added **"Cutting Class"** tracking support with dedicated UI buttons, KPI card, and cycle transitions across [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php), [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php), and [`assets/css/role.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/role.css).
  - Added automated tests in [`tests/Sf2ExporterTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/Sf2ExporterTest.php) verifying summary calculations, remarks column population, cutting marks (`▄`), and sign-off blocks.

## v0.3.84 — 2026-08-22

### Changed

- **Vector SVG Icon on Offline Connection-Lost Screen**:
  - Replaced Unicode emoji `"📶"` in the [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js) offline fallback page with an inline vector SVG disconnected-wifi icon container.
  - Bumped Service Worker cache to **`bshs-ams-v29`**.
  - Added unit test assertion in [`tests/PwaAssetFreshnessTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PwaAssetFreshnessTest.php).

## v0.3.83 — 2026-08-22

### Added

- **First-Open Push Notification Permission Onboarding Prompt**:
  - Added `#pushPromptModal` to [`includes/modals.php`](file:///c:/laragon/www/attendance-and-academic-management-system/includes/modals.php) with clear highlights for real-time attendance, grade releases, and announcements, offering explicit **Allow Notifications** and **Later** choices.
  - Implemented `initPwaPushFirstOpenPrompt()` in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) to trigger the onboarding prompt on first load when notification permission is unprompted (`default`).
  - Added modal and feature-list component styling in [`assets/css/main.css`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/css/main.css) with responsive layout and dark-mode support.
  - Added test coverage in [`tests/PwaAssetFreshnessTest.php`](file:///c:/laragon/www/attendance-and-academic-management-system/tests/PwaAssetFreshnessTest.php).

## v0.3.82 — 2026-08-21

### Fixed

- **Instant Flicker-Free PWA Launch**:
  - Added instant synchronous offline redirect check in `<head>` of [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php).
  - Added pre-paint visual mask ensuring zero flash or glitch of the login form during cold-start offline launches.
  - Bumped Service Worker cache to **`bshs-ams-v28`**.

## v0.3.81 — 2026-08-21

### Removed

- **Removed In-Page Offline Banners**:
  - Removed all banner notices from [`teacher/teacher.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher.php), [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php), [`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php), [`teacher/teacher_Reports.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Reports.php), and [`teacher/teacher_Chat.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Chat.php).
  - Bumped Service Worker cache to **`bshs-ams-v27`** for complete client-side cache refresh.

## v0.3.80 — 2026-08-20

### Added

- **Persistent Header Offline Badge & In-Page Offline Workspace Guidance**:
  - Added global topbar `Offline Mode` status badge in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) that appears automatically whenever disconnected.
  - Added in-page status banners in [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php) and [`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php) confirming that offline attendance and score entries are saved locally.
  - Added in-page connection-required guidance on [`teacher/teacher_Reports.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Reports.php) with quick action links to Attendance and Activities.
  - Bumped Service Worker cache to **`bshs-ams-v26`**.

## v0.3.79 — 2026-08-20

### Removed

- **Deleted Separate `offline.html` Application and Standalone Offline Hubs**:
  - Removed `offline.html` completely from repository and cleared all cache entries.
  - Removed standalone offline workspace button and hub rendering from [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php).

### Added

- **Client-Side Link Guard for Offline Browsing**:
  - Added proactive offline click interception in [`assets/js/main.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/main.js) to display toast guidance when clicking online-only links while disconnected.
  - Bumped Service Worker cache to **`bshs-ams-v25`**.

## v0.3.78 — 2026-08-20

### Changed

- **Unified Direct Offline Teacher Application Architecture**:
  - Integrated direct launch of cached [`teacher/teacher.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher.php) upon offline cold start when authenticated session is present.
  - Added automatic offline class and roster hydration in [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php) and [`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php).
  - Pre-warmed cache coverage for all teacher routes including `teacher_Archives.php`.

## v0.3.77 — 2026-08-20

### Fixed

- **Automatic Authenticated Shell Cache Warming on Login**:
  - Added background cache warming in [`assets/js/offlineStorage.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/offlineStorage.js) during `bootstrapOnline()` to pre-fetch and store verified `200 OK` HTML shells for all teacher subpages (`teacher_Attendance.php`, `teacher_Classes.php`, `teacher_Grades.php`, `teacher.php`).
  - Updated Service Worker navigation fetch handler in [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js) with dual-key relative/absolute matching and `{ ignoreSearch: true }` search resilience.
  - Bumped Service Worker cache to **`bshs-ams-v24`**.

## v0.3.76 — 2026-08-20

### Changed

- **Polished Dedicated Offline Teacher Workspace Hub on App Launch**:
  - Automatically hides the login form, footer, and subtext when a valid offline teacher session is detected in [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php), showing only the authenticated Teacher Workspace Hub.
  - Provided direct action cards to **Daily Attendance** (`teacher_Attendance.php`), **Grade Activities** (`teacher_Classes.php`), and **Teacher Dashboard** (`teacher.php`).
  - Added explicit inline dimensions on seal image and bumped Service Worker cache to **`bshs-ams-v23`**.

## v0.3.75 — 2026-08-20

### Fixed

- **Eliminated Cold-Start Redirect Loops & Fixed Pre-Cache Behavior**:
  - Removed protected teacher PHP endpoints from Service Worker installation pre-caching to prevent caching unauthenticated `302 Redirect` responses under teacher URLs.
  - Replaced client-side blind redirect in [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php) with an immediate, interactive offline workspace launchpad to completely prevent `ERR_TOO_MANY_REDIRECTS`.
  - Added guaranteed static offline HTML fallback in Service Worker navigation fetch handler.
  - Bumped Service Worker cache to **`bshs-ams-v22`**.

## v0.3.74 — 2026-08-20

### Added

- **Complete Teacher Classes Grade Activity & Score Offline Recording**:
  - Implemented client-side offline creation of grade activities and student score entry in [`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php) via IndexedDB `activity_records` and `sync_queue`.
  - Pre-cached [`teacher/teacher_Classes.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Classes.php) under cache **`bshs-ams-v21`**.
  - Localized all camera QR scanning libraries and dependencies within the pre-cached application shell.

## v0.3.73 — 2026-08-20

### Added

- **Complete Teacher Grades Offline Hydration & Activity Sync**:
  - Implemented client-side offline hydration in [`teacher/teacher_Grades.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Grades.php) to automatically load assigned classes and enrolled student rosters from IndexedDB when offline.
  - Updated Service Worker navigation fallback to seamlessly route offline root `/` and `/index.php` launches directly into the cached teacher portal dashboard.
  - Bumped Service Worker cache to **`bshs-ams-v20`**.

## v0.3.72 — 2026-08-20

### Added

- **Unified Offline-First Real Teacher Application**:
  - Pre-cached the real teacher application shells ([`teacher/teacher.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher.php), [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php), [`teacher/teacher_Grades.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Grades.php), [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php)) under cache **`bshs-ams-v19`**.
  - Updated Service Worker navigation routing to employ **Network-First with Exact Application Shell Cache Fallback**, guaranteeing teachers remain on the exact same portal URL and UI whether online or offline without falling back to generic offline screens.
  - Implemented in-page offline hydration in [`teacher/teacher_Attendance.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Attendance.php) to automatically load assigned classes, enrolled student rosters, local attendance marks, and camera QR scanning directly from IndexedDB when offline.
  - Updated [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php) to automatically route offline users to the real teacher dashboard when a local authenticated teacher session is detected.

## v0.3.71 — 2026-08-20

### Added

- Built **Production Offline Teacher Application Shell** in [`offline.html`](file:///c:/laragon/www/attendance-and-academic-management-system/offline.html) that executes 100% client-side from IndexedDB without contacting PHP or MySQL:
  - Automatically loads the authenticated teacher's real assigned classes and enrolled student rosters.
  - Supports offline Daily Attendance recording and offline Camera QR Code scanning with live roster matching.
  - Supports offline Grade Activities creation and student score encoding (WW, PT, QA).
  - Persists all saves locally to IndexedDB `attendance_records` and `activity_records` with local timestamps and pending sync statuses.
  - Seamlessly survives PWA closes, reboots, and complete offline restarts.
- Enhanced **IndexedDB Client Engine v2** in [`assets/js/offlineStorage.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/offlineStorage.js) supporting `teacher_session`, `teacher_classes`, `class_rosters`, `attendance_records`, `activity_records`, and `sync_queue`.
- Updated **Background Auto-Sync Engine** in [`assets/js/networkSync.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/networkSync.js) to submit pending offline records upon internet restoration with idempotency protection, mark records as `synced` in IndexedDB, and refresh local rosters.
- Bumped Service Worker cache to `bshs-ams-v18`.

## v0.3.70 — 2026-08-20

### Fixed

- Updated `sw.js` navigation handler to strictly bypass stale cached dynamic PHP responses and directly serve [`offline.html`](file:///c:/laragon/www/attendance-and-academic-management-system/offline.html) when the device is offline, ensuring the offline workspace opens immediately upon launching the PWA without internet.
- Added automatic offline redirection and an explicit **Open Offline Workspace** launch button on [`auth/login.php`](file:///c:/laragon/www/attendance-and-academic-management-system/auth/login.php) so opening the app offline from the login screen immediately switches to the offline attendance/activities workspace.
- Bumped Service Worker cache to `bshs-ams-v17`.

## v0.3.68 — 2026-08-20

### Added

- Built **IndexedDB & LocalStorage Client Storage Engine** ([`assets/js/offlineStorage.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/offlineStorage.js)) with offline teacher profile, assigned classes, student rosters, offline submissions, and sync queue.
- Added dual-module **Offline Workspace** in [`offline.html`](file:///c:/laragon/www/attendance-and-academic-management-system/offline.html) supporting:
  - Daily Attendance taking and Camera QR scanning offline.
  - Grade Activities and student score entry offline (Written Works, Performance Tasks, Quarterly Assessments).
- Added `action=offline_bootstrap` and `action=save_offline_activity` endpoints in [`teacher/teacher_Action.php`](file:///c:/laragon/www/attendance-and-academic-management-system/teacher/teacher_Action.php) for instant roster population and multi-item score synchronization.
- Updated [`assets/js/networkSync.js`](file:///c:/laragon/www/attendance-and-academic-management-system/assets/js/networkSync.js) to automatically submit both offline attendance records and offline activity score sets upon internet reconnection.
- Updated [`sw.js`](file:///c:/laragon/www/attendance-and-academic-management-system/sw.js) to pre-cache `offlineStorage.js` under cache `bshs-ams-v16`.

## v0.3.67 — 2026-08-20

### Added

- Built full **Cold-Start Offline Teacher Attendance Workspace** inside [`offline.html`](file:///c:/laragon/www/attendance-and-academic-management-system/offline.html) allowing teachers to choose cached classes, cycle attendance marks (Present/Absent/Late), scan QR codes offline with camera, and save submissions locally with zero internet connection.
- Bundled `html5-qrcode.min.js` locally under `assets/vendor/html5-qrcode/` and pre-cached it in `sw.js` under `bshs-ams-v15` so camera QR scanning works completely offline without CDN dependencies.
- Added automatic background teacher class and student roster persistence in `teacher/teacher_Attendance.php` to keep rosters available in local storage.

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

- Updated `src/Export/Sf9Exporter.php` to format official DepEd SF9-SHS 2-page / 4-panel booklet layout matching official DepEd guidelines (`depedph.com`), including 11-month attendance grid, parent signature blocks, Certificate of Transfer, Semester 1 & 2 subject tables, Observed Values ratings (_Maka-Diyos_, _Makatao_, _Makakalikasan_, _Makabansa_), and Grading Scale legend.
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
