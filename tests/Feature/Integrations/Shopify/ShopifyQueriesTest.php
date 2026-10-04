<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyQueries;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyQueriesTest extends TestCase
{
    public function test_every_document_holds_one_operation_named_after_its_file(): void
    {
        $files = glob(resource_path('graphql/shopify/*.graphql')) ?: [];

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $operation = basename($file, '.graphql');
            $document = ShopifyQueries::get($operation);

            $this->assertMatchesRegularExpression('/\A(query|mutation) '.$operation.'\b/', $document, $file);
            $this->assertSame(1, preg_match_all('/^(query|mutation) /m', $document), $file);
            $this->assertSame(substr_count($document, '{'), substr_count($document, '}'), $file);
        }
    }

    public function test_every_document_the_client_loads_exists(): void
    {
        $source = implode("\n", array_map(
            fn (string $path): string => (string) file_get_contents($path),
            glob(app_path('Integrations/Shopify/*.php')) ?: [],
        ));
        preg_match_all("/ShopifyQueries::get\\('([A-Za-z0-9]+)'\\)|paginateOrderNodes\\('([A-Za-z0-9]+)'|ordersByIds\\('([A-Za-z0-9]+)'/", $source, $matches);
        $operations = array_unique(array_filter([...$matches[1], ...$matches[2], ...$matches[3]]));

        $this->assertGreaterThan(30, count($operations));

        foreach ($operations as $operation) {
            $this->assertFileExists(ShopifyQueries::path($operation));
        }
    }

    public function test_documents_are_returned_without_a_trailing_newline(): void
    {
        $this->assertStringEndsWith('}', ShopifyQueries::get('ShopifyHealth'));
    }

    #[DataProvider('invalidOperations')]
    public function test_rejects_unknown_or_unsafe_operation_names(string $operation): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShopifyQueries::get($operation);
    }

    /** @return array<string, array{string}> */
    public static function invalidOperations(): array
    {
        return [
            'missing document' => ['NoSuchOperation'],
            'path traversal' => ['../ShopifyHealth'],
            'lowercase' => ['shopifyHealth'],
        ];
    }
}
