<?php
/**
 * Comparator — pure logic, no I/O.
 */
class Comparator
{
    /**
     * Build a fast lookup: normalised orderNumber → [ss orders].
     *
     * Indexes each SS order under every distinct numeric segment in its
     * orderNumber so that compound formats like "100042-B2" or "Addon-100031"
     * all resolve to their Shopify counterpart correctly.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function buildSSIndex(array $ssOrders): array
    {
        $index = [];
        foreach ($ssOrders as $order) {
            $raw = (string) ($order['orderNumber'] ?? '');
            // Primary key: full-normalised (digits only, all segments joined)
            $full = self::normalise($raw);
            if ($full !== '') {
                $index[$full][] = $order;
            }
            // Secondary keys: each individual contiguous digit-run
            // e.g. "100042-B2" → ["100042", "2"]; "Addon-100031" → ["100031"]
            preg_match_all('/\d+/', $raw, $m);
            foreach ($m[0] as $segment) {
                if ($segment !== $full && $segment !== '') {
                    $index[$segment][] = $order;
                }
            }
        }
        return $index;
    }

    /**
     * Build a secondary lookup: lowercase email → [ss orders].
     * Used as a fallback when order-number matching fails.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function buildSSEmailIndex(array $ssOrders): array
    {
        $index = [];
        foreach ($ssOrders as $order) {
            $email = strtolower(trim($order['customerEmail'] ?? ''));
            if ($email !== '') {
                $index[$email][] = $order;
            }
        }
        return $index;
    }

    /**
     * Compare Shopify orders against the ShipStation index.
     *
     * Match order (first match wins):
     *   1. Order number — normalised numeric segments vs SS orderNumber
     *   2. Email + amount — same customer email and total within 1% tolerance
     *      (catches orders manually entered in SS with a wrong order number)
     *
     * Skipped reasons (stored in $order['_skip_reason']):
     *   cancelled     — cancelled_at is set
     *   financial     — pending / voided / refunded
     *   fulfilled     — fulfillment_status === 'fulfilled' (fully shipped; already processed)
     *   restocked     — fulfillment_status === 'restocked' (returned & restocked after shipment)
     *   on_hold       — fulfillment order has status 'on_hold' (checked post-compare in audit.php
     *                   via Shopify::isOnHold(); requires a separate API call per order)
     *   zero_value    — total_price == 0 (digital downloads, gift cards)
     *   no_shipping   — no shipping lines (fulfilled digitally or local pickup)
     *   ignored       — manually ignored via the web dashboard
     *
     * @param  array<int, array<string, mixed>>                $shopifyOrders
     * @param  array<string, array<int, array<string, mixed>>> $ssIndex
     * @param  array<string, array<string, mixed>>             $ignoredNumbers  key = normalised order number
     * @param  array<string, array<int, array<string, mixed>>> $ssEmailIndex    optional secondary index
     * @return array{missing: list<array>, found: list<array>, skipped: list<array>, ignored: list<array>}
     */
    public static function compare(
        array $shopifyOrders,
        array $ssIndex,
        array $ignoredNumbers = [],
        array $ssEmailIndex   = []
    ): array {
        $missing = [];
        $found   = [];
        $skipped = [];
        $ignored = [];

        foreach ($shopifyOrders as $order) {
            $num = self::normalise((string) ($order['order_number'] ?? $order['name'] ?? ''));

            // ── Ignored (manually dismissed) ──────────────────────────
            if (isset($ignoredNumbers[$num])) {
                $order['_ignore_info'] = $ignoredNumbers[$num];
                $ignored[] = $order;
                continue;
            }

            // ── Cancelled ─────────────────────────────────────────────
            if (!empty($order['cancelled_at'])) {
                $order['_skip_reason'] = 'cancelled';
                $skipped[] = $order;
                continue;
            }

            // ── Financial status ──────────────────────────────────────
            $financial = $order['financial_status'] ?? '';
            if (in_array($financial, ['pending', 'voided', 'refunded', 'partially_refunded'], true)) {
                $order['_skip_reason'] = 'financial';
                $skipped[] = $order;
                continue;
            }

            // ── Fulfillment status ────────────────────────────────────
            // 'fulfilled'  → all line items shipped; order is done.
            // 'restocked'  → items were returned and restocked after shipment
            //                (Shopify sets this when a fulfilment is cancelled
            //                 post-shipment, e.g. a return/void after dispatch).
            // Both cases mean the order is no longer actionable from a
            // ShipStation perspective, so we skip them.
            $fulfillment = $order['fulfillment_status'] ?? null;
            if (in_array($fulfillment, ['fulfilled', 'restocked'], true)) {
                $order['_skip_reason'] = 'fulfilled';
                $skipped[] = $order;
                continue;
            }

            // ── Zero-value orders (digital downloads, gift cards) ─────
            if (isset($order['total_price']) && (float) $order['total_price'] === 0.0) {
                $order['_skip_reason'] = 'zero_value';
                $skipped[] = $order;
                continue;
            }

            // ── No shipping lines (digital / local pickup) ────────────
            // shipping_lines is an array; empty = nothing shipped physically.
            if (array_key_exists('shipping_lines', $order) && $order['shipping_lines'] === []) {
                $order['_skip_reason'] = 'no_shipping';
                $skipped[] = $order;
                continue;
            }

            // ── Match 1: order number ─────────────────────────────────
            // Shopify exposes two identifiers: `order_number` (e.g. 65075) and
            // `name` (e.g. #165075). ShipStation may import either one depending
            // on the integration. We try both so a mismatch in convention doesn't
            // produce false positives.
            $nameNorm = self::normalise((string) ($order['name'] ?? ''));
            $match    = $ssIndex[$num] ?? $ssIndex[$nameNorm] ?? null;

            if ($match !== null) {
                $order['_ss_matches']    = $match;
                $order['_match_method']  = 'order_number';
                $found[] = $order;
                continue;
            }

            // ── Match 2: email + amount ───────────────────────────────
            // Fallback for orders manually entered in SS with a wrong/different
            // order number. Requires the same customer email and a total within
            // 1% of the Shopify total to avoid false positives.
            if (!empty($ssEmailIndex)) {
                $email        = strtolower(trim($order['email'] ?? ''));
                $shopifyTotal = (float) ($order['total_price'] ?? 0);

                if ($email !== '' && isset($ssEmailIndex[$email]) && $shopifyTotal > 0) {
                    foreach ($ssEmailIndex[$email] as $ssOrder) {
                        $ssTotal = (float) ($ssOrder['orderTotal'] ?? 0);
                        if (abs($shopifyTotal - $ssTotal) / $shopifyTotal < 0.01) {
                            $order['_ss_matches']   = [$ssOrder];
                            $order['_match_method'] = 'email+amount';
                            $found[] = $order;
                            continue 2;
                        }
                    }
                }
            }

            $missing[] = $order;
        }

        return compact('missing', 'found', 'skipped', 'ignored');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** Strip leading # and whitespace; keep digits only. */
    public static function normalise(string $raw): string
    {
        return preg_replace('/\D/', '', ltrim(trim($raw), '#'));
    }

    /**
     * Classify a Shopify order using rules defined in order_types.json.
     * Multiple matching rule names are joined with " + " (e.g. "Pro + Accessory").
     * Returns the configured fallback (default "Other") when no rules match.
     */
    public static function classifyOrder(array $order): string
    {
        static $config = null;
        if ($config === null) {
            $file   = __DIR__ . '/../order_types.json';
            $config = file_exists($file)
                ? (json_decode(file_get_contents($file), true) ?: [])
                : [];
        }

        $rules    = $config['rules']    ?? [];
        $fallback = $config['fallback'] ?? 'Other';

        if (empty($rules)) return $fallback;

        $matched = [];
        foreach ($rules as $rule) {
            $name  = $rule['name']  ?? 'Unknown';
            $match = $rule['match'] ?? '';
            $value = $rule['value'] ?? '';

            foreach ($order['line_items'] ?? [] as $li) {
                $sku    = strtolower($li['sku']   ?? '');
                $title  = strtolower($li['title'] ?? '');
                $vendor = strtolower($li['vendor'] ?? '');

                $hit = match($match) {
                    'sku_starts_with'     => str_starts_with($sku, strtolower((string)$value)),
                    'sku_contains'        => str_contains($sku, strtolower((string)$value)),
                    'sku_not_starts_with' => !array_filter((array)$value, fn($p) => str_starts_with($sku, strtolower($p))),
                    'title_contains'      => str_contains($title, strtolower((string)$value)),
                    'vendor_is'           => $vendor === strtolower((string)$value),
                    default               => false,
                };

                if ($hit) {
                    $matched[$name] = true;
                    break;
                }
            }
        }

        return $matched ? implode(' + ', array_keys($matched)) : $fallback;
    }
}
