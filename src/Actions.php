<?php
declare(strict_types=1);

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
            'queue_audit'         => self::queueAudit($ctx),
            'save_slack_rules'    => self::saveSlackRules($ctx),
            'preview_push'        => self::previewPush($ctx),
            'order_detail'        => self::orderDetail($ctx),
            'flush_cache'         => self::flushCache($ctx),
            'add_user'            => self::addUser($ctx),
            'delete_user'         => self::deleteUser($ctx),
            'save_order_note'     => self::saveOrderNote($ctx),
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
        $storeId = $_POST['store_id'] ?? '';
        Stores::setActive($storeId);
        UserActionLog::append('switch_store', ['store_id' => $storeId]);
        header('Location: ?');
        exit;
    }

    private static function unbanIp(array $ctx): void
    {
        $ip = $_POST['ip'] ?? '';
        Auth::unban($ip);
        UserActionLog::append('unban_ip', ['ip' => $ip]);
        header('Location: ?page=settings&unbanned=1');
        exit;
    }

    private static function ignoreOrder(array $ctx): void
    {
        $norm = Comparator::normalise($_POST['order_number'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        IgnoreList::add($norm, $reason);
        UserActionLog::append('ignore_order', ['order_number' => $norm, 'reason' => $reason]);
        header('Location: ' . self::redirectBack()); exit;
    }

    private static function unignoreOrder(array $ctx): void
    {
        $norm = Comparator::normalise($_POST['order_number'] ?? '');
        IgnoreList::remove($norm);
        UserActionLog::append('unignore_order', ['order_number' => $norm]);
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
        UserActionLog::append('bulk_ignore_orders', ['count' => count($entries), 'reason' => $reason]);
        header('Location: ' . self::redirectBack()); exit;
    }

    private static function bulkUnignore(array $ctx): void
    {
        $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
        $norms   = array_values(array_filter(array_map(Comparator::normalise(...), $numbers)));
        IgnoreList::bulkRemove($norms);
        UserActionLog::append('bulk_unignore_orders', ['count' => count($norms)]);
        header('Location: ?page=ignored'); exit;
    }

    private static function importIgnoreCsv(array $ctx): void
    {
        $file   = $_FILES['ignore_csv'] ?? null;
        $reason = trim($_POST['import_reason'] ?? '') ?: 'CSV import ' . date('Y-m-d');
        $count  = ($file && $file['error'] === UPLOAD_ERR_OK)
            ? IgnoreList::importCsv($file['tmp_name'], $reason)
            : 0;
        UserActionLog::append('import_ignore_csv', ['count' => $count, 'reason' => $reason]);
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
                UserActionLog::append('push_to_shipstation', [
                    'order_number' => $orderNum,
                    'shopify_id'   => $shopifyId,
                    'ss_order_id'  => $created['orderId'] ?? null,
                ]);

                $loc .= '&push_ok=' . urlencode($orderNum);
            } catch (Throwable $e) {
                $loc .= '&push_error=' . urlencode($e->getMessage());
            }
        }

        header('Location: ' . $loc); exit;
    }

    private static function queueAudit(array $ctx): void
    {
        $start = trim($_POST['audit_start'] ?? '');
        $end   = trim($_POST['audit_end'] ?? '');
        $loc   = '?page=jobs';

        if ($err = DateRange::validate($start, $end)) {
            header('Location: ' . $loc . '&queue_error=' . urlencode($err)); exit;
        }

        $id = JobQueue::enqueue('audit', [
            'start'    => $start,
            'end'      => $end,
            'store_id' => $ctx['storeId'] ?? '',
        ], "Audit {$start} -> {$end}");
        UserActionLog::append('queue_audit', ['job_id' => $id, 'start' => $start, 'end' => $end]);
        header('Location: ' . $loc . '&queued=' . urlencode($id)); exit;
    }

    private static function saveSlackRules(array $ctx): void
    {
        $rules = [
            'audit_enabled'      => isset($_POST['audit_enabled']),
            'audit_min_missing'  => $_POST['audit_min_missing'] ?? 0,
            'scan_enabled'       => isset($_POST['scan_enabled']),
            'scan_min_rows'      => $_POST['scan_min_rows'] ?? 1,
            'include_zero_audit' => isset($_POST['include_zero_audit']),
        ];
        SlackRules::save($rules);
        UserActionLog::append('save_slack_rules', SlackRules::load());
        header('Location: ?page=slackrules&saved=1'); exit;
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

    private static function addUser(array $ctx): void
    {
        $username = trim($_POST['new_username'] ?? '');
        $password = $_POST['new_password'] ?? '';
        $role     = $_POST['new_role'] ?? 'viewer';

        if (!in_array($role, ['viewer', 'operator', 'admin'], true)) {
            header('Location: ?page=settings&user_error=' . urlencode('Invalid role.')); exit;
        }
        if ($username === '' || $password === '') {
            header('Location: ?page=settings&user_error=' . urlencode('Username and password are required.')); exit;
        }

        $users = Auth::loadUsers();
        foreach ($users as $u) {
            if (($u['name'] ?? '') === $username) {
                header('Location: ?page=settings&user_error=' . urlencode('A user with that username already exists.')); exit;
            }
        }

        $users[] = [
            'name'          => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
        ];
        Auth::saveUsers($users);
        UserActionLog::append('add_user', ['username' => $username, 'role' => $role]);
        header('Location: ?page=settings&user_added=1'); exit;
    }

    private static function deleteUser(array $ctx): void
    {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            header('Location: ?page=settings'); exit;
        }

        $users = Auth::loadUsers();
        $users = array_values(array_filter($users, fn($u) => ($u['name'] ?? '') !== $username));
        Auth::saveUsers($users);
        UserActionLog::append('delete_user', ['username' => $username]);
        header('Location: ?page=settings&user_deleted=1'); exit;
    }

    private static function saveOrderNote(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        $note      = trim($_POST['note'] ?? '');
        $loc       = self::redirectBack('spotcheck');

        if (!$shopifyId) {
            header('Location: ' . $loc . '&note_error=' . urlencode('Missing order ID.') . '&note_order=' . urlencode($shopifyId));
            exit;
        }

        if (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
            header('Location: ' . $loc . '&note_error=' . urlencode('Shopify credentials not configured.') . '&note_order=' . urlencode($shopifyId));
            exit;
        }

        try {
            $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
            $shopify->updateOrderNote($shopifyId, $note);
            UserActionLog::append('save_order_note', ['shopify_id' => $shopifyId, 'note_length' => strlen($note)]);
            header('Location: ' . $loc . '&note_ok=' . urlencode($shopifyId));
        } catch (Throwable $e) {
            header('Location: ' . $loc . '&note_error=' . urlencode($e->getMessage()) . '&note_order=' . urlencode($shopifyId));
        }
        exit;
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
