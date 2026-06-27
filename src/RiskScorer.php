<?php
declare(strict_types=1);

/**
 * Composite Risk Score utility.
 *
 * Accepts a Shopify order array (either the REST-normalised shape used by
 * spotcheck/SearchLookupPageLoader or the raw GraphQL node shape returned by
 * the customer-lookup query) and returns a score + signal breakdown.
 *
 * Custom weights may be stored in data/risk_weights.json (copy from
 * data/risk_weights.json.example to enable).  Keys must match signal names
 * exactly; the file is loaded once per process and merged over the defaults.
 */
class RiskScorer
{
    // ── Default signal weights ────────────────────────────────────────────────

    private const DEFAULTS = [
        'Disposable/invalid email'          => 30,
        'Billing ≠ shipping country'        => 25,
        'Missing phone on high-value order' => 15,
        'PO Box address'                    => 10,
        'Partially paid'                    => 10,
        'Fraud/high-risk tag'               => 35,
        'Shopify HIGH risk level'           => 40,
        'No shipping address'               => 20,
    ];

    /** Threshold above which the order is considered high-value for the phone check. */
    private const HV_THRESHOLD = 200;

    /** Disposable / temporary email domains (mirrors emailcheck.php). */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwam.com',
        'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
        'guerrillamail.info', 'trashmail.com', 'trashmail.net', 'trashmail.org',
        'dispostable.com', 'maildrop.cc', 'spamgourmet.com', 'spamgourmet.net',
        'mailnull.com', 'spamcorner.com', '10minutemail.com', '10minutemail.net',
        'fakeinbox.com', 'mailnesia.com', 'discard.email', 'spamspot.com',
        'mytemp.email', 'temp-mail.org', 'getnada.com', 'tempr.email',
    ];

    // ── Weight cache ──────────────────────────────────────────────────────────

    private static ?array $customWeights = null;
    private static bool   $weightsLoaded = false;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Score a single Shopify order.
     *
     * Handles both the REST-normalised shape (spotcheck) and the raw GraphQL
     * node shape (customer-lookup).  Signals for which the necessary fields are
     * absent in the supplied array are silently skipped (graceful degradation).
     *
     * @param  array $order The order data array.
     * @param  array $opts  Reserved for future options.
     * @return array{score: int, level: string, signals: list<array{label: string, points: int}>}
     */
    public static function score(array $order, array $opts = []): array
    {
        $weights = self::weights();
        $signals = [];
        $score   = 0;

        // ── 1. Disposable / invalid email ─────────────────────────────────────
        $email = strtolower(trim((string) ($order['email'] ?? '')));
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $pts       = $weights['Disposable/invalid email'];
                $signals[] = ['label' => 'Disposable/invalid email', 'points' => $pts];
                $score    += $pts;
            } else {
                $domain = substr($email, strrpos($email, '@') + 1);
                if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
                    $pts       = $weights['Disposable/invalid email'];
                    $signals[] = ['label' => 'Disposable/invalid email', 'points' => $pts];
                    $score    += $pts;
                }
            }
        }

        // ── 2. Billing country ≠ shipping country ─────────────────────────────
        $bill = $order['billing_address']  ?? null;
        $ship = $order['shipping_address'] ?? null;
        if (is_array($bill) && is_array($ship)) {
            $billCountry = strtoupper(trim((string) (
                $bill['country_code'] ?? $bill['countryCodeV2'] ?? $bill['country'] ?? ''
            )));
            $shipCountry = strtoupper(trim((string) (
                $ship['country_code'] ?? $ship['countryCodeV2'] ?? $ship['country'] ?? ''
            )));
            if ($billCountry !== '' && $shipCountry !== '' && $billCountry !== $shipCountry) {
                $pts       = $weights['Billing ≠ shipping country'];
                $signals[] = ['label' => 'Billing ≠ shipping country', 'points' => $pts];
                $score    += $pts;
            }
        }

        // ── 3. Missing phone on high-value order (> $200) ─────────────────────
        $total = (float) (
            $order['total_price']
            ?? $order['totalPriceSet']['shopMoney']['amount']
            ?? 0
        );
        if ($total > self::HV_THRESHOLD) {
            $phone = is_array($ship) ? trim((string) ($ship['phone'] ?? '')) : '';
            if ($phone === '') {
                $pts       = $weights['Missing phone on high-value order'];
                $signals[] = ['label' => 'Missing phone on high-value order', 'points' => $pts];
                $score    += $pts;
            }
        }

        // ── 4. PO Box shipping address ────────────────────────────────────────
        if (is_array($ship)) {
            $addr1 = (string) ($ship['address1'] ?? $ship['address_1'] ?? '');
            if (preg_match('/\bP\.?\s*O\.?\s*Box\b/i', $addr1)) {
                $pts       = $weights['PO Box address'];
                $signals[] = ['label' => 'PO Box address', 'points' => $pts];
                $score    += $pts;
            }
        }

        // ── 5. Partially paid ─────────────────────────────────────────────────
        // Handles both REST-normalised 'financial_status' and raw GQL 'displayFinancialStatus'.
        $finRaw    = (string) ($order['financial_status'] ?? $order['displayFinancialStatus'] ?? '');
        $finStatus = strtolower(str_replace(' ', '_', $finRaw));
        if ($finStatus === 'partially_paid') {
            $pts       = $weights['Partially paid'];
            $signals[] = ['label' => 'Partially paid', 'points' => $pts];
            $score    += $pts;
        }

        // ── 6. Order tags contain 'fraud' or 'high-risk' ─────────────────────
        // 'tags' is a comma-separated string in the normalised REST shape;
        // an array of strings in the raw GraphQL shape.
        if (array_key_exists('tags', $order) && $order['tags'] !== null) {
            $tagsRaw = $order['tags'];
            $tagsStr = is_array($tagsRaw) ? implode(', ', $tagsRaw) : (string) $tagsRaw;
            if (preg_match('/\b(fraud|high-risk)\b/i', $tagsStr)) {
                $pts       = $weights['Fraud/high-risk tag'];
                $signals[] = ['label' => 'Fraud/high-risk tag', 'points' => $pts];
                $score    += $pts;
            }
        }

        // ── 7. Shopify risk_level = HIGH ──────────────────────────────────────
        $riskLevel = strtoupper(trim((string) ($order['risk_level'] ?? '')));
        if ($riskLevel === 'HIGH') {
            $pts       = $weights['Shopify HIGH risk level'];
            $signals[] = ['label' => 'Shopify HIGH risk level', 'points' => $pts];
            $score    += $pts;
        }

        // ── 8. No shipping address ────────────────────────────────────────────
        // Only fires when the key is present but null (explicit absence returned
        // from a full order fetch).  Avoids false-positives on limited queries
        // that simply don't include the shipping_address field.
        if (array_key_exists('shipping_address', $order) && $order['shipping_address'] === null) {
            $pts       = $weights['No shipping address'];
            $signals[] = ['label' => 'No shipping address', 'points' => $pts];
            $score    += $pts;
        }

        // ── Level ─────────────────────────────────────────────────────────────
        $level = match (true) {
            $score >= 51 => 'high',
            $score >= 21 => 'medium',
            default      => 'low',
        };

        return ['score' => $score, 'level' => $level, 'signals' => $signals];
    }

    // ── Weight loading ────────────────────────────────────────────────────────

    /**
     * Returns merged weights: defaults overridden by any valid entries in
     * data/risk_weights.json (numeric values only; unknown keys are ignored).
     *
     * @return array<string, int>
     */
    private static function weights(): array
    {
        if (!self::$weightsLoaded) {
            self::$weightsLoaded = true;
            $path = __DIR__ . '/../data/risk_weights.json';
            if (is_file($path) && is_readable($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded)) {
                    self::$customWeights = $decoded;
                }
            }
        }

        if (self::$customWeights === null) {
            return self::DEFAULTS;
        }

        $merged = self::DEFAULTS;
        foreach (self::$customWeights as $key => $value) {
            if (isset($merged[$key]) && is_numeric($value)) {
                $merged[$key] = (int) $value;
            }
        }
        return $merged;
    }
}
