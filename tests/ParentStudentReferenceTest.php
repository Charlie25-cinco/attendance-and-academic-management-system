<?php

use PHPUnit\Framework\TestCase;

final class ParentStudentReferenceTest extends TestCase
{
    public function testParentAnnouncementsRendersStudentReferenceCode(): void
    {
        $content = file_get_contents(__DIR__ . '/../parent/Parent_Announcements.php');
        $this->assertIsString($content);

        // Hero chip with Student Ref
        $this->assertStringContainsString('Student Ref: <?php echo htmlspecialchars($selectedStudentRef); ?>', $content);

        // Select Student header badge
        $this->assertStringContainsString('Ref: <?php echo htmlspecialchars($selectedStudentRef); ?>', $content);

        // Student button badge
        $this->assertStringContainsString('$childRef', $content);
        $this->assertStringContainsString('<?php echo htmlspecialchars($childRef !== \'\' ? $childRef : \'ID #\' . $child[\'id\']); ?>', $content);

        // Latest Updates header subtitle
        $this->assertStringContainsString('Showing notices for <strong><?php echo htmlspecialchars($selectedStudentName); ?></strong>', $content);
    }
}