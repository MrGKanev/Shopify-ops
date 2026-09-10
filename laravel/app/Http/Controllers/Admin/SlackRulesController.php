<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SlackRulesRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SlackRulesController extends Controller
{
    public function edit(Request $request): View
    {
        $store = $this->store($request);

        return view('admin.slack-rules', ['rules' => $store->resolvedSlackRules(), 'configured' => trim((string) config('services.slack.notifications.webhook_url')) !== '']);
    }

    public function update(SlackRulesRequest $request): RedirectResponse
    {
        $this->store($request)->update(['slack_rules' => $request->validated()]);

        return back()->with('status', 'Slack rules saved.');
    }

    private function store(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
