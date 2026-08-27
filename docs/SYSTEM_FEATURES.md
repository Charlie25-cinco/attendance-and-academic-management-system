# Balingasag Senior High School Attendance and Academic Management System (BSHS AMS)
## Comprehensive System Features and Capabilities Document

> **Document Purpose:** Academic Research Paper Reference / Capstone Technical Documentation  
> **System Name:** Balingasag Senior High School - Attendance and Academic Management System (BSHS AMS)  
> **Standard Compliance:** ISO/IEC/IEEE 29148:2018 (Requirements Engineering)  
> **Version:** 1.0.0  
> **Date:** August 2026  

---

## 1. Executive Summary & Architecture Overview

The **Balingasag Senior High School Attendance and Academic Management System (BSHS AMS)** is an integrated, full-stack web and Progressive Web Application (PWA) tailored specifically to the administrative, grading, attendance, and communication workflows mandated by the **Department of Education (DepEd) Senior High School (SHS)** curriculum in the Philippines.

The platform provides a centralized, role-based ecosystem connecting **School Administrators**, **Subject Teachers & Section Advisers**, **Students**, and **Parents/Guardians**. It automates compliance with DepEd standard school forms (SF1, SF2, SF5, SF9, and ECR), supports server-authoritative QR attendance tracking with offline capabilities, enforces a multi-tier grade governance workflow, and ensures real-time stakeholder communication through Web Push notifications, PhilSMS alerts, and private adviser-parent messaging.

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
- **4-Stage Grade Approval & Governance State Machine:**
  1. **Subject Teacher Submission (`submitted`):** Teachers submit grades per subject/section; input fields lock to prevent tampering during review. Teachers retain the option to recall pending submissions before admin action.
  2. **Admin Subject Verification (`admin_verified`):** Administrators review and verify subject grades. Admin can reject with feedback, unlocking the grade sheet for teacher correction.
  3. **Adviser Report Card Consolidation (`submitted_admin`):** Section advisers consolidate all verified subject grades into final report cards (SF9) and submit the section batch to the School Head/Admin.
  4. **Admin Final Approval & Publication (`approved`):** Final administrative approval officially releases grades to Student and Parent portals.
- **Grade Recall & Stale Data Invalidation:** If an approved subject grade requires post-release correction, re-submission automatically reverts affected report cards to `rejected` status, immediately masking stale final grades from student/parent portals until re-approved.
- **Learner Progress Report Card (DepEd SF9) Generation:** Printable, formatted PDF/HTML report cards showing quarterly grades, general averages, attendance summaries, and core values observation ratings.

---

### 2.2 Attendance Tracking & Management Engine
- **Multi-Modal Attendance Recording:**
  - **Teacher Web Marking:** Daily class and advisory attendance recording with status indicators (*Present*, *Late/Tardy*, *Absent*, *Excused*).
  - **Server-Authoritative QR Code Scanner:** Instant QR code badge scanning via device camera (`teacher_Attendance.php`), utilizing `Asia/Manila` server time to automatically classify students as *Present* or *Late* (based on a configurable 15-minute grace threshold).
- **Offline Attendance Capture & Auto-Sync:**
  - Built-in Service Worker and `localStorage` queue allow teachers to take attendance without active internet.
  - Submissions automatically synchronize to the database once network connectivity is restored.
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
- **PhilSMS Gateway Integration:** Automated SMS text message broadcast (`smsNotifyGradePublication`) directly to parent and student mobile numbers upon official admin release of quarterly report cards.
- **Targeted School & Class Announcements:** School-wide and section-level announcement boards with rich-text formatting, priority pinning, and role-based audience filters.

---

## 3. Secondary & Supporting System Features

