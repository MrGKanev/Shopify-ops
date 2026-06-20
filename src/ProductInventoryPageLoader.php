<?php
declare(strict_types=1);

/**
 * Loads product catalog and inventory report pages.
 */
class ProductInventoryPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'bundlecheck'       => self::loadBundleCheck($action, $ctx),
            'productcheck'      => self::loadProductCheck($action, $ctx),
            'skudupes'          => self::loadSkuDupes($action, $ctx),
            'inventoryoversell' => self::loadInventoryOversell($action, $ctx),
            'zombieproducts'    => self::loadZombieProducts($action, $ctx),
            'inventoryaging'    => self::loadInventoryAging($action, $ctx),
            default             => [],
        };
    }

    private static function loadBundleCheck(string $action, array $ctx): array
    {
        $bcResult = null;
        $bcError  = '';
        [$bcStart, $bcEnd] = DateRange::fromRequest('bc', 30);

        ['result' => $bcResult, 'error' => $bcError, 'start' => $bcStart, 'end' => $bcEnd] =
            ScanRunner::run($action, 'scan_bundle', $ctx, 'bc', function ($ctx, $start, $end) {
                self::setLimits(300);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchAllOrders($start, $end));

                $rows = [];
                foreach ($orders as $o) {
                    if (!empty($o['cancelled_at'])) continue;
                    $fin = $o['financial_status'] ?? '';
                    if (in_array($fin, ['pending', 'voided', 'refunded', 'partially_refunded'], true)) continue;
                    if ((float)($o['total_price'] ?? 0) == 0) continue;
                    if (($o['shipping_lines'] ?? []) === []) continue;

                    $missingReq = Comparator::findMissingRequired($o);
                    if (empty($missingReq)) continue;

                    $missingParts = [];
                    foreach ($missingReq as $typeName => $items) {
                        $missingParts[] = (count($missingReq) > 1 ? "{$typeName}: " : '') . implode(', ', $items);
                    }
                    $rows[] = [
                        'shopify_id'         => $o['id']                 ?? '',
                        'order_number'       => $o['name']               ?? '',
                        'created_at'         => self::dateOnly($o['created_at']         ?? ''),
                        'email'              => $o['email']              ?? '',
                        'financial_status'   => $o['financial_status']   ?? '',
                        'fulfillment_status' => $o['fulfillment_status'] ?? '',
                        'total'              => $o['total_price']        ?? 0,
                        'order_type'         => Comparator::classifyOrder($o),
                        'missing_required'   => $missingReq,
                        'missing_text'       => implode('; ', $missingParts),
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end];
            }, 30);

        $bcConfig = Comparator::getOrderTypesConfig();
        return compact('bcResult', 'bcError', 'bcStart', 'bcEnd', 'bcConfig');
    }

    private static function loadProductCheck(string $action, array $ctx): array
    {
        $pcResult = null;
        $pcError  = '';

        if ($action === 'scan_products') {
            if ($err = self::requireShopify($ctx)) {
                $pcError = $err;
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts());
                    $scanned  = count($products);
                    $rows     = [];

                    foreach ($products as $p) {
                        $issues = [];

                        if (empty($p['images'])) {
                            $issues[] = ['level' => 'warning', 'message' => 'No product images'];
                        }

                        $desc = trim(strip_tags($p['body_html'] ?? ''));
                        if ($desc === '') {
                            $issues[] = ['level' => 'warning', 'message' => 'No description'];
                        }

                        $variantCount = count($p['variants'] ?? []);
                        $missingSkuCount = 0;
                        foreach ($p['variants'] ?? [] as $v) {
                            if (trim($v['sku'] ?? '') === '') {
                                $missingSkuCount++;
                            }
                        }
                        if ($missingSkuCount > 0) {
                            $label = $missingSkuCount . ' of ' . $variantCount . ' variant' . ($variantCount !== 1 ? 's' : '') . ' missing SKU';
                            $issues[] = ['level' => 'critical', 'message' => $label];
                        }

                        if (!empty($issues)) {
                            $rows[] = [
                                'id'       => (string)($p['id'] ?? ''),
                                'title'    => $p['title']        ?? '',
                                'vendor'   => $p['vendor']       ?? '',
                                'type'     => $p['product_type'] ?? '',
                                'status'   => $p['status']       ?? '',
                                'images'   => count($p['images']   ?? []),
                                'variants' => $variantCount,
                                'issues'   => $issues,
                                'severity' => in_array('critical', array_column($issues, 'level')) ? 'critical' : 'warning',
                            ];
                        }
                    }

                    $pcResult = [
                        'rows'     => $rows,
                        'scanned'  => $scanned,
                        'critical' => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                        'warnings' => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                    ];
                } catch (Throwable $e) {
                    $pcError = $e->getMessage();
                }
            }
        }

        return compact('pcResult', 'pcError');
    }

    private static function loadSkuDupes(string $action, array $ctx): array
    {
        $sdResult = null;
        $sdError  = '';

        if ($action === 'scan_skudupes') {
            if ($err = self::requireShopify($ctx)) {
                $sdError = $err;
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts('any'));

                    $skuMap = [];
                    $totalVariants = 0;
                    foreach ($products as $p) {
                        foreach ($p['variants'] ?? [] as $v) {
                            $totalVariants++;
                            $sku = trim($v['sku'] ?? '');
                            if ($sku === '') continue;
                            $skuMap[$sku][] = [
                                'product_id'     => (string)($p['id'] ?? ''),
                                'product_title'  => $p['title'] ?? '',
                                'product_status' => $p['status'] ?? '',
                                'variant_title'  => $v['title'] ?? '',
                            ];
                        }
                    }

                    $rows = [];
                    foreach ($skuMap as $sku => $variants) {
                        if (count($variants) > 1) {
                            $rows[] = [
                                'sku'      => $sku,
                                'count'    => count($variants),
                                'variants' => $variants,
                            ];
                        }
                    }

                    usort($rows, fn($a, $b) => $b['count'] - $a['count']);

                    $sdResult = [
                        'rows'     => $rows,
                        'scanned'  => count($products),
                        'variants' => $totalVariants,
                    ];
                } catch (Throwable $e) {
                    $sdError = $e->getMessage();
                }
            }
        }

        return compact('sdResult', 'sdError');
    }

    private static function loadInventoryOversell(string $action, array $ctx): array
    {
        $ioResult = null;
        $ioError  = '';

        if ($action === 'scan_inventory') {
            if ($err = self::requireShopify($ctx)) {
                $ioError = $err;
            } elseif ($err = self::requireSS($ctx)) {
                $ioError = $err;
            } else {
                try {
                    self::setLimits(300);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);

                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts('active'));
                    $ssOrders = self::suppressOutput(fn() => $ss->fetchAwaitingOrders());

                    $skuStock = [];
                    $skuInfo  = [];
                    foreach ($products as $p) {
                        foreach ($p['variants'] ?? [] as $v) {
                            $sku = trim($v['sku'] ?? '');
                            if ($sku === '') continue;
                            if (($v['inventory_management'] ?? '') === '') continue;
                            if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;
                            $qty = (int)($v['inventory_quantity'] ?? 0);
                            $skuStock[$sku] = ($skuStock[$sku] ?? 0) + $qty;
                            $skuInfo[$sku]  = [
                                'product_id'    => (string)($p['id'] ?? ''),
                                'product_title' => $p['title'] ?? '',
                                'variant_title' => $v['title'] ?? '',
                            ];
                        }
                    }

                    $skuAwaiting = [];
                    foreach ($ssOrders as $o) {
                        foreach ($o['items'] ?? [] as $item) {
                            $sku = trim($item['sku'] ?? '');
                            if ($sku === '') continue;
                            $skuAwaiting[$sku] = ($skuAwaiting[$sku] ?? 0) + (int)($item['quantity'] ?? 1);
                        }
                    }

                    $rows = [];
                    foreach ($skuAwaiting as $sku => $awaitingQty) {
                        if (!isset($skuStock[$sku])) continue;
                        $stock = $skuStock[$sku];
                        $shortfall = $awaitingQty - $stock;
                        if ($shortfall <= 0) continue;
                        $info = $skuInfo[$sku] ?? [];
                        $rows[] = [
                            'sku'           => $sku,
                            'product_id'    => $info['product_id']    ?? '',
                            'product_title' => $info['product_title'] ?? '(unknown)',
                            'variant_title' => $info['variant_title'] ?? '',
                            'stock'         => $stock,
                            'awaiting'      => $awaitingQty,
                            'shortfall'     => $shortfall,
                        ];
                    }
                    usort($rows, fn($a, $b) => $b['shortfall'] <=> $a['shortfall']);

                    $ioResult = [
                        'rows'             => $rows,
                        'products_scanned' => count($products),
                        'ss_orders'        => count($ssOrders),
                    ];
                } catch (Throwable $e) {
                    $ioError = $e->getMessage();
                }
            }
        }

        return compact('ioResult', 'ioError');
    }

    private static function loadZombieProducts(string $action, array $ctx): array
    {
        $zpResult = null;
        $zpError  = '';

        if ($action === 'scan_zombieproducts') {
            if ($err = self::requireShopify($ctx)) {
                $zpError = $err;
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts('active'));

                    $rows = [];
                    foreach ($products as $p) {
                        $variants = $p['variants'] ?? [];
                        if (empty($variants)) {
                            $rows[] = [
                                'id'     => (string)($p['id'] ?? ''),
                                'title'  => $p['title']        ?? '',
                                'vendor' => $p['vendor']       ?? '',
                                'type'   => $p['product_type'] ?? '',
                                'reason' => 'no_variants',
                                'detail' => 'No variants defined',
                                'stock'  => null,
                            ];
                            continue;
                        }

                        $trackedCount = 0;
                        $zeroStockCount = 0;
                        $totalStock = 0;
                        foreach ($variants as $v) {
                            if (($v['inventory_management'] ?? '') === '') continue;
                            if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;
                            $trackedCount++;
                            $qty = (int)($v['inventory_quantity'] ?? 0);
                            $totalStock += $qty;
                            if ($qty <= 0) $zeroStockCount++;
                        }

                        if ($trackedCount > 0 && $trackedCount === $zeroStockCount) {
                            $rows[] = [
                                'id'     => (string)($p['id'] ?? ''),
                                'title'  => $p['title']        ?? '',
                                'vendor' => $p['vendor']       ?? '',
                                'type'   => $p['product_type'] ?? '',
                                'reason' => 'zero_stock',
                                'detail' => "{$trackedCount} tracked variant" . ($trackedCount !== 1 ? 's' : '') . ', all at 0',
                                'stock'  => $totalStock,
                            ];
                        }
                    }

                    $zpResult = ['rows' => $rows, 'scanned' => count($products)];
                } catch (Throwable $e) {
                    $zpError = $e->getMessage();
                }
            }
        }

        return compact('zpResult', 'zpError');
    }

    private static function loadInventoryAging(string $action, array $ctx): array
    {
        ['result' => $iaResult, 'error' => $iaError, 'start' => $iaStart, 'end' => $iaEnd] =
            ScanRunner::run($action, 'scan_inventoryaging', $ctx, 'ia', function ($ctx, $start, $end) {
                self::setLimits(240);
                [$products, $orders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [
                        $shopify->fetchAllProducts('active'),
                        $shopify->fetchAllOrders($start, $end),
                    ];
                });

                $sales = [];
                foreach ($orders as $o) {
                    foreach ($o['line_items'] ?? [] as $li) {
                        $sku = trim((string)($li['sku'] ?? ''));
                        if ($sku === '') continue;
                        if (!isset($sales[$sku])) {
                            $sales[$sku] = ['qty' => 0, 'last_order' => '', 'last_date' => ''];
                        }
                        $sales[$sku]['qty'] += (int)($li['quantity'] ?? 1);
                        $date = self::dateOnly($o['created_at'] ?? '');
                        if ($date > $sales[$sku]['last_date']) {
                            $sales[$sku]['last_date'] = $date;
                            $sales[$sku]['last_order'] = $o['name'] ?? '';
                        }
                    }
                }

                $rows = [];
                $variantCount = 0;
                foreach ($products as $p) {
                    foreach ($p['variants'] ?? [] as $v) {
                        $variantCount++;
                        $sku = trim((string)($v['sku'] ?? ''));
                        if ($sku === '' || !isset($sales[$sku])) continue;
                        if (($v['inventory_management'] ?? '') === '') continue;
                        if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;
                        $stock = (int)($v['inventory_quantity'] ?? 0);
                        if ($stock > 0) continue;
                        $rows[] = [
                            'product_id'    => (string)($p['id'] ?? ''),
                            'product_title' => $p['title'] ?? '',
                            'variant_title' => $v['title'] ?? '',
                            'sku'           => $sku,
                            'stock'         => $stock,
                            'recent_qty'    => $sales[$sku]['qty'],
                            'last_order'    => $sales[$sku]['last_order'],
                            'last_date'     => $sales[$sku]['last_date'],
                        ];
                    }
                }
                usort($rows, fn($a, $b) => $b['recent_qty'] <=> $a['recent_qty']);
                return ['rows' => $rows, 'products' => count($products), 'variants' => $variantCount, 'orders' => count($orders), 'start' => $start, 'end' => $end];
            }, 30);

        return compact('iaResult', 'iaError', 'iaStart', 'iaEnd');
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function requireShopify(array $ctx): ?string
    {
        return (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A')
            ? 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.'
            : null;
    }

    private static function requireSS(array $ctx): ?string
    {
        return (!$ctx['ssKey'] || !$ctx['ssSecret'])
            ? 'SS_API_KEY / SS_API_SECRET not set in .env.'
            : null;
    }

    private static function suppressOutput(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }
}
