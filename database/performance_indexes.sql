-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- PERFORMANCE OPTIMIZATION INDEXES
-- =============================================================================
-- Execute via Web Browser: http://localhost/attendance-and-academic-management-system/database/run_performance_indexes.php
-- Or run individual ALTER TABLE queries below in your database dashboard.
-- =============================================================================

ALTER TABLE users ADD INDEX idx_users_role_status_grade (role, status, grade_level, section);
ALTER TABLE classes ADD INDEX idx_classes_grade_section_status (grade_level, section, status, teacher_id);
ALTER TABLE enrollments ADD INDEX idx_enrollments_student_class_ay (student_id, class_id, academic_year, status);
ALTER TABLE attendance ADD INDEX idx_attendance_student_date_status (student_id, date, status);
ALTER TABLE grades ADD INDEX idx_grades_student_cs_term_ay (student_id, class_subject_id, academic_year, term);
ALTER TABLE grade_items ADD INDEX idx_grade_items_class_term (class_id, term, academic_year);
ALTER TABLE grade_item_scores ADD INDEX idx_gis_item_student (grade_item_id, student_id);
