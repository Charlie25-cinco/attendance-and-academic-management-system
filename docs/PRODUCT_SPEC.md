# Product Specification: Balingasag SHS AMS

**System Name:** Balingasag Senior High School - Attendance and Academic Management System (BSHS AMS)  
**Document Version:** 2.1.0
**Standard Compliance:** ISO/IEC/IEEE 29148:2018 (Systems and software engineering — Life cycle processes — Requirements engineering)  
**Status:** Approved  

---

## 1. Product Overview

The Balingasag Senior High School Attendance and Academic Management System (BSHS AMS) is an integrated web and Progressive Web Application (PWA) platform designed for managing student attendance, academic grading under the DepEd Senior High School curriculum, official DepEd form generation (SF1, SF2, SF5, SF9, ECR), and school stakeholder communication (Adviser-Parent messaging).

### 1.1 Scope & Purpose
The system serves five primary user roles:
- **Principal**: Final report-card review, release, return, and withdrawal.
- **Administrators**: Operational setup, non-Principal user lifecycle, curriculum mapping, grade approval governance, DepEd reporting, and audit logs.
- **Subject Teachers & Advisers**: Attendance recording, score tracking, DepEd ECR import/export, grade submission to admin, advisory section management, and parent communication.
- **Students**: Class schedules, score transparency, attendance history, PWA QR identity card, and released report cards.
- **Parents / Guardians**: Linked student academic monitoring, attendance notifications, report cards, and direct adviser messaging.

---

## 2. Requirements Specification (ISO/IEC/IEEE 29148)

| Requirement ID | Category | Description | Rationale | Acceptance Criteria |
| :--- | :--- | :--- | :--- | :--- |
| **REQ-001** | Security | The system shall enforce centralized Role-Based Access Control (RBAC) on every HTTP request. | Prevents unauthorized role privilege escalation across Principal, administrative, teaching, student, and parent surfaces. | Unauthorized route access attempts return HTTP 403 or redirect to login; all script-permission mappings in `app-helpers.php` are evaluated prior to execution. |
| **REQ-002** | Security & Compliance | The system shall store session tokens and authentication state in the database when `APP_SESSION_DRIVER=database`. | Enables session persistence across stateless cloud instances (e.g., Wasmer Edge) without relying on local server files. | Active sessions remain valid across container restarts; session data is queryable in `php_sessions` table. |
| **REQ-003** | Data Privacy | The system shall redact sensitive values and log critical Principal, Admin, and Teacher transactions to `activity_logs`. | Establishes cross-role accountability without storing credentials, tokens, full contact details, or notification content. | Critical mutations store actor ID/role, action, target, sanitized metadata, IP address, and timestamp; legacy admin history remains readable. |
| **REQ-004** | DepEd Integration | The system shall import and export official DepEd Excel forms (SF1, SF2, SF5, SF9, ECR) preserving official row/column cell mappings. | Ensures compatibility with Department of Education reporting standards. | Official SF1, SF2, and ECR `.xlsx` files parse without structure errors; generated exports match DepEd template dimensions. |
| **REQ-005** | Grading Workflow | The system shall enforce a 4-tier grade approval state machine (`submitted` → `admin_verified` → `submitted_admin` → `approved`) with the Principal as the sole final reviewer. | Prevents unverified grade changes and separates academic verification from official release authority. | Admin verifies subject grades; adviser submits; only an active Principal with `report_cards.review` may release, return, or withdraw; family portals display only `approved` records. |
| **REQ-006** | Grading Recall | The system shall allow subject teachers to recall pending grade submissions while in `submitted` status, and auto-invalidate downstream approved report cards upon re-submission. | Ensures grade corrections update official records while preventing stale final report cards from being viewed. | Re-submitting a previously approved subject grade sets affected report cards to `rejected` until approved again. |
| **REQ-007** | PWA & Offline | The system shall support offline attendance submission with local queueing and sync upon network recovery. | Enables teachers to mark attendance during network interruptions without losing records. | Submissions queue in `localStorage` when offline and submit automatically to `teacher_Action.php` when connectivity restores. |
| **REQ-008** | Web Push | The system shall support browser Web Push API notifications for student attendance events and grade publication. | Provides immediate notification to parents and students regarding attendance anomalies and academic updates. | Device subscriptions saved in `push_subscriptions` receive push payloads signed with VAPID keys. |
| **REQ-009** | UI/UX Standard | The system shall maintain an accessible visual design system supporting high contrast, dark mode, and responsive layouts. | Adheres to UI/UX Engineering & Design Standards (§9 & §10). | UI components utilize tokens defined in `assets/css/main.css`; contrast ratios meet WCAG AA standards across light and dark themes. |
| **REQ-010** | Communication | The system shall restrict parent chat contacts exclusively to the section adviser of their linked students. | Protects teacher privacy while maintaining clear communication channels with section advisers. | Parent chat directory lists only advisers of currently enrolled section classes for linked children. |

### 2.1 Protected Principal Account Requirement

- **Requirement ID:** REQ-011
- **Category:** Security and separation of duties
- **Description:** The system shall provision the Principal as a deployment-owned protected account and shall deny Administrator attempts to create, edit, reset, activate, deactivate, or archive any Principal account through web or API user-management interfaces.
- **Rationale:** Prevents an Administrator from assuming the Principal identity and bypassing independent final report-card authority.
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
- **Acceptance criteria:** Each sidebar item resolves to a distinct protected URL and displays the correct active state; the pending page permits release and return; the released page permits withdrawal; the history page is read-only; all four pages require `report_cards.review`.
- **Traceability:** `principal/principal.php`, `principal/principal_Pending.php`, `principal/principal_Released.php`, `principal/principal_History.php`, `includes/principal-report-card-page.php`, `src/Grade/PrincipalReportCardQuery.php`, `functions/app-helpers.php`, and `tests/PrincipalNavigationTest.php`.

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
[ Login ] ──► [ Principal Dashboard ] ──► [ Pending Review ] ──► [ Release / Return ]
                       │                                           │
                       ├──► [ Released Cards / Withdraw ]          ▼
                       └──► [ Read-only Decision History ] [ Family Notifications ]
```

### 3.3 Teacher & Adviser Workflow
```
[ Login ] ──► [ Select Class ] ──► [ Mark Attendance ] ──► (Offline Queue / Online Push)
                    │
                    ▼
          [ Grade Activities ] ──► [ Submit to Admin ] ──► (Lock Editing)
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
