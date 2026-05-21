<?php
/**
 * Global view-helper functions.
 * Keep all functions at global scope - views call them without qualification.
 */

function esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function badge(int $count): string
{
    return $count === 0
        ? '<span class="badge badge-ok">All clear</span>'
        : '<span class="badge badge-warn">' . $count . ' missing</span>';
}

function pushFlashBanner(): string
{
    $ok  = $_GET['push_ok']    ?? '';
    $err = $_GET['push_error'] ?? '';
    if ($ok) {
        return '<div class="flash flash-ok">✓ Order #' . esc($ok) . ' pushed to ShipStation successfully.</div>';
    }
    if ($err) {
        return '<div class="flash flash-err">✗ Push failed: ' . esc($err) . '</div>';
    }
    return '';
}

function classifyOrder(array $order): string
{
    if (!empty($order['order_type'])) return $order['order_type'];
    return Comparator::classifyOrder($order);
}

/**
 * Prepares display variables for a single GraphQL order node.
 * Used by metafields.php, tagsearch.php and any future GraphQL order tables.
 *
 * @return array{url:string|null, name:string, date:string, email:string,
 *               finChip:string, finLabel:string, fulLabel:string,
 *               amount:string|null, currency:string, legacyId:string}
 */
function gqlOrderRow(array $o, string $shopifyAdminBase): array
{
    $legacyId = $o['legacyResourceId'] ?? '';
    $fin      = strtolower($o['displayFinancialStatus'] ?? '');
    return [
        'legacyId' => $legacyId,
        'url'      => $legacyId ? $shopifyAdminBase . '/' . $legacyId : null,
        'name'     => $o['name'] ?? '-',
        'date'     => !empty($o['createdAt']) ? date('Y-m-d', strtotime($o['createdAt'])) : '-',
        'email'    => $o['email'] ?? '-',
        'finChip'  => match($fin) {
            'paid'                        => 'chip-paid',
            'partially_paid'              => 'chip-partial',
            'unpaid', 'pending'           => 'chip-unpaid',
            default                       => 'chip-unknown',
        },
        'finLabel' => $o['displayFinancialStatus']   ?? '-',
        'fulLabel' => $o['displayFulfillmentStatus'] ?? '-',
        'amount'   => $o['totalPriceSet']['shopMoney']['amount']       ?? null,
        'currency' => $o['totalPriceSet']['shopMoney']['currencyCode'] ?? '',
    ];
}

/**
 * Renders a metafield value - pretty-prints JSON, escapes plain text.
 */
function renderMetafieldValue(string $value): string
{
    $decoded = json_decode($value, true);
    if ($decoded !== null) {
        return '<pre class="mf-val-pre">' . esc(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }
    return esc($value);
}
