-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- COMPLETE DATABASE SCHEMA
-- =============================================================================
-- Includes: DO 009, s. 2026 3-term grading system (Term1/Term2/Term3)
--           DM 74, s. 2025 / DM 12, s. 2026 SSHS weight distribution
--           Combined EC/MK subject averaging
--           Configurable academic year settings
--           Baseline website, school settings, Grade 11 subjects, and RBAC data
-- Select the intended database before importing. This file does not reset data
-- and does not provision password-bearing Admin or Principal accounts.
-- =============================================================================


SET NAMES utf8mb4;

-- =============================================================================
-- USERS
-- =============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(20) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    lrn VARCHAR(12) NULL UNIQUE COMMENT 'DepEd Learner Reference Number (9-12 digits)',
    password VARCHAR(255) NOT NULL,
    api_token_version INT NOT NULL DEFAULT 0 COMMENT 'Bumped on password change to revoke issued API bearer tokens',
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
    role ENUM('admin', 'teacher', 'student', 'parent', 'principal') NOT NULL,
    track VARCHAR(50) DEFAULT NULL COMMENT 'academic|techpro',
    curriculum VARCHAR(50) DEFAULT NULL COMMENT 'strengthened_shs for Grade 11 SY 2026+',
    program VARCHAR(50) DEFAULT NULL COMMENT 'academic_strengthened|technical_professional for Grade 11 SSHS',
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- CLASSES
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
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- CLASS SCHEDULES
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
-- SUBJECTS
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
-- SECTIONS (grade-level track groupings managed via Admin → Sections)
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
-- CLASS SUBJECTS (Many-to-Many)
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
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_class_subject (class_id, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ENROLLMENTS
-- =============================================================================
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL DEFAULT '2026-2027',
    semester INT NULL COMMENT '1/2 for legacy 4-quarter; NULL for 3-term system',
    curriculum VARCHAR(50) DEFAULT NULL COMMENT 'strengthened_shs for Grade 11 SY 2026+',
    program VARCHAR(50) DEFAULT NULL COMMENT 'academic_strengthened|technical_professional for Grade 11 SSHS',
    status ENUM('enrolled', 'dropped', 'completed') DEFAULT 'enrolled',
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_student_class_year (student_id, class_id, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ATTENDANCE
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
    KEY idx_attendance_term (class_id, academic_year, semester, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- GRADES
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
    academic_year VARCHAR(20) NOT NULL DEFAULT '2026-2027',
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_grade_student_subject_term_year (student_id, class_subject_id, term, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- GRADE ITEMS (teacher-created activities)
-- =============================================================================
CREATE TABLE IF NOT EXISTS grade_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    teacher_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    component ENUM('WW','PT','ASSESSMENT') NOT NULL,
    total_score DECIMAL(7,2) NOT NULL,
    activity_date DATE NOT NULL,
    status ENUM('active','finished') DEFAULT 'active',
    finished_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- GRADE ITEM SCORES
-- =============================================================================
CREATE TABLE IF NOT EXISTS grade_item_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_item_id INT NOT NULL,
    student_id INT NOT NULL,
    score DECIMAL(7,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (grade_item_id) REFERENCES grade_items(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_item_student (grade_item_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- GRADE ITEM SCORE VERIFICATIONS (QR-based)
-- =============================================================================
CREATE TABLE IF NOT EXISTS grade_item_score_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_item_id INT NOT NULL,
    student_id INT NOT NULL,
    verified_by INT NULL,
    verification_method ENUM('qr') DEFAULT 'qr',
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (grade_item_id) REFERENCES grade_items(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_grade_item_verified_student (grade_item_id, student_id),
    KEY idx_grade_item_verified_lookup (grade_item_id, student_id, verified_at),
    KEY idx_grade_item_verified_by (verified_by, verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- GRADE APPROVALS
-- =============================================================================
CREATE TABLE IF NOT EXISTS grade_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_id INT NOT NULL,
    status ENUM('pending','submitted','admin_verified','rejected','approved') DEFAULT 'pending' COMMENT 'admin_verified is retained as the legacy name for Principal verification',
    submitted_by INT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    remarks VARCHAR(255) NULL,
    UNIQUE KEY uq_grade_approval_grade (grade_id),
    KEY idx_grade_approval_status (status),
    FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ANNOUNCEMENTS
-- =============================================================================
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    category ENUM('general', 'urgent', 'event', 'academic') DEFAULT 'general',
    posted_by INT NOT NULL,
    status ENUM('active', 'archived') DEFAULT 'active',
    views INT DEFAULT 0,
    show_on_website TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- CLASS ANNOUNCEMENTS
-- =============================================================================
CREATE TABLE IF NOT EXISTS class_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    posted_by INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- LEARNING MATERIALS
-- =============================================================================
CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    file_size INT,
    class_subject_id INT,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- PARENT-STUDENT RELATIONSHIPS
-- =============================================================================
CREATE TABLE IF NOT EXISTS parent_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    relationship VARCHAR(20),
    FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_parent_student (parent_id, student_id),
    KEY idx_parent_students_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ADMIN REPORT NOTES
-- =============================================================================
CREATE TABLE IF NOT EXISTS admin_report_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    report_type ENUM('general','attendance','top_attendance','class_summary','at_risk','grades','enrollment','teachers','classes') DEFAULT 'general',
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_admin_type_created (admin_id, report_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- TEACHER REPORT NOTES
-- =============================================================================
CREATE TABLE IF NOT EXISTS teacher_report_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    report_type ENUM('general','top_attendance','class_summary','at_risk') DEFAULT 'general',
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_teacher_type_created (teacher_id, report_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- USER NOTIFICATIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    source_key VARCHAR(255) NOT NULL,
    title VARCHAR(200) NOT NULL,
    subtitle VARCHAR(255) DEFAULT '',
    icon VARCHAR(50) DEFAULT 'bi-bell',
    color VARCHAR(20) DEFAULT 'primary',
    link VARCHAR(255) DEFAULT '',
    event_at DATETIME NOT NULL,
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_source (user_id, source_key),
    KEY idx_user_read_event (user_id, is_read, event_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- WEB PUSH SUBSCRIPTIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint VARCHAR(512) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_push_endpoint (endpoint),
    KEY idx_push_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- REPORT CARD APPROVALS
-- =============================================================================
CREATE TABLE IF NOT EXISTS report_card_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(5) NULL,
    advisory_teacher_id INT NOT NULL,
    status ENUM('pending','rejected','submitted_admin','approved') DEFAULT 'submitted_admin' COMMENT 'submitted_admin awaits Principal; pending is Principal-endorsed and awaits Admin',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    remarks VARCHAR(255) NULL,
    UNIQUE KEY uq_report_card_term (student_id, academic_year, semester),
    KEY idx_report_card_status (status),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (advisory_teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- USER SETTINGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    dark_mode TINYINT DEFAULT 0,
    email_notifications TINYINT DEFAULT 1,
    push_notifications TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_settings (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- LOGIN AUDIT LOGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS auth_login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(50) NOT NULL,
    user_id INT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    failure_reason VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ref_time (reference_code, created_at),
    KEY idx_success_time (success, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ADMIN AUDIT LOGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    action_name VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT DEFAULT NULL,
    details_json TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_logs_admin_user_id (admin_user_id),
    INDEX idx_admin_audit_logs_action_name (action_name),
    INDEX idx_admin_audit_logs_target_type (target_type),
    INDEX idx_admin_audit_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ROLE-AWARE ACTIVITY LOGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_role VARCHAR(30) NOT NULL,
    action_name VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT DEFAULT NULL,
    details_json TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_logs_actor (actor_user_id),
    INDEX idx_activity_logs_role (actor_role),
    INDEX idx_activity_logs_action (action_name),
    INDEX idx_activity_logs_target (target_type, target_id),
    INDEX idx_activity_logs_created_at (created_at),
    CONSTRAINT fk_activity_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- RATE LIMITS
-- =============================================================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    context VARCHAR(20) NOT NULL DEFAULT 'web',
    action_key VARCHAR(128) NOT NULL,
    identifier_hash CHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    first_attempt INT NULL,
    lock_until DATETIME NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_context_action_identifier (context, action_key, identifier_hash),
    KEY idx_expires (expires_at),
    KEY idx_lock (context, action_key, lock_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- REMEMBER-ME TOKENS
-- =============================================================================
CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_selector (selector),
    KEY idx_user_active (user_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- DATABASE-BACKED PHP SESSIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS app_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NULL,
    payload MEDIUMBLOB NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_app_sessions_user_id (user_id),
    KEY idx_app_sessions_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- PASSWORD RESET TOKENS
-- =============================================================================
CREATE TABLE IF NOT EXISTS auth_password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    request_ip VARCHAR(45) NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_reset_token_hash (token_hash),
    KEY idx_user_active_resets (user_id, used_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- API FIRST-LOGIN PASSWORD CHANGE TOKENS
-- =============================================================================
CREATE TABLE IF NOT EXISTS auth_password_change_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    request_ip VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    INDEX idx_user (user_id),
    UNIQUE KEY uq_token_hash (token_hash),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- MOBILE PUSH TOKENS
-- =============================================================================
CREATE TABLE IF NOT EXISTS mobile_push_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    device VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mobile_token (token),
    KEY idx_mobile_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- MESSAGES (teacher-parent chat)
-- =============================================================================
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_user_id INT NOT NULL,
    to_user_id INT NOT NULL,
    student_id INT NULL COMMENT 'Which student this conversation is about',
    message TEXT NOT NULL,
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_messages_from_to (from_user_id, to_user_id, created_at),
    KEY idx_messages_to_read (to_user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- WEBSITE CONTENT
-- =============================================================================
CREATE TABLE IF NOT EXISTS website_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_key VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ATTENDANCE SYNC QUEUE (LAN offline sync)
-- =============================================================================
CREATE TABLE IF NOT EXISTS attendance_sync_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('present', 'absent', 'late') NOT NULL,
    remarks VARCHAR(255),
    recorded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    synced_at TIMESTAMP NULL,
    KEY idx_sync_pending (synced_at),
    KEY idx_sync_class_date (class_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SCHOOL SETTINGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS school_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ACADEMIC YEAR SETTINGS
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
-- RBAC ROLES
-- =============================================================================
CREATE TABLE IF NOT EXISTS rbac_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(30) UNIQUE NOT NULL COMMENT 'Must match users.role ENUM value',
    label VARCHAR(50) NOT NULL,
    description TEXT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'System roles cannot be deleted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- RBAC PERMISSIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS rbac_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    description TEXT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- RBAC ROLE PERMISSIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS rbac_role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_permission (role_id, permission_id),
    CONSTRAINT fk_rbac_rp_role FOREIGN KEY (role_id) REFERENCES rbac_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rbac_rp_perm FOREIGN KEY (permission_id) REFERENCES rbac_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PERFORMANCE COMPOSITE INDEXES (Tuned Data Level)
-- =============================================================================
CREATE INDEX idx_users_role_status_grade ON users (role, status, grade_level, section);
CREATE INDEX idx_classes_grade_section_status ON classes (grade_level, section, status, teacher_id);
CREATE INDEX idx_enrollments_student_class_ay ON enrollments (student_id, class_id, academic_year, status);
CREATE INDEX idx_attendance_student_date_status ON attendance (student_id, date, status);
CREATE INDEX idx_grades_student_cs_term_ay ON grades (student_id, class_subject_id, academic_year, term);
CREATE INDEX idx_grade_items_class_teacher_date_status ON grade_items (class_id, teacher_id, activity_date, status);
CREATE INDEX idx_gis_item_student ON grade_item_scores (grade_item_id, student_id);

-- =============================================================================
-- BASELINE DATA
-- =============================================================================
-- Fresh imports include required public content, school settings, the complete
-- Strengthened SHS Grade 11 subject registry, and default RBAC configuration.

-- WEBSITE CONTENT (admin-managed school website pages)
-- =============================================================================
INSERT IGNORE INTO website_content (section_key, title, content) VALUES
('hero_title', 'Welcome to Balingasag Senior High School',
 'Nurturing excellence, building futures. A DepEd-accredited Senior High School in Balingasag, Misamis Oriental.'),
('about', 'About Our School',
 'Balingasag Senior High School (BSHS) is committed to providing quality education for Senior High School students in the municipality of Balingasag. We offer various tracks and strands aligned with the K to 12 curriculum of the Department of Education.'),
('contact_address', 'Address',
 'Balingasag, Misamis Oriental, Philippines'),
('contact_email', 'Email',
 'balingasagshs@deped.gov.ph'),
('contact_phone', 'Phone',
 '(088) 000-0000'),
('contact_hours', 'Office Hours',
 'Monday â€“ Friday: 7:00 AM â€“ 5:00 PM');

-- =============================================================================
-- ACADEMIC YEAR SETTINGS
-- =============================================================================
INSERT IGNORE INTO academic_year_settings (academic_year, grading_system) VALUES
('2025-2026', '4_quarter'),
('2026-2027', '3_term');

-- =============================================================================
-- SCHOOL SETTINGS
-- =============================================================================
INSERT IGNORE INTO school_settings (setting_key, setting_value) VALUES
('school_name', 'Balingasag Senior High School'),
('school_id', '341227'),
('district', 'Balingasag North'),
('division', 'Misamis Oriental'),
('region', 'Region X'),
('school_address', 'Balingasag, Misamis Oriental, Philippines');

-- CORE SUBJECTS (Required for ALL Grade 11 learners, both tracks)
-- Year-long: 160 hours across Term 1 + Term 2 + Term 3
-- =============================================================================
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('ELECTCOM',    'Effective Communication',                                      'core', 11, NULL, 3, 'strengthened_shs'),
('MK',          'Mabisang Komunikasyon',                                        'core', 11, NULL, 3, 'strengthened_shs'),
('GENMATH',     'General Mathematics',                                          'core', 11, NULL, 3, 'strengthened_shs'),
('GENSCI',      'General Science',                                              'core', 11, NULL, 3, 'strengthened_shs'),
('LIFECARE',    'Life and Career Skills',                                       'core', 11, NULL, 3, 'strengthened_shs'),
('KKLP',        'Pag-aaral ng Kasaysayan at Lipunang Pilipino',                'core', 11, NULL, 3, 'strengthened_shs');

-- =============================================================================
-- ACADEMIC TRACK ELECTIVES (80 hours each, single term)
-- Students take at least 9 electives (960 hours total)
-- =============================================================================

-- Cluster 1: Arts, Social Sciences, and Humanities
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('ACOLIT1',     'Contemporary Literature 1',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACOLIT2',     'Contemporary Literature 2',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACRECOMP1',   'Creative Composition 1',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACRECOMP2',   'Creative Composition 2',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFILART',     'Filipino Identity Through the Arts',                           'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL1',       'Filipino 1 (Wika at Komunikasyon sa Akademikong Filipino)',    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL2TP',     'Filipino 2 (Filipino sa Larang Teknikal Propesyonal)',         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL2IS',     'Filipino 2 (Filipino sa Isports)',                             'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL2SD',     'Filipino 2 (Filipino sa Sining at Disenyo)',                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AARTS1',      'Arts 1 (Creative Industries - Visual, Literary, Media, Applied, Traditional Art)', 'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AARTS2',      'Arts 2 (Creative Industries - Music, Dance, Theater)',         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHIL1',      'Introduction to Philosophy',                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ALEADART',    'Leadership and Management in the Arts',                        'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AMALPAG',     'Malikhaing Pagsulat',                                          'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHGOV',      'Philippine Governance (Philippine Politics and Governance)',   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASOSTP',      'Social Sciences Theory and Practice',                          'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACITCIV',     'Citizenship and Civic Engagement',                             'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- Cluster 2: Business and Entrepreneurship
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('ABUS1',       'Business 1 (Basic Accounting)',                                'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AINTOM',      'Introduction to Organization and Management',                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABUS2',       'Business 2 (Business Finance and Income Taxation)',            'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABUS3',       'Business 3 (Business Economics)',                              'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACONMKG',     'Contemporary Marketing',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AENTREP',     'Entrepreneurship',                                             'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- Cluster 3: STEM
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('AFINM1',      'Finite Mathematics 1',                                         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFINM2',      'Finite Mathematics 2',                                         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO1',       'Biology 1',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO2',       'Biology 2',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM1',      'Chemistry 1',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM2',      'Chemistry 2',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS1',       'Earth and Space Science 1',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS2',       'Earth and Space Science 2',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS1',      'Physics 1',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS2',      'Physics 2',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AADVM1',      'Advanced Mathematics 1',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AADVM2',      'Advanced Mathematics 2',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO3',       'Biology 3',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO4',       'Biology 4',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM3',      'Chemistry 3',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM4',      'Chemistry 4',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS3',       'Earth and Space Science 3',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS4',       'Earth and Space Science 4',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS3',      'Physics 3',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS4',      'Physics 4',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACALC1',      'Calculus 1',                                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACALC2',      'Calculus 2',                                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ATRIG1',      'Trigonometry 1',                                               'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ATRIG2',      'Trigonometry 2',                                               'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AEMPTECH',    'Empowerment Technologies',                                     'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ADATAMGT',    'Database Management',                                          'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ADATAAN',     'Fundamentals of Data Analytics and Management',                'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- Cluster 4: Sports, Health, and Wellness
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('AHUMOV1',     'Human Movement 1 (Basic Anatomy in Sports and Exercise)',      'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APE1',        'Physical Education 1 (Fitness and Recreation)',                'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AHUMOV2',     'Human Movement 2 (Motor Skills Development)',                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APE2',        'Physical Education 2 (Sports and Dance)',                      'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASPTACT',     'Sports Activity Management',                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASPTCOA',     'Sports Coaching',                                              'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASPTOFF',     'Sports Officiating',                                           'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AEXSPR',      'Exercise and Sports Programming',                              'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASAFFirstAid','Safety and First Aid',                                         'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- =============================================================================
-- TECHPRO TRACK ELECTIVES (320 hours each, full year in Grade 11)
-- Students take at least 2 TechPro electives (640 hours)
-- =============================================================================

-- Cluster 1: Aesthetic, Wellness, and Human Care
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TESPAESTH',   'Aesthetic Services (Beauty Care)',                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPBARB',    'Barbering Services',                                           'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPCARGIV',  'Caregiving (Adult Care)',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPCARKID',  'Caregiving (Child Care)',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPHAIR',    'Hairdressing Services',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPWELL',    'Wellness Services (Hilot/Massage)',                            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 2: Agri-Fishery Business and Food Innovation
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEAGRCRP',    'Agricultural Crops Production',                                'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAGRAGR',    'Agro-entrepreneurship',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAQUACUL',   'Aquaculture',                                                  'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEFISHCAP',   'Fish Capture Operation',                                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEFOODPRC',   'Food Processing',                                              'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEORGAGR',    'Organic Agriculture Production',                               'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEPOULCHR',   'Poultry Production (Chicken)',                                 'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TERUMPROD',   'Ruminants Production',                                         'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESWIPROD',   'Swine Production',                                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 3: Artisanry and Creative Enterprise
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEGARPART',   'Garments Artisanry',                                           'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEHANDWEAV',  'Handicrafts (Weaving)',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 4: Automotive and Small Engine Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEAUTOELEC',  'Automotive Servicing (Electrical Repair)',                     'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAUTOECHR',  'Automotive Servicing (Engine and Chassis Repairs)',            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAUTOALL',   'Driving and Automotive Servicing',                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEMOTOENG',   'Motorcycle and Small Engine Servicing',                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 5: Construction and Building Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TECARP',      'Carpentry',                                                    'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECONOP',     'Construction Operation',                                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEWELD',      'Manual Metal Arc Welding',                                     'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TETECHDFT',   'Technical Drafting',                                           'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 6: Creative Arts and Design Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEANIM',      'Animation',                                                    'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEILLUS',     'Illustration',                                                 'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEVISGDES',   'Visual Graphic Design',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 7: Hospitality and Tourism
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEBAKERY',    'Bakery Operation',                                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEEVTMGT',    'Events Management Services',                                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEFBOPER',    'Food and Beverage Operation',                                  'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEHOTELFO',   'Hotel Operation (Front Office Services)',                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEHOTELHS',   'Hotel Operation (Housekeeping Services)',                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEKITCHEN',   'Kitchen Operation',                                            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TETOURISM',   'Tourism Services',                                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 8: ICT Support and Computer Programming Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEBROADINS',  'Broadband Installation',                                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPROG1',  'Computer Programming (Java)',                                  'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPROG2',  'Computer Programming (.NET Technology)',                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPROG3',  'Computer Programming (Oracle Database)',                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPSERV',  'Computer Systems Servicing',                                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECONCTR',    'Contact Center Services',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 9: Industrial Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEACINS',     'Commercial Air-Conditioning Installation and Servicing',       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TERACSERV',   'Domestic Refrigeration and Air-Conditioning Servicing',        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEELCINS',    'Electrical Installation and Maintenance',                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEELCPROD',   'Electronics Product Assembly and Servicing',                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEMECHTRN',   'Mechatronics',                                                 'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEPHOTVS',    'Photovoltaic Systems Installation',                            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 10: Maritime Transport
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEMARENG',    'Marine Engineering at the Support Level',                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEMARTRANS',  'Marine Transportation at the Support Level',                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESHPCAT',    'Ships Catering Services',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- =============================================================================
-- WORK IMMERSION (TechPro mandatory in G12, Academic optional)
-- 320-640 hours, typically Grade 12
-- =============================================================================
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('WORKIMM',     'Work Immersion',                                               'work_immersion', 11, NULL, 3, 'strengthened_shs');

-- =============================================================================
-- FIELD EXPERIENCE (Academic track, mainly Grade 12)
-- =============================================================================
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('FIELDEXP',    'Field Experience / Exposure',                                  'field_experience_elective', 11, 'academic', 1, 'strengthened_shs');

SELECT
    COUNT(*) AS strengthened_g11_subject_count
FROM subjects
WHERE grade_level = 11
AND curriculum = 'strengthened_shs';

-- =============================================================================
-- DEFAULT RBAC ROLES, PERMISSIONS, AND ROLE MAPPINGS
-- =============================================================================
INSERT IGNORE INTO rbac_roles (role_key, label, description, is_system) VALUES
('principal', 'Principal', 'Verifies grades, endorses report cards, and monitors academic operations.', 1),
('admin', 'Administrator', 'Full system access.', 1),
('teacher', 'Teacher', 'Can manage attendance, grades, and view assigned classes.', 1),
('student', 'Student', 'Can view attendance, grades, and class schedules.', 1),
('parent', 'Parent', 'Can view child progress and report cards.', 1);

INSERT IGNORE INTO rbac_permissions (permission_key, label, category) VALUES
('report_cards.review', 'Verify Grades and Endorse Report Cards (Principal)', 'grades'),
('principal.monitoring.view', 'View Principal Monitoring', 'reports'),
('attendance.view', 'View Attendance', 'attendance'),
('attendance.manage', 'Manage Attendance', 'attendance'),
('attendance.reports', 'Attendance Reports', 'attendance'),
('grades.view', 'View Grades', 'grades'),
('grades.enter', 'Enter Grades', 'grades'),
('grades.approve', 'Approve Grades', 'grades'),
('grades.reports', 'Grade Reports', 'grades'),
('classes.view', 'View Classes', 'classes'),
('classes.manage', 'Manage Classes', 'classes'),
('classes.assign', 'Assign Teachers', 'classes'),
('users.view', 'View Users', 'users'),
('users.create', 'Create Users', 'users'),
('users.edit', 'Edit Users', 'users'),
('users.delete', 'Delete Users', 'users'),
('users.reset_password', 'Reset Passwords', 'users'),
('announcements.view', 'View Announcements', 'announcements'),
('announcements.create', 'Create Announcements', 'announcements'),
('announcements.delete', 'Delete Announcements', 'announcements'),
('reports.view', 'View Reports', 'reports'),
('reports.export', 'Export Reports', 'reports'),
('settings.view', 'View Settings', 'settings'),
('settings.manage', 'Manage Settings', 'settings'),
('messages.view', 'View Messages', 'messages'),
('messages.send', 'Send Messages', 'messages'),
('archives.view', 'View Archives', 'archives'),
('archives.manage', 'Manage Archives', 'archives');

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id, enabled)
SELECT r.id, p.id, 1
FROM rbac_roles r
CROSS JOIN rbac_permissions p
WHERE r.role_key = 'admin'
   OR (r.role_key = 'principal' AND p.permission_key IN ('report_cards.review', 'principal.monitoring.view'))
   OR (
       r.role_key = 'teacher'
       AND p.permission_key IN (
           'attendance.view', 'attendance.manage', 'attendance.reports',
           'grades.view', 'grades.enter', 'classes.view', 'users.view',
           'announcements.view', 'reports.view', 'reports.export',
           'messages.view', 'messages.send', 'archives.view'
       )
   )
   OR (
       r.role_key = 'student'
       AND p.permission_key IN (
           'attendance.view', 'grades.view', 'classes.view', 'announcements.view'
       )
   )
   OR (
       r.role_key = 'parent'
       AND p.permission_key IN (
           'attendance.view', 'grades.view', 'reports.view',
           'announcements.view', 'messages.view', 'messages.send'
       )
   );
