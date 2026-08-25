<?php

use PHPUnit\Framework\TestCase;

final class RuntimeDdlGuardTest extends TestCase
{
    /**
     * Files allowed to contain runtime CREATE TABLE statements:
     * - functions/app-helpers.php: SQLite test fixtures plus the RBAC bootstrap
     *   that README documents as auto-creating and seeding RBAC tables.
     * - config/constants.php, src/Notification/SmsService.php: SQLite test fixtures.
     * database/schema.sql is the canonical source and is not scanned here.
     */
    private function allowedRuntimeFiles(): array
    {
        return [
            'functions/app-helpers.php',
            'config/constants.php',
            'src/Notification/SmsService.php',
        ];
    }

    public function testNoNewRuntimeCreateTableStatementsOutsideTheAllowlist(): void
    {
        $directories = ['admin', 'api', 'auth', 'config', 'functions', 'includes', 'parent', 'scripts', 'site', 'src', 'student', 'teacher'];
        $offenders = [];
        foreach ($directories as $directory) {
            foreach (glob(APP_ROOT . '/' . $directory . '/*.php') ?: [] as $path) {
                $relative = str_replace('\\', '/', substr($path, strlen(APP_ROOT) + 1));
                if (in_array($relative, $this->allowedRuntimeFiles(), true)) {
                    continue;
                }
                if (preg_match('/CREATE TABLE\s+(IF NOT EXISTS\s+)?[`"]?[a-z_]+/i', (string)file_get_contents($path))) {
                    $offenders[] = $relative;
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'Runtime MySQL DDL must live in database/schema.sql. New tables ship there first; '
            . 'SQLite test fixtures stay inside the allowlisted fixture files.'
        );
    }

    public function testEveryRuntimeCreatedTableExistsInSchemaSql(): void
    {
        $schema = (string)file_get_contents(APP_ROOT . '/database/schema.sql');
        foreach ($this->allowedRuntimeFiles() as $relative) {
            $content = (string)file_get_contents(APP_ROOT . '/' . $relative);
            preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z_]+)/i', $content, $matches);
            foreach ($matches[1] as $table) {
                $this->assertMatchesRegularExpression(
                    '/CREATE TABLE IF NOT EXISTS\s+`?' . preg_quote($table, '/') . '`?/i',
                    $schema,
                    "Table {$table} is created by runtime code in {$relative} but missing from database/schema.sql."
                );
            }
        }
    }
}
