<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class JobQueueController extends Controller
{
    public function index(): View
    {
        $jobs = DB::table('jobs')->latest('id')->paginate(100, ['*'], 'jobs');
        $failed = DB::table('failed_jobs')->latest('id')->paginate(100, ['*'], 'failed');

        return view('jobs.index', compact('jobs', 'failed'));
    }

    public function retry(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', 'Failed job queued for retry.');
    }

    public function destroy(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('status', 'Failed job removed.');
    }
}
