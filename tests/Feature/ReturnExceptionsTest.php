<?php

namespace Tests\Feature;

use App\Application\Operations\RaiseOperationalIssue;
use App\Application\Reports\RunReturnRmaReport;
use App\Domain\Reports\ReturnExceptionAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Integrations\Shopify\ShopifyReturns;
use App\Models\OperationalIssue;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReturnExceptionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_request_deadline_excludes_weekends_and_uses_shop_timezone(): void
    {
        $return = $this->return(['status' => 'REQUESTED', 'createdAt' => '2026-10-02T16:00:00Z']);
        $analyzer = new ReturnExceptionAnalyzer;
        $policy = ['approval_days' => 2, 'processing_days' => 3, 'exchange_days' => 5];
        $this->assertSame([], $analyzer->analyze([$return], $policy, CarbonImmutable::parse('2026-10-05T20:00:00Z')->setTimezone('America/New_York')));
        $this->assertSame([], $analyzer->analyze([$return], $policy, CarbonImmutable::parse('2026-10-06T16:00:00Z')->setTimezone('America/New_York')));

        $rows = $analyzer->analyze([$return], $policy, CarbonImmutable::parse('2026-10-06T16:01:00Z')->setTimezone('America/New_York'));

        $this->assertSame('approval_overdue', $rows[0]['kind']);
        $this->assertSame('2026-10-06T12:00:00-04:00', $rows[0]['due_at']);
    }

    public function test_received_unprocessed_items_require_warehouse_dispositions_and_preserve_quantities(): void
    {
        $return = $this->return();
        $return['reverse_lines'] = $this->receipts();

        $rows = $this->analyze([$return]);

        $this->assertSame('processing_overdue', $rows[0]['kind']);
        $this->assertSame(2, $rows[0]['quantity']);
        $this->assertTrue($rows[0]['receipt_confirmed']);
        $this->assertSame('2026-09-30T12:00:00+00:00', $rows[0]['due_at']);
    }

    #[TestWith(['MISSING', 'gid://shopify/Location/1'])]
    #[TestWith(['PROCESSING_REQUIRED', null])]
    public function test_missing_items_and_dispositions_without_a_location_are_not_receipts(string $type, ?string $location): void
    {
        $return = $this->return();
        $return['reverse_lines'] = $this->receipts();
        $return['reverse_lines'][0]['dispositions'][0]['type'] = $type;
        $return['reverse_lines'][0]['dispositions'][0]['location'] = $location === null ? null : ['id' => $location];

        $this->assertSame([], $this->analyze([$return]));
    }

    public function test_labels_delivery_scans_and_old_approvals_do_not_imply_warehouse_receipt(): void
    {
        $return = $this->return(['tracking' => ['number' => 'TRACK', 'status' => 'DELIVERED'], 'label' => ['id' => 5]]);

        $this->assertSame([], $this->analyze([$return]));
    }

    public function test_already_processed_partial_receipts_do_not_raise_a_false_processing_issue(): void
    {
        $return = $this->return();
        $return['reverse_lines'] = $this->receipts();
        $return['reverse_lines'][0]['dispositions'][0]['quantity'] = 1;
        $return['return_lines'][0]['processedQuantity'] = 1;
        $return['return_lines'][0]['unprocessedQuantity'] = 1;

        $this->assertSame([], $this->analyze([$return]));
    }

    public function test_recent_receipts_do_not_hide_older_overdue_items_or_count_as_overdue_themselves(): void
    {
        $return = $this->return();
        $return['reverse_lines'] = $this->receipts();
        $newLine = $return['return_lines'][0];
        $newLine['id'] = 'gid://shopify/ReturnLineItem/2';
        $newLine['fulfillmentLineItem']['id'] = 'gid://shopify/FulfillmentLineItem/2';
        $return['return_lines'][] = $newLine;
        $newReceipt = $return['reverse_lines'][0];
        $newReceipt['fulfillmentLineItem']['id'] = 'gid://shopify/FulfillmentLineItem/2';
        $newReceipt['dispositions'][0]['id'] = 'gid://shopify/ReverseFulfillmentOrderDisposition/2';
        $newReceipt['dispositions'][0]['createdAt'] = '2026-10-05T12:00:00Z';
        $return['reverse_lines'][] = $newReceipt;

        $rows = $this->analyze([$return]);

        $this->assertSame(2, $rows[0]['quantity']);
        $this->assertSame('2026-09-30T12:00:00+00:00', $rows[0]['due_at']);
    }

    public function test_duplicate_dispositions_do_not_create_extra_received_units(): void
    {
        $return = $this->return();
        $return['return_lines'][0]['processedQuantity'] = 1;
        $return['return_lines'][0]['unprocessedQuantity'] = 1;
        $return['reverse_lines'] = $this->receipts();
        $return['reverse_lines'][0]['dispositions'][0]['quantity'] = 1;
        $return['reverse_lines'][0]['dispositions'][] = $return['reverse_lines'][0]['dispositions'][0];

        $this->assertSame([], $this->analyze([$return]));
    }

    public function test_closed_return_with_pending_physical_exchange_still_requires_shipping(): void
    {
        $return = $this->return(['status' => 'CLOSED', 'requestApprovedAt' => null]);
        $return['exchange_lines'] = $this->exchanges();

        $rows = $this->analyze([$return]);

        $this->assertSame('exchange_overdue', $rows[0]['kind']);
        $this->assertSame('Fulfill exchange items', $rows[0]['next_action']);
        $this->assertSame(1, $rows[0]['quantity']);
    }

    public function test_planned_exchanges_start_processing_deadline_only_after_receipt(): void
    {
        $return = $this->return();
        $return['exchange_lines'] = [['id' => 'gid://shopify/ExchangeLineItem/1', 'quantity' => 1, 'processedQuantity' => 0, 'unprocessedQuantity' => 1, 'lineItems' => []]];
        $this->assertSame([], $this->analyze([$return]));
        $return['reverse_lines'] = $this->receipts();
        $rows = $this->analyze([$return]);
        $this->assertSame(['processing_overdue', 'exchange_overdue'], array_column($rows, 'kind'));
        $this->assertSame('Process exchange after receipt', $rows[1]['next_action']);
    }

    #[TestWith(['CANCELED'])]
    #[TestWith(['DECLINED'])]
    public function test_cancelled_and_declined_returns_do_not_raise_exceptions(string $status): void
    {
        $return = $this->return(['status' => $status]);
        $return['reverse_lines'] = $this->receipts();
        $return['exchange_lines'] = $this->exchanges();
        $this->assertSame([], $this->analyze([$return]));
    }

    public function test_fulfilled_removed_and_digital_exchange_items_are_not_shipping_exceptions(): void
    {
        $return = $this->return(['status' => 'CLOSED']);
        $return['exchange_lines'] = $this->exchanges();
        $return['exchange_lines'][0]['lineItems'] = [
            ['id' => 'a', 'requiresShipping' => true, 'unfulfilledQuantity' => 0, 'currentQuantity' => 1],
            ['id' => 'b', 'requiresShipping' => true, 'unfulfilledQuantity' => 1, 'currentQuantity' => 0],
            ['id' => 'c', 'requiresShipping' => false, 'unfulfilledQuantity' => 1, 'currentQuantity' => 1],
        ];
        $this->assertSame([], $this->analyze([$return]));
    }

    public function test_report_creates_deduplicated_store_scoped_issues_and_resolves_a_confirmed_completed_return(): void
    {
        $this->travelTo('2026-10-06 12:00:00');
        $store = Store::factory()->create();
        $return = $this->return(['status' => 'REQUESTED']);
        $this->fakeSources($return);
        $other = OperationalIssue::factory()->for(Store::factory())->create(['source_tool' => 'return_exceptions', 'reference' => $return['id']]);
        $report = app(RunReturnRmaReport::class);

        $report->handle($store, '2026-09-01', '2026-10-06');
        $report->handle($store, '2026-09-01', '2026-10-06');
        $issue = $store->operationalIssues()->sole();
        $this->assertSame(1, $issue->occurrences);
        $this->assertSame('normal', $issue->priority->value);
        $return['status'] = 'CLOSED';
        $report->handle($store, '2026-09-01', '2026-10-06');
        $this->assertSame('resolved', $issue->fresh()->status->value);
        $this->assertSame('open', $other->fresh()->status->value);
    }

    public function test_incomplete_or_out_of_range_returns_do_not_resolve_existing_issues(): void
    {
        $this->travelTo('2026-10-06 12:00:00');
        $store = Store::factory()->create();
        $issue = app(RaiseOperationalIssue::class)->handle($store, ['source_tool' => 'return_exceptions', 'reference' => 'gid://shopify/Return/1', 'fingerprint' => RaiseOperationalIssue::fingerprint('return_exceptions', '1|approval_overdue'), 'title' => 'Waiting', 'priority' => 'normal']);
        $this->mock(ShopifyPayments::class)->shouldReceive('refundTrackerCandidates')->andReturn(['orders' => [], 'pages' => 1, 'truncated' => false]);
        $this->mock(ShopifyReturns::class)->shouldReceive('candidates')->andReturn(['returns' => [], 'pages' => 20, 'truncated' => true]);

        $result = app(RunReturnRmaReport::class)->handle($store, '2026-10-01', '2026-10-06');

        $this->assertTrue($result->truncated);
        $this->assertSame('open', $issue->fresh()->status->value);
    }

    public function test_report_renders_returns_with_safe_links_and_keeps_refunds_separate(): void
    {
        $this->travelTo('2026-10-06 12:00:00');
        [$operator, $store] = $this->userWithStore(true);
        $return = $this->return(['status' => 'REQUESTED', 'name' => '<script>return</script>']);
        $this->fakeSources($return);

        $this->actingAs($operator)->post(route('reports.return-rma.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-10-06'])
            ->assertSeeText('1 return exceptions from 1 returns')->assertSeeText('Review return request')->assertSee('id="return-1-approval_overdue"', false)->assertDontSee('<script>return</script>', false)->assertSeeText('Refund history');
        $this->get(route('operational-issues.index'))->assertSeeText('Open return exception')->assertSee('#return-1-approval_overdue', false);
        $this->assertSame('gid://shopify/Return/1', $store->operationalIssues()->sole()->reference);
    }

    public function test_a_refund_without_a_return_is_not_an_exception(): void
    {
        $store = Store::factory()->create();
        $this->mock(ShopifyPayments::class)->shouldReceive('refundTrackerCandidates')->andReturn(['orders' => [['id' => 1, 'name' => '#1', 'refunds' => [['created_at' => '2026-10-01', 'note' => 'Goodwill', 'total_refunded' => 10]]]], 'pages' => 1, 'truncated' => false]);
        $this->mock(ShopifyReturns::class)->shouldReceive('candidates')->andReturn(['returns' => [], 'pages' => 1, 'truncated' => false]);
        $result = app(RunReturnRmaReport::class)->handle($store, '2026-10-01', '2026-10-06');
        $this->assertSame([], $result->rows);
        $this->assertSame('Goodwill', $result->meta['refundRows'][0]['reason']);
        $this->assertDatabaseCount('operational_issues', 0);
    }

    public function test_store_policy_can_be_saved_and_invalid_thresholds_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $input = ['slug' => $store->slug, 'label' => $store->label, 'shopify_store' => $store->shopify_store, 'delivery_watch_days' => 5, 'return_exception_policy' => ['approval_days' => '4', 'processing_days' => '7', 'exchange_days' => '9']];
        $this->actingAs($admin)->put(route('admin.stores.update', $store), $input)->assertSessionHas('status', 'Store updated.');
        $this->assertSame(['approval_days' => 4, 'processing_days' => 7, 'exchange_days' => 9], $store->fresh()->returnExceptionPolicy());
        $input['return_exception_policy']['approval_days'] = 0;
        $this->put(route('admin.stores.update', $store), $input)->assertSessionHasErrors('return_exception_policy.approval_days');
        $this->assertSame(4, $store->fresh()->returnExceptionPolicy()['approval_days']);
        $this->get(route('admin.stores.edit', $store))->assertSeeText('Return exception deadlines');
    }

    public function test_viewer_cannot_run_return_scans(): void
    {
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->post(route('reports.return-rma.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-10-06'])->assertForbidden();
    }

    /** @param array<string, mixed> $return */
    private function fakeSources(array &$return): void
    {
        $this->mock(ShopifyPayments::class)->shouldReceive('refundTrackerCandidates')->andReturn(['orders' => [], 'pages' => 1, 'truncated' => false]);
        $this->mock(ShopifyReturns::class)->shouldReceive('candidates')->andReturnUsing(function () use (&$return): array {
            return ['returns' => [$return], 'pages' => 1, 'truncated' => false];
        });
    }

    /** @param list<array<string, mixed>> $returns
     * @return list<array<string, mixed>> */
    private function analyze(array $returns): array
    {
        return (new ReturnExceptionAnalyzer)->analyze($returns, ['approval_days' => 2, 'processing_days' => 3, 'exchange_days' => 5], CarbonImmutable::parse('2026-10-06T12:00:00Z'));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function return(array $overrides = []): array
    {
        return array_replace(['id' => 'gid://shopify/Return/1', 'name' => 'RMA1', 'status' => 'OPEN', 'createdAt' => '2026-09-24T12:00:00Z', 'requestApprovedAt' => '2026-09-25T12:00:00Z', 'order' => ['legacyResourceId' => 42, 'name' => '#1001'], 'return_lines' => [['id' => 'gid://shopify/ReturnLineItem/1', 'quantity' => 2, 'processedQuantity' => 0, 'unprocessedQuantity' => 2, 'fulfillmentLineItem' => ['id' => 'gid://shopify/FulfillmentLineItem/1']]], 'reverse_lines' => [], 'exchange_lines' => []], $overrides);
    }

    /** @return list<array<string, mixed>> */
    private function receipts(): array
    {
        return [['id' => 'gid://shopify/ReverseFulfillmentOrderLineItem/1', 'fulfillmentLineItem' => ['id' => 'gid://shopify/FulfillmentLineItem/1'], 'totalQuantity' => 2, 'dispositions' => [['id' => 'gid://shopify/ReverseFulfillmentOrderDisposition/1', 'type' => 'PROCESSING_REQUIRED', 'quantity' => 2, 'createdAt' => '2026-09-25T12:00:00Z', 'location' => ['id' => 'gid://shopify/Location/1']]]]];
    }

    /** @return list<array<string, mixed>> */
    private function exchanges(): array
    {
        return [['id' => 'gid://shopify/ExchangeLineItem/1', 'quantity' => 1, 'processedQuantity' => 1, 'unprocessedQuantity' => 0, 'lineItems' => [['id' => 'gid://shopify/LineItem/2', 'requiresShipping' => true, 'unfulfilledQuantity' => 1, 'currentQuantity' => 1]]]];
    }
}
