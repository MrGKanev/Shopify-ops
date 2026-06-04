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
        'finChip'  => financialChip($fin),
        'finLabel' => $o['displayFinancialStatus']   ?? '-',
        'fulLabel' => $o['displayFulfillmentStatus'] ?? '-',
        'amount'   => $o['totalPriceSet']['shopMoney']['amount']       ?? null,
        'currency' => $o['totalPriceSet']['shopMoney']['currencyCode'] ?? '',
    ];
}

function formatPrice(float|string|null $amount): string
{
    if ($amount === null || $amount === '') return '-';
    return '$' . number_format((float) $amount, 2);
}

function financialChip(string $status): string
{
    return match(strtolower($status)) {
        'paid'                   => 'chip-paid',
        'partially_paid'         => 'chip-partial',
        'unpaid', 'pending'      => 'chip-unpaid',
        default                  => 'chip-unknown',
    };
}

function formatAddressLine(?array $addr): string
{
    if ($addr === null) return '';
    return implode(', ', array_filter([
        trim(($addr['address1'] ?? '') . ' ' . ($addr['address2'] ?? '')),
        $addr['city']          ?? '',
        $addr['province_code'] ?? $addr['state']   ?? '',
        $addr['zip']           ?? $addr['postalCode'] ?? '',
        $addr['country_code']  ?? $addr['country'] ?? '',
    ]));
}

function topbar(string $title, string $meta = ''): string
{
    return '<div class="topbar"><div>'
        . '<h1>' . esc($title) . '</h1>'
        . ($meta ? '<div class="meta">' . esc($meta) . '</div>' : '')
        . '</div></div>';
}

function featureInfoStart(string $key, string $label): string
{
    return '<div class="feature-info" data-info-key="' . esc($key) . '">'
        . '<button class="feature-info-toggle" aria-expanded="false">'
        . '<svg width="12" height="12" viewBox="0 0 10 6" fill="none">'
        . '<path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg> About: ' . esc($label) . '</button>'
        . '<div class="feature-info-body">';
}

function featureInfoEnd(): string
{
    return '</div></div>';
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

function datePresets(): string
{
    $presets = [
        7   => '1 week',
        21  => '3 weeks',
        30  => '1 month',
        90  => '3 months',
        180 => '6 months',
        365 => '1 year',
    ];
    $html = '<div class="preset-row"><span class="preset-label">Quick select:</span>';
    foreach ($presets as $days => $label) {
        $html .= '<button class="preset-btn" type="button" data-days="' . $days . '">' . $label . '</button>';
    }
    return $html . '</div>';
}
