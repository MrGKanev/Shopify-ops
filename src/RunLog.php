<?php
declare(strict_types=1);

/**
 * Append-only operational run history for audits and scan pages.
 */
class RunLog
{
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
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

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
