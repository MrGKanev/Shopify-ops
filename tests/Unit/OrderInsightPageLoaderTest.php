<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ShipStation.php';
require_once __DIR__ . '/../../src/OrderInsightPageLoader.php';

use PHPUnit\Framework\TestCase;

class OrderInsightPageLoaderTest extends TestCase
{
    private array $previousGet;
    private array $previousPost;

    protected function setUp(): void
    {
        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testCompareInitialStateUsesQueryInputs(): void
    {
        $_GET = ['a' => '#1001', 'b' => '1002'];

        $data = OrderInsightPageLoader::load('compare', '', $this->ctx());

        $this->assertNull($data['compareResult']);
        $this->assertSame('', $data['compareError']);
        $this->assertSame('#1001', $data['compareA']);
        $this->assertSame('1002', $data['compareB']);
    }

    public function testCompareRequiresTwoOrderNumbersBeforeCredentials(): void
    {
        $_POST = ['compare_a' => '#1001', 'compare_b' => ''];

        $data = OrderInsightPageLoader::load('compare', 'compare_orders', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['compareResult']);
        $this->assertSame('Enter two order numbers to compare.', $data['compareError']);
        $this->assertSame('1001', $data['compareA']);
        $this->assertSame('', $data['compareB']);
    }

    public function testCompareMissingShopifyCredentials(): void
    {
        $_POST = ['compare_a' => '#1001', 'compare_b' => '1002'];

        $data = OrderInsightPageLoader::load('compare', 'compare_orders', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['compareResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['compareError']);
        $this->assertSame('1001', $data['compareA']);
        $this->assertSame('1002', $data['compareB']);
    }

    public function testTimelineInitialStateUsesQueryInput(): void
    {
        $_GET = ['order' => '#1001'];

        $data = OrderInsightPageLoader::load('timeline', '', $this->ctx());

        $this->assertSame('#1001', $data['tlInput']);
        $this->assertNull($data['tlResult']);
        $this->assertSame('', $data['tlError']);
    }

    public function testTimelineRequiresOrderNumberBeforeCredentials(): void
    {
        $_POST = ['tl_order' => ''];

        $data = OrderInsightPageLoader::load('timeline', 'order_timeline', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertSame('', $data['tlInput']);
        $this->assertNull($data['tlResult']);
        $this->assertSame('Enter an order number.', $data['tlError']);
    }

    public function testTimelineMissingShopifyCredentials(): void
    {
        $_POST = ['tl_order' => '#1001'];

        $data = OrderInsightPageLoader::load('timeline', 'order_timeline', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertSame('#1001', $data['tlInput']);
        $this->assertNull($data['tlResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['tlError']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], OrderInsightPageLoader::load('unknown', '', $this->ctx()));
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
            'ssKey'        => 'ss_key',
            'ssSecret'     => 'ss_secret',
        ];
    }
}
