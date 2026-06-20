<?php
declare(strict_types=1);

/**
 * Catalog and fulfillment-order utility queries.
 */
class ShopifyCatalogAndFulfillment
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(string $status = 'active'): array
    {
        $all      = [];
        $queryArg = ShopifyGraphQLQueries::productStatusGraphQLArg($status);
        $template = <<<GQL
        {
          products(first: 250{$queryArg}{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                title
                status
                descriptionHtml
                vendor
                productType
                mediaCount { count }
                variants(first: 250) {
                  edges {
                    node {
                      id
                      legacyResourceId
                      title
                      sku
                      barcode
                      inventoryQuantity
                      inventoryPolicy
                      inventoryItem { tracked }
                    }
                  }
                }
              }
            }
          }
        }
        GQL;

        $this->client->paginateGraphQL(
            $template,
            'products',
            function (array $edges) use (&$all) {
                foreach ($edges as $edge) {
                    $all[] = ShopifyGraphQLNormalizer::normalizeProduct($edge['node'] ?? []);
                }
            },
            1000
        );

        return $all;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOnHoldFulfillmentOrders(string $startDate, string $endDate): array
    {
        $all      = [];
        $template = <<<'GQL'
        {
          fulfillmentOrders(first: 250, query: "status:on_hold"{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                status
                order {
                  id
                  legacyResourceId
                  name
                  email
                  createdAt
                  displayFinancialStatus
                  displayFulfillmentStatus
                  totalPriceSet { shopMoney { amount } }
                }
                fulfillmentHolds {
                  reason
                  reasonNotes
                }
              }
            }
          }
        }
        GQL;

        $this->client->paginateGraphQL($template, 'fulfillmentOrders', function (array $edges) use (&$all, $startDate, $endDate) {
            foreach ($edges as $e) {
                $node      = $e['node'];
                $orderDate = substr($node['order']['createdAt'] ?? '', 0, 10);
                if ($orderDate >= $startDate && $orderDate <= $endDate) {
                    $all[] = $node;
                }
            }
        }, 40);

        return $all;
    }
}
