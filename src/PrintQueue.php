<?php
declare(strict_types=1);

/**
 * Simple queue for packing-slip print jobs, stored in data/print_queue.json.
 * Follows the PushLog pattern (JsonFileLock + static methods).
 */
class PrintQueue
{
    use JsonFileLock;

    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/print_queue.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/print_queue.json');
    }

    /**
     * Add an order to the queue.  Silently skips duplicates.
     *
     * @param string $orderNumber  The ShipStation order number to queue.
     * @param string $note         Optional note stored alongside the entry.
     */
    public static function add(string $orderNumber, string $note = ''): void
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            return;
        }

        self::writeJson(self::file(), function (array $queue) use ($orderNumber, $note): array {
            foreach ($queue as $entry) {
                if (($entry['order_number'] ?? '') === $orderNumber) {
                    return $queue; // already queued
                }
            }
            $queue[] = [
                'order_number' => $orderNumber,
                'note'         => $note,
                'queued_at'    => date('Y-m-d H:i:s'),
            ];
            return $queue;
        });
    }

    /**
     * Remove a single entry from the queue by order number.
     */
    public static function remove(string $orderNumber): void
    {
        $orderNumber = trim($orderNumber);
        self::writeJson(self::file(), function (array $queue) use ($orderNumber): array {
            return array_values(array_filter(
                $queue,
                fn($e) => ($e['order_number'] ?? '') !== $orderNumber
            ));
        });
    }

    /**
     * Returns all queued entries, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::readJson(self::file());
    }

    /**
     * Empties the entire queue.
     */
    public static function clear(): void
    {
        self::writeJson(self::file(), fn() => []);
    }
}
