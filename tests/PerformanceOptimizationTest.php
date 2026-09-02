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

        $this->assertStringContainsString('bshs-ams-v36', $swJs);
        $this->assertStringContainsString('isStaticAsset', $swJs);
        $this->assertStringContainsString('cacheResponse', $swJs);

        // 3. Verify main.js and offlineStorage.js cache version synchronization
        $mainJs = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertStringContainsString('bshs-ams-v36', $mainJs);

        $storageJs = file_get_contents(__DIR__ . '/../assets/js/offlineStorage.js');
        $this->assertStringContainsString('bshs-ams-v36', $storageJs);
    }

    public function testMainCssHasZeroBlockingImports(): void
    {
        $mainCss = file_get_contents(__DIR__ . '/../assets/css/main.css');
        $this->assertIsString($mainCss);
        $this->assertStringNotContainsString('@import', $mainCss);
        $this->assertStringContainsString('notification-toast', $mainCss);
    }

    public function testPwaHeadIncludesFontPreconnect(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);
        $this->assertStringContainsString('preconnect', $constants);
        $this->assertStringContainsString('fonts.googleapis.com', $constants);
        $this->assertStringContainsString('fonts.gstatic.com', $constants);
    }
}