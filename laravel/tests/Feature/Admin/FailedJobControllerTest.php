<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FailedJobControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_an_admin_can_view_retry_or_delete(): void
    {
        $id = $this->seedFailedJob();

        $this->get(route('admin.failed-jobs.index'))->assertRedirect(route('login'));
        [$operator] = $this->userWithStore(false);
        $this->actingAs($operator)->get(route('admin.failed-jobs.index'))->assertForbidden();
        $this->actingAs($operator)->post(route('admin.failed-jobs.retry', $id))->assertForbidden();
        $this->actingAs($operator)->delete(route('admin.failed-jobs.destroy', $id))->assertForbidden();
    }

    public function test_admin_sees_the_job_class_and_exception_summary(): void
    {
        [$admin] = $this->userWithStore(true);
        $this->seedFailedJob(displayName: 'App\\Jobs\\RunAuditJob', exception: "RuntimeException: boom\n#0 somewhere");

        $this->actingAs($admin)->get(route('admin.failed-jobs.index'))
            ->assertOk()
            ->assertSeeText('App\\Jobs\\RunAuditJob')
            ->assertSeeText('RuntimeException: boom');
    }

    public function test_admin_can_retry_a_failed_job_and_it_is_removed_from_the_failed_list(): void
    {
        Queue::fake();
        [$admin] = $this->userWithStore(true);
        $uuid = $this->seedFailedJob();

        $this->actingAs($admin)->post(route('admin.failed-jobs.retry', $uuid))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_admin_can_delete_a_failed_job(): void
    {
        [$admin] = $this->userWithStore(true);
        $uuid = $this->seedFailedJob();

        $this->actingAs($admin)->delete(route('admin.failed-jobs.destroy', $uuid))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    private function seedFailedJob(string $displayName = 'App\\Jobs\\RunAuditJob', string $exception = "RuntimeException: boom\n#0 somewhere"): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'sync',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $displayName]]),
            'exception' => $exception,
            'failed_at' => now(),
        ]);

        return $uuid;
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
