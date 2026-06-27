<?php
declare(strict_types=1);

/**
 * Simple file-backed background job queue.
 */
class JobQueue
{
    use JsonFileLock;

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

        self::writeJson(self::file(), function (array $jobs) use ($job): array {
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
        return array_reverse(self::readJson(self::file()));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function claimNext(): ?array
    {
        $claimed = null;
        self::writeJson(self::file(), function (array $jobs) use (&$claimed): array {
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
        self::writeJson(self::file(), function (array $jobs) use ($id, $patch): array {
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

}
