<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ViewHelpersTest extends TestCase
{
    // ── formatPrice ───────────────────────────────────────────────────────────

    public function testFormatPriceNull(): void
    {
        $this->assertSame('-', formatPrice(null));
    }

    public function testFormatPriceEmptyString(): void
    {
        $this->assertSame('-', formatPrice(''));
    }

    public function testFormatPriceZero(): void
    {
        $this->assertSame('$0.00', formatPrice(0));
    }

    public function testFormatPriceInteger(): void
    {
        $this->assertSame('$25.00', formatPrice(25));
    }

    public function testFormatPriceFloat(): void
    {
        $this->assertSame('$19.99', formatPrice(19.99));
    }

    public function testFormatPriceStringFloat(): void
    {
        $this->assertSame('$99.90', formatPrice('99.9'));
    }

    public function testFormatPriceThousandsSeparator(): void
    {
        $this->assertSame('$1,234.50', formatPrice(1234.5));
    }

    // ── financialChip ─────────────────────────────────────────────────────────

    public function testFinancialChipPaid(): void
    {
        $this->assertSame('chip-paid', financialChip('paid'));
    }

    public function testFinancialChipCaseInsensitive(): void
    {
        $this->assertSame('chip-paid', financialChip('PAID'));
    }

    public function testFinancialChipPartiallyPaid(): void
    {
        $this->assertSame('chip-partial', financialChip('partially_paid'));
    }

    public function testFinancialChipUnpaid(): void
    {
        $this->assertSame('chip-unpaid', financialChip('unpaid'));
    }

    public function testFinancialChipPending(): void
    {
        $this->assertSame('chip-unpaid', financialChip('pending'));
    }

    public function testFinancialChipUnknown(): void
    {
        $this->assertSame('chip-unknown', financialChip('voided'));
    }

    // ── formatAddressLine ─────────────────────────────────────────────────────

    public function testFormatAddressLineNull(): void
    {
        $this->assertSame('', formatAddressLine(null));
    }

    public function testFormatAddressLineEmpty(): void
    {
        $this->assertSame('', formatAddressLine([]));
    }

    public function testFormatAddressLineCityCountry(): void
    {
        $result = formatAddressLine(['city' => 'New York', 'country_code' => 'US']);
        $this->assertSame('New York, US', $result);
    }

    public function testFormatAddressLineFull(): void
    {
        $result = formatAddressLine([
            'address1'     => '123 Main St',
            'address2'     => 'Apt 4',
            'city'         => 'Boston',
            'province_code'=> 'MA',
            'zip'          => '02101',
            'country_code' => 'US',
        ]);
        $this->assertSame('123 Main St Apt 4, Boston, MA, 02101, US', $result);
    }

    public function testFormatAddressLineSkipsEmpty(): void
    {
        $result = formatAddressLine(['city' => 'Paris', 'zip' => '', 'country_code' => 'FR']);
        $this->assertSame('Paris, FR', $result);
    }

    public function testFormatAddressLineFallsBackToState(): void
    {
        // province_code absent, state present
        $result = formatAddressLine(['city' => 'Austin', 'state' => 'TX', 'country_code' => 'US']);
        $this->assertSame('Austin, TX, US', $result);
    }

    // ── topbar ────────────────────────────────────────────────────────────────

    public function testTopbarContainsTitle(): void
    {
        $html = topbar('My Page');
        $this->assertStringContainsString('<h1>My Page</h1>', $html);
        $this->assertStringContainsString('class="topbar"', $html);
    }

    public function testTopbarContainsMeta(): void
    {
        $html = topbar('Title', 'Some subtitle');
        $this->assertStringContainsString('<div class="meta">Some subtitle</div>', $html);
    }

    public function testTopbarNoMetaWhenEmpty(): void
    {
        $html = topbar('Title');
        $this->assertStringNotContainsString('class="meta"', $html);
    }

    public function testTopbarEscapesMeta(): void
    {
        $html = topbar('T', '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── featureInfoStart / featureInfoEnd ─────────────────────────────────────

    public function testFeatureInfoStructure(): void
    {
        $html = featureInfoStart('mykey', 'My Label') . '<p>body</p>' . featureInfoEnd();

        $this->assertStringContainsString('data-info-key="mykey"', $html);
        $this->assertStringContainsString('About: My Label', $html);
        $this->assertStringContainsString('class="feature-info-body"', $html);
        $this->assertStringContainsString('<p>body</p>', $html);
    }
}
