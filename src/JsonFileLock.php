<?php
declare(strict_types=1);

/**
 * Shared helpers for classes that store state in a single JSON file.
 * All writes use exclusive file locking so concurrent PHP processes are safe.
 */
trait JsonFileLock
{
    protected static function writeJson(string $file, callable $mutator): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $raw  = stream_get_contents($fh);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
        $data = $mutator($data);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    protected static function readJson(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?: [];
    }
}
