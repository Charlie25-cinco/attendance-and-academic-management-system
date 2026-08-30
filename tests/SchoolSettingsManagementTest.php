<?php

use PHPUnit\Framework\TestCase;

final class SchoolSettingsManagementTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE IF NOT EXISTS school_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT UNIQUE NOT NULL,
            setting_value TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id INTEGER NOT NULL,
            action_name TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NULL,
            details_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS website_content (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_key TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testGetAndSetSchoolSettings(): void
    {
        $this->assertTrue(setSchoolSetting($this->db, 'school_name', 'Magsaysay National High School'));
        $this->assertTrue(setSchoolSetting($this->db, 'school_id', '304123'));
        $this->assertTrue(setSchoolSetting($this->db, 'region', 'Region VII'));
        $this->assertTrue(setSchoolSetting($this->db, 'division', 'Cebu Province'));

        $this->assertSame('Magsaysay National High School', getSchoolSetting($this->db, 'school_name'));
        $this->assertSame('304123', getSchoolSetting($this->db, 'school_id'));
        $this->assertSame('Region VII', getSchoolSetting($this->db, 'region'));
        $this->assertSame('Cebu Province', getSchoolSetting($this->db, 'division'));
        $this->assertSame('Default Fallback', getSchoolSetting($this->db, 'non_existent_key', 'Default Fallback'));

        $all = getSchoolSettings($this->db);
        $this->assertArrayHasKey('school_name', $all);
        $this->assertSame('Magsaysay National High School', $all['school_name']);
        $this->assertSame('304123', $all['school_id']);
    }

    public function testAdminSchoolSettingsPageStructureAndInputs(): void
    {
        $page = file_get_contents(__DIR__ . '/../admin/admin_School_Settings.php');
        $this->assertIsString($page);

        $this->assertStringContainsString('name="school_name"', $page);
        $this->assertStringContainsString('name="school_id"', $page);
        $this->assertStringContainsString('name="school_head"', $page);
        $this->assertStringContainsString('name="region"', $page);
        $this->assertStringContainsString('name="division"', $page);
        $this->assertStringContainsString('name="district"', $page);
        $this->assertStringContainsString('name="school_address"', $page);
        $this->assertStringContainsString('name="contact_email"', $page);
        $this->assertStringContainsString('name="contact_number"', $page);
        $this->assertStringContainsString('name="office_hours"', $page);
        $this->assertStringContainsString('csrf_token', $page);
    }

    public function testAdminSchoolSettingsActionEnforcesCsrfAndRbac(): void
    {
        $action = file_get_contents(__DIR__ . '/../admin/admin_School_Settings_Action.php');
        $this->assertIsString($action);

        $this->assertStringContainsString('requireCsrfToken()', $action);
        $this->assertStringContainsString("hasPermission('settings.manage')", $action);
        $this->assertStringContainsString('recordAdminAuditLog(', $action);
        $this->assertStringContainsString('setSchoolSetting(', $action);
        $this->assertStringContainsString("REQUEST_METHOD", $action);

        // Verify recordAdminAuditLog executes without TypeError
        recordAdminAuditLog(
            $this->db,
            'school_settings.update',
            'school_settings',
            null,
            ['school_name' => 'Test High School'],
            1
        );
        $logCount = (int)$this->db->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action_name = 'school_settings.update'")->fetchColumn();
        $this->assertSame(1, $logCount);
    }

    public function testPermissionForScriptIncludesSchoolSettings(): void
    {
        $this->assertSame('settings.view', permissionForScript('admin_school_settings.php'));
        $this->assertSame('settings.manage', permissionForScript('admin_school_settings_action.php'));
    }

    public function testSidebarContainsSchoolSettingsMenu(): void
    {
        $sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
        $this->assertIsString($sidebar);

        $this->assertStringContainsString('admin_School_Settings.php', $sidebar);
        $this->assertStringContainsString('School Settings', $sidebar);
        $this->assertStringContainsString('getSchoolSetting(', $sidebar);
    }
}