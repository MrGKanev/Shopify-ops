<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class DuplicateAddressControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_store_results_truncation_and_remote_text_are_rendered_safely(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $address = ['address1' => '1 Main', 'city' => 'X', 'zip' => '1', 'country_code' => 'US'];
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('addressCheckCandidates')->once()->with(Mockery::on(fn (Store $value): bool => $value->is($store)), '2026-09-01', '2026-09-07', false)->andReturn(['orders' => [['id' => '42', 'name' => '#<script>', 'email' => 'a@x.com', 'shipping_address' => $address], ['id' => '43', 'name' => '#2', 'email' => '<img>@x.com', 'shipping_address' => $address]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyOrders::class, $gateway);
        $this->actingAs($operator)->post('/reports/duplicate-addresses', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('2 scanned · 1 shared addresses')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);
    }
}
