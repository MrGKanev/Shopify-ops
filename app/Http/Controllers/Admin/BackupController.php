<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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

        return view('admin.backups', compact('backups', 'latestVerifications'));
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
}
