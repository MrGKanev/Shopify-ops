<?php
declare(strict_types=1);

/**
 * Append-only audit log for operator actions in the dashboard.
 */
class UserActionLog
{
    use JsonFileLock;

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
    public static function append(
        string $action,
        array  $details   = [],
        string $ip        = '',
        string $userAgent = '',
    ): void {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        $entry = [
            'id'         => bin2hex(random_bytes(6)),
            'at'         => date('Y-m-d H:i:s'),
            'action'     => $action,
            'ip'         => $ip        ?: ($_SERVER['REMOTE_ADDR']     ?? 'cli'),
            'user_agent' => substr($userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 180),
            'details'    => $details,
        ];

        self::writeJson(self::file(), function (array $log) use ($entry): array {
            $log[] = $entry;
            return count($log) > self::MAX_ENTRIES ? array_slice($log, -self::MAX_ENTRIES) : $log;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_reverse(self::readJson(self::file()));
    }
}
