<?php

namespace Tests\Feature;

use App\Application\Operations\SaveCarrierClaim;
use App\Domain\Orders\CarrierClaimLedger;
use App\IssueStatus;
use App\Models\CarrierClaim;
use App\Models\CarrierClaimDocument;
use App\Models\CarrierClaimPayment;
use App\Models\CarrierClaimPolicy;
use App\Models\OperationalIssue;
use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CarrierClaimTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return $overrides + ['provider' => 'UPS', 'external_reference' => 'CLM-1', 'status' => 'draft', 'currency' => 'usd'];
    }

    public function test_ledger_is_exact_and_keeps_goods_and_postage_apart(): void
    {
        $ledger = new CarrierClaimLedger;
        $summary = $ledger->summarize(
            ['goods_requested' => '100.10', 'goods_approved' => '80.30', 'postage_requested' => '9.99', 'postage_approved' => '9.99'],
            [['kind' => 'goods', 'amount' => '0.10'], ['kind' => 'goods', 'amount' => '0.20'], ['kind' => 'postage', 'amount' => '10.000000']],
        );

        $this->assertSame('80.000000', $summary['goods']['outstanding']);
        $this->assertSame('0.300000', $summary['goods']['received']);
        $this->assertFalse($summary['goods']['over_received']);
        $this->assertSame('0.000000', $summary['postage']['outstanding']);
        $this->assertTrue($summary['postage']['over_received']);
        $this->assertNull($ledger->summarize([], [])['goods']['outstanding']);
        $this->assertSame('1.500000', CarrierClaimLedger::format(CarrierClaimLedger::toMicros('1.5')));
        $this->expectException(InvalidArgumentException::class);
        CarrierClaimLedger::toMicros('1e3');
    }

    public function test_pages_are_authorized_and_store_scoped(): void
    {
        $this->get(route('carrier-claims.index'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('carrier-claims.index'))->assertForbidden();

        [$operator, $store] = $this->userWithStore(true);
        $own = CarrierClaim::factory()->for($store)->create(['external_reference' => 'OWN-1']);
        $foreign = CarrierClaim::factory()->create();
        $this->actingAs($operator)->get(route('carrier-claims.index'))->assertOk()->assertSee('OWN-1')->assertDontSee($foreign->external_reference);
        $this->actingAs($operator)->get(route('carrier-claims.show', $own->id))->assertOk();
        $this->actingAs($operator)->get(route('carrier-claims.show', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->post(route('carrier-claims.payments.store', $foreign->id), ['kind' => 'goods', 'amount' => '1', 'currency' => 'USD', 'received_on' => today()->toDateString(), 'source_note' => 'x'])->assertNotFound();
        $this->actingAs($operator)->post(route('carrier-claims.communications.store', $foreign->id), ['text' => 'x'])->assertNotFound();
    }

    public function test_duplicate_external_reference_is_refused_but_other_stores_may_reuse_it(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input())->assertRedirect();
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['provider' => ' ups ', 'external_reference' => 'clm-1']))->assertSessionHasErrors('external_reference');
        $this->assertSame(1, $store->carrierClaims()->count());
        $this->assertSame('USD', $store->carrierClaims()->sole()->currency);

        [$other, $otherStore] = $this->userWithStore(true);
        $this->actingAs($other)->post(route('carrier-claims.store'), $this->input())->assertRedirect();
        $this->assertSame(1, $otherStore->carrierClaims()->count());
        $this->assertSame('UPS', $otherStore->carrierClaims()->sole()->provider);
    }

    public function test_validation_requires_evidence_verified_dates_and_policy(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['goods_approved' => '10']))->assertSessionHasErrors('evidence');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['status' => 'submitted']))->assertSessionHasErrors('submitted_on');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['status' => 'denied']))->assertSessionHasErrors('decision_on');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['submitted_on' => today()->toDateString()]))->assertSessionHasErrors('dates_verified');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['deadline_on' => today()->addDay()->toDateString(), 'dates_verified' => '1']))->assertSessionHasErrors('deadline_policy');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['goods_requested' => '1e3']))->assertSessionHasErrors('goods_requested');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['evidence' => "http://insecure.example/a\nnot a url", 'goods_approved' => '1']))->assertSessionHasErrors(['evidence.0', 'evidence.1']);
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['external_reference' => 'OK-9', 'status' => 'approved', 'submitted_on' => today()->subDays(3)->toDateString(), 'decision_on' => today()->toDateString(), 'dates_verified' => '1', 'goods_approved' => '25.50', 'evidence' => "https://portal.example/claims/9\n"]))->assertSessionHasNoErrors();
        $claim = CarrierClaim::query()->where('external_reference', 'OK-9')->sole();
        $this->assertSame(['https://portal.example/claims/9'], $claim->evidence);
        $this->assertTrue(now()->equalTo($claim->dates_verified_at));
        $this->assertSame($operator->id, $claim->dates_verified_by);
    }

    public function test_payments_are_partial_separate_and_never_double_count_a_ledger_posting(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $claim = CarrierClaim::factory()->for($store)->create(['status' => 'approved', 'goods_approved' => '50', 'postage_approved' => '8']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['transaction_id' => 'ledger-1', 'type' => 'label_refund', 'amount' => '8']);
        $pay = fn (array $data) => $this->actingAs($operator)->post(route('carrier-claims.payments.store', $claim->id), $data + ['currency' => 'USD', 'received_on' => today()->toDateString(), 'source_note' => 'Portal']);

        $pay(['kind' => 'postage', 'amount' => '8', 'ledger_reference' => 'ledger-1', 'received_on' => today()->subDays(5)->toDateString()])->assertRedirect();
        $pay(['kind' => 'goods', 'amount' => '20'])->assertRedirect();
        $pay(['kind' => 'goods', 'amount' => '8', 'ledger_reference' => 'ledger-1'])->assertSessionHasErrors('ledger_reference');
        $pay(['kind' => 'goods', 'amount' => '5', 'currency' => 'EUR'])->assertSessionHasErrors('currency');
        $pay(['kind' => 'goods', 'amount' => '0'])->assertSessionHasErrors('amount');
        $pay(['kind' => 'goods', 'amount' => '5', 'received_on' => today()->addDay()->toDateString()])->assertSessionHasErrors('received_on');
        $this->assertSame(2, CarrierClaimPayment::count());

        $this->actingAs($operator)->get(route('carrier-claims.show', $claim->id))->assertOk()->assertSeeText('30')->assertSeeText('Customer refunds are not part of this register');
        $csv = $this->actingAs($operator)->get(route('carrier-claims.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Goods outstanding', $csv);
        $this->assertStringContainsString(',,50.000000,20.000000,30.000000,,8.000000,8.000000,0.000000,', $csv);
    }

    public function test_follow_up_raises_one_issue_and_closing_the_claim_resolves_it(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $follow = today()->subDay()->toDateString();
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['follow_up_on' => $follow]))->assertRedirect();
        $claim = $store->carrierClaims()->sole();
        $issue = OperationalIssue::query()->where('store_id', $store->id)->where('source_tool', SaveCarrierClaim::ISSUE_SOURCE)->sole();
        $this->assertSame(IssueStatus::Open, $issue->status);
        $this->assertSame($follow, $issue->due_date->toDateString());

        $this->actingAs($operator)->put(route('carrier-claims.update', $claim->id), $this->input(['follow_up_on' => $follow, 'status' => 'under_review', 'submitted_on' => $follow, 'dates_verified' => '1']))->assertRedirect();
        $this->assertSame(1, OperationalIssue::query()->where('source_tool', SaveCarrierClaim::ISSUE_SOURCE)->count());

        $this->actingAs($operator)->put(route('carrier-claims.update', $claim->id), $this->input(['follow_up_on' => $follow, 'status' => 'closed']))->assertRedirect();
        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
        $this->actingAs($operator)->get(route('carrier-claims.index', ['overdue' => 1]))->assertOk()->assertDontSee('CLM-1');
    }

    public function test_linked_issue_must_belong_to_the_store_and_currency_is_locked_after_payments(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $foreignIssue = OperationalIssue::factory()->create();
        $issue = OperationalIssue::factory()->create(['store_id' => $store->id]);
        $this->actingAs($operator)->get(route('carrier-claims.create', ['issue' => $foreignIssue->id]))->assertOk()->assertSee('name="operational_issue_id" value=""', false);
        $this->actingAs($operator)->get(route('carrier-claims.create', ['issue' => $issue->id]))->assertOk()->assertSee('name="operational_issue_id" value="'.$issue->id.'"', false);
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['operational_issue_id' => $foreignIssue->id]))->assertSessionHasErrors('operational_issue_id');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input(['operational_issue_id' => $issue->id]))->assertSessionHasNoErrors();
        $claim = $store->carrierClaims()->sole();
        $this->assertSame($issue->id, $claim->operational_issue_id);

        CarrierClaimPayment::factory()->create(['carrier_claim_id' => $claim->id]);
        $this->actingAs($operator)->put(route('carrier-claims.update', $claim->id), $this->input(['currency' => 'EUR']))->assertSessionHasErrors('currency');
        $this->expectException(ValidationException::class);
        app(SaveCarrierClaim::class)->handle($store, null, $this->input(['currency' => 'USD']), $operator);
    }

    public function test_communication_notes_are_appended(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $claim = CarrierClaim::factory()->for($store)->create();
        $this->actingAs($operator)->post(route('carrier-claims.communications.store', $claim->id), ['text' => 'Called <b>UPS</b>'])->assertRedirect();
        $this->actingAs($operator)->post(route('carrier-claims.communications.store', $claim->id), ['text' => 'Second'])->assertRedirect();
        $this->actingAs($operator)->post(route('carrier-claims.communications.store', $claim->id), ['text' => ''])->assertSessionHasErrors('text');
        $this->assertCount(2, $claim->fresh()->communications);
        $this->actingAs($operator)->get(route('carrier-claims.show', $claim->id))->assertSee('Called &lt;b&gt;UPS&lt;/b&gt;', false);
    }

    public function test_policies_are_store_scoped_and_a_claim_deadline_uses_a_verified_policy(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('carrier-claims.policies.store'), ['provider' => 'UPS', 'description' => '60 days', 'source_url' => 'http://insecure.example', 'verified_on' => today()->toDateString()])->assertSessionHasErrors('source_url');
        $this->actingAs($operator)->post(route('carrier-claims.policies.store'), ['provider' => 'UPS', 'description' => '60 days from ship date', 'source_url' => 'https://ups.example/terms', 'verified_on' => today()->toDateString()])->assertRedirect();
        $policy = $store->carrierClaimPolicies()->sole();
        $foreign = CarrierClaimPolicy::factory()->create();
        $this->actingAs($operator)->delete(route('carrier-claims.policies.destroy', $foreign->id))->assertNotFound();

        $deadline = ['deadline_on' => today()->addDays(10)->toDateString(), 'dates_verified' => '1'];
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input($deadline))->assertSessionHasErrors('deadline_policy');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input($deadline + ['carrier_claim_policy_id' => $foreign->id]))->assertSessionHasErrors('carrier_claim_policy_id');
        $this->actingAs($operator)->post(route('carrier-claims.store'), $this->input($deadline + ['carrier_claim_policy_id' => $policy->id]))->assertSessionHasNoErrors();

        $claim = $store->carrierClaims()->sole();
        $this->assertSame($policy->id, $claim->carrier_claim_policy_id);
        $this->assertStringContainsString('60 days from ship date', (string) $claim->deadline_policy);
        $this->actingAs($operator)->get(route('carrier-claims.index'))->assertOk()->assertSee('https://ups.example/terms');
    }

    public function test_a_payment_can_be_linked_to_a_matching_billing_credit_only_once(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $claim = CarrierClaim::factory()->for($store)->create(['order_number' => '1001', 'postage_approved' => '8']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['transaction_id' => 'credit-1', 'type' => 'label_refund', 'amount' => '8', 'order_number' => '1001']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['transaction_id' => 'charge-1', 'type' => 'label_charge', 'amount' => '8', 'order_number' => '1001']);
        ShippingBillingEntry::factory()->forAccount($store)->create(['transaction_id' => 'eur-1', 'type' => 'label_refund', 'amount' => '8', 'currency' => 'EUR', 'order_number' => '1001']);
        $pay = fn (array $data) => $this->actingAs($operator)->post(route('carrier-claims.payments.store', $claim->id), $data + ['kind' => 'postage', 'amount' => '8', 'currency' => 'USD', 'received_on' => today()->toDateString(), 'source_note' => 'Ledger']);

        $this->actingAs($operator)->get(route('carrier-claims.show', $claim->id))->assertSee('value="credit-1"', false)->assertDontSee('value="charge-1"', false)->assertDontSee('value="eur-1"', false);
        $pay(['ledger_reference' => 'missing'])->assertSessionHasErrors('ledger_reference');
        $pay(['ledger_reference' => 'charge-1'])->assertSessionHasErrors('ledger_reference');
        $pay(['ledger_reference' => 'credit-1', 'amount' => '7'])->assertSessionHasErrors('ledger_reference');
        $pay(['ledger_reference' => 'credit-1', 'kind' => 'goods'])->assertSessionHasErrors('ledger_reference');
        $pay(['ledger_reference' => 'credit-1'])->assertSessionHasNoErrors();
        $pay(['ledger_reference' => 'credit-1'])->assertSessionHasErrors('ledger_reference');
        $this->actingAs($operator)->get(route('carrier-claims.show', $claim->id))->assertDontSee('value="credit-1"', false);
        $this->assertSame(1, $claim->payments()->count());
    }

    public function test_documents_are_stored_privately_downloaded_and_deleted_within_the_store(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$operator, $store] = $this->userWithStore(true);
        $claim = CarrierClaim::factory()->for($store)->create();
        $pdf = UploadedFile::fake()->create('../Proof of loss.php.pdf', 100, 'application/pdf');

        $this->actingAs($operator)->post(route('carrier-claims.documents.store', $claim->id), ['document' => $pdf])->assertRedirect(route('carrier-claims.show', $claim->id));

        $document = $claim->documents()->sole();
        $this->assertSame('Proof-of-loss-php.pdf', $document->original_name);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame($store->id, $document->store_id);
        $this->assertMatchesRegularExpression('#^carrier-claims/'.$store->id.'/'.$claim->id.'/[0-9a-f-]{36}\.pdf$#', $document->path);
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->actingAs($operator)->get(route('carrier-claims.show', $claim->id))->assertOk()->assertSee('Proof-of-loss-php.pdf');
        $this->actingAs($operator)->get(route('carrier-claims.documents.download', [$claim->id, $document->id]))->assertOk()->assertDownload('Proof-of-loss-php.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($operator)->delete(route('carrier-claims.documents.destroy', [$claim->id, $document->id]))->assertRedirect(route('carrier-claims.show', $claim->id));
        Storage::disk('local')->assertMissing($document->path);
        $this->assertSame(0, CarrierClaimDocument::count());
        $this->assertSame(['carrier_claim_document_upload', 'carrier_claim_document_delete'], Activity::where('log_name', 'operator-actions')->orderBy('id')->pluck('description')->all());
    }

    public function test_documents_of_another_store_or_claim_are_not_reachable(): void
    {
        Storage::fake('local');
        [$operator, $store] = $this->userWithStore(true);
        $own = CarrierClaim::factory()->for($store)->create();
        $foreign = CarrierClaim::factory()->create();
        $foreignDocument = CarrierClaimDocument::factory()->for($foreign, 'claim')->create();
        Storage::disk('local')->put($foreignDocument->path, 'secret');
        $ownDocument = CarrierClaimDocument::factory()->for($own, 'claim')->create();

        $this->get(route('carrier-claims.documents.download', [$own->id, $ownDocument->id]))->assertRedirect(route('login'));
        $this->actingAs($operator)->post(route('carrier-claims.documents.store', $foreign->id), ['document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertNotFound();
        $this->actingAs($operator)->get(route('carrier-claims.documents.download', [$foreign->id, $foreignDocument->id]))->assertNotFound();
        $this->actingAs($operator)->get(route('carrier-claims.documents.download', [$own->id, $foreignDocument->id]))->assertNotFound();
        $this->actingAs($operator)->delete(route('carrier-claims.documents.destroy', [$foreign->id, $foreignDocument->id]))->assertNotFound();
        $this->actingAs($operator)->delete(route('carrier-claims.documents.destroy', [$own->id, $foreignDocument->id]))->assertNotFound();
        Storage::disk('local')->assertExists($foreignDocument->path);
        $this->assertSame(2, CarrierClaimDocument::count());
        $this->actingAs($operator)->get(route('carrier-claims.documents.download', [$own->id, $ownDocument->id]))->assertNotFound();

        [$viewer, $viewerStore] = $this->userWithStore();
        $viewerClaim = CarrierClaim::factory()->for($viewerStore)->create();
        $this->actingAs($viewer)->post(route('carrier-claims.documents.store', $viewerClaim->id), ['document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertForbidden();
    }

    public function test_document_type_size_and_count_are_validated(): void
    {
        Storage::fake('local');
        [$operator, $store] = $this->userWithStore(true);
        $claim = CarrierClaim::factory()->for($store)->create();
        $post = fn (UploadedFile $file) => $this->actingAs($operator)->post(route('carrier-claims.documents.store', $claim->id), ['document' => $file]);

        $post(UploadedFile::fake()->create('shell.php', 1, 'application/x-php'))->assertSessionHasErrors('document');
        $post(UploadedFile::fake()->create('disguised.pdf', 1, 'text/x-php'))->assertSessionHasErrors('document');
        $post(UploadedFile::fake()->create('run.exe', 1, 'application/x-msdownload'))->assertSessionHasErrors('document');
        $post(UploadedFile::fake()->create('page.html', 1, 'text/html'))->assertSessionHasErrors('document');
        $post(UploadedFile::fake()->create('big.pdf', CarrierClaimDocument::MAX_KILOBYTES + 1, 'application/pdf'))->assertSessionHasErrors('document');
        $this->actingAs($operator)->post(route('carrier-claims.documents.store', $claim->id), [])->assertSessionHasErrors('document');
        $this->assertSame(0, $claim->documents()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $post(UploadedFile::fake()->create('ok.pdf', 5120, 'application/pdf'))->assertSessionHasNoErrors();
        for ($i = 1; $i < CarrierClaimDocument::MAX_PER_CLAIM; $i++) {
            $post(UploadedFile::fake()->create("p{$i}.pdf", 1, 'application/pdf'))->assertSessionHasNoErrors();
        }
        $post(UploadedFile::fake()->create('one-too-many.pdf', 1, 'application/pdf'))->assertSessionHasErrors('document');
        $this->assertSame(CarrierClaimDocument::MAX_PER_CLAIM, $claim->documents()->count());
    }

    public function test_deleting_a_document_row_removes_its_file(): void
    {
        Storage::fake('local');
        $document = CarrierClaimDocument::factory()->create();
        Storage::disk('local')->put($document->path, 'x');

        $document->delete();

        Storage::disk('local')->assertMissing($document->path);
    }

    /** @return array{0: CarrierClaim, 1: string} a claim with one stored file under its generated path */
    private function claimWithFile(?Store $store = null): array
    {
        $claim = CarrierClaim::factory()->for($store ?? Store::factory()->create())->create();
        $path = CarrierClaimDocument::directory($claim->store_id, $claim->getKey()).'/'.Str::uuid().'.pdf';
        CarrierClaimDocument::factory()->for($claim, 'claim')->create(['path' => $path]);
        Storage::disk('local')->put($path, 'x');

        return [$claim, $path];
    }

    public function test_deleting_a_claim_removes_its_files_and_keeps_other_claims_files(): void
    {
        Storage::fake('local');
        $store = Store::factory()->create();
        [$claim, $path] = $this->claimWithFile($store);
        [, $siblingPath] = $this->claimWithFile($store);
        [, $foreignPath] = $this->claimWithFile();

        $claim->delete();

        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($siblingPath);
        Storage::disk('local')->assertExists($foreignPath);
    }

    public function test_deleting_a_store_removes_the_files_of_all_its_claims_only(): void
    {
        Storage::fake('local');
        $store = Store::factory()->create();
        [, $first] = $this->claimWithFile($store);
        [, $second] = $this->claimWithFile($store);
        [, $foreignPath] = $this->claimWithFile();

        $store->delete();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertMissing($second);
        Storage::disk('local')->assertExists($foreignPath);
    }
}
