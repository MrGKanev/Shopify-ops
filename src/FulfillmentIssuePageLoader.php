<?php
declare(strict_types=1);

/**
 * Loads fulfillment and shipping issue scan pages.
 */
class FulfillmentIssuePageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'onholdstall'  => self::loadOnHoldStall($action, $ctx),
            'notracking'   => self::loadNoTracking($action, $ctx),
            'postshipaddr' => self::loadPostShipAddrChange($action, $ctx),
            'ssshipped'    => self::loadSsShippedUnfulfilled($action, $ctx),
            'slabreaches'  => self::loadSlaBreaches($action, $ctx),
            'shipmentaging'=> self::loadShipmentAging($action, $ctx),
            default        => [],
        };
    }

    private static function loadOnHoldStall(string $action, array $ctx): array
    {
        ['result' => $ohResult, 'error' => $ohError, 'start' => $ohStart, 'end' => $ohEnd] =
            ScanRunner::run($action, 'scan_onhold', $ctx, 'oh', function ($ctx, $start, $end) {
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $nodes   = self::suppressOutput(fn() => $shopify->fetchOnHoldFulfillmentOrders($start, $end));

                $now  = time();
                $rows = [];
                foreach ($nodes as $node) {
                    $order   = $node['order'];
                    $created = $order['createdAt'] ?? '';
                    $days    = $created ? (int)floor(($now - strtotime($created)) / 86400) : 0;
                    $holds   = $node['fulfillmentHolds'] ?? [];
                    $rows[] = [
                        'shopify_id'   => $order['legacyResourceId']            ?? '',
                        'order_number' => $order['name']                        ?? '',
                        'created_at'   => self::dateOnly($created),
                        'days_waiting' => $days,
                        'email'        => $order['email']                       ?? '',
                        'total'        => $order['totalPriceSet']['shopMoney']['amount'] ?? '',
                        'financial'    => $order['displayFinancialStatus']      ?? '',
                        'fulfillment'  => $order['displayFulfillmentStatus']    ?? '',
                        'hold_reason'  => $holds[0]['reason']                  ?? '',
                        'hold_notes'   => $holds[0]['reasonNotes']             ?? '',
                    ];
                }
                usort($rows, fn($a, $b) => $b['days_waiting'] <=> $a['days_waiting']);
                return ['rows' => $rows, 'start' => $start, 'end' => $end];
            }, 90);

        return compact('ohResult', 'ohError', 'ohStart', 'ohEnd');
    }

    private static function loadNoTracking(string $action, array $ctx): array
    {
        $ntThreshold = max(1, (int)($_POST['nt_threshold'] ?? $_GET['nt_threshold'] ?? 24));

        ['result' => $ntResult, 'error' => $ntError, 'start' => $ntStart, 'end' => $ntEnd] =
            ScanRunner::run($action, 'scan_notracking', $ctx, 'nt', function ($ctx, $start, $end) use (&$ntThreshold) {
                $ntThreshold = max(1, (int)($_POST['nt_threshold'] ?? 24));
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchFulfilledOrdersWithTracking($start, $end));

                $now  = time();
                $rows = [];
                foreach ($orders as $o) {
                    $missing = [];
                    foreach ($o['fulfillments'] ?? [] as $f) {
                        if (trim($f['tracking_number'] ?? '') !== '') continue;
                        $createdAt = $f['created_at'] ?? '';
                        $hoursAgo  = $createdAt ? (int)(($now - strtotime($createdAt)) / 3600) : 0;
                        if ($hoursAgo < $ntThreshold) continue;
                        $missing[] = [
                            'id'         => $f['id']       ?? '',
                            'created_at' => self::dateOnly($createdAt),
                            'hours_ago'  => $hoursAgo,
                            'status'     => $f['shipment_status'] ?? $f['status'] ?? '',
                            'company'    => $f['tracking_company'] ?? '',
                        ];
                    }
                    if (empty($missing)) continue;
                    $rows[] = [
                        'shopify_id'   => $o['id']          ?? '',
                        'order_number' => $o['name']        ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'email'        => $o['email']       ?? '',
                        'total'        => $o['total_price'] ?? '',
                        'financial'    => $o['financial_status']   ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?? '',
                        'missing'      => $missing,
                    ];
                }
                usort($rows, fn($a, $b) => ($b['missing'][0]['hours_ago'] ?? 0) <=> ($a['missing'][0]['hours_ago'] ?? 0));
                return [
                    'rows'      => $rows,
                    'scanned'   => count($orders),
                    'start'     => $start,
                    'end'       => $end,
                    'threshold' => $ntThreshold,
                ];
            });

        return compact('ntResult', 'ntError', 'ntStart', 'ntEnd', 'ntThreshold');
    }

    private static function loadPostShipAddrChange(string $action, array $ctx): array
    {
        ['result' => $psResult, 'error' => $psError, 'start' => $psStart, 'end' => $psEnd] =
            ScanRunner::run($action, 'scan_postshipaddr', $ctx, 'ps', function ($ctx, $start, $end) {
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $entries = self::suppressOutput(fn() => $shopify->fetchPostShipAddressChanges($start, $end));

                $rows = [];
                foreach ($entries as $e) {
                    $o    = $e['order'];
                    $addr = $o['shipping_address'] ?? null;
                    $addrLine = $addr ? implode(', ', array_filter([
                        $addr['address1']      ?? '',
                        $addr['city']          ?? '',
                        $addr['province_code'] ?? '',
                        $addr['zip']           ?? '',
                        $addr['country_code']  ?? '',
                    ])) : '';
                    $changedTs     = strtotime($e['changed_at']     ?? '');
                    $fulfillTs     = strtotime($e['fulfillment_at'] ?? '');
                    $minsAfterShip = ($changedTs && $fulfillTs) ? max(0, (int)(($changedTs - $fulfillTs) / 60)) : 0;
                    $rows[] = [
                        'shopify_id'      => $o['id']          ?? '',
                        'order_number'    => $o['name']        ?? '',
                        'created_at'      => self::dateOnly($o['created_at']     ?? ''),
                        'fulfillment_at'  => self::dateOnly($e['fulfillment_at'] ?? ''),
                        'changed_at'      => substr($e['changed_at'] ?? '', 0, 16),
                        'mins_after_ship' => $minsAfterShip,
                        'email'           => $o['email']       ?? '',
                        'total'           => $o['total_price'] ?? '',
                        'financial'       => $o['financial_status']   ?? '',
                        'fulfillment'     => $o['fulfillment_status'] ?? '',
                        'addr_name'       => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                        'addr_line'       => $addrLine,
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));
                return ['rows' => $rows, 'start' => $start, 'end' => $end];
            });

        return compact('psResult', 'psError', 'psStart', 'psEnd');
    }

    private static function loadSsShippedUnfulfilled(string $action, array $ctx): array
    {
        $ssuResult = null;
        $ssuError  = '';
        [$ssuStart, $ssuEnd] = DateRange::fromRequest('ssu');

        ['result' => $ssuResult, 'error' => $ssuError, 'start' => $ssuStart, 'end' => $ssuEnd] =
            ScanRunner::run($action, 'scan_ssshipped', $ctx, 'ssu', function ($ctx, $start, $end) {
                self::setLimits(300);

                [$ssOrders, $shOrders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [
                        $ss->fetchAllOrders($start, $end),
                        $shopify->fetchAllOrders($start, $end),
                    ];
                });

                $shIndex = [];
                foreach ($shOrders as $o) {
                    $num = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
                    if ($num) {
                        $shIndex[$num] = [
                            'fulfillment_status' => $o['fulfillment_status'] ?? '',
                            'financial_status'   => $o['financial_status']   ?? '',
                            'shopify_id'         => $o['id']                 ?? '',
                        ];
                    }
                }

                $rows = [];
                foreach ($ssOrders as $o) {
                    if (($o['orderStatus'] ?? '') !== 'shipped') continue;
                    $num = Comparator::normalise((string)($o['orderNumber'] ?? ''));
                    if (!$num || !isset($shIndex[$num])) continue;

                    $sh            = $shIndex[$num];
                    $shFulfillment = $sh['fulfillment_status'] ?? '';
                    if ($shFulfillment === 'fulfilled') continue;

                    $rows[] = [
                        'ss_order_id'    => $o['orderId']      ?? '',
                        'order_number'   => $o['orderNumber']  ?? '',
                        'order_date'     => self::dateOnly($o['orderDate'] ?? ''),
                        'customer'       => trim($o['shipTo']['name'] ?? ''),
                        'email'          => $o['customerEmail'] ?? '',
                        'total'          => $o['orderTotal']   ?? 0,
                        'sh_fulfillment' => $shFulfillment ?: 'unfulfilled',
                        'sh_financial'   => $sh['financial_status'] ?? '',
                        'shopify_id'     => $sh['shopify_id'] ?? '',
                        'ss_url'         => $o['orderId'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode((string)$o['orderId']) : null,
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['order_date'], $a['order_date']));

                $shippedCount = count(array_filter($ssOrders, fn($o) => ($o['orderStatus'] ?? '') === 'shipped'));
                return [
                    'rows'          => $rows,
                    'shipped_total' => $shippedCount,
                    'start'         => $start,
                    'end'           => $end,
                ];
            }, 30, true);

        return compact('ssuResult', 'ssuError', 'ssuStart', 'ssuEnd');
    }

    private static function loadSlaBreaches(string $action, array $ctx): array
    {
        $slaThreshold = max(1, (int)($_POST['sla_threshold'] ?? $_GET['sla_threshold'] ?? 3));

        ['result' => $slaResult, 'error' => $slaError, 'start' => $slaStart, 'end' => $slaEnd] =
            ScanRunner::run($action, 'scan_sla', $ctx, 'sla', function ($ctx, $start, $end) use (&$slaThreshold) {
                $slaThreshold = max(1, (int)($_POST['sla_threshold'] ?? 3));
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForSla($start, $end));

                $now  = time();
                $rows = [];
                foreach ($orders as $o) {
                    if (!empty($o['cancelled_at'])) continue;
                    if (in_array($o['financial_status'] ?? '', ['refunded', 'voided'], true)) continue;

                    $createdTs = strtotime($o['created_at'] ?? '');
                    if (!$createdTs) continue;

                    $firstFulfillment = self::firstFulfillmentAt($o);
                    $fulfilledTs      = $firstFulfillment ? strtotime($firstFulfillment) : null;
                    $days             = $fulfilledTs
                        ? (int) floor(($fulfilledTs - $createdTs) / 86400)
                        : (int) floor(($now - $createdTs) / 86400);

                    if ($days < $slaThreshold) continue;

                    $addr = $o['shipping_address'] ?? [];
                    $rows[] = [
                        'shopify_id'   => $o['id'] ?? '',
                        'order_number' => $o['name'] ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'fulfilled_at' => $firstFulfillment ? self::dateOnly($firstFulfillment) : '',
                        'days'         => $days,
                        'email'        => $o['email'] ?? '',
                        'total'        => $o['total_price'] ?? '',
                        'financial'    => $o['financial_status'] ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?: 'unfulfilled',
                        'method'       => self::shippingMethod($o),
                        'region'       => self::addressRegion($addr),
                        'order_type'   => Comparator::classifyOrder($o),
                    ];
                }
                usort($rows, fn($a, $b) => $b['days'] <=> $a['days']);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'threshold' => $slaThreshold];
            }, 30);

        return compact('slaResult', 'slaError', 'slaStart', 'slaEnd', 'slaThreshold');
    }

    private static function loadShipmentAging(string $action, array $ctx): array
    {
        $saThreshold = max(1, (int)($_POST['sa_threshold'] ?? $_GET['sa_threshold'] ?? 3));
        $saResult = null;
        $saError  = '';

        if ($action === 'scan_shipmentaging') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);
            $saThreshold = max(1, (int)($_POST['sa_threshold'] ?? 3));
            if ($err = self::requireSS($ctx)) {
                $saError = $err;
                RunLog::append([
                    'tool'       => 'scan_shipmentaging',
                    'status'     => 'config_error',
                    'created_at' => $runStartedAt,
                    'duration'   => round(microtime(true) - $t0, 2),
                    'error'      => $saError,
                    'meta'       => ['threshold' => $saThreshold],
                ]);
            } else {
                try {
                    self::setLimits(180);
                    $ss     = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    $orders = self::suppressOutput(fn() => $ss->fetchAwaitingOrders());
                    $now    = time();
                    $rows   = [];
                    $bySku  = [];
                    $byType = [];

                    foreach ($orders as $o) {
                        $dateRaw = $o['orderDate'] ?? $o['createDate'] ?? '';
                        $orderTs = strtotime($dateRaw);
                        if (!$orderTs) continue;
                        $days = (int)floor(($now - $orderTs) / 86400);
                        if ($days < $saThreshold) continue;

                        $items = $o['items'] ?? [];
                        $fakeOrder = ['line_items' => array_map(fn($item) => [
                            'sku'   => $item['sku'] ?? '',
                            'title' => $item['name'] ?? '',
                        ], $items)];
                        $orderType = Comparator::classifyOrder($fakeOrder);

                        $skus = [];
                        foreach ($items as $item) {
                            $sku = trim((string)($item['sku'] ?? ''));
                            if ($sku === '') continue;
                            $qty = (int)($item['quantity'] ?? 1);
                            $skus[$sku] = ($skus[$sku] ?? 0) + $qty;
                            if (!isset($bySku[$sku])) $bySku[$sku] = ['sku' => $sku, 'orders' => 0, 'qty' => 0, 'oldest_days' => 0];
                            $bySku[$sku]['qty'] += $qty;
                            $bySku[$sku]['oldest_days'] = max($bySku[$sku]['oldest_days'], $days);
                        }
                        foreach (array_keys($skus) as $sku) {
                            $bySku[$sku]['orders']++;
                        }
                        if (!isset($byType[$orderType])) $byType[$orderType] = ['type' => $orderType, 'orders' => 0, 'oldest_days' => 0];
                        $byType[$orderType]['orders']++;
                        $byType[$orderType]['oldest_days'] = max($byType[$orderType]['oldest_days'], $days);

                        $rows[] = [
                            'ss_order_id'  => $o['orderId'] ?? '',
                            'order_number' => $o['orderNumber'] ?? '',
                            'order_date'   => self::dateOnly($dateRaw),
                            'days'         => $days,
                            'customer'     => trim($o['shipTo']['name'] ?? ''),
                            'email'        => $o['customerEmail'] ?? '',
                            'total'        => $o['orderTotal'] ?? '',
                            'status'       => $o['orderStatus'] ?? '',
                            'order_type'   => $orderType,
                            'skus'         => $skus,
                        ];
                    }
                    usort($rows, fn($a, $b) => $b['days'] <=> $a['days']);
                    usort($bySku, fn($a, $b) => $b['oldest_days'] <=> $a['oldest_days'] ?: $b['orders'] <=> $a['orders']);
                    usort($byType, fn($a, $b) => $b['oldest_days'] <=> $a['oldest_days'] ?: $b['orders'] <=> $a['orders']);
                    $saResult = [
                        'rows'      => $rows,
                        'scanned'   => count($orders),
                        'threshold' => $saThreshold,
                        'by_sku'    => array_values($bySku),
                        'by_type'   => array_values($byType),
                    ];
                    RunLog::append([
                        'tool'       => 'scan_shipmentaging',
                        'status'     => count($rows) > 0 ? 'issues_found' : 'ok',
                        'created_at' => $runStartedAt,
                        'duration'   => round(microtime(true) - $t0, 2),
                        'scanned'    => count($orders),
                        'rows_found' => count($rows),
                        'meta'       => ['threshold' => $saThreshold],
                    ]);
                } catch (Throwable $e) {
                    $saError = $e->getMessage();
                    RunLog::append([
                        'tool'       => 'scan_shipmentaging',
                        'status'     => 'error',
                        'created_at' => $runStartedAt,
                        'duration'   => round(microtime(true) - $t0, 2),
                        'error'      => $saError,
                        'meta'       => ['threshold' => $saThreshold],
                    ]);
                }
            }
        }

        return compact('saResult', 'saError', 'saThreshold');
    }

    private static function firstFulfillmentAt(array $order): string
    {
        $first = '';
        foreach ($order['fulfillments'] ?? [] as $f) {
            $ts = $f['created_at'] ?? '';
            if ($ts && (!$first || $ts < $first)) $first = $ts;
        }
        return $first;
    }

    private static function shippingMethod(array $order): string
    {
        $line = ($order['shipping_lines'] ?? [])[0] ?? [];
        return trim((string)($line['title'] ?? $line['code'] ?? 'Unknown'));
    }

    private static function addressRegion(?array $addr): string
    {
        if (!$addr) return 'Unknown';
        return implode(', ', array_filter([
            $addr['province_code'] ?? $addr['province'] ?? '',
            $addr['country_code'] ?? $addr['country'] ?? '',
        ])) ?: 'Unknown';
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
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
