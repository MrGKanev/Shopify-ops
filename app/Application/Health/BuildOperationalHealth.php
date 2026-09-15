<?php

namespace App\Application\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BuildOperationalHealth
{
    /** @return list<array{label:string,ok:bool,detail:string}> */
    public function handle(): array
    {
        return [
            $this->check('Laravel runtime', fn (): string => 'Laravel '.app()->version().' · '.app()->environment().' · PHP '.PHP_VERSION),
            $this->check('Laravel optimisation', fn (): string => 'Configuration cache: '.(app()->configurationIsCached() ? 'enabled' : 'not cached').'.'),
            $this->check('Application key', fn (): string => config('app.key') !== null ? 'Encryption key is configured.' : throw new \RuntimeException('Application encryption key is missing.')),
            $this->check('Database', function (): string {
                DB::select('select 1');

                return 'Database accepted a test query.';
            }),
            $this->check('Cache write/read', function (): string {
                $key = 'operational-health:'.Str::uuid();
                Cache::put($key, 'ok', 10);
                if (Cache::pull($key) !== 'ok') {
                    throw new \RuntimeException('Cache did not return the test value.');
                }

                return 'Cache accepted and returned a temporary value.';
            }),
            $this->diskCheck('Local file storage', 'local'),
            $this->diskCheck('Backup storage', 'backups'),
            $this->heartbeatCheck('Queue worker', 'health:checks:queue:latestHeartbeatAt.default'),
            $this->heartbeatCheck('Scheduler', 'health:checks:schedule:latestHeartbeatAt'),
            $this->check('Failed queue jobs', function (): string {
                $failed = DB::table('failed_jobs')->count();
                if ($failed > 0) {
                    throw new \RuntimeException("{$failed} failed job(s) need review.");
                }

                return 'No failed jobs are waiting.';
            }),
            $this->backupCheck(),
        ];
    }

    /** @param callable(): string $operation @return array{label:string,ok:bool,detail:string} */
    private function check(string $label, callable $operation): array
    {
        try {
            return ['label' => $label, 'ok' => true, 'detail' => $operation()];
        } catch (Throwable $exception) {
            return ['label' => $label, 'ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /** @return array{label:string,ok:bool,detail:string} */
    private function diskCheck(string $label, string $diskName): array
    {
        return $this->check($label, function () use ($diskName): string {
            $disk = Storage::disk($diskName);
            $path = 'health-checks/'.Str::uuid().'.txt';
            $disk->put($path, 'ok');
            $readable = $disk->get($path) === 'ok';
            $disk->delete($path);
            if (! $readable) {
                throw new \RuntimeException('The test file could not be read back.');
            }

            return 'A temporary file was written, read, and removed.';
        });
    }

    /** @return array{label:string,ok:bool,detail:string} */
    private function heartbeatCheck(string $label, string $key): array
    {
        return $this->check($label, function () use ($key): string {
            $timestamp = Cache::get($key);
            if (! is_numeric($timestamp) || now()->timestamp - (int) $timestamp > 300) {
                throw new \RuntimeException('No heartbeat was recorded in the last five minutes.');
            }

            return 'Recent heartbeat received.';
        });
    }

    /** @return array{label:string,ok:bool,detail:string} */
    private function backupCheck(): array
    {
        return $this->check('Latest backup', function (): string {
            $disk = Storage::disk('backups');
            $files = $disk->allFiles((string) config('backup.backup.name'));
            if ($files === []) {
                throw new \RuntimeException('No backup archive has been found.');
            }

            return 'A backup archive is available.';
        });
    }
}
