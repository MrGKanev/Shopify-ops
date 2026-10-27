<?php

namespace App\Application\Orders;

use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Throwable;

class OperationalFieldExportColumns
{
    public const MAX_ORDERS = 200;

    public function __construct(private readonly LoadOperationalFields $fields) {}

    /**
     * Extra CSV columns (value and status per field) for exports that already have one row per order. Read-only.
     * Orders that cannot be matched to exactly one ShipStation order, orders beyond the cap and a failed lookup are
     * "unreadable", never "missing", so an export cannot claim a value is absent when it was not read.
     *
     * @param  list<string>  $orderNumbers
     * @return array{headers: list<string>, cells: array<string, list<string>>, fallback: list<string>}
     */
    public function handle(Store $store, array $orderNumbers): array
    {
        $definitions = $store->operationalFields()->orderBy('alias')->get();
        $headers = [];
        foreach ($definitions as $definition) {
            $headers[] = $definition->alias;
            $headers[] = $definition->alias.' (status)';
        }
        if ($definitions->isEmpty()) {
            return ['headers' => [], 'cells' => [], 'fallback' => []];
        }
        $numbers = array_slice(array_values(array_unique(array_filter($orderNumbers, fn (string $number): bool => $number !== ''))), 0, self::MAX_ORDERS);
        $found = [];
        if ($numbers !== []) {
            try {
                foreach ($this->fields->handle($store, $numbers)['rows'] as $row) {
                    $cells = [];
                    foreach ($row['fields'] as $field) {
                        $cells[] = $field['value'];
                        $cells[] = $field['state'];
                    }
                    $found[$row['order']] = $cells;
                }
            } catch (Throwable $exception) {
                Log::warning('Operational field export columns lookup failed.', ['exception_type' => $exception::class]);
                $found = [];
            }
        }
        $fallback = [];
        foreach ($definitions as $unused) {
            $fallback[] = '';
            $fallback[] = 'unreadable';
        }

        return ['headers' => $headers, 'cells' => $found, 'fallback' => $fallback];
    }
}
