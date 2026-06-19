<?php
declare(strict_types=1);

/**
 * Append-only audit log for operator actions in the dashboard.
 */
class UserActionLog
{
    private const int MAX_ENTRIES = 1000;
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/user_action_log.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/user_action_log.json');
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function append(string $action, array $details = []): void
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        $entry = [
            'id'         => bin2hex(random_bytes(6)),
            'at'         => date('Y-m-d H:i:s'),
            'action'     => $action,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 180),
            'details'    => $details,
        ];

        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $log = $raw ? (json_decode($raw, true) ?: []) : [];
        $log[] = $entry;
        if (count($log) > self::MAX_ENTRIES) {
            $log = array_slice($log, -self::MAX_ENTRIES);
        }
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($log, JSON_PRETTY_PRINT));
        flock($fh, LOCK_UN); fclose($fh);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $file = self::file();
        if (!file_exists($file)) {
            return [];
        }
        return array_reverse(json_decode(file_get_contents($file), true) ?: []);
    }
}
