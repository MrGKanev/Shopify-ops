<?php

namespace App\Domain\Reports;

use CommerceGuys\Addressing\Country\CountryRepository;

class CustomsReadinessAnalyzer
{
    public function __construct(private readonly CountryRepository $countries = new CountryRepository) {}

    /** @param array<string, mixed> $variant
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>|null */
    public function variant(array $variant, array $products, bool $ssComplete, string $currency = ''): ?array
    {
        $inventory = is_array($variant['inventoryItem'] ?? null) ? $variant['inventoryItem'] : [];
        if (($inventory['requiresShipping'] ?? null) === false) {
            return null;
        }
        $sku = $this->text($variant['sku'] ?? null);
        $matches = array_values(array_filter($products, fn (array $product): bool => $sku !== '' && $this->text($product['sku'] ?? null) === $sku && ($product['active'] ?? null) !== false));
        $ambiguous = count($matches) > 1 || (int) ($inventory['duplicateSkuCount'] ?? 0) > 0;
        $default = count($matches) === 1 && ! $ambiguous ? $matches[0] : [];
        $shopify = [
            'hs' => $this->hs($inventory['harmonizedSystemCode'] ?? null),
            'origin' => strtoupper($this->text($inventory['countryCodeOfOrigin'] ?? null)),
            'description' => $this->text($variant['customs_title'] ?? $variant['title'] ?? null),
            'value' => $this->positive($variant['price'] ?? null),
            'currency' => $currency,
            'grams' => $this->grams(data_get($inventory, 'measurement.weight.value'), data_get($inventory, 'measurement.weight.unit')),
        ];
        $ss = [
            'hs' => $this->hs($default['customsTariffNo'] ?? null),
            'origin' => strtoupper($this->text($default['customsCountryCode'] ?? null)),
            'description' => $this->text($default['customsDescription'] ?? null),
            'value' => $this->positive($default['customsValue'] ?? null),
            'currency' => '',
            'grams' => $this->grams($default['weightOz'] ?? null, 'ounces'),
        ];
        $findings = [];
        if (($inventory['requiresShipping'] ?? null) !== true) {
            $findings[] = 'Shipping requirement is unconfirmed.';
        }
        if ($sku === '') {
            $findings[] = 'Missing SKU prevents matching ShipStation defaults.';
        }
        if ($ambiguous) {
            $findings[] = 'Duplicate SKU prevents choosing a ShipStation product default.';
        }
        if (! $ssComplete) {
            $findings[] = 'ShipStation product coverage is incomplete; defaults cannot be confirmed.';
        }
        if (($default['noCustoms'] ?? null) === true) {
            $findings[] = 'ShipStation excludes this product from customs; review the exemption.';
        }
        foreach (['hs' => 'HS code', 'origin' => 'origin country'] as $field => $label) {
            $validShopify = $field === 'hs' ? $this->validHs($shopify[$field]) : $this->validCountry($shopify[$field]);
            $validSs = $field === 'hs' ? $this->validHs($ss[$field]) : $this->validCountry($ss[$field]);
            if (! $validShopify && ! $validSs) {
                $findings[] = $field === 'hs' ? 'No usable HS code in the available sources.' : 'No usable origin country in the available sources.';
            }
            if ($validShopify && $validSs && ($field === 'hs' ? ! $this->compatibleHs($shopify[$field], $ss[$field]) : $shopify[$field] !== $ss[$field])) {
                $findings[] = $field === 'hs' ? 'Shopify and ShipStation HS codes differ; review the intended override.' : 'Shopify and ShipStation origin countries differ; review the intended override.';
            }
        }
        if ($shopify['description'] === '' && $ss['description'] === '') {
            $findings[] = 'No description in the available sources.';
        }
        if ($shopify['value'] === null && $ss['value'] === null) {
            $findings[] = 'No positive declared-value candidate in the available sources.';
        }
        if ($shopify['grams'] === null && $ss['grams'] === null) {
            $findings[] = 'No positive item weight in the available sources.';
        }
        if ($shopify['grams'] !== null && $ss['grams'] !== null && abs($shopify['grams'] - $ss['grams']) > 0.1) {
            $findings[] = 'Shopify and ShipStation item weights differ.';
        }

        return ['id' => $this->text($variant['id'] ?? null), 'sku' => $sku, 'title' => $shopify['description'], 'sources' => ['Shopify' => $shopify, 'ShipStation default' => $ss], 'findings' => $findings, 'status' => $findings === [] ? 'defaults_available' : 'review'];
    }

