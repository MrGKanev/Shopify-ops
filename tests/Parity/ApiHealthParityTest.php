<?php

declare(strict_types=1);

use App\Application\Health\CheckApiHealth;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use PHPUnit\Framework\TestCase;

final class ApiHealthParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_shopify_success_and_missing_scope_semantics_match_and_version_guard_stays_visible(): void
    {
        foreach ([
            [['read_orders', 'read_fulfillments'], Shopify::API_VERSION],
            [['read_orders'], Shopify::API_VERSION],
            [['read_orders', 'read_fulfillments'], '2026-04'],
        ] as [$scopes, $returnedVersion]) {
            $legacy = ApiHealth::checkShopify('acme', 'token', static fn (): array => [
                'ok' => true, 'error' => '', 'headers' => ['x-shopify-api-version' => $returnedVersion],
                'json' => ['data' => ['shop' => ['name' => 'Acme'], 'currentAppInstallation' => ['accessScopes' => array_map(static fn (string $scope): array => ['handle' => $scope], $scopes)]]],
            ]);
            $gateway = Mockery::mock(ShopifyAdminGateway::class);
            $gateway->shouldReceive('healthCheck')->once()->andReturn([
                'shop_name' => 'Acme', 'scopes' => $scopes, 'requested_version' => Shopify::API_VERSION, 'returned_version' => $returnedVersion,
            ]);
            $checker = new CheckApiHealth($gateway, Mockery::mock(ShipStationClientFactory::class));
            $method = new ReflectionMethod($checker, 'checkShopify');
            $laravel = $method->invoke($checker, $this->store());

            $this->assertSame($legacy['scopes'], $laravel['scopes']);
            $this->assertSame($legacy['missing_scopes'], $laravel['missing_scopes']);
            if ($returnedVersion === '2026-04') {
                $this->assertTrue($legacy['ok']);
                $this->assertFalse($laravel['ok']);
            } else {
                $this->assertSame($legacy['ok'], $laravel['ok']);
            }
        }
    }

    private function store(): Store
    {
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('getAttribute')->with('shopify_store')->andReturn('acme');
        $store->shouldReceive('getAttribute')->with('shopify_access_token')->andReturn('token');

        return $store;
    }
}
