<?php

use PHPUnit\Framework\TestCase;

final class SubjectSeedTest extends TestCase
{
    public function testCanonicalSchemaContainsHostedSafeGradeElevenSubjectRegistry(): void
    {
        $path = __DIR__ . '/../database/schema.sql';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);

        $this->assertDoesNotMatchRegularExpression('/^\s*USE\s+/mi', $content);
        $this->assertStringContainsString('strengthened_g11_subject_count', $content);
    }

    public function testCanonicalSchemaIncludesSubjectsSettingsAndRbacBaseline(): void
    {
        $path = __DIR__ . '/../database/schema.sql';
        $content = file_get_contents($path);
        $this->assertIsString($content);

        $this->assertStringContainsString('INSERT IGNORE INTO subjects', $content);
        $this->assertStringContainsString('INSERT IGNORE INTO website_content', $content);
        $this->assertStringContainsString('INSERT IGNORE INTO school_settings', $content);
        $this->assertStringContainsString('INSERT IGNORE INTO rbac_roles', $content);
        $this->assertStringContainsString('INSERT IGNORE INTO rbac_permissions', $content);
        $this->assertStringContainsString('INSERT IGNORE INTO rbac_role_permissions', $content);
        $this->assertStringContainsString("'ELECTCOM'", $content);
        $this->assertStringContainsString("'GENMATH'", $content);
        $this->assertStringContainsString("'academic_elective'", $content);
        $this->assertStringContainsString("'techpro_elective'", $content);
    }

    public function testSeparateLegacySeedFilesWereRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__ . '/../database/seed.sql');
        $this->assertFileDoesNotExist(__DIR__ . '/../database/seed_ssms_g11_subjects.sql');
        $this->assertFileDoesNotExist(__DIR__ . '/../database/upgrade_principal_portal.sql');
    }
}
