# Technical Architecture Specification

**System Name:** Balingasag Senior High School - Attendance and Academic Management System (BSHS AMS)  
**Document Version:** 1.0.1  
**Status:** Approved  

---

## 1. System Architecture Overview

BSHS AMS follows a modular, layered PHP Web and PWA architecture. Core domain business logic is encapsulated in PSR-4 namespaced classes located in `src/`, while application front-controllers routing web requests reside in isolated role directories (`admin/`, `teacher/`, `student/`, `parent/`, `api/`).

```
                              ┌────────────────────────┐
                              │   Web / PWA Client     │
                              └───────────┬────────────┘
                                          │ HTTP / HTTPS
                                          ▼
                              ┌────────────────────────┐
                              │ functions/bootstrap.php│
                              └───────────┬────────────┘
                                          │
                  ┌───────────────────────┼───────────────────────┐
                  ▼                       ▼                       ▼
        ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐
        │ Session & RBAC   │    │ CSRF Validation  │    │ Performance Logs │
        │ (config/session) │    │  (requireCsrf)   │    │  (error_log)     │
        └─────────┬────────┘    └─────────┬────────┘    └─────────┬────────┘
                  └───────────────────────┼───────────────────────┘
                                          │
                                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              Application Core                                   │
│  ┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────────┐ │
│  │   Role Controllers   │ │     API Endpoints    │ │  DepEd Excel & Exporters │ │
│  │(admin, teacher, etc) │ │   (api/routes/*.php) │ │   (src/Export/*.php)     │ │
│  └──────────┬───────────┘ └──────────┬───────────┘ └────────────┬─────────────┘ │
└─────────────┼────────────────────────┼──────────────────────────┼───────────────┘
              │                        │                          │
              ▼                        ▼                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                               Domain & Services                                 │
│  ┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────────┐ │
│  │ BshsAms\Database     │ │ BshsAms\Grade        │ │ BshsAms\Xlsx             │ │
│  │ Database, Session    │ │ Calculator, Importer │ │ SimpleXlsxParser/Writer  │ │
│  └──────────┬───────────┘ └──────────┬───────────┘ └────────────┬─────────────┘ │
└─────────────┼────────────────────────┼──────────────────────────┼───────────────┘
              │                        │                          │
              └────────────────────────┼──────────────────────────┘
                                       ▼
                         ┌─────────────────────────────────────────┐
                         │ Wasmer Attached Database / Hosted MySQL │
                         └─────────────────────────────────────────┘
```

---

## 2. Namespace & Class Structure

The application source code lives under the `BshsAms\` namespace in `src/`:

### 2.1 Database Component (`BshsAms\Database`)
- **`Database.php`**: PDO connection manager with support for standard MySQL/MariaDB and hosted MySQL SSL certificates (`DB_SSL_CA` or `DB_SSL_CA_CONTENT`).
- **`SchemaCache.php`**: Static cache of database table schema metadata (`dbHasTable`, `dbHasColumn`) to reduce redundant `INFORMATION_SCHEMA` queries.
- **`SessionHandler.php`**: Custom `SessionHandlerInterface` implementation for database-backed session storage (`APP_SESSION_DRIVER=database`), providing stateless session persistence across container instances.

### 2.2 Export & Parser Component (`BshsAms\Export`)
- **`Sf1Exporter.php` & `Sf1Parser.php`**: School Form 1 (School Register) Excel generator and parser.
- **`Sf2Exporter.php`**: School Form 2 (Daily Attendance) monthly summary exporter.
- **`Sf5Exporter.php`**: School Form 5 (Report on Promotion and Level of Proficiency) exporter.
- **`Sf9Exporter.php`**: School Form 9 (Learner Progress Report Card) generator.
- **`EcrExporter.php` & `EcrParser.php`**: Electronic Class Record (ECR) parser and exporter supporting DepEd 3-term Senior High School workbooks.

### 2.3 Grading Component (`BshsAms\Grade`)
- **`SshsGradeCalculator.php`**: DepEd SHS 3-term weight calculator handling Written Work, Performance Tasks, and Quarterly Assessments.
- **`GradeImporter.php`**: Business logic importer mapping parsed ECR scores to subject student enrollments.

### 2.4 Schedule Component (`BshsAms\Schedule`)
- **`ScheduleParser.php`**: Parser for class section schedules, room assignments, and teacher time slots.

### 2.5 XLSX Engine Component (`BshsAms\Xlsx`)
- **`SimpleXlsxParser.php`**: Lightweight fallback XLSX parser reading openXML zip structures directly without heavy memory consumption.
- **`SimpleXlsxTemplateEditor.php`**: Template cell modifier for fast DepEd Excel report generation.
- **`SimpleXlsxWriter.php`**: Lightweight memory-efficient XLSX writer.

### 2.6 Notification Component (`BshsAms\Notification`)
- **`SmsService.php`**: Multi-gateway SMS dispatcher supporting PhilSMS (default), Semaphore, Twilio, and safe log fallbacks with Philippine mobile number normalization.

### 2.7 Storage Component (`BshsAms\Storage`)
- **`MaterialStorage.php`**: Secure learning material storage manager with randomized filenames, path traversal protection, and role-authorized file streaming.

---

## 3. Security Architecture

### 3.1 Authentication & Session Management
- **Password Hashing:** Passwords are hashed using `PASSWORD_DEFAULT` (Bcrypt/Argon2id).
- **Default Password Guard:** Web login and remember-me auto-login enforce mandatory password setup if `password_verify(DEFAULT_NEW_USER_PASSWORD)` matches.
- **Stateless Cloud Sessions:** When running on cloud container platforms such as Wasmer Edge, setting `APP_SESSION_DRIVER=database` delegates session storage to the SQL `app_sessions` table.

### 3.2 Authorization & Centralized RBAC
Script-level RBAC is configured centrally in `functions/bootstrap.php` via `enforceScriptPermission($db)`:
- Maps requested script URIs to permission keys.
- Validates user roles (`admin`, `teacher`, `student`, `parent`).
- Logs access violations and terminates unauthorized execution with HTTP 403.

### 3.3 CSRF Protection
Functions in `functions/app-helpers.php` enforce CSRF tokens on state-changing requests:
- Session token is generated on authentication (`$_SESSION['csrf_token']`).
- Validation function `requireCsrfToken()` checks POST body (`$_POST['csrf_token']`), GET parameter (`$_GET['csrf_token']`), or HTTP header (`X-CSRF-Token`).

---

## 4. Deployment Architecture

### 4.1 Local Development Environment
- PHP Built-in Server: `composer run serve` (listens on `http://localhost:5000/` via `router.php`).
- Database: Local MySQL/MariaDB (`database/schema.sql`).

### 4.2 Wasmer Edge Cloud Environment
- **Configuration:** `wasmer.toml` (package definition) and `app.yaml` (runtime configuration).
- **Database:** Wasmer Attached Database / hosted MySQL (`database/schema.sql`).
- **PHP Config:** Custom settings in `config/wasmer/php.ini`.
- **CI/CD Pipeline:** GitHub Actions workflow in `.github/workflows/wasmer-deploy.yml`.
