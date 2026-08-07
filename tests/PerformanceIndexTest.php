<?php

use PHPUnit\Framework\TestCase;

final class PerformanceIndexTest extends TestCase
{
    public function testCanonicalSchemaIncludesPerformanceIndexes(): void
    {
        $path = __DIR__ . '/../database/schema.sql';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('PERFORMANCE COMPOSITE INDEXES', $content);
        $this->assertStringContainsString('idx_users_role_status_grade', $content);
        $this->assertStringContainsString('idx_grade_items_class_teacher_date_status', $content);
        $this->assertStringNotContainsString('idx_grade_items_class_term', $content);
        $this->assertStringNotContainsString('CREATE INDEX IF NOT EXISTS', $content);
    }

    public function testGradeItemsPerformanceIndexUsesExistingColumns(): void
    {
        $path = __DIR__ . '/../database/schema.sql';
        $content = file_get_contents($path);
        $this->assertIsString($content);

        $this->assertSame(1, preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+grade_items\s*\((.*?)\)\s+ENGINE=/is',
            $content,
            $tableMatches
        ));
        $this->assertSame(1, preg_match(
            '/CREATE\s+INDEX\s+idx_grade_items_class_teacher_date_status\s+ON\s+grade_items\s*\((.*?)\);/is',
            $content,
            $indexMatches
        ));

        $tableColumns = [];
        foreach (preg_split('/\R/', $tableMatches[1]) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^`?([a-z_]+)`?\s+[A-Z]/i', $line, $matches)) {
                $tableColumns[] = $matches[1];
            }
        }

        $indexColumns = array_map(
            static fn (string $column): string => trim($column, " `\t\n\r\0\x0B"),
            explode(',', $indexMatches[1])
        );

        foreach ($indexColumns as $column) {
            $this->assertContains($column, $tableColumns, sprintf('Missing grade_items column used by index: %s', $column));
        }
    }

    public function testProductionSchemaIncludesCoreHostedSetup(): void
    {
        $path = __DIR__ . '/../database/schema.sql';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS app_sessions', $content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS rbac_roles', $content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS rbac_permissions', $content);
        $this->assertStringContainsString('idx_users_role_status_grade', $content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS users', $content);
    }

    public function testSchemaCacheMemoryOperations(): void
    {
        SchemaCache::clearCache();

        $refClass = new ReflectionClass(SchemaCache::class);
        $ttlProp = $refClass->getProperty('ttl');
        $ttlProp->setAccessible(true);
        $this->assertSame(3600, $ttlProp->getValue());

        SchemaCache::setTtl(7200);
        $this->assertSame(7200, $ttlProp->getValue());
        SchemaCache::setTtl(3600);
    }
}