    /** @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $variants
     * @param array<string, mixed>|null $ss
     * @return array{declarations: list<array<string, mixed>>, findings: list<string>, from: string, to: string, status: string} */
    public function shipment(array $order, array $lines, array $variants, ?array $ss, string $from, bool $itemsComplete = true, bool $mappingConfirmed = false): array
    {
        $to = strtoupper($this->text(data_get($ss, 'shipTo.country') ?? data_get($order, 'shippingAddress.countryCodeV2')));
        $findings = [];
        $declarations = [];
        if (! $this->validCountry($from) || ! $this->validCountry($to)) {
            $findings[] = 'Ship-from warehouse or destination country is unconfirmed.';
        } elseif ($from !== $to) {
            $findings[] = 'Cross-border route: review customs applicability for the actual carrier, service and territories.';
        }
        if ($ss === null) {
            $findings[] = 'No imported ShipStation order: product defaults do not prove a prepared declaration.';
        } elseif (! in_array($ss['orderStatus'] ?? null, ['awaiting_shipment', 'awaiting_payment', 'on_hold'], true)) {
            $findings[] = 'ShipStation order is not an upcoming shipment.';
        }
        $customs = data_get($ss, 'internationalOptions.customsItems');
        if (is_array($customs) && ! array_is_list($customs)) {
            $findings[] = 'Malformed ShipStation customs row.';
            $customs = [];
        }
        if (! is_array($customs) || $customs === []) {
            if ($from !== $to || $from === '') {
                $findings[] = 'No prepared customs rows. Review whether this route requires a declaration.';
            }
        } else {
            $ids = [];
            foreach ($customs as $index => $item) {
                if (! is_array($item)) {
                    $findings[] = 'Malformed ShipStation customs row.';

                    continue;
                }
                $id = $this->text($item['customsItemId'] ?? null);
                if ($id !== '' && isset($ids[$id])) {
                    $findings[] = 'Duplicate customs row identity; quantities cannot be confirmed.';
                }
                $ids[$id] = true;
                $row = [
                    'id' => $id !== '' ? $id : (string) ($index + 1), 'hs' => $this->hs($item['harmonizedTariffCode'] ?? null),
                    'origin' => strtoupper($this->text($item['countryOfOrigin'] ?? null)),
                    'description' => $this->text($item['description'] ?? null),
                    'quantity' => $this->positive($item['quantity'] ?? null),
                    'value' => $this->positive($item['value'] ?? null), 'currency' => 'USD',
                ];
                $row['findings'] = [];
                if (! $this->validHs($row['hs'])) {
                    $row['findings'][] = 'Prepared declaration has a missing or malformed HS code.';
                }
                if (! $this->validCountry($row['origin'])) {
                    $row['findings'][] = 'Prepared declaration has a missing or invalid origin country.';
                }
                if ($row['description'] === '') {
                    $row['findings'][] = 'Prepared declaration has no description.';
                }
                if ($row['quantity'] === null || floor($row['quantity']) !== $row['quantity']) {
                    $row['findings'][] = 'Prepared declaration has no positive whole-item quantity.';
                }
                if ($row['value'] === null) {
                    $row['findings'][] = 'Prepared declaration has no positive value; review gifts or zero-value exceptions.';
                }
                $declarations[] = $row;
            }
            if ($mappingConfirmed && $itemsComplete && count($lines) === 1 && count($declarations) === 1 && $declarations[0]['quantity'] === (float) $lines[0]['pending_quantity']) {
                foreach ($variants[0]['sources'] ?? [] as $source) {
                    if ($this->validHs($source['hs']) && $this->validHs($declarations[0]['hs']) && ! $this->compatibleHs($source['hs'], $declarations[0]['hs'])) {
                        $findings[] = 'Prepared HS code differs from product data; review the intended override.';
                    }
                    if ($this->validCountry($source['origin']) && $this->validCountry($declarations[0]['origin']) && $source['origin'] !== $declarations[0]['origin']) {
                        $findings[] = 'Prepared origin differs from product data; review the intended override.';
                    }
                }
            } else {
                $findings[] = 'Customs rows cannot be reliably mapped to individual items; V1 has no guaranteed SKU link.';
            }
            $expected = array_sum(array_column($lines, 'pending_quantity'));
            $declared = array_sum(array_map(fn (array $row): float => $row['quantity'] ?? 0.0, $declarations));
            if ($itemsComplete && (float) $expected !== $declared) {
                $findings[] = 'Customs quantity differs from pending Shopify items; review split or partial shipments.';
            }
        }
        if ($ss !== null && $this->grams(data_get($ss, 'weight.value'), data_get($ss, 'weight.units')) === null) {
            $findings[] = 'ShipStation package weight is missing or its unit is unconfirmed.';
        }
        if (! $itemsComplete) {
            $findings[] = 'Shopify item coverage is incomplete; declaration quantities and item mapping are unconfirmed.';
        }
        if (($order['cancelledAt'] ?? null) !== null || ($order['displayFulfillmentStatus'] ?? null) === 'FULFILLED' || $lines === []) {
            $findings[] = 'No upcoming physical Shopify items were confirmed.';
        }

        return ['declarations' => $declarations, 'findings' => array_values(array_unique($findings)), 'from' => $from, 'to' => $to, 'status' => 'review'];
    }

