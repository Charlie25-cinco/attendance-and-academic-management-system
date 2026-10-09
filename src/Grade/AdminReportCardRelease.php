<?php

namespace BshsAms\Grade;

use Closure;
use DomainException;
use PDO;
use Throwable;

final class AdminReportCardRelease
{
    public function __construct(private PDO $db, private ?Closure $notify = null) {}

    public function decide(int $actorId, array $ids, string $decision, string $remarks = ''): int
    {
        $actor = $this->db->prepare("SELECT role FROM users WHERE id = ? AND status = 'active'");
        $actor->execute([$actorId]);
        if ($actor->fetchColumn() !== 'admin') {
            throw new DomainException('Only an active Admin can release report cards.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        $remarks = trim($remarks);
        if (!$ids || count($ids) > 100 || !in_array($decision, ['approve', 'reject', 'withdraw'], true)) {
            throw new DomainException('Select between 1 and 100 report cards and a valid decision.');
        }
        if ($decision !== 'approve' && $remarks === '') {
            throw new DomainException('A return or withdrawal reason is required.');
        }
        $expected = $decision === 'withdraw' ? 'approved' : 'pending';
        $next = $decision === 'approve' ? 'approved' : 'rejected';
        $events = [];
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT * FROM report_card_approvals WHERE id = ?');
            $update = $this->db->prepare('UPDATE report_card_approvals SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, remarks = ? WHERE id = ? AND status = ?');
            foreach ($ids as $id) {
                $select->execute([$id]);
                $card = $select->fetch(PDO::FETCH_ASSOC);
                if (!$card || $card['status'] !== $expected) {
                    throw new DomainException('A selected report card is no longer eligible. Refresh and try again.');
                }
                $update->execute([$next, $actorId, $remarks !== '' ? $remarks : null, $id, $expected]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('A report card changed during review.');
                }
                $logged = \BshsAms\Audit\ActivityLogger::record($this->db, $actorId, 'admin', 'report_card.admin_' . $decision, 'report_card', $id, [
                    'student_id' => (int)$card['student_id'], 'from' => $expected, 'to' => $next,
                    'remarks_provided' => $remarks !== '',
                ]);
                if (!$logged) {
                    throw new DomainException('The decision could not be audited, so no report card was changed.');
                }
                $deliveries = [];
                if ($this->notify === null) {
                    $deliveries = $this->prepareDeliveries($card, $decision, $remarks);
                    \appPersistNotificationDeliveries($this->db, $deliveries);
                }
                $events[] = ['card' => $card, 'deliveries' => $deliveries];
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }

        foreach ($events as $event) {
            $card = $event['card'];
            try {
                if ($this->notify !== null) {
                    ($this->notify)($card, $decision, $remarks);
                } else {
                    \appPushNotificationDeliveries($this->db, $event['deliveries']);
                }
            } catch (Throwable $e) {
                error_log('Admin report-card notification delivery failed.');
            }
        }
        return count($events);
    }

    private function prepareDeliveries(array $card, string $decision, string $remarks): array
    {
        $released = $decision === 'approve';
        $title = $released ? 'Official Report Card Released' : ($decision === 'withdraw' ? 'Report Card Release Withdrawn' : 'Report Card Returned by Admin');
        $copy = $released ? 'Admin approved and released the official report card.' : ($remarks !== '' ? $remarks : 'The report card requires correction.');
        $parents = $this->db->prepare('SELECT parent_id FROM parent_students WHERE student_id = ?');
        $parents->execute([$card['student_id']]);
        $family = array_merge([(int)$card['student_id']], $parents->fetchAll(PDO::FETCH_COLUMN));
        $key = 'admin_report_card_' . $decision . '_' . $card['id'] . '_' . time();
        $deliveries = \appPrepareNotificationDeliveries($this->db, $family, $key, $title, $copy,
            'bi-journal-check', $released ? 'success' : 'warning',
            ['student' => 'Student_Report_Card.php', 'parent' => 'Parent_Report_Card.php'],
            ['type' => $released ? 'grade_publication' : 'grade_recall']);

        $staff = [(int)$card['advisory_teacher_id']];
        $principalIds = $this->db->query("SELECT id FROM users WHERE role = 'principal' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $teacherStmt = $this->db->prepare("SELECT DISTINCT g.recorded_by FROM grades g WHERE g.student_id = ? AND g.academic_year = ? AND (? = '' OR g.semester = ?) AND g.recorded_by IS NOT NULL");
        $semester = (string)($card['semester'] ?? '');
        $teacherStmt->execute([$card['student_id'], $card['academic_year'], $semester, $semester]);
        $staff = array_merge($staff, $principalIds, $teacherStmt->fetchAll(PDO::FETCH_COLUMN));
        return array_merge($deliveries, \appPrepareNotificationDeliveries($this->db, $staff, $key . '_staff', $title, $copy,
            'bi-journal-check', $released ? 'success' : 'warning',
            ['principal' => 'principal_Released.php', 'teacher' => 'teacher_Advisory.php'],
            ['type' => $released ? 'grade_publication' : 'grade_recall']));
    }
}
