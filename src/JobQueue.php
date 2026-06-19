<?php
declare(strict_types=1);

/**
 * Simple file-backed background job queue.
 */
class JobQueue
{
    private const int MAX_ENTRIES = 500;
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/jobs.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/jobs.json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function enqueue(string $type, array $payload, string $label = ''): string
    {
        $id = bin2hex(random_bytes(8));
        $job = [
            'id'          => $id,
            'type'        => $type,
            'label'       => $label ?: $type,
            'status'      => 'pending',
            'queued_at'   => date('Y-m-d H:i:s'),
            'started_at'  => '',
            'finished_at' => '',
            'payload'     => $payload,
            'result'      => [],
            'error'       => '',
        ];

        self::write(function (array $jobs) use ($job): array {
            $jobs[] = $job;
            return count($jobs) > self::MAX_ENTRIES ? array_slice($jobs, -self::MAX_ENTRIES) : $jobs;
        });

        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_reverse(self::read());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function claimNext(): ?array
    {
        $claimed = null;
        self::write(function (array $jobs) use (&$claimed): array {
            foreach ($jobs as &$job) {
                if (($job['status'] ?? '') !== 'pending') continue;
                $job['status'] = 'running';
                $job['started_at'] = date('Y-m-d H:i:s');
                $claimed = $job;
                break;
            }
            unset($job);
            return $jobs;
        });
        return $claimed;
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function complete(string $id, array $result = []): void
    {
        self::update($id, [
            'status'      => 'done',
            'finished_at' => date('Y-m-d H:i:s'),
            'result'      => $result,
            'error'       => '',
        ]);
    }

    public static function fail(string $id, string $error): void
    {
        self::update($id, [
            'status'      => 'failed',
            'finished_at' => date('Y-m-d H:i:s'),
            'error'       => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $patch
     */
    private static function update(string $id, array $patch): void
    {
        self::write(function (array $jobs) use ($id, $patch): array {
            foreach ($jobs as &$job) {
                if (($job['id'] ?? '') === $id) {
                    $job = array_merge($job, $patch);
                    break;
                }
            }
            unset($job);
            return $jobs;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function read(): array
    {
        $file = self::file();
        if (!file_exists($file)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function write(callable $mutator): void
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $jobs = $raw ? (json_decode($raw, true) ?: []) : [];
        $jobs = $mutator($jobs);
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($jobs, JSON_PRETTY_PRINT));
        flock($fh, LOCK_UN); fclose($fh);
    }
}
