<?php

use PHPUnit\Framework\TestCase;

final class SubjectSeedTest extends TestCase
{
    public function testGradeElevenSubjectSeedIsHostedDatabaseSafe(): void
    {
        $path = __DIR__ . '/../database/seed_ssms_g11_subjects.sql';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);

        $this->assertDoesNotMatchRegularExpression('/^\s*USE\s+/mi', $content);
        $this->assertStringContainsString('table_schema = DATABASE()', $content);
        $this->assertStringContainsString('strengthened_g11_subject_count', $content);
    }

    public function testGradeElevenSubjectSeedTargetsSubjectRegistryOnly(): void
    {
        $path = __DIR__ . '/../database/seed_ssms_g11_subjects.sql';
        $content = file_get_contents($path);
        $this->assertIsString($content);

        preg_match_all('/INSERT\s+IGNORE\s+INTO\s+([a-z_]+)/i', $content, $matches);

        $this->assertNotEmpty($matches[1]);
        $this->assertSame(['subjects'], array_values(array_unique(array_map('strtolower', $matches[1]))));
        $this->assertStringContainsString("'ELECTCOM'", $content);
        $this->assertStringContainsString("'GENMATH'", $content);
        $this->assertStringContainsString("'academic_elective'", $content);
        $this->assertStringContainsString("'techpro_elective'", $content);
    }
}
