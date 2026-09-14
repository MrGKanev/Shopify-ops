<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
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

        return view('admin.backups', compact('backups'));
    }

    public function download(string $path): StreamedResponse
    {
        $disk = Storage::disk('backups');
        $folder = (string) config('backup.backup.name');

        abort_unless(in_array($path, $disk->allFiles($folder), true), 404);

        return $disk->download($path);
    }
}
