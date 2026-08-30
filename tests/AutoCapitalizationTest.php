<?php

use PHPUnit\Framework\TestCase;

final class AutoCapitalizationTest extends TestCase
{
    public function testNormalizeSubjectNamePreservesAcronymsAndCapitalizesWords(): void
    {
        require_once __DIR__ . '/../functions/app-helpers.php';

        $this->assertSame('PE', normalizeSubjectNameValue('PE'));
        $this->assertSame('MAPEH 10', normalizeSubjectNameValue('MAPEH 10'));
        $this->assertSame('TVL - ICT', normalizeSubjectNameValue('TVL - ICT'));
        $this->assertSame('STEM', normalizeSubjectNameValue('STEM'));
        $this->assertSame('General Mathematics', normalizeSubjectNameValue('general mathematics'));
        $this->assertSame('Intro To World Religions', normalizeSubjectNameValue('intro to world religions'));
        $this->assertSame('', normalizeSubjectNameValue('   '));
    }

    public function testClassesJsPreservesUppercaseInput(): void
    {
        $classesJs = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($classesJs);
        $this->assertStringNotContainsString('value.toLowerCase().replace(/\b\w/g', $classesJs);
        $this->assertStringContainsString('value.replace(/\b\w/g, ch => ch.toUpperCase())', $classesJs);
    }

    public function testSectionInputsHaveAutoCapitalization(): void
    {
        $sections = file_get_contents(__DIR__ . '/../admin/admin_Sections.php');
        $this->assertIsString($sections);
        $this->assertStringContainsString('id="createSectionName"', $sections);
        $this->assertStringContainsString('id="editSectionName"', $sections);
        $this->assertStringContainsString('oninput="capitalizeWords(this)"', $sections);
        $this->assertStringContainsString('function capitalizeWords(input)', $sections);
    }

    public function testAnnouncementInputsHaveAutoCapitalization(): void
    {
        $announcements = file_get_contents(__DIR__ . '/../admin/admin_Announcements.php');
        $this->assertIsString($announcements);
        $this->assertStringContainsString('name="title" class="form-control" oninput="capitalizeWords(this)"', $announcements);
        $this->assertStringContainsString('id="editAnnouncementTitle" class="form-control" oninput="capitalizeWords(this)"', $announcements);
        $this->assertStringContainsString('function capitalizeWords(input)', $announcements);
    }

    public function testTeacherClassesInputsHaveAutoCapitalization(): void
    {
        $teacherClasses = file_get_contents(__DIR__ . '/../teacher/teacher_Classes.php');
        $this->assertIsString($teacherClasses);
        $this->assertStringContainsString('Activity Title', $teacherClasses);
        $this->assertStringContainsString('name="title" class="form-control" placeholder="e.g. Quiz 1, Performance Task 2" oninput="capitalizeWords(this)"', $teacherClasses);
        $this->assertStringContainsString('function capitalizeWords(input)', $teacherClasses);
    }
}