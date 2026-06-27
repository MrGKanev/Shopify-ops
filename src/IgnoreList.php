<?php
declare(strict_types=1);

use League\Csv\Reader;

/**
 * Manages the data/ignored.json file.
 * All write operations use exclusive file locking.
 */
class IgnoreList
{
    use JsonFileLock;

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
        return self::readJson(self::file());
    }

    // ── Write ─────────────────────────────────────────────────────────

    /**
     * Add a single entry.
     */
    public static function add(string $normNum, string $reason, string $orderName = ''): void
    {
        if (!$normNum) return;
        self::writeJson(self::file(), function (array $data) use ($normNum, $reason): array {
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
        self::writeJson(self::file(), function (array $data) use ($normNum): array {
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
        self::writeJson(self::file(), function (array $data) use ($entries): array {
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
        self::writeJson(self::file(), function (array $data) use ($normNums): array {
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

        $csv  = Reader::from($tmpPath, 'r');
        $all  = [...$csv];

        if (!empty($all)) {
            // First row: include only if purely numeric (skip text headers)
            $firstCell = ltrim(trim((string)($all[0][0] ?? '')), '#');
            if (preg_match('/^\d+$/', $firstCell)) {
                $entries[] = ['number' => $firstCell, 'reason' => $reason];
                $count++;
            }
            // Remaining rows: include all non-empty values
            foreach (array_slice($all, 1) as $row) {
                $cell = ltrim(trim((string)($row[0] ?? '')), '#');
                if ($cell) {
                    $entries[] = ['number' => $cell, 'reason' => $reason];
                    $count++;
                }
            }
        }

        if ($entries) {
            self::bulkAdd($entries);
        }

        return $count;
    }

}
