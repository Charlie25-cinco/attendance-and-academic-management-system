-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- COMPLETE DATABASE SCHEMA
-- =============================================================================
-- Includes: DO 009, s. 2026 3-term grading system (Term1/Term2/Term3)
--           DM 74, s. 2025 / DM 12, s. 2026 SSHS weight distribution
--           Combined EC/MK subject averaging
--           Configurable academic year settings
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
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ENROLLMENTS
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
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
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
    academic_year VARCHAR(20),
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_subject_id) REFERENCES class_subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
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
    status ENUM('pending','submitted','admin_verified','rejected','approved') DEFAULT 'pending',
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
    UNIQUE KEY uq_student_single_parent (student_id),
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
    status ENUM('pending','rejected','submitted_admin','approved') DEFAULT 'pending',
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


