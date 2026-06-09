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
            'globalsearch'=> self::loadGlobalSearch($ctx, $data),
            'spotcheck'   => self::loadSpotCheck($action, $ctx),
            'metafields'=> self::loadMetafields($action, $ctx),
            'tagsearch' => self::loadTagSearch($action, $ctx),
            'tagaudit'  => self::loadTagAudit($action, $ctx),
            'dupes'     => self::loadDuplicates($action, $ctx),
            'customer'  => self::loadCustomer($action, $ctx),
            'refunds'   => self::loadRefunds($action, $ctx),
            'addrcheck'  => self::loadAddrCheck($action, $ctx),
            'tracking'   => self::loadTracking($action, $ctx),
            'compare'    => self::loadCompare($action, $ctx),
            'emailcheck' => self::loadEmailCheck($action, $ctx),
            'orphans'       => self::loadOrphans($action, $ctx),
            'hvorders'      => self::loadHvOrders($action, $ctx),
            'repeatrefunds' => self::loadRepeatRefunds($action, $ctx),
            'failedship'    => self::loadFailedShipments($action, $ctx),
            'addrchanges'   => self::loadAddrChanges($action, $ctx),
            'timeline'      => self::loadTimeline($action, $ctx),
            'orderedits'    => self::loadOrderEdits($action, $ctx),
            'bundlecheck'   => self::loadBundleCheck($action, $ctx),
            'productcheck'  => self::loadProductCheck($action, $ctx),
            'skudupes'      => self::loadSkuDupes($action, $ctx),
            'packingslip'       => self::loadPackingSlip($action, $ctx),
            'inventoryoversell' => self::loadInventoryOversell($action, $ctx),
            'countrymismatch'   => self::loadCountryMismatch($action, $ctx),
            'partialfulfill'    => self::loadPartialFulfill($action, $ctx),
            'onholdstall'       => self::loadOnHoldStall($action, $ctx),
            'notracking'        => self::loadNoTracking($action, $ctx),
            'postshipaddr'      => self::loadPostShipAddrChange($action, $ctx),
            'noteflags'         => self::loadNoteFlags($action, $ctx),
            'ssshipped'         => self::loadSsShippedUnfulfilled($action, $ctx),
            'zombieproducts'    => self::loadZombieProducts($action, $ctx),
            'addrdupes'         => self::loadAddrDupes($action, $ctx),
            'settings'          => self::loadSettings($action, $ctx),
            default     => [],
        };

        return $data;
    }

    // ── Always-loaded data ────────────────────────────────────────────────────

    private static function loadGlobal(array $ctx): array
    {
        // Probabilistic background prune — keeps cache dir tidy without blocking every request
        if (mt_rand(1, 10) === 1) {
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
        $bannedIps = Auth::bannedIps();

        return compact('reports', 'orderHistory', 'latestReport', 'selectedDate', 'selectedReport',
                       'shopifyAdminBase', 'pushLog', 'bannedIps');
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    private static function loadAudit(string $action, array $ctx, array $already): array
    {
        $auditResult    = null;
        $auditError     = '';
        $auditDuration  = 0;
        $auditFromCache = ['shopify' => false, 'ss' => false];
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

            if ($err = self::validateDates($auditStart, $auditEnd)) {
                $auditError = $err;
            } elseif (!$ctx['ssKey'] || !$ctx['ssSecret'] || !$ctx['shopifyToken']) {
                $auditError = 'API credentials missing in .env.';
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

                    $cacheEntries = $ctx['cacheObj']->entries();
                } catch (Throwable $e) {
                    $auditError = $e->getMessage();
                }
            }
        }

        $cacheTtl = $ctx['cacheTtl'];
        return compact('auditResult', 'auditError', 'auditDuration', 'auditFromCache',
                       'auditStart', 'auditEnd', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
    }

    // ── Spot-check ────────────────────────────────────────────────────────────

    private static function loadSpotCheck(string $action, array $ctx): array
    {
        $spotResults = null;
        $spotError   = '';
        $spotInput   = trim($_GET['prefill'] ?? '');

        if ($action === 'spotcheck') {
            $spotInput = trim($_POST['orders'] ?? '');
            $numbers   = array_filter(array_map('trim', preg_split('/[\s,]+/', $spotInput)));

            if (empty($numbers)) {
                $spotError = 'Enter at least one order number.';
            } elseif (count($numbers) > 50) {
                $spotError = 'Maximum 50 order numbers at once.';
            } else {
                $spotMode = $_POST['spotcheck_mode'] ?? 'both';
                $checkSS  = in_array($spotMode, ['both', 'ss'], true);
                $checkSh  = in_array($spotMode, ['both', 'shopify'], true)
                    && $ctx['shopifyToken'] && $ctx['shopifyStore'] !== 'N/A';

                if ($checkSS && ($err = self::requireSS($ctx))) {
                    $spotError = $err;
                } else {
                    try {
                        $ss      = $checkSS ? new ShipStation($ctx['ssKey'], $ctx['ssSecret']) : null;
                        $shopify = $checkSh ? new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']) : null;

                        $spotResults = [];
                        foreach ($numbers as $num) {
                            $clean    = ltrim(trim($num), '#');
                            $ssOrders = $ss      ? $ss->findByOrderNumber($clean)     : null;
                            $shOrders = $shopify ? $shopify->findByOrderNumber($clean) : null;

                            $spotResults[] = [
                                'input'          => $num,
                                'number'         => $clean,
                                'mode'           => $spotMode,
                                'ss_orders'      => $ssOrders,
                                'ss_found'       => !empty($ssOrders),
                                'shopify_orders' => $shOrders,
                                'shopify_found'  => !empty($shOrders),
                                'orders'         => $ssOrders ?? [],
                                'found'          => !empty($ssOrders),
                            ];
                        }
                    } catch (Throwable $e) {
                        $spotError = 'Error: ' . $e->getMessage();
                    }
                }
            }
        }

        return compact('spotResults', 'spotInput', 'spotError');
    }

    // ── Metafields ────────────────────────────────────────────────────────────

    private static function loadMetafields(string $action, array $ctx): array
    {
        $metafieldDefs        = null;
        $metafieldOrders      = null;
        $metafieldInput       = '';
        $metafieldError       = '';
        $metafieldFilter      = '';
        $metafieldSearch      = null;
        $metafieldSearchError = '';

        if ($err = self::requireShopify($ctx)) {
            $metafieldError = $err;
            return compact('metafieldDefs', 'metafieldOrders', 'metafieldInput', 'metafieldError',
                           'metafieldFilter', 'metafieldSearch', 'metafieldSearchError');
        }

        $shopifyMeta = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);

        try {
            $metafieldDefs = $shopifyMeta->fetchMetafieldDefinitions('ORDER');
        } catch (Throwable $e) {
            $metafieldError = 'Could not load metafield definitions: ' . $e->getMessage();
        }

        if ($action === 'metafield_search') {
            $mfNs    = trim($_POST['mf_ns']    ?? '');
            $mfKey   = trim($_POST['mf_key']   ?? '');
            $mfVal   = trim($_POST['mf_value'] ?? '');
            $mfStart = trim($_POST['mf_start'] ?? '');
            $mfEnd   = trim($_POST['mf_end']   ?? '');

            if (!$mfNs || !$mfKey) {
                $metafieldSearchError = 'Namespace and key are required.';
            } else {
                try {
                    self::setLimits(120);
                    $result = $shopifyMeta->searchOrdersByMetafield($mfNs, $mfKey, $mfVal, $mfStart, $mfEnd);
                    $metafieldSearch = [
                        'namespace'     => $mfNs,
                        'key'           => $mfKey,
                        'value'         => $mfVal,
                        'start'         => $mfStart,
                        'end'           => $mfEnd,
                        'orders'        => $result['matches'],
                        'scanned'       => $result['scanned'],
                        'with_mf'       => $result['with_mf'],
                        'sample_values' => $result['sample_values'],
                        'pages'         => $result['pages'],
                        'truncated'     => $result['truncated'],
                    ];
                } catch (Throwable $e) {
                    $metafieldSearchError = $e->getMessage();
                }
            }
        }

        if ($action === 'metafield_lookup') {
            $metafieldInput  = trim($_POST['mf_orders'] ?? '');
            $metafieldFilter = trim($_POST['mf_filter'] ?? '');
            $numbers         = array_filter(array_map('trim', preg_split('/[\s,]+/', $metafieldInput)));

            if (empty($numbers)) {
                $metafieldError = 'Enter at least one order number.';
            } elseif (count($numbers) > 20) {
                $metafieldError = 'Maximum 20 order numbers at once.';
            } else {
                $metafieldOrders = [];
                foreach ($numbers as $num) {
                    $clean    = ltrim($num, '#');
                    $shOrders = $shopifyMeta->findByOrderNumber($clean);

                    if (empty($shOrders)) {
                        $metafieldOrders[] = ['number' => $clean, 'shopify_id' => null, 'metafields' => [], 'found' => false];
                        continue;
                    }

                    foreach ($shOrders as $shOrder) {
                        $oid = (string) ($shOrder['id'] ?? '');
                        $mfs = $oid ? $shopifyMeta->getOrderMetafields($oid) : [];

                        if ($metafieldFilter !== '') {
                            $mfs = array_values(array_filter($mfs, function ($mf) use ($metafieldFilter) {
                                $nk = ($mf['namespace'] ?? '') . '.' . ($mf['key'] ?? '');
                                return stripos($nk, $metafieldFilter) !== false
                                    || stripos((string) ($mf['value'] ?? ''), $metafieldFilter) !== false;
                            }));
                        }

                        $metafieldOrders[] = [
                            'number'     => $clean,
                            'shopify_id' => $oid,
                            'name'       => $shOrder['name'] ?? ('#' . $clean),
                            'metafields' => $mfs,
                            'found'      => true,
                        ];
                    }
                }
            }
        }

        return compact('metafieldDefs', 'metafieldOrders', 'metafieldInput', 'metafieldError',
                       'metafieldFilter', 'metafieldSearch', 'metafieldSearchError');
    }

    // ── Tag search ────────────────────────────────────────────────────────────

    private static function loadTagSearch(string $action, array $ctx): array
    {
        $tagSearch      = null;
        $tagSearchError = '';
        $tagInput       = '';
        $tagStart       = '';
        $tagEnd         = '';

        if ($action === 'tag_search') {
            $tagInput = trim($_POST['tag_input'] ?? '');
            $tagStart = trim($_POST['tag_start'] ?? '');
            $tagEnd   = trim($_POST['tag_end']   ?? '');

            if (!$tagInput) {
                $tagSearchError = 'Enter at least one tag.';
            } elseif ($err = self::requireShopify($ctx)) {
                $tagSearchError = $err;
            } else {
                try {
                    self::setLimits(120);
                    $shopifyTag = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $tagResult  = $shopifyTag->searchOrdersByTag($tagInput, $tagStart, $tagEnd);
                    $tagSearch  = array_merge($tagResult, [
                        'tag'   => $tagInput,
                        'start' => $tagStart,
                        'end'   => $tagEnd,
                    ]);
                } catch (Throwable $e) {
                    $tagSearchError = $e->getMessage();
                }
            }
        }

        return compact('tagSearch', 'tagSearchError', 'tagInput', 'tagStart', 'tagEnd');
    }

    // ── Tag Audit ─────────────────────────────────────────────────────────────

    private static function loadTagAudit(string $action, array $ctx): array
    {
        $tagAuditResult = null;
        $tagAuditError  = '';
        [$taStart, $taEnd] = self::extractDateRange('ta', 90);

        if ($action === 'tag_audit') {
            $taStart = trim($_POST['ta_start'] ?? '');
            $taEnd   = trim($_POST['ta_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $tagAuditError = $err;
            } elseif ($err = self::validateDates($taStart, $taEnd)) {
                $tagAuditError = $err;
            } else {
                try {
                    self::setLimits(300);
                    $shopify        = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $tagAuditResult = $shopify->fetchTagStats($taStart, $taEnd);
                    $tagAuditResult['start'] = $taStart;
                    $tagAuditResult['end']   = $taEnd;
                } catch (Throwable $e) {
                    $tagAuditError = $e->getMessage();
                }
            }
        }

        return compact('tagAuditResult', 'tagAuditError', 'taStart', 'taEnd');
    }

    // ── Address Problem Scanner ───────────────────────────────────────────────

    private static function loadAddrCheck(string $action, array $ctx): array
    {
        $addrResult      = null;
        $addrError       = '';
        [$addrStart, $addrEnd] = self::extractDateRange('addr');
        $unfulfilledOnly = (bool)($_POST['unfulfilled_only'] ?? false);

        if ($action === 'scan_addresses') {
            $addrStart = trim($_POST['addr_start'] ?? '');
            $addrEnd   = trim($_POST['addr_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $addrError = $err;
            } elseif ($err = self::validateDates($addrStart, $addrEnd)) {
                $addrError = $err;
            } else {
                try {
                    self::setLimits(180);

                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $unfulfilledOnly = (bool)($_POST['unfulfilled_only'] ?? false);
                    $orders = self::suppressOutput(
                        fn() => $shopify->fetchOrdersForAddressScan($addrStart, $addrEnd, $unfulfilledOnly)
                    );

                    $rows = [];
                    foreach ($orders as $o) {
                        $addr   = $o['shipping_address'] ?? null;
                        $issues = self::checkAddress($addr, $o);
                        if (!empty($issues)) {
                            $rows[] = [
                                'shopify_id'   => $o['id'] ?? '',
                                'order_number' => $o['name'] ?? '',
                                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                                'email'        => $o['email'] ?? '',
                                'address'      => $addr,
                                'issues'       => $issues,
                                'severity'     => in_array('critical', array_column($issues, 'level')) ? 'critical' : 'warning',
                            ];
                        }
                    }

                    usort($rows, fn($a, $b) =>
                        ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1)
                    );

                    $poBoxOnly = (bool)($_POST['po_box_only'] ?? false);
                    if ($poBoxOnly) {
                        $rows = array_values(array_filter($rows, function ($r) {
                            foreach ($r['issues'] as $issue) {
                                if (in_array($issue['code'], ['po_box', 'po_box_carrier'], true)) return true;
                            }
                            return false;
                        }));
                    }

                    $addrResult = [
                        'rows'      => $rows,
                        'scanned'   => count($orders),
                        'start'     => $addrStart,
                        'end'       => $addrEnd,
                        'critical'  => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                        'warnings'  => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                        'po_box_only' => $poBoxOnly,
                    ];
                } catch (Throwable $e) {
                    $addrError = $e->getMessage();
                }
            }
        }

        $poBoxOnly       = (bool)($_POST['po_box_only']      ?? false);
        $unfulfilledOnly = (bool)($_POST['unfulfilled_only'] ?? false);
        return compact('addrResult', 'addrError', 'addrStart', 'addrEnd', 'poBoxOnly', 'unfulfilledOnly');
    }

    private static function checkAddress(?array $addr, array $order): array
    {
        $issues = [];

        if (!$addr) {
            $issues[] = ['level' => 'critical', 'code' => 'no_address', 'message' => 'No shipping address on this order'];
            return $issues;
        }

        $name    = trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? ''));
        $address1 = trim($addr['address1'] ?? '');
        $city     = trim($addr['city']     ?? '');
        $zip      = trim($addr['zip']      ?? '');
        $country  = strtoupper(trim($addr['country_code'] ?? $addr['country'] ?? ''));
        $province = trim($addr['province_code'] ?? '');
        $phone    = trim($addr['phone'] ?? '');

        if (!$name || $name === ' ') {
            $issues[] = ['level' => 'critical', 'code' => 'no_name', 'message' => 'Missing recipient name'];
        }
        if (!$address1) {
            $issues[] = ['level' => 'critical', 'code' => 'no_address1', 'message' => 'Missing street address'];
        } elseif (strlen($address1) < 5) {
            $issues[] = ['level' => 'warning', 'code' => 'short_address', 'message' => 'Street address is suspiciously short'];
        }
        if (!$city) {
            $issues[] = ['level' => 'critical', 'code' => 'no_city', 'message' => 'Missing city'];
        }
        if (!$zip) {
            $issues[] = ['level' => 'critical', 'code' => 'no_zip', 'message' => 'Missing postal / ZIP code'];
        } elseif ($country === 'US' && !preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
            $issues[] = ['level' => 'warning', 'code' => 'bad_zip_us', 'message' => 'US ZIP code format invalid (expected 12345 or 12345-6789)'];
        } elseif ($country === 'CA' && !preg_match('/^[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d$/', $zip)) {
            $issues[] = ['level' => 'warning', 'code' => 'bad_zip_ca', 'message' => 'Canadian postal code format invalid (expected A1A 1A1)'];
        }
        if (!$country) {
            $issues[] = ['level' => 'critical', 'code' => 'no_country', 'message' => 'Missing country'];
        }
        if (in_array($country, ['US', 'CA'], true) && !$province) {
            $issues[] = ['level' => 'warning', 'code' => 'no_province', 'message' => 'Missing state / province (required for US and CA)'];
        }
        if (!$phone) {
            $shippingTitles = implode(' ', array_column($order['shipping_lines'] ?? [], 'title'));
            if (preg_match('/overnight|express|priority|fedex|ups/i', $shippingTitles)) {
                $issues[] = ['level' => 'warning', 'code' => 'no_phone_express', 'message' => 'No phone number - carrier may require it for express shipping'];
            }
        }
        if ($address1 && preg_match('/\bbox\b/i', $address1)) {
            $shippingTitles = implode(' ', array_column($order['shipping_lines'] ?? [], 'title'));
            if (preg_match('/fedex|ups|dhl/i', $shippingTitles)) {
                $issues[] = ['level' => 'warning', 'code' => 'po_box_carrier', 'message' => 'PO Box - carrier cannot deliver (FedEx/UPS/DHL do not deliver to PO Boxes)'];
            } else {
                $issues[] = ['level' => 'warning', 'code' => 'po_box', 'message' => 'PO Box address - confirm your shipping carrier accepts PO Box deliveries'];
            }
        }

        return $issues;
    }

    // ── Refunds Tracker ───────────────────────────────────────────────────────

    private static function loadRefunds(string $action, array $ctx): array
    {
        $refundsResult = null;
        $refundsError  = '';
        [$refundsStart, $refundsEnd] = self::extractDateRange('refunds');

        if ($action === 'find_refunds') {
            $refundsStart = trim($_POST['refunds_start'] ?? '');
            $refundsEnd   = trim($_POST['refunds_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $refundsError = $err;
            } elseif ($err = self::validateDates($refundsStart, $refundsEnd)) {
                $refundsError = $err;
            } else {
                try {
                    self::setLimits(300);

                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $refundedOrders = self::suppressOutput(
                        fn() => $shopify->fetchRefundedOrders($refundsStart, $refundsEnd)
                    );

                    $ssEnd  = date('Y-m-d', strtotime($refundsEnd . ' +7 days'));
                    $ssRows = [];
                    if ($ctx['ssKey'] && $ctx['ssSecret']) {
                        $ssRows = self::suppressOutput(function () use ($ctx, $refundsStart, $ssEnd) {
                            $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                            return $ss->fetchAllOrders($refundsStart, $ssEnd);
                        });
                    }

                    $ssIndex = [];
                    foreach ($ssRows as $ssO) {
                        $num = Comparator::normalise((string)($ssO['orderNumber'] ?? ''));
                        if ($num) $ssIndex[$num][] = $ssO;
                    }

                    $rows = [];
                    foreach ($refundedOrders as $o) {
                        $num     = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
                        $ssMatch = $ssIndex[$num] ?? [];

                        $refundedAmt = 0.0;
                        foreach ($o['refunds'] ?? [] as $ref) {
                            foreach ($ref['refund_line_items'] ?? [] as $rli) {
                                $refundedAmt += (float)($rli['subtotal'] ?? 0);
                            }
                        }
                        if ($refundedAmt == 0 && ($o['financial_status'] ?? '') === 'refunded') {
                            $refundedAmt = (float)($o['total_price'] ?? 0);
                        }

                        $ssStatuses = array_map(fn($s) => $s['orderStatus'] ?? 'unknown', $ssMatch);
                        $anyActive  = !empty(array_filter($ssStatuses, fn($s) => in_array($s, ['awaiting_shipment', 'awaiting_payment', 'on_hold'], true)));

                        $risk = 'ok';
                        if (empty($ssMatch)) $risk = 'missing';
                        elseif ($anyActive)  $risk = 'active';

                        $rows[] = [
                            'shopify_id'      => $o['id'] ?? '',
                            'order_number'    => $o['name'] ?? ('#' . $num),
                            'created_at'      => self::dateOnly($o['created_at'] ?? ''),
                            'email'           => $o['email'] ?? '',
                            'financial_status'=> $o['financial_status'] ?? '',
                            'total_price'     => (float)($o['total_price'] ?? 0),
                            'refunded_amount' => $refundedAmt,
                            'ss_orders'       => $ssMatch,
                            'ss_statuses'     => $ssStatuses,
                            'risk'            => $risk,
                        ];
                    }

                    usort($rows, function($a, $b) {
                        $rankOf = fn($r) => match($r) { 'active' => 0, 'missing' => 1, default => 2 };
                        return $rankOf($a['risk']) <=> $rankOf($b['risk']);
                    });

                    $refundsResult = [
                        'rows'    => $rows,
                        'start'   => $refundsStart,
                        'end'     => $refundsEnd,
                        'has_ss'  => !empty($ssRows),
                        'active'  => count(array_filter($rows, fn($r) => $r['risk'] === 'active')),
                        'missing' => count(array_filter($rows, fn($r) => $r['risk'] === 'missing')),
                    ];
                } catch (Throwable $e) {
                    $refundsError = $e->getMessage();
                }
            }
        }

        return compact('refundsResult', 'refundsError', 'refundsStart', 'refundsEnd');
    }

    // ── Customer Lookup ───────────────────────────────────────────────────────

    private static function loadCustomer(string $action, array $ctx): array
    {
        $customerResult = null;
        $customerError  = '';
        $customerEmail  = trim($_GET['email'] ?? $_POST['customer_email'] ?? '');

        if ($action === 'customer_lookup') {
            $customerEmail = trim($_POST['customer_email'] ?? '');

            if (!$customerEmail || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $customerError = 'Enter a valid email address.';
            } elseif ($err = self::requireShopify($ctx)) {
                $customerError = $err;
            } else {
                try {
                    self::setLimits(120);
                    $shopify        = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $customerResult = $shopify->lookupCustomer($customerEmail);
                    $customerResult['email'] = $customerEmail;
                } catch (Throwable $e) {
                    $customerError = $e->getMessage();
                }
            }
        }

        return compact('customerResult', 'customerError', 'customerEmail');
    }

    // ── Duplicate Detector ────────────────────────────────────────────────────

    private static function loadDuplicates(string $action, array $ctx): array
    {
        $dupesResult = null;
        $dupesError  = '';
        [$dupesStart, $dupesEnd] = self::extractDateRange('dupes');

        if ($action === 'find_dupes') {
            $dupesStart = trim($_POST['dupes_start'] ?? '');
            $dupesEnd   = trim($_POST['dupes_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $dupesError = $err;
            } elseif ($err = self::validateDates($dupesStart, $dupesEnd)) {
                $dupesError = $err;
            } else {
                try {
                    self::setLimits(300);
                    $shopify     = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $dupesResult = $shopify->findDuplicateOrders($dupesStart, $dupesEnd);
                    $dupesResult['start'] = $dupesStart;
                    $dupesResult['end']   = $dupesEnd;
                } catch (Throwable $e) {
                    $dupesError = $e->getMessage();
                }
            }
        }

        return compact('dupesResult', 'dupesError', 'dupesStart', 'dupesEnd');
    }

    // ── Shipment Tracking Feed ────────────────────────────────────────────────

    private static function loadTracking(string $action, array $ctx): array
    {
        $trackingResults = null;
        $trackingError   = '';
        $trackingInput   = trim($_GET['prefill'] ?? '');

        if ($action === 'lookup_tracking') {
            $trackingInput = trim($_POST['tracking_orders'] ?? '');
            $numbers = array_filter(array_map('trim', preg_split('/[\s,]+/', $trackingInput)));

            if (empty($numbers)) {
                $trackingError = 'Enter at least one order number.';
            } elseif (count($numbers) > 30) {
                $trackingError = 'Maximum 30 order numbers at once.';
            } elseif ($err = self::requireSS($ctx)) {
                $trackingError = $err;
            } else {
                try {
                    $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    $trackingResults = [];

                    $carrierUrls = [
                        'usps'    => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
                        'fedex'   => 'https://www.fedex.com/fedextrack/?tracknumbers=',
                        'ups'     => 'https://www.ups.com/track?tracknum=',
                        'dhl'     => 'https://www.dhl.com/en/express/tracking.html?AWB=',
                        'stamps_com' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
                        'ontrac'  => 'https://www.ontrac.com/tracking/?number=',
                        'lasership' => 'https://www.lasership.com/track/',
                    ];

                    foreach ($numbers as $num) {
                        $clean    = ltrim(trim($num), '#');
                        $ssOrders = $ss->findByOrderNumber($clean);

                        if (empty($ssOrders)) {
                            $trackingResults[] = ['number' => $clean, 'found' => false, 'shipments' => []];
                            continue;
                        }

                        $shipments = [];
                        foreach ($ssOrders as $o) {
                            $carrier  = strtolower($o['carrierCode'] ?? '');
                            $tracking = $o['trackingNumber'] ?? '';
                            $baseUrl  = $carrierUrls[$carrier] ?? null;
                            $shipments[] = [
                                'orderId'        => $o['orderId']        ?? '',
                                'orderStatus'    => $o['orderStatus']    ?? '',
                                'carrierCode'    => $o['carrierCode']    ?? '',
                                'serviceCode'    => $o['serviceCode']    ?? '',
                                'trackingNumber' => $tracking,
                                'shipDate'       => $o['shipDate']       ?? '',
                                'trackingUrl'    => ($baseUrl && $tracking) ? $baseUrl . urlencode($tracking) : null,
                                'ssUrl'          => $o['orderId'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($o['orderId']) : null,
                            ];
                        }

                        $trackingResults[] = ['number' => $clean, 'found' => true, 'shipments' => $shipments];
                    }
                } catch (Throwable $e) {
                    $trackingError = $e->getMessage();
                }
            }
        }

        return compact('trackingResults', 'trackingError', 'trackingInput');
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

    // ── Email Check ───────────────────────────────────────────────────────────

    private static function loadEmailCheck(string $action, array $ctx): array
    {
        $emailResult = null;
        $emailError  = '';
        [$emailStart, $emailEnd] = self::extractDateRange('email');

        if ($action === 'scan_emails') {
            $emailStart = trim($_POST['email_start'] ?? '');
            $emailEnd   = trim($_POST['email_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $emailError = $err;
            } elseif ($err = self::validateDates($emailStart, $emailEnd)) {
                $emailError = $err;
            } else {
                try {
                    self::setLimits(180);

                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $orders  = self::suppressOutput(
                        fn() => $shopify->fetchOrdersForAddressScan($emailStart, $emailEnd)
                    );

                    $disposable = [
                        'mailinator.com','guerrillamail.com','tempmail.com','throwam.com',
                        'yopmail.com','sharklasers.com','guerrillamailblock.com','grr.la',
                        'guerrillamail.info','trashmail.com','trashmail.net','trashmail.org',
                        'dispostable.com','maildrop.cc','spamgourmet.com','spamgourmet.net',
                        'mailnull.com','spamcorner.com','10minutemail.com','10minutemail.net',
                        'fakeinbox.com','mailnesia.com','discard.email','spamspot.com',
                        'mytemp.email','temp-mail.org','getnada.com','tempr.email',
                    ];

                    $rows = [];
                    foreach ($orders as $o) {
                        $email  = strtolower(trim($o['email'] ?? ''));
                        $issues = [];

                        if (!$email) {
                            $issues[] = ['level' => 'critical', 'message' => 'No email address on order'];
                        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $issues[] = ['level' => 'critical', 'message' => 'Invalid email format'];
                        } else {
                            $domain = substr($email, strrpos($email, '@') + 1);
                            if (in_array($domain, $disposable, true)) {
                                $issues[] = ['level' => 'critical', 'message' => 'Disposable / temporary email domain (' . $domain . ')'];
                            }
                            $local = substr($email, 0, strrpos($email, '@'));
                            if (strlen($local) <= 2) {
                                $issues[] = ['level' => 'warning', 'message' => 'Very short local part - may be a test address'];
                            }
                            if (preg_match('/^(test|noemail|no-?reply|none|null|fake|dummy|xxx|aaa|zzz)\b/i', $local)) {
                                $issues[] = ['level' => 'warning', 'message' => 'Email looks like a placeholder'];
                            }
                            if (preg_match('/(.)\1{4,}/', $local)) {
                                $issues[] = ['level' => 'warning', 'message' => 'Email has repeated characters - may be keyboard mashing'];
                            }
                        }

                        if (!empty($issues)) {
                            $rows[] = [
                                'shopify_id'   => $o['id'] ?? '',
                                'order_number' => $o['name'] ?? '',
                                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                                'email'        => $o['email'] ?? '',
                                'issues'       => $issues,
                                'severity'     => in_array('critical', array_column($issues, 'level')) ? 'critical' : 'warning',
                            ];
                        }
                    }

                    usort($rows, fn($a, $b) =>
                        ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1)
                    );

                    $emailResult = [
                        'rows'     => $rows,
                        'scanned'  => count($orders),
                        'start'    => $emailStart,
                        'end'      => $emailEnd,
                        'critical' => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                        'warnings' => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                    ];
                } catch (Throwable $e) {
                    $emailError = $e->getMessage();
                }
            }
        }

        return compact('emailResult', 'emailError', 'emailStart', 'emailEnd');
    }

    // ── SS → Shopify Orphan Detector ──────────────────────────────────────────

    private static function loadOrphans(string $action, array $ctx): array
    {
        $orphanResult = null;
        $orphanError  = '';
        [$orphanStart, $orphanEnd] = self::extractDateRange('orphan');

        if ($action === 'find_orphans') {
            $orphanStart = trim($_POST['orphan_start'] ?? '');
            $orphanEnd   = trim($_POST['orphan_end']   ?? '');

            if ($err = self::requireSS($ctx)) {
                $orphanError = $err;
            } elseif ($err = self::requireShopify($ctx)) {
                $orphanError = $err;
            } elseif ($err = self::validateDates($orphanStart, $orphanEnd)) {
                $orphanError = $err;
            } else {
                try {
                    self::setLimits(300);

                    [$ssOrders, $shOrders] = self::suppressOutput(function () use ($ctx, $orphanStart, $orphanEnd) {
                        $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                        $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                        return [$ss->fetchAllOrders($orphanStart, $orphanEnd), $shopify->fetchAllOrders($orphanStart, $orphanEnd)];
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

                    $orphanResult = [
                        'rows'     => $rows,
                        'ss_total' => count($ssOrders),
                        'sh_total' => count($shOrders),
                        'start'    => $orphanStart,
                        'end'      => $orphanEnd,
                    ];
                } catch (Throwable $e) {
                    $orphanError = $e->getMessage();
                }
            }
        }

        return compact('orphanResult', 'orphanError', 'orphanStart', 'orphanEnd');
    }

    // ── High-Value Orders Without Phone ──────────────────────────────────────

    private static function loadHvOrders(string $action, array $ctx): array
    {
        $hvResult = null;
        $hvError  = '';
        [$hvStart, $hvEnd] = self::extractDateRange('hv');
        $hvMin    = (int)($_POST['hv_min'] ?? $_GET['hv_min'] ?? 200);

        if ($action === 'scan_hvorders') {
            $hvStart = trim($_POST['hv_start'] ?? '');
            $hvEnd   = trim($_POST['hv_end']   ?? '');
            $hvMin   = max(0, (int)($_POST['hv_min'] ?? 200));

            if ($err = self::requireShopify($ctx)) {
                $hvError = $err;
            } elseif ($err = self::validateDates($hvStart, $hvEnd)) {
                $hvError = $err;
            } else {
                try {
                    self::setLimits(180);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForHighValue($hvStart, $hvEnd));

                    $rows = [];
                    foreach ($orders as $o) {
                        $addr  = $o['shipping_address'] ?? null;
                        $phone = trim($addr['phone'] ?? '');
                        $total = (float)($o['total_price'] ?? 0);
                        if ($phone || $total < $hvMin) continue;
                        $rows[] = [
                            'shopify_id'   => $o['id'] ?? '',
                            'order_number' => $o['name'] ?? '',
                            'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                            'email'        => $o['email'] ?? '',
                            'total'        => $total,
                            'address'      => $addr,
                        ];
                    }
                    usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
                    $hvResult = ['rows' => $rows, 'scanned' => count($orders), 'start' => $hvStart, 'end' => $hvEnd, 'min' => $hvMin];
                } catch (Throwable $e) {
                    $hvError = $e->getMessage();
                }
            }
        }
        return compact('hvResult', 'hvError', 'hvStart', 'hvEnd', 'hvMin');
    }

    // ── Repeat Refund Customers ───────────────────────────────────────────────

    private static function loadRepeatRefunds(string $action, array $ctx): array
    {
        $rrResult   = null;
        $rrError    = '';
        [$rrStart, $rrEnd] = self::extractDateRange('rr', 90);
        $rrMinCount = (int)($_POST['rr_min_count'] ?? $_GET['rr_min_count'] ?? 2);

        if ($action === 'scan_repeat_refunds') {
            $rrStart    = trim($_POST['rr_start'] ?? '');
            $rrEnd      = trim($_POST['rr_end']   ?? '');
            $rrMinCount = max(2, (int)($_POST['rr_min_count'] ?? 2));

            if ($err = self::requireShopify($ctx)) {
                $rrError = $err;
            } elseif ($err = self::validateDates($rrStart, $rrEnd)) {
                $rrError = $err;
            } else {
                try {
                    self::setLimits(300);
                    $shopify       = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $refundedOrders = self::suppressOutput(fn() => $shopify->fetchRefundedOrders($rrStart, $rrEnd));

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

                    $rrResult = ['rows' => $rows, 'scanned' => count($refundedOrders), 'start' => $rrStart, 'end' => $rrEnd, 'min_count' => $rrMinCount];
                } catch (Throwable $e) {
                    $rrError = $e->getMessage();
                }
            }
        }
        return compact('rrResult', 'rrError', 'rrStart', 'rrEnd', 'rrMinCount');
    }

    // ── Voided / Failed Shipments ─────────────────────────────────────────────

    private static function loadFailedShipments(string $action, array $ctx): array
    {
        $fsResult = null;
        $fsError  = '';
        [$fsStart, $fsEnd] = self::extractDateRange('fs');

        if ($action === 'scan_failed_shipments') {
            $fsStart = trim($_POST['fs_start'] ?? '');
            $fsEnd   = trim($_POST['fs_end']   ?? '');

            if ($err = self::requireSS($ctx)) {
                $fsError = str_replace('SS_API_KEY / SS_API_SECRET', 'SHIPSTATION_API_KEY / SHIPSTATION_API_SECRET', $err);
            } elseif ($err = self::validateDates($fsStart, $fsEnd)) {
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
        [$acStart, $acEnd] = self::extractDateRange('ac');

        if ($action === 'scan_addr_changes') {
            $acStart = trim($_POST['ac_start'] ?? '');
            $acEnd   = trim($_POST['ac_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $acError = $err;
            } elseif ($err = self::validateDates($acStart, $acEnd)) {
                $acError = $err;
            } else {
                try {
                    self::setLimits(240);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $entries = self::suppressOutput(fn() => $shopify->fetchOrdersWithAddressChanges($acStart, $acEnd));

                    $rows = [];
                    foreach ($entries as $e) {
                        $o    = $e['order'];
                        $addr = $o['shipping_address'] ?? null;
                        $addrLine = $addr ? implode(', ', array_filter([
                            $addr['address1'] ?? '',
                            $addr['city']     ?? '',
                            $addr['province_code'] ?? '',
                            $addr['zip']      ?? '',
                            $addr['country_code'] ?? '',
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

                    $acResult = ['rows' => $rows, 'start' => $acStart, 'end' => $acEnd];
                } catch (Throwable $e) {
                    $acError = $e->getMessage();
                }
            }
        }

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

    // ── Bundle / Required Items Check ─────────────────────────────────────────

    private static function loadBundleCheck(string $action, array $ctx): array
    {
        $bcResult = null;
        $bcError  = '';
        [$bcStart, $bcEnd] = self::extractDateRange('bc', 30);

        if ($action === 'scan_bundle') {
            $bcStart = trim($_POST['bc_start'] ?? '');
            $bcEnd   = trim($_POST['bc_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $bcError = $err;
            } elseif ($err = self::validateDates($bcStart, $bcEnd)) {
                $bcError = $err;
            } else {
                try {
                    self::setLimits(300);

                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $orders  = self::suppressOutput(fn() => $shopify->fetchAllOrders($bcStart, $bcEnd));

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

                    $bcResult = [
                        'rows'    => $rows,
                        'scanned' => count($orders),
                        'start'   => $bcStart,
                        'end'     => $bcEnd,
                    ];
                } catch (Throwable $e) {
                    $bcError = $e->getMessage();
                }
            }
        }

        $bcConfig = Comparator::getOrderTypesConfig();
        return compact('bcResult', 'bcError', 'bcStart', 'bcEnd', 'bcConfig');
    }

    // ── Product Check ─────────────────────────────────────────────────────────

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

                        $variantCount   = count($p['variants'] ?? []);
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

    // ── SKU Duplicate Detector ────────────────────────────────────────────────

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

                    $skuMap       = [];
                    $totalVariants = 0;
                    foreach ($products as $p) {
                        foreach ($p['variants'] ?? [] as $v) {
                            $totalVariants++;
                            $sku = trim($v['sku'] ?? '');
                            if ($sku === '') continue;
                            $skuMap[$sku][] = [
                                'product_id'    => (string)($p['id'] ?? ''),
                                'product_title' => $p['title'] ?? '',
                                'product_status'=> $p['status'] ?? '',
                                'variant_title' => $v['title'] ?? '',
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

    // ── Inventory Oversell Risk ───────────────────────────────────────────────

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

                    // Build SKU → stock map (sum across all variants that deny oversell)
                    $skuStock = [];
                    $skuInfo  = [];
                    foreach ($products as $p) {
                        foreach ($p['variants'] ?? [] as $v) {
                            $sku = trim($v['sku'] ?? '');
                            if ($sku === '') continue;
                            if (($v['inventory_management'] ?? '') === '') continue; // untracked
                            if (($v['inventory_policy'] ?? 'deny') === 'continue') continue; // allows oversell
                            $qty = (int)($v['inventory_quantity'] ?? 0);
                            $skuStock[$sku] = ($skuStock[$sku] ?? 0) + $qty;
                            $skuInfo[$sku]  = [
                                'product_id'    => (string)($p['id'] ?? ''),
                                'product_title' => $p['title'] ?? '',
                                'variant_title' => $v['title'] ?? '',
                            ];
                        }
                    }

                    // Count awaiting qty per SKU from ShipStation
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
                        if (!isset($skuStock[$sku])) continue; // not tracked in Shopify
                        $stock    = $skuStock[$sku];
                        $shortfall = $awaitingQty - $stock;
                        if ($shortfall <= 0) continue;
                        $info   = $skuInfo[$sku] ?? [];
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
                        'rows'           => $rows,
                        'products_scanned' => count($products),
                        'ss_orders'      => count($ssOrders),
                    ];
                } catch (Throwable $e) {
                    $ioError = $e->getMessage();
                }
            }
        }

        return compact('ioResult', 'ioError');
    }

    // ── Billing ≠ Shipping Country ────────────────────────────────────────────

    private static function loadCountryMismatch(string $action, array $ctx): array
    {
        $cmResult = null;
        $cmError  = '';
        [$cmStart, $cmEnd] = self::extractDateRange('cm');

        if ($action === 'scan_country_mismatch') {
            $cmStart = trim($_POST['cm_start'] ?? '');
            $cmEnd   = trim($_POST['cm_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $cmError = $err;
            } elseif ($err = self::validateDates($cmStart, $cmEnd)) {
                $cmError = $err;
            } else {
                try {
                    self::setLimits(180);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $orders  = self::suppressOutput(
                        fn() => $shopify->fetchOrdersForCountryMismatch($cmStart, $cmEnd)
                    );

                    $rows = [];
                    foreach ($orders as $o) {
                        $bill = $o['billing_address']  ?? null;
                        $ship = $o['shipping_address'] ?? null;
                        $billCountry = strtoupper(trim($bill['country_code'] ?? $bill['country'] ?? ''));
                        $shipCountry = strtoupper(trim($ship['country_code'] ?? $ship['country'] ?? ''));
                        if (!$billCountry || !$shipCountry) continue;
                        if ($billCountry === $shipCountry) continue;
                        $rows[] = [
                            'shopify_id'      => $o['id'] ?? '',
                            'order_number'    => $o['name'] ?? '',
                            'created_at'      => self::dateOnly($o['created_at'] ?? ''),
                            'email'           => $o['email'] ?? '',
                            'total_price'     => (float)($o['total_price'] ?? 0),
                            'financial'       => $o['financial_status'] ?? '',
                            'fulfillment'     => $o['fulfillment_status'] ?? '',
                            'bill_country'    => $billCountry,
                            'ship_country'    => $shipCountry,
                            'bill_name'       => trim(($bill['first_name'] ?? '') . ' ' . ($bill['last_name'] ?? '')),
                        ];
                    }
                    usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

                    $cmResult = [
                        'rows'    => $rows,
                        'scanned' => count($orders),
                        'start'   => $cmStart,
                        'end'     => $cmEnd,
                    ];
                } catch (Throwable $e) {
                    $cmError = $e->getMessage();
                }
            }
        }

        return compact('cmResult', 'cmError', 'cmStart', 'cmEnd');
    }

    // ── Partially Fulfilled Orders Aged Out ───────────────────────────────────

    private static function loadPartialFulfill(string $action, array $ctx): array
    {
        $pfResult   = null;
        $pfError    = '';
        [$pfStart, $pfEnd] = self::extractDateRange('pf', 90);
        $pfThreshold = (int)($_POST['pf_threshold'] ?? $_GET['pf_threshold'] ?? 7);

        if ($action === 'scan_partial_fulfill') {
            $pfStart     = trim($_POST['pf_start']     ?? '');
            $pfEnd       = trim($_POST['pf_end']       ?? '');
            $pfThreshold = max(1, (int)($_POST['pf_threshold'] ?? 7));

            if ($err = self::requireShopify($ctx)) {
                $pfError = $err;
            } elseif ($err = self::validateDates($pfStart, $pfEnd)) {
                $pfError = $err;
            } else {
                try {
                    self::setLimits(240);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $orders  = self::suppressOutput(
                        fn() => $shopify->fetchPartiallyFulfilledOrders($pfStart, $pfEnd)
                    );

                    $now  = time();
                    $rows = [];
                    foreach ($orders as $o) {
                        // Find date of last fulfillment
                        $lastFulfilled = '';
                        foreach ($o['fulfillments'] ?? [] as $f) {
                            $fa = $f['created_at'] ?? '';
                            if ($fa > $lastFulfilled) $lastFulfilled = $fa;
                        }
                        $stallSince = $lastFulfilled ?: ($o['created_at'] ?? '');
                        $daysStalled = $stallSince ? (int) floor(($now - strtotime($stallSince)) / 86400) : 0;

                        if ($daysStalled < $pfThreshold) continue;

                        // Identify unfulfilled line items
                        $unfulfilledItems = [];
                        foreach ($o['line_items'] ?? [] as $li) {
                            $fulfillableQty = (int)($li['fulfillable_quantity'] ?? 0);
                            if ($fulfillableQty <= 0) continue;
                            $unfulfilledItems[] = [
                                'name'     => $li['name']     ?? $li['title'] ?? '',
                                'sku'      => $li['sku']      ?? '',
                                'qty'      => $fulfillableQty,
                            ];
                        }

                        if (empty($unfulfilledItems)) continue;

                        $rows[] = [
                            'shopify_id'      => $o['id'] ?? '',
                            'order_number'    => $o['name'] ?? '',
                            'created_at'      => self::dateOnly($o['created_at'] ?? ''),
                            'last_fulfilled'  => self::dateOnly($lastFulfilled),
                            'days_stalled'    => $daysStalled,
                            'email'           => $o['email'] ?? '',
                            'total_price'     => (float)($o['total_price'] ?? 0),
                            'financial'       => $o['financial_status'] ?? '',
                            'unfulfilled_items' => $unfulfilledItems,
                        ];
                    }
                    usort($rows, fn($a, $b) => $b['days_stalled'] <=> $a['days_stalled']);

                    $pfResult = [
                        'rows'      => $rows,
                        'scanned'   => count($orders),
                        'start'     => $pfStart,
                        'end'       => $pfEnd,
                        'threshold' => $pfThreshold,
                    ];
                } catch (Throwable $e) {
                    $pfError = $e->getMessage();
                }
            }
        }

        return compact('pfResult', 'pfError', 'pfStart', 'pfEnd', 'pfThreshold');
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    private static function loadSettings(string $action, array $ctx): array
    {
        $connResults  = null;
        $cacheEntries = $ctx['cacheObj']->entries();
        $cacheFlushed = 0;
        $cacheTtl     = $ctx['cacheTtl'];

        if ($action === 'flush_cache') {
            $cacheFlushed = $ctx['cacheObj']->flush();
            $cacheEntries = $ctx['cacheObj']->entries();
        }

        if ($action === 'test_connection') {
            $ping = function (string $url, array $headers): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_USERAGENT      => 'ShopifyOps/1.0',
                ]);
                $t0   = microtime(true);
                curl_exec($ch);
                $ms   = (int) round((microtime(true) - $t0) * 1000);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'ms' => $ms, 'error' => $err ?: null];
            };

            if ($ctx['ssKey'] && $ctx['ssSecret']) {
                $auth = base64_encode("{$ctx['ssKey']}:{$ctx['ssSecret']}");
                $connResults['ss'] = $ping(
                    'https://ssapi.shipstation.com/orders?pageSize=1',
                    ["Authorization: Basic {$auth}", 'Accept: application/json']
                );
            } else {
                $connResults['ss'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SS_API_KEY / SS_API_SECRET not set in .env'];
            }

            if ($ctx['shopifyToken'] && $ctx['shopifyStore'] !== 'N/A') {
                $host = str_contains($ctx['shopifyStore'], '.') ? $ctx['shopifyStore'] : "{$ctx['shopifyStore']}.myshopify.com";
                $connResults['shopify'] = $ping(
                    "https://{$host}/admin/api/2024-01/shop.json",
                    ["X-Shopify-Access-Token: {$ctx['shopifyToken']}", 'Accept: application/json']
                );
            } else {
                $connResults['shopify'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env'];
            }
        }

        return compact('connResults', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    // ── Order Edit History ────────────────────────────────────────────────────

    private static function loadOrderEdits(string $action, array $ctx): array
    {
        $oeResult = null;
        $oeError  = '';
        [$oeStart, $oeEnd] = self::extractDateRange('oe', 30);

        if ($action === 'scan_order_edits') {
            $oeStart = trim($_POST['oe_start'] ?? '');
            $oeEnd   = trim($_POST['oe_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $oeError = $err;
            } elseif ($err = self::validateDates($oeStart, $oeEnd)) {
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

    // ── Global Search ─────────────────────────────────────────────────────────

    private static function loadGlobalSearch(array $ctx, array $globalData): array
    {
        $q         = trim($_GET['q'] ?? '');
        $gsResults = null;

        if ($q !== '') {
            $norm = Comparator::normalise($q);
            $gsResults = ['query' => $q, 'reports' => [], 'push' => [], 'ignored' => []];

            foreach (($globalData['orderHistory'] ?? []) as $num => $entry) {
                if ($norm && (str_contains($num, $norm) || str_contains($norm, $num))) {
                    $gsResults['reports'][] = ['number' => $num] + $entry;
                }
            }

            foreach (($globalData['pushLog'] ?? []) as $entry) {
                $entryNorm = Comparator::normalise($entry['order_number'] ?? '');
                if ($norm && ($entryNorm === $norm || str_contains($entryNorm, $norm) || str_contains($norm, $entryNorm))) {
                    $gsResults['push'][] = $entry;
                }
            }

            foreach (($ctx['ignoredOrders'] ?? []) as $num => $entry) {
                if ($norm && (str_contains($num, $norm) || str_contains($norm, $num))) {
                    $gsResults['ignored'][] = ['number' => $num] + $entry;
                }
            }
        }

        return compact('gsResults');
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function extractDateRange(string $prefix, int $defaultDays = 30): array
    {
        $start = $_POST["{$prefix}_start"] ?? $_GET["{$prefix}_start"] ?? date('Y-m-d', strtotime("-{$defaultDays} days"));
        $end   = $_POST["{$prefix}_end"]   ?? $_GET["{$prefix}_end"]   ?? date('Y-m-d');
        return [$start, $end];
    }

    private static function validateDates(string $start, string $end): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return 'Invalid date format. Use YYYY-MM-DD.';
        }
        if ($start > $end) {
            return 'Start date must be before end date.';
        }
        return null;
    }

    // ── Packing Slip Preview ─────────────────────────────────────────────────

    private static function loadPackingSlip(string $action, array $ctx): array
    {
        $slipOrder = null;
        $slipInput = trim($_GET['order'] ?? '');
        $slipError = '';

        if ($err = self::requireSS($ctx)) {
            $slipError = $err;
            return compact('slipOrder', 'slipInput', 'slipError');
        }

        if ($action === 'packingslip') {
            $slipInput = trim($_POST['order_number'] ?? '');
            $clean     = ltrim($slipInput, '#');

            if ($clean === '') {
                $slipError = 'Enter an order number.';
            } else {
                try {
                    $ss     = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    $orders = $ss->findByOrderNumber($clean);
                    if (empty($orders)) {
                        $slipError = "Order #{$clean} not found in ShipStation.";
                    } else {
                        $slipOrder = $orders[0];
                    }
                } catch (Throwable $e) {
                    $slipError = 'Error: ' . $e->getMessage();
                }
            }
        }

        return compact('slipOrder', 'slipInput', 'slipError');
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

        return compact(
            'dbPushRecent', 'dbTrendReports', 'dbMaxCount',
            'dbTotalReports', 'dbTotalMissing', 'dbTrend',
            'dbLastPush', 'dbCacheCount',
            'dbDaysSinceAudit', 'dbOldestMissingDays', 'dbStaleIgnored'
        );
    }

    // ── On-Hold Stall ─────────────────────────────────────────────────────────

    private static function loadOnHoldStall(string $action, array $ctx): array
    {
        $ohResult = null;
        $ohError  = '';
        [$ohStart, $ohEnd] = self::extractDateRange('oh', 90);

        if ($action === 'scan_onhold') {
            $ohStart = trim($_POST['oh_start'] ?? '');
            $ohEnd   = trim($_POST['oh_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $ohError = $err;
            } elseif ($err = self::validateDates($ohStart, $ohEnd)) {
                $ohError = $err;
            } else {
                try {
                    self::setLimits(240);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $nodes   = self::suppressOutput(fn() => $shopify->fetchOnHoldFulfillmentOrders($ohStart, $ohEnd));

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

                    $ohResult = ['rows' => $rows, 'start' => $ohStart, 'end' => $ohEnd];
                } catch (Throwable $e) {
                    $ohError = $e->getMessage();
                }
            }
        }

        return compact('ohResult', 'ohError', 'ohStart', 'ohEnd');
    }

    // ── Fulfilled Without Tracking ────────────────────────────────────────────

    private static function loadNoTracking(string $action, array $ctx): array
    {
        $ntResult    = null;
        $ntError     = '';
        [$ntStart, $ntEnd] = self::extractDateRange('nt', 30);
        $ntThreshold = (int)($_POST['nt_threshold'] ?? $_GET['nt_threshold'] ?? 24);

        if ($action === 'scan_notracking') {
            $ntStart     = trim($_POST['nt_start']     ?? '');
            $ntEnd       = trim($_POST['nt_end']       ?? '');
            $ntThreshold = max(1, (int)($_POST['nt_threshold'] ?? 24));

            if ($err = self::requireShopify($ctx)) {
                $ntError = $err;
            } elseif ($err = self::validateDates($ntStart, $ntEnd)) {
                $ntError = $err;
            } else {
                try {
                    self::setLimits(180);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $orders  = self::suppressOutput(fn() => $shopify->fetchFulfilledOrdersWithTracking($ntStart, $ntEnd));

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

                    $ntResult = [
                        'rows'      => $rows,
                        'scanned'   => count($orders),
                        'start'     => $ntStart,
                        'end'       => $ntEnd,
                        'threshold' => $ntThreshold,
                    ];
                } catch (Throwable $e) {
                    $ntError = $e->getMessage();
                }
            }
        }

        return compact('ntResult', 'ntError', 'ntStart', 'ntEnd', 'ntThreshold');
    }

    // ── Post-Ship Address Change ──────────────────────────────────────────────

    private static function loadPostShipAddrChange(string $action, array $ctx): array
    {
        $psResult = null;
        $psError  = '';
        [$psStart, $psEnd] = self::extractDateRange('ps');

        if ($action === 'scan_postshipaddr') {
            $psStart = trim($_POST['ps_start'] ?? '');
            $psEnd   = trim($_POST['ps_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $psError = $err;
            } elseif ($err = self::validateDates($psStart, $psEnd)) {
                $psError = $err;
            } else {
                try {
                    self::setLimits(240);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $entries = self::suppressOutput(fn() => $shopify->fetchPostShipAddressChanges($psStart, $psEnd));

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

                    $psResult = ['rows' => $rows, 'start' => $psStart, 'end' => $psEnd];
                } catch (Throwable $e) {
                    $psError = $e->getMessage();
                }
            }
        }

        return compact('psResult', 'psError', 'psStart', 'psEnd');
    }

    // ── Note Flags ────────────────────────────────────────────────────────────

    private static function loadNoteFlags(string $action, array $ctx): array
    {
        $nfResult = null;
        $nfError  = '';
        [$nfStart, $nfEnd] = self::extractDateRange('nf', 30);

        $defaultKeywords = 'urgent, hold, cancel, wrong, error, stop, do not ship, dont ship, wait, attention';
        $nfKeywordsRaw   = trim($_POST['nf_keywords'] ?? $_GET['nf_keywords'] ?? $defaultKeywords);

        if ($action === 'scan_noteflags') {
            $nfStart       = trim($_POST['nf_start']    ?? '');
            $nfEnd         = trim($_POST['nf_end']      ?? '');
            $nfKeywordsRaw = trim($_POST['nf_keywords'] ?? $defaultKeywords);

            $keywords = array_values(array_filter(array_map('trim', explode(',', strtolower($nfKeywordsRaw)))));

            if ($err = self::requireShopify($ctx)) {
                $nfError = $err;
            } elseif ($err = self::validateDates($nfStart, $nfEnd)) {
                $nfError = $err;
            } elseif (empty($keywords)) {
                $nfError = 'Enter at least one keyword.';
            } else {
                try {
                    self::setLimits(180);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersWithNotes($nfStart, $nfEnd));

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

                    $nfResult = [
                        'rows'     => $rows,
                        'scanned'  => count($orders),
                        'start'    => $nfStart,
                        'end'      => $nfEnd,
                        'keywords' => $keywords,
                    ];
                } catch (Throwable $e) {
                    $nfError = $e->getMessage();
                }
            }
        }

        return compact('nfResult', 'nfError', 'nfStart', 'nfEnd', 'nfKeywordsRaw');
    }

    // ── SS Shipped / Shopify Unfulfilled ─────────────────────────────────────

    private static function loadSsShippedUnfulfilled(string $action, array $ctx): array
    {
        $ssuResult = null;
        $ssuError  = '';
        [$ssuStart, $ssuEnd] = self::extractDateRange('ssu');

        if ($action === 'scan_ssshipped') {
            $ssuStart = trim($_POST['ssu_start'] ?? '');
            $ssuEnd   = trim($_POST['ssu_end']   ?? '');

            if ($err = self::requireSS($ctx)) {
                $ssuError = $err;
            } elseif ($err = self::requireShopify($ctx)) {
                $ssuError = $err;
            } elseif ($err = self::validateDates($ssuStart, $ssuEnd)) {
                $ssuError = $err;
            } else {
                try {
                    self::setLimits(300);

                    [$ssOrders, $shOrders] = self::suppressOutput(function () use ($ctx, $ssuStart, $ssuEnd) {
                        $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                        $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                        return [
                            $ss->fetchAllOrders($ssuStart, $ssuEnd),
                            $shopify->fetchAllOrders($ssuStart, $ssuEnd),
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
                        if (!$num || !isset($shIndex[$num])) continue; // orphan = different check

                        $sh           = $shIndex[$num];
                        $shFulfillment = $sh['fulfillment_status'] ?? '';
                        if ($shFulfillment === 'fulfilled') continue; // correctly synced

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
                    $ssuResult = [
                        'rows'          => $rows,
                        'shipped_total' => $shippedCount,
                        'start'         => $ssuStart,
                        'end'           => $ssuEnd,
                    ];
                } catch (Throwable $e) {
                    $ssuError = $e->getMessage();
                }
            }
        }

        return compact('ssuResult', 'ssuError', 'ssuStart', 'ssuEnd');
    }

    // ── Zombie Products ───────────────────────────────────────────────────────

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

                        $trackedCount   = 0;
                        $zeroStockCount = 0;
                        $totalStock     = 0;
                        foreach ($variants as $v) {
                            if (($v['inventory_management'] ?? '') === '') continue;
                            if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;
                            $trackedCount++;
                            $qty         = (int)($v['inventory_quantity'] ?? 0);
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

    // ── Duplicate Shipping Addresses ─────────────────────────────────────────

    private static function loadAddrDupes(string $action, array $ctx): array
    {
        $adResult = null;
        $adError  = '';
        [$adStart, $adEnd] = self::extractDateRange('ad', 30);

        if ($action === 'scan_addrdupes') {
            $adStart = trim($_POST['ad_start'] ?? '');
            $adEnd   = trim($_POST['ad_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $adError = $err;
            } elseif ($err = self::validateDates($adStart, $adEnd)) {
                $adError = $err;
            } else {
                try {
                    self::setLimits(180);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForAddrDupes($adStart, $adEnd));

                    $groups = [];
                    foreach ($orders as $o) {
                        $addr  = $o['shipping_address'] ?? null;
                        $email = strtolower(trim($o['email'] ?? ''));
                        if (!$addr || !$email) continue;
                        $key = strtolower(implode('|', [
                            trim($addr['address1']    ?? ''),
                            trim($addr['city']        ?? ''),
                            trim($addr['zip']         ?? ''),
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

                    $adResult = [
                        'rows'    => $rows,
                        'scanned' => count($orders),
                        'start'   => $adStart,
                        'end'     => $adEnd,
                    ];
                } catch (Throwable $e) {
                    $adError = $e->getMessage();
                }
            }
        }

        return compact('adResult', 'adError', 'adStart', 'adEnd');
    }
}
