<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrderEditControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected(): void
    {
        $this->get('/reports/order-edits')->assertRedirect(route('login'));
    }

    public function test_viewer_cannot_run_the_report(): void
    {
        [$viewer] = $this->userWithStore();

        $this->actingAs($viewer)->post('/reports/order-edits', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertForbidden();
    }

    public function test_defaults_validation_and_missing_credentials(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->travelTo('2026-09-08 12:00:00');
        $this->actingAs($operator)->get('/reports/order-edits')->assertOk()->assertSee('value="2026-08-09"', false)->assertSee('value="2026-09-08"', false);
        $this->actingAs($operator)->post('/reports/order-edits', ['start_date' => '2026-01-02', 'end_date' => '2026-01-01'])->assertSessionHasErrors(['end_date']);

        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/order-edits', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_report_lists_edited_orders_and_escapes_untrusted_values(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('orderEditCandidates')->once()->with(Mockery::on(fn (Store $value): bool => $value->is($store)), '2026-01-01', '2026-01-31')->andReturn([
            'events' => [['subject_id' => 1, 'message' => 'An item was added', 'created_at' => '2026-01-02T11:30:00Z']],
            'orders' => ['1' => ['name' => '#<script>', 'created_at' => '2026-01-02T10:00:00Z', 'email' => '<img>@x.com']],
            'pages' => 100,
            'truncated' => true,
        ]);
        $this->app->instance(ShopifyOrders::class, $gateway);

        $this->actingAs($operator)->post('/reports/order-edits', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
            ->assertOk()->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);
    }

    public function test_failure_is_reported_without_leaking_the_error(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyOrders::class);
        $gateway->shouldReceive('orderEditCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyOrders::class, $gateway);

        $this->actingAs($operator)->post('/reports/order-edits', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
            ->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }
}
