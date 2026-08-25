<?php

use PHPUnit\Framework\TestCase;

final class SectionMatchSqlTest extends TestCase
{
    public function testPlaceholderModeProducesTheCanonicalClause(): void
    {
        $sql = sectionMatchSql('c.section');
        $this->assertSame(
            "(LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(?, '')))"
            . " OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(c.section, ''), '(', 1)))"
            . " = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1))))",
            $sql
        );
    }

    public function testColumnModeComparesTwoColumnsWithoutPlaceholders(): void
    {
        $sql = sectionMatchSql('u.section', 'cb.section');
        $this->assertStringContainsString("COALESCE(u.section, ''))) = LOWER(TRIM(COALESCE(cb.section", $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testRejectsInvalidIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        sectionMatchSql('section; DROP TABLE users');
    }

    public function testRawFragmentExistsOnlyInAppHelpers(): void
    {
        $directories = ['admin', 'api', 'auth', 'config', 'database', 'functions', 'includes', 'parent', 'scripts', 'site', 'src', 'student', 'teacher'];
        $offenders = [];
        foreach ($directories as $directory) {
            foreach (glob(APP_ROOT . '/' . $directory . '/*.php') ?: [] as $path) {
                if (str_ends_with($path, 'app-helpers.php')) {
                    continue;
                }
                $content = (string)file_get_contents($path);
                if (str_contains($content, 'SUBSTRING_INDEX(COALESCE(')) {
                    $offenders[] = basename($path);
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'The fuzzy section-match SQL fragment must come from sectionMatchSql() in functions/app-helpers.php.'
        );
    }

    public function testApiHelpersDelegateToGlobalTwins(): void
    {
        $delegations = [
            'api/index.php' => [
                'apiValidPersonName' => 'isValidPersonName',
                'apiHasMinimumLetters' => 'hasMinimumLetters',
                'apiNormalizeDate' => 'normalizeDate',
            ],
            'api/apisupport.php' => [
                'apiGetTeacherRoles' => 'getTeacherRoles',
            ],
        ];
        foreach ($delegations as $file => $pairs) {
            $content = (string)file_get_contents(APP_ROOT . '/' . $file);
            foreach ($pairs as $apiName => $globalName) {
                $this->assertMatchesRegularExpression(
                    '/function\s+' . $apiName . '\([^)]*\)\s*(?::\s*\w+\s*)?\{\s*return\s+' . $globalName . '\(/',
                    $content,
                    "{$apiName}() must delegate to {$globalName}() instead of duplicating its logic."
                );
            }
        }
    }
}
