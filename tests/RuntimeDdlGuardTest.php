<?php

use PHPUnit\Framework\TestCase;

final class RuntimeDdlGuardTest extends TestCase
{
    /**
     * Files allowed to contain documented compatibility CREATE/ALTER TABLE statements:
     * - functions/app-helpers.php: SQLite test fixtures plus the RBAC bootstrap
     *   that README documents as auto-creating and seeding RBAC tables.
     * - config/constants.php: SQLite test fixtures.
     * database/schema.sql is the canonical source and is not scanned here.
     */
    private function allowedRuntimeFiles(): array
    {
        return [
            'functions/app-helpers.php',
            'config/constants.php',
        ];
    }

    public function testNoNewRuntimeDdlStatementsOutsideTheAllowlist(): void
    {
        $directories = ['principal', 'admin', 'api', 'auth', 'config', 'functions', 'includes', 'parent', 'scripts', 'site', 'src', 'student', 'teacher'];
        $offenders = [];
        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                APP_ROOT . '/' . $directory,
                FilesystemIterator::SKIP_DOTS
            ));
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $relative = str_replace('\\', '/', substr($path, strlen(APP_ROOT) + 1));
                if (in_array($relative, $this->allowedRuntimeFiles(), true)) {
                    continue;
                }
                if (preg_match('/\b(?:CREATE|ALTER)\s+TABLE\b/i', (string)file_get_contents($path))) {
                    $offenders[] = $relative;
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'Runtime MySQL DDL must live in database/schema.sql. New tables and columns ship there first; '
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

    public function testAllowlistedDdlStaysInsideDocumentedCompatibilityFunctions(): void
    {
        $allowedFunctionsByFile = [
            'functions/app-helpers.php' => [
                'ensureUserApiTokenVersionColumn',
                'ensureStrengthenedShsColumns',
                'ensureReportNotesTables',
                'appEnsureUserNotificationsTable',
                'ensureAuthLoginLogsTable',
                'ensureRbacTables',
            ],
            'config/constants.php' => ['pushEnsureSubscriptionsTable'],
        ];

        foreach ($allowedFunctionsByFile as $relative => $functionNames) {
            $ranges = [];
            foreach ($functionNames as $functionName) {
                $reflection = new ReflectionFunction($functionName);
                $ranges[] = [$reflection->getStartLine(), $reflection->getEndLine()];
            }
            $lines = file(APP_ROOT . '/' . $relative);
            foreach ($lines as $index => $line) {
                if (!preg_match('/\b(?:CREATE|ALTER)\s+TABLE\b/i', $line)) {
                    continue;
                }
                $lineNumber = $index + 1;
                $allowed = array_filter($ranges, static fn(array $range): bool => $lineNumber >= $range[0] && $lineNumber <= $range[1]);
                $this->assertNotEmpty($allowed, "Undocumented runtime DDL at {$relative}:{$lineNumber}");
            }
        }

        $notificationBody = $this->functionSource('appEnsureUserNotificationsTable');
        $pushBody = $this->functionSource('pushEnsureSubscriptionsTable');
        $this->assertStringNotContainsString('ENGINE=InnoDB', $notificationBody);
        $this->assertStringNotContainsString('ENGINE=InnoDB', $pushBody);
        $this->assertFileDoesNotExist(APP_ROOT . '/src/Notification/SmsService.php');
    }

    private function functionSource(string $functionName): string
    {
        $reflection = new ReflectionFunction($functionName);
        $source = file($reflection->getFileName());
        return implode('', array_slice($source, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
}
