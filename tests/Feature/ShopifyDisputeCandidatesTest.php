<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyDisputeCandidatesTest extends TestCase
{
    public function test_open_status_query_and_normalization(): void
    {
        Http::fake(['*' => Http::response(['data' => ['disputes' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '1', 'status' => 'NEEDS_RESPONSE', 'initiatedAt' => '2026-06-01', 'evidenceDueBy' => '2026-06-04', 'amount' => ['amount' => '50.00', 'currencyCode' => 'USD'], 'reasonDetails' => ['reason' => 'FRAUDULENT', 'networkReasonCode' => '10.4'], 'order' => ['legacyResourceId' => '1001', 'name' => '#1001']]]]]]])]);
        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->openDisputes(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']));
        $this->assertSame('needs_response', $result['disputes'][0]['status']);
        $this->assertSame('fraudulent', $result['disputes'][0]['reason']);
        $this->assertSame('10.4', $result['disputes'][0]['network_reason_code']);
        $this->assertSame(50.0, $result['disputes'][0]['amount']);
        $this->assertSame('USD', $result['disputes'][0]['currency']);
        $this->assertSame('1001', $result['disputes'][0]['order_id']);
        $this->assertSame('#1001', $result['disputes'][0]['order_name']);
        Http::assertSent(fn ($request): bool => $request['variables']['search'] === 'status:NEEDS_RESPONSE OR status:UNDER_REVIEW');
    }

    public function test_paginates_and_handles_a_dispute_without_an_order(): void
    {
        $node = ['legacyResourceId' => '1', 'status' => 'UNDER_REVIEW', 'initiatedAt' => '2026-06-01', 'amount' => ['amount' => '50', 'currencyCode' => 'USD'], 'reasonDetails' => ['reason' => 'FRAUDULENT'], 'order' => null];
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['disputes' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next'], 'edges' => [['node' => $node]]]]])
            ->push(['data' => ['disputes' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => [...$node, 'legacyResourceId' => '2']]]]]])]);

        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->openDisputes(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']));

        $this->assertCount(2, $result['disputes']);
        $this->assertSame('', $result['disputes'][0]['order_name']);
        $this->assertNull($result['disputes'][0]['network_reason_code']);
        $this->assertSame(2, $result['pages']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['after'] === 'next');
    }
}
