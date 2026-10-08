-- Reset the selected Balingasag SHS AMS database.
-- WARNING: This permanently deletes all application tables and data.
-- Standalone use: run this file, then database/schema.sql, then provision the
-- protected accounts with composer run seed:admin.
-- One-file dashboard use: run composer run database:setup-sql and import the
-- ignored database/reset_and_setup.local.sql instead of this source file.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS sms_logs;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS rbac_role_permissions;
DROP TABLE IF EXISTS rbac_permissions;
DROP TABLE IF EXISTS rbac_roles;
DROP TABLE IF EXISTS academic_year_settings;
DROP TABLE IF EXISTS school_settings;
DROP TABLE IF EXISTS attendance_sync_queue;
DROP TABLE IF EXISTS website_content;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS mobile_push_tokens;
DROP TABLE IF EXISTS auth_password_change_tokens;
DROP TABLE IF EXISTS auth_password_resets;
DROP TABLE IF EXISTS app_sessions;
DROP TABLE IF EXISTS auth_remember_tokens;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS admin_audit_logs;
DROP TABLE IF EXISTS auth_login_logs;
DROP TABLE IF EXISTS user_settings;
DROP TABLE IF EXISTS report_card_approvals;
DROP TABLE IF EXISTS push_subscriptions;
DROP TABLE IF EXISTS user_notifications;
DROP TABLE IF EXISTS teacher_report_notes;
DROP TABLE IF EXISTS admin_report_notes;
DROP TABLE IF EXISTS parent_students;
DROP TABLE IF EXISTS materials;
DROP TABLE IF EXISTS class_announcements;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS grade_approvals;
DROP TABLE IF EXISTS grade_item_score_verifications;
DROP TABLE IF EXISTS grade_item_scores;
DROP TABLE IF EXISTS grade_items;
DROP TABLE IF EXISTS grades;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS class_subjects;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS class_schedules;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;
