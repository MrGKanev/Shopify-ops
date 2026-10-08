<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProductCompletenessControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_configuration_error_prevents_call(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $gateway = Mockery::mock(ShopifyCatalog::class);
        $gateway->shouldNotReceive('productCompletenessCandidates');
        $this->app->instance(ShopifyCatalog::class, $gateway);
        $this->actingAs($user)->post(route('reports.product-completeness.store'))->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_results_truncation_and_xss_render_safely(): void
    {
        [$user,$store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyCatalog::class);
        $gateway->shouldReceive('productCompletenessCandidates')->once()->with(Mockery::on(fn (Store $s): bool => $s->is($store)))->andReturn(['products' => [['legacyResourceId' => '42', 'title' => '<script>x</script>', 'vendor' => '<img src=x>', 'descriptionHtml' => '', 'images' => ['nodes' => []], 'variants' => []]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyCatalog::class, $gateway);
        $this->actingAs($user)->post(route('reports.product-completeness.store'))->assertOk()->assertSeeText('1 scanned · 1 with issues')->assertSeeText('not a complete store inventory')->assertDontSee('<script>', false)->assertDontSee('<img', false);
    }

    public function test_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyCatalog::class);
        $gateway->shouldReceive('productCompletenessCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyCatalog::class, $gateway);
        $this->actingAs($user)->post(route('reports.product-completeness.store'))->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('with issues');
    }
}
