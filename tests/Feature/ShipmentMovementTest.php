<?php

namespace Tests\Feature;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunShipmentMovementReport;
use App\Domain\Reports\ShipmentMovementAnalyzer;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\IssueStatus;
use App\Jobs\RunQueuedReport;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class ShipmentMovementTest extends TestCase
{
    private bool $voidShipment = false;

    use LazilyRefreshDatabase;

    #[DataProvider('states')]
    public function test_classifies_only_confirmed_carrier_evidence(string $status, array $events, ?string $labelDate, ?string $deliveryDate, string $expected, bool $actionable, bool $resolves): void
    {
        $result = (new ShipmentMovementAnalyzer)->analyze(['status_code' => $status, 'events' => $events, 'actual_delivery_date' => $deliveryDate], $labelDate, 48, 72, CarbonImmutable::parse('2026-10-24T12:00:00Z'));
        $this->assertSame($expected, $result['classification']);
        $this->assertSame($actionable, $result['actionable']);
        $this->assertSame($resolves, $result['resolves']);
    }

    public static function states(): array
    {
        $label = '2026-10-10T12:00:00Z';
        $recent = [['status_code' => 'IT', 'occurred_at' => '2026-10-23T12:00:00Z']];

        return [
            'no acceptance record' => ['NY', [], $label, null, 'no_acceptance_record', true, false],
            'grace' => ['NY', [], '2026-10-23T12:00:00Z', null, 'acceptance_grace', false, false],
            'old progress' => ['IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-20T12:00:00Z']], $label, null, 'no_recent_progress', true, false],
            'recent progress' => ['IT', $recent, $label, null, 'recent_progress', false, true],
            'exception' => ['EX', [], $label, null, 'carrier_exception', true, false],
            'attempt' => ['AT', $recent, $label, null, 'delivery_attempt', true, false],
            'unknown' => ['UN', $recent, $label, null, 'movement_unknown', false, false],
            'delivered history' => ['DE', $recent, $label, null, 'delivered', false, true],
            'delivered timestamp' => ['DE', [], $label, '2026-10-23T12:00:00Z', 'delivered', false, true],
            'undated delivered' => ['DE', [], $label, null, 'movement_unknown', false, false],
            'collection is not recipient delivery' => ['SP', $recent, $label, null, 'awaiting_collection', false, false],
            'contradictory no acceptance' => ['NY', $recent, $label, null, 'movement_unknown', false, false],
            'unmapped carrier event' => ['IT', [['status_code' => null, 'occurred_at' => '2026-10-23T12:00:00Z']], $label, null, 'movement_unknown', false, false],
            'future event' => ['IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-25T12:00:00Z']], $label, null, 'movement_unknown', false, false],
            'timezone missing' => ['IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-23T12:00:00']], $label, null, 'movement_unknown', false, false],
            'reused tracking history' => ['IT', [['status_code' => 'IT', 'occurred_at' => '2026-10-01T12:00:00Z']], $label, null, 'movement_unknown', false, false],
            'missing label creation' => ['NY', [], null, null, 'movement_unknown', false, false],
            'invalid label creation' => ['EX', [], '2026-02-30T12:00:00Z', null, 'movement_unknown', false, false],
        ];
    }

    public function test_report_creates_triage_issues_preserves_owner_and_deadline_and_resolves_only_with_current_evidence(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42', 'shipstation_v2_api_key' => 'v2-secret']);
        $this->fake($this->tracking('NY'), [$this->tracking('UN'), $this->tracking('DE', [['status_code' => 'DE', 'occurred_at' => '2026-10-24T10:00:00Z']])]);
        $input = ['order_number' => '1001', 'acceptance_hours' => 48, 'stale_hours' => 72];
        $this->actingAs($operator)->post('/reports/shipment-movement', $input)->assertSeeText('No carrier acceptance record')->assertDontSeeText('v2-secret');
        $this->get(route('reports.shipment-movement.result', $input))->assertSeeText('No carrier acceptance record');
        $this->travel(2)->days();
        $this->get(route('reports.shipment-movement.result', $input))->assertSeeText('No carrier acceptance record');
        $this->travelBack();
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $issue = $store->operationalIssues()->where('source_tool', 'shipment_movement')->sole();
        $this->put(route('operational-issues.update', $issue), ['status' => 'in_progress', 'priority' => 'high', 'owner_user_id' => $operator->id, 'due_date' => '2026-11-01', 'resolution_note' => 'Check carrier policy'])->assertRedirect();

        app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);
        $this->assertSame(IssueStatus::InProgress, $issue->fresh()->status);
        $this->assertSame($operator->id, $issue->fresh()->owner_user_id);
        $this->assertSame('2026-11-01', $issue->fresh()->due_date->toDateString());
        $this->actingAs($operator)->get(route('operational-issues.index', ['source' => 'delivery_watch']))->assertSeeText('TRACK-1')->assertSeeText('Check carrier policy');

        app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);
        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
        $this->assertSame(1, $issue->fresh()->occurrences);
    }

    public function test_voiding_the_label_resolves_its_open_movement_issue(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = Store::factory()->create(['store_number' => '42', 'shipstation_v2_api_key' => 'v2-secret']);
        $this->fake($this->tracking('NY'));
        app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);
        $issue = $store->operationalIssues()->where('source_tool', 'shipment_movement')->sole();
        $this->assertSame(IssueStatus::Open, $issue->status);

        $this->voidShipment = true;
        $this->fake($this->tracking('NY'));
        app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
    }

    public function test_failed_or_foreign_tracking_never_writes_a_partial_issue(): void
    {
        $store = Store::factory()->create(['store_number' => '42', 'shipstation_v2_api_key' => 'v2-secret']);
        $this->fake([...$this->tracking('EX'), 'tracking_number' => 'FOREIGN']);
        try {
            $this->expectException(UnexpectedResponse::class);
            app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);
        } finally {
            $this->assertDatabaseCount('operational_issues', 0);
        }
    }

    public function test_foreign_order_is_rejected_before_any_v2_tracking_read(): void
    {
        $store = Store::factory()->create(['store_number' => '42', 'shipstation_v2_api_key' => 'v2-secret']);
        Http::preventStrayRequests();
        Http::fake(['ssapi.shipstation.com/orders*' => Http::response($this->page([ShipStationFixtures::lookupOrder(1, '1001', 99)]))]);
        try {
            $this->expectException(UnexpectedResponse::class);
            app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);
        } finally {
            $this->assertDatabaseCount('operational_issues', 0);
            Http::assertSentCount(1);
        }
    }

    public function test_report_requires_an_operator_valid_parameters_and_a_separate_tracking_key(): void
    {
        Http::preventStrayRequests();
        $this->get('/reports/shipment-movement')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/shipment-movement')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/shipment-movement', ['order_number' => '1001', 'acceptance_hours' => 0, 'stale_hours' => 72])->assertSessionHasErrors('acceptance_hours');
        $this->post('/reports/shipment-movement', ['order_number' => '1001', 'acceptance_hours' => 48, 'stale_hours' => 72])->assertSeeText('separate V2 tracking API key');
        $this->get(route('reports.shipment-movement.result', ['order_number' => '1001', 'acceptance_hours' => 48, 'stale_hours' => 72]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_v2_key_is_encrypted_hidden_kept_when_blank_removable_and_not_flashed_after_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $payload = ['slug' => $store->slug, 'label' => $store->label, 'shopify_store' => $store->shopify_store, 'delivery_watch_days' => 5, 'shipstation_v2_api_key' => 'private-v2'];
        $this->actingAs($admin)->put(route('admin.stores.update', $store), $payload)->assertRedirect();
        $this->assertSame('private-v2', $store->fresh()->shipstation_v2_api_key);
        $this->assertNotSame('private-v2', $store->fresh()->getRawOriginal('shipstation_v2_api_key'));
        $this->assertArrayNotHasKey('shipstation_v2_api_key', $store->fresh()->toArray());
        $this->get(route('admin.stores.edit', $store))->assertDontSee('private-v2');
        $this->put(route('admin.stores.update', $store), [...$payload, 'shipstation_v2_api_key' => ''])->assertRedirect();
        $this->assertSame('private-v2', $store->fresh()->shipstation_v2_api_key);
        $this->put(route('admin.stores.update', $store), [...$payload, 'label' => ''])->assertSessionHasErrors('label')->assertSessionMissing('_old_input.shipstation_v2_api_key');
        $this->put(route('admin.stores.update', $store), [...$payload, 'shipstation_v2_api_key' => '', 'forget_shipstation_v2_api_key' => true])->assertRedirect();
        $this->assertNull($store->fresh()->shipstation_v2_api_key);
    }

    public function test_pending_and_foreign_result_views_do_not_start_tracking_requests(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42', 'shipstation_v2_api_key' => 'v2-secret']);
        Queue::fake([RunQueuedReport::class]);
        Http::preventStrayRequests();
        $input = ['order_number' => '1001', 'acceptance_hours' => 48, 'stale_hours' => 72];
        $this->actingAs($operator)->post('/reports/shipment-movement', $input)->assertRedirect();
        $this->get(route('reports.shipment-movement.result', $input))->assertSeeText('The report is running in the background.');
        $this->assertSame(1, $store->reportRuns()->count());
        Queue::assertPushed(RunQueuedReport::class, 1);
        $other = Store::factory()->create();
        $foreignRun = app(QueuedReportRunner::class)->createRun($other, 'shipment_movement', RunShipmentMovementReport::class, ['1001', 48, 72], null, null);
        $foreignRun->storeResult(new ReportResult(rows: [], scanned: 0));
        $this->get(route('reports.shipment-movement.result', [...$input, 'report_run' => $foreignRun->id]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_later_tracking_failure_does_not_write_the_first_labels_finding(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $store = Store::factory()->create(['store_number' => '42', 'shipstation_v2_api_key' => 'v2-secret']);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('findByOrderNumber')->once()->andReturn([ShipStationFixtures::lookupOrder(1, '1001')]);
        $first = ['shipmentId' => 1, 'orderId' => 1, 'carrierCode' => 'ups', 'trackingNumber' => 'TRACK-1', 'createDate' => '2026-10-10T12:00:00Z'];
        $client->shouldReceive('getOrderCostShipments')->once()->andReturn([$first, [...$first, 'shipmentId' => 2, 'trackingNumber' => 'TRACK-2']]);
        Http::preventStrayRequests();
        Http::fake(['api.shipstation.com/v2/tracking*' => fn (Request $request) => Http::response($request['tracking_number'] === 'TRACK-1' ? $this->tracking('EX') : [...$this->tracking('EX'), 'tracking_number' => 'FOREIGN'])]);
        try {
            $this->expectException(UnexpectedResponse::class);
            app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);
        } finally {
            $this->assertDatabaseCount('operational_issues', 0);
            Http::assertSentCount(2);
        }
    }

    #[DataProvider('invalidShipments')]
    public function test_unverified_or_ambiguous_labels_are_rejected_before_tracking_calls(array $rows): void
    {
        $store = Store::factory()->create(['store_number' => '42', 'shipstation_v2_api_key' => 'v2-secret']);
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('findByOrderNumber')->once()->andReturn([ShipStationFixtures::lookupOrder(1, '1001')]);
        $client->shouldReceive('getOrderCostShipments')->once()->andReturn($rows);
        Http::preventStrayRequests();
        try {
            $this->expectException(UnexpectedResponse::class);
            app(RunShipmentMovementReport::class)->handle($store, '1001', 48, 72);
        } finally {
            $this->assertDatabaseCount('operational_issues', 0);
            Http::assertNothingSent();
        }
    }

    public static function invalidShipments(): array
    {
        $first = ['shipmentId' => 1, 'orderId' => 1, 'carrierCode' => 'ups', 'trackingNumber' => 'TRACK-1', 'createDate' => '2026-10-10T12:00:00Z'];

        return [
            'foreign order' => [[[...$first, 'orderId' => 2]]],
            'foreign store' => [[[...$first, 'advancedOptions' => ['storeId' => 99]]]],
            'ambiguous tracking' => [[$first, [...$first, 'shipmentId' => 2]]],
            'conflicting shipment' => [[$first, [...$first, 'trackingNumber' => 'OTHER']]],
            'missing carrier' => [[[...$first, 'carrierCode' => '']]],
            'scan bound' => [array_map(fn (int $id): array => [...$first, 'shipmentId' => $id], range(1, 11))],
        ];
    }

    private function tracking(string $status, array $events = []): array
    {
        return ['tracking_number' => 'TRACK-1', 'carrier_code' => 'ups', 'status_code' => $status, 'events' => $events];
    }

    private function fake(array $tracking, array $later = []): void
    {
        Http::preventStrayRequests();
        $trackingResponses = [$tracking, ...$later];
        Http::fake([
            'ssapi.shipstation.com/orders*' => fn (Request $request) => Http::response($this->page([ShipStationFixtures::lookupOrder(1, '1001')])),
            'ssapi.shipstation.com/shipments*' => function (Request $request) {
                $this->assertSame('42', (string) $request['storeId']);
                $this->assertSame('1', (string) $request['orderId']);

                return Http::response($this->page([['shipmentId' => 1, 'orderId' => 1, 'orderNumber' => '1001', 'carrierCode' => 'ups', 'trackingNumber' => 'TRACK-1', 'createDate' => '2026-10-10T12:00:00Z'] + ($this->voidShipment ? ['voidDate' => '2026-10-12T12:00:00Z'] : [])]));
            },
            'api.shipstation.com/v2/tracking*' => function (Request $request) use (&$trackingResponses) {
                $this->assertTrue($request->hasHeader('API-Key', 'v2-secret'));
                $this->assertSame('ups', $request['carrier_code']);
                $this->assertSame('TRACK-1', $request['tracking_number']);

                return Http::response(array_shift($trackingResponses));
            },
        ]);
    }

    private function page(array $rows): array
    {
        return ['orders' => $rows, 'shipments' => $rows, 'page' => 1, 'pages' => 1, 'total' => count($rows)];
    }
}
