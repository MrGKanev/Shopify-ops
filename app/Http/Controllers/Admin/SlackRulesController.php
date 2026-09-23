<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\BuildsNotificationRulesView;
use App\Http\Controllers\Controller;
use App\Http\Requests\SlackRulesRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SlackRulesController extends Controller
{
    use BuildsNotificationRulesView;

    public function edit(Request $request): View
    {
        $store = $this->store($request);

        return view('admin.notification-rules', $this->notificationRulesViewData($store, 'slack'));
    }

    public function update(SlackRulesRequest $request): RedirectResponse
    {
        $store = $this->store($request);
        $store->update(['slack_rules' => $request->validated()]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties($store->resolvedSlackRules())->log('save_slack_rules');

        return back()->with('status', 'Slack rules saved.');
    }

    private function store(Request $request): Store
    {

        return $this->resolveStore($request);
    }
}
