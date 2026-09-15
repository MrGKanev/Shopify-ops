<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyWebhookEvent;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, Store $store): Response
    {
        $secret = (string) $store->shopify_webhook_secret;
        abort_if($secret === '', 503, 'Webhook verification is not configured.');

        $signature = $request->header('X-Shopify-Hmac-Sha256', '');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        abort_unless($signature !== '' && hash_equals($expected, $signature), 401);

        $shopDomain = mb_strtolower(trim($request->header('X-Shopify-Shop-Domain', '')));
        abort_unless($shopDomain === $store->shopify_store.'.myshopify.com', 401);

        $webhookId = trim($request->header('X-Shopify-Webhook-Id', ''));
        $topic = mb_strtolower(trim($request->header('X-Shopify-Topic', '')));
        abort_if($webhookId === '' || mb_strlen($webhookId) > 255 || $topic === '' || mb_strlen($topic) > 255, 400);

        $payload = $request->json()->all();
        abort_if($payload === [] && trim($request->getContent()) !== '{}', 400);
        $subjectId = $payload['id'] ?? null;

        $event = $store->webhookEvents()->firstOrCreate(
            ['webhook_id' => $webhookId],
            [
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'api_version' => $request->header('X-Shopify-Api-Version'),
                'subject_id' => is_scalar($subjectId) ? (string) $subjectId : null,
                'status' => 'received',
                'payload' => $payload,
                'occurred_at' => now(),
            ],
        );
        if ($event->wasRecentlyCreated) {
            ProcessShopifyWebhookEvent::dispatch($event->getKey());
        }

        return response()->noContent();
    }
}
