<?php

namespace Tests\Feature;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\ImportShippingBilling;
use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShippingBillingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_import_requires_an_operator_and_preview_does_not_write(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        $this->get('/shipping-billing')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/shipping-billing')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['store_number' => '12']);
        $this->actingAs($operator)->get('/shipping-billing')->assertSeeText('Shipping Billing Audit');

        $response = $this->post('/shipping-billing/preview', $this->input([$this->row()]));

        $response->assertSeeText('Confirm billing import')->assertSeeText('10.100000');
        $this->assertDatabaseCount('shipping_billing_entries', 0);
    }

    public function test_confirmed_import_is_idempotent_and_reordered_columns_do_not_change_identity(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->actingAs($operator);
        $preview = $this->post('/shipping-billing/preview', $this->input([$this->row()]));
        $this->post('/shipping-billing/import', ['token' => $preview->viewData('token')])->assertRedirect(route('shipping-billing.index'));
        $entry = $store->shippingBillingEntries()->sole();
        $entry->update(['dispute_status' => 'submitted']);
        $row = array_reverse($this->row(), true);
        $file = app(CsvExporter::class)->content(array_keys($row), [array_values($row)]);
        $preview = $this->post('/shipping-billing/preview', ['file' => UploadedFile::fake()->createWithContent('billing.csv', $file), 'source_account' => 'ups-123', 'statement_reference' => 'Overlapping statement']);

        $this->post('/shipping-billing/import', ['token' => $preview->viewData('token')])->assertRedirect();

        $this->assertSame(1, $store->shippingBillingEntries()->count());
        $this->assertSame('10.100000', $entry->fresh()->amount);
        $this->assertSame('submitted', $entry->fresh()->dispute_status);
        $this->assertSame('Statement 1', $entry->fresh()->statement_reference);
    }

    public function test_conflicting_transaction_rolls_back_the_whole_batch(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->actingAs($operator);
        $preview = $this->post('/shipping-billing/preview', $this->input([$this->row()]));
        $this->post('/shipping-billing/import', ['token' => $preview->viewData('token')]);
        $preview = $this->post('/shipping-billing/preview', $this->input([$this->row(['transaction_id' => 'new-id']), $this->row(['amount' => '11.00'])]));

        $this->post('/shipping-billing/import', ['token' => $preview->viewData('token')])->assertSessionHasErrors('import');

        $this->assertSame(1, $store->shippingBillingEntries()->count());
        $this->assertDatabaseMissing('shipping_billing_entries', ['transaction_id' => 'new-id']);
    }

    #[DataProvider('invalidRows')]
    public function test_foreign_store_rows_and_invalid_postings_are_rejected(array $override): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator] = $this->userWithStore(true, ['store_number' => '12']);

        $this->actingAs($operator)->post('/shipping-billing/preview', $this->input([$this->row($override)]))->assertSessionHasErrors('file');

        $this->assertDatabaseCount('shipping_billing_entries', 0);
    }

    public static function invalidRows(): array
    {
        return ['foreign store' => [['shipstation_store_id' => '99']], 'negative amount' => [['amount' => '-1']], 'scientific amount' => [['amount' => '1e3']], 'excess precision' => [['amount' => '0.1234567']], 'unsupported posting' => [['type' => 'balance_top_up']], 'currency' => [['currency' => 'US']], 'shipment identity' => [['shipment_id' => '0']], 'invalid day' => [['posted_at' => '2026-02-30']]];
    }

    public function test_extra_columns_are_rejected_instead_of_silently_truncated(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator] = $this->userWithStore(true, ['store_number' => '12']);
        $csv = implode(',', ImportShippingBilling::HEADERS).",extra\n".implode(',', array_values($this->row())).",hidden\n";

        $this->actingAs($operator)->post('/shipping-billing/preview', ['file' => UploadedFile::fake()->createWithContent('billing.csv', $csv), 'source_account' => 'ups-123', 'statement_reference' => 'Statement'])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('shipping_billing_entries', 0);
    }

    public function test_utf8_bom_from_spreadsheet_exports_is_accepted(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator] = $this->userWithStore(true, ['store_number' => '12']);
        $csv = "\xEF\xBB\xBF".implode(',', ImportShippingBilling::HEADERS)."\n".implode(',', array_values($this->row()))."\n";

        $this->actingAs($operator)->post('/shipping-billing/preview', ['file' => UploadedFile::fake()->createWithContent('billing.csv', $csv), 'source_account' => 'ups-123', 'statement_reference' => 'Statement'])->assertSessionDoesntHaveErrors();
    }

    public function test_preview_is_user_scoped_expires_and_rechecks_account_settings(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $preview = $this->actingAs($operator)->post('/shipping-billing/preview', $this->input([$this->row()]));
        [$other] = $this->userWithStore(true);
        $this->actingAs($other)->post('/shipping-billing/import', ['token' => $preview->viewData('token')])->assertSessionHasErrors('import');
        $this->actingAs($operator);
        $store->update(['store_number' => '13']);
        $this->post('/shipping-billing/import', ['token' => $preview->viewData('token')])->assertSessionHasErrors('import');
        $store->update(['store_number' => '12']);
        $this->travel(11)->minutes();
        $this->post('/shipping-billing/import', ['token' => $preview->viewData('token')])->assertSessionHasErrors('import');
        $this->assertDatabaseCount('shipping_billing_entries', 0);
    }

    public function test_review_is_scoped_escapes_input_and_does_not_invent_credits(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true);
        $entry = ShippingBillingEntry::factory()->forAccount($store)->create(['reason' => '<script>unsafe</script>']);
        $other = ShippingBillingEntry::factory()->create();
        $this->actingAs($operator)->put(route('shipping-billing.update', $other), ['dispute_status' => 'accepted', 'excluded' => false])->assertNotFound();

        $this->put(route('shipping-billing.update', $entry), ['dispute_status' => 'accepted', 'excluded' => false, 'review_note' => 'Accepted dispute', 'expected_refund' => '10.1', 'refund_due_at' => '2026-10-20'])->assertRedirect();

        $this->assertSame('10.100000', $entry->fresh()->expected_refund);
        $this->assertStringNotContainsString('Accepted dispute', $entry->fresh()->getRawOriginal('review_note'));
        $response = $this->get('/shipping-billing')->assertSeeText('<script>unsafe</script>')->assertDontSee('<script>unsafe</script>', false)->assertSeeText(__('overdue'));
        $this->assertSame('0', $response->viewData('groups')[0]['refunds']);
        $this->assertDatabaseCount('shipping_billing_entries', 2);
        $this->put(route('shipping-billing.update', $entry), ['dispute_status' => 'review', 'excluded' => true])->assertSessionHasErrors('review_note');
    }

    public function test_export_is_store_scoped_formula_safe_and_uses_posting_dates(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true);
        ShippingBillingEntry::factory()->forAccount($store)->create(['reason' => '=DANGEROUS()', 'transaction_id' => 'visible']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['posted_at' => '2026-09-01', 'transaction_id' => 'outside-period']);
        ShippingBillingEntry::factory()->create(['transaction_id' => 'foreign-secret']);

        $response = $this->actingAs($operator)->get(route('shipping-billing.export', ['start_date' => '2026-10-01', 'end_date' => '2026-10-23']));

        $response->assertDownload('shipping-billing-2026-10-01-to-2026-10-23.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $content);
        $this->assertStringContainsString('visible', $content);
        $this->assertStringNotContainsString('foreign-secret', $content);
        $this->assertStringNotContainsString('outside-period', $content);
    }

    public function test_late_credit_selection_includes_earlier_label_cost(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [$operator, $store] = $this->userWithStore(true);
        ShippingBillingEntry::factory()->forAccount($store)->create(['posted_at' => '2026-09-01', 'amount' => '10.1']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['posted_at' => '2026-10-01', 'type' => 'label_refund', 'amount' => '10.1']);

        $response = $this->actingAs($operator)->get('/shipping-billing?start_date=2026-10-01&end_date=2026-10-23');

        $response->assertViewHas('entries', fn ($entries): bool => $entries->count() === 1);
        $this->assertSame('0.000000', $response->viewData('groups')[0]['observed_net']);
        $this->assertSame('10.100000', $response->viewData('groups')[0]['charges']);
    }

    private function parse(Store $store, array $rows): array
    {
        return app(ImportShippingBilling::class)->parse($this->input($rows)['file'], $store);
    }

    public function test_duplicate_or_missing_header_names_are_rejected(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $headers = ImportShippingBilling::HEADERS;
        $duplicated = [...array_slice($headers, 0, -1), $headers[0]];
        $values = array_values($this->row());
        $content = fn (array $names): UploadedFile => UploadedFile::fake()->createWithContent('billing.csv', app(CsvExporter::class)->content($names, [$values]));

        foreach ([$duplicated, array_slice($headers, 1), [...$headers, $headers[1]]] as $names) {
            try {
                app(ImportShippingBilling::class)->parse($content($names), $store);
                $this->fail('The header row must match the template exactly.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
    }

    public function test_the_row_ceiling_allows_five_thousand_rows_and_rejects_the_next_one(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $rows = array_map(fn (int $number): array => $this->row(['transaction_id' => "tx-{$number}"]), range(1, 5000));

        $this->assertCount(5000, $this->parse($store, $rows));

        $this->expectException(ValidationException::class);
        $this->parse($store, [...$rows, $this->row(['transaction_id' => 'tx-5001'])]);
    }

    public function test_hash_prefixes_are_removed_from_order_numbers_and_a_bare_hash_is_rejected(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [, $store] = $this->userWithStore(true, ['store_number' => '12']);

        $this->assertSame('1001', $this->parse($store, [$this->row(['order_number' => '#1001'])])[0]['order_number']);
        $this->assertSame('1001', $this->parse($store, [$this->row(['order_number' => ' ##1001 '])])[0]['order_number']);
        $this->assertSame('10#01', $this->parse($store, [$this->row(['order_number' => '#10#01'])])[0]['order_number']);
        $this->expectException(ValidationException::class);
        $this->parse($store, [$this->row(['order_number' => '#'])]);
    }

    public function test_posted_at_may_be_today_in_the_store_timezone_but_not_tomorrow(): void
    {
        // 2026-10-22 23:00 UTC is already 2026-10-23 in Auckland (UTC+13) and still the 22nd in UTC.
        $this->travelTo('2026-10-22 23:00:00 UTC');
        [, $auckland] = $this->userWithStore(true, ['store_number' => '12', 'shopify_timezone' => 'Pacific/Auckland']);
        [, $utc] = $this->userWithStore(true, ['store_number' => '12', 'shopify_timezone' => 'UTC']);

        $this->assertCount(1, $this->parse($auckland, [$this->row(['posted_at' => '2026-10-23'])]));
        $this->assertCount(1, $this->parse($utc, [$this->row(['posted_at' => '2026-10-22'])]));
        try {
            $this->parse($auckland, [$this->row(['posted_at' => '2026-10-24'])]);
            $this->fail('A posting date after the store day must be rejected.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $this->parse($utc, [$this->row(['posted_at' => '2026-10-23'])]);
    }

    public function test_the_same_transaction_id_in_another_store_or_source_account_is_a_separate_entry(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [, $store] = $this->userWithStore(true, ['store_number' => '12']);
        [, $otherStore] = $this->userWithStore(true, ['store_number' => '12']);
        $import = app(ImportShippingBilling::class);
        $rows = $this->parse($store, [$this->row()]);

        $this->assertSame(['created' => 1, 'duplicates' => 0], $import->handle($store, $rows, 'ups-123', 'S1', str_repeat('a', 64)));
        $this->assertSame(['created' => 1, 'duplicates' => 0], $import->handle($store, $rows, 'ups-456', 'S1', str_repeat('a', 64)));
        $this->assertSame(['created' => 1, 'duplicates' => 0], $import->handle($otherStore, $rows, 'ups-123', 'S1', str_repeat('a', 64)));
        $this->assertSame(['created' => 0, 'duplicates' => 1], $import->handle($store, $rows, 'ups-123', 'S2', str_repeat('b', 64)));

        $this->assertSame(3, ShippingBillingEntry::query()->where('transaction_id', 'tx-1')->count());
        $this->assertSame(2, $store->shippingBillingEntries()->count());
    }

    public function test_a_conflict_inside_one_file_rolls_back_every_row(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $rows = $this->parse($store, [$this->row(['transaction_id' => 'first']), $this->row(['transaction_id' => 'twice']), $this->row(['transaction_id' => 'twice', 'amount' => '99'])]);

        try {
            app(ImportShippingBilling::class)->handle($store, $rows, 'ups-123', 'S1', str_repeat('a', 64));
            $this->fail('Conflicting rows with one transaction ID must fail the whole import.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('import', $exception->errors());
        }

        $this->assertSame(0, $store->shippingBillingEntries()->count());
    }

    public function test_identical_rows_repeated_in_one_file_count_as_one_entry_and_one_duplicate(): void
    {
        $this->travelTo('2026-10-23 12:00:00 UTC');
        [, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $rows = $this->parse($store, [$this->row(), $this->row()]);

        $this->assertSame(['created' => 1, 'duplicates' => 1], app(ImportShippingBilling::class)->handle($store, $rows, 'ups-123', 'S1', str_repeat('a', 64)));
    }

    private function input(array $rows): array
    {
        return ['file' => UploadedFile::fake()->createWithContent('billing.csv', app(CsvExporter::class)->content(ImportShippingBilling::HEADERS, array_map(fn (array $row): array => array_values($row), $rows))), 'source_account' => 'ups-123', 'statement_reference' => 'Statement 1'];
    }

    private function row(array $overrides = []): array
    {
        return array_replace(array_combine(ImportShippingBilling::HEADERS, ['12', 'tx-1', '123', '42', '1001', 'TRACK-1', 'label_charge', '10.1', 'USD', '2026-10-01', 'ups', 'ground', 'box-1', 'SKU-A;SKU-B', 'Label']), $overrides);
    }
}
