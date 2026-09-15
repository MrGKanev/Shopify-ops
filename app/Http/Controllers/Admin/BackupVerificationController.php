<?php

namespace App\Http\Controllers\Admin;

use App\Application\Backups\VerifyBackupArchive;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyBackupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class BackupVerificationController extends Controller
{
    public function __invoke(VerifyBackupRequest $request, VerifyBackupArchive $verifier): RedirectResponse
    {
        $path = (string) $request->validated('path');
        $folder = (string) config('backup.backup.name');
        abort_unless(in_array($path, Storage::disk('backups')->allFiles($folder), true), 404);

        $verification = $verifier->handle($path);
        activity('administration')->causedBy($request->user())->performedOn($verification)
            ->withProperties(['path' => $path, 'status' => $verification->status])
            ->log('backup_verified');

        if ($verification->status !== 'verified') {
            return back()->withErrors(['verification' => 'Backup-ът не издържа проверката: '.$verification->error]);
        }

        return back()->with('status', 'Backup-ът е проверен успешно и съдържа четим database dump.');
    }
}
