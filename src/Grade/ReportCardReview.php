<?php

namespace BshsAms\Grade;

use Closure;
use DomainException;
use PDO;
use Throwable;

/** Principal endorsement decisions before final Admin release. */
final class ReportCardReview
{
    public function __construct(private PDO $db, private ?Closure $notify = null) {}

    public function review(int $actorId, array $ids, string $decision, string $remarks = ''): int
    {
        $actor = $this->db->prepare("SELECT role FROM users WHERE id = ? AND status = 'active'");
        $actor->execute([$actorId]);
        if ($actor->fetchColumn() !== 'principal') {
            throw new DomainException('Only an active Principal can endorse or return report cards.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        sort($ids);
        $remarks = trim($remarks);
        if (!$ids || count($ids) > 100 || !in_array($decision, ['approve', 'reject'], true)) {
            throw new DomainException('Select between 1 and 100 report cards and a valid decision.');
        }
        if (mb_strlen($remarks) > 255 || ($decision !== 'approve' && $remarks === '')) {
            throw new DomainException('Provide a correction reason of 1 to 255 characters.');
        }
        $expected = 'submitted_admin';
        $next = $decision === 'approve' ? 'pending' : 'rejected';
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $events = [];
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT * FROM report_card_approvals WHERE id = ?' . $lock);
            $update = $this->db->prepare('UPDATE report_card_approvals SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, remarks = ? WHERE id = ? AND status = ?');
            foreach ($ids as $id) {
                $select->execute([$id]);
                $card = $select->fetch(PDO::FETCH_ASSOC);
                if (!$card || $card['status'] !== $expected) {
                    throw new DomainException('A selected report card has changed or is not eligible. Refresh and review again.');
                }
                if ($decision === 'approve') {
                    $grades = $this->db->prepare("SELECT g.id, ga.status FROM grades g
                        JOIN class_subjects cs ON cs.id = g.class_subject_id
                        JOIN classes c ON c.id = cs.class_id
                        LEFT JOIN grade_approvals ga ON ga.grade_id = g.id
                        WHERE g.student_id = ? AND g.academic_year = ?
                        AND (? = '' OR g.semester = ?)
                        AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'" . $lock);
                    $grades->execute([$card['student_id'], $card['academic_year'], $card['semester'] ?? '', $card['semester'] ?? '']);
                    $rows = $grades->fetchAll(PDO::FETCH_ASSOC);
                    if (!$rows || array_filter($rows, fn($row) => $row['status'] !== 'admin_verified')) {
                        throw new DomainException('All subject grades must be verified by the Principal before endorsement. Return this report card for correction.');
                    }
                }
                $update->execute([$next, $actorId, $remarks !== '' ? $remarks : null, $id, $expected]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('The report card changed during review. Refresh and try again.');
                }
                $logged = \BshsAms\Audit\ActivityLogger::record($this->db, $actorId, 'principal', 'report_card.' . $decision, 'report_card', $id, [
                    'student_id' => (int)$card['student_id'], 'academic_year' => $card['academic_year'],
                    'semester' => $card['semester'], 'from' => $expected, 'to' => $next, 'remarks_provided' => $remarks !== '',
                ]);
                if (!$logged) {
                    throw new DomainException('The decision could not be audited, so no report card was changed.');
                }
                $card['event_id'] = (int)$this->db->lastInsertId();
                $events[] = $card;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        // Network delivery failures cannot roll back an official decision or audit entry.
        foreach ($events as $card) {
            try {
                if ($this->notify !== null) {
                    ($this->notify)($card, $decision, $remarks);
                } else {
                    $this->dispatch($card, $decision, $remarks);
                }
            } catch (Throwable $e) {
                error_log('Report card notification delivery failed for event ' . $card['event_id']);
            }
        }
        return count($events);
    }

    private function dispatch(array $card, string $decision, string $remarks): void
    {
        $endorsed = $decision === 'approve';
        $title = $endorsed ? 'Report Cards Endorsed to Admin' : 'Report Card Returned for Correction';
        $copy = $endorsed
            ? 'The Principal verified and endorsed the report card for final Admin release.'
            : 'The Principal returned the report card to the Adviser for correction.';
        $key = 'report_card_review_' . $card['event_id'];
        $staff = $this->db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $staff[] = (int)$card['advisory_teacher_id'];
        \appDispatchNotification($this->db, $staff, $key, $title, $copy . ($remarks !== '' ? ' ' . $remarks : ''),
            'bi-journal-check', $endorsed ? 'success' : 'warning',
            ['teacher' => 'teacher_Advisory.php', 'admin' => 'admin_Report_Cards.php'], ['type' => 'grade_workflow']);
    }
}
