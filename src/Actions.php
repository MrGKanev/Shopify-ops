<?php
/**
 * Handles all POST actions that terminate with a redirect or direct output.
 * Call Actions::dispatch() early in index.php; it exits if the action was handled.
 */
class Actions
{
    public static function dispatch(string $action, array $ctx): void
    {
        if (!$ctx['authed']) return;

        match ($action) {
            'switch_store'        => self::switchStore($ctx),
            'unban_ip'            => self::unbanIp($ctx),
            'ignore_order'        => self::ignoreOrder($ctx),
            'unignore_order'      => self::unignoreOrder($ctx),
            'bulk_ignore_orders'  => self::bulkIgnore($ctx),
            'bulk_unignore_orders'=> self::bulkUnignore($ctx),
            'import_ignore_csv'   => self::importIgnoreCsv($ctx),
            'push_to_shipstation' => self::pushToShipStation($ctx),
            'preview_push'        => self::previewPush($ctx),
            'order_detail'        => self::orderDetail($ctx),
            'flush_cache'         => self::flushCache($ctx),
            default               => null,
        };

        if (($_GET['action'] ?? '') === 'download') {
            self::csvDownload($ctx);
        }
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    private static function switchStore(array $ctx): void
    {
        if (!class_exists('Stores') || !Stores::isMultiStore()) return;
        Stores::setActive($_POST['store_id'] ?? '');
        header('Location: ?');
        exit;
    }

    private static function unbanIp(array $ctx): void
    {
        Auth::unban($_POST['ip'] ?? '');
        header('Location: ?page=settings&unbanned=1');
        exit;
    }

    private static function ignoreOrder(array $ctx): void
    {
        IgnoreList::add(
            Comparator::normalise($_POST['order_number'] ?? ''),
            trim($_POST['reason'] ?? '')
        );
        header('Location: ' . self::redirectBack()); exit;
    }

    private static function unignoreOrder(array $ctx): void
    {
        IgnoreList::remove(Comparator::normalise($_POST['order_number'] ?? ''));
        header('Location: ' . self::redirectBack()); exit;
    }

    private static function bulkIgnore(array $ctx): void
    {
        $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
        $reason  = trim($_POST['reason'] ?? '');
        $entries = [];
        foreach ($numbers as $raw) {
            $norm = Comparator::normalise($raw);
            if ($norm) $entries[] = ['number' => $norm, 'reason' => $reason];
        }
        IgnoreList::bulkAdd($entries);
        header('Location: ' . self::redirectBack()); exit;
    }

    private static function bulkUnignore(array $ctx): void
    {
        $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
        $norms   = array_values(array_filter(array_map(Comparator::normalise(...), $numbers)));
        IgnoreList::bulkRemove($norms);
        header('Location: ?page=ignored'); exit;
    }

    private static function importIgnoreCsv(array $ctx): void
    {
        $file   = $_FILES['ignore_csv'] ?? null;
        $reason = trim($_POST['import_reason'] ?? '') ?: 'CSV import ' . date('Y-m-d');
        $count  = ($file && $file['error'] === UPLOAD_ERR_OK)
            ? IgnoreList::importCsv($file['tmp_name'], $reason)
            : 0;
        header('Location: ?page=ignored&imported=' . $count); exit;
    }

    private static function pushToShipStation(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        $loc       = self::redirectBack('run');

        if (!$shopifyId || !$ctx['ssKey'] || !$ctx['ssSecret'] || !$ctx['shopifyToken']) {
            $loc .= '&push_error=' . urlencode('Missing credentials or order ID.');
        } else {
            try {
                $shopify      = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $shopifyOrder = $shopify->getOrder($shopifyId);

                if (empty($shopifyOrder)) {
                    throw new RuntimeException("Order {$shopifyId} not found in Shopify.");
                }

                $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                $created = $ss->createOrder($shopifyOrder);
                $orderNum = $created['orderNumber'] ?? $shopifyId;

                PushLog::append([
                    'order_number' => $orderNum,
                    'shopify_id'   => $shopifyId,
                    'ss_order_id'  => $created['orderId'] ?? null,
                    'pushed_at'    => date('Y-m-d H:i:s'),
                ]);

                $loc .= '&push_ok=' . urlencode($orderNum);
            } catch (Throwable $e) {
                $loc .= '&push_error=' . urlencode($e->getMessage());
            }
        }

        header('Location: ' . $loc); exit;
    }

    private static function previewPush(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        header('Content-Type: application/json');

        if (!$shopifyId || !$ctx['shopifyToken']) {
            echo json_encode(['error' => 'Missing credentials or order ID.']); exit;
        }

        try {
            $shopify      = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
            $shopifyOrder = $shopify->getOrder($shopifyId);

            if (empty($shopifyOrder)) {
                echo json_encode(['error' => "Order {$shopifyId} not found in Shopify."]); exit;
            }

            $ss      = new ShipStation($ctx['ssKey'] ?: 'preview', $ctx['ssSecret'] ?: 'preview');
            $payload = $ss->buildPayload($shopifyOrder);
            echo json_encode(['payload' => $payload], JSON_PRETTY_PRINT);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    private static function orderDetail(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        header('Content-Type: application/json');

        if (!$shopifyId || !$ctx['shopifyToken']) {
            echo json_encode(['error' => 'Missing credentials or order ID.']); exit;
        }

        try {
            $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
            $order   = $shopify->getOrder($shopifyId);
            if (empty($order)) {
                echo json_encode(['error' => "Order {$shopifyId} not found."]); exit;
            }
            echo json_encode(['order' => $order]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    private static function flushCache(array $ctx): void
    {
        // Handled in PageLoader (needs to return flushed count to view) - no early exit.
    }

    private static function csvDownload(array $ctx): void
    {
        $date = $_GET['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400); exit('Invalid date.');
        }
        $path = $ctx['reportDir'] . '/missing_' . $date . '.csv';
        if (!file_exists($path)) {
            http_response_code(404); exit('Report not found.');
        }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="missing_' . $date . '.csv"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function redirectBack(string $defaultPage = 'reports'): string
    {
        $page = $_POST['redirect_page'] ?? $defaultPage;
        $date = $_POST['redirect_date'] ?? '';
        $loc  = '?page=' . urlencode($page);
        if ($date) $loc .= '&date=' . urlencode($date);
        return $loc;
    }
}
