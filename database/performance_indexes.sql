-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- PERFORMANCE OPTIMIZATION INDEXES (Tuned Data Level)
-- =============================================================================
-- Run after schema_tidb.sql or schema.sql to add high-performance composite indexes.
-- =============================================================================

-- USERS TABLE INDEXES
-- Optimizes student listing by section, advisory lookup, and user filtering queries
ALTER TABLE users ADD INDEX idx_users_role_status_grade (role, status, grade_level, section);

-- CLASSES TABLE INDEXES
-- Accelerates section class lookups and teacher advisory gradebook queries
ALTER TABLE classes ADD INDEX idx_classes_grade_section_status (grade_level, section, status, teacher_id);

-- ENROLLMENTS TABLE INDEXES
-- Accelerates student dashboard load times and report card generation
ALTER TABLE enrollments ADD INDEX idx_enrollments_student_class_ay (student_id, class_id, academic_year, status);

-- ATTENDANCE TABLE INDEXES
-- Speeds up monthly SF2 export generation and student/parent attendance stats
ALTER TABLE attendance ADD INDEX idx_attendance_student_date_status (student_id, date, status);

-- GRADES TABLE INDEXES
-- Accelerates grade calculation, report card approvals, and combined subject processing
ALTER TABLE grades ADD INDEX idx_grades_student_cs_term_ay (student_id, class_subject_id, academic_year, term);

-- GRADE ITEMS TABLE INDEXES
-- Speeds up teacher gradebook activity loading
ALTER TABLE grade_items ADD INDEX idx_grade_items_class_term (class_id, term, academic_year);

-- GRADE ITEM SCORES TABLE INDEXES
-- Speeds up student score summaries and parent progress view
ALTER TABLE grade_item_scores ADD INDEX idx_gis_item_student (grade_item_id, student_id);
