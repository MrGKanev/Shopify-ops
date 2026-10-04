<?php

namespace Tests\Feature\Models;

use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StoreTimezoneTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_changing_the_shopify_store_forgets_the_recorded_timezone(): void
    {
        $store = Store::factory()->create(['shopify_timezone' => 'Europe/Sofia']);

        $store->update(['label' => 'Renamed']);
        $this->assertSame('Europe/Sofia', $store->fresh()->shopify_timezone);

        $store->update(['shopify_store' => 'another-shop']);
        $this->assertNull($store->fresh()->shopify_timezone);
        $this->assertSame('UTC', $store->fresh()->shopTimezone());
    }
}
