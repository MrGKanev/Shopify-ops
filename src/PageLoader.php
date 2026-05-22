<?php
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
            'run'       => self::loadAudit($action, $ctx, $data),
            'spotcheck' => self::loadSpotCheck($action, $ctx),
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
            'settings'      => self::loadSettings($action, $ctx),
            default     => [],
        };

        return $data;
    }

    // ── Always-loaded data ────────────────────────────────────────────────────

    private static function loadGlobal(array $ctx): array
    {
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

                if (($fh = fopen($csvPath, 'r')) !== false) {
                    $headers = fgetcsv($fh, 0, ',', '"', '\\');
                    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                        $rawRows[] = array_combine($headers ?: [], $row);
                    }
                    fclose($fh);
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

                    $shopifyOrders = $shopify->fetchAllOrders($auditStart, $auditEnd);
                    $ssOrders      = $ss->fetchAllOrders($auditStart, $ssAuditEnd);

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
                $issues[] = ['level' => 'warning', 'code' => 'no_phone_express', 'message' => 'No phone number — carrier may require it for express shipping'];
            }
        }
        if ($address1 && preg_match('/\bbox\b/i', $address1)) {
            $shippingTitles = implode(' ', array_column($order['shipping_lines'] ?? [], 'title'));
            if (preg_match('/fedex|ups|dhl/i', $shippingTitles)) {
                $issues[] = ['level' => 'warning', 'code' => 'po_box_carrier', 'message' => 'PO Box — carrier cannot deliver (FedEx/UPS/DHL do not deliver to PO Boxes)'];
            } else {
                $issues[] = ['level' => 'warning', 'code' => 'po_box', 'message' => 'PO Box address — confirm your shipping carrier accepts PO Box deliveries'];
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
                                $issues[] = ['level' => 'warning', 'message' => 'Very short local part — may be a test address'];
                            }
                            if (preg_match('/^(test|noemail|no-?reply|none|null|fake|dummy|xxx|aaa|zzz)\b/i', $local)) {
                                $issues[] = ['level' => 'warning', 'message' => 'Email looks like a placeholder'];
                            }
                            if (preg_match('/(.)\1{4,}/', $local)) {
                                $issues[] = ['level' => 'warning', 'message' => 'Email has repeated characters — may be keyboard mashing'];
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

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
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
