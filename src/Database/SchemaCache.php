<?php

namespace BshsAms\Database;

use PDO;

class SchemaCache
{
    private static ?array $columns = null;
    private static ?array $tables = null;
    private static int $ttl = 3600;
    private static ?int $loadedAt = null;

    private static array $knownTables = [
        'users' => true,
        'classes' => true,
        'class_subjects' => true,
        'class_schedules' => true,
        'enrollments' => true,
        'attendance' => true,
        'announcements' => true,
        'subjects' => true,
        'sections' => true,
        'grade_items' => true,
        'grade_item_scores' => true,
        'grade_approvals' => true,
        'report_card_approvals' => true,
        'user_settings' => true,
        'user_notifications' => true,
        'messages' => true,
        'push_subscriptions' => true,
        'parent_students' => true,
        'rbac_roles' => true,
        'rbac_permissions' => true,
        'rbac_role_permissions' => true,
        'admin_audit_logs' => true,
        'auth_login_logs' => true,
        'auth_password_resets' => true,
        'auth_remember_tokens' => true,
        'materials' => true,
        'learning_materials' => true,
        'school_settings' => true,
        'academic_year_settings' => true,
        'report_notes' => true,
        'website_content' => true,
    ];

    private static array $knownColumns = [
        'classes' => [
            'id', 'class_name', 'grade_level', 'section', 'teacher_id', 'schedule', 'room',
            'ww_weight', 'pt_weight', 'assessment_weight', 'status', 'subject_category',
            'track', 'curriculum', 'program', 'created_at', 'updated_at'
        ],
        'users' => [
            'id', 'reference_code', 'email', 'lrn', 'password', 'api_token_version',
            'first_name', 'middle_name', 'last_name', 'name_extension', 'sex', 'date_of_birth',
            'religion', 'profile_picture', 'contact_number', 'address', 'house_street',
            'barangay', 'municipality', 'province', 'father_name', 'mother_name',
            'guardian_name', 'guardian_relationship', 'grade_level', 'section', 'role',
            'track', 'curriculum', 'program', 'status', 'created_at', 'updated_at'
        ],
        'user_settings' => [
            'id', 'user_id', 'dark_mode', 'email_notifications', 'push_notifications', 'created_at', 'updated_at'
        ],
        'user_notifications' => [
            'id', 'user_id', 'source_key', 'title', 'subtitle', 'icon', 'color', 'link', 'is_read', 'event_at', 'created_at'
        ],
        'attendance' => [
            'id', 'student_id', 'class_id', 'date', 'status', 'time_in', 'recorded_by', 'remarks', 'created_at', 'updated_at'
        ],
        'enrollments' => [
            'id', 'student_id', 'class_id', 'grade_level', 'section', 'academic_year', 'status', 'enrolled_at'
        ],
        'grade_items' => [
            'id', 'class_id', 'grading_period', 'component', 'item_number', 'item_name', 'total_score', 'weight', 'created_at', 'updated_at'
        ],
        'grade_item_scores' => [
            'id', 'grade_item_id', 'student_id', 'score', 'created_at', 'updated_at'
        ],
        'messages' => [
            'id', 'sender_id', 'receiver_id', 'message', 'is_read', 'created_at'
        ],
    ];

    public static function getColumns(PDO $db, string $table): array
    {
        self::ensureInitialized();
        if (!isset(self::$columns[$table])) {
            if (isset(self::$knownColumns[$table])) {
                self::$columns[$table] = self::$knownColumns[$table];
                return self::$columns[$table];
            }
            try {
                $stmt = $db->prepare("SHOW COLUMNS FROM {$table}");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                self::$columns[$table] = array_column($columns, 'Field');
            } catch (\Throwable $e) {
                self::$columns[$table] = [];
            }
        }
        return self::$columns[$table];
    }

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        if (isset(self::$knownColumns[$table]) && in_array($column, self::$knownColumns[$table], true)) {
            return true;
        }
        return in_array($column, self::getColumns($db, $table), true);
    }

    public static function getTables(PDO $db): array
    {
        self::ensureInitialized();
        if (self::$tables === null) {
            try {
                $stmt = $db->query("SHOW TABLES");
                self::$tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            } catch (\Throwable $e) {
                self::$tables = array_keys(self::$knownTables);
            }
        }
        return self::$tables;
    }

    public static function hasTable(PDO $db, string $table): bool
    {
        if (isset(self::$knownTables[$table])) {
            return true;
        }
        return in_array($table, self::getTables($db), true);
    }

    public static function clearCache(): void
    {
        self::$columns = null;
        self::$tables = null;
        self::$loadedAt = null;
    }

    private static function ensureInitialized(): void
    {
        if (self::$loadedAt === null || (time() - self::$loadedAt) > self::$ttl) {
            self::$columns = [];
            self::$tables = null;
            self::$loadedAt = time();
        }
    }

    public static function setTtl(int $seconds): void
    {
        self::$ttl = $seconds;
    }
}
