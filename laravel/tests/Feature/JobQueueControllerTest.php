<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobQueueControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_and_safe_pending_failed_job_visibility(): void
    {
        $this->get('/jobs')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/jobs')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['displayName' => '<script>Pending</script>']), 'attempts' => 1, 'available_at' => time(), 'created_at' => time()]);
        DB::table('failed_jobs')->insert(['uuid' => 'job-1', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => '<img>Failed</img>']), 'exception' => 'secret-token']);
        $this->actingAs($operator)->get('/jobs')->assertOk()->assertSeeText('Pending · 1')->assertSeeText('Failed · 1')->assertDontSee('<script>', false)->assertDontSee('<img>', false)->assertDontSeeText('secret-token');
    }

    public function test_admin_sees_a_horizon_link_instead_of_the_pending_table_when_queue_is_redis(): void
    {
        config(['queue.default' => 'redis']);
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['displayName' => 'Ignored']), 'attempts' => 1, 'available_at' => time(), 'created_at' => time()]);

        $this->actingAs($admin)->get('/jobs')
            ->assertOk()
            ->assertDontSeeText('Pending · 1')
            ->assertSee(url(config('horizon.path')));
    }

    public function test_non_admin_sees_an_admin_only_notice_instead_of_the_pending_table_when_queue_is_redis(): void
    {
        config(['queue.default' => 'redis']);
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get('/jobs')
            ->assertOk()
            ->assertDontSeeText('Pending · 0')
            ->assertDontSee(url(config('horizon.path')))
            ->assertSeeText('Pending job details are only visible to administrators in the Horizon dashboard.');
    }

    public function test_retry_and_forget_only_existing_failed_jobs(): void
    {
        [$operator] = $this->userWithStore(true);
        DB::table('failed_jobs')->insert(['uuid' => 'job-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x']);
        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['job-1']])->andReturn(0);
        $this->actingAs($operator)->post('/jobs/failed/job-1/retry')->assertRedirect();
        Artisan::shouldReceive('call')->once()->with('queue:forget', ['id' => 'job-1'])->andReturn(0);
        $this->actingAs($operator)->delete('/jobs/failed/job-1')->assertRedirect();
        $this->actingAs($operator)->post('/jobs/failed/missing/retry')->assertNotFound();
    }

    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
