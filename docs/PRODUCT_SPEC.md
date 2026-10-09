# Product Specification: Balingasag SHS AMS

**System Name:** Balingasag Senior High School - Attendance and Academic Management System (BSHS AMS)  
**Document Version:** 3.0.3
**Standard Compliance:** ISO/IEC/IEEE 29148:2018 (Systems and software engineering — Life cycle processes — Requirements engineering)  
**Status:** Approved  

---

## 1. Product Overview

The Balingasag Senior High School Attendance and Academic Management System (BSHS AMS) is an integrated web and Progressive Web Application (PWA) platform designed for managing student attendance, academic grading under the DepEd Senior High School curriculum, official DepEd form generation (SF1, SF2, SF5, SF9, ECR), and school stakeholder communication (Adviser-Parent messaging).

### 1.1 Scope & Purpose
The system serves five primary user roles:
- **Principal**: Subject-grade verification, report-card endorsement, academic/attendance monitoring, and read-only system activity oversight.
- **Administrators**: Operational setup, non-Principal user lifecycle, final report-card release, curriculum mapping, DepEd reporting, and audit logs.
- **Subject Teachers & Advisers**: Attendance recording, score tracking, DepEd ECR import/export, grade submission to the Principal, advisory report-card compilation, and parent communication.
- **Students**: Class schedules, score transparency, attendance history, PWA QR identity card, and released report cards.
- **Parents / Guardians**: Linked student academic monitoring, attendance notifications, report cards, and direct adviser messaging.

---

## 2. Requirements Specification (ISO/IEC/IEEE 29148)

| Requirement ID | Category | Description | Rationale | Acceptance Criteria |
| :--- | :--- | :--- | :--- | :--- |
| **REQ-001** | Security | The system shall enforce centralized Role-Based Access Control (RBAC) on every HTTP request and handler action. | Prevents unauthorized role privilege escalation across Principal, administrative, teaching, student, and parent surfaces. | Unauthorized route or action access attempts return HTTP 403 or redirect to login; page mappings in `permissionForScript()` and action mappings in `permissionForScriptAction()` are evaluated before handler execution. |
| **REQ-002** | Security & Compliance | The system shall store session tokens and authentication state in the database when `APP_SESSION_DRIVER=database`. | Enables session persistence across stateless cloud instances (e.g., Wasmer Edge) without relying on local server files. | Active sessions remain valid across container restarts; session data is queryable in `php_sessions` table. |
| **REQ-003** | Data Privacy | The system shall redact sensitive values and log critical Principal, Admin, and Teacher transactions to `activity_logs`. | Establishes cross-role accountability without storing credentials, tokens, full contact details, or notification content. | Critical mutations store actor ID/role, action, target, sanitized metadata, IP address, and timestamp; legacy admin history remains readable. |
| **REQ-004** | DepEd Integration | The system shall import and export official DepEd Excel forms (SF1, SF2, SF5, SF9, ECR) preserving official row/column cell mappings. | Ensures compatibility with Department of Education reporting standards. | Official SF1, SF2, and ECR `.xlsx` files parse without structure errors; generated exports match DepEd template dimensions. |
| **REQ-005** | Grading Workflow | The system shall enforce the workflow `submitted` → `admin_verified` → `submitted_admin` → `pending` → `approved` across Subject Teacher, Principal, Adviser, Principal, and Admin respectively. | Separates academic verification and endorsement from operational final release. | Principal verifies subject grades; Adviser submits compiled cards; Principal endorses them; Admin alone releases them; family portals display only `approved` records. |
| **REQ-006** | Grading Recall | The system shall allow subject teachers to recall pending grade submissions while in `submitted` status, and auto-invalidate downstream approved report cards upon re-submission. | Ensures grade corrections update official records while preventing stale final report cards from being viewed. | Re-submitting a previously approved subject grade sets affected report cards to `rejected` until approved again. |
| **REQ-007** | PWA & Offline | The system shall support durable offline attendance submission with account-owned local queueing and synchronization recovery. | Enables teachers to mark attendance during network interruptions without losing or misattributing records. | A save is acknowledged only after IndexedDB or account-scoped localStorage persistence succeeds; queued work synchronizes only under the same authenticated teacher account. |
| **REQ-008** | Web Push | The system shall support browser Web Push API notifications for student attendance events and grade publication. | Provides immediate notification to parents and students regarding attendance anomalies and academic updates. | Device subscriptions saved in `push_subscriptions` receive push payloads signed with VAPID keys. |
| **REQ-009** | UI/UX Standard | The system shall maintain an accessible visual design system supporting high contrast, dark mode, and responsive layouts. | Adheres to UI/UX Engineering & Design Standards (§9 & §10). | UI components utilize tokens defined in `assets/css/main.css`; contrast ratios meet WCAG AA standards across light and dark themes. |
| **REQ-010** | Communication | The system shall restrict parent chat contacts exclusively to the section adviser of their linked students. | Protects teacher privacy while maintaining clear communication channels with section advisers. | Parent chat directory lists only advisers of currently enrolled section classes for linked children. |

