<?php

declare(strict_types=1);

use App\Application\Orders\UseMetafields;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use PHPUnit\Framework\TestCase;

final class MetafieldFilterParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_case_insensitive_namespace_key_and_value_filter_matches_legacy(): void
    {
        $metafields = [
            ['namespace' => 'Custom', 'key' => 'Gift_Note', 'value' => 'Happy birthday'],
            ['namespace' => 'shipping', 'key' => 'instructions', 'value' => 'Leave at BACK door'],
            ['namespace' => 'other', 'key' => 'ignored', 'value' => 'No match'],
        ];
        $legacyMethod = new ReflectionMethod(SearchLookupPageLoader::class, 'filterMetafields');
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('findByOrderNumbers')->twice()->andReturn(['1001' => [['id' => 42, 'name' => '#1001']]]);
        $gateway->shouldReceive('orderMetafields')->twice()->andReturn(['42' => $metafields]);
        $lookup = new UseMetafields($gateway);

        foreach (['gift_note', 'back'] as $filter) {
            $legacy = $legacyMethod->invoke(null, $metafields, $filter);
            $laravel = $lookup->lookup(new Store, ['1001'], $filter);
            $this->assertSame($legacy, $laravel[0]['metafields']);
        }
    }
}
