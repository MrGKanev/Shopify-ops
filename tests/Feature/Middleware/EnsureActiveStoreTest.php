<?php

namespace Tests\Feature\Middleware;

use App\Models\HealthIncident;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EnsureActiveStoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_account_without_any_store_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertForbidden();
    }

    public function test_the_first_store_by_label_is_used_when_none_is_selected(): void
    {
        $user = User::factory()->create();
        $zulu = Store::factory()->create(['label' => 'Zulu']);
        $alpha = Store::factory()->create(['label' => 'Alpha']);
        $user->stores()->attach([$zulu->id, $alpha->id]);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertSessionHas('active_store_id', $alpha->id);
        $this->assertSame(['Alpha', 'Zulu'], $response->viewData('availableStores')->pluck('label')->all());
        $this->assertSame($alpha->id, $response->viewData('activeStore')->id);
    }

    public function test_a_selected_store_is_kept_but_never_one_the_user_cannot_access(): void
    {
        $user = User::factory()->create();
        $first = Store::factory()->create(['label' => 'Alpha']);
        $second = Store::factory()->create(['label' => 'Zulu']);
        $foreign = Store::factory()->create(['label' => 'Aardvark']);
        $user->stores()->attach([$first->id, $second->id]);

        $this->actingAs($user)->withSession(['active_store_id' => $second->id])->get(route('dashboard'))->assertOk()->assertSessionHas('active_store_id', $second->id);
        $this->withSession(['active_store_id' => $foreign->id])->get(route('dashboard'))->assertOk()->assertSessionHas('active_store_id', $first->id);
    }

    public function test_foreign_stores_are_not_listed_to_the_view(): void
    {
        [$user] = $this->userWithStore(false, ['label' => 'Mine']);
        Store::factory()->create(['label' => 'Not mine']);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame(['Mine'], $response->viewData('availableStores')->pluck('label')->all());
    }

    public function test_external_system_outage_flag_follows_unresolved_api_incidents_and_is_cached_briefly(): void
    {
        [$user] = $this->userWithStore();
        Cache::forget('external-systems-down');
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewHas('externalSystemsDown', false);

        $incident = HealthIncident::factory()->create(['check_name' => 'ShopifyApiHealth', 'resolved_at' => null]);
        $this->get(route('dashboard'))->assertViewHas('externalSystemsDown', false);

        $this->travel(31)->seconds();
        $this->get(route('dashboard'))->assertViewHas('externalSystemsDown', true);

        $incident->update(['resolved_at' => now()]);
        $this->travel(31)->seconds();
        $this->get(route('dashboard'))->assertViewHas('externalSystemsDown', false);
    }

    public function test_unrelated_incidents_do_not_flag_an_outage(): void
    {
        [$user] = $this->userWithStore();
        Cache::forget('external-systems-down');
        HealthIncident::factory()->create(['check_name' => 'Database', 'resolved_at' => null]);

        $this->actingAs($user)->get(route('dashboard'))->assertViewHas('externalSystemsDown', false);
    }
}
