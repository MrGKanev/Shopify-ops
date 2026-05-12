<?php
/**
 * Global view-helper functions.
 * Keep all functions at global scope — views call them without qualification.
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
