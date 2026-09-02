<?php

use PHPUnit\Framework\TestCase;

final class AdminClassMultiSectionTest extends TestCase
{
    public function testAdminClassesPageContainsMultiSectionAndDatalistMarkup(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($html);

        $this->assertStringContainsString('id="sectionCheckboxesContainer"', $html);
        $this->assertStringContainsString('id="selectAllSectionsBtn"', $html);
        $this->assertStringContainsString('toggleAllSections()', $html);
        $this->assertStringContainsString('id="registeredSubjectsList"', $html);
        $this->assertStringContainsString('list="registeredSubjectsList"', $html);
        $this->assertStringContainsString('name="sections[]"', $html);
    }

    public function testAdminClassesActionSupportsMultiSectionArray(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($code);

        $this->assertStringContainsString('isset($_POST[\'sections\'])', $code);
        $this->assertStringContainsString('$createdSections[] = $section;', $code);
        $this->assertStringContainsString('\'created_count\' => count($createdSections)', $code);
        $this->assertStringContainsString('\'created_sections\' => $createdSections', $code);
    }

    public function testAdminClassEditContainsDatalistAndMultiSectionApply(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Class_Edit.php');
        $this->assertIsString($html);

        $this->assertStringContainsString('id="editSectionCheckboxesContainer"', $html);
        $this->assertStringContainsString('id="selectAllEditSectionsBtn"', $html);
        $this->assertStringContainsString('toggleAllEditSections()', $html);
        $this->assertStringContainsString('id="registeredSubjectsList"', $html);
        $this->assertStringContainsString('list="registeredSubjectsList"', $html);
        $this->assertStringContainsString('name="apply_to_sections[]"', $html);
    }

    public function testAdminClassesActionUpdateSupportsApplyToSections(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($code);

        $this->assertStringContainsString('$_POST[\'apply_to_sections\']', $code);
        $this->assertStringContainsString('$appliedSections[] = $extraSection;', $code);
        $this->assertStringContainsString('and applied to', $code);
    }
}
