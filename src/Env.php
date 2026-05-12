<?php
/**
 * Loads a .env file into the process environment.
 */
class Env
{
    /**
     * Parse $path and register each key=value pair via putenv().
     * Blank lines and lines beginning with # are silently skipped.
     * Existing environment variables are never overwritten.
     */
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        foreach (file($path) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2)) + ['', ''];
            if ($k && !isset($_ENV[$k])) putenv("{$k}={$v}");
        }
    }
}
