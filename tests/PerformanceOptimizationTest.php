<?php

use PHPUnit\Framework\TestCase;
use BshsAms\Database\SchemaCache;

final class PerformanceOptimizationTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    public function testLazyAutoloadAliasesResolvesLegacyClasses(): void
    {
        $this->assertTrue(class_exists('SimpleXlsxWriter'));
        $this->assertTrue(class_exists('Sf1Exporter'));
        $this->assertTrue(class_exists('Sf2Exporter'));
        $this->assertTrue(class_exists('Sf5Exporter'));
        $this->assertTrue(class_exists('Sf9Exporter'));
        $this->assertTrue(class_exists('EcrExporter'));
        $this->assertTrue(class_exists('ReportFilterHelper'));
        $this->assertTrue(class_exists('SshsGradeCalculator'));
        $this->assertTrue(class_exists('GradeImporter'));
    }

    public function testRbacPermissionsCacheValidation(): void
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = 42;
        $_SESSION['role'] = 'teacher';
        $_SESSION['rbac_permissions_user_id'] = 42;
        $_SESSION['rbac_permissions_role'] = 'teacher';
        $_SESSION['rbac_permissions_loaded_at'] = time();
        $_SESSION['rbac_permissions'] = ['attendance.view', 'attendance.mark'];

        $this->assertTrue(isRbacPermissionsCacheValid());
        $this->assertTrue(hasPermission('attendance.view'));
        $this->assertFalse(hasPermission('users.manage'));

        // Test user ID mismatch invalidation
        $_SESSION['user_id'] = 99;
        $this->assertFalse(isRbacPermissionsCacheValid());

        // Restore and test role mismatch invalidation
        $_SESSION['user_id'] = 42;
        $_SESSION['role'] = 'admin';
        $this->assertFalse(isRbacPermissionsCacheValid());

        // Test expired TTL invalidation
        $_SESSION['role'] = 'teacher';
        $_SESSION['rbac_permissions_loaded_at'] = time() - 400; // > 300s TTL
        $this->assertFalse(isRbacPermissionsCacheValid());
    }

    public function testSchemaCacheKnownMapReturnsTrueInstantly(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->assertTrue(SchemaCache::hasTable($pdo, 'users'));
        $this->assertTrue(SchemaCache::hasTable($pdo, 'classes'));
        $this->assertTrue(SchemaCache::hasTable($pdo, 'messages'));
        $this->assertTrue(SchemaCache::hasTable($pdo, 'user_notifications'));
        $this->assertTrue(SchemaCache::hasTable($pdo, 'user_settings'));

        $this->assertTrue(SchemaCache::hasColumn($pdo, 'classes', 'track'));
        $this->assertTrue(SchemaCache::hasColumn($pdo, 'classes', 'curriculum'));
        $this->assertTrue(SchemaCache::hasColumn($pdo, 'classes', 'program'));
        $this->assertTrue(SchemaCache::hasColumn($pdo, 'users', 'reference_code'));
        $this->assertTrue(SchemaCache::hasColumn($pdo, 'users', 'sex'));
    }

    public function testNotificationCacheInvalidationOnDispatch(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT);");
        $pdo->exec("INSERT INTO users (id, role) VALUES (10, 'student');");

        $_SESSION['user_id'] = 10;
        $_SESSION['app_header_notifications'] = [
            'items' => [['id' => 1, 'title' => 'Old']],
            'count' => 1
        ];

        appNotifyUsers($pdo, [10], 'test_key', 'New Title', 'New Subtitle');

        // Session cache should be invalidated
        $this->assertArrayNotHasKey('app_header_notifications', $_SESSION);
    }
}
