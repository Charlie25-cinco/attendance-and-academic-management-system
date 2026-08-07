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
        $this->assertStringNotContainsString('CREATE INDEX IF NOT EXISTS', $content);
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
