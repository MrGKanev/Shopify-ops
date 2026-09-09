<?php

namespace Tests\Feature;

use App\Application\Orders\RecordPush;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PushLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recorder_appends_and_page_is_newest_first_and_store_scoped(): void
    {
        $firstStore = Store::factory()->create();
        $secondStore = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($firstStore);
        $recorder = app(RecordPush::class);
        $this->travelTo('2026-09-01 10:00:00');
        $recorder->handle($firstStore, '#<script>', '42', 100);
        $this->travelTo('2026-09-02 10:00:00');
        $recorder->handle($firstStore, '#2002', '43', null);
        $recorder->handle($secondStore, '#SECRET', '99', 999);

        $response = $this->actingAs($user)->get('/push-logs')->assertOk()->assertSeeInOrder(['#2002', '#&lt;script&gt;'], false)->assertDontSee('<script>', false)->assertDontSeeText('SECRET');
        $response->assertDontSeeText('No pushes yet.');
        $this->assertDatabaseCount('push_logs', 3);
    }

    public function test_page_requires_authentication(): void
    {
        $this->get('/push-logs')->assertRedirect(route('login'));
    }
}
