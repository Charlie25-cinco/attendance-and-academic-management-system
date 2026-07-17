-- =============================================================================
-- RBAC Default Seed Data
-- Run AFTER rbac_migration.sql
-- =============================================================================

-- Default Roles
INSERT INTO rbac_roles (role_key, label, description, is_system) VALUES
('admin', 'Administrator', 'Full system access. Can manage all users, classes, grades, and settings.', 1),
('teacher', 'Teacher', 'Can manage attendance, grades, and view assigned classes.', 1),
('student', 'Student', 'Can view attendance, grades, and class schedules.', 1),
('parent', 'Parent', 'Can view child progress and report cards.', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Permission Categories: attendance, grades, classes, users, announcements, reports, settings, messages

INSERT INTO rbac_permissions (permission_key, label, description, category) VALUES
-- Attendance
('attendance.view', 'View Attendance', 'View attendance records', 'attendance'),
('attendance.manage', 'Manage Attendance', 'Record and edit attendance', 'attendance'),
('attendance.reports', 'Attendance Reports', 'Export attendance reports', 'attendance'),
-- Grades
('grades.view', 'View Grades', 'View grades and report cards', 'grades'),
('grades.enter', 'Enter Grades', 'Input and edit student grades', 'grades'),
('grades.approve', 'Approve Grades', 'Review and approve grade submissions', 'grades'),
('grades.reports', 'Grade Reports', 'Export grade reports', 'grades'),
-- Classes
('classes.view', 'View Classes', 'View class schedules and sections', 'classes'),
('classes.manage', 'Manage Classes', 'Create, edit, and delete classes', 'classes'),
('classes.assign', 'Assign Teachers', 'Assign teachers to classes and subjects', 'classes'),
-- Users
('users.view', 'View Users', 'View user accounts', 'users'),
('users.create', 'Create Users', 'Create new user accounts', 'users'),
('users.edit', 'Edit Users', 'Edit user account details', 'users'),
('users.delete', 'Delete Users', 'Delete user accounts', 'users'),
('users.reset_password', 'Reset Passwords', 'Reset user passwords', 'users'),
-- Announcements
('announcements.view', 'View Announcements', 'View announcements', 'announcements'),
('announcements.create', 'Create Announcements', 'Create and post announcements', 'announcements'),
('announcements.delete', 'Delete Announcements', 'Delete announcements', 'announcements'),
-- Reports
('reports.view', 'View Reports', 'Access system reports', 'reports'),
('reports.export', 'Export Reports', 'Download reports as files', 'reports'),
-- Settings
('settings.view', 'View Settings', 'View system settings', 'settings'),
('settings.manage', 'Manage Settings', 'Modify system settings', 'settings'),
-- Messages
('messages.view', 'View Messages', 'View chat messages', 'messages'),
('messages.send', 'Send Messages', 'Send chat messages', 'messages'),
-- Archives
('archives.view', 'View Archives', 'Access archived records', 'archives'),
('archives.manage', 'Manage Archives', 'Archive and restore records', 'archives')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Role-Permission Mappings
-- Helper: Get role_id and permission_id for INSERT

-- Admin: Full access to everything
INSERT INTO rbac_role_permissions (role_id, permission_id, enabled)
SELECT r.id, p.id, 1
FROM rbac_roles r
CROSS JOIN rbac_permissions p
WHERE r.role_key = 'admin'
ON DUPLICATE KEY UPDATE enabled = 1;

-- Teacher permissions
INSERT INTO rbac_role_permissions (role_id, permission_id, enabled)
SELECT r.id, p.id, 1
FROM rbac_roles r
JOIN rbac_permissions p ON p.permission_key IN (
    'attendance.view', 'attendance.manage', 'attendance.reports',
    'grades.view', 'grades.enter',
    'classes.view',
    'users.view',
    'announcements.view',
    'reports.view', 'reports.export',
    'messages.view', 'messages.send',
    'archives.view'
)
WHERE r.role_key = 'teacher'
ON DUPLICATE KEY UPDATE enabled = 1;

-- Student permissions
INSERT INTO rbac_role_permissions (role_id, permission_id, enabled)
SELECT r.id, p.id, 1
FROM rbac_roles r
JOIN rbac_permissions p ON p.permission_key IN (
    'attendance.view',
    'grades.view',
    'classes.view',
    'announcements.view'
)
WHERE r.role_key = 'student'
ON DUPLICATE KEY UPDATE enabled = 1;

-- Parent permissions
INSERT INTO rbac_role_permissions (role_id, permission_id, enabled)
SELECT r.id, p.id, 1
FROM rbac_roles r
JOIN rbac_permissions p ON p.permission_key IN (
    'attendance.view',
    'grades.view',
    'reports.view',
    'announcements.view'
)
WHERE r.role_key = 'parent'
ON DUPLICATE KEY UPDATE enabled = 1;
