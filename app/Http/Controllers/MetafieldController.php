<?php

namespace App\Http\Controllers;

use App\Application\Orders\UseMetafields;
use App\Http\Requests\MetafieldLookupRequest;
use App\Http\Requests\MetafieldSearchRequest;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class MetafieldController extends Controller
{
    public function create(Request $request, ShopifyAdminGateway $shopify): View
    {
        $store = $this->store($request);
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $definitions = [];
        $loadFailed = false;
        if (! $configurationError) {
            try {
                $definitions = $shopify->orderMetafieldDefinitions($store);
            } catch (Throwable $exception) {
                $loadFailed = true;
                $this->log($exception, $store);
            }
        }

        return view('metafields.index', compact('definitions', 'configurationError', 'loadFailed') + ['operationFailed' => false, 'search' => null, 'lookup' => null]);
    }

    public function search(MetafieldSearchRequest $request, ShopifyAdminGateway $shopify, UseMetafields $use): View
    {
        return $this->run($request, $shopify, fn (Store $store): array => ['search' => $use->search($store, (string) $request->validated('namespace'), (string) $request->validated('key'), (string) ($request->validated('value') ?? ''), $request->validated('start_date'), $request->validated('end_date')), 'lookup' => null]);
    }

    public function lookup(MetafieldLookupRequest $request, ShopifyAdminGateway $shopify, UseMetafields $use): View
    {
        $numbers = preg_split('/[\s,]+/', trim((string) $request->validated('orders')), -1, PREG_SPLIT_NO_EMPTY);

        return $this->run($request, $shopify, fn (Store $store): array => ['search' => null, 'lookup' => $use->lookup($store, $numbers ?: [], trim((string) ($request->validated('filter') ?? '')))]);
    }

    private function run(Request $request, ShopifyAdminGateway $shopify, callable $action): View
    {
        $store = $this->store($request);
        $definitions = [];
        $loadFailed = $operationFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        if ($configurationError) {
            return view('metafields.index', compact('definitions', 'configurationError', 'loadFailed', 'operationFailed') + ['search' => null, 'lookup' => null]);
        }
        try {
            $definitions = $shopify->orderMetafieldDefinitions($store);
        } catch (Throwable $exception) {
            $loadFailed = true;
            $this->log($exception, $store);
        }
        $data = ['search' => null, 'lookup' => null];
        try {
            $data = $action($store);
        } catch (Throwable $exception) {
            $operationFailed = true;
            $this->log($exception, $store);
        }

        return view('metafields.index', compact('definitions', 'configurationError', 'loadFailed', 'operationFailed') + $data);
    }

    private function store(Request $request): Store
    { /** @var Store $active */ $active = $request->attributes->get('activeStore');

        return $request->user()->stores()->whereKey($active->getKey())->firstOrFail();
    }

    private function log(Throwable $exception, Store $store): void
    {
        Log::warning('Metafield operation failed.', ['exception_type' => $exception::class, 'store_id' => $store->getKey()]);
    }
}
