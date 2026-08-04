<?php

namespace BshsAms\Database;

use PDO;

class SchemaCache
{
    private static ?array $columns = null;
    private static ?array $tables = null;
    private static int $ttl = 3600;
    private static ?int $loadedAt = null;

    public static function getColumns(PDO $db, string $table): array
    {
        self::ensureInitialized();
        if (!isset(self::$columns[$table])) {
            $stmt = $db->prepare("SHOW COLUMNS FROM {$table}");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            self::$columns[$table] = array_column($columns, 'Field');
        }
        return self::$columns[$table];
    }

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        return in_array($column, self::getColumns($db, $table), true);
    }

    public static function getTables(PDO $db): array
    {
        self::ensureInitialized();
        if (self::$tables === null) {
            $stmt = $db->query("SHOW TABLES");
            self::$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return self::$tables;
    }

    public static function hasTable(PDO $db, string $table): bool
    {
        return in_array($table, self::getTables($db), true);
    }

    public static function clearCache(): void
    {
        self::$columns = null;
        self::$tables = null;
        self::$loadedAt = null;
    }

    private static function ensureInitialized(): void
    {
        if (self::$loadedAt === null || (time() - self::$loadedAt) > self::$ttl) {
            self::$columns = [];
            self::$tables = null;
            self::$loadedAt = time();
        }
    }

    public static function setTtl(int $seconds): void
    {
        self::$ttl = $seconds;
    }
}
