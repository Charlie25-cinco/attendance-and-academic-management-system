<?php

use BshsAms\Storage\MaterialStorage;
use PHPUnit\Framework\TestCase;

final class MaterialStorageTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bshs_material_' . bin2hex(random_bytes(8));
        putenv('MATERIAL_STORAGE_PATH=' . $this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        putenv('MATERIAL_STORAGE_PATH');
        if (!is_dir($this->temporaryDirectory)) {
            return;
        }
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) { @unlink($path); }
        }
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '.*') ?: [] as $path) {
            if (is_file($path)) { @unlink($path); }
        }
        @rmdir($this->temporaryDirectory);
    }

    public function testCreatesProtectedDurableDirectoryAndLocatesFile(): void
    {
        $storedName = MaterialStorage::createStoredName('pdf');
        $path = MaterialStorage::pathFor($storedName, true);

        $this->assertDirectoryExists($this->temporaryDirectory);
        $this->assertFileExists($this->temporaryDirectory . DIRECTORY_SEPARATOR . '.htaccess');
        $this->assertFileExists($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'index.php');
        $this->assertMatchesRegularExpression('/^material_[a-f0-9]{32}\.pdf$/', $storedName);

        file_put_contents($path, '%PDF-1.4 test');
        $this->assertSame($path, MaterialStorage::locate($storedName));
        $this->assertTrue(MaterialStorage::delete($storedName));
        $this->assertFileDoesNotExist($path);
    }

    public function testRejectsUnsafeStoredFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MaterialStorage::pathFor('../private.pdf');
    }

    public function testUsesExpectedDownloadContentTypes(): void
    {
        $this->assertSame('application/pdf', MaterialStorage::contentType('pdf'));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            MaterialStorage::contentType('pptx')
        );
        $this->assertSame('application/octet-stream', MaterialStorage::contentType('unknown'));
    }

    public function testMaterialEndpointsUseSharedStorageAndEnrollmentAuthorization(): void
    {
        $teacherAction = file_get_contents(__DIR__ . '/../teacher/teacher_Action.php');
        $studentAction = file_get_contents(__DIR__ . '/../student/Student_Action.php');

        $this->assertIsString($teacherAction);
        $this->assertStringContainsString('MaterialStorage::pathFor($storedName, true)', $teacherAction);
        $this->assertStringContainsString('MaterialStorage::locate', $teacherAction);
        $this->assertStringContainsString('MaterialStorage::delete', $teacherAction);
        $this->assertStringNotContainsString("realpath(__DIR__ . '/../../')", $teacherAction);

        $this->assertIsString($studentAction);
        $this->assertStringContainsString('MaterialStorage::locate', $studentAction);
        $this->assertStringContainsString("e.student_id = ? AND e.status = 'enrolled'", $studentAction);
        $this->assertStringNotContainsString("realpath(__DIR__ . '/../../')", $studentAction);
    }

    public function testMaterialMutationsIncludeCsrfAndStorageCannotBeFetchedDirectly(): void
    {
        $teacherPage = file_get_contents(__DIR__ . '/../teacher/teacher_Classes.php');
        $router = file_get_contents(__DIR__ . '/../router.php');
        $uploadProtection = file_get_contents(__DIR__ . '/../assets/uploads/.htaccess');

        $this->assertIsString($teacherPage);
        $uploadStart = strpos($teacherPage, 'function uploadMaterial(event)');
        $uploadEnd = strpos($teacherPage, 'function openEditMaterialModal', $uploadStart ?: 0);
        $updateStart = strpos($teacherPage, 'function updateMaterial()');
        $updateEnd = strpos($teacherPage, 'function deleteMaterial', $updateStart ?: 0);
        $this->assertIsInt($uploadStart);
        $this->assertIsInt($uploadEnd);
        $this->assertIsInt($updateStart);
        $this->assertIsInt($updateEnd);
        $this->assertStringContainsString('appendCsrfToFormData(fd)', substr($teacherPage, $uploadStart, $uploadEnd - $uploadStart));
        $this->assertStringContainsString('appendCsrfToFormData(fd)', substr($teacherPage, $updateStart, $updateEnd - $updateStart));
        $this->assertStringContainsString('fetchMaterialJson', $teacherPage);
        $this->assertStringContainsString('id="uploadMaterialModal"', $teacherPage);
        $this->assertStringContainsString('id="materialDropZone"', $teacherPage);
        $this->assertStringContainsString('id="chooseMaterialFileBtn"', $teacherPage);
        $this->assertStringContainsString('id="materialFileStatus" role="status" aria-live="polite"', $teacherPage);
        $this->assertStringContainsString("materialFileInput.addEventListener('change',validateMaterialFile)", $teacherPage);
        $this->assertStringContainsString("materialDropZone.addEventListener('drop'", $teacherPage);
        $this->assertStringContainsString("materialDropZone.addEventListener('paste'", $teacherPage);
        $this->assertStringContainsString('window.showOpenFilePicker', $teacherPage);
        $this->assertStringContainsString("startIn:'documents'", $teacherPage);
        $this->assertStringContainsString('new DataTransfer()', $teacherPage);
        $this->assertStringContainsString('file.size>10*1024*1024', $teacherPage);
        $this->assertMatchesRegularExpression('/<input type="file"[^>]*id="materialFileInput"[^>]*hidden>/', $teacherPage);
        $this->assertDoesNotMatchRegularExpression('/<input type="file"[^>]*id="materialFileInput"[^>]*accept=/', $teacherPage);
        $this->assertStringNotContainsString('FileReader', $teacherPage);
        $this->assertStringNotContainsString('createObjectURL', $teacherPage);

        $this->assertIsString($router);
        $this->assertStringContainsString('(?:storage|assets/uploads/materials)', $router);
        $this->assertIsString($uploadProtection);
        $this->assertStringContainsString('RewriteRule ^materials(?:/|$)', $uploadProtection);
    }
}
