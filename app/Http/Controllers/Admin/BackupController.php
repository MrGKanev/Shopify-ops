<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RestoreBackup;
use App\Models\BackupVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(): View
    {
        $disk = Storage::disk('backups');
        $folder = (string) config('backup.backup.name');

        $backups = collect($disk->allFiles($folder))
            ->map(fn (string $path): array => [
                'path' => $path,
                'name' => basename($path),
                'size' => $disk->size($path),
                'lastModified' => Carbon::createFromTimestamp($disk->lastModified($path)),
            ])
            ->sortByDesc('lastModified')
            ->values();
        $latestVerifications = $backups->isEmpty()
            ? collect()
            : BackupVerification::query()
                ->whereIn('path', $backups->pluck('path'))
                ->latest('verified_at')
                ->get()
                ->unique('path')
                ->keyBy('path');

        $operationDirectory = storage_path('app/backup-operations');
        File::ensureDirectoryExists($operationDirectory, 0700);
        $operations = collect(File::files($operationDirectory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'json')
            ->map(fn (\SplFileInfo $file): array => json_decode(File::get($file->getPathname()), true))
            ->sortByDesc('requested_at')
            ->take(5);

        return view('admin.backups', compact('backups', 'latestVerifications', 'operations'));
    }

    public function download(string $path): StreamedResponse
    {
        $disk = Storage::disk('backups');
        $folder = (string) config('backup.backup.name');

        abort_unless(in_array($path, $disk->allFiles($folder), true), 404);

        return $disk->download($path);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['scope' => ['required', 'in:database,full']]);
        $arguments = ['--isolated' => true, '--disable-notifications' => true];
        if ($validated['scope'] === 'database') {
            $arguments['--only-db'] = true;
        }

        if (Artisan::call('backup:run', $arguments) !== 0) {
            return back()->withErrors(['backup' => 'Backup-ът не можа да бъде създаден. Виж application log за подробности.']);
        }

        activity('administration')->causedBy($request->user())->withProperties(['scope' => $validated['scope']])->log('manual_backup_created');

        return back()->with('status', $validated['scope'] === 'database' ? 'Database backup-ът е създаден.' : 'Пълният backup е създаден.');
    }

    public function restore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'string', 'max:255'],
        ]);
        $disk = Storage::disk('backups');
        $folder = (string) config('backup.backup.name');
        $path = $validated['path'];

        abort_unless(in_array($path, $disk->allFiles($folder), true), 404);

        if (! hash_equals(basename($path), $validated['confirmation'])) {
            throw ValidationException::withMessages(['confirmation' => 'Въведи точното име на архива, за да потвърдиш restore-а.']);
        }

        $verification = BackupVerification::query()->where('path', $path)->latest('verified_at')->first();
        if ($verification?->status !== 'verified' || ! is_string($verification->checksum)) {
            throw ValidationException::withMessages(['restore' => 'Първо провери архива успешно от бутона „Verify restore“.']);
        }

        $operationId = (string) Str::uuid();
        $operationDirectory = storage_path('app/backup-operations');
        File::ensureDirectoryExists($operationDirectory, 0700);
        $operationFile = $operationDirectory.'/'.$operationId.'.json';
        File::put($operationFile, json_encode([
            'id' => $operationId,
            'path' => $path,
            'status' => 'queued',
            'requested_by' => $request->user()->name,
            'requested_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        chmod($operationFile, 0600);

        RestoreBackup::dispatch($operationId, $path, $verification->checksum, $request->user()->name)->onConnection('background');

        return back()->with('status', 'Restore-ът е стартиран. Презареди страницата, за да видиш статуса.');
    }
}
