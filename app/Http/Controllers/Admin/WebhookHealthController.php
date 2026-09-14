<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckWebhookHealth;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebhookHealthController extends Controller
{
    public function __invoke(Request $request, CheckWebhookHealth $check): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return view('admin.webhook-health', $check->handle($store));
    }
}
