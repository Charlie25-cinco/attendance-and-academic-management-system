<?php

class SchemaCache {
    private static ?array $columns = null;
    private static ?array $tables = null;
    private static int $ttl = 3600;
    private static ?int $loadedAt = null;

    public static function getColumns(PDO $db, string $table): array {
        self::ensureInitialized();
        if (!isset(self::$columns[$table])) {
            $stmt = $db->prepare("SHOW COLUMNS FROM {$table}");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            self::$columns[$table] = array_column($columns, 'Field');
        }
        return self::$columns[$table];
    }

    public static function hasColumn(PDO $db, string $table, string $column): bool {
        return in_array($column, self::getColumns($db, $table), true);
    }

    public static function getTables(PDO $db): array {
        self::ensureInitialized();
        if (self::$tables === null) {
            $stmt = $db->query("SHOW TABLES");
            self::$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return self::$tables;
    }

    public static function hasTable(PDO $db, string $table): bool {
        return in_array($table, self::getTables($db), true);
    }

    public static function clearCache(): void {
        self::$columns = null;
        self::$tables = null;
        self::$loadedAt = null;
    }

    private static function ensureInitialized(): void {
        if (self::$loadedAt === null || (time() - self::$loadedAt) > self::$ttl) {
            self::$columns = [];
            self::$tables = null;
            self::$loadedAt = time();
        }
    }

    public static function setTtl(int $seconds): void { self::$ttl = $seconds; }
}

class ScheduleParser {
    const VALID_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public static function normalizeDay(string $day): string {
        $abbr = ucfirst(strtolower(substr(trim($day), 0, 3)));
        return in_array($abbr, self::VALID_DAYS, true) ? $abbr : '';
    }

    public static function normalizeDays(array $days): array {
        $normalized = [];
        foreach ($days as $day) {
            $d = self::normalizeDay($day);
            if ($d !== '') { $normalized[] = $d; }
        }
        return array_values(array_unique($normalized));
    }

