# Balingasag Senior High School Attendance and Academic Management System (BSHS AMS)
## Comprehensive System Features and Capabilities Document

> **Document Purpose:** Academic Research Paper Reference / Capstone Technical Documentation  
> **System Name:** Balingasag Senior High School - Attendance and Academic Management System (BSHS AMS)  
> **Standard Compliance:** ISO/IEC/IEEE 29148:2018 (Requirements Engineering)  
> **Version:** 3.0.0
> **Date:** October 2026

---

## 1. Executive Summary & Architecture Overview

The **Balingasag Senior High School Attendance and Academic Management System (BSHS AMS)** is an integrated, full-stack web and Progressive Web Application (PWA) tailored specifically to the administrative, grading, attendance, and communication workflows mandated by the **Department of Education (DepEd) Senior High School (SHS)** curriculum in the Philippines.

The platform provides a centralized, role-based ecosystem connecting the **Principal**, **School Administrators**, **Subject Teachers & Section Advisers**, **Students**, and **Parents/Guardians**. It automates compliance with DepEd standard school forms (SF1, SF2, SF5, SF9, and ECR), supports server-authoritative QR attendance tracking with offline capabilities, enforces a multi-tier grade governance workflow, and supports stakeholder communication through saved in-app/Web Push notifications and private adviser-parent messaging.

---

## 2. Core (Main) System Features

### 2.1 DepEd-Compliant Academic Grading & Assessment Engine
- **Three-Term & Two-Semester Support:** Full support for Senior High School grading models, dividing academic terms into standard grading periods (1st, 2nd, and 3rd Terms / Semesters).
- **Weighted Component Grading:** Computes raw scores based on official DepEd assessment components:
  - *Written Work (WW)*
  - *Performance Tasks (PT)*
  - *Quarterly Assessment (QA)*
  - Dynamic percentage weights tailored by track and subject classification (Core, Applied, Specialized).
- **Transmutation Table Computation:** Server-side transmutation of initial percentage grades to official final ratings based on DepEd transmutation guidelines, stored immutably in `grades.final_grade`.
- **Electronic Class Record (ECR) Integration:**
  - Import, preview, and parse official DepEd ECR (`.xlsx` / `.xlsm`) templates.
  - Automated calculation of quarterly and final grades directly from uploaded workbooks.
  - Direct export of consolidated student ratings into DepEd-formatted ECR workbooks.
- **5-Stage Grade Approval & Governance State Machine:**
  1. **Subject Teacher Submission (`submitted`):** Teachers submit grades per subject/section; input fields lock during Principal review.
  2. **Principal Subject Verification (`admin_verified`):** The Principal verifies or returns subject grades. The legacy storage name is retained for compatibility.
  3. **Adviser Report Card Consolidation (`submitted_admin`):** Section advisers consolidate verified subject grades and submit report cards to the Principal.
  4. **Principal Endorsement (`pending`):** The Principal verifies the complete card and endorses it to Admin, or returns it for correction.
  5. **Admin Final Release (`approved`):** Admin releases the official report card to Student and Parent portals, or returns/withdraws it for correction.
- **Grade Recall & Stale Data Invalidation:** If an approved subject grade requires post-release correction, re-submission automatically reverts affected report cards to `rejected` status, immediately masking stale final grades from student/parent portals until re-approved.
- **Learner Progress Report Card (DepEd SF9) Generation:** Printable, formatted PDF/HTML report cards showing quarterly grades, general averages, attendance summaries, and core values observation ratings.

---

### 2.2 Attendance Tracking & Management Engine
- **Multi-Modal Attendance Recording:**
  - **Teacher Web Marking:** Daily class and advisory attendance recording with status indicators (*Present*, *Late/Tardy*, *Absent*). Additional context is stored in remarks.
  - **Server-Authoritative QR Code Scanner:** Instant QR code badge scanning via device camera (`teacher_Attendance.php`), utilizing `Asia/Manila` server time to automatically classify students as *Present* or *Late* (based on a configurable 15-minute grace threshold).
- **Offline Attendance Capture & Auto-Sync:**
  - Teacher-owned IndexedDB with account-scoped localStorage fallback allows manual attendance on cached scheduled dates without active internet.
  - Durable saves register Background Sync and also retry on reconnection or application startup; teachers can inspect pending/failed counts and retry explicitly.
  - Attendance snapshots are keyed by class and date, while QR scanning remains online-only for server-authoritative Present/Late classification.
  - Offline grade activities use teacher-scoped client operation IDs so retries are idempotent while separate same-title/same-date activities remain distinct.
