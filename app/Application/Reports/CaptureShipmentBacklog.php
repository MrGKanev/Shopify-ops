<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShipmentBacklogAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\AuditSnapshot;
use App\Models\Store;
use Carbon\CarbonImmutable;
use LogicException;

class CaptureShipmentBacklog
{
    public function __construct(private readonly ShipStationClientFactory $factory, private readonly ShipmentBacklogAnalyzer $analyzer) {}

    public function handle(Store $store): AuditSnapshot
    {
        $capturedAt = CarbonImmutable::now($store->shopTimezone());
        $identity = ['tool' => 'shipment_backlog', 'report_date' => $capturedAt->startOfDay()];
        $existing = $store->auditSnapshots()->where($identity)->first();
        if ($existing !== null) {
            return $existing;
        }
        $storeNumber = (string) $store->store_number;
        if (! ctype_digit($storeNumber) || (int) $storeNumber < 1) {
            throw new LogicException('A ShipStation store number is required for backlog history.');
        }
        $client = $this->factory->forStore($store) ?? throw new LogicException('ShipStation credentials are required for backlog history.');
        $orders = $client->fetchBacklogOrders((int) $storeNumber);
        $counts = $this->analyzer->analyze($orders, $capturedAt);

        return $store->auditSnapshots()->firstOrCreate($identity, [
            'start_date' => $identity['report_date'], 'end_date' => $identity['report_date'],
            'rows_found' => count($orders),
            'result' => [...$counts, 'captured_at' => $capturedAt->toIso8601String(), 'completed_at' => CarbonImmutable::now($store->shopTimezone())->toIso8601String(), 'timezone' => $store->shopTimezone(), 'source' => 'shipstation'],
        ]);
    }
}
