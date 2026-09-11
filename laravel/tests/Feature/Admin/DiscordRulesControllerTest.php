<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DiscordRulesControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_admin_can_view_and_save_normalized_store_rules(): void
    {
        $this->get('/admin/discord-rules')->assertRedirect(route('login'));
        [$operator] = $this->userWithStore(false);
        $this->actingAs($operator)->get('/admin/discord-rules')->assertForbidden();
        [$admin, $store] = $this->userWithStore(true);
        $this->actingAs($admin)->get('/admin/discord-rules')->assertOk()->assertSeeText('Discord webhook is not configured');
        $this->actingAs($admin)->put('/admin/discord-rules', ['audit_enabled' => '1', 'audit_min_missing' => 2, 'scan_enabled' => '1', 'scan_min_rows' => 5])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(['audit_enabled' => true, 'audit_min_missing' => 2, 'include_zero_audit' => false, 'scan_enabled' => true, 'scan_min_rows' => 5], $store->fresh()->discord_rules);
        $this->actingAs($admin)->put('/admin/discord-rules', ['audit_min_missing' => -1])->assertSessionHasErrors('audit_min_missing');
    }

    private function userWithStore(bool $admin): array
    {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
