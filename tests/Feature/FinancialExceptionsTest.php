<?php

namespace Tests\Feature;

use App\Domain\Reports\ExactDecimalAmount;
use App\Domain\Reports\PayoutAnomalyAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\ShopifyPayouts;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use UnexpectedValueException;

class FinancialExceptionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_manual_form_does_not_query_payments_or_create_runs(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->mock(ShopifyPayouts::class)->shouldNotReceive('collect')->shouldNotReceive('choices');

        $this->actingAs($operator)->get(route('reports.financial-exceptions'))->assertOk()->assertSeeText('Financial Exceptions')->assertSeeText('not bank reconciliation');

        $this->assertDatabaseCount('report_runs', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['payout_id', '1 OR id:2'])]
    #[TestWith(['max_age_days', 0])]
    #[TestWith(['adjustment_threshold', '-1'])]
    #[TestWith(['tolerance', '1e1000000000'])]
    #[TestWith(['tolerance', '0.1234567'])]
    public function test_invalid_policy_does_not_start_a_report(string $field, mixed $value): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->post(route('reports.financial-exceptions.store'), [...$this->policy(), $field => $value])->assertSessionHasErrors($field);

        $this->assertDatabaseCount('report_runs', 0);
    }

    public function test_missing_shopify_credentials_prevent_payments_calls(): void
    {
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->mock(ShopifyPayouts::class)->shouldNotReceive('collect');

        $this->actingAs($operator)->post(route('reports.financial-exceptions.store'), $this->policy())->assertSeeText('credentials are incomplete');

        $this->assertDatabaseCount('report_runs', 0);
    }

    public function test_net_sum_uses_signed_components_and_never_double_counts_fees_or_order_breakdowns(): void
    {
        $data = $this->data();
        $data['transactions'] = [
            $this->transaction('1', 'CHARGE', '100.00', '3.00', '97.00'),
            $this->transaction('2', 'REFUND', '-10.00', '0.00', '-10.00'),
            [...$this->transaction('3', 'ADJUSTMENT', '5.00', '0.00', '5.00'), 'adjustmentsOrders' => [['name' => '#1001', 'amount' => ['amount' => '5.00', 'currencyCode' => 'EUR']]]],
            $this->transaction('4', 'RESERVED_FUNDS', '-2.00', '0.00', '-2.00'),
            $this->transaction('5', 'TRANSFER', '-90.00', '0.00', '-90.00'),
        ];
        $result = $this->analyze($data);

        $this->assertTrue($result['summary']['complete']);
        $this->assertSame('90.00', $result['summary']['confirmed_component_net']);
        $this->assertSame('3.00', $result['summary']['observed_fees']);
        $this->assertSame('0.00', $result['summary']['delta']);
        $this->assertSame([], $result['rows']);
    }

    public function test_settlement_dates_do_not_need_to_match_order_transaction_dates(): void
    {
        $data = $this->data();
        $data['transactions'][0]['transactionDate'] = '2026-10-01T10:00:00Z';
        $data['payout']['issuedAt'] = '2026-10-07T10:00:00Z';

        $this->assertSame([], $this->analyze($data)['rows']);
    }

    #[TestWith(['FAILED'])]
    #[TestWith(['CANCELED'])]
    public function test_failed_and_canceled_payouts_are_exceptions_without_claiming_bank_receipt(string $status): void
    {
        $data = $this->data();
        $data['payout']['status'] = $status;
        $result = $this->analyze($data);

        $this->assertSame('payout_status', $result['rows'][0]['code']);
        $this->assertNull($result['summary']['delta']);
        $this->assertFalse($result['summary']['comparison_eligible']);
    }

    #[TestWith(['SCHEDULED'])]
    #[TestWith(['IN_TRANSIT'])]
    public function test_pending_age_uses_issue_date_and_the_explicit_limit(string $status): void
    {
        $data = $this->data();
        $data['payout']['status'] = $status;
        $data['payout']['issuedAt'] = '2026-09-20T00:00:00Z';

        $this->assertSame('pending_age', $this->analyze($data)['rows'][0]['code']);
        $data['payout']['issuedAt'] = '2026-10-10T00:00:00Z';
        $this->assertSame([], $this->analyze($data)['rows']);
    }

    public function test_large_adjustment_rule_uses_absolute_amount_and_can_be_disabled(): void
    {
        $data = $this->data();
        $data['transactions'] = [$this->transaction('1', 'ADJUSTMENT', '-120.00', '0.00', '-120.00')];
        $data['payout']['net']['amount'] = '-120.00';
        $result = $this->analyze($data, [...$this->policy(), 'adjustment_threshold' => '100']);

        $this->assertSame('large_adjustment', $result['rows'][0]['code']);
        $this->assertSame([], $this->analyze($data, [...$this->policy(), 'adjustment_threshold' => null])['rows']);
        $this->assertSame([], $this->analyze($data, [...$this->policy(), 'adjustment_threshold' => '120'])['rows']);
    }

    public function test_decimal_tolerance_comparison_is_exact_without_float_arithmetic(): void
    {
        $data = $this->data();
        $data['payout']['net']['amount'] = '0.3';
        $data['transactions'] = [$this->transaction('1', 'CHARGE', '0.1', '0', '0.1'), $this->transaction('2', 'CHARGE', '0.2', '0', '0.2')];

        $this->assertSame('0.0', $this->analyze($data, [...$this->policy(), 'tolerance' => '0'])['summary']['delta']);
        $data['payout']['net']['amount'] = '0.289999';
        $result = $this->analyze($data);
        $this->assertSame('net_difference', $result['rows'][0]['code']);
        $this->assertSame('0.010001', $result['summary']['delta']);
    }

    #[TestWith(['truncated'])]
    #[TestWith(['mixed_currency'])]
    #[TestWith(['missing_fee'])]
    #[TestWith(['unknown_type'])]
    #[TestWith(['test_mode'])]
    #[TestWith(['duplicate'])]
    public function test_incomplete_financial_data_never_produces_a_confirmed_difference(string $case): void
    {
        $data = $this->data();
        $data['payout']['net']['amount'] = '999';
        if ($case === 'truncated') {
            $data['complete'] = false;
        } elseif ($case === 'mixed_currency') {
            $data['transactions'][0]['net']['currencyCode'] = 'USD';
        } elseif ($case === 'missing_fee') {
            unset($data['transactions'][0]['fee']);
        } elseif ($case === 'unknown_type') {
            $data['transactions'][0]['type'] = 'FUTURE_UNKNOWN_TYPE';
        } elseif ($case === 'test_mode') {
            $data['transactions'][0]['test'] = true;
        } else {
            $data['transactions'][] = $data['transactions'][0];
        }
        $result = $this->analyze($data);

        $this->assertFalse($result['summary']['complete']);
        $this->assertNull($result['summary']['delta']);
        $this->assertNull($result['summary']['confirmed_component_net']);
        $this->assertSame([], $result['rows']);
    }

    public function test_adjustments_without_order_links_are_not_assumed_to_be_errors(): void
    {
        $data = $this->data();
        $data['transactions'][0]['type'] = 'ADJUSTMENT';
        $data['transactions'][0]['associatedOrder'] = null;
        $data['transactions'][0]['sourceOrderTransactionId'] = null;

        $this->assertSame([], $this->analyze($data)['rows']);
        $this->assertTrue($this->analyze($data)['summary']['complete']);
    }

    public function test_withdrawals_are_not_compared_using_a_deposit_sign_assumption(): void
    {
        $data = $this->data();
        $data['payout']['transactionType'] = 'WITHDRAWAL';
        $result = $this->analyze($data);

        $this->assertNull($result['summary']['delta']);
        $this->assertFalse($result['summary']['comparison_eligible']);
    }

    public function test_untrusted_decimal_notation_is_rejected_and_large_values_remain_exact(): void
    {
        $amounts = app(ExactDecimalAmount::class);

        $this->assertNull($amounts->parse('1e1000000000'));
        $this->assertNull($amounts->parse(0.1));
        $this->assertSame('999999999999999999.123456789012', (string) $amounts->parse('999999999999999999.123456789012'));
    }

    public function test_collector_filters_by_payout_identity_and_reads_all_transaction_pages(): void
    {
        $store = Store::factory()->make();
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->twice()->withArgs(fn (Store $selected, string $query, array $variables): bool => $selected === $store && str_contains($query, 'query FinancialPayouts(') && $variables['query'] === 'id:77')->andReturn($this->payoutResponse());
        $transport->shouldReceive('graphql')->once()->withArgs(fn (Store $selected, string $query, array $variables): bool => $variables === ['query' => 'payments_transfer_id:77', 'after' => null])->andReturn($this->transactionResponse([$this->transaction()], true, 'next'));
        $transport->shouldReceive('graphql')->once()->withArgs(fn (Store $selected, string $query, array $variables): bool => $variables === ['query' => 'payments_transfer_id:77', 'after' => 'next'])->andReturn($this->transactionResponse([$this->transaction('2', 'REFUND', '-1', '0', '-1')]));

        $data = app(ShopifyPayouts::class)->collect($store, '77');

        $this->assertTrue($data['complete']);
        $this->assertSame(2, $data['pages']);
        $this->assertCount(2, $data['transactions']);
    }

    #[TestWith(['foreign'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['missing_pagination'])]
    #[TestWith(['stalled_cursor'])]
    public function test_collector_rejects_unowned_or_unconfirmed_transaction_coverage(string $case): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->withArgs(fn (Store $selected, string $query): bool => str_contains($query, 'query FinancialPayouts('))->andReturn($this->payoutResponse());
        $transaction = $this->transaction();
        if ($case === 'foreign') {
            $transaction['associatedPayout']['id'] = 'gid://shopify/ShopifyPaymentsPayout/999';
        }
        $response = $this->transactionResponse($case === 'duplicate' ? [$transaction, $transaction] : [$transaction]);
        if ($case === 'missing_pagination') {
            unset($response['data']['shopifyPaymentsAccount']['balanceTransactions']['pageInfo']);
        } elseif ($case === 'stalled_cursor') {
            $response = $this->transactionResponse([], true, 'same');
        }
        $transport->shouldReceive('graphql')->withArgs(fn (Store $selected, string $query): bool => str_contains($query, 'FinancialPayoutTransactions'))->andReturn($response);

        $this->expectException(UnexpectedValueException::class);
        app(ShopifyPayouts::class)->collect(Store::factory()->make(), '77');
    }

    public function test_payout_changed_during_scan_is_marked_incomplete(): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $changed = $this->payoutResponse();
        $changed['data']['shopifyPaymentsAccount']['payouts']['nodes'][0]['net']['amount'] = '91.00';
        $transport->shouldReceive('graphql')->withArgs(fn (Store $selected, string $query): bool => str_contains($query, 'query FinancialPayouts('))->andReturn($this->payoutResponse(), $changed);
        $transport->shouldReceive('graphql')->withArgs(fn (Store $selected, string $query): bool => str_contains($query, 'FinancialPayoutTransactions'))->andReturn($this->transactionResponse([$this->transaction()]));

        $data = app(ShopifyPayouts::class)->collect(Store::factory()->make(), '77');

        $this->assertFalse($data['complete']);
        $this->assertSame(['Payout changed during the scan; rerun before comparing totals.'], $data['notes']);
    }

    public function test_payments_unavailable_is_not_reported_as_zero_payouts(): void
    {
        $this->mock(ShopifyTransport::class)->shouldReceive('graphql')->once()->andReturn(['data' => ['shopifyPaymentsAccount' => null]]);

        $this->expectException(UnexpectedValueException::class);
        app(ShopifyPayouts::class)->choices(Store::factory()->make());
    }

    public function test_report_is_store_scoped_and_escapes_financial_details(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['shipstation_api_key' => '', 'shipstation_api_secret' => '']);
        $data = $this->data();
        $data['transactions'][0]['adjustmentReason'] = '<script>alert(1)</script>';
        $this->mock(ShopifyPayouts::class)->shouldReceive('collect')->once()->withArgs(fn (Store $selected, string $id): bool => $selected->is($store) && $id === '77')->andReturn($data);

        $this->actingAs($operator)->post(route('reports.financial-exceptions.store'), $this->policy())->assertOk()->assertSeeText('Payout #77')->assertSeeText('90.00 EUR')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);

        $this->assertDatabaseHas('report_runs', ['store_id' => $store->id, 'tool' => 'financial_exceptions', 'status' => 'completed']);
        Http::assertNothingSent();
    }

    public function test_payout_selection_does_not_run_anomaly_checks_until_one_is_chosen(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->mock(ShopifyPayouts::class)->shouldReceive('choices')->once()->andReturn(['payouts' => [$this->data()['payout']], 'next_after' => null])->shouldNotReceive('collect');

        $this->actingAs($operator)->post(route('reports.financial-exceptions.store'), [...$this->policy(), 'payout_id' => null])->assertOk()->assertSeeText('Inspect payout');
    }

    public function test_page_limit_preserves_observed_records_but_marks_coverage_incomplete(): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->twice()->withArgs(fn (Store $selected, string $query): bool => str_contains($query, 'query FinancialPayouts('))->andReturn($this->payoutResponse());
        $page = 0;
        $transport->shouldReceive('graphql')->times(20)->withArgs(fn (Store $selected, string $query): bool => str_contains($query, 'FinancialPayoutTransactions'))->andReturnUsing(function () use (&$page): array {
            $page++;

            return $this->transactionResponse([$this->transaction((string) $page)], true, 'page-'.$page);
        });

        $data = app(ShopifyPayouts::class)->collect(Store::factory()->make(), '77');

        $this->assertFalse($data['complete']);
        $this->assertSame(20, $data['pages']);
        $this->assertCount(20, $data['transactions']);
        $this->assertContains('Transaction coverage was truncated; totals cannot be confirmed.', $data['notes']);
    }

    public function test_policy_thresholds_and_amounts_are_localized_without_exposing_api_failures(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        app()->setLocale('bg');
        $this->actingAs($operator)->get(route('reports.financial-exceptions'))->assertOk()->assertSeeText('Финансови изключения');
        $this->mock(ShopifyPayouts::class)->shouldReceive('collect')->once()->andThrow(new \RuntimeException('private-access-token'));

        $this->post(route('reports.financial-exceptions.store'), $this->policy())->assertOk()->assertSeeText('Проверете достъпността на Shopify Payments')->assertDontSeeText('private-access-token');

        $this->assertDatabaseHas('report_runs', ['store_id' => $store->id, 'status' => 'failed']);
    }

    public function test_next_payout_page_passes_only_the_requested_cursor_to_the_active_store(): void
    {
        $store = Store::factory()->make();
        $this->mock(ShopifyTransport::class)->shouldReceive('graphql')->once()->withArgs(fn (Store $selected, string $query, array $variables): bool => $selected === $store && $variables === ['query' => null, 'after' => 'page-2'])->andReturn($this->payoutResponse());

        $this->assertCount(1, app(ShopifyPayouts::class)->choices($store, 'page-2')['payouts']);
    }

    public function test_invalid_issue_dates_are_not_interpreted_as_relative_dates(): void
    {
        $data = $this->data();
        $data['payout']['issuedAt'] = 'yesterday';
        $result = $this->analyze($data);

        $this->assertFalse($result['summary']['complete']);
        $this->assertNull($result['summary']['delta']);
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return ['payout_id' => '77', 'max_age_days' => 7, 'adjustment_threshold' => null, 'tolerance' => '0.01'];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['payout' => ['id' => 'gid://shopify/ShopifyPaymentsPayout/77', 'legacyResourceId' => '77', 'issuedAt' => '2026-10-07T10:00:00Z', 'status' => 'PAID', 'transactionType' => 'DEPOSIT', 'net' => ['amount' => '90.00', 'currencyCode' => 'EUR']], 'transactions' => [$this->transaction()], 'complete' => true, 'pages' => 1, 'notes' => []];
    }

    /** @return array<string, mixed> */
    private function transaction(string $id = '1', string $type = 'CHARGE', string $amount = '93.00', string $fee = '3.00', string $net = '90.00'): array
    {
        return ['id' => 'gid://shopify/ShopifyPaymentsBalanceTransaction/'.$id, 'type' => $type, 'test' => false, 'transactionDate' => '2026-10-01T10:00:00Z', 'associatedPayout' => ['id' => 'gid://shopify/ShopifyPaymentsPayout/77'], 'sourceType' => $type === 'ADJUSTMENT' ? 'ADJUSTMENT' : 'CHARGE', 'amount' => ['amount' => $amount, 'currencyCode' => 'EUR'], 'fee' => ['amount' => $fee, 'currencyCode' => 'EUR'], 'net' => ['amount' => $net, 'currencyCode' => 'EUR'], 'associatedOrder' => ['id' => 'gid://shopify/Order/1', 'name' => '#1001'], 'sourceOrderTransactionId' => '9', 'adjustmentsOrders' => []];
    }

    /** @return array<string, mixed> */
    private function analyze(array $data, ?array $policy = null): array
    {
        return app(PayoutAnomalyAnalyzer::class)->analyze($data, $policy ?? $this->policy(), CarbonImmutable::parse('2026-10-08T12:00:00Z'));
    }

    /** @return array<string, mixed> */
    private function payoutResponse(): array
    {
        return ['data' => ['shopifyPaymentsAccount' => ['payouts' => ['nodes' => [$this->data()['payout']], 'pageInfo' => ['hasNextPage' => false]]]]];
    }

    /** @return array<string, mixed> */
    private function transactionResponse(array $nodes, bool $more = false, ?string $cursor = null): array
    {
        return ['data' => ['shopifyPaymentsAccount' => ['balanceTransactions' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $more, 'endCursor' => $cursor]]]]];
    }
}
