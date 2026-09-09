<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CustomerLookupControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_prefill_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/customers/lookup')->assertRedirect(route('login'));
        [$user] = $this->userWithStore(false, ['shopify_access_token' => '']);
        $this->actingAs($user)->get('/customers/lookup?email=jane%40example.com')->assertOk()->assertSee('jane@example.com');
        $this->actingAs($user)->post('/customers/lookup', ['email' => 'bad'])->assertSessionHasErrors('email');
        $this->actingAs($user)->post('/customers/lookup', ['email' => 'jane@example.com'])->assertOk()->assertSeeText('credentials are incomplete');

        [$user, $store] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('customerOrderHistory')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), 'jane@example.com')->andReturn(['orders' => [['id' => 42, 'name' => '#<script>', 'created_at' => '2026-01-01', 'cancelled_at' => null, 'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'total_price' => 10, 'currency' => 'USD', 'tags' => ['<img>']]], 'customer' => ['firstName' => '<b>', 'lastName' => 'Doe'], 'pages' => 20, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($user)->post('/customers/lookup', ['email' => 'Jane@Example.com'])->assertOk()->assertSeeText('1+ orders · USD 10.00 spent · 1 paid · 0 cancelled')->assertSeeText('truncated after 20 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false)->assertDontSee('<b>', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('customerOrderHistory')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($user)->post('/customers/lookup', ['email' => 'jane@example.com'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