- **DepEd School Form 2 (SF2) Daily Attendance Export:**
  - Fully automated XLSX generation strictly following the official `deped/SF2_Senior_High_School.xlsx` template.
  - Preserves merged headers, summary formulas (rows 60–89), dynamic Monday–Saturday day anchors, and official DepEd attendance symbols (Blank = Present, `X` = Absent, Upper-half block = Late).
  - Automated multi-sheet cloning for sections with learner counts exceeding single-sheet capacity.
- **Delta-Only Attendance Notifications:** Smart notification dispatcher that compares previous records and triggers push/in-app notices *only* for students whose attendance status changed or was newly marked, preventing spam.

---

### 2.3 Learner Information & Enrollment Management
- **DepEd School Form 1 (SF1) School Register Import/Export:**
  - Direct parsing of official DepEd SF1 Excel templates.
  - Intelligent extraction of combined learner name fields (`Last Name, First Name, Middle Name, Name Extension`) and 12-digit Learner Reference Numbers (LRN).
  - Multi-component address normalization (`house_street`, `barangay`, `municipality`, `province`).
- **Curriculum & Track Mapping:** Full support for Senior High School Academic and Technical-Professional (TechPro) tracks, strands (e.g., STEM, ABM, HUMSS, TVL), grade levels (11 & 12), and active school years.
- **Automated Reference Code & Identity Generation:** Unique, standardized reference codes auto-generated for students, teachers, and parents (e.g., `STD-2026-0001`, `TCH-2026-0001`, `PAR-2026-0001`).

---

### 2.4 Progressive Web App (PWA) & Offline Capabilities
- **Cross-Platform Installability:** Installs natively on Android, iOS, Windows, macOS, and ChromeOS with custom splash screens and masked school seal icons.
- **Root Service Worker (`sw.js`):** Implements a Network-First caching strategy for assets and application shells, enabling offline page loading and asset resilience.
- **PWA Digital Student ID Badge:** Dynamic in-app student ID card with an embedded, secure QR code used for rapid morning and class attendance scanning.
- **Persistent PWA Authentication:** Database-backed sessions with trusted-device remember-me options designed specifically for standalone PWA instances.

---

### 2.5 Multi-Channel Stakeholder Communication & Alerts
- **Private Adviser-Parent Chat System:**
  - Role-restricted 1-to-1 direct messaging connecting parents strictly with the official Section Adviser of their linked children.
  - Real-time conversation polling, unread count tracking, and support for file attachments (images, PDFs, documents).
- **Web Push Notifications (VAPID):** Real-time browser push notifications delivered to desktop and mobile devices via standard Web Push protocols for:
  - Daily attendance updates (absences, tardiness).
  - New grade activity announcements and recorded scores.
  - Official grade publication and report card releases.
  - Administrative grade recalls or schedule announcements.
- **Saved and Device Notifications:** Report-card release and its saved family/staff notifications commit atomically; optional Web Push delivery is attempted only after the database commit.
- **Targeted School & Class Announcements:** School-wide and section-level announcement boards with rich-text formatting, priority pinning, and role-based audience filters.

---

## 3. Secondary & Supporting System Features

### 3.1 Security & Access Control (RBAC)
- **Centralized Role-Based Access Control (RBAC):** Strict per-page and per-handler-action permission enforcement mapped across 5 roles: `principal`, `admin`, `teacher`, `student`, and `parent`; unavailable mutation controls are hidden or disabled in the relevant portal UI.
- **Triple-Layer CSRF Protection:** Protects all state-modifying requests via token validation across POST bodies, URL query parameters, and `X-CSRF-Token` headers.
- **Stateless Database Session Driver:** Stores active user sessions in SQL (`php_sessions`) to support seamless container deployment (e.g., Wasmer Edge, Docker) without losing login state across server restarts.
- **Immediate Token Version Revocation:** API bearer tokens enforce an `api_token_version` check against the database; changing or resetting a password immediately revokes all existing active tokens.
- **Secure Password Reset via OTP:** Hashed 6-digit One-Time Password (OTP) verification powered by Resend API with automated SMTP fallback.
- **First-Login Mandatory Password Setup:** Forces newly created users to establish a personal password before accessing portal features.

---

