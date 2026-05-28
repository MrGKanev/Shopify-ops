<?php
/**
 * Optional multi-store support.
 * When stores.json exists in the project root, multi-store mode is active.
 * Each store entry defines its own API credentials and optional data paths.
 */
class Stores
{
    private static ?array $cache    = null;
    private static string $jsonPath = '';

    public static function init(string $rootDir): void
    {
        self::$jsonPath = $rootDir . '/stores.json';
    }

    public static function isMultiStore(): bool
    {
        return self::$jsonPath !== '' && file_exists(self::$jsonPath);
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $raw = self::isMultiStore() ? file_get_contents(self::$jsonPath) : '';
            self::$cache = ($raw ? (json_decode($raw, true) ?: []) : []);
        }
        return self::$cache;
    }

    /** @return array<string, mixed> */
    public static function getActive(): array
    {
        $stores = self::all();
        if (empty($stores)) return [];

        $id = $_SESSION['store_id'] ?? '';
        foreach ($stores as $s) {
            if (($s['id'] ?? '') === $id) return $s;
        }
        return $stores[0];
    }

    public static function setActive(string $id): void
    {
        $_SESSION['store_id'] = $id;
    }
}
