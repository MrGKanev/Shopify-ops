<?php
/**
 * Manages the data/ignored.json file.
 * All write operations use exclusive file locking.
 */
class IgnoreList
{
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/ignored.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/ignored.json');
    }

    // ── Read ──────────────────────────────────────────────────────────

    /**
     * Returns the full ignored-orders array keyed by normalised order number.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function load(): array
    {
        $file = self::file();
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?: [];
    }

    // ── Write ─────────────────────────────────────────────────────────

    /**
     * Add a single entry.
     */
    public static function add(string $normNum, string $reason, string $orderName = ''): void
    {
        if (!$normNum) return;
        self::write(function (array $data) use ($normNum, $reason): array {
            $data[$normNum] = ['reason' => $reason, 'ignored_at' => date('Y-m-d')];
            return $data;
        });
    }

    /**
     * Remove a single entry.
     */
    public static function remove(string $normNum): void
    {
        if (!$normNum) return;
        self::write(function (array $data) use ($normNum): array {
            unset($data[$normNum]);
            return $data;
        });
    }

    /**
     * Add multiple entries in one locked write.
     * Each entry must have keys 'number' and 'reason'.
     *
     * @param array<int, array{number: string, reason: string}> $entries
     */
    public static function bulkAdd(array $entries): void
    {
        self::write(function (array $data) use ($entries): array {
            foreach ($entries as $e) {
                $norm = $e['number'] ?? '';
                if ($norm) {
                    $data[$norm] = ['reason' => $e['reason'] ?? '', 'ignored_at' => date('Y-m-d')];
                }
            }
            return $data;
        });
    }

    /**
     * Remove multiple entries in one locked write.
     *
     * @param string[] $normNums
     */
    public static function bulkRemove(array $normNums): void
    {
        self::write(function (array $data) use ($normNums): array {
            foreach ($normNums as $n) {
                unset($data[$n]);
            }
            return $data;
        });
    }

    /**
     * Import order numbers from a CSV file (first column).
     * Returns the number of entries added.
     */
    public static function importCsv(string $tmpPath, string $reason): int
    {
        $count   = 0;
        $entries = [];

        if (($fh = fopen($tmpPath, 'r')) !== false) {
            $first = fgetcsv($fh);
            if ($first) {
                $firstCell = ltrim(trim((string) ($first[0] ?? '')), '#');
                if (preg_match('/^\d+$/', $firstCell)) {
                    $entries[] = ['number' => $firstCell, 'reason' => $reason];
                    $count++;
                }
            }
            while (($row = fgetcsv($fh)) !== false) {
                $cell = ltrim(trim((string) ($row[0] ?? '')), '#');
                if ($cell) {
                    $entries[] = ['number' => $cell, 'reason' => $reason];
                    $count++;
                }
            }
            fclose($fh);
        }

        if ($entries) {
            self::bulkAdd($entries);
        }

        return $count;
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Read-modify-write with exclusive locking.
     * $mutator receives the current array and must return the updated array.
     */
    private static function write(callable $mutator): void
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $raw  = stream_get_contents($fh);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
        $data = $mutator($data);
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT));
        flock($fh, LOCK_UN); fclose($fh);
    }
}
