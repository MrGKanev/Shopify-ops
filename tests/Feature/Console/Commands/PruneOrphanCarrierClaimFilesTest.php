<?php

namespace Tests\Feature\Console\Commands;

use App\Models\CarrierClaim;
use App\Models\CarrierClaimDocument;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneOrphanCarrierClaimFilesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_removes_files_of_claims_and_stores_that_no_longer_exist(): void
    {
        $kept = CarrierClaim::factory()->create();
        $keptPath = CarrierClaimDocument::directory($kept->store_id, $kept->getKey()).'/kept.pdf';
        CarrierClaimDocument::factory()->for($kept, 'claim')->create(['path' => $keptPath]);
        Storage::disk('local')->put($keptPath, 'x');

        // A claim removed by a database cascade leaves its files behind: no Eloquent event runs.
        $cascaded = CarrierClaim::factory()->create();
        $cascadedPath = CarrierClaimDocument::directory($cascaded->store_id, $cascaded->getKey()).'/gone.pdf';
        Storage::disk('local')->put($cascadedPath, 'x');
        $cascaded->store()->getQuery()->delete();
        $this->assertDatabaseMissing('carrier_claims', ['id' => $cascaded->getKey()]);
        Storage::disk('local')->put('carrier-claims/999/888/lost.pdf', 'x');

        $this->artisan('claims:prune-orphan-files')->assertSuccessful();

        Storage::disk('local')->assertExists($keptPath);
        Storage::disk('local')->assertMissing($cascadedPath);
        Storage::disk('local')->assertMissing('carrier-claims/999/888/lost.pdf');
    }

    public function test_it_never_touches_paths_outside_the_claim_directories(): void
    {
        Storage::disk('local')->put('carrier-claims/notes.txt', 'x');
        Storage::disk('local')->put('carrier-claims/abc/def/file.pdf', 'x');
        Storage::disk('local')->put('reports/1/2/file.csv', 'x');

        $this->artisan('claims:prune-orphan-files')->assertSuccessful();

        Storage::disk('local')->assertExists('carrier-claims/notes.txt');
        Storage::disk('local')->assertExists('carrier-claims/abc/def/file.pdf');
        Storage::disk('local')->assertExists('reports/1/2/file.csv');
    }

    public function test_dry_run_removes_nothing_and_unreferenced_recent_uploads_are_kept(): void
    {
        $claim = CarrierClaim::factory()->create();
        $directory = CarrierClaimDocument::directory($claim->store_id, $claim->getKey());
        Storage::disk('local')->put($directory.'/fresh.pdf', 'x');
        Storage::disk('local')->put('carrier-claims/999/888/lost.pdf', 'x');

        $this->artisan('claims:prune-orphan-files', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('local')->assertExists('carrier-claims/999/888/lost.pdf');

        $this->artisan('claims:prune-orphan-files')->assertSuccessful();
        Storage::disk('local')->assertMissing('carrier-claims/999/888/lost.pdf');
        Storage::disk('local')->assertExists($directory.'/fresh.pdf');
    }

    public function test_old_unreferenced_files_in_a_live_claim_directory_are_removed(): void
    {
        $claim = CarrierClaim::factory()->create();
        $path = CarrierClaimDocument::directory($claim->store_id, $claim->getKey()).'/stray.pdf';
        Storage::disk('local')->put($path, 'x');
        touch(Storage::disk('local')->path($path), now()->subDays(2)->getTimestamp());

        $this->artisan('claims:prune-orphan-files')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
    }
}
