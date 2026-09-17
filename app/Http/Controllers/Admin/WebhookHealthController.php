<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckWebhookHealth;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebhookHealthController extends Controller
{
    public function __invoke(Request $request, CheckWebhookHealth $check): View
    {
        $store = $this->resolveStore($request);

        return view('admin.webhook-health', $check->handle($store));
    }
}