    public static function toMinutes(string $timeStr): int {
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

    public static function to24hString(int $minutes): string {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    public static function toTimeLabel(int $minutes): string {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        if ($h === 0) $h = 12;
        elseif ($h > 12) $h -= 12;
        return sprintf('%d:%02d %s', $h, $m, $ampm);
    }

    public static function fromParts(int $hour, int $minute, string $ampm): ?int {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 || !in_array(strtoupper($ampm), ['AM', 'PM'], true)) {
            return null;
        }
        $h = $hour; $ampm = strtoupper($ampm);
        if ($ampm === 'PM' && $h !== 12) $h += 12;
        elseif ($ampm === 'AM' && $h === 12) $h = 0;
        return $h * 60 + $minute;
    }

    public static function parseSegments(string $scheduleText): array {
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

    public static function hasDay(array $segments, string $dayAbbr): bool {
        foreach ($segments as $seg) {
            if (($seg['day'] ?? '') === $dayAbbr) return true;
        }
        return false;
    }

    public static function hasScheduleOnDate(string $scheduleText, string $date): bool {
        if ($scheduleText === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) return false;
        $segments = self::parseSegments($scheduleText);
        return self::hasDay($segments, $dateObj->format('D'));
    }

    public static function getDaysAndTime(string $schedule): array {
        $schedule = trim($schedule);
        if ($schedule === '') return ['days' => [], 'time' => ''];
        if (preg_match('/^([\w,\s\/\-]+)\s+(\d{1,2}:\d{2}\s*[AP]M\s*-\s*\d{1,2}:\d{2}\s*[AP]M)$/i', $schedule, $matches)) {
            $daysRaw = preg_replace('/\s*\/\s*/', ',', trim($matches[1]));
            $days = self::normalizeDays(explode(',', $daysRaw));
            return ['days' => $days, 'time' => preg_replace('/\s+/', ' ', trim($matches[2]))];
        }
        return ['days' => [], 'time' => $schedule];
    }

    public static function startMinutes(string $timeRange): int {
        if (!preg_match('/^(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*\d{1,2}:\d{2}\s*[AP]M$/i', trim($timeRange), $matches)) {
            return PHP_INT_MAX;
        }
        return self::toMinutes($matches[1]);
    }

    public static function hasConflict(array $segments1, array $segments2): bool {
        foreach ($segments1 as $a) {
            foreach ($segments2 as $b) {
                if (($a['day'] ?? '') !== ($b['day'] ?? '')) continue;
                if (($a['start'] ?? 0) < ($b['end'] ?? 0) && ($b['start'] ?? 0) < ($a['end'] ?? 0)) return true;
            }
        }
        return false;
    }

    public static function segmentSummary(array $segments): string {
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

class SimpleXlsxParser {
    private $zip;
    private $sheets = [];
    private $sharedStrings = [];
    private $filePath;
    private $useZipArchive = false;
    private $zipResource = null;

    public function __construct(string $filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        $this->filePath = $filePath;
        $this->sharedStrings = [];

        if (class_exists('ZipArchive')) {
            $this->zip = new ZipArchive();
            if ($this->zip->open($filePath) === true) {
                $this->useZipArchive = true;
                $this->loadSharedStrings();
                return;
            }
            @$this->zip->close();
            $this->zip = null;
        }

        $this->useZipArchive = false;
        $this->tryProceduralZip();
    }

    private function tryProceduralZip(): void {
        if (!function_exists('zip_open')) { return; }
        $this->zipResource = zip_open($this->filePath);
        if (!is_resource($this->zipResource)) { $this->zipResource = null; return; }
        while ($entry = zip_read($this->zipResource)) {
            $name = zip_entry_name($entry);
            if ($name === 'xl/sharedStrings.xml' || $name === 'xl\\sharedStrings.xml') {
                if (zip_entry_open($this->zipResource, $entry, 'r')) {
                    $content = zip_entry_read($entry, zip_entry_filesize($entry));
                    zip_entry_close($entry);
                    $this->parseSharedStrings($content);
                    break;
                }
            }
        }
    }

    private function parseSharedStrings(string $content): void {
        if ($content === '') return;
        $xml = @simplexml_load_string($content);
        if ($xml === false) return;
        foreach ($xml->si as $item) {
            $text = '';
            if (isset($item->t)) { $text = (string)$item->t; }
            else {
                foreach ($item->r as $r) {
                    if (isset($r->t)) { $text .= (string)$r->t; }
                }
            }
            $this->sharedStrings[] = $text;
        }
    }

    private function loadSharedStrings(): void {
        if ($this->useZipArchive) {
            $content = $this->zip->getFromName('xl/sharedStrings.xml');
            if ($content !== false) { $this->parseSharedStrings($content); }
        }
    }

    public function getSheet(int $index = 0): array {
        $workbookXml = $this->getFileContent('xl/workbook.xml');
        if ($workbookXml === false || $workbookXml === '') {
            throw new Exception("Invalid xlsx: missing workbook.xml");
        }
        $workbook = @simplexml_load_string($workbookXml);
        if ($workbook === false) { throw new Exception("Invalid xlsx: cannot parse workbook.xml"); }
        $sheets = $workbook->sheets->sheet;
        if (!isset($sheets[$index])) { throw new Exception("Sheet index $index not found"); }
        $sheetFile = 'xl/worksheets/sheet' . ($index + 1) . '.xml';
        return $this->parseSheet($sheetFile);
    }

    public function getSheetNames(): array {
        $workbookXml = $this->getFileContent('xl/workbook.xml');
        if ($workbookXml === false || $workbookXml === '') { return []; }
        $workbook = @simplexml_load_string($workbookXml);
        if ($workbook === false) { return []; }
        $names = [];
        foreach ($workbook->sheets->sheet as $sheet) {
            $names[] = (string)$sheet['name'];
        }
        return $names;
    }

    private function getFileContent(string $path): string {
        if ($this->useZipArchive) {
            $content = $this->zip->getFromName($path);
            if ($content === false) { $content = $this->zip->getFromName(str_replace('/', '\\', $path)); }
            return $content === false ? '' : $content;
        }
        if (is_resource($this->zipResource)) {
            zip_close($this->zipResource);
            $this->zipResource = zip_open($this->filePath);
        }
        if (!is_resource($this->zipResource)) { return ''; }
        $normalizedPath = str_replace('\\', '/', $path);
        while (($entry = zip_read($this->zipResource)) !== false) {
            $name = str_replace('\\', '/', zip_entry_name($entry));
            if ($name === $normalizedPath) {
                if (zip_entry_open($this->zipResource, $entry, 'r')) {
                    $content = zip_entry_read($entry, zip_entry_filesize($entry));
                    zip_entry_close($entry);
                    return $content;
                }
            }
        }
        return '';
    }

    private function parseSheet(string $sheetFile): array {
        $content = $this->getFileContent($sheetFile);
        if ($content === '') {
            $altFile = str_replace('/', '\\', $sheetFile);
            $content = $this->getFileContent($altFile);
            if ($content === '') { throw new Exception("Sheet not found: $sheetFile"); }
        }
        $xml = @simplexml_load_string($content);
        if ($xml === false || $xml->sheetData === null) { throw new Exception("Invalid sheet XML structure"); }
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowIndex = (int)$row['r'];
            if ($rowIndex < 1) continue;
            $cells = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                $colLetter = preg_replace('/[0-9]/', '', $ref);
                $colIndex = $this->columnLetterToIndex($colLetter);
                $type = (string)$cell['t'];
                $value = '';
                if (isset($cell->v)) { $value = (string)$cell->v; }
                if ($type === 's' && is_numeric($value)) {
                    $value = $this->sharedStrings[(int)$value] ?? '';
                }
                $cells[$colIndex] = $value;
            }
            if (!empty($cells)) {
                ksort($cells);
                $rows[$rowIndex] = $cells;
            }
        }
        ksort($rows);
        return $rows;
    }

    private function columnLetterToIndex(string $letter): int {
        $index = 0;
        $length = strlen($letter);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    public function __destruct() {
        if ($this->useZipArchive && $this->zip) { @$this->zip->close(); }
        if (is_resource($this->zipResource)) { @zip_close($this->zipResource); }
    }
}
