<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PrintQueueControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_deduplication_safe_output_and_actions(): void
    {
        $this->get('/print-queue')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/print-queue')->assertForbidden();
        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/print-queue', ['order_number' => 'bad order'])->assertSessionHasErrors('order_number');
        $payload = ['order_number' => '#ORD-001', 'note' => '<script>x</script>'];
        $this->actingAs($operator)->post('/print-queue', $payload)->assertRedirect();
        $this->actingAs($operator)->post('/print-queue', $payload)->assertRedirect();
        $item = $store->printQueueItems()->firstOrFail();
        $this->assertSame(1, $store->printQueueItems()->count());
        $this->actingAs($operator)->get('/print-queue')->assertOk()->assertSeeText('ORD-001')->assertSee(route('orders.packing-slip', ['order' => 'ORD-001']), false)->assertDontSee('<script>', false);
        $this->actingAs($operator)->delete(route('print-queue.destroy', $item))->assertRedirect();
        $this->assertSame(0, $store->printQueueItems()->count());
        $store->printQueueItems()->create(['order_number' => 'ORD-002']);
        $store->printQueueItems()->create(['order_number' => 'ORD-003']);
        $this->actingAs($operator)->delete(route('print-queue.clear'))->assertRedirect();
        $this->assertSame(0, $store->printQueueItems()->count());
    }

    public function test_other_store_item_cannot_be_removed(): void
    {
        [$operator] = $this->userWithStore(true);
        [, $otherStore] = $this->userWithStore(true);
        $item = $otherStore->printQueueItems()->create(['order_number' => 'ORD-999']);

        $this->actingAs($operator)->delete(route('print-queue.destroy', $item))->assertNotFound();
    }

    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
