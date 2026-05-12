<?php
/**
 * Append-only log for orders pushed from Shopify to ShipStation.
 */
class PushLog
{
    private const string FILE = __DIR__ . '/../data/push_log.json';

    /**
     * Append a single entry to the push log.
     *
     * @param array<string, mixed> $entry
     */
    public static function append(array $entry): void
    {
        $file = self::FILE;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $log = $raw ? (json_decode($raw, true) ?: []) : [];
        $log[] = $entry;
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($log, JSON_PRETTY_PRINT));
        flock($fh, LOCK_UN); fclose($fh);
    }

    /**
     * Returns all log entries, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $file = self::FILE;
        if (!file_exists($file)) {
            return [];
        }
        return array_reverse(json_decode(file_get_contents($file), true) ?: []);
    }
}
