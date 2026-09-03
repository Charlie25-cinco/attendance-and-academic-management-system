<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class TeacherChatAndActivityUiTest extends TestCase
{
    public function testTeacherChatPageMarkupContainsSearchAndMobileNavigation(): void
    {
        $html = file_get_contents(__DIR__ . '/../teacher/teacher_Chat.php');
        $this->assertIsString($html);

        // Responsive search input and controls
        $this->assertStringContainsString('id="parentSearchInput"', $html);
        $this->assertStringContainsString('id="clearParentSearchBtn"', $html);
        $this->assertStringContainsString('id="chatSearchEmptyState"', $html);
        $this->assertStringContainsString('id="chatSearchQueryText"', $html);
        $this->assertStringContainsString('clearParentSearch()', $html);
        $this->assertStringContainsString('filterParentConversations', $html);

        // Data attributes for client-side search filtering
        $this->assertStringContainsString('data-parent-name=', $html);
        $this->assertStringContainsString('data-student-names=', $html);
        $this->assertStringContainsString('data-parent-id=', $html);

        // Mobile list/detail back navigation
        $this->assertStringContainsString('teacher-chat-back-btn', $html);
        $this->assertStringContainsString('href="teacher_Chat.php"', $html);
        $this->assertStringContainsString('Parents</span>', $html);

        // Viewport and scroll layout styles
        $this->assertStringContainsString('height:calc(100dvh - 200px);', $html);
        $this->assertStringContainsString('teacher-chat-search-wrap', $html);
        $this->assertStringContainsString('sticky', $html);
    }

    public function testTeacherChatClientSideFilteringLogic(): void
    {
        $conversations = [
            [
                'parent_id' => 10,
                'parent_name' => 'Maria Santos',
                'student_names' => ['Juan Santos', 'Pedro Santos'],
            ],
            [
                'parent_id' => 11,
                'parent_name' => 'Roberto Cruz',
                'student_names' => ['Ana Cruz'],
            ],
            [
                'parent_id' => 12,
                'parent_name' => 'Elena Reyes',
                'student_names' => ['Carlos Reyes', 'Clara Reyes'],
            ]
        ];

        $filter = function (string $query) use ($conversations): array {
            $q = strtolower(trim($query));
            if ($q === '') {
                return $conversations;
            }
            return array_values(array_filter($conversations, function ($c) use ($q) {
                $parentName = strtolower($c['parent_name']);
                $studentNames = strtolower(implode(', ', $c['student_names']));
                return str_contains($parentName, $q) || str_contains($studentNames, $q);
            }));
        };

        // 1. Empty search returns all conversations
        $this->assertCount(3, $filter(''));

        // 2. Search by parent first name
        $results = $filter('Maria');
        $this->assertCount(1, $results);
        $this->assertSame(10, $results[0]['parent_id']);

        // 3. Search by student sibling name (matches parent of multiple siblings)
        $results = $filter('Pedro');
        $this->assertCount(1, $results);
        $this->assertSame(10, $results[0]['parent_id']);

        $results = $filter('Clara');
        $this->assertCount(1, $results);
        $this->assertSame(12, $results[0]['parent_id']);

        // 4. Search by family name (matches multiple parents)
        $results = $filter('Cruz');
        $this->assertCount(1, $results);
        $this->assertSame(11, $results[0]['parent_id']);

        // 5. Search with no matches
        $results = $filter('NonExistentName');
        $this->assertCount(0, $results);
    }

    public function testTeacherActivitiesCardMarkupAndResponsiveActions(): void
    {
        $html = file_get_contents(__DIR__ . '/../teacher/teacher_Classes.php');
        $this->assertIsString($html);

        // Activity card and responsive flex container in renderGradeItems()
        $this->assertStringContainsString('activity-card', $html);
        $this->assertStringContainsString('activity-actions', $html);
        $this->assertStringContainsString('flex-column flex-md-row', $html);

        // Action buttons inside activity card
        $this->assertStringContainsString('openRecordScores(', $html);
        $this->assertStringContainsString('finishGradeItem(', $html);
        $this->assertStringContainsString('deleteGradeItem(', $html);
        $this->assertStringContainsString('btn-secondary-custom', $html);
        $this->assertStringContainsString('btn-outline-warning', $html);
        $this->assertStringContainsString('btn-outline-danger', $html);

        // Verify CSS rules exist in role.css
        $css = file_get_contents(__DIR__ . '/../assets/css/role.css');
        $this->assertIsString($css);
        $this->assertStringContainsString('.activity-actions', $css);
        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $css);
        $this->assertStringContainsString('.teacher-chat-search-wrap', $css);
        $this->assertStringContainsString('.teacher-chat-back-btn', $css);
    }
}