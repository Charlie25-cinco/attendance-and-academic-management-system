<?php

namespace BshsAms\Storage;

use InvalidArgumentException;
use RuntimeException;

final class MaterialStorage
{
    public static function directory(bool $create = false): string
    {
        $configured = trim((string)appEnvValue('MATERIAL_STORAGE_PATH', ''));
        $directory = $configured !== ''
            ? rtrim($configured, '/\\')
            : APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'materials';

        if ($create) {
            self::ensureDirectory($directory);
        }
        return $directory;
    }

    public static function createStoredName(string $extension): string
    {
        $extension = strtolower(trim($extension));
        if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            throw new InvalidArgumentException('Invalid material file extension.');
        }
        return 'material_' . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    public static function pathFor(string $storedName, bool $createDirectory = false): string
    {
        self::assertSafeStoredName($storedName);
        return self::directory($createDirectory) . DIRECTORY_SEPARATOR . $storedName;
    }

    public static function locate(string $storedName): ?string
    {
        self::assertSafeStoredName($storedName);
        foreach (self::candidateDirectories() as $directory) {
            $path = $directory . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public static function delete(string $storedName): bool
    {
        $path = self::locate($storedName);
        return $path === null || @unlink($path);
    }

    public static function contentType(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain; charset=UTF-8',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    public static function outputDownload(string $path, string $title, string $extension): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Material file is unavailable.');
        }

        $extension = strtolower(preg_replace('/[^a-z0-9]+/', '', $extension) ?? '');
        $safeTitle = trim(preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', ' ', $title) ?? '');
        if ($safeTitle === '') {
            $safeTitle = 'material';
        }
        $utf8Name = $safeTitle . ($extension !== '' ? '.' . $extension : '');
        $asciiTitle = trim(preg_replace('/[^A-Za-z0-9 _.-]+/', '', $safeTitle) ?? '');
        $asciiName = ($asciiTitle !== '' ? $asciiTitle : 'material') . ($extension !== '' ? '.' . $extension : '');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Description: File Transfer');
        header('Content-Type: ' . self::contentType($extension));
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($utf8Name));
        header('Content-Length: ' . (string)filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit();
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create material storage directory.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Material storage directory is not writable.');
        }

        $guards = [
            '.htaccess' => "Require all denied\n",
            'index.php' => "<?php\nhttp_response_code(404);\necho 'Not Found';\n",
        ];
        foreach ($guards as $name => $contents) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) && file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Unable to secure material storage directory.');
            }
        }
    }

    private static function candidateDirectories(): array
    {
        return array_values(array_unique([
            self::directory(false),
            APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'materials',
            APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'Materials',
            APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'Materials' . DIRECTORY_SEPARATOR . 'Materials',
            dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'Uploads' . DIRECTORY_SEPARATOR . 'Materials',
            dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'materials',
        ]));
    }

    private static function assertSafeStoredName(string $storedName): void
    {
        if ($storedName === '' || basename($storedName) !== $storedName || !preg_match('/^[A-Za-z0-9._-]+$/', $storedName)) {
            throw new InvalidArgumentException('Invalid stored material filename.');
        }
    }
}
