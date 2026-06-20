<?php
declare(strict_types=1);

/**
 * Loads search and lookup pages with narrow request/response state.
 */
class SearchLookupPageLoader
{
    public static function load(string $page, string $action, array $ctx, array $globalData = []): array
    {
        return match ($page) {
            'globalsearch' => self::loadGlobalSearch($ctx, $globalData),
            'tagsearch'    => self::loadTagSearch($action, $ctx),
            'customer'     => self::loadCustomer($action, $ctx),
            'tracking'     => self::loadTracking($action, $ctx),
            default        => [],
        };
    }

    private static function loadGlobalSearch(array $ctx, array $globalData): array
    {
        $q         = trim($_GET['q'] ?? '');
        $gsResults = null;

        if ($q !== '') {
            $norm = Comparator::normalise($q);
            $gsResults = ['query' => $q, 'reports' => [], 'push' => [], 'ignored' => []];

            foreach (($globalData['orderHistory'] ?? []) as $num => $entry) {
                $num = (string) $num;
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
                $num = (string) $num;
                if ($norm && (str_contains($num, $norm) || str_contains($norm, $num))) {
                    $gsResults['ignored'][] = ['number' => $num] + $entry;
                }
            }
        }

        return compact('gsResults');
    }

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
                        'usps'       => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
                        'fedex'      => 'https://www.fedex.com/fedextrack/?tracknumbers=',
                        'ups'        => 'https://www.ups.com/track?tracknum=',
                        'dhl'        => 'https://www.dhl.com/en/express/tracking.html?AWB=',
                        'stamps_com' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
                        'ontrac'     => 'https://www.ontrac.com/tracking/?number=',
                        'lasership'  => 'https://www.lasership.com/track/',
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
}
