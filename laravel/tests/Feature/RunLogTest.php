<?php

namespace Tests\Feature;

use App\Application\Reports\RecordRun;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RunLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recorder_defaults_caps_history_and_page_is_store_scoped(): void
    {
        $store = Store::factory()->create();
        $foreign = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($store);
        $recorder = app(RecordRun::class);
        for ($index = 0; $index < 501; $index++) {
            $recorder->handle($store, ['tool' => "scan-{$index}"]);
        }
        $recorder->handle($foreign, ['tool' => 'secret']);

        $this->assertSame(500, $store->runLogs()->count());
        $this->assertDatabaseMissing('run_logs', ['store_id' => $store->id, 'tool' => 'scan-0']);
        $latest = $store->runLogs()->latest('id')->firstOrFail();
        $this->assertSame(['ok', '', null], [$latest->status, $latest->error, $latest->duration_seconds]);
        $this->actingAs($user)->get('/run-logs')->assertOk()->assertSeeText('scan-500')->assertDontSeeText('secret')->assertDontSee('<script>', false);
    }

    public function test_page_requires_authentication(): void
    {
        $this->get('/run-logs')->assertRedirect(route('login'));
    }
}