    /** @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $declarations
     * @return list<array<string, mixed>> */
    public function preparedCoverage(array $rows, array $declarations): array
    {
        if ($declarations === []) {
            return $rows;
        }
        $covered = [];
        foreach ([
            'No usable HS code in the available sources.' => fn (array $row): bool => $this->validHs($row['hs']),
            'No usable origin country in the available sources.' => fn (array $row): bool => $this->validCountry($row['origin']),
            'No description in the available sources.' => fn (array $row): bool => $row['description'] !== '',
            'No positive declared-value candidate in the available sources.' => fn (array $row): bool => $row['value'] !== null,
        ] as $message => $valid) {
            if (count(array_filter($declarations, $valid)) === count($declarations)) {
                $covered[] = $message;
            }
        }
        foreach ($rows as &$row) {
            $supplied = array_intersect($row['findings'], $covered);
            if ($supplied !== []) {
                $row['findings'] = array_values(array_diff($row['findings'], $covered));
                $row['findings'][] = 'Prepared declarations supply fields missing from defaults; verify their item mapping.';
                $row['status'] = 'review';
            }
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $lines */
    public function matchesPendingItems(array $lines, mixed $ssItems): bool
    {
        if (! is_array($ssItems) || $ssItems === [] || $lines === []) {
            return false;
        }
        $expected = [];
        foreach ($lines as $line) {
            $id = preg_replace('~^gid://shopify/LineItem/~', '', $this->text($line['id'] ?? null));
            if ($id === '' || isset($expected[$id])) {
                return false;
            }
            $expected[$id] = $line['pending_quantity'];
        }
        $actual = [];
        foreach ($ssItems as $item) {
            if (! is_array($item)) {
                return false;
            }
            if (($item['adjustment'] ?? false) === true) {
                continue;
            }
            $id = preg_replace('~^gid://shopify/LineItem/~', '', $this->text($item['lineItemKey'] ?? null));
            $quantity = $item['quantity'] ?? null;
            if ($id === '' || ! is_numeric($quantity) || (float) $quantity <= 0 || floor((float) $quantity) !== (float) $quantity || isset($actual[$id])) {
                return false;
            }
            $actual[$id] = (int) $quantity;
        }
        ksort($expected);
        ksort($actual);

        return $expected === $actual;
    }

    public function validCountry(string $value): bool
    {
        return preg_match('/^[A-Z]{2}$/', $value) === 1 && isset($this->countries->getList()[$value]);
    }

    public function grams(mixed $value, mixed $unit): ?float
    {
        $number = $this->positive($value);
        $factor = match (strtolower($this->text($unit))) {
            'grams' => 1, 'kilograms' => 1000, 'ounces' => 28.349523125, 'pounds' => 453.59237,
            default => null,
        };

        return $number !== null && $factor !== null ? $number * $factor : null;
    }

    private function hs(mixed $value): string
    {
        return str_replace(['.', ' '], '', $this->text($value));
    }

    private function validHs(string $value): bool
    {
        return preg_match('/^[0-9]{6,13}$/', $value) === 1;
    }

    private function compatibleHs(string $one, string $two): bool
    {
        return str_starts_with($one, $two) || str_starts_with($two, $one);
    }

    private function positive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
