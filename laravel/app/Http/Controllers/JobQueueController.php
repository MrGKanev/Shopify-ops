<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class JobQueueController extends Controller
{
    public function index(Request $request): View
    {
        $usingRedis = config('queue.default') === 'redis';
        $jobs = $usingRedis ? null : DB::table('jobs')->latest('id')->paginate(100, ['*'], 'jobs');
        $failed = DB::table('failed_jobs')->latest('id')->paginate(100, ['*'], 'failed');

        return view('jobs.index', [
            'auditJobs' => $request->attributes->get('activeStore')->auditJobs()->latest()->limit(100)->get(),
            'jobs' => $jobs,
            'failed' => $failed,
            'usingRedis' => $usingRedis,
            'canViewHorizon' => Gate::allows('viewHorizon'),
        ]);
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
