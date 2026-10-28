<?php

namespace Tests\Feature;

use App\Application\Operations\SyncAuditIssues;
use App\Application\Reports\RunAudit;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\IssueStatus;
use App\Models\OperationalIssue;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class RunAuditIssueRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('incompleteScans')]
    public function test_incomplete_scans_record_observed_missing_orders_without_resolving_existing_issues(bool $shopifyTruncated, bool $holdsTruncated): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        [$store, $existing] = $this->existingIssue();
        $existing->update(['status' => 'in_progress', 'due_date' => '2026-10-30']);
        $this->sources([$this->order(), $this->order(['name' => '#1002'])], [['orderNumber' => '1001']], $shopifyTruncated, $holdsTruncated);
        Notification::fake();

        $result = app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');

        $this->assertTrue($result->truncated);
        $this->assertSame(IssueStatus::InProgress, $existing->fresh()->status);
        $this->assertNull($existing->fresh()->resolved_at);
        $this->assertSame('2026-10-30', $existing->fresh()->due_date->toDateString());
        $this->assertDatabaseHas('operational_issues', ['store_id' => $store->id, 'source_tool' => 'run_audit', 'reference' => '#1002', 'status' => 'open']);
        $this->assertSame(1, $store->runLogs()->count());
    }

    public static function incompleteScans(): array
    {
        return ['orders truncated' => [true, false], 'holds truncated' => [false, true], 'both truncated' => [true, true]];
    }

    public function test_complete_scan_of_another_window_does_not_resolve_an_unobserved_order(): void
    {
        [$store, $issue] = $this->existingIssue();
        $this->sources([], []);
        Notification::fake();

        app(RunAudit::class)->handle($store, '2026-10-20', '2026-10-24');

        $this->assertSame(IssueStatus::Open, $issue->fresh()->status);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->assertSame(1, $issue->fresh()->occurrences);
    }

    public function test_confirmed_exact_recovery_resolves_only_the_observed_active_issue_in_its_store(): void
    {
        $this->travelTo('2026-10-25 12:00:00 UTC');
        [$store, $issue] = $this->existingIssue();
        [$foreignStore, $foreignIssue] = $this->existingIssue();
        app(SyncAuditIssues::class)->handle($store, [$this->order(['name' => '#1003'])]);
        $unobserved = $store->operationalIssues()->where('reference', '#1003')->sole();
        $this->sources([$this->order()], [['orderNumber' => '#1001']]);
        Notification::fake();
        $this->travel(1)->hour();

        app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
        $this->assertSame('2026-10-25 13:00:00', $issue->fresh()->resolved_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 12:00:00', $issue->fresh()->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame(IssueStatus::Open, $unobserved->fresh()->status);
        $this->assertSame(IssueStatus::Open, $foreignIssue->fresh()->status);
        $this->assertSame(0, $foreignStore->runLogs()->count());
    }

    public function test_fuzzy_email_and_amount_match_is_not_confirmation_of_the_missing_order_recovery(): void
    {
        [$store, $issue] = $this->existingIssue();
        $this->sources([$this->order()], [['orderNumber' => '9999', 'customerEmail' => 'jane@example.com', 'orderTotal' => 20]]);
        Notification::fake();

        $result = app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');

        $this->assertSame(1, $result->meta['found']);
        $this->assertSame(IssueStatus::Open, $issue->fresh()->status);
        $this->assertNull($issue->fresh()->resolved_at);
    }

    #[DataProvider('skippedOrders')]
    public function test_skipped_orders_do_not_prove_recovery_even_when_the_scan_is_complete(array $attributes): void
    {
        [$store, $issue] = $this->existingIssue();
        $this->sources([$this->order($attributes)], []);
        Notification::fake();

        $result = app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');

        $this->assertSame(1, $result->meta['skipped']);
        $this->assertSame(IssueStatus::Open, $issue->fresh()->status);
        $this->assertNull($issue->fresh()->resolved_at);
    }

    public static function skippedOrders(): array
    {
        return ['cancelled' => [['cancelled_at' => '2026-10-23T12:00:00Z']], 'refunded' => [['financial_status' => 'refunded']], 'fulfilled' => [['fulfillment_status' => 'fulfilled']], 'zero value' => [['total_price' => 0]], 'no shipping' => [['shipping_lines' => []]]];
    }

    public function test_confirmed_order_does_not_change_an_explicitly_ignored_issue(): void
    {
        [$store, $issue] = $this->existingIssue();
        $issue->update(['status' => 'ignored']);
        $this->sources([$this->order()], [['orderNumber' => '1001']]);
        Notification::fake();

        app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');

        $this->assertSame(IssueStatus::Ignored, $issue->fresh()->status);
        $this->assertNull($issue->fresh()->resolved_at);
    }

    public function test_foreign_store_order_with_same_number_cannot_hide_or_resolve_a_missing_order(): void
    {
        [$store, $issue] = $this->existingIssue();
        $this->sources([$this->order()], [ShipStationFixtures::lookupOrder(0, '1001', 99)]);
        Notification::fake();

        $result = app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');

        $this->assertSame(0, $result->meta['found']);
        $this->assertSame(0, $result->meta['shipstationTotal']);
        $this->assertSame('#1001', $result->rows[0]['name']);
        $this->assertSame(IssueStatus::Open, $issue->fresh()->status);
        $this->assertSame(2, $issue->fresh()->occurrences);
        $this->assertNull($issue->fresh()->resolved_at);
    }

    #[DataProvider('unverifiedShipStationOrders')]
    public function test_unverified_shipstation_ownership_or_identity_fails_without_changing_existing_issues(array $attributes): void
    {
        [$store, $issue] = $this->existingIssue();
        $this->sources([$this->order()], [['orderNumber' => '1001', ...$attributes]]);
        Notification::fake();

        try {
            $this->expectException(UnexpectedResponse::class);
            app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');
        } finally {
            $this->assertSame(IssueStatus::Open, $issue->fresh()->status);
            $this->assertSame(1, $issue->fresh()->occurrences);
            $this->assertNull($issue->fresh()->resolved_at);
            $this->assertDatabaseCount('audit_snapshots', 0);
            $this->assertDatabaseHas('run_logs', ['store_id' => $store->id, 'tool' => 'run_audit', 'status' => 'error']);
            Notification::assertNothingSent();
        }
    }

    public static function unverifiedShipStationOrders(): array
    {
        return [
            'missing ownership' => [['advancedOptions' => []]],
            'null ownership' => [['advancedOptions' => ['storeId' => null]]],
            'boolean ownership' => [['advancedOptions' => ['storeId' => true]]],
            'float ownership' => [['advancedOptions' => ['storeId' => 42.0]]],
            'zero ownership' => [['advancedOptions' => ['storeId' => 0]]],
            'invalid order id' => [['orderId' => 0]],
            'missing order number' => [['orderNumber' => '']],
        ];
    }

    #[DataProvider('nonExactRecoveryMatches')]
    public function test_fragment_and_ambiguous_number_matches_do_not_automatically_resolve_issues(array $matches): void
    {
        [$store, $issue] = $this->existingIssue();
        $this->sources([$this->order()], $matches);
        Notification::fake();

        $result = app(RunAudit::class)->handle($store, '2026-10-01', '2026-10-24');

        $this->assertSame(1, $result->meta['found']);
        $this->assertSame(IssueStatus::Open, $issue->fresh()->status);
        $this->assertNull($issue->fresh()->resolved_at);
    }

    public static function nonExactRecoveryMatches(): array
    {
        return ['combined number fragment' => [[['orderNumber' => '1001-1002']]], 'multiple matching records' => [[['orderId' => 1, 'orderNumber' => '1001'], ['orderId' => 2, 'orderNumber' => '1001']]]];
    }

    /** @return array{Store, OperationalIssue} */
    private function existingIssue(): array
    {
        $store = Store::factory()->create(['store_number' => '42']);
        app(SyncAuditIssues::class)->handle($store, [$this->order()]);

        return [$store, $store->operationalIssues()->sole()];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed> */
    private function order(array $attributes = []): array
    {
        return ['id' => '1', 'name' => '#1001', 'created_at' => '2026-10-10T12:00:00Z', 'email' => 'jane@example.com', 'total_price' => 20, 'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled', ...$attributes];
    }

    /** @param list<array<string, mixed>> $orders
     * @param list<array<string, mixed>> $shipments */
    private function sources(array $orders, array $shipments, bool $shopifyTruncated = false, bool $holdsTruncated = false): void
    {
        $client = ShipStationFixtures::bindClient(1);
        $client->shouldReceive('fetchAllOrders')->once()->andReturn(array_map(fn (array $shipment): array => ['orderId' => 1, 'advancedOptions' => ['storeId' => 42], ...$shipment], $shipments));
        $shopify = $this->mock(ShopifyOrders::class);
        $shopify->shouldReceive('itemMismatchCandidates')->once()->andReturn(['orders' => $orders, 'pages' => 1, 'truncated' => $shopifyTruncated]);
        $shopify->shouldReceive('onHoldFulfillmentCandidates')->once()->andReturn(['fulfillment_orders' => [], 'pages' => 1, 'truncated' => $holdsTruncated]);
    }
}
