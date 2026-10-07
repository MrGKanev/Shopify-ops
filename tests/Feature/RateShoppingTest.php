<?php

namespace Tests\Feature;

use App\Domain\Reports\RateShoppingAnalyzer;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClient;
use App\Jobs\CaptureRateQuoteSnapshot;
use App\Models\RateQuoteSnapshot;
use App\Models\Store;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RateShoppingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_capture_uses_account_scope_full_route_parcel_and_confirmation_and_is_encrypted(): void
    {
        [$operator, $store, $order] = $this->context();
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input())->assertRedirect();
        $snapshot = $store->rateQuoteSnapshots()->sole();
        $this->assertSame('ready', $snapshot->status);
        $this->assertSame('simulation', $snapshot->mode);
        $this->assertSame('US', $snapshot->context['from']['country']);
        $this->assertSame('94105', $snapshot->context['to']['postalCode']);
        $this->assertSame('operator_approved', $snapshot->context['eligibility_source']);
        $this->assertStringNotContainsString('Market Street', DB::table('rate_quote_snapshots')->value('context'));
        $this->assertArrayNotHasKey('context', $snapshot->toArray());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/shipments/getrates') && $request['fromWarehouseId'] === '9' && $request['weight']['units'] === 'grams' && (float) $request['dimensions']['length'] === 10.0 && $request['confirmation'] === 'delivery' && $request['residential'] === false);
        $this->get(route('reports.rate-shopping.result', $snapshot->id))->assertSeeText('Current-tariff simulation')->assertSeeText('Simulated quoted difference')->assertSeeText('Excluded: service not approved');
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/createorder'));
    }

    public function test_price_comparison_includes_other_cost_and_excludes_unapproved_options(): void
    {
        $analyzer = new RateShoppingAnalyzer;
        $rows = $analyzer->normalize($this->rates(), ['ground', 'express']);
        $comparison = $analyzer->compare($rows, 'ground');
        $this->assertSame('express', $comparison['cheapest']['service_code']);
        $this->assertSame(12.0, $comparison['reference']['total']);
        $this->assertSame(3.0, $comparison['difference']);
        $this->assertNull($analyzer->compare($rows, 'unknown')['difference']);
    }

    public function test_cache_preserves_actual_retrieval_time_and_does_not_reuse_rates_for_changed_route_or_conditions(): void
    {
        [$operator, $store, $order] = $this->context();
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $first = $store->rateQuoteSnapshots()->sole();
        $this->travel(1)->minutes();
        $this->post(route('reports.rate-shopping.store'), $this->input());
        $second = $store->rateQuoteSnapshots()->latest('id')->first();
        $this->assertSame($first->quoted_at->toIso8601String(), $second->quoted_at->toIso8601String());
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/shipments/getrates')));
        $order['shipTo']['street2'] = 'Apartment 2';
        $this->post(route('reports.rate-shopping.store'), $this->input());
        $changed = $store->rateQuoteSnapshots()->latest('id')->first();
        $this->assertNotSame($first->signature, $changed->signature);
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/shipments/getrates')));
    }

    public function test_changed_account_parcel_date_and_eligibility_do_not_share_a_cache_entry(): void
    {
        [$operator, $store, $order] = $this->context();
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $input = $this->input();
        $input['weight_value'] = 600;
        $this->post(route('reports.rate-shopping.store'), $input);
        $store->update(['shipstation_api_key' => 'rotated-key']);
        $this->post(route('reports.rate-shopping.store'), $input);
        $input['max_transit_days'] = 2;
        $this->post(route('reports.rate-shopping.store'), $input);
        $this->travel(1)->days();
        $this->post(route('reports.rate-shopping.store'), $input);
        $this->assertCount(5, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/shipments/getrates')));
    }

    public function test_confirmed_selection_preserves_order_identity_and_records_only_captured_quote_difference(): void
    {
        [$operator, $store, $order] = $this->context();
        $order['internalNotes'] = 'Keep warehouse notes';
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $snapshot = $store->rateQuoteSnapshots()->sole();
        $this->post(route('rate-quote-selections.store', $snapshot->id), ['service_code' => 'ground', 'confirmed' => '1'])->assertRedirect();
        $this->assertSame('selected', $snapshot->fresh()->status);
        $this->assertSame('recorded_decision', $snapshot->fresh()->mode);
        $this->assertNotNull($snapshot->fresh()->selected_at);
        $this->assertSame(500.0, (float) $order['weight']['value']);
        $this->assertSame('Keep warehouse notes', $order['internalNotes']);
        $this->assertSame('gid://shopify/Order/1', $order['orderKey']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/createorder') && $request['orderId'] === 77 && $request['orderStatus'] === 'awaiting_shipment' && $request['serviceCode'] === 'ground');
        $this->assertDatabaseHas('activity_log', ['description' => 'rate_selection_confirmed', 'subject_id' => $snapshot->id]);
        $this->get(route('reports.rate-shopping.result', $snapshot->id))->assertSeeText('Recorded quote decision')->assertSeeText('Quoted difference at recorded selection')->assertSeeText('not evidence of label purchase');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/createlabel'));
    }

    public function test_unapproved_services_expired_quotes_and_changed_source_data_cannot_be_applied(): void
    {
        [$operator, $store, $order] = $this->context();
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $snapshot = $store->rateQuoteSnapshots()->sole();
        $this->post(route('rate-quote-selections.store', $snapshot->id), ['service_code' => 'slow', 'confirmed' => '1'])->assertUnprocessable();
        $order['shipTo']['street1'] = 'Changed after capture';
        $this->post(route('rate-quote-selections.store', $snapshot->id), ['service_code' => 'express', 'confirmed' => '1']);
        $this->assertSame('failed', $snapshot->fresh()->status);
        $this->assertStringContainsString('conditions changed', $snapshot->fresh()->message);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/createorder'));
    }

    public function test_expired_quotes_stay_simulations_and_cannot_be_selected(): void
    {
        [$operator, $store, $order] = $this->context();
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $snapshot = $store->rateQuoteSnapshots()->sole();
        $this->travel(6)->minutes();
        $this->post(route('rate-quote-selections.store', $snapshot->id), ['service_code' => 'express', 'confirmed' => '1'])->assertConflict();
        $this->get(route('reports.rate-shopping.result', $snapshot->id))->assertDontSeeText('Apply service and record decision');
        $this->assertSame('simulation', $snapshot->fresh()->mode);
    }

    #[TestWith(['insurance'])]
    #[TestWith(['ddp'])]
    #[TestWith(['international'])]
    #[TestWith(['third_party'])]
    #[TestWith(['unknown_option'])]
    public function test_unpriced_conditions_are_blocked_instead_of_being_compared_as_cheaper(string $condition): void
    {
        [$operator, $store, $order] = $this->context();
        if ($condition === 'unknown_option') {
            $order['advancedOptions']['dangerousGoods'] = true;
        }
        if ($condition === 'insurance') {
            $order['insuranceOptions']['insureShipment'] = true;
        }
        if ($condition === 'ddp') {
            $order['advancedOptions']['deliveredDutyPaid'] = true;
        }
        if ($condition === 'third_party') {
            $order['advancedOptions']['billToAccount'] = 'another-account';
        }
        if ($condition === 'international') {
            $order['shipTo']['country'] = 'CA';
            $order['shipTo']['state'] = 'ON';
            $order['shipTo']['postalCode'] = 'M5V 1E3';
            $order['shipTo']['city'] = 'Toronto';
        }
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $snapshot = $store->rateQuoteSnapshots()->sole();
        $this->assertSame('failed', $snapshot->status);
        $this->assertNull($snapshot->quotes);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/shipments/getrates'));
    }

    public function test_empty_rate_response_has_no_fake_zero_difference_or_service_selection(): void
    {
        [$operator, $store, $order] = $this->context();
        $this->fakeShipStation($order);
        $order['carrierCode'] = 'unavailable-carrier';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => fn (Request $request): PromiseInterface => Http::response(parse_url($request->url(), PHP_URL_PATH) === '/orders' ? ['orders' => [$order]] : $order),
            'https://ssapi.shipstation.com/warehouses/9' => Http::response(['warehouseId' => 9, 'originAddress' => ['name' => 'Warehouse', 'street1' => '10 Main Street', 'city' => 'New York', 'state' => 'NY', 'postalCode' => '10001', 'country' => 'US']]),
            'https://ssapi.shipstation.com/shipments/getrates' => Http::response([]),
        ]);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $snapshot = $store->rateQuoteSnapshots()->sole();
        $this->assertSame([], $snapshot->quoteRows());
        $this->get(route('reports.rate-shopping.result', $snapshot->id))->assertSeeText('No rates were returned.')->assertDontSeeText('Apply service and record decision');
        $this->assertNull((new RateShoppingAnalyzer)->compare([], 'ground')['difference']);
    }

    public function test_a_shipped_order_can_only_produce_a_current_simulation(): void
    {
        [$operator, $store, $order] = $this->context();
        $order['orderStatus'] = 'shipped';
        $this->fakeShipStation($order);
        $this->actingAs($operator)->post(route('reports.rate-shopping.store'), $this->input());
        $snapshot = $store->rateQuoteSnapshots()->sole();
        $this->get(route('reports.rate-shopping.result', $snapshot->id))->assertSeeText('Current-tariff simulation')->assertDontSeeText('Apply service and record decision');
        $this->post(route('rate-quote-selections.store', $snapshot->id), ['service_code' => 'ground', 'confirmed' => '1'])->assertConflict();
        $this->assertSame('simulation', $snapshot->fresh()->mode);
    }

    public function test_snapshots_are_store_scoped_and_a_different_operator_cannot_select_them(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $snapshot = RateQuoteSnapshot::factory()->for($store)->for($operator, 'user')->create();
        [$other, $otherStore] = $this->userWithStore(true);
        $this->actingAs($other)->get(route('reports.rate-shopping.result', $snapshot->id))->assertNotFound();
        $this->post(route('rate-quote-selections.store', $snapshot->id), ['service_code' => 'ground', 'confirmed' => '1'])->assertNotFound();
        $other->stores()->attach($store);
        $other->update(['active_store_id' => $store->id]);
        $this->post(route('rate-quote-selections.store', $snapshot->id), ['service_code' => 'ground', 'confirmed' => '1'])->assertNotFound();
    }

    public function test_input_authorization_and_equivalent_service_confirmation_are_required(): void
    {
        $this->get(route('reports.rate-shopping'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('reports.rate-shopping'))->assertForbidden();
        [$operator] = $this->userWithStore(true);
        Queue::fake([CaptureRateQuoteSnapshot::class]);
        $input = $this->input();
        unset($input['equivalence_confirmed']);
        $this->actingAs($operator)->from(route('reports.rate-shopping'))->post(route('reports.rate-shopping.store'), $input)->assertSessionHasErrors('equivalence_confirmed');
        $this->get(route('reports.rate-shopping'))->assertSeeText('Approved service codes');
        $input['carrier_code'] = ['not a scalar'];
        $this->post(route('reports.rate-shopping.store'), $input)->assertSessionHasErrors('carrier_code');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('rate_quote_snapshots', 0);
    }

    public function test_rate_api_validates_cost_fields_and_preserves_the_read_only_request_payload(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/shipments/getrates' => Http::response($this->rates())]);
        $client = new ShipStationClient('test-key', 'test-secret');
        $rates = $client->getRates(['carrierCode' => 'ups', 'fromPostalCode' => '10001', 'toPostalCode' => '94105', 'toCountry' => 'US', 'weight' => ['value' => 500, 'units' => 'grams']]);
        $this->assertCount(3, $rates);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['carrierCode'] === 'ups');
    }

    public function test_missing_other_cost_is_not_treated_as_zero(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/shipments/getrates' => Http::response([['serviceCode' => 'ground', 'shipmentCost' => 10]])]);
        $this->expectException(UnexpectedResponse::class);
        (new ShipStationClient('key', 'secret'))->getRates(['carrierCode' => 'ups']);
    }

    /** @return array{User, Store, array<string, mixed>} */
    private function context(): array
    {
        $this->travelTo('2026-10-07 12:00:00');
        Notification::fake();
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $order = ['orderId' => 77, 'orderKey' => 'gid://shopify/Order/1', 'orderNumber' => '1001', 'orderDate' => '2026-10-01', 'orderStatus' => 'awaiting_shipment', 'shipTo' => ['name' => 'Jane Doe', 'street1' => '123 Market Street', 'street2' => '', 'city' => 'San Francisco', 'state' => 'CA', 'postalCode' => '94105', 'country' => 'US', 'residential' => false], 'billTo' => ['name' => 'Jane Doe'], 'items' => [['sku' => 'SKU1', 'quantity' => 1]], 'weight' => ['value' => 1, 'units' => 'pounds'], 'dimensions' => ['length' => 1, 'width' => 1, 'height' => 1, 'units' => 'inches'], 'carrierCode' => 'ups', 'serviceCode' => 'ground', 'packageCode' => 'package', 'confirmation' => 'delivery', 'advancedOptions' => ['storeId' => 12, 'warehouseId' => 9]];

        return [$operator, $store, $order];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['order_number' => '#1001', 'carrier_code' => 'ups', 'warehouse_id' => 9, 'currency' => 'USD', 'weight_value' => 500, 'weight_unit' => 'grams', 'dimension_unit' => 'centimeters', 'length' => 10, 'width' => 5, 'height' => 5, 'package_code' => 'package', 'confirmation' => 'delivery', 'residential' => '0', 'tracking_required' => '1', 'max_transit_days' => 5, 'minimum_coverage' => 0, 'allowed_services' => 'ground, express', 'equivalence_confirmed' => '1'];
    }

    /** @return list<array<string, mixed>> */
    private function rates(): array
    {
        return [['serviceCode' => 'ground', 'serviceName' => 'Ground', 'shipmentCost' => 10, 'otherCost' => 2], ['serviceCode' => 'express', 'serviceName' => 'Express', 'shipmentCost' => 8, 'otherCost' => 1], ['serviceCode' => 'slow', 'serviceName' => 'Slow', 'shipmentCost' => 1, 'otherCost' => 0]];
    }

    /** @param array<string, mixed> $order */
    private function fakeShipStation(array &$order): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => function (Request $request) use (&$order): PromiseInterface {
                if ($request->method() === 'POST') {
                    $order = $request->data();

                    return Http::response($order);
                }

                return Http::response(parse_url($request->url(), PHP_URL_PATH) === '/orders' ? ['orders' => [$order], 'pages' => 1] : $order);
            },
            'https://ssapi.shipstation.com/warehouses/9' => Http::response(['warehouseId' => 9, 'originAddress' => ['name' => 'Warehouse', 'street1' => '10 Main Street', 'city' => 'New York', 'state' => 'NY', 'postalCode' => '10001', 'country' => 'US']]),
            'https://ssapi.shipstation.com/shipments/getrates' => Http::response($this->rates()),
        ]);
    }
}
