-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- COMPLETE WASMER / CLOUD DATABASE SETUP & SEED SCRIPT
-- =============================================================================
-- Features:
--   1. Complete Database Schema (DO 009 3-term system, DM 74/12 weights, RBAC, Sessions)
--   2. Performance Composite Indexes (Tuned Data Level)
--   3. First Admin Account Seed (Reference: A341227-1 / Password: password)
--   4. Default System RBAC Roles and Permission Seed
--
-- Deployment Note:
--   Safe for 1-click import into Wasmer Edge MySQL and TiDB Cloud.
--   Contains NO local 'DROP DATABASE' or 'USE' statements.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. USERS
-- =============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(20) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    lrn VARCHAR(12) NULL UNIQUE COMMENT 'DepEd Learner Reference Number (9-12 digits)',
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    name_extension VARCHAR(10) NULL COMMENT 'Jr., Sr., III, etc.',
    sex VARCHAR(10) NULL,
    date_of_birth DATE NULL,
    religion VARCHAR(50) NULL,
    profile_picture VARCHAR(255) NULL,
    contact_number VARCHAR(20) NULL,
    address TEXT NULL,
    house_street VARCHAR(120) NULL,
    barangay VARCHAR(120) NULL,
    municipality VARCHAR(120) NULL,
    province VARCHAR(120) NULL,
    father_name VARCHAR(100) NULL,
    mother_name VARCHAR(100) NULL,
    guardian_name VARCHAR(100) NULL,
    guardian_relationship VARCHAR(50) NULL,
    grade_level INT NULL,
    section VARCHAR(10) NULL,
    role ENUM('admin', 'teacher', 'student', 'parent') NOT NULL,
    track VARCHAR(50) DEFAULT NULL COMMENT 'academic|techpro',
    curriculum VARCHAR(50) DEFAULT NULL COMMENT 'strengthened_shs for Grade 11 SY 2026+',
    program VARCHAR(50) DEFAULT NULL COMMENT 'academic_strengthened|technical_professional for Grade 11 SSHS',
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_users_role_status_grade (role, status, grade_level, section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 2. CLASSES
-- =============================================================================
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(50) NOT NULL,
    grade_level INT NOT NULL,
    section VARCHAR(10) NOT NULL,
    subject_category VARCHAR(50) DEFAULT 'core' COMMENT 'core|academic_elective|techpro_elective|work_immersion|field_experience_elective',
    track VARCHAR(50) DEFAULT 'academic' COMMENT 'academic|techpro',
    curriculum VARCHAR(50) DEFAULT NULL COMMENT 'strengthened_shs for Grade 11 SY 2026+',
    program VARCHAR(50) DEFAULT NULL COMMENT 'academic_strengthened|technical_professional for Grade 11 SSHS',
    teacher_id INT,
    schedule VARCHAR(255),
    room VARCHAR(20),
    ww_weight DECIMAL(5,2) DEFAULT 25.00,
    pt_weight DECIMAL(5,2) DEFAULT 50.00,
    assessment_weight DECIMAL(5,2) DEFAULT 25.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_classes_grade_section_status (grade_level, section, status, teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 3. CLASS SCHEDULES
-- =============================================================================
CREATE TABLE IF NOT EXISTS class_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    day ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    KEY idx_class_day_time (class_id, day, start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 4. SUBJECTS
-- =============================================================================
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(100) NOT NULL,
    subject_code VARCHAR(20) UNIQUE NOT NULL,
    subject_category VARCHAR(50) DEFAULT 'core' COMMENT 'core|academic_elective|techpro_elective|work_immersion',
    grade_level INT NULL,
    track VARCHAR(50) NULL COMMENT 'academic|techpro|null for all tracks',
    term_count INT DEFAULT 3 COMMENT 'Number of terms this subject spans',
    curriculum VARCHAR(50) DEFAULT NULL COMMENT 'strengthened_shs or null for all curricula',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 5. SECTIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    grade_level INT NOT NULL,
    track VARCHAR(50) NOT NULL COMMENT 'academic|techpro',
    curriculum VARCHAR(50) DEFAULT NULL COMMENT 'strengthened_shs for Grade 11 SY 2026+',
    program VARCHAR(50) DEFAULT NULL COMMENT 'academic_strengthened|technical_professional for Grade 11 SSHS',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_section_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 6. CLASS SUBJECTS
-- =============================================================================
CREATE TABLE IF NOT EXISTS class_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject_id INT,
    teacher_id INT,
    schedule VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 7. ENROLLMENTS
-- =============================================================================
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester INT NULL COMMENT '1/2 for legacy 4-quarter; NULL for 3-term system',
    curriculum VARCHAR(50) DEFAULT NULL COMMENT 'strengthened_shs for Grade 11 SY 2026+',
    program VARCHAR(50) DEFAULT NULL COMMENT 'academic_strengthened|technical_professional for Grade 11 SSHS',
    status ENUM('enrolled', 'dropped', 'completed') DEFAULT 'enrolled',
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    INDEX idx_enrollments_student_class_ay (student_id, class_id, academic_year, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 8. ATTENDANCE
-- =============================================================================
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    date DATE NOT NULL,
    academic_year VARCHAR(20) NULL,
    semester INT NULL,
    term VARCHAR(10) NULL COMMENT 'Term1/Term2/Term3 for 3-term; NULL for legacy 4-quarter',
    status ENUM('present', 'absent', 'late') NOT NULL,
    time_in TIME,
    remarks VARCHAR(255),
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_attendance (student_id, class_id, date),
    KEY idx_attendance_term (class_id, academic_year, semester, date),
    INDEX idx_attendance_student_date_status (student_id, date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 9. GRADES
-- =============================================================================
CREATE TABLE IF NOT EXISTS grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_subject_id INT NOT NULL,
    ww_raw_score DECIMAL(7,2),
    ww_total_score DECIMAL(7,2),
    pt_raw_score DECIMAL(7,2),
    pt_total_score DECIMAL(7,2),
    assessment_raw_score DECIMAL(7,2),
    assessment_total_score DECIMAL(7,2),
    quiz_score DECIMAL(5,2),
    exam_score DECIMAL(5,2),
    activity_score DECIMAL(5,2),
    final_grade DECIMAL(5,2),
    semester VARCHAR(20) NULL COMMENT 'S1/S2 for legacy 4-quarter; NULL for 3-term system',
    term ENUM('Q1','Q2','Q3','Q4','Term1','Term2','Term3') NOT NULL DEFAULT 'Term1',
    academic_year VARCHAR(20),
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_grades_student_cs_term_ay (student_id, class_subject_id, academic_year, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 10. GRADE ITEMS & SCORES
-- =============================================================================
CREATE TABLE IF NOT EXISTS grade_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    teacher_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    term ENUM('Term1','Term2','Term3') NOT NULL DEFAULT 'Term1',
    academic_year VARCHAR(20) NOT NULL,
    component ENUM('WW','PT','ASSESSMENT') NOT NULL,
    total_score DECIMAL(7,2) NOT NULL,
    activity_date DATE NOT NULL,
    status ENUM('active','finished') DEFAULT 'active',
    finished_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_grade_items_class_term (class_id, term, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grade_item_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_item_id INT NOT NULL,
    student_id INT NOT NULL,
    score DECIMAL(7,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (grade_item_id) REFERENCES grade_items(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_item_student (grade_item_id, student_id),
    INDEX idx_gis_item_student (grade_item_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 11. GRADE & REPORT CARD APPROVALS
-- =============================================================================
CREATE TABLE IF NOT EXISTS grade_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_subject_id INT NOT NULL,
    term ENUM('Q1','Q2','Q3','Q4','Term1','Term2','Term3') NOT NULL DEFAULT 'Term1',
    academic_year VARCHAR(20) NOT NULL,
    teacher_id INT NOT NULL,
    status ENUM('draft','submitted','admin_verified','rejected') DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    verified_by INT NULL,
    verified_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_subject_term_ay (class_subject_id, term, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_card_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    term ENUM('Q1','Q2','Q3','Q4','Term1','Term2','Term3') NOT NULL DEFAULT 'Term1',
    academic_year VARCHAR(20) NOT NULL,
    adviser_id INT NOT NULL,
    status ENUM('draft','submitted_admin','approved','rejected') DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_student_term_ay (student_id, term, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 12. SESSIONS (Database Session Driver for Stateless Wasmer Cloud Hosts)
-- =============================================================================
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) NOT NULL PRIMARY KEY,
    user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sessions_user_id (user_id),
    KEY idx_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 13. AUTH LOGS, SECURITY & AUDIT LOGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS auth_login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    reference_code VARCHAR(50) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'unknown',
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NOT NULL,
    status ENUM('success', 'failed', 'blocked') NOT NULL,
    failure_reason VARCHAR(255) NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_auth_logs_ref_status (reference_code, status, attempted_at),
    KEY idx_auth_logs_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_resets_token (token_hash),
    KEY idx_resets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    actor_name VARCHAR(100) NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_audit_actor (actor_id, created_at),
    KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 14. RBAC ROLES & PERMISSIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS rbac_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(30) UNIQUE NOT NULL,
    label VARCHAR(50) NOT NULL,
    description TEXT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rbac_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    description TEXT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rbac_role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_permission (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES rbac_roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES rbac_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 15. SETTINGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS academic_year_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL UNIQUE,
    grading_system ENUM('4_quarter','3_term') NOT NULL DEFAULT '3_term',
    term1_start DATE NULL,
    term1_end DATE NULL,
    term2_start DATE NULL,
    term2_end DATE NULL,
    term3_start DATE NULL,
    term3_end DATE NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 16. DATA SEEDING: SYSTEM ROLES & ADMIN ACCOUNT
-- =============================================================================
INSERT IGNORE INTO rbac_roles (role_key, label, description, is_system) VALUES
('admin', 'Administrator', 'Full system access and portal administration', 1),
('teacher', 'Teacher / Subject Teacher / Adviser', 'Class management, attendance, grading, and advisory portal', 1),
('student', 'Student', 'Student grades, attendance, and portal access', 1),
('parent', 'Parent / Guardian', 'Parent portal, linked student progress, and adviser chat', 1);

INSERT INTO users (
    reference_code,
    email,
    password,
    first_name,
    last_name,
    role,
    status,
    created_at,
    updated_at
) VALUES (
    'A341227-1',
    'A341227-1@balingasag.edu.ph',
    '$2y$10$8eXTl75GxU3W0RFAHqUgi.BFdGfbU8U4sZzJ7xdoIW2ey3ZEoTJA6',
    'System',
    'Administrator',
    'admin',
    'active',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password = VALUES(password),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    role = 'admin',
    status = 'active',
    updated_at = NOW();

SET FOREIGN_KEY_CHECKS = 1;
