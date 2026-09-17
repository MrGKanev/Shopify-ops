<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailRulesRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailRulesController extends Controller
{
    public function edit(Request $request): View
    {
        $store = $this->store($request);
        $rules = $store->resolvedEmailRules();
        foreach (array_keys(config('tool-catalog')) as $tool) {
            $rules[$tool] ??= ['mode' => 'off', 'threshold' => $tool === 'run_audit' ? 0 : 1, 'include_zero' => false, 'email' => ''];
        }

        return view('admin.email-rules', ['rules' => $rules, 'catalog' => config('tool-catalog'), 'defaultAlertEmail' => $store->default_alert_email]);
    }

    public function update(EmailRulesRequest $request): RedirectResponse
    {
        $store = $this->store($request);
        $store->update(['email_rules' => $request->validated('rules'), 'default_alert_email' => $request->validated('default_alert_email')]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)
            ->withProperties($store->resolvedEmailRules())->log('save_email_rules');

        return back()->with('status', 'Email rules saved.');
    }

    private function store(Request $request): Store
    {

        return $this->resolveStore($request);
    }
}