### 3.2 Learning Materials & Digital Document Repository
- **Secure File Storage Architecture:** Uploaded learning modules and lesson resources are stored in isolated server directories (`storage/materials`) rather than public web roots.
- **Authorization-Gated File Downloads:** Enforces strict verification—only the authoring teacher or actively enrolled students in the target class subject can access and download materials.
- **Modern File Upload UI:** Supports native file picker API (`showOpenFilePicker`), drag-and-drop zones, and clipboard paste with client-side file size and MIME-type validation.

---

### 3.3 Governance, Auditing & Administrative Tools
- **Role-Aware Activity Trail (`activity_logs`):** Logs critical role-aware mutations with actor role, target, sanitized metadata, IP address, and timestamp. Principal/Admin monitoring retains broader read-only oversight, while Teacher, Student, and Parent My Activity pages return only the signed-in account's records and omit IP and sensitive metadata. Legacy `admin_audit_logs` remains visible only in administrative history.
- **Authentication Login Logs (`auth_login_logs`):** Tracks successful and failed login attempts across the platform for forensic auditing.
- **Soft-Delete & Archive Restoration:** Safely archives deleted users, enrollments, classes, and sections into an archive repository, allowing one-click administrative restoration.
- **Universal Live Search & Filter:** Debounced, client-side and server-side search bars with quick-clear (`x`) buttons across all tables, modal lists, and enrollment registers.

---

### 3.4 Public School Portal Gateway (`site/index.php`)
- **Integrated Responsive School Website:** Public-facing landing page featuring school background, vision/mission, SHS track offerings, academic calendar, campus highlights, and faculty directories.
- **Unified Portal Authentication Entry:** Seamless single-point login gateway for administrative staff, faculty, students, and parents.

---

## 4. Feature Matrix by User Role

| Feature / Capability | Principal | Admin | Teacher / Adviser | Student | Parent / Guardian |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Manage Users & Role Assignments** | Self only | Yes, except protected Principal | No | No | No |
| **Curriculum & Section Configuration** | No | Yes | No | No | No |
| **SF1 Register Import / Export** | No | Yes | Yes (Adviser) | No | No |
| **Daily Attendance Marking** | No | View | Yes | View own | View linked |
| **QR Code Scanner for Attendance** | No | No | Yes | No | No |
| **Digital Student QR ID Badge** | No | No | No | Yes | No |
| **Offline Attendance Sync** | No | No | Yes | No | No |
| **SF2 Attendance Form Export** | No | Yes | Yes (Adviser) | No | No |
| **Record Activity Scores (WW/PT/QA)** | No | No | Yes | No | No |
| **DepEd ECR Excel Import / Export** | No | No | Yes | No | No |
| **4-Tier Grade Approval Governance** | Final review | Verify | Submit/compile | No | No |
| **Final Report Card Review & Release** | Yes | No | No | No | No |
| **View Published Report Cards (SF9)** | Yes | Yes | Yes | Yes | Yes |
| **Adviser-Parent Direct Messaging** | No | No | Yes (Adviser) | No | Yes |
| **Upload Learning Materials** | No | No | Yes | No | No |
| **Download Enrolled Subject Materials** | No | No | Own uploads | Yes | No |
| **Web Push Notifications** | Yes | Yes | Yes | Yes | Yes |
| **System-Wide Activity Logs & Archive Recovery** | Activity monitoring only | Yes | No | No | No |
| **Self-Only Activity History** | Covered by monitoring | Covered by audit logs | Yes | Yes | Yes |

---

## 5. Technical Specifications & Stack Details

- **Backend Architecture:** PHP 8.2+ / PHP 8.3 (Object-Oriented, PSR-4 Namespace `BshsAms\`, Clean MVC/Action Handler Pattern).
- **Database Engine:** MySQL 8.0 / MariaDB 10.11 with InnoDB Engine, UTF-8 MB4 charset, and prepared PDO transactions.
- **Frontend Layer:** Semantic HTML5, Vanilla JavaScript (ES6+), Bootstrap 5.3 UI framework, Bootstrap Icons, and CSS3 custom properties with Dark Mode support.
- **Progressive Web App:** W3C Manifest specification (`assets/manifest.json`), Root Service Worker (`sw.js`), Push API, Web Push with VAPID (`minishlink/web-push`).
- **Spreadsheet Processing:** Native OpenXML (`.xlsx`) parsing and templating engine for high-fidelity DepEd form generation.
- **Third-Party Integrations:**
  - *Resend API / SMTP* (Email OTP authentication)
  - *Wasmer Edge Platform* (Containerized serverless hosting)
