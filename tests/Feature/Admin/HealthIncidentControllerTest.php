<?php

namespace Tests\Feature\Admin;

use App\Models\HealthIncident;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HealthIncidentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_administrators_can_view_health_incidents(): void
    {
        $this->get(route('admin.health-incidents'))->assertRedirect(route('login'));
        [$viewer] = $this->makeUserAndStore();

        $this->actingAs($viewer)->get(route('admin.health-incidents'))->assertForbidden();
    }

    public function test_it_lists_metrics_escapes_messages_and_filters_incidents(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        [$admin] = $this->makeUserAndStore(true);
        HealthIncident::factory()->create([
            'check_name' => 'database',
            'check_label' => 'Database',
            'summary' => '<script>alert(1)</script>',
            'started_at' => now()->subMinutes(15),
            'last_observed_at' => now(),
        ]);
        HealthIncident::factory()->resolved()->create([
            'check_name' => 'cache',
            'check_label' => 'Cache',
            'started_at' => now()->subMinutes(20),
            'last_observed_at' => now()->subMinutes(10),
            'resolved_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.health-incidents', ['status' => 'open', 'component' => 'database']))
            ->assertOk()
            ->assertSeeText('Health Incident History')
            ->assertSeeText('Database')
            ->assertSeeText('15m')
            ->assertDontSee('<script>', false);

        $this->assertSame(['database'], $response->viewData('incidents')->pluck('check_name')->all());
    }

    public function test_it_rejects_unknown_filters(): void
    {
        [$admin] = $this->makeUserAndStore(true);

        $this->actingAs($admin)->get(route('admin.health-incidents', ['status' => 'broken']))
            ->assertSessionHasErrors('status');
    }

    /** @return array{User, Store} */
    private function makeUserAndStore(bool $administrator = false): array
    {
        $user = $administrator ? User::factory()->admin()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
