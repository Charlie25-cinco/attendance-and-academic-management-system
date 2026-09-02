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
        $this->assertStringContainsString('id="schedModePerSection"', $html);
        $this->assertStringContainsString('id="schedModeUniform"', $html);
        $this->assertStringContainsString('id="schedModeTba"', $html);
        $this->assertStringContainsString('id="sectionSchedTabs"', $html);
        $this->assertStringContainsString('staggerSectionSchedules()', $html);
        $this->assertStringContainsString('copyFirstSectionScheduleToAll()', $html);
    }

    public function testAdminClassesCardButtonsAndScriptIntegrity(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($html);

        // Verify card buttons markup
        $this->assertStringContainsString('onclick="viewClass(', $html);
        $this->assertStringContainsString('onclick="editClass(', $html);
        $this->assertStringContainsString('onclick=\'deleteClass(', $html);

        // Verify JavaScript action functions exist
        $this->assertStringContainsString('function viewClass(id)', $html);
        $this->assertStringContainsString('function editClass(id)', $html);
        $this->assertStringContainsString('function deleteClass(id, className)', $html);
        $this->assertStringContainsString('function handleDelete(id)', $html);

        // Verify no duplicate const addClassModalEl declaration
        $matches = [];
        preg_match_all('/const\s+addClassModalEl\s*=/i', $html, $matches);
        $this->assertCount(1, $matches[0], 'addClassModalEl should only be declared once as const');
    }

    public function testAdminClassesActionSupportsMultiSectionArrayAndScheduleModes(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($code);

        $this->assertStringContainsString('isset($_POST[\'sections\'])', $code);
        $this->assertStringContainsString('$createdSections[] = $section;', $code);
        $this->assertStringContainsString('\'created_count\' => count($createdSections)', $code);
        $this->assertStringContainsString('\'created_sections\' => $createdSections', $code);
        $this->assertStringContainsString('$_POST[\'schedule_mode\']', $code);
        $this->assertStringContainsString('$_POST[\'section_schedules\']', $code);
        $this->assertStringContainsString('$scheduleMode === \'per_section\'', $code);
        $this->assertStringContainsString('$scheduleMode === \'tba\'', $code);
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
        $this->assertStringContainsString('id="editSchedModeSpecific"', $html);
        $this->assertStringContainsString('id="editSchedModeTba"', $html);
    }

    public function testAdminClassesActionUpdateSupportsApplyToSections(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($code);

        $this->assertStringContainsString('$_POST[\'apply_to_sections\']', $code);
        $this->assertStringContainsString('$appliedSections[] = $extraSection;', $code);
        $this->assertStringContainsString('and applied to', $code);
        $this->assertStringContainsString('$scheduleMode === \'tba\'', $code);
    }
}
