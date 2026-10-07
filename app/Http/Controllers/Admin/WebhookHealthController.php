<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckWebhookHealth;
use App\Application\Health\ManageWebhookSubscriptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class WebhookHealthController extends Controller
{
    public function __invoke(Request $request, CheckWebhookHealth $check): View
    {
        $store = $this->resolveStore($request);

        return view('admin.webhook-health', $check->handle($store));
    }

    public function store(Request $request, ManageWebhookSubscriptions $subscriptions): RedirectResponse
    {
        try {
            $count = $subscriptions->registerMissing($this->resolveStore($request));
        } catch (Throwable) {
            return back()->withErrors(['webhooks' => __('Webhooks could not all be registered. Check credentials, the app signing secret, HTTPS APP_URL and scopes, then reload to see any successful registrations.')]);
        }

        return back()->with('status', __('Registered :count missing webhooks.', ['count' => $count]));
    }

    public function destroy(Request $request, ManageWebhookSubscriptions $subscriptions): RedirectResponse
    {
        $validated = $request->validate(['subscription_id' => ['required', 'string', 'regex:~\Agid://shopify/WebhookSubscription/[0-9]+\z~']]);
        try {
            $subscriptions->remove($this->resolveStore($request), $validated['subscription_id']);
        } catch (Throwable) {
            return back()->withErrors(['webhooks' => __('The webhook could not be removed. Register its replacement first; only outdated subscriptions owned by this application can be removed.')]);
        }

        return back()->with('status', __('Outdated webhook removed.'));
    }
}
