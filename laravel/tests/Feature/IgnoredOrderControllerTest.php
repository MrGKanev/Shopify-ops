<?php

namespace Tests\Feature;

use App\Models\IgnoredOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class IgnoredOrderControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_add_update_and_store_scoped_delete(): void
    {
        $this->get('/ignored-orders')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/ignored-orders')->assertForbidden();
        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/ignored-orders', ['order_number' => '#12-345', 'reason' => '<script>'])->assertRedirect();
        $this->actingAs($operator)->post('/ignored-orders', ['order_number' => '12345', 'reason' => 'updated'])->assertRedirect();
        $ignored = IgnoredOrder::firstOrFail();
        $this->assertSame([$store->id, '12345', 'updated'], [$ignored->store_id, $ignored->order_number, $ignored->reason]);
        $this->actingAs($operator)->get('/ignored-orders')->assertOk()->assertSeeText('#12345')->assertDontSee('<script>', false);

        [, $foreign] = $this->userWithStore(true);
        $foreignOrder = IgnoredOrder::create(['store_id' => $foreign->id, 'order_number' => '9', 'ignored_at' => today()]);
        $this->actingAs($operator)->delete(route('ignored-orders.destroy', $foreignOrder))->assertNotFound();
        $this->actingAs($operator)->delete(route('ignored-orders.destroy', $ignored))->assertRedirect();
        $this->assertDatabaseMissing('ignored_orders', ['id' => $ignored->id]);

        $first = IgnoredOrder::create(['store_id' => $store->id, 'order_number' => '1', 'ignored_at' => today()]);
        $second = IgnoredOrder::create(['store_id' => $store->id, 'order_number' => '2', 'ignored_at' => today()]);
        $this->actingAs($operator)->delete(route('ignored-orders.bulk-destroy'), ['ids' => [$first->id, $second->id, $foreignOrder->id]])->assertRedirect();
        $this->assertDatabaseCount('ignored_orders', 1);
    }

    public function test_csv_import_skips_header_strips_hash_and_deduplicates(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $file = UploadedFile::fake()->createWithContent('orders.csv', "order_number\n#1001\n1002\n1001\n");
        $this->actingAs($operator)->post('/ignored-orders/import', ['file' => $file, 'reason' => 'bulk'])->assertRedirect()->assertSessionHas('status', '2 orders imported.');
        $this->assertSame(['1001', '1002'], $store->ignoredOrders()->orderBy('order_number')->pluck('order_number')->all());
    }

    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
