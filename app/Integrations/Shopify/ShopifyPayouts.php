<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use UnexpectedValueException;

class ShopifyPayouts
{
    public function __construct(private readonly ShopifyTransport $transport) {}

    /** @return array{payouts: list<array<string, mixed>>, next_after: string|null} */
    public function choices(Store $store, ?string $after = null): array
    {
        $response = $this->transport->graphql($store, ShopifyQueries::get('FinancialPayouts'), ['query' => null, 'after' => $after]);
        $connection = $this->connection(data_get($response, 'data.shopifyPaymentsAccount.payouts'), $after);
        foreach ($connection['nodes'] as $payout) {
            $this->identity($payout);
        }

        return ['payouts' => $connection['nodes'], 'next_after' => $connection['next']];
    }

    /** @return array{payout: array<string, mixed>, transactions: list<array<string, mixed>>, complete: bool, pages: int, notes: list<string>} */
    public function collect(Store $store, string $id): array
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1) {
            throw new UnexpectedValueException('A valid payout identity is required.');
        }
        $payout = $this->payout($store, $id);
        $transactions = [];
        $seen = [];
        $cursors = [];
        $after = null;
        $pages = 0;
        do {
            $response = $this->transport->graphql($store, ShopifyQueries::get('FinancialPayoutTransactions'), ['query' => 'payments_transfer_id:'.$id, 'after' => $after]);
            $connection = $this->connection(data_get($response, 'data.shopifyPaymentsAccount.balanceTransactions'), $after);
            foreach ($connection['nodes'] as $transaction) {
                $transactionId = $transaction['id'] ?? null;
                if (! is_string($transactionId) || preg_match('~^gid://shopify/ShopifyPaymentsBalanceTransaction/[0-9]+$~', $transactionId) !== 1 || data_get($transaction, 'associatedPayout.id') !== $payout['id']) {
                    throw new UnexpectedValueException('Shopify could not confirm payout transaction ownership.');
                }
                if (isset($seen[$transactionId])) {
                    throw new UnexpectedValueException('Shopify returned duplicate payout transactions.');
                }
                $seen[$transactionId] = true;
                $transactions[] = $transaction;
            }
            $after = $connection['next'];
            if ($after !== null && isset($cursors[$after])) {
                throw new UnexpectedValueException('Shopify payout transaction pagination did not advance.');
            }
            if ($after !== null) {
                $cursors[$after] = true;
            }
            $pages++;
        } while ($after !== null && $pages < 20);
        $fresh = $this->payout($store, $id);
        $stable = $fresh === $payout;
        $notes = $stable ? [] : ['Payout changed during the scan; rerun before comparing totals.'];
        if ($after !== null) {
            $notes[] = 'Transaction coverage was truncated; totals cannot be confirmed.';
        }

        return ['payout' => $payout, 'transactions' => $transactions, 'complete' => $stable && $after === null, 'pages' => $pages, 'notes' => $notes];
    }

    /** @return array<string, mixed> */
    private function payout(Store $store, string $id): array
    {
        $response = $this->transport->graphql($store, ShopifyQueries::get('FinancialPayouts'), ['query' => 'id:'.$id, 'after' => null]);
        $connection = $this->connection(data_get($response, 'data.shopifyPaymentsAccount.payouts'), null);
        if (count($connection['nodes']) !== 1 || $connection['next'] !== null) {
            throw new UnexpectedValueException('Exactly one accessible Shopify Payments payout is required.');
        }
        $payout = $connection['nodes'][0];
        $this->identity($payout);
        if ((string) $payout['legacyResourceId'] !== $id) {
            throw new UnexpectedValueException('Shopify returned another payout.');
        }

        return $payout;
    }

    /** @param array<string, mixed> $payout */
    private function identity(array $payout): void
    {
        $id = $payout['legacyResourceId'] ?? null;
        if ((! is_string($id) && ! is_int($id)) || preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id) !== 1 || ($payout['id'] ?? null) !== 'gid://shopify/ShopifyPaymentsPayout/'.$id) {
            throw new UnexpectedValueException('Shopify payout identity was not confirmed.');
        }
    }

    /** @return array{nodes: list<array<string, mixed>>, next: string|null} */
    private function connection(mixed $value, ?string $previous): array
    {
        if (! is_array($value) || ! is_array($value['nodes'] ?? null) || ! is_bool(data_get($value, 'pageInfo.hasNextPage'))) {
            throw new UnexpectedValueException('Shopify Payments is unavailable or pagination was not confirmed.');
        }
        foreach ($value['nodes'] as $node) {
            if (! is_array($node)) {
                throw new UnexpectedValueException('Shopify returned a malformed financial record.');
            }
        }
        $next = $value['pageInfo']['hasNextPage'] ? ($value['pageInfo']['endCursor'] ?? null) : null;
        if ($value['pageInfo']['hasNextPage'] && (! is_string($next) || $next === '' || $next === $previous)) {
            throw new UnexpectedValueException('Shopify financial pagination did not advance.');
        }

        return ['nodes' => array_values($value['nodes']), 'next' => $next];
    }
}
