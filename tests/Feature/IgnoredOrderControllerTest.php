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

    public function test_bulk_ignore_by_selection_with_a_shared_reason(): void
    {
        [$operator, $store] = $this->userWithStore(true);

        $this->actingAs($operator)->post('/ignored-orders/bulk', ['order_numbers' => ['#1001', '', '1002'], 'reason' => 'Repeat offender'])
            ->assertRedirect()
            ->assertSessionHas('status', '2 orders ignored.');

        $this->assertSame([['1001', 'Repeat offender'], ['1002', 'Repeat offender']], $store->ignoredOrders()->orderBy('order_number')->get(['order_number', 'reason'])->map(fn ($o) => [$o->order_number, $o->reason])->all());
    }

    public function test_index_shows_recurrence_counts_for_repeatedly_missing_orders(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $store->ignoredOrders()->create(['order_number' => '1234', 'ignored_at' => today()]);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-01', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'rows_found' => 1, 'result' => ['missing' => [['name' => '#1234']]]]);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-03', 'start_date' => '2026-09-03', 'end_date' => '2026-09-03', 'rows_found' => 1, 'result' => ['missing' => [['name' => '#1234']]]]);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => '2026-09-05', 'start_date' => '2026-09-05', 'end_date' => '2026-09-05', 'rows_found' => 1, 'result' => ['missing' => [['name' => '#1234']]]]);

        $response = $this->actingAs($operator)->get('/ignored-orders')->assertOk();

        $response->assertViewHas('recurrenceCounts', fn ($counts) => $counts['1234'] === 3);
        $response->assertSeeText('Hot');
    }

    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
