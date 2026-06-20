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
            'spotcheck'   => self::loadSpotCheck($action, $ctx),
            'metafields'=> self::loadMetafields($action, $ctx),
            'tagsearch' => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'tagaudit'  => self::loadTagAudit($action, $ctx),
            'dupes'     => self::loadDuplicates($action, $ctx),
            'customer'  => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'refunds'   => self::loadRefunds($action, $ctx),
            'addrcheck'  => self::loadAddrCheck($action, $ctx),
            'tracking'   => SearchLookupPageLoader::load($page, $action, $ctx, $data),
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
            'slabreaches'       => self::loadSlaBreaches($action, $ctx),
            'activess'          => self::loadActiveSsConflicts($action, $ctx),
            'discountabuse'     => self::loadDiscountAbuse($action, $ctx),
            'tagpolicy'         => self::loadTagPolicy($action, $ctx),
            'inventoryaging'    => self::loadInventoryAging($action, $ctx),
            'shipmentaging'     => self::loadShipmentAging($action, $ctx),
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

    // ── Tag Audit ─────────────────────────────────────────────────────────────

    private static function loadTagAudit(string $action, array $ctx): array
    {
        ['result' => $tagAuditResult, 'error' => $tagAuditError, 'start' => $taStart, 'end' => $taEnd] =
            ScanRunner::run($action, 'tag_audit', $ctx, 'ta', function ($ctx, $start, $end) {
                self::setLimits(300);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $result  = $shopify->fetchTagStats($start, $end);
                $result['start'] = $start;
                $result['end']   = $end;
                return $result;
            }, 90);

        return compact('tagAuditResult', 'tagAuditError', 'taStart', 'taEnd');
    }

    // ── Address Problem Scanner ───────────────────────────────────────────────

    private static function loadAddrCheck(string $action, array $ctx): array
    {
        $unfulfilledOnly = (bool)($_POST['unfulfilled_only'] ?? false);
        $poBoxOnly       = (bool)($_POST['po_box_only']      ?? false);

        ['result' => $addrResult, 'error' => $addrError, 'start' => $addrStart, 'end' => $addrEnd] =
            ScanRunner::run($action, 'scan_addresses', $ctx, 'addr', function ($ctx, $start, $end) use (&$unfulfilledOnly, &$poBoxOnly) {
                $unfulfilledOnly = (bool)($_POST['unfulfilled_only'] ?? false);
                $poBoxOnly       = (bool)($_POST['po_box_only']      ?? false);
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForAddressScan($start, $end, $unfulfilledOnly));

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
                if ($poBoxOnly) {
                    $rows = array_values(array_filter($rows, function ($r) {
                        foreach ($r['issues'] as $issue) {
                            if (in_array($issue['code'], ['po_box', 'po_box_carrier'], true)) return true;
                        }
                        return false;
                    }));
                }
                return [
                    'rows'        => $rows,
                    'scanned'     => count($orders),
                    'start'       => $start,
                    'end'         => $end,
                    'critical'    => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                    'warnings'    => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                    'po_box_only' => $poBoxOnly,
                ];
            });

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
        ['result' => $refundsResult, 'error' => $refundsError, 'start' => $refundsStart, 'end' => $refundsEnd] =
            ScanRunner::run($action, 'find_refunds', $ctx, 'refunds', function ($ctx, $start, $end) {
                self::setLimits(300);
                $shopify        = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                $refundedOrders = self::suppressOutput(fn() => $shopify->fetchRefundedOrders($start, $end));

                $ssEnd  = date('Y-m-d', strtotime($end . ' +7 days'));
                $ssRows = [];
                if ($ctx['ssKey'] && $ctx['ssSecret']) {
                    $ssRows = self::suppressOutput(function () use ($ctx, $start, $ssEnd) {
                        $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                        return $ss->fetchAllOrders($start, $ssEnd);
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
                        'shopify_id'       => $o['id'] ?? '',
                        'order_number'     => $o['name'] ?? ('#' . $num),
                        'created_at'       => self::dateOnly($o['created_at'] ?? ''),
                        'email'            => $o['email'] ?? '',
                        'financial_status' => $o['financial_status'] ?? '',
                        'total_price'      => (float)($o['total_price'] ?? 0),
                        'refunded_amount'  => $refundedAmt,
                        'ss_orders'        => $ssMatch,
                        'ss_statuses'      => $ssStatuses,
                        'risk'             => $risk,
                    ];
                }
                usort($rows, function ($a, $b) {
                    $rankOf = fn($r) => match($r) { 'active' => 0, 'missing' => 1, default => 2 };
                    return $rankOf($a['risk']) <=> $rankOf($b['risk']);
                });
                return [
                    'rows'    => $rows,
                    'start'   => $start,
                    'end'     => $end,
                    'has_ss'  => !empty($ssRows),
                    'active'  => count(array_filter($rows, fn($r) => $r['risk'] === 'active')),
                    'missing' => count(array_filter($rows, fn($r) => $r['risk'] === 'missing')),
                ];
            });

        return compact('refundsResult', 'refundsError', 'refundsStart', 'refundsEnd');
    }

    // ── Duplicate Detector ────────────────────────────────────────────────────

    private static function loadDuplicates(string $action, array $ctx): array
    {
        $dupesResult = null;
        $dupesError  = '';
        [$dupesStart, $dupesEnd] = DateRange::fromRequest('dupes');

        if ($action === 'find_dupes') {
            $dupesStart = trim($_POST['dupes_start'] ?? '');
            $dupesEnd   = trim($_POST['dupes_end']   ?? '');

            if ($err = self::requireShopify($ctx)) {
                $dupesError = $err;
            } elseif ($err = DateRange::validate($dupesStart, $dupesEnd)) {
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
        [$emailStart, $emailEnd] = DateRange::fromRequest('email');

        ['result' => $emailResult, 'error' => $emailError, 'start' => $emailStart, 'end' => $emailEnd] =
            ScanRunner::run($action, 'scan_emails', $ctx, 'email', function ($ctx, $start, $end) {
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForAddressScan($start, $end));

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
                return [
                    'rows'     => $rows,
                    'scanned'  => count($orders),
                    'start'    => $start,
                    'end'      => $end,
                    'critical' => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                    'warnings' => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                ];
            });

        return compact('emailResult', 'emailError', 'emailStart', 'emailEnd');
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

    // ── High-Value Orders Without Phone ──────────────────────────────────────

    private static function loadHvOrders(string $action, array $ctx): array
    {
        $hvMin = max(0, (int)($_POST['hv_min'] ?? $_GET['hv_min'] ?? 200));

        ['result' => $hvResult, 'error' => $hvError, 'start' => $hvStart, 'end' => $hvEnd] =
            ScanRunner::run($action, 'scan_hvorders', $ctx, 'hv', function ($ctx, $start, $end) use (&$hvMin) {
                $hvMin   = max(0, (int)($_POST['hv_min'] ?? 200));
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForHighValue($start, $end));

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
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'min' => $hvMin];
            });

        return compact('hvResult', 'hvError', 'hvStart', 'hvEnd', 'hvMin');
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

    // ── Bundle / Required Items Check ─────────────────────────────────────────

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
        [$cmStart, $cmEnd] = DateRange::fromRequest('cm');

        ['result' => $cmResult, 'error' => $cmError, 'start' => $cmStart, 'end' => $cmEnd] =
            ScanRunner::run($action, 'scan_country_mismatch', $ctx, 'cm', function ($ctx, $start, $end) {
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForCountryMismatch($start, $end));

                $rows = [];
                foreach ($orders as $o) {
                    $bill = $o['billing_address']  ?? null;
                    $ship = $o['shipping_address'] ?? null;
                    $billCountry = strtoupper(trim($bill['country_code'] ?? $bill['country'] ?? ''));
                    $shipCountry = strtoupper(trim($ship['country_code'] ?? $ship['country'] ?? ''));
                    if (!$billCountry || !$shipCountry || $billCountry === $shipCountry) continue;
                    $rows[] = [
                        'shopify_id'   => $o['id'] ?? '',
                        'order_number' => $o['name'] ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'email'        => $o['email'] ?? '',
                        'total_price'  => (float)($o['total_price'] ?? 0),
                        'financial'    => $o['financial_status'] ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?? '',
                        'bill_country' => $billCountry,
                        'ship_country' => $shipCountry,
                        'bill_name'    => trim(($bill['first_name'] ?? '') . ' ' . ($bill['last_name'] ?? '')),
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end];
            });

        return compact('cmResult', 'cmError', 'cmStart', 'cmEnd');
    }

    // ── Partially Fulfilled Orders Aged Out ───────────────────────────────────

    private static function loadPartialFulfill(string $action, array $ctx): array
    {
        $pfThreshold = max(1, (int)($_POST['pf_threshold'] ?? $_GET['pf_threshold'] ?? 7));

        ['result' => $pfResult, 'error' => $pfError, 'start' => $pfStart, 'end' => $pfEnd] =
            ScanRunner::run($action, 'scan_partial_fulfill', $ctx, 'pf', function ($ctx, $start, $end) use (&$pfThreshold) {
                $pfThreshold = max(1, (int)($_POST['pf_threshold'] ?? 7));
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchPartiallyFulfilledOrders($start, $end));

                $now  = time();
                $rows = [];
                foreach ($orders as $o) {
                    $lastFulfilled = '';
                    foreach ($o['fulfillments'] ?? [] as $f) {
                        $fa = $f['created_at'] ?? '';
                        if ($fa > $lastFulfilled) $lastFulfilled = $fa;
                    }
                    $stallSince  = $lastFulfilled ?: ($o['created_at'] ?? '');
                    $daysStalled = $stallSince ? (int) floor(($now - strtotime($stallSince)) / 86400) : 0;
                    if ($daysStalled < $pfThreshold) continue;

                    $unfulfilledItems = [];
                    foreach ($o['line_items'] ?? [] as $li) {
                        $fulfillableQty = (int)($li['fulfillable_quantity'] ?? 0);
                        if ($fulfillableQty <= 0) continue;
                        $unfulfilledItems[] = [
                            'name' => $li['name'] ?? $li['title'] ?? '',
                            'sku'  => $li['sku']  ?? '',
                            'qty'  => $fulfillableQty,
                        ];
                    }
                    if (empty($unfulfilledItems)) continue;

                    $rows[] = [
                        'shopify_id'        => $o['id'] ?? '',
                        'order_number'      => $o['name'] ?? '',
                        'created_at'        => self::dateOnly($o['created_at'] ?? ''),
                        'last_fulfilled'    => self::dateOnly($lastFulfilled),
                        'days_stalled'      => $daysStalled,
                        'email'             => $o['email'] ?? '',
                        'total_price'       => (float)($o['total_price'] ?? 0),
                        'financial'         => $o['financial_status'] ?? '',
                        'unfulfilled_items' => $unfulfilledItems,
                    ];
                }
                usort($rows, fn($a, $b) => $b['days_stalled'] <=> $a['days_stalled']);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'threshold' => $pfThreshold];
            }, 90);

        return compact('pfResult', 'pfError', 'pfStart', 'pfEnd', 'pfThreshold');
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

    // ── On-Hold Stall ─────────────────────────────────────────────────────────

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

    // ── Fulfilled Without Tracking ────────────────────────────────────────────

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

    // ── Post-Ship Address Change ──────────────────────────────────────────────

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

    // ── SS Shipped / Shopify Unfulfilled ─────────────────────────────────────

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

    // ── Fulfillment SLA Breaches ─────────────────────────────────────────────

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

    // ── Inventory Aging ──────────────────────────────────────────────────────

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
                            $sales[$sku]['last_date']  = $date;
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

    // ── ShipStation Shipment Aging ───────────────────────────────────────────

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
