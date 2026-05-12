<?php
/**
 * Authentication helpers: login brute-force protection, logout, ban management.
 */
class Auth
{
    private const string ATTEMPTS_FILE = __DIR__ . '/../data/login_attempts.json';
    private const int    LOCK_DURATION  = 604800; // 1 week
    private const int    ATTEMPT_WINDOW = 3600;   // sliding window (1 hour)
    private const int    MAX_ATTEMPTS   = 3;

    /**
     * Attempt a login. Returns an empty string on success, an error message on failure.
     * On success the caller is responsible for setting $_SESSION['authed'] = true.
     */
    public static function attempt(
        string $inputUser,
        string $inputPass,
        string $correctUser,
        string $correctPass,
        string $ip
    ): string {
        $attemptsFile = self::ATTEMPTS_FILE;
        if (!is_dir(dirname($attemptsFile))) {
            mkdir(dirname($attemptsFile), 0755, true);
        }

        $fh = fopen($attemptsFile, 'c+');
        flock($fh, LOCK_EX);
        $raw      = stream_get_contents($fh);
        $attempts = $raw ? (json_decode($raw, true) ?: []) : [];

        $now = time();
        // Keep entries that are still banned OR had a recent failed attempt
        $attempts = array_filter(
            $attempts,
            fn($e) => ($e['until'] ?? 0) > $now || ($e['first'] ?? 0) > $now - self::ATTEMPT_WINDOW
        );

        $entry    = $attempts[$ip] ?? ['count' => 0, 'first' => $now, 'until' => 0];
        $lockedOut = ($entry['until'] ?? 0) > $now;

        if ($lockedOut) {
            flock($fh, LOCK_UN);
            fclose($fh);
            $secs  = $entry['until'] - $now;
            $days  = (int) floor($secs / 86400);
            $hours = (int) floor(($secs % 86400) / 3600);
            return $days > 0
                ? "Too many failed attempts. Try again in {$days} day" . ($days !== 1 ? 's' : '') . ($hours > 0 ? " and {$hours}h" : '') . '.'
                : "Too many failed attempts. Try again in {$hours} hour" . ($hours !== 1 ? 's' : '') . '.';
        }

        $okUser = hash_equals($correctUser, $inputUser);
        $okPass = hash_equals($correctPass, $inputPass);

        if ($okUser && $okPass) {
            unset($attempts[$ip]);
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, json_encode($attempts));
            flock($fh, LOCK_UN); fclose($fh);
            return '';
        }

        $entry['count'] = ($entry['count'] ?? 0) + 1;
        if (!isset($entry['first'])) $entry['first'] = $now;
        if ($entry['count'] >= self::MAX_ATTEMPTS) {
            $entry['until'] = $now + self::LOCK_DURATION;
        }
        $attempts[$ip] = $entry;
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($attempts));
        flock($fh, LOCK_UN); fclose($fh);

        $remaining = self::MAX_ATTEMPTS - $entry['count'];
        return $remaining > 0
            ? 'Incorrect username or password. ' . $remaining . ' attempt' . ($remaining !== 1 ? 's' : '') . ' remaining.'
            : 'Too many failed attempts. Account locked for 1 week. Contact your administrator.';
    }

    /**
     * Destroy the current session (logout).
     */
    public static function logout(): void
    {
        session_destroy();
    }

    /**
     * Remove a specific IP from the ban list.
     */
    public static function unban(string $ip): void
    {
        $attemptsFile = self::ATTEMPTS_FILE;
        if (!$ip || !file_exists($attemptsFile)) {
            return;
        }
        $fh = fopen($attemptsFile, 'c+');
        flock($fh, LOCK_EX);
        $attempts = json_decode(stream_get_contents($fh), true) ?: [];
        unset($attempts[$ip]);
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($attempts));
        flock($fh, LOCK_UN); fclose($fh);
    }

    /**
     * Returns an associative array of currently banned IPs keyed by IP address.
     * Each value is the raw entry array from the attempts file.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function bannedIps(): array
    {
        $attemptsFile = self::ATTEMPTS_FILE;
        if (!file_exists($attemptsFile)) {
            return [];
        }
        $now    = time();
        $all    = json_decode(file_get_contents($attemptsFile), true) ?: [];
        $banned = [];
        foreach ($all as $ip => $entry) {
            if (($entry['until'] ?? 0) > $now) {
                $banned[$ip] = $entry;
            }
        }
        return $banned;
    }
}
