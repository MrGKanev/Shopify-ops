<?php

namespace Tests\Feature;

use App\Models\HealthIncident;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;
use Spatie\Health\Events\CheckEndedEvent;
use Tests\TestCase;

class TrackHealthIncidentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_failed_observations_form_one_incident_until_the_check_recovers(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $check = $this->check();

        event(new CheckEndedEvent($check, $this->healthResult($check, Status::failed(), 'Connection refused')));
        $this->travel(5)->minutes();
        event(new CheckEndedEvent($check, $this->healthResult($check, Status::warning(), 'Connection is slow')));

        $incident = HealthIncident::query()->sole();
        $this->assertSame('warning', $incident->severity);
        $this->assertSame('Connection is slow', $incident->summary);
        $this->assertSame(2, $incident->observations);
        $this->assertNull($incident->resolved_at);

        $this->travel(10)->minutes();
        event(new CheckEndedEvent($check, $this->healthResult($check, Status::ok())));

        $incident->refresh();
        $this->assertSame('2026-09-15 12:15:00', $incident->resolved_at->format('Y-m-d H:i:s'));
        $this->assertSame(900, $incident->durationInSeconds());
    }

    public function test_skipped_checks_do_not_change_incidents_and_a_later_failure_starts_a_new_one(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $check = $this->check();
        HealthIncident::factory()->resolved()->create(['check_name' => 'database', 'check_label' => 'Database']);

        event(new CheckEndedEvent($check, $this->healthResult($check, Status::skipped())));
        event(new CheckEndedEvent($check, $this->healthResult($check, Status::crashed(), 'Check crashed')));

        $this->assertSame(2, HealthIncident::query()->count());
        $this->assertDatabaseHas('health_incidents', [
            'check_name' => 'database',
            'severity' => 'crashed',
            'summary' => 'Check crashed',
            'resolved_at' => null,
        ]);
    }

    private function check(): Check
    {
        return (new class extends Check
        {
            public function run(): Result
            {
                return Result::make()->ok();
            }
        })->name('database')->label('Database');
    }

    private function healthResult(Check $check, Status $status, string $message = ''): Result
    {
        return (new Result($status, $message))->check($check)->endedAt(now());
    }
}
