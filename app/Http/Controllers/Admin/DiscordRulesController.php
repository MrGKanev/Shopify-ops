<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscordRulesRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiscordRulesController extends Controller
{
    public function edit(Request $request): View
    {
        $store = $this->store($request);

        return view('admin.discord-rules', ['rules' => $store->resolvedDiscordRules(), 'configured' => trim((string) config('services.discord.notifications.webhook_url')) !== '']);
    }

    public function update(DiscordRulesRequest $request): RedirectResponse
    {
        $this->store($request)->update(['discord_rules' => $request->validated()]);

        return back()->with('status', 'Discord rules saved.');
    }

    private function store(Request $request): Store
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
    }
}
