<?php

use PHPUnit\Framework\TestCase;

final class AppHelpersTest extends TestCase
{
    public function testMaterialStorageDirUsesIgnoredStorageDirectory(): void
    {
        $expected = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'materials';

        self::assertSame($expected, appMaterialStorageDir(false));
    }

    public function testMaterialFilePathSanitizesNestedFileName(): void
    {
        $expected = appMaterialStorageDir(false) . DIRECTORY_SEPARATOR . 'lesson.pdf';

        self::assertSame($expected, appMaterialFilePath('../nested/lesson.pdf'));
    }
}

