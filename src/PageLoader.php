<?php
declare(strict_types=1);

use League\Csv\Reader;

/**
 * Loads all view data for each page.
 * Returns an array that gets extract()-ed into the view scope.
 */
class PageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        $data = [];

        // Always loaded
        $data += self::loadGlobal($ctx);

        // Page-specific
        $data += match ($page) {
            'dashboard'   => self::loadDashboard($ctx, $data),
            'run'         => self::loadAudit($action, $ctx, $data),
            'globalsearch'=> SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'spotcheck'   => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'metafields'=> SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'tagsearch' => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'tagaudit'  => SimpleScanPageLoader::load($page, $action, $ctx),
            'dupes'     => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'customer'  => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'refunds'   => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'addrcheck'  => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'tracking'   => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'compare'    => self::loadCompare($action, $ctx),
            'emailcheck' => SimpleScanPageLoader::load($page, $action, $ctx),
            'orphans'       => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'hvorders'      => SimpleScanPageLoader::load($page, $action, $ctx),
            'repeatrefunds' => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'failedship'    => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'addrchanges'   => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'timeline'      => self::loadTimeline($action, $ctx),
            'orderedits'    => self::loadOrderEdits($action, $ctx),
            'bundlecheck'   => ProductInventoryPageLoader::load($page, $action, $ctx),
            'productcheck'  => ProductInventoryPageLoader::load($page, $action, $ctx),
            'skudupes'      => ProductInventoryPageLoader::load($page, $action, $ctx),
            'packingslip'       => PackingSlipPageLoader::load($action, $ctx),
            'inventoryoversell' => ProductInventoryPageLoader::load($page, $action, $ctx),
            'countrymismatch'   => SimpleScanPageLoader::load($page, $action, $ctx),
            'partialfulfill'    => SimpleScanPageLoader::load($page, $action, $ctx),
            'onholdstall'       => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'notracking'        => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'postshipaddr'      => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'noteflags'         => self::loadNoteFlags($action, $ctx),
            'ssshipped'         => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'zombieproducts'    => ProductInventoryPageLoader::load($page, $action, $ctx),
            'addrdupes'         => self::loadAddrDupes($action, $ctx),
            'slabreaches'       => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'activess'          => self::loadActiveSsConflicts($action, $ctx),
            'discountabuse'     => self::loadDiscountAbuse($action, $ctx),
            'tagpolicy'         => self::loadTagPolicy($action, $ctx),
            'inventoryaging'    => ProductInventoryPageLoader::load($page, $action, $ctx),
            'shipmentaging'     => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'jobs',
            'slackrules',
            'apihealth',
            'configcheck',
            'actionlog',
            'settings'          => ManageSettingsPageLoader::load($page, $action, $ctx),
            default     => [],
        };

        return $data;
    }

    // ── Always-loaded data ────────────────────────────────────────────────────

    private static function loadGlobal(array $ctx): array
    {
        // Probabilistic background prune — keeps cache dir tidy without blocking every request
        if ($ctx['cacheObj'] && mt_rand(1, 10) === 1) {
            $ctx['cacheObj']->pruneExpired();
        }

        $reports      = [];
        $orderHistory = [];
        $reportDir    = $ctx['reportDir'];
        $ignoredOrders = $ctx['ignoredOrders'];

        if (is_dir($reportDir)) {
            $files = glob($reportDir . '/missing_*.csv') ?: [];
            rsort($files);

            foreach ($files as $csvPath) {
                preg_match('/missing_(\d{4}-\d{2}-\d{2})\.csv$/', $csvPath, $m);
                $date    = $m[1] ?? 'unknown';
                $rawRows = [];

                $csv = Reader::from($csvPath, 'r');
                $csv->setHeaderOffset(0);
                foreach ($csv->getRecords() as $record) {
                    $rawRows[] = $record;
                }

                foreach ($rawRows as $row) {
                    $num = Comparator::normalise((string) ($row['order_number'] ?? ''));
                    if (!$num) continue;
                    if (!isset($orderHistory[$num])) {
                        $orderHistory[$num] = ['count' => 0, 'first' => $date, 'last' => $date];
                    }
                    $orderHistory[$num]['count']++;
                    if ($date < $orderHistory[$num]['first']) $orderHistory[$num]['first'] = $date;
                    if ($date > $orderHistory[$num]['last'])  $orderHistory[$num]['last']  = $date;
                }

                $missing = array_values(array_filter(
                    $rawRows,
                    fn($o) => !isset($ignoredOrders[Comparator::normalise((string) ($o['order_number'] ?? ''))])
                ));

                $reports[] = ['date' => $date, 'csvPath' => $csvPath, 'missing' => $missing, 'count' => count($missing)];
            }
        }

        $latestReport = $reports[0] ?? null;
        $selectedDate = $_GET['date'] ?? ($latestReport['date'] ?? null);
        $selectedReport = null;
        foreach ($reports as $r) {
            if ($r['date'] === $selectedDate) { $selectedReport = $r; break; }
        }

        $shopifyStore     = $ctx['shopifyStore'];
        $shopifyAdminBase = 'https://'
            . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com")
            . '/admin/orders';

        $pushLog   = PushLog::all();
        $runLog    = RunLog::all();
        $jobLog    = JobQueue::all();
        $actionLog = UserActionLog::all();
        $bannedIps = Auth::bannedIps();

        return compact('reports', 'orderHistory', 'latestReport', 'selectedDate', 'selectedReport',
                       'shopifyAdminBase', 'pushLog', 'runLog', 'jobLog', 'actionLog', 'bannedIps');
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    private static function loadAudit(string $action, array $ctx, array $already): array
    {
        $auditResult    = null;
        $auditError     = '';
        $auditDuration  = 0;
        $auditFromCache = ['shopify' => false, 'ss' => false];
        $auditSlack     = ['configured' => SlackNotifier::isConfigured(), 'sent' => false, 'error' => ''];
        $cacheEntries   = $ctx['cacheObj']->entries();
        $cacheFlushed   = 0;
        $auditStart     = $_POST['audit_start'] ?? $_GET['start'] ?? date('Y-m-d', strtotime('-12 months'));
        $auditEnd       = $_POST['audit_end']   ?? $_GET['end']   ?? date('Y-m-d');

        if ($action === 'flush_cache') {
            $cacheFlushed = $ctx['cacheObj']->flush();
            $cacheEntries = $ctx['cacheObj']->entries();
        }

        if ($action === 'run_audit') {
            $auditStart = $_POST['audit_start'] ?? '';
            $auditEnd   = $_POST['audit_end']   ?? '';
            $runStartedAt = date('Y-m-d H:i:s');

            if ($err = DateRange::validate($auditStart, $auditEnd)) {
                $auditError = $err;
                RunLog::append([
                    'tool'       => 'run_audit',
                    'status'     => 'validation_error',
                    'created_at' => $runStartedAt,
                    'start_date' => $auditStart,
                    'end_date'   => $auditEnd,
                    'error'      => $err,
                    'meta'       => ['api_version' => Shopify::API_VERSION],
                ]);
            } elseif (!$ctx['ssKey'] || !$ctx['ssSecret'] || !$ctx['shopifyToken']) {
                $auditError = 'API credentials missing in .env.';
                RunLog::append([
                    'tool'       => 'run_audit',
                    'status'     => 'config_error',
                    'created_at' => $runStartedAt,
                    'start_date' => $auditStart,
                    'end_date'   => $auditEnd,
                    'error'      => $auditError,
                    'meta'       => ['api_version' => Shopify::API_VERSION],
                ]);
            } else {
                try {
                    self::setLimits(600);
                    ini_set('memory_limit', '512M');
                    $t0 = microtime(true);

                    $ssAuditEnd = date('Y-m-d', strtotime($auditEnd . ' +7 days'));

                    $auditFromCache = [
                        'shopify' => $ctx['cacheObj']->isFresh('shopify', "{$auditStart}|{$auditEnd}"),
                        'ss'      => $ctx['cacheObj']->isFresh('ss',      "{$auditStart}|{$ssAuditEnd}"),
                    ];

                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);

                    [$shopifyOrders, $ssOrders] = self::suppressOutput(function () use ($ss, $shopify, $auditStart, $auditEnd, $ssAuditEnd) {
                        return [
                            $shopify->fetchAllOrders($auditStart, $auditEnd),
                            $ss->fetchAllOrders($auditStart, $ssAuditEnd),
                        ];
                    });

                    $ssIndex      = Comparator::buildSSIndex($ssOrders);
                    $ssEmailIndex = Comparator::buildSSEmailIndex($ssOrders);
                    $comparison   = Comparator::compare($shopifyOrders, $ssIndex, $ctx['ignoredOrders'], $ssEmailIndex);

                    Reporter::saveReports($comparison['missing'], $auditStart, $auditEnd);

                    $auditDuration = round(microtime(true) - $t0, 1);
                    $auditResult   = [
                        'missing'    => $comparison['missing'],
                        'ignored'    => $comparison['ignored'],
                        'found'      => count($comparison['found']),
                        'skipped'    => count($comparison['skipped']),
                        'total_ss'   => count($ssOrders),
                        'duplicates' => Comparator::findDuplicates($shopifyOrders),
                    ];

                    if (SlackRules::shouldNotifyAudit(count($comparison['missing'])) && ($notifier = SlackNotifier::fromEnvironment())) {
                        try {
                            $notifier->notifyAudit([
                                'store'          => $ctx['shopifyStore'],
                                'start'          => $auditStart,
                                'end'            => $auditEnd,
                                'duration'       => $auditDuration,
                                'missing_count'  => count($comparison['missing']),
                                'missing_orders' => $comparison['missing'],
                                'found'          => count($comparison['found']),
                                'skipped'        => count($comparison['skipped']),
                                'ignored'        => count($comparison['ignored']),
                                'total_ss'       => count($ssOrders),
                            ]);
                            $auditSlack['sent'] = true;
                        } catch (Throwable $e) {
                            $auditSlack['error'] = $e->getMessage();
                            Logger::getInstance()->warning('Slack audit notification failed: {message}', [
                                'message'   => $e->getMessage(),
                                'exception' => $e->getFile() . ':' . $e->getLine(),
                            ]);
                        }
                    }

                    RunLog::append([
                        'tool'       => 'run_audit',
                        'status'     => count($comparison['missing']) > 0 ? 'issues_found' : 'ok',
                        'created_at' => $runStartedAt,
                        'duration'   => $auditDuration,
                        'start_date' => $auditStart,
                        'end_date'   => $auditEnd,
                        'scanned'    => count($shopifyOrders),
                        'rows_found' => count($comparison['missing']),
                        'meta'       => [
                            'api_version' => Shopify::API_VERSION,
                            'shopify_cache' => $auditFromCache['shopify'],
                            'shipstation_cache' => $auditFromCache['ss'],
                            'shipstation_total' => count($ssOrders),
                            'found' => count($comparison['found']),
                            'skipped' => count($comparison['skipped']),
                            'ignored' => count($comparison['ignored']),
                        ],
                    ]);

                    $cacheEntries = $ctx['cacheObj']->entries();
                } catch (Throwable $e) {
                    $auditError = $e->getMessage();
                    RunLog::append([
                        'tool'       => 'run_audit',
                        'status'     => 'error',
                        'created_at' => $runStartedAt,
                        'start_date' => $auditStart,
                        'end_date'   => $auditEnd,
                        'error'      => $auditError,
                        'meta'       => ['api_version' => Shopify::API_VERSION],
                    ]);
                }
            }
        }

        $cacheTtl = $ctx['cacheTtl'];
        return compact('auditResult', 'auditError', 'auditDuration', 'auditFromCache', 'auditSlack',
                       'auditStart', 'auditEnd', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
    }

    // ── Order Comparison Tool ─────────────────────────────────────────────────

    private static function loadCompare(string $action, array $ctx): array
    {
        $compareResult = null;
        $compareError  = '';
        $compareA      = trim($_POST['compare_a'] ?? $_GET['a'] ?? '');
        $compareB      = trim($_POST['compare_b'] ?? $_GET['b'] ?? '');

        if ($action === 'compare_orders') {
            $compareA = ltrim(trim($_POST['compare_a'] ?? ''), '#');
            $compareB = ltrim(trim($_POST['compare_b'] ?? ''), '#');

            if (!$compareA || !$compareB) {
                $compareError = 'Enter two order numbers to compare.';
            } elseif ($err = self::requireShopify($ctx)) {
                $compareError = $err;
            } else {
                try {
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $ss      = ($ctx['ssKey'] && $ctx['ssSecret'])
                                 ? new ShipStation($ctx['ssKey'], $ctx['ssSecret']) : null;

                    $fetchOrder = function (string $num) use ($shopify, $ss): array {
                        $shOrders = $shopify->findByOrderNumber($num);
                        $shOrder  = !empty($shOrders) ? $shopify->getOrder((string)($shOrders[0]['id'] ?? '')) : null;
                        $ssOrders = $ss ? $ss->findByOrderNumber($num) : [];
                        return ['shopify' => $shOrder, 'ss' => $ssOrders, 'num' => $num];
                    };

                    $compareResult = ['a' => $fetchOrder($compareA), 'b' => $fetchOrder($compareB)];
                } catch (Throwable $e) {
                    $compareError = $e->getMessage();
                }
            }
        }

        return compact('compareResult', 'compareError', 'compareA', 'compareB');
    }

    // ── SS → Shopify Orphan Detector ──────────────────────────────────────────

    private static function loadOrphans(string $action, array $ctx): array
    {
        $orphanResult = null;
        $orphanError  = '';
        [$orphanStart, $orphanEnd] = DateRange::fromRequest('orphan');

        ['result' => $orphanResult, 'error' => $orphanError, 'start' => $orphanStart, 'end' => $orphanEnd] =
            ScanRunner::run($action, 'find_orphans', $ctx, 'orphan', function ($ctx, $start, $end) {
                self::setLimits(300);
                [$ssOrders, $shOrders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [$ss->fetchAllOrders($start, $end), $shopify->fetchAllOrders($start, $end)];
                });

                $shIndex = [];
                foreach ($shOrders as $o) {
                    $num = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
                    if ($num) $shIndex[$num] = true;
                }

                $rows = [];
                foreach ($ssOrders as $o) {
                    $num = Comparator::normalise((string)($o['orderNumber'] ?? ''));
                    if (!$num || isset($shIndex[$num])) continue;
                    $rows[] = [
                        'ss_order_id'  => $o['orderId']     ?? '',
                        'order_number' => $o['orderNumber'] ?? '',
                        'order_status' => $o['orderStatus'] ?? '',
                        'order_date'   => self::dateOnly($o['orderDate'] ?? ''),
                        'customer'     => trim(($o['shipTo']['name'] ?? '')),
                        'email'        => $o['customerEmail'] ?? '',
                        'total'        => $o['orderTotal']   ?? 0,
                        'ss_url'       => $o['orderId'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($o['orderId']) : null,
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['order_date'], $a['order_date']));
                return [
                    'rows'     => $rows,
                    'ss_total' => count($ssOrders),
                    'sh_total' => count($shOrders),
                    'start'    => $start,
                    'end'      => $end,
                ];
            }, 30, true);

        return compact('orphanResult', 'orphanError', 'orphanStart', 'orphanEnd');
    }

    // ── Repeat Refund Customers ───────────────────────────────────────────────

    private static function loadRepeatRefunds(string $action, array $ctx): array
    {
        $rrMinCount = max(2, (int)($_POST['rr_min_count'] ?? $_GET['rr_min_count'] ?? 2));

        ['result' => $rrResult, 'error' => $rrError, 'start' => $rrStart, 'end' => $rrEnd] =
            ScanRunner::run($action, 'scan_repeat_refunds', $ctx, 'rr', function ($ctx, $start, $end) use (&$rrMinCount) {
                $rrMinCount     = max(2, (int)($_POST['rr_min_count'] ?? 2));
                self::setLimits(300);
                $shopify        = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                $refundedOrders = self::suppressOutput(fn() => $shopify->fetchRefundedOrders($start, $end));

                $byEmail = [];
                foreach ($refundedOrders as $o) {
                    $email = strtolower(trim($o['email'] ?? ''));
                    if (!$email) continue;
                    $refundedAmt = 0.0;
                    foreach ($o['refunds'] ?? [] as $ref) {
                        foreach ($ref['transactions'] ?? [] as $tx) {
                            if (($tx['kind'] ?? '') === 'refund' && ($tx['status'] ?? '') === 'success') {
                                $refundedAmt += (float)($tx['amount'] ?? 0);
                            }
                        }
                    }
                    $byEmail[$email][] = [
                        'order_number' => $o['name'] ?? '',
                        'shopify_id'   => $o['id'] ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'refunded_amt' => $refundedAmt,
                    ];
                }

                $rows = [];
                foreach ($byEmail as $email => $orders) {
                    if (count($orders) < $rrMinCount) continue;
                    $totalRefunded = array_sum(array_column($orders, 'refunded_amt'));
                    usort($orders, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                    $rows[] = [
                        'email'          => $email,
                        'refund_count'   => count($orders),
                        'total_refunded' => $totalRefunded,
                        'orders'         => $orders,
                    ];
                }
                usort($rows, fn($a, $b) => $b['refund_count'] <=> $a['refund_count']);
                return ['rows' => $rows, 'scanned' => count($refundedOrders), 'start' => $start, 'end' => $end, 'min_count' => $rrMinCount];
            }, 90);

        return compact('rrResult', 'rrError', 'rrStart', 'rrEnd', 'rrMinCount');
    }

    // ── Voided / Failed Shipments ─────────────────────────────────────────────

    private static function loadFailedShipments(string $action, array $ctx): array
    {
        $fsResult = null;
        $fsError  = '';
        [$fsStart, $fsEnd] = DateRange::fromRequest('fs');

        if ($action === 'scan_failed_shipments') {
            $fsStart = trim($_POST['fs_start'] ?? '');
            $fsEnd   = trim($_POST['fs_end']   ?? '');

            if ($err = self::requireSS($ctx)) {
                $fsError = str_replace('SS_API_KEY / SS_API_SECRET', 'SHIPSTATION_API_KEY / SHIPSTATION_API_SECRET', $err);
            } elseif ($err = DateRange::validate($fsStart, $fsEnd)) {
                $fsError = $err;
            } else {
                try {
                    self::setLimits(180);
                    $ss        = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    $shipments = self::suppressOutput(fn() => $ss->fetchVoidedShipments($fsStart, $fsEnd));

                    $rows = [];
                    foreach ($shipments as $s) {
                        $addr = $s['shipTo'] ?? null;
                        $rows[] = [
                            'order_number'    => $s['orderNumber'] ?? '',
                            'shipment_id'     => $s['shipmentId']  ?? '',
                            'tracking'        => $s['trackingNumber'] ?? '',
                            'carrier'         => $s['carrierCode']    ?? '',
                            'service'         => $s['serviceCode']    ?? '',
                            'ship_date'       => self::dateOnly($s['shipDate']  ?? ''),
                            'void_date'       => self::dateOnly($s['voidDate']  ?? ''),
                            'ship_to_name'    => trim(($addr['name'] ?? '')),
                            'ship_to_city'    => $addr['city']       ?? '',
                            'ship_to_state'   => $addr['state']      ?? '',
                            'ship_to_zip'     => $addr['postalCode'] ?? '',
                            'ship_to_country' => $addr['country']    ?? '',
                        ];
                    }
                    usort($rows, fn($a, $b) => strcmp($b['void_date'], $a['void_date']));
                    $fsResult = ['rows' => $rows, 'start' => $fsStart, 'end' => $fsEnd];
                } catch (Throwable $e) {
                    $fsError = $e->getMessage();
                }
            }
        }
        return compact('fsResult', 'fsError', 'fsStart', 'fsEnd');
    }

    // ── Address Changes ───────────────────────────────────────────────────────

    private static function loadAddrChanges(string $action, array $ctx): array
    {
        $acResult = null;
        $acError  = '';
        [$acStart, $acEnd] = DateRange::fromRequest('ac');

        ['result' => $acResult, 'error' => $acError, 'start' => $acStart, 'end' => $acEnd] =
            ScanRunner::run($action, 'scan_addr_changes', $ctx, 'ac', function ($ctx, $start, $end) {
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $entries = self::suppressOutput(fn() => $shopify->fetchOrdersWithAddressChanges($start, $end));

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
                    $rows[] = [
                        'shopify_id'   => $o['id']           ?? '',
                        'order_number' => $o['name']         ?? '',
                        'created_at'   => self::dateOnly($o['created_at']  ?? ''),
                        'changed_at'   => substr($e['changed_at']  ?? '', 0, 16),
                        'email'        => $o['email']        ?? '',
                        'total'        => $o['total_price']  ?? '',
                        'financial'    => $o['financial_status']    ?? '',
                        'fulfillment'  => $o['fulfillment_status']  ?? '',
                        'addr_name'    => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                        'addr_line'    => $addrLine,
                    ];
                }
                return ['rows' => $rows, 'start' => $start, 'end' => $end];
            });

        return compact('acResult', 'acError', 'acStart', 'acEnd');
    }

    // ── Order Timeline ────────────────────────────────────────────────────────

    private static function loadTimeline(string $action, array $ctx): array
    {
        $tlInput  = trim($_POST['tl_order'] ?? $_GET['order'] ?? '');
        $tlResult = null;
        $tlError  = '';

        if ($action === 'order_timeline') {
            $num = ltrim($tlInput, '#');

            if (!$num) {
                $tlError = 'Enter an order number.';
            } elseif ($err = self::requireShopify($ctx)) {
                $tlError = $err;
            } else {
                try {
                    self::setLimits(60);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $matches = $shopify->findByOrderNumber($num);

                    if (empty($matches)) {
                        $tlError = "Order #{$num} not found in Shopify.";
                    } else {
                        $shopifyId = (string) ($matches[0]['id'] ?? '');
                        $order     = $shopify->getOrder($shopifyId);
                        $events    = $shopify->getOrderEvents($shopifyId);

                        $ssOrders    = [];
                        $ssShipments = [];
                        if ($ctx['ssKey'] && $ctx['ssSecret']) {
                            $ss          = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                            $ssOrders    = $ss->findByOrderNumber($num);
                            $ssShipments = $ss->getOrderShipments($num);
                        }

                        $timeline   = self::buildOrderTimeline($order, $events, $ssOrders, $ssShipments);
                        $risks      = self::analyzeOrderRisks($order, $ssOrders);
                        $timeToShip = self::calcTimeToShip($order);

                        $tlResult = [
                            'order'        => $order,
                            'ss_orders'    => $ssOrders,
                            'ss_shipments' => $ssShipments,
                            'timeline'     => $timeline,
                            'risks'        => $risks,
                            'time_to_ship' => $timeToShip,
                            'label'        => $order['name'] ?? ('#' . $num),
                        ];
                    }
                } catch (Throwable $e) {
                    $tlError = $e->getMessage();
                }
            }
        }

        return compact('tlInput', 'tlResult', 'tlError');
    }

    private static function buildOrderTimeline(
        array $order,
        array $events,
        array $ssOrders,
        array $ssShipments
    ): array {
        $items = [];

        // Order placed
        if (!empty($order['created_at'])) {
            $items[] = [
                'ts'       => $order['created_at'],
                'type'     => 'order_placed',
                'source'   => 'shopify',
                'title'    => 'Order placed',
                'detail'   => trim(($order['email'] ?? '')),
                'tracking' => '',
                'url'      => '',
            ];
        }

        // Payment
        $finStatus = $order['financial_status'] ?? '';
        if (in_array($finStatus, ['paid', 'partially_paid'], true) && !empty($order['processed_at'])) {
            $total   = (float) ($order['total_price'] ?? 0);
            $items[] = [
                'ts'       => $order['processed_at'],
                'type'     => 'payment',
                'source'   => 'shopify',
                'title'    => 'Payment captured',
                'detail'   => '$' . number_format($total, 2),
                'tracking' => '',
                'url'      => '',
            ];
        }

        // Fulfillments
        foreach ($order['fulfillments'] ?? [] as $f) {
            $itemCount = count($f['line_items'] ?? []);
            $tracking  = $f['tracking_number'] ?? '';
            $carrier   = $f['tracking_company'] ?? '';
            $detail    = $itemCount . ' item' . ($itemCount !== 1 ? 's' : '');
            if ($carrier) $detail .= ' · ' . $carrier;

            $items[] = [
                'ts'       => $f['created_at'],
                'type'     => 'fulfillment',
                'source'   => 'shopify',
                'title'    => 'Fulfillment created',
                'detail'   => $detail,
                'tracking' => $tracking,
                'url'      => $tracking && $f['tracking_url'] ? $f['tracking_url'] : '',
            ];
        }

        // Refunds
        foreach ($order['refunds'] ?? [] as $r) {
            $amt = 0.0;
            foreach ($r['transactions'] ?? [] as $tx) {
                if (($tx['kind'] ?? '') === 'refund' && ($tx['status'] ?? '') === 'success') {
                    $amt += (float) ($tx['amount'] ?? 0);
                }
            }
            $items[] = [
                'ts'       => $r['created_at'],
                'type'     => 'refund',
                'source'   => 'shopify',
                'title'    => 'Refund processed',
                'detail'   => $amt > 0 ? '$' . number_format($amt, 2) : '',
                'tracking' => '',
                'url'      => '',
            ];
        }

        // Cancellation
        if (!empty($order['cancelled_at'])) {
            $reason  = $order['cancel_reason'] ?? '';
            $items[] = [
                'ts'       => $order['cancelled_at'],
                'type'     => 'cancelled',
                'source'   => 'shopify',
                'title'    => 'Order cancelled',
                'detail'   => $reason ? ucfirst(str_replace('_', ' ', $reason)) : '',
                'tracking' => '',
                'url'      => '',
            ];
        }

        // Order closed
        if (!empty($order['closed_at'])) {
            $items[] = [
                'ts'       => $order['closed_at'],
                'type'     => 'closed',
                'source'   => 'shopify',
                'title'    => 'Order closed',
                'detail'   => '',
                'tracking' => '',
                'url'      => '',
            ];
        }

        // Shopify audit events - skip verbs already covered by the order object
        $skipVerbs = ['placed', 'confirmed', 'fulfillment_created', 'fulfillment_success',
                      'fulfillment_shipped', 'closed', 'cancelled'];
        foreach ($events as $ev) {
            if (in_array($ev['verb'] ?? '', $skipVerbs, true)) continue;
            $msg     = $ev['message'] ?? ucfirst(str_replace('_', ' ', $ev['verb'] ?? ''));
            $items[] = [
                'ts'       => $ev['created_at'],
                'type'     => 'shopify_event',
                'source'   => 'shopify',
                'title'    => $msg,
                'detail'   => '',
                'tracking' => '',
                'url'      => '',
            ];
        }

        // ShipStation orders
        foreach ($ssOrders as $sso) {
            $ts = $sso['createDate'] ?? $sso['orderDate'] ?? '';
            if (!$ts) continue;
            $ssId    = $sso['orderId'] ?? '';
            $status  = $sso['orderStatus'] ?? 'unknown';
            $items[] = [
                'ts'       => $ts,
                'type'     => 'ss_order',
                'source'   => 'shipstation',
                'title'    => 'ShipStation: ' . ucfirst(str_replace('_', ' ', $status)),
                'detail'   => $ssId ? 'SS ID ' . $ssId : '',
                'tracking' => '',
                'url'      => $ssId ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($ssId) : '',
            ];
        }

        // ShipStation shipments
        foreach ($ssShipments as $s) {
            $ts = $s['shipDate'] ?? '';
            if (!$ts) continue;
            $carrier  = strtoupper($s['carrierCode'] ?? '');
            $tracking = $s['trackingNumber'] ?? '';
            $detail   = implode(' · ', array_filter([$carrier, $tracking]));
            $items[]  = [
                'ts'       => $ts,
                'type'     => 'ss_shipment',
                'source'   => 'shipstation',
                'title'    => 'Shipped via ShipStation',
                'detail'   => $detail,
                'tracking' => $tracking,
                'url'      => '',
            ];
        }

        // Sort descending (newest first)
        usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));

        // Format timestamps for display
        foreach ($items as &$item) {
            $item['ts_fmt'] = $item['ts']
                ? date('Y-m-d H:i', strtotime($item['ts']))
                : '';
        }
        unset($item);

        return $items;
    }

    private static function analyzeOrderRisks(array $order, array $ssOrders): array
    {
        $risks = [];

        // Slow to ship
        $timeToShip = self::calcTimeToShip($order);
        if ($timeToShip !== null) {
            if ($timeToShip > 7) {
                $risks[] = ['level' => 'danger', 'msg' => "Slow to ship: {$timeToShip} days between order placement and first fulfillment"];
            } elseif ($timeToShip > 3) {
                $risks[] = ['level' => 'warn', 'msg' => "Slow to ship: {$timeToShip} days between order placement and first fulfillment"];
            }
        }

        // Cancelled but has fulfillments
        if (!empty($order['cancelled_at']) && !empty($order['fulfillments'])) {
            $risks[] = ['level' => 'danger', 'msg' => 'Order is cancelled but has fulfillments - items may have already shipped'];
        }

        // Refunded but SS order still active
        $finStatus = $order['financial_status'] ?? '';
        if (in_array($finStatus, ['refunded', 'partially_refunded'], true)) {
            $activeStatuses = ['awaiting_shipment', 'awaiting_payment', 'on_hold'];
            foreach ($ssOrders as $sso) {
                if (in_array($sso['orderStatus'] ?? '', $activeStatuses, true)) {
                    $risks[] = ['level' => 'danger', 'msg' => 'Order is refunded in Shopify but still active in ShipStation (' . ($sso['orderStatus'] ?? '') . ')'];
                    break;
                }
            }
        }

        // Fulfilled without tracking
        foreach ($order['fulfillments'] ?? [] as $f) {
            if (empty($f['tracking_number'])) {
                $risks[] = ['level' => 'warn', 'msg' => 'Fulfillment exists without a tracking number'];
                break;
            }
        }

        // Multiple fulfillments
        $fCount = count($order['fulfillments'] ?? []);
        if ($fCount > 1) {
            $risks[] = ['level' => 'info', 'msg' => "Order has {$fCount} separate fulfillments (split shipment)"];
        }

        return $risks;
    }

    private static function calcTimeToShip(array $order): ?int
    {
        $fulfillments = $order['fulfillments'] ?? [];
        if (empty($fulfillments) || empty($order['created_at'])) return null;

        $ordered   = strtotime($order['created_at']);
        $fulfilled = strtotime($fulfillments[0]['created_at']);
        return max(0, (int) round(($fulfilled - $ordered) / 86400));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    // ── Order Edit History ────────────────────────────────────────────────────

    private static function loadOrderEdits(string $action, array $ctx): array
    {
        $oeResult = null;
        $oeError  = '';
        [$oeStart, $oeEnd] = DateRange::fromRequest('oe', 30);

        if ($action === 'scan_order_edits') {
            $oeStart = trim($_POST['oe_start'] ?? '');
            $oeEnd   = trim($_POST['oe_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $oeError = $err;
            } elseif ($err = DateRange::validate($oeStart, $oeEnd)) {
                $oeError = $err;
            } else {
                try {
                    self::setLimits(240);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $rows    = self::suppressOutput(fn() => $shopify->fetchEditedOrders($oeStart, $oeEnd));
                    $oeResult = ['rows' => $rows, 'start' => $oeStart, 'end' => $oeEnd];
                } catch (Throwable $e) {
                    $oeError = $e->getMessage();
                }
            }
        }

        return compact('oeResult', 'oeError', 'oeStart', 'oeEnd');
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

    // ── Dashboard ─────────────────────────────────────────────────────────────

    private static function loadDashboard(array $ctx, array $already): array
    {
        $reports       = $already['reports']   ?? [];
        $pushLog       = $already['pushLog']   ?? [];
        $ignoredOrders = $ctx['ignoredOrders'] ?? [];
        $cacheObj      = $ctx['cacheObj'];

        // Push stats — last 30 days
        $cutoff30      = date('Y-m-d', strtotime('-30 days'));
        $dbPushRecent  = array_values(array_filter(
            $pushLog,
            fn($e) => substr($e['pushed_at'] ?? '', 0, 10) >= $cutoff30
        ));

        // Last 10 reports for the history bar chart
        $dbTrendReports = array_slice($reports, 0, 10);
        $counts         = array_column($dbTrendReports, 'count');
        $dbMaxCount     = max(1, ...(count($counts) ? $counts : [1]));

        // Totals across all history
        $dbTotalReports = count($reports);
        $dbTotalMissing = (int) array_sum(array_column($reports, 'count'));

        // Trend: compare latest vs previous report (-1 better, 0 same, 1 worse)
        $dbTrend = null;
        if (count($reports) >= 2) {
            $dbTrend = $reports[0]['count'] <=> $reports[1]['count'];
        }

        // Last push date
        $dbLastPush = $pushLog[0]['pushed_at'] ?? null;

        // Cache stats
        $dbCacheCount = $cacheObj ? count($cacheObj->entries()) : 0;

        // Days since last audit
        $dbDaysSinceAudit = null;
        if (!empty($reports[0]['date'])) {
            $dbDaysSinceAudit = (int) round(
                (strtotime('today') - strtotime($reports[0]['date'])) / 86400
            );
        }

        // Oldest unresolved missing order (by created_at) in latest report
        $dbOldestMissingDays = null;
        if (!empty($reports[0]['missing'])) {
            $dates = array_filter(array_column($reports[0]['missing'], 'created_at'));
            if ($dates) {
                $dbOldestMissingDays = (int) round(
                    (strtotime('today') - strtotime(substr(min($dates), 0, 10))) / 86400
                );
            }
        }

        // Stale ignored orders (ignored 60+ days ago)
        $cutoff60       = date('Y-m-d', strtotime('-60 days'));
        $dbStaleIgnored = count(array_filter(
            $ignoredOrders,
            fn($e) => ($e['ignored_at'] ?? '9999-99-99') <= $cutoff60
        ));

        // Missing order type breakdown for the latest report
        $dbMissingByType = [];
        foreach ($reports[0]['missing'] ?? [] as $o) {
            $t = $o['order_type'] ?? 'Unknown';
            $dbMissingByType[$t] = ($dbMissingByType[$t] ?? 0) + 1;
        }
        arsort($dbMissingByType);

        // Average days between consecutive audits
        $dbAvgCadence = null;
        if (count($reports) >= 2) {
            $gaps = [];
            for ($i = 0; $i < count($reports) - 1; $i++) {
                $gaps[] = (strtotime($reports[$i]['date']) - strtotime($reports[$i + 1]['date'])) / 86400;
            }
            $dbAvgCadence = (float) round(array_sum($gaps) / count($gaps), 1);
        }

        // Average days from first-seen-as-missing to pushed
        $dbAvgResolutionDays = null;
        $orderHistory        = $already['orderHistory'] ?? [];
        $lags = [];
        foreach ($pushLog as $p) {
            $norm   = Comparator::normalise($p['order_number'] ?? '');
            $pushed = substr($p['pushed_at'] ?? '', 0, 10);
            if ($norm && $pushed && isset($orderHistory[$norm]['first'])) {
                $first = $orderHistory[$norm]['first'];
                $lag   = (strtotime($pushed) - strtotime($first)) / 86400;
                if ($lag >= 0) $lags[] = $lag;
            }
        }
        if ($lags) {
            $dbAvgResolutionDays = (float) round(array_sum($lags) / count($lags), 1);
        }

        return compact(
            'dbPushRecent', 'dbTrendReports', 'dbMaxCount',
            'dbTotalReports', 'dbTotalMissing', 'dbTrend',
            'dbLastPush', 'dbCacheCount',
            'dbDaysSinceAudit', 'dbOldestMissingDays', 'dbStaleIgnored',
            'dbMissingByType', 'dbAvgCadence', 'dbAvgResolutionDays'
        );
    }

    // ── Note Flags ────────────────────────────────────────────────────────────

    private static function loadNoteFlags(string $action, array $ctx): array
    {
        $defaultKeywords = 'urgent, hold, cancel, wrong, error, stop, do not ship, dont ship, wait, attention';
        $nfKeywordsRaw   = trim($_POST['nf_keywords'] ?? $_GET['nf_keywords'] ?? $defaultKeywords);

        ['result' => $nfResult, 'error' => $nfError, 'start' => $nfStart, 'end' => $nfEnd] =
            ScanRunner::run($action, 'scan_noteflags', $ctx, 'nf', function ($ctx, $start, $end) use (&$nfKeywordsRaw, $defaultKeywords) {
                $nfKeywordsRaw = trim($_POST['nf_keywords'] ?? $defaultKeywords);
                $keywords = array_values(array_filter(array_map('trim', explode(',', strtolower($nfKeywordsRaw)))));
                if (empty($keywords)) {
                    throw new \InvalidArgumentException('Enter at least one keyword.');
                }
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersWithNotes($start, $end));

                $rows = [];
                foreach ($orders as $o) {
                    $note = strtolower($o['note'] ?? '');
                    if (!$note) continue;
                    $matched = [];
                    foreach ($keywords as $kw) {
                        if ($kw !== '' && str_contains($note, $kw)) $matched[] = $kw;
                    }
                    if (empty($matched)) continue;
                    $rows[] = [
                        'shopify_id'   => $o['id']          ?? '',
                        'order_number' => $o['name']        ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'email'        => $o['email']       ?? '',
                        'total'        => $o['total_price'] ?? '',
                        'financial'    => $o['financial_status']   ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?? '',
                        'note'         => $o['note']        ?? '',
                        'matched'      => $matched,
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                return [
                    'rows'     => $rows,
                    'scanned'  => count($orders),
                    'start'    => $start,
                    'end'      => $end,
                    'keywords' => $keywords,
                ];
            });

        return compact('nfResult', 'nfError', 'nfStart', 'nfEnd', 'nfKeywordsRaw');
    }

    // ── Duplicate Shipping Addresses ─────────────────────────────────────────

    private static function loadAddrDupes(string $action, array $ctx): array
    {
        ['result' => $adResult, 'error' => $adError, 'start' => $adStart, 'end' => $adEnd] =
            ScanRunner::run($action, 'scan_addrdupes', $ctx, 'ad', function ($ctx, $start, $end) {
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForAddrDupes($start, $end));

                $groups = [];
                foreach ($orders as $o) {
                    $addr  = $o['shipping_address'] ?? null;
                    $email = strtolower(trim($o['email'] ?? ''));
                    if (!$addr || !$email) continue;
                    $key = strtolower(implode('|', [
                        trim($addr['address1']     ?? ''),
                        trim($addr['city']         ?? ''),
                        trim($addr['zip']          ?? ''),
                        trim($addr['country_code'] ?? ''),
                    ]));
                    if ($key === '|||') continue;
                    if (!isset($groups[$key])) {
                        $groups[$key] = ['addr' => $addr, 'emails' => [], 'orders' => []];
                    }
                    $groups[$key]['emails'][$email] = true;
                    $groups[$key]['orders'][] = [
                        'shopify_id'   => $o['id']          ?? '',
                        'order_number' => $o['name']        ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'email'        => $o['email']       ?? '',
                        'total'        => $o['total_price'] ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?? '',
                    ];
                }

                $rows = [];
                foreach ($groups as $g) {
                    if (count($g['emails']) < 2) continue;
                    $addr = $g['addr'];
                    $rows[] = [
                        'addr_line'   => implode(', ', array_filter([
                            $addr['address1']      ?? '',
                            $addr['city']          ?? '',
                            $addr['province_code'] ?? '',
                            $addr['zip']           ?? '',
                            $addr['country_code']  ?? '',
                        ])),
                        'addr_name'   => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                        'email_count' => count($g['emails']),
                        'order_count' => count($g['orders']),
                        'emails'      => array_keys($g['emails']),
                        'orders'      => $g['orders'],
                    ];
                }
                usort($rows, fn($a, $b) => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end];
            });

        return compact('adResult', 'adError', 'adStart', 'adEnd');
    }

    // ── Refunded/Cancelled Shopify Orders Still Active in ShipStation ────────

    private static function loadActiveSsConflicts(string $action, array $ctx): array
    {
        ['result' => $asResult, 'error' => $asError, 'start' => $asStart, 'end' => $asEnd] =
            ScanRunner::run($action, 'scan_activess', $ctx, 'as', function ($ctx, $start, $end) {
                self::setLimits(300);
                [$refunded, $cancelled, $activeSs] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    return [
                        $shopify->fetchRefundedOrders($start, $end),
                        $shopify->fetchCancelledOrders($start, $end),
                        $ss->fetchActiveOrders(),
                    ];
                });

                $activeIndex = Comparator::buildSSIndex($activeSs);
                $shopifyRows = [];
                foreach (array_merge($refunded, $cancelled) as $o) {
                    $id = (string)($o['id'] ?? '');
                    if ($id && isset($shopifyRows[$id])) continue;
                    $shopifyRows[$id ?: spl_object_id((object)$o)] = $o;
                }

                $rows = [];
                foreach ($shopifyRows as $o) {
                    $num      = Comparator::normalise((string)($o['order_number'] ?? ''));
                    $nameNorm = Comparator::normalise((string)($o['name'] ?? ''));
                    $matches  = $activeIndex[$num] ?? $activeIndex[$nameNorm] ?? [];
                    if (empty($matches)) continue;

                    $issue = !empty($o['cancelled_at']) ? 'cancelled' : ($o['financial_status'] ?? 'refunded');
                    foreach ($matches as $ssOrder) {
                        $rows[] = [
                            'shopify_id'   => $o['id'] ?? '',
                            'order_number' => $o['name'] ?? $o['order_number'] ?? '',
                            'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                            'issue'        => $issue,
                            'email'        => $o['email'] ?? '',
                            'total'        => $o['total_price'] ?? '',
                            'financial'    => $o['financial_status'] ?? '',
                            'cancelled_at' => self::dateOnly($o['cancelled_at'] ?? ''),
                            'ss_order_id'  => $ssOrder['orderId'] ?? '',
                            'ss_status'    => $ssOrder['orderStatus'] ?? '',
                            'ss_date'      => self::dateOnly($ssOrder['orderDate'] ?? $ssOrder['createDate'] ?? ''),
                            'ss_total'     => $ssOrder['orderTotal'] ?? '',
                        ];
                    }
                }
                usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                return [
                    'rows'       => $rows,
                    'scanned'    => count($shopifyRows),
                    'active_ss'  => count($activeSs),
                    'start'      => $start,
                    'end'        => $end,
                ];
            }, 30, true);

        return compact('asResult', 'asError', 'asStart', 'asEnd');
    }

    // ── Discount Abuse Clusters ──────────────────────────────────────────────

    private static function loadDiscountAbuse(string $action, array $ctx): array
    {
        $daMinEmails = max(2, (int)($_POST['da_min_emails'] ?? $_GET['da_min_emails'] ?? 3));

        ['result' => $daResult, 'error' => $daError, 'start' => $daStart, 'end' => $daEnd] =
            ScanRunner::run($action, 'scan_discountabuse', $ctx, 'da', function ($ctx, $start, $end) use (&$daMinEmails) {
                $daMinEmails = max(2, (int)($_POST['da_min_emails'] ?? 3));
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForDiscountAudit($start, $end));

                $groups = [];
                foreach ($orders as $o) {
                    $codes = $o['discount_codes'] ?? [];
                    if (empty($codes)) continue;
                    $addr = $o['shipping_address'] ?? null;
                    if (!$addr) continue;
                    $addrKey = self::addressKey($addr);
                    if ($addrKey === '') continue;
                    foreach ($codes as $discount) {
                        $code = strtoupper(trim((string)($discount['code'] ?? '')));
                        if ($code === '') continue;
                        $key = $code . '|' . $addrKey;
                        if (!isset($groups[$key])) {
                            $groups[$key] = ['code' => $code, 'addr' => $addr, 'emails' => [], 'orders' => [], 'total' => 0.0];
                        }
                        $email = strtolower(trim($o['email'] ?? ''));
                        if ($email) $groups[$key]['emails'][$email] = true;
                        $groups[$key]['total'] += (float)($o['total_price'] ?? 0);
                        $groups[$key]['orders'][] = [
                            'shopify_id'   => $o['id'] ?? '',
                            'order_number' => $o['name'] ?? '',
                            'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                            'email'        => $o['email'] ?? '',
                            'total'        => $o['total_price'] ?? '',
                            'financial'    => $o['financial_status'] ?? '',
                            'fulfillment'  => $o['fulfillment_status'] ?? '',
                        ];
                    }
                }

                $rows = [];
                foreach ($groups as $g) {
                    if (count($g['emails']) < $daMinEmails) continue;
                    $addr = $g['addr'];
                    $rows[] = [
                        'code'        => $g['code'],
                        'addr_line'   => self::addressLine($addr),
                        'addr_name'   => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                        'email_count' => count($g['emails']),
                        'order_count' => count($g['orders']),
                        'emails'      => array_keys($g['emails']),
                        'orders'      => $g['orders'],
                        'total'       => $g['total'],
                    ];
                }
                usort($rows, fn($a, $b) => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'min_emails' => $daMinEmails];
            }, 30);

        return compact('daResult', 'daError', 'daStart', 'daEnd', 'daMinEmails');
    }

    // ── Tag Policy Audit ─────────────────────────────────────────────────────

    private static function loadTagPolicy(string $action, array $ctx): array
    {
        $tpConfig = self::tagPolicyConfig();

        ['result' => $tpResult, 'error' => $tpError, 'start' => $tpStart, 'end' => $tpEnd] =
            ScanRunner::run($action, 'scan_tagpolicy', $ctx, 'tp', function ($ctx, $start, $end) use ($tpConfig) {
                $rules = array_merge($tpConfig['required'] ?? [], $tpConfig['forbidden'] ?? []);
                if (empty($rules)) {
                    return ['rows' => [], 'scanned' => 0, 'start' => $start, 'end' => $end, 'configured' => false];
                }

                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForTagPolicy($start, $end));

                $rows = [];
                foreach ($orders as $o) {
                    $tags       = self::orderTags($o);
                    $tagLookup  = array_fill_keys(array_map('strtolower', $tags), true);
                    $violations = [];

                    foreach ($tpConfig['required'] ?? [] as $rule) {
                        $when = array_map('strtolower', (array)($rule['when'] ?? []));
                        $must = array_map('strtolower', (array)($rule['must_have'] ?? []));
                        if ($when === [] || $must === []) continue;
                        if (array_diff($when, array_keys($tagLookup)) !== []) continue;
                        $missing = array_values(array_diff($must, array_keys($tagLookup)));
                        if ($missing !== []) {
                            $violations[] = [
                                'type' => 'required',
                                'name' => $rule['name'] ?? 'Required tag policy',
                                'detail' => 'Missing: ' . implode(', ', $missing),
                            ];
                        }
                    }

                    foreach ($tpConfig['forbidden'] ?? [] as $rule) {
                        $forbidden = array_map('strtolower', (array)($rule['tags'] ?? []));
                        if (count($forbidden) < 2) continue;
                        if (array_diff($forbidden, array_keys($tagLookup)) === []) {
                            $violations[] = [
                                'type' => 'forbidden',
                                'name' => $rule['name'] ?? 'Forbidden tag combination',
                                'detail' => 'Combination: ' . implode(', ', $forbidden),
                            ];
                        }
                    }

                    if ($violations === []) continue;
                    $rows[] = [
                        'shopify_id'   => $o['id'] ?? '',
                        'order_number' => $o['name'] ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'email'        => $o['email'] ?? '',
                        'total'        => $o['total_price'] ?? '',
                        'financial'    => $o['financial_status'] ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?? '',
                        'tags'         => $tags,
                        'violations'   => $violations,
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'configured' => true];
            }, 30);

        return compact('tpResult', 'tpError', 'tpStart', 'tpEnd', 'tpConfig');
    }

    private static function addressLine(array $addr): string
    {
        return implode(', ', array_filter([
            $addr['address1']      ?? '',
            $addr['city']          ?? '',
            $addr['province_code'] ?? '',
            $addr['zip']           ?? '',
            $addr['country_code']  ?? '',
        ]));
    }

    private static function addressKey(array $addr): string
    {
        return strtolower(implode('|', array_filter([
            trim((string)($addr['address1'] ?? '')),
            trim((string)($addr['city'] ?? '')),
            trim((string)($addr['zip'] ?? '')),
            trim((string)($addr['country_code'] ?? $addr['country'] ?? '')),
        ])));
    }

    /**
     * @return string[]
     */
    private static function orderTags(array $order): array
    {
        $raw = $order['tags'] ?? [];
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        return array_values(array_filter(array_map('trim', (array)$raw), fn($tag) => $tag !== ''));
    }

    /**
     * @return array{required?: array<int, array<string, mixed>>, forbidden?: array<int, array<string, mixed>>}
     */
    private static function tagPolicyConfig(): array
    {
        $file = __DIR__ . '/../tag_policy.json';
        if (!file_exists($file)) return [];
        $decoded = json_decode(file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }
}
