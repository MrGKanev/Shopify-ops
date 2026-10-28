<?php

namespace App\Application\Operations;

use App\Domain\Reports\ImportFreshnessAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\IssueStatus;
use App\Models\OperationalIssue;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoadImportFreshness
{
    public function __construct(private readonly ImportFreshnessAnalyzer $analyzer, private readonly ShipStationClientFactory $clients) {}

    /**
     * Read-only freshness view built from the existing run-audit issues and run logs.
     * The ShipStation refresh status is fetched only on request and is shown as reported, never interpreted as a failure or a completed import.
     *
     * @return array{freshness: array<string, mixed>, grace_hours: int, last_run: array{status: string, at: CarbonImmutable}|null, last_success_at: CarbonImmutable|null, refresh: array{state: string, status?: string|null, refresh_date?: string|null}|null}
     */
    public function handle(Store $store, bool $withRefreshStatus = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $issues = array_values($store->operationalIssues()->where('source_tool', 'run_audit')->whereIn('status', IssueStatus::active())->get()
            ->map(fn (OperationalIssue $issue): array => ['reference' => (string) $issue->reference, 'title' => $issue->title, 'priority' => $issue->priority->value, 'occurrences' => $issue->occurrences, 'first_seen_at' => CarbonImmutable::parse($issue->first_seen_at), 'last_seen_at' => CarbonImmutable::parse($issue->last_seen_at)])
            ->all());
        $latest = $store->runLogs()->where('tool', 'run_audit')->latest('id')->first();
        $success = $store->runLogs()->where('tool', 'run_audit')->whereIn('status', ['ok', 'issues_found'])->latest('id')->first();

        return [
            'freshness' => $this->analyzer->analyze($issues, $now, $store->importGraceHours()),
            'grace_hours' => $store->importGraceHours(),
            'last_run' => $latest === null ? null : ['status' => (string) $latest->status, 'at' => CarbonImmutable::parse($latest->created_at)],
            'last_success_at' => $success === null ? null : CarbonImmutable::parse($success->created_at),
            'refresh' => $withRefreshStatus ? $this->refreshStatus($store) : null,
        ];
    }

    /** @return array{state: string, status?: string|null, refresh_date?: string|null} */
    private function refreshStatus(Store $store): array
    {
        $storeNumber = ctype_digit((string) $store->store_number) && (int) $store->store_number > 0 ? (int) $store->store_number : null;
        $client = $this->clients->forStore($store);
        if ($client === null || $storeNumber === null) {
            return ['state' => 'not_configured'];
        }
        try {
            return ['state' => 'reported'] + $client->refreshStatus($storeNumber);
        } catch (Throwable $exception) {
            Log::warning('ShipStation refresh status unavailable.', ['exception_type' => $exception::class]);

            return ['state' => 'unavailable'];
        }
    }
}
