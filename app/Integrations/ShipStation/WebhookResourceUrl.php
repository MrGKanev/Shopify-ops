<?php

namespace App\Integrations\ShipStation;

use InvalidArgumentException;

class WebhookResourceUrl
{
    /** @return array{path: string, query: array<string, scalar>} */
    public function parse(string $url, string $topic, int $storeId): array
    {
        $parts = parse_url($url);
        $path = match ($topic) {
            'ORDER_NOTIFY' => '/orders',
            'SHIP_NOTIFY' => '/shipments',
            default => throw new InvalidArgumentException('Unsupported ShipStation event.'),
        };
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'ssapi.shipstation.com'
            || ($parts['port'] ?? 443) !== 443 || ($parts['path'] ?? '') !== $path || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid ShipStation resource URL.');
        }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = strtolower(rawurldecode($key));
            $value = rawurldecode($value);
            if (! in_array($key, ['storeid', 'batchid', 'importbatch', 'includeorderitems', 'includeshipmentitems'], true) || isset($query[$key]) || $value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('Invalid ShipStation resource parameters.');
            }
            $query[$key] = $value;
        }
        if (isset($query['storeid']) && (! ctype_digit($query['storeid']) || (int) $query['storeid'] !== $storeId)) {
            throw new InvalidArgumentException('ShipStation resource belongs to another store.');
        }
        $filters = ['storeId' => $storeId];
        $batchKey = $topic === 'SHIP_NOTIFY' ? 'batchid' : 'importbatch';
        if (! isset($query[$batchKey]) || preg_match('/^[a-zA-Z0-9-]+$/D', $query[$batchKey]) !== 1) {
            throw new InvalidArgumentException('ShipStation batch identity is required.');
        }
        $filters[$topic === 'SHIP_NOTIFY' ? 'batchId' : 'importBatch'] = $query[$batchKey];
        if ($topic === 'SHIP_NOTIFY') {
            $filters['includeShipmentItems'] = 'true';
        }

        return ['path' => $path, 'query' => $filters];
    }
}
