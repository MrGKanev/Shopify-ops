<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\SkuAliasMap;
use PHPUnit\Framework\TestCase;

class SkuAliasMapTest extends TestCase
{
    public function test_disabled_aliases_preserve_legacy_product_name_grouping(): void
    {
        $map = new SkuAliasMap;
        $totals = [];
        $map->add($totals, 'Shared title', 'SKU-A', 2);
        $map->add($totals, 'Shared title', 'SKU-B', 3);
        $map->add($totals, '', 'SKU-C', 4);

        $this->assertSame([['product' => 'Shared title', 'quantity' => 5]], $map->rows($totals));
    }

    public function test_alias_and_master_quantities_merge_without_losing_original_skus(): void
    {
        $map = new SkuAliasMap([['alias' => 'old', 'master' => 'master'], ['alias' => 'retailer', 'master' => 'master']]);
        $totals = [];
        $map->add($totals, 'Old listing', 'old', 2);
        $map->add($totals, 'Current listing', 'master', 3);
        $map->add($totals, 'Retail listing', 'retailer', 4);
        $map->add($totals, 'Old listing again', 'old', 1);

        $this->assertSame([['product' => 'master', 'quantity' => 10, 'sku' => 'master', 'source_skus' => ['master', 'old', 'retailer']]], $map->rows($totals));
    }

    public function test_unmapped_products_with_equal_names_remain_distinct_when_mapping_is_enabled(): void
    {
        $map = new SkuAliasMap([['alias' => 'old', 'master' => 'master']]);
        $totals = [];
        $map->add($totals, 'Shared title', 'B', 1);
        $map->add($totals, 'Shared title', 'A', 2);

        $this->assertSame([['product' => 'Shared title', 'quantity' => 2, 'sku' => 'A', 'source_skus' => ['A']], ['product' => 'Shared title', 'quantity' => 1, 'sku' => 'B', 'source_skus' => ['B']]], $map->rows($totals));
    }

    public function test_name_only_items_do_not_collide_with_a_canonical_sku_of_the_same_text(): void
    {
        $map = new SkuAliasMap([['alias' => 'old', 'master' => 'master']]);
        $totals = [];
        $map->add($totals, 'master', '', 2);
        $map->add($totals, 'Old title', 'old', 1);

        $this->assertSame([['product' => 'master', 'quantity' => 2], ['product' => 'master', 'quantity' => 1, 'sku' => 'master', 'source_skus' => ['old']]], $map->rows($totals));
    }

    public function test_numeric_skus_are_not_coerced_into_conflicting_array_keys(): void
    {
        $map = new SkuAliasMap([['alias' => '001', 'master' => '01'], ['alias' => '1', 'master' => '1-MASTER']]);

        $this->assertSame('01', $map->canonical('001'));
        $this->assertSame('1-MASTER', $map->canonical('1'));
        $this->assertSame('01', $map->canonical('01'));
        $this->assertTrue($map->isMapped('01'));
        $this->assertFalse($map->isMapped('0001'));
    }

    public function test_mapping_is_case_sensitive_and_does_not_recursively_follow_chains(): void
    {
        $map = new SkuAliasMap([['alias' => 'old', 'master' => 'master'], ['alias' => 'master', 'master' => 'next']]);

        $this->assertSame('master', $map->canonical('old'));
        $this->assertSame('OLD', $map->canonical('OLD'));
        $this->assertSame('next', $map->canonical('master'));
    }
}
