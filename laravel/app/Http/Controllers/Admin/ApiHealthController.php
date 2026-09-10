<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckApiHealth;
use App\Application\Health\SendTestEmail;
use App\Application\Health\SendTestSlack;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTestEmailRequest;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ApiHealthController extends Controller
{
    public function show(Request $request, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        return $this->view($this->store($request), null, $sendTestEmail, $sendTestSlack);
    }

    public function check(Request $request, CheckApiHealth $checkApiHealth, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        $store = $this->store($request);

        return $this->view($store, $checkApiHealth->handle($store), $sendTestEmail, $sendTestSlack);
    }

    public function sendTestEmail(SendTestEmailRequest $request, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        $sent = false;

        try {
            $sendTestEmail->handle((string) $request->validated('email'));
            $sent = true;
        } catch (Throwable $exception) {
            Log::warning('Test email delivery failed.', ['exception_type' => $exception::class]);
        }

        return $this->view($this->store($request), null, $sendTestEmail, $sendTestSlack, $sent ? 'sent' : 'failed');
    }

    public function sendTestSlack(Request $request, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        $sent = false;

        try {
            $sendTestSlack->handle();
            $sent = true;
        } catch (Throwable $exception) {
            Log::warning('Test Slack delivery failed.', ['exception_type' => $exception::class]);
        }

        return $this->view($this->store($request), null, $sendTestEmail, $sendTestSlack, slackResult: $sent ? 'sent' : 'failed');
    }

    /** @param array<string, mixed>|null $health */
    private function view(Store $store, ?array $health, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack, ?string $mailResult = null, ?string $slackResult = null): View
    {
        return view('admin.api-health', [
            'health' => $health,
            'flowHealth' => $this->flowHealth($store),
            'mailConfiguration' => $sendTestEmail->configuration(),
            'mailResult' => $mailResult,
            'slackConfiguration' => $sendTestSlack->configuration(),
            'slackResult' => $slackResult,
        ]);
    }

    /** @return array{summary:array{healthy:int,attention:int},flows:list<array{tool:string,status:string,runs:int,errors:int,last_run_at:string,last_error:string}>} */
    private function flowHealth(Store $store): array
    {
        $runs = $store->runLogs()->latest('id')->limit(500)->get()->groupBy('tool');
        $flows = [];
        $summary = ['healthy' => 0, 'attention' => 0];
        foreach ($runs as $tool => $history) {
            $latest = $history->first();
            $errors = $history->filter(fn ($run): bool => $run->status === 'error' || $run->error !== '');
            $attention = $latest->status === 'error' || $latest->error !== '';
            $summary[$attention ? 'attention' : 'healthy']++;
            $flows[] = ['tool' => (string) $tool, 'status' => $latest->status, 'runs' => $history->count(), 'errors' => $errors->count(), 'last_run_at' => $latest->created_at->toDateTimeString(), 'last_error' => (string) ($errors->first()?->error ?? '')];
        }

        return compact('summary', 'flows');
    }

    private function store(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
