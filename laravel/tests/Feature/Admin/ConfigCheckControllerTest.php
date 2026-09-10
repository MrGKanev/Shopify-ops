<?php

namespace Tests\Feature\Admin;

use App\Application\Health\CheckConfiguration;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ConfigCheckControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_only_page_reports_runtime_configuration_without_secrets(): void
    {
        $operator = User::factory()->operator()->create();
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create(['shopify_access_token' => 'secret-token']);
        $operator->stores()->attach($store);
        $admin->stores()->attach($store);

        $this->actingAs($operator)->get('/admin/config-check')->assertForbidden();
        $this->actingAs($admin)->get('/admin/config-check')->assertOk()->assertSeeText('Application')->assertSeeText('Active store')->assertSeeText('Order types')->assertSeeText('Tag policy')->assertDontSeeText('secret-token');
    }

    public function test_contract_detects_unsafe_app_store_and_policy_configuration(): void
    {
        $store = Store::factory()->create(['shopify_access_token' => '', 'shipstation_api_secret' => '']);
        config(['app.env' => 'production', 'app.debug' => true, 'cache.default' => 'missing', 'queue.default' => 'missing', 'services.google.client_id' => 'partial', 'services.google.client_secret' => '', 'services.google.allowed_domains' => '', 'order-types.rules' => [['name' => 'Broken', 'match' => 'unknown']], 'tag-policy.required' => [['when' => [], 'must_have' => []]], 'tag-policy.forbidden' => [['tags' => ['one']]]]);

        $results = collect(app(CheckConfiguration::class)->handle($store))->keyBy('name');

        $this->assertFalse($results['Application']['ok']);
        $this->assertFalse($results['Active store']['ok']);
        $this->assertFalse($results['Order types']['ok']);
        $this->assertFalse($results['Tag policy']['ok']);
    }
}