### 2.1 Protected Principal Account Requirement

- **Requirement ID:** REQ-011
- **Category:** Security and separation of duties
- **Description:** The system shall provision the Principal as a deployment-owned protected account and shall deny Administrator attempts to create, edit, reset, activate, deactivate, or archive any Principal account through web or API user-management interfaces.
- **Rationale:** Prevents an Administrator from assuming the Principal identity and bypassing independent academic verification and endorsement authority.
- **Source:** Capstone defense follow-up clarification, October 8, 2026
- **Priority:** High
- **Acceptance criteria:** The Principal account is seeded from the same `DEFAULT_NEW_USER_PASSWORD` used for every role; Admin interfaces expose it read-only; direct Admin mutation requests return a denial; Principal self-service profile changes, password changes, and password recovery remain available; web, remembered-session, and API login require the default password to be replaced before portal access.
- **Traceability:** `src/User/SystemAccountPolicy.php`, `database/seed_admin.php`, `scripts/generate_database_setup_sql.php`, `database/schema.sql`, `admin/admin_Users_Action.php`, `api/routes/06-admin.php`, and `tests/PrincipalAccountProtectionTest.php`.

### 2.2 Principal Portal Navigation Requirement

- **Requirement ID:** REQ-012
- **Category:** Principal workflow and usability
- **Description:** The system shall provide separate Principal pages for the report-card overview, pending review queue, released cards, and completed decision history.
- **Rationale:** Each sidebar destination must represent a predictable workspace instead of reloading one page with an implicit status filter.
- **Source:** Developer clarification, October 8, 2026
- **Priority:** High
- **Acceptance criteria:** Each sidebar item resolves to a distinct protected URL, independently rendered workspace, and correct active state; the pending page presents a readiness-focused action queue with release and return controls; the released page presents a family-visibility register with withdrawal controls; the history page presents a read-only chronological audit trail; all four pages require `report_cards.review`.
- **Traceability:** `principal/principal.php`, `principal/principal_Pending.php`, `principal/principal_Released.php`, `principal/principal_History.php`, `src/Grade/PrincipalReportCardQuery.php`, `functions/app-helpers.php`, and `tests/PrincipalNavigationTest.php`.

### 2.3 Principal Monitoring Requirement

- **Requirement ID:** REQ-013
- **Category:** Stakeholder requirement / monitoring
- **Description:** The system shall provide the Principal with read-only academic workflow summaries, attendance summaries, Admin-released report cards, and role-aware activity logs.
- **Rationale:** The defense panel identified school-wide monitoring as a Principal responsibility.
- **Source:** Capstone defense panel feedback, October 2026
- **Priority:** High
- **Acceptance criteria:** Principal navigation opens dedicated monitoring pages; attendance can be filtered by date, grade, and section; activity logs omit login diagnostics, IP addresses, credentials, and security tokens; monitoring pages contain no mutation controls.
- **Traceability:** `principal/principal_Academic_Monitoring.php`, `principal/principal_Attendance_Monitoring.php`, `principal/principal_Activity_Logs.php`, `functions/app-helpers.php`, `database/schema.sql`, and `tests/PrincipalNavigationTest.php`.

### 2.4 Attendance Status Requirement

- **Requirement ID:** REQ-014
- **Category:** System requirement / attendance
- **Description:** The system shall accept and display only Present, Absent, and Late attendance statuses.
- **Rationale:** The defense panel directed the team to remove the Cutting status.
- **Source:** Capstone defense panel feedback, October 2026
- **Priority:** High
- **Acceptance criteria:** Teacher controls cycle through three statuses; server requests reject any other status; summaries and SF2 exports contain no Cutting category or mark.
- **Traceability:** `database/schema.sql`, `teacher/teacher_Attendance.php`, `teacher/teacher_Action.php`, `src/Export/Sf2Exporter.php`, and `tests/Sf2ExporterTest.php`.

### 2.5 Admin Learner Information Requirement

- **Requirement ID:** REQ-015
- **Category:** System requirement / learner records and data privacy
- **Description:** The system shall provide authenticated Administrators with a read-only complete learner profile in Admin Enrollments while excluding authentication secrets from the response.
- **Rationale:** Administrators require the full stored student record for enrollment administration, but student records and account credentials require separate access boundaries.
- **Source:** Developer-reported Admin student-information visibility issue, October 9, 2026
- **Priority:** High
- **Acceptance criteria:** The student-details view groups identity, academic placement, contact and address, family and guardian, linked parent account, enrollment history, and account activity information; the modal remains usable on desktop and mobile screens; Admin Manage Users links to Student Records without listing students; the response contains no password, token-version, reset-token, or authentication-secret fields.
- **Traceability:** `admin/admin_Enrollments.php`, `admin/admin_Enrollments_Action.php`, `admin/admin_Users.php`, `includes/modals/enrollment_modals.php`, and `tests/AdminUsersStudentExclusionAndEnrollmentsRefCodeTest.php`.

