<?php

namespace Tests\Unit;

use App\Domain\Reports\DuplicateAddressAnalyzer;
use Tests\TestCase;

class DuplicateAddressAnalyzerTest extends TestCase
{
    public function test_groups_normalized_addresses_only_across_distinct_emails(): void
    {
        $analyzer = new DuplicateAddressAnalyzer;
        $address = ['address1' => '1 Main St', 'city' => 'Austin', 'zip' => '78701', 'country_code' => 'US'];
        $rows = $analyzer->analyze([$this->order('A@example.com', $address), $this->order('a@example.com', $address), $this->order('B@example.com', [...$address, 'address1' => ' 1 MAIN ST '])]);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['email_count']);
        $this->assertSame(3, $rows[0]['order_count']);
    }

    public function test_excludes_missing_identity_and_sorts_largest_clusters_first(): void
    {
        $analyzer = new DuplicateAddressAnalyzer;
        $one = ['address1' => 'One', 'city' => 'X', 'zip' => '1', 'country_code' => 'US'];
        $two = ['address1' => 'Two', 'city' => 'X', 'zip' => '2', 'country_code' => 'US'];
        $rows = $analyzer->analyze([$this->order('', $one), $this->order('a@x.com', []), $this->order('a@x.com', $one), $this->order('b@x.com', $one), $this->order('c@x.com', $two), $this->order('d@x.com', $two), $this->order('e@x.com', $two)]);
        $this->assertSame([3, 2], array_column($rows, 'email_count'));
    }

    public function test_country_name_fallback_keeps_different_countries_separate(): void
    {
        $address = ['address1' => '1 Main', 'city' => 'Springfield', 'zip' => '12345'];
        $rows = (new DuplicateAddressAnalyzer)->analyze([
            $this->order('a@example.com', [...$address, 'country' => 'United States']),
            $this->order('b@example.com', [...$address, 'country' => 'Canada']),
        ]);

        $this->assertSame([], $rows);
    }

    public function test_different_apartments_and_provinces_are_not_grouped(): void
    {
        $address = ['address1' => '10 Main St', 'city' => 'Austin', 'province_code' => 'TX', 'zip' => '78701', 'country_code' => 'US', 'address2' => 'Unit 1'];

        $rows = (new DuplicateAddressAnalyzer)->analyze([
            $this->order('a@example.com', $address),
            $this->order('b@example.com', [...$address, 'address2' => 'Unit 2']),
            $this->order('c@example.com', [...$address, 'province_code' => 'CA']),
            $this->order('d@example.com', [...$address, 'address2' => '']),
            $this->order('e@example.com', [...$address, 'address2' => 'Unit1']),
        ]);

        $this->assertSame([], $rows);
    }

    public function test_shared_company_and_phone_are_context_and_not_fraud_or_exclusion(): void
    {
        $address = ['first_name' => 'Jane', 'last_name' => 'Smith', 'address1' => '10 Main St', 'address2' => 'Suite 3', 'company' => 'Shared office / forwarding', 'city' => 'Austin', 'province_code' => 'TX', 'zip' => '78701', 'country_code' => 'US', 'phone' => '+12025550123'];

        $rows = (new DuplicateAddressAnalyzer)->analyze([
            $this->order('jane@example.com', $address),
            $this->order('john@example.com', [...$address, 'first_name' => 'John', 'phone' => '(202) 555-0123']),
        ]);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['warning_only']);
        $this->assertSame(['Jane Smith', 'John Smith'], $rows[0]['names']);
        $this->assertSame(['shared_valid_phone' => true, 'company_present' => true, 'address_quality_codes' => []], $rows[0]['review_context']);
        $this->assertStringContainsString('Suite 3', $rows[0]['address_line']);
    }

    public function test_invalid_shared_phone_is_not_treated_as_a_shared_valid_contact(): void
    {
        $address = ['address1' => '10 Main St', 'city' => 'Austin', 'zip' => '78701', 'country_code' => 'US', 'phone' => '123'];

        $rows = (new DuplicateAddressAnalyzer)->analyze([$this->order('a@example.com', $address), $this->order('b@example.com', $address)]);

        $this->assertFalse($rows[0]['review_context']['shared_valid_phone']);
        $this->assertContains('invalid_phone', $rows[0]['review_context']['address_quality_codes']);
    }

    /** @param array<string, mixed> $address @return array<string, mixed> */
    private function order(string $email, array $address): array
    {
        return ['id' => '42', 'name' => '#1', 'created_at' => '2026-09-01', 'email' => $email, 'shipping_address' => $address];
    }
}
