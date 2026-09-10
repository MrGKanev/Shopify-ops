<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EmailRulesControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_save_validated_store_scoped_rules(): void
    {
        $operator = User::factory()->operator()->create();
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $operator->stores()->attach($store);
        $admin->stores()->attach($store);

        $this->actingAs($operator)->get('/admin/email-rules')->assertForbidden();
        $this->actingAs($admin)->get('/admin/email-rules')->assertOk()->assertSeeText('run_audit');
        $this->actingAs($admin)->put('/admin/email-rules', ['rules' => ['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => '1', 'email' => 'ops@example.com']]])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true, 'email' => 'ops@example.com']], $store->fresh()->email_rules);
        $this->actingAs($admin)->put('/admin/email-rules', ['rules' => ['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'email' => 'bad']]])->assertSessionHasErrors('rules.run_audit.email');
    }
}
