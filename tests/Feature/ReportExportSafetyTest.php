<?php

namespace Tests\Feature;

use App\Application\Reports\RunActiveShipStationConflictReport;
use App\Application\Reports\RunAddressChangeReport;
use App\Application\Reports\RunBundleCheckReport;
use App\Application\Reports\RunFulfilledItemsReport;
use App\Application\Reports\RunFulfillmentSlaReport;
use App\Application\Reports\RunItemMismatchReport;
use App\Application\Reports\RunNoTrackingReport;
use App\Application\Reports\RunOnHoldStallReport;
use App\Application\Reports\RunOrphanOrderReport;
use App\Application\Reports\RunPartialFulfillmentReport;
use App\Application\Reports\RunPostShipAddressChangeReport;
use App\Application\Reports\RunReturnedItemsReport;
use App\Application\Reports\RunShipmentActivityReport;
use App\Application\Reports\RunShipmentAgingReport;
use App\Application\Reports\RunShippedUnfulfilledReport;
use App\Application\Reports\RunShippingMarginReport;
use App\Application\Reports\RunVoidedShipmentsReport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReportExportSafetyTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var array<string, string> */
    private const INPUT = ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'threshold' => '5'];

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function exports(): array
    {
        return [
            'active ShipStation conflicts' => ['reports.active-shipstation-conflicts.export', RunActiveShipStationConflictReport::class],
            'address changes' => ['reports.address-changes.export', RunAddressChangeReport::class],
            'bundle check' => ['reports.bundle-check.export', RunBundleCheckReport::class],
            'fulfilled items' => ['reports.fulfilled-items.export', RunFulfilledItemsReport::class],
            'fulfillment SLA' => ['reports.fulfillment-sla.export', RunFulfillmentSlaReport::class],
            'item mismatch' => ['reports.item-mismatch.export', RunItemMismatchReport::class],
            'no tracking' => ['reports.no-tracking.export', RunNoTrackingReport::class],
            'on-hold stall' => ['reports.on-hold-stall.export', RunOnHoldStallReport::class],
            'orphan orders' => ['reports.orphan-orders.export', RunOrphanOrderReport::class],
            'partial fulfillment' => ['reports.partial-fulfillment.export', RunPartialFulfillmentReport::class],
            'post-ship address changes' => ['reports.post-ship-address-changes.export', RunPostShipAddressChangeReport::class],
            'returned items' => ['reports.returned-items.export', RunReturnedItemsReport::class],
            'shipment activity' => ['reports.shipment-activity.export', RunShipmentActivityReport::class],
            'shipment aging' => ['reports.shipment-aging.export', RunShipmentAgingReport::class],
            'shipped unfulfilled' => ['reports.shipped-unfulfilled.export', RunShippedUnfulfilledReport::class],
            'shipping margin' => ['reports.shipping-margin.export', RunShippingMarginReport::class],
            'voided shipments' => ['reports.voided-shipments.export', RunVoidedShipmentsReport::class],
        ];
    }

    #[DataProvider('exports')]
    public function test_guest_and_read_only_users_cannot_export(string $route): void
    {
        $this->post(route($route), self::INPUT)->assertRedirect(route('login'));

        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->post(route($route), self::INPUT)->assertForbidden();
    }

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function exportsGuardingCredentials(): array
    {
        return array_diff_key(self::exports(), ['shipping margin' => true]);
    }

    #[DataProvider('exportsGuardingCredentials')]
    public function test_export_is_refused_when_store_credentials_are_missing(string $route, string $report): void
    {
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '', 'shipstation_api_key' => '']);
        $this->app->instance($report, Mockery::mock($report)->makePartial()->shouldNotReceive('handle')->getMock());

        $this->actingAs($operator)->post(route($route), self::INPUT)->assertSessionHasErrors('export');
    }

    #[DataProvider('exports')]
    public function test_export_failure_never_leaks_the_underlying_error(string $route, string $report): void
    {
        [$operator] = $this->userWithStore(true);
        $this->app->instance($report, Mockery::mock($report)->makePartial()->shouldReceive('handle')->andThrow(new RuntimeException('secret-token'))->getMock());

        $response = $this->actingAs($operator)->post(route($route), self::INPUT);

        $response->assertSessionHasErrors('export');
        $this->assertStringNotContainsString('secret-token', json_encode(session('errors')->getBag('default')->all(), JSON_THROW_ON_ERROR));
    }
}
