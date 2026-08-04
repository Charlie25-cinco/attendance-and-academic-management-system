<?php

use PHPUnit\Framework\TestCase;

final class PerformanceIndexTest extends TestCase
{
    public function testPerformanceIndexesMigrationFileExists(): void
    {
        $path = __DIR__ . '/../database/performance_indexes.sql';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);

        $expectedIndexes = [
            'idx_users_role_status_grade',
            'idx_classes_grade_section_status',
            'idx_enrollments_student_class_ay',
            'idx_attendance_student_date_status',
            'idx_grades_student_cs_term_ay',
            'idx_grade_items_cs_term',
            'idx_grade_scores_item_student',
        ];

        foreach ($expectedIndexes as $indexName) {
            $this->assertStringContainsString($indexName, $content);
        }
    }

    public function testSchemaTidbIncludesPerformanceIndexes(): void
    {
        $path = __DIR__ . '/../database/schema_tidb.sql';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('PERFORMANCE COMPOSITE INDEXES', $content);
        $this->assertStringContainsString('idx_users_role_status_grade', $content);
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