### 3.1 Security & Access Control (RBAC)
- **Centralized Role-Based Access Control (RBAC):** Strict per-route permission enforcement mapped across 4 roles: `admin`, `teacher`, `student`, and `parent`.
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
- **Immutable Admin Audit Trail (`admin_audit_logs`):** Logs all administrative actions with actor ID, target entity, detailed action payload, IP address, and timestamp.
- **Authentication Login Logs (`auth_login_logs`):** Tracks successful and failed login attempts across the platform for forensic auditing.
- **Soft-Delete & Archive Restoration:** Safely archives deleted users, enrollments, classes, and sections into an archive repository, allowing one-click administrative restoration.
- **Universal Live Search & Filter:** Debounced, client-side and server-side search bars with quick-clear (`x`) buttons across all tables, modal lists, and enrollment registers.

---

### 3.4 Public School Portal Gateway (`site/index.php`)
- **Integrated Responsive School Website:** Public-facing landing page featuring school background, vision/mission, SHS track offerings, academic calendar, campus highlights, and faculty directories.
- **Unified Portal Authentication Entry:** Seamless single-point login gateway for administrative staff, faculty, students, and parents.

---

## 4. Feature Matrix by User Role

| Feature / Capability | Admin | Teacher / Adviser | Student | Parent / Guardian |
| :--- | :---: | :---: | :---: | :---: |
| **Manage Users & Role Assignments** | ✅ | ❌ | ❌ | ❌ |
| **Curriculum & Section Configuration** | ✅ | ❌ | ❌ | ❌ |
| **SF1 Register Import / Export** | ✅ | ✅ (Adviser) | ❌ | ❌ |
| **Daily Attendance Marking** | ✅ | ✅ | ❌ | ❌ |
| **QR Code Scanner for Attendance** | ❌ | ✅ | ❌ | ❌ |
| **Digital Student QR ID Badge** | ❌ | ❌ | ✅ | ❌ |
| **Offline Attendance Sync** | ❌ | ✅ | ❌ | ❌ |
| **SF2 Attendance Form Export** | ✅ | ✅ (Adviser) | ❌ | ❌ |
| **Record Activity Scores (WW/PT/QA)** | ❌ | ✅ | ❌ | ❌ |
| **DepEd ECR Excel Import / Export** | ❌ | ✅ | ❌ | ❌ |
| **4-Tier Grade Approval Governance** | ✅ (Verify/Approve) | ✅ (Submit/Adviser) | ❌ | ❌ |
| **View Published Report Cards (SF9)** | ✅ | ✅ | ✅ | ✅ |
| **Adviser-Parent Direct Messaging** | ❌ | ✅ (Adviser) | ❌ | ✅ |
| **Upload Learning Materials** | ❌ | ✅ | ❌ | ❌ |
| **Download Enrolled Subject Materials** | ❌ | ✅ (Own) | ✅ | ❌ |
| **Web Push Notifications** | ✅ | ✅ | ✅ | ✅ |
| **PhilSMS Grade Publication SMS** | ✅ (Trigger) | ❌ | ✅ (Receive) | ✅ (Receive) |
| **System Audit Logs & Archive Recovery** | ✅ | ❌ | ❌ | ❌ |

---

## 5. Technical Specifications & Stack Details

- **Backend Architecture:** PHP 8.2+ / PHP 8.3 (Object-Oriented, PSR-4 Namespace `BshsAms\`, Clean MVC/Action Handler Pattern).
- **Database Engine:** MySQL 8.0 / MariaDB 10.11 with InnoDB Engine, UTF-8 MB4 charset, and prepared PDO transactions.
- **Frontend Layer:** Semantic HTML5, Vanilla JavaScript (ES6+), Bootstrap 5.3 UI framework, Bootstrap Icons, and CSS3 custom properties with Dark Mode support.
- **Progressive Web App:** W3C Manifest specification (`assets/manifest.json`), Root Service Worker (`sw.js`), Push API, Web Push with VAPID (`minishlink/web-push`).
- **Spreadsheet Processing:** Native OpenXML (`.xlsx`) parsing and templating engine for high-fidelity DepEd form generation.
- **Third-Party Integrations:**
  - *PhilSMS API* (SMS broadcast)
  - *Resend API / SMTP* (Email OTP authentication)
  - *Wasmer Edge Platform* (Containerized serverless hosting)