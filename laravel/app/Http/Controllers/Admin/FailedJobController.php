<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class FailedJobController extends Controller
{
    public function index(): View
    {
        $failedJobs = collect(app('queue.failer')->all())->map(function (object $job): object {
            $payload = json_decode((string) $job->payload, true);
            $job->displayName = $payload['displayName'] ?? 'Unknown job';
            $job->exceptionSummary = strtok((string) $job->exception, "\n");

            return $job;
        });

        return view('admin.failed-jobs', compact('failedJobs'));
    }

    public function retry(string $id): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$id]]);

        return back()->with('status', "Retrying failed job #{$id}.");
    }

    public function destroy(string $id): RedirectResponse
    {
        app('queue.failer')->forget($id);

        return back()->with('status', "Deleted failed job #{$id}.");
    }
}
