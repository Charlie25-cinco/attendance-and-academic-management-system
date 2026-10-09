<?php

namespace BshsAms\Audit;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class OwnActivityLogQuery
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly PDO $db)
    {
    }

    public function search(int $actorUserId, string $actorRole, array $filters = []): array
    {
        $actorRole = strtolower(trim($actorRole));
        if ($actorUserId <= 0 || !in_array($actorRole, ['teacher', 'student', 'parent'], true)) {
            throw new InvalidArgumentException('A valid activity log owner is required.');
        }

        $search = mb_substr(trim((string)($filters['search'] ?? '')), 0, 100);
        $dateFrom = $this->validDate((string)($filters['date_from'] ?? ''));
        $dateTo = $this->validDate((string)($filters['date_to'] ?? ''));
        $requestedPage = max(1, (int)($filters['page'] ?? 1));

        $where = [
            'actor_user_id = :actor_user_id',
            'actor_role = :actor_role',
        ];
        $params = [
            ':actor_user_id' => $actorUserId,
            ':actor_role' => $actorRole,
        ];

        if ($search !== '') {
            $where[] = '(action_name LIKE :search_action OR target_type LIKE :search_target)';
            $params[':search_action'] = '%' . $search . '%';
            $params[':search_target'] = '%' . $search . '%';
        }
        if ($dateFrom !== '') {
            $where[] = 'created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*) FROM activity_logs WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $totalPages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page = min($requestedPage, $totalPages);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $query = $this->db->prepare(
            'SELECT action_name, target_type, target_id, details_json, created_at
             FROM activity_logs
             WHERE ' . $whereSql . '
             ORDER BY created_at DESC, id DESC
             LIMIT :page_size OFFSET :page_offset'
        );
        foreach ($params as $key => $value) {
            $query->bindValue($key, $value, $key === ':actor_user_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->bindValue(':page_size', self::PAGE_SIZE, PDO::PARAM_INT);
        $query->bindValue(':page_offset', $offset, PDO::PARAM_INT);
        $query->execute();

        return [
            'rows' => $query->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ];
    }

    public static function formatDetails(?string $json): string
    {
        $details = json_decode((string)$json, true);
        if (!is_array($details)) {
            return 'No additional details';
        }

        $parts = [];
        self::flattenSafeDetails($details, $parts);

        return $parts === [] ? 'No additional details' : implode(' | ', $parts);
    }

    private function validDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
    }

    private static function flattenSafeDetails(array $details, array &$parts, string $prefix = ''): void
    {
        foreach ($details as $key => $value) {
            $normalized = strtolower((string)$key);
            $exactSensitiveKeys = ['ip', 'ip_address', 'client_ip', 'remote_ip'];
            $containsSensitiveTerm = preg_match(
                '/(?:password|token|secret|authorization|user_agent|phone|contact|email|message|address|reference_code|lrn)/',
                $normalized
            );
            $isSensitiveContent = preg_match('/(?:^|_)(?:name|title|content)(?:_|$)/', $normalized);
            if (in_array($normalized, $exactSensitiveKeys, true) || $containsSensitiveTerm || $isSensitiveContent) {
                continue;
            }

            $label = trim($prefix . ' ' . str_replace('_', ' ', (string)$key));
            if (is_array($value)) {
                self::flattenSafeDetails($value, $parts, $label);
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            if (is_bool($value)) {
                $display = $value ? 'yes' : 'no';
            } elseif ($value === null) {
                $display = 'none';
            } else {
                $display = mb_substr((string)$value, 0, 200);
            }
            $parts[] = ucwords($label) . ': ' . $display;
        }
    }
}
