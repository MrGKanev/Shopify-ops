<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ShippingBillingAnalyzer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShippingBillingAnalyzerTest extends TestCase
{
    public function test_groups_same_shipment_separately_for_source_accounts_and_currencies(): void
    {
        $rows = $this->analyze([
            $this->entry(['amount' => '1']),
            $this->entry(['source_account' => 'second-account', 'amount' => '2']),
            $this->entry(['currency' => 'EUR', 'amount' => '3']),
        ]);

        $this->assertCount(3, $rows);
        $this->assertSame(['first-account', 'second-account', 'first-account'], array_column($rows, 'source_account'));
        $this->assertSame(['USD', 'USD', 'EUR'], array_column($rows, 'currency'));
        $this->assertSame(['1', '2', '3'], array_column($rows, 'observed_net'));
    }

    public function test_excluded_only_shipments_do_not_create_phantom_billing_groups(): void
    {
        $rows = $this->analyze([$this->entry(['excluded' => true])]);

        $this->assertSame([], $rows);
    }

    public function test_multiple_base_charges_preserve_observed_postings_but_do_not_establish_unambiguous_cost(): void
    {
        $rows = $this->analyze([
            $this->entry(['amount' => '10', 'expected_refund' => '10']),
            $this->entry(['amount' => '12']),
        ]);

        $this->assertSame(2, $rows[0]['base_count']);
        $this->assertSame('22', $rows[0]['observed_net']);
        $this->assertSame('review_required', $rows[0]['refund_status']);
        $this->assertNull($rows[0]['billed_cost']);
        $this->assertNull($rows[0]['expected_refund']);
    }

    public function test_reversal_greater_than_posted_refunds_requires_review_instead_of_claiming_a_credit(): void
    {
        $rows = $this->analyze([
            $this->entry(['expected_refund' => '10']),
            $this->entry(['type' => 'label_refund', 'amount' => '2']),
            $this->entry(['type' => 'label_refund_reversal', 'amount' => '3']),
        ]);

        $this->assertSame('review_required', $rows[0]['refund_status']);
        $this->assertSame('-1', $rows[0]['refunds']);
        $this->assertSame('11', $rows[0]['outstanding_refund']);
        $this->assertSame('11', $rows[0]['observed_net']);
    }

    public function test_refund_above_expectation_clamps_outstanding_to_zero_without_hiding_observed_money(): void
    {
        $rows = $this->analyze([
            $this->entry(['expected_refund' => '10']),
            $this->entry(['type' => 'label_refund', 'amount' => '12']),
        ]);

        $this->assertSame('credited', $rows[0]['refund_status']);
        $this->assertSame('0', $rows[0]['outstanding_refund']);
        $this->assertSame('12', $rows[0]['refunds']);
        $this->assertSame('-2', $rows[0]['observed_net']);
    }

    #[DataProvider('refundDeadlines')]
    public function test_refund_deadlines_use_local_calendar_day_and_require_the_due_date_to_have_passed(?string $due, string $expectedStatus): void
    {
        $rows = $this->analyze([
            $this->entry(['expected_refund' => '10', 'refund_due_at' => $due]),
        ], CarbonImmutable::parse('2026-10-25T00:30:00+03:00'));

        $this->assertSame($expectedStatus, $rows[0]['refund_status']);
    }

    public static function refundDeadlines(): array
    {
        return [
            'yesterday at local midnight boundary' => ['2026-10-24', 'overdue'],
            'due today is not overdue' => ['2026-10-25', 'awaiting_credit'],
            'due in future' => ['2026-10-26', 'awaiting_credit'],
            'missing deadline does not imply overdue' => [null, 'awaiting_credit'],
        ];
    }

    public function test_zero_expected_refund_is_distinct_from_no_recorded_expectation(): void
    {
        $rows = $this->analyze([
            $this->entry(['expected_refund' => '0']),
            $this->entry(['shipment_id' => 'other-label']),
        ]);

        $this->assertSame('no_refund_due', $rows[0]['refund_status']);
        $this->assertSame('0', $rows[0]['outstanding_refund']);
        $this->assertSame('expectation_not_set', $rows[1]['refund_status']);
        $this->assertNull($rows[1]['outstanding_refund']);
    }

    #[DataProvider('identityConflicts')]
    public function test_conflicting_order_identity_discards_refund_expectation_and_cost_coverage(array $override): void
    {
        $rows = $this->analyze([
            $this->entry(['expected_refund' => '10', 'refund_due_at' => '2026-10-01']),
            $this->entry(['type' => 'adjustment_debit', 'amount' => '1', ...$override]),
        ]);

        $this->assertTrue($rows[0]['identity_conflict']);
        $this->assertSame('review_required', $rows[0]['refund_status']);
        $this->assertNull($rows[0]['expected_refund']);
        $this->assertNull($rows[0]['refund_due_at']);
        $this->assertNull($rows[0]['billed_cost']);
    }

    public static function identityConflicts(): array
    {
        return [
            'same number different order id' => [['order_id' => 'other-order']],
            'same id different number' => [['order_number' => 'other-number']],
        ];
    }

    public function test_grouping_preserves_signed_totals_and_unknown_dimension_without_mixing_currencies(): void
    {
        $groups = $this->analyze([
            $this->entry(['amount' => '0.100000000001', 'carrier' => '']),
            $this->entry(['shipment_id' => 'second-label', 'amount' => '0.200000000002', 'carrier' => '']),
            $this->entry(['shipment_id' => 'third-label', 'currency' => 'EUR', 'carrier' => '', 'amount' => '7']),
            $this->entry(['shipment_id' => 'second-label', 'type' => 'adjustment_debit', 'amount' => '0.3', 'carrier' => '']),
            $this->entry(['shipment_id' => 'second-label', 'type' => 'adjustment_credit', 'amount' => '0.4', 'carrier' => '']),
            $this->entry(['shipment_id' => 'second-label', 'type' => 'label_refund', 'amount' => '0.5', 'carrier' => '']),
        ]);

        $rows = (new ShippingBillingAnalyzer)->grouped($groups, 'carrier');

        $this->assertCount(2, $rows);
        $this->assertSame(['label' => '', 'currency' => 'USD', 'labels' => 2, 'net' => '-0.299999999997', 'adjustments' => '0.3', 'credits' => '0.4', 'refunds' => '0.5'], $rows[0]);
        $this->assertSame('EUR', $rows[1]['currency']);
        $this->assertSame('7', $rows[1]['net']);
    }

    public function test_refund_status_follows_exact_credited_partial_and_overdue_amounts(): void
    {
        $due = fn (string $date): array => ['expected_refund' => '10', 'refund_due_at' => $date];
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');

        $exact = $this->analyze([$this->entry($due('2026-10-20')), $this->entry(['type' => 'label_refund', 'amount' => '10'])], $now);
        $partial = $this->analyze([$this->entry($due('2026-10-30')), $this->entry(['type' => 'label_refund', 'amount' => '4'])], $now);
        $partialLate = $this->analyze([$this->entry($due('2026-10-20')), $this->entry(['type' => 'label_refund', 'amount' => '4'])], $now);
        $reversedBackToDue = $this->analyze([$this->entry($due('2026-10-30')), $this->entry(['type' => 'label_refund', 'amount' => '10']), $this->entry(['type' => 'label_refund_reversal', 'amount' => '10'])], $now);

        $this->assertSame(['credited', '0'], [$exact[0]['refund_status'], (string) $exact[0]['outstanding_refund']]);
        $this->assertSame(['partially_credited', '6'], [$partial[0]['refund_status'], (string) $partial[0]['outstanding_refund']]);
        $this->assertSame(['overdue', '6'], [$partialLate[0]['refund_status'], (string) $partialLate[0]['outstanding_refund']]);
        $this->assertSame(['awaiting_credit', '10'], [$reversedBackToDue[0]['refund_status'], (string) $reversedBackToDue[0]['outstanding_refund']]);
    }

    public function test_negative_postings_are_never_presented_as_a_credit(): void
    {
        $rows = $this->analyze([$this->entry(['expected_refund' => '10']), $this->entry(['type' => 'label_refund', 'amount' => '-3'])]);

        $this->assertSame('review_required', $rows[0]['refund_status']);
    }

    public function test_benchmarks_report_median_coverage_and_source_shipments_per_sku_and_service(): void
    {
        $rows = $this->analyze([
            $this->entry(['shipment_id' => 'a', 'amount' => '10']),
            $this->entry(['shipment_id' => 'b', 'amount' => '20']),
            $this->entry(['shipment_id' => 'c', 'amount' => '40']),
            $this->entry(['shipment_id' => 'd', 'amount' => '30']),
            $this->entry(['shipment_id' => 'e', 'amount' => '99', 'service' => 'express']),
        ]);

        $benchmarks = (new ShippingBillingAnalyzer)->benchmarks($rows, 4);

        $this->assertCount(2, $benchmarks);
        $ground = $benchmarks[0];
        $this->assertSame(['single_sku', 'SKU-A', 'ground', 'USD', 4, 4], [$ground['basis'], $ground['skus'], $ground['service'], $ground['currency'], $ground['shipments'], $ground['sampled']]);
        $this->assertSame(['25.0000', '25.0000', '10', '40'], [$ground['median'], $ground['mean'], $ground['min'], $ground['max']]);
        $this->assertSame(1.0, $ground['coverage']);
        $this->assertFalse($ground['small_sample']);
        $this->assertSame(['a', 'b', 'c', 'd'], $ground['shipment_ids']);
        $this->assertTrue($benchmarks[1]['small_sample']);
    }

    public function test_benchmarks_never_mix_currencies_or_attribute_mixed_baskets_to_a_single_sku(): void
    {
        $rows = $this->analyze([
            $this->entry(['shipment_id' => 'a', 'amount' => '10']),
            $this->entry(['shipment_id' => 'b', 'amount' => '8', 'currency' => 'EUR']),
            $this->entry(['shipment_id' => 'c', 'amount' => '30', 'skus' => 'SKU-A;SKU-B']),
        ]);

        $benchmarks = (new ShippingBillingAnalyzer)->benchmarks($rows);

        $this->assertCount(3, $benchmarks);
        $byKey = array_column($benchmarks, null, 'skus');
        $this->assertSame('single_sku', $byKey['SKU-A']['basis']);
        $this->assertSame('sku_set', $byKey['SKU-A;SKU-B']['basis']);
        $currencies = array_column(array_filter($benchmarks, fn (array $row): bool => $row['skus'] === 'SKU-A'), 'currency');
        sort($currencies);
        $this->assertSame(['EUR', 'USD'], $currencies);
    }

    public function test_benchmarks_exclude_ambiguous_labels_from_the_sample_but_keep_them_in_coverage(): void
    {
        $rows = $this->analyze([
            $this->entry(['shipment_id' => 'a', 'amount' => '10']),
            $this->entry(['shipment_id' => 'b', 'amount' => '12']),
            $this->entry(['shipment_id' => 'b', 'amount' => '12']),
            $this->entry(['shipment_id' => 'c', 'amount' => '14', 'order_id' => 'order-2']),
            $this->entry(['shipment_id' => 'c', 'amount' => '1', 'type' => 'adjustment_debit', 'order_id' => 'order-2']),
        ]);

        $benchmarks = (new ShippingBillingAnalyzer)->benchmarks($rows, 1);

        $this->assertCount(1, $benchmarks);
        $this->assertSame(3, $benchmarks[0]['shipments']);
        $this->assertSame(2, $benchmarks[0]['sampled']);
        $this->assertSame(0.6667, $benchmarks[0]['coverage']);
        $this->assertSame('12.5000', $benchmarks[0]['median']);
        $this->assertSame(['a', 'c'], $benchmarks[0]['shipment_ids']);
    }

    public function test_benchmarks_report_unknown_statistics_when_nothing_is_sampled(): void
    {
        $rows = $this->analyze([$this->entry(['skus' => '', 'amount' => '10'])]);

        $benchmarks = (new ShippingBillingAnalyzer)->benchmarks($rows);

        $this->assertNull($benchmarks[0]['median']);
        $this->assertSame(0, $benchmarks[0]['sampled']);
        $this->assertTrue($benchmarks[0]['small_sample']);
    }

    /** @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>> */
    private function analyze(array $entries, ?CarbonImmutable $now = null): array
    {
        return (new ShippingBillingAnalyzer)->analyze($entries, $now ?? CarbonImmutable::parse('2026-10-25T12:00:00Z'));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function entry(array $overrides = []): array
    {
        return array_replace([
            'source_account' => 'first-account', 'shipment_id' => 'label-1', 'currency' => 'USD',
            'order_id' => 'order-1', 'order_number' => '1001', 'type' => 'label_charge',
            'amount' => '10', 'expected_refund' => null, 'refund_due_at' => null,
            'carrier' => 'ups', 'service' => 'ground', 'package' => 'box', 'skus' => 'SKU-A', 'tracking_number' => 'TRACK-1',
        ], $overrides);
    }
}
