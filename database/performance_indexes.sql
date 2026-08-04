-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- PERFORMANCE OPTIMIZATION INDEXES (Tuned Data Level - Idempotent)
-- =============================================================================
-- Safe to run multiple times without MySQL Error 1061 (Duplicate key name).
-- =============================================================================

-- USERS TABLE INDEX
DROP INDEX IF EXISTS idx_users_role_status_grade ON users;
ALTER TABLE users ADD INDEX idx_users_role_status_grade (role, status, grade_level, section);

-- CLASSES TABLE INDEX
DROP INDEX IF EXISTS idx_classes_grade_section_status ON classes;
ALTER TABLE classes ADD INDEX idx_classes_grade_section_status (grade_level, section, status, teacher_id);

-- ENROLLMENTS TABLE INDEX
DROP INDEX IF EXISTS idx_enrollments_student_class_ay ON enrollments;
ALTER TABLE enrollments ADD INDEX idx_enrollments_student_class_ay (student_id, class_id, academic_year, status);

-- ATTENDANCE TABLE INDEX
DROP INDEX IF EXISTS idx_attendance_student_date_status ON attendance;
ALTER TABLE attendance ADD INDEX idx_attendance_student_date_status (student_id, date, status);

-- GRADES TABLE INDEX
DROP INDEX IF EXISTS idx_grades_student_cs_term_ay ON grades;
ALTER TABLE grades ADD INDEX idx_grades_student_cs_term_ay (student_id, class_subject_id, academic_year, term);

-- GRADE ITEMS TABLE INDEX
DROP INDEX IF EXISTS idx_grade_items_class_term ON grade_items;
ALTER TABLE grade_items ADD INDEX idx_grade_items_class_term (class_id, term, academic_year);

-- GRADE ITEM SCORES TABLE INDEX
DROP INDEX IF EXISTS idx_gis_item_student ON grade_item_scores;
ALTER TABLE grade_item_scores ADD INDEX idx_gis_item_student (grade_item_id, student_id);