### 2.6 Offline Reliability Requirement

- **Requirement ID:** REQ-016
- **Category:** System requirement / offline reliability and data integrity
- **Description:** The system shall durably preserve teacher offline attendance and grade-activity work, synchronize it under the owning account, and expose pending or failed synchronization state to the teacher.
- **Rationale:** Connectivity indicators and background execution are not reliable enough to guarantee that unsaved or rejected academic records will recover without explicit persistence and user feedback.
- **Source:** Developer-requested offline feature review and approved remediation plan, October 9, 2026
- **Priority:** High
- **Acceptance criteria:** Offline saves report success only after a durable local write; each locally created grade activity carries a stable client operation ID and repeated delivery creates no duplicate while separate same-title/same-date activities remain distinct; attendance registers Background Sync; transient failures receive no more than five automatic attempts before becoming visible failures; permanent validation failures become visible immediately; manual Retry Sync and confirmation-protected Clear Local Data controls are available; manual attendance is restricted by cached schedules and class/date snapshots; QR scanning is unavailable offline; server account ownership, CSRF, class ownership, schedule, and record validation remain authoritative.
- **Traceability:** `sw.js`, `assets/js/offlineStorage.js`, `assets/js/networkSync.js`, `teacher/teacher_Attendance.php`, `teacher/teacher_Classes.php`, `teacher/teacher_Action.php`, `tests/browser-security.cjs`, `tests/QrAttendanceStatusTest.php`, and `tests/TeacherPwaOfflineLifecycleTest.php`.

### 2.7 Notification Consistency Requirement

- **Requirement ID:** REQ-017
- **Category:** System requirement / notification integrity
- **Description:** The system shall save authoritative in-app notifications before attempting optional device push delivery and shall not release an official report card when its required saved notifications cannot be persisted.
- **Rationale:** Device push is best-effort, while the in-app record is the durable notification channel and must remain consistent with official release state.
- **Source:** System review remediation plan, October 9, 2026
- **Priority:** High
- **Acceptance criteria:** Notification dispatch returns failure when saved-notification persistence fails and does not attempt push for that failed delivery; Admin final release and its saved family/staff notifications commit or roll back together; device push occurs only after successful persistence and does not roll back an already committed primary event.
- **Traceability:** `functions/app-helpers.php`, `src/Grade/AdminReportCardRelease.php`, `tests/NotificationDeliveryTest.php`, and `tests/PrincipalReportCardWorkflowTest.php`.

---

## 3. User Personas & Workflows

### 3.1 Administrator Workflow
```
[ Login ] ──► [ Dashboard KPI ] ──► [ Users / Sections / Classes ]
                                           │
                                           ▼
[ Audit Logs ] ◄── [ Verify Grades ] ◄── [ SF1/SF2 Reports ]
```

### 3.2 Principal Workflow
```
[ Login ] -> [ Verify Subject Grades ] -> [ Endorse Report Cards to Admin ]
    |                    |                              |
    +-> [ Academic / Attendance Monitoring ]           +-> [ Read-only Decision History ]
    +-> [ Privacy-filtered Activity Logs ]              +-> [ Monitor Admin Releases ]
```

### 3.3 Teacher & Adviser Workflow
```
[ Login ] ──► [ Select Class ] ──► [ Mark Attendance ] ──► (Offline Queue / Online Push)
                    │
                    ▼
          [ Grade Activities ] ──► [ Submit to Principal ] ──► (Lock Editing)
                    │
                    ▼
          [ Adviser Chat ] ◄── [ Parent Messages ]
```

### 3.4 Student & Parent Workflow
```
[ Parent Login ] ──► [ Select Linked Child ] ──► [ Attendance / Grade Activity Feed ]
                                                          │
                                                          ▼
                                                [ Adviser Chat Contact ]
```

---

## 4. Environment & Deployment Constraints

- **PHP Version:** PHP 8.2+ with `pdo_mysql`, `mbstring`, `json`, `zip`, `dom`, `curl`, `openssl`.
- **Database:** Wasmer Attached Database for production, with local MySQL/MariaDB for development.
- **Runtimes:** Local development via PHP CLI dev server (`composer run serve`), production stateless container runtime via Wasmer Edge (`wasmer.toml`, `app.yaml`).
