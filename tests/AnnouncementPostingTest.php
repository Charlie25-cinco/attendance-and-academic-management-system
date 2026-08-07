<?php

use PHPUnit\Framework\TestCase;

final class AnnouncementPostingTest extends TestCase
{
    public function testTeacherAnnouncementSubmissionIncludesCsrfToken(): void
    {
        $content = file_get_contents(__DIR__ . '/../teacher/teacher_Classes.php');
        $this->assertIsString($content);

        preg_match('/function createClassAnnouncement\(\)\{(.+?)function loadClassAnnouncements/s', $content, $matches);

        $this->assertNotEmpty($matches[1] ?? '');
        $this->assertStringContainsString('appendCsrfToFormData(fd)', $matches[1]);
        $this->assertStringContainsString('action=create_class_announcement', $matches[1]);
    }

    public function testAdminAnnouncementPostingSupportsLegacyWebsiteSchema(): void
    {
        $content = file_get_contents(__DIR__ . '/../admin/admin_Announcements_Action.php');
        $this->assertIsString($content);

        $this->assertStringContainsString("dbHasColumn(\$db, 'announcements', 'show_on_website')", $content);
        $this->assertStringContainsString('ALTER TABLE announcements ADD COLUMN show_on_website', $content);
    }

    public function testAnnouncementPushFailuresDoNotInvalidateSavedPosts(): void
    {
        $adminContent = file_get_contents(__DIR__ . '/../admin/admin_Announcements_Action.php');
        $teacherContent = file_get_contents(__DIR__ . '/../teacher/teacher_Action.php');
        $this->assertIsString($adminContent);
        $this->assertIsString($teacherContent);

        $this->assertStringContainsString('catch (Throwable $notificationError)', $adminContent);
        $this->assertStringContainsString('Announcement saved, but push delivery failed:', $adminContent);
        $this->assertStringContainsString('catch (Throwable $notificationError)', $teacherContent);
        $this->assertStringContainsString('Class announcement saved, but push delivery failed:', $teacherContent);
    }
}
