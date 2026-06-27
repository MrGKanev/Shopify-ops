<?php
declare(strict_types=1);

/**
 * Append-only log for orders pushed from Shopify to ShipStation.
 */
class PushLog
{
    use JsonFileLock;

    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/push_log.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/push_log.json');
    }

    /**
     * Append a single entry to the push log.
     *
     * @param array<string, mixed> $entry
     */
    public static function append(array $entry): void
    {
        self::writeJson(self::file(), function (array $log) use ($entry): array {
            $log[] = $entry;
            return $log;
        });
    }

    /**
     * Returns all log entries, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_reverse(self::readJson(self::file()));
    }
}
