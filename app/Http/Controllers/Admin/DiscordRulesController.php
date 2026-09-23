<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\BuildsNotificationRulesView;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscordRulesRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiscordRulesController extends Controller
{
    use BuildsNotificationRulesView;

    public function edit(Request $request): View
    {
        $store = $this->store($request);

        return view('admin.notification-rules', $this->notificationRulesViewData($store, 'discord'));
    }

    public function update(DiscordRulesRequest $request): RedirectResponse
    {
        $store = $this->store($request);
        $store->update(['discord_rules' => $request->validated()]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties($store->resolvedDiscordRules())->log('save_discord_rules');

        return back()->with('status', 'Discord rules saved.');
    }

    private function store(Request $request): Store
    {

        return $this->resolveStore($request);
    }
}
