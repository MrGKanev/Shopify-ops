<?php
declare(strict_types=1);

/**
 * Authentication helpers: login brute-force protection, logout, ban management.
 */
class Auth
{
    private const int LOCK_DURATION  = 604800; // 1 week
    private const int ATTEMPT_WINDOW = 3600;   // sliding window (1 hour)
    private const int MAX_ATTEMPTS   = 3;

    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/login_attempts.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/login_attempts.json');
    }

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
        $attemptsFile = self::file();
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
        $okPass = self::verifyPassword($inputPass, $correctPass);

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
        $_SESSION = [];
        session_destroy();
    }

    public static function verifyPassword(string $inputPass, string $correctPass): bool
    {
        $info = password_get_info($correctPass);
        if (($info['algo'] ?? 0) !== 0) {
            return password_verify($inputPass, $correctPass);
        }
        return hash_equals($correctPass, $inputPass);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function rotateCsrfToken(): string
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function validateCsrf(string $token): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
    }

    // ── RBAC ─────────────────────────────────────────────────────────────────

    /**
     * Returns the role of the currently logged-in user ('viewer'|'operator'|'admin').
     * Defaults to 'admin' so that existing sessions (pre-RBAC) are not downgraded.
     */
    public static function role(): string
    {
        return $_SESSION['user_role'] ?? 'admin';
    }

    /**
     * Check if the current user may perform the given abstract action.
     *
     * Actions understood:
     *   'push'            – push orders to ShipStation (operator+)
     *   'ignore'          – ignore/unignore orders (operator+)
     *   'run_audit'       – run / queue audits (operator+)
     *   'flush_cache'     – flush cache (operator+)
     *   'queue_audit'     – queue audit jobs (operator+)
     *   'manage_settings' – change settings, ban/unban IPs, Slack rules (admin only)
     *   'manage_users'    – add/delete users (admin only)
     */
    public static function can(string $action): bool
    {
        $role = self::role();
        $adminOnly    = ['manage_settings', 'manage_users'];
        $operatorPlus = ['push', 'ignore', 'run_audit', 'flush_cache', 'queue_audit'];

        if (in_array($action, $adminOnly, true)) {
            return $role === 'admin';
        }
        if (in_array($action, $operatorPlus, true)) {
            return in_array($role, ['operator', 'admin'], true);
        }
        return true; // viewers can read everything
    }

    // ── Multi-user support ────────────────────────────────────────────────────

    /**
     * Attempt login against data/users.json.
     * Applies the same brute-force tracking as attempt().
     * Returns the matched role string on success, '' on failure (bad credentials or locked out).
     */
    public static function attemptMultiUser(string $username, string $password, string $ip): string
    {
        $attemptsFile = self::file();
        if (!is_dir(dirname($attemptsFile))) {
            mkdir(dirname($attemptsFile), 0755, true);
        }

        $fh = fopen($attemptsFile, 'c+');
        flock($fh, LOCK_EX);
        $raw      = stream_get_contents($fh);
        $attempts = $raw ? (json_decode($raw, true) ?: []) : [];

        $now = time();
        $attempts = array_filter(
            $attempts,
            fn($e) => ($e['until'] ?? 0) > $now || ($e['first'] ?? 0) > $now - self::ATTEMPT_WINDOW
        );

        $entry     = $attempts[$ip] ?? ['count' => 0, 'first' => $now, 'until' => 0];
        $lockedOut = ($entry['until'] ?? 0) > $now;

        if ($lockedOut) {
            flock($fh, LOCK_UN);
            fclose($fh);
            return '';
        }

        // Verify credentials against users.json
        $role  = '';
        $users = self::loadUsers();
        foreach ($users as $user) {
            if (isset($user['name'], $user['password_hash'], $user['role'])
                && hash_equals((string) $user['name'], $username)
                && self::verifyPassword($password, (string) $user['password_hash'])
            ) {
                $role = (string) $user['role'];
                break;
            }
        }

        if ($role !== '') {
            unset($attempts[$ip]);
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, json_encode($attempts));
            flock($fh, LOCK_UN); fclose($fh);
            return $role;
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
        return '';
    }

    /**
     * Load all users from data/users.json.
     *
     * @return array<int, array<string, string>>
     */
    public static function loadUsers(): array
    {
        $file = self::usersFile();
        if (!file_exists($file)) return [];
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Persist the users array to data/users.json.
     *
     * @param array<int, array<string, string>> $users
     */
    public static function saveUsers(array $users): void
    {
        $file = self::usersFile();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, json_encode(array_values($users), JSON_PRETTY_PRINT));
    }

    private static function usersFile(): string
    {
        return __DIR__ . '/../data/users.json';
    }

    /**
     * Remove a specific IP from the ban list.
     */
    public static function unban(string $ip): void
    {
        $attemptsFile = self::file();
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
        $attemptsFile = self::file();
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
