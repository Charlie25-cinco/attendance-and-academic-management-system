<?php

namespace BshsAms\Schedule;

use DateTime;

class ScheduleParser
{
    const VALID_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public static function normalizeDay(string $day): string
    {
        $abbr = ucfirst(strtolower(substr(trim($day), 0, 3)));
        return in_array($abbr, self::VALID_DAYS, true) ? $abbr : '';
    }

    public static function normalizeDays(array $days): array
    {
        $normalized = [];
        foreach ($days as $day) {
            $d = self::normalizeDay($day);
            if ($d !== '') { $normalized[] = $d; }
        }
        return array_values(array_unique($normalized));
    }

    public static function toMinutes(string $timeStr): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', trim($timeStr), $m)) {
            $hour = (int)$m[1];
            $minute = (int)$m[2];
            $ampm = strtoupper($m[3]);
            if ($ampm === 'PM' && $hour !== 12) $hour += 12;
            elseif ($ampm === 'AM' && $hour === 12) $hour = 0;
            return $hour * 60 + $minute;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($timeStr), $m)) {
            return (int)$m[1] * 60 + (int)$m[2];
        }
        return 0;
    }

    public static function to24hString(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    public static function toTimeLabel(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        if ($h === 0) $h = 12;
        elseif ($h > 12) $h -= 12;
        return sprintf('%d:%02d %s', $h, $m, $ampm);
    }

    public static function fromParts(int $hour, int $minute, string $ampm): ?int
    {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 || !in_array(strtoupper($ampm), ['AM', 'PM'], true)) {
            return null;
        }
        $h = $hour; $ampm = strtoupper($ampm);
        if ($ampm === 'PM' && $h !== 12) $h += 12;
        elseif ($ampm === 'AM' && $h === 12) $h = 0;
        return $h * 60 + $minute;
    }

    public static function parseSegments(string $scheduleText): array
    {
        $scheduleText = trim($scheduleText);
        if ($scheduleText === '') return [];
        $segments = [];
        $parts = preg_split('/\s*;\s*/', $scheduleText);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (!preg_match('/^([\w,\s\/\-]+)\s+(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i', $part, $m)) {
                continue;
            }
            $daysText = preg_replace('/\s*\/\s*/', ',', trim($m[1]));
            $days = self::normalizeDays(explode(',', $daysText));
            if (empty($days)) continue;
            $startMin = self::toMinutes($m[2]);
            $endMin = self::toMinutes($m[3]);
            if ($endMin <= $startMin) continue;
            $startLabel = strtoupper(preg_replace('/\s+/', ' ', trim($m[2])));
            $endLabel = strtoupper(preg_replace('/\s+/', ' ', trim($m[3])));
            foreach ($days as $day) {
                $segments[] = [
                    'day' => $day, 'start' => $startMin, 'end' => $endMin,
                    'start_time' => self::to24hString($startMin), 'end_time' => self::to24hString($endMin),
                    'start_label' => $startLabel, 'end_label' => $endLabel,
                ];
            }
        }
        return $segments;
    }

    public static function hasDay(array $segments, string $dayAbbr): bool
    {
        foreach ($segments as $seg) {
            if (($seg['day'] ?? '') === $dayAbbr) return true;
        }
        return false;
    }

    public static function hasScheduleOnDate(string $scheduleText, string $date): bool
    {
        if ($scheduleText === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) return false;
        $segments = self::parseSegments($scheduleText);
        return self::hasDay($segments, $dateObj->format('D'));
    }

    public static function getDaysAndTime(string $schedule): array
    {
        $schedule = trim($schedule);
        if ($schedule === '') return ['days' => [], 'time' => ''];
        if (preg_match('/^([\w,\s\/\-]+)\s+(\d{1,2}:\d{2}\s*[AP]M\s*-\s*\d{1,2}:\d{2}\s*[AP]M)$/i', $schedule, $matches)) {
            $daysRaw = preg_replace('/\s*\/\s*/', ',', trim($matches[1]));
            $days = self::normalizeDays(explode(',', $daysRaw));
            return ['days' => $days, 'time' => preg_replace('/\s+/', ' ', trim($matches[2]))];
        }
        return ['days' => [], 'time' => $schedule];
    }

    public static function startMinutes(string $timeRange): int
    {
        if (!preg_match('/^(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*\d{1,2}:\d{2}\s*[AP]M$/i', trim($timeRange), $matches)) {
            return PHP_INT_MAX;
        }
        return self::toMinutes($matches[1]);
    }

    public static function hasConflict(array $segments1, array $segments2): bool
    {
        foreach ($segments1 as $a) {
            foreach ($segments2 as $b) {
                if (($a['day'] ?? '') !== ($b['day'] ?? '')) continue;
                if (($a['start'] ?? 0) < ($b['end'] ?? 0) && ($b['start'] ?? 0) < ($a['end'] ?? 0)) return true;
            }
        }
        return false;
    }

    public static function segmentSummary(array $segments): string
    {
        if (empty($segments)) return '';
        $dayOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $byTime = [];
        foreach ($segments as $seg) {
            $key = $seg['start_label'] . '-' . $seg['end_label'];
            if (!isset($byTime[$key])) {
                $byTime[$key] = ['time' => $seg['start_label'] . ' - ' . $seg['end_label'], 'days' => []];
            }
            if (!in_array($seg['day'], $byTime[$key]['days'], true)) {
                $byTime[$key]['days'][] = $seg['day'];
            }
        }
        $parts = [];
        foreach ($byTime as $entry) {
            $sortedDays = array_values(array_intersect($dayOrder, $entry['days']));
            $parts[] = implode('/', $sortedDays) . ' ' . $entry['time'];
        }
        return implode('; ', $parts);
    }
}
