<?php

use PHPUnit\Framework\TestCase;
use BshsAms\Database\Database;

final class PerformanceOptimizationTest extends TestCase
{
    protected function setUp(): void
    {
        Database::resetSharedConnection();
    }

    protected function tearDown(): void
    {
        Database::resetSharedConnection();
    }

    public function testDatabaseClassHasSharedConnectionLogic(): void
    {
        $dbClass = file_get_contents(__DIR__ . '/../src/Database/Database.php');
        $this->assertIsString($dbClass);

        $this->assertStringContainsString('private static ?PDO $sharedConnection', $dbClass);
        $this->assertStringContainsString('public static function resetSharedConnection()', $dbClass);
        $this->assertStringContainsString('self::$sharedConnection instanceof PDO', $dbClass);
    }

    public function testServiceWorkerUsesStaleWhileRevalidateForAssets(): void
    {
        $swJs = file_get_contents(__DIR__ . '/../sw.js');
        $this->assertIsString($swJs);

        $this->assertStringContainsString('bshs-ams-v32', $swJs);
        $this->assertStringContainsString('return cached || fetchAndCache;', $swJs);
    }

    public function testMainJsAndOfflineStorageTargetV32(): void
    {
        $mainJs = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($mainJs);
        $this->assertStringContainsString('bshs-ams-v32', $mainJs);

        $storageJs = file_get_contents(__DIR__ . '/../assets/js/offlineStorage.js');
        $this->assertIsString($storageJs);
        $this->assertStringContainsString('bshs-ams-v32', $storageJs);
    }
}