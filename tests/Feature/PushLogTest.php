<?php

namespace Tests\Feature;

use App\Application\Orders\RecordPush;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PushLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recorder_appends_and_page_is_newest_first_and_store_scoped(): void
    {
        $firstStore = Store::factory()->create();
        $secondStore = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($firstStore);
        $recorder = app(RecordPush::class);
        $this->travelTo('2026-09-01 10:00:00');
        $recorder->handle($firstStore, '#<script>', '42', 100);
        $this->travelTo('2026-09-02 10:00:00');
        $recorder->handle($firstStore, '#2002', '43', null);
        $recorder->handle($secondStore, '#SECRET', '99', 999);

        $response = $this->actingAs($user)->get('/push-logs')->assertOk()->assertSeeInOrder(['#2002', '#&lt;script&gt;'], false)->assertDontSee('<script>', false)->assertDontSeeText('SECRET');
        $response->assertDontSeeText('No pushes yet.');
        $this->assertDatabaseCount('push_logs', 3);
    }

    public function test_page_requires_authentication(): void
    {
        $this->get('/push-logs')->assertRedirect(route('login'));
    }

    public function test_q_filters_by_order_number_or_shopify_id(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($store);
        $recorder = app(RecordPush::class);
        $recorder->handle($store, '#1001', '42', 100);
        $recorder->handle($store, '#2002', '43', null);

        $this->actingAs($user)->get('/push-logs?q=1001')->assertOk()->assertSeeText('#1001')->assertDontSeeText('#2002');
        $this->actingAs($user)->get('/push-logs?q=43')->assertOk()->assertSeeText('#2002')->assertDontSeeText('#1001');
    }

    public function test_failed_attempts_show_a_safe_error_category_without_an_external_link(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($store);
        app(RecordPush::class)->failed($store, '#1001', '42', new RuntimeException('secret token'));

        $this->actingAs($user)->get('/push-logs')
            ->assertOk()
            ->assertSeeText('Failed')
            ->assertSeeText(RuntimeException::class)
            ->assertDontSeeText('secret token')
            ->assertDontSeeText('View in SS');
    }
}
