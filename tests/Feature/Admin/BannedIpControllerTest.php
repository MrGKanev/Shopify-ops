<?php

namespace Tests\Feature\Admin;

use App\Application\Auth\LoginThrottle;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BannedIpControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_an_admin_can_view_and_unban(): void
    {
        $this->get(route('admin.banned-ips.index'))->assertRedirect(route('login'));
        [$operator] = $this->userWithStore(false);
        $this->actingAs($operator)->get(route('admin.banned-ips.index'))->assertForbidden();
    }

    public function test_admin_sees_currently_banned_ips(): void
    {
        [$admin] = $this->userWithStore(true);
        $throttle = app(LoginThrottle::class);
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $this->actingAs($admin)->get(route('admin.banned-ips.index'))->assertOk()->assertSeeText('1.2.3.4');
    }

    public function test_admin_can_unban_an_ip(): void
    {
        [$admin] = $this->userWithStore(true);
        $throttle = app(LoginThrottle::class);
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');
        $throttle->recordFailureMessage('1.2.3.4');

        $this->actingAs($admin)->delete(route('admin.banned-ips.destroy'), ['ip' => '1.2.3.4'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Unbanned 1.2.3.4.');

        $this->assertNull($throttle->bannedMessage('1.2.3.4'));
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $admin): array
    {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
