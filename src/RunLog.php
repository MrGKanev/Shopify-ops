<?php
declare(strict_types=1);

/**
 * Append-only operational run history for audits and scan pages.
 */
class RunLog
{
    use JsonFileLock;

    private const int MAX_ENTRIES = 500;
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/run_log.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/run_log.json');
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function append(array $entry): void
    {
        $entry += [
            'id'          => bin2hex(random_bytes(6)),
            'created_at'  => date('Y-m-d H:i:s'),
            'status'      => 'ok',
            'tool'        => 'unknown',
            'duration'    => null,
            'start_date'  => '',
            'end_date'    => '',
            'scanned'     => null,
            'rows_found'  => null,
            'error'       => '',
            'meta'        => [],
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
