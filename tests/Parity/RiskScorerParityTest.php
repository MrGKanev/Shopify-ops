<?php

declare(strict_types=1);

use App\Domain\Orders\OrderRiskScorer;
use PHPUnit\Framework\TestCase;

final class RiskScorerParityTest extends TestCase
{
    /**
     * `RiskScorer`/`OrderRiskScorer` back the Fraud Risk Report, Spot-check,
     * Customer Lookup, and Order Timeline's risk badge — a wrong score or
     * level here silently mis-triages which orders an operator investigates
     * for fraud. Covers all 8 signals individually, the low/medium/high
     * threshold boundaries (21 and 51 are unreachable with the default
     * weights, so the nearest achievable score on each side is pinned), and
     * a multi-signal accumulation case.
     *
     * `signals` is deliberately NOT compared: legacy returns
     * `list<{label: string, points: int}>` (consumed by `riskBadge()` to
     * show a per-signal point breakdown), while `OrderRiskScorer` returns
     * `list<string>` (labels only, no points) — consumed as such by 3
     * Laravel blade views (`fraud-risk`, `spot-check`, `orders/timeline`)
     * and asserted on by 2 existing Laravel unit tests. Bringing that in
     * sync means widening one domain class plus 3 views plus their tests —
     * a larger, separate unit of work than the single-class fixes done so
     * far this session (same reasoning as the Slack/Discord notification
     * content gap). Score and level are unaffected: both sides compute them
     * from the same fixed default weights (no `data/risk_weights.json`
     * exists in this repo, so the custom-override path is dead code on both
     * sides today). See `docs/laravel-todo.md`.
     */
    public function test_score_and_level_match_legacy(): void
    {
        $cases = [
            'clean order' => ['email' => 'good@example.com', 'billing_address' => ['country_code' => 'US'], 'shipping_address' => ['country_code' => 'US', 'phone' => '555-1234', 'address1' => '123 Main St'], 'total_price' => '50.00', 'financial_status' => 'paid', 'tags' => ''],
            'invalid email' => ['email' => 'not-an-email'],
            'disposable domain' => ['email' => 'someone@mailinator.com'],
            'empty email skipped' => ['email' => ''],
            'country mismatch' => ['billing_address' => ['country_code' => 'US'], 'shipping_address' => ['country_code' => 'CA', 'address1' => '']],
            'same country' => ['billing_address' => ['country_code' => 'US'], 'shipping_address' => ['country_code' => 'US', 'address1' => '']],
            'graphql country field' => ['billing_address' => ['countryCodeV2' => 'DE'], 'shipping_address' => ['countryCodeV2' => 'FR', 'address1' => '']],
            'missing phone high value' => ['shipping_address' => ['country_code' => 'US', 'phone' => '', 'address1' => ''], 'total_price' => '201.00'],
            'phone present high value' => ['shipping_address' => ['phone' => '555-9999', 'address1' => ''], 'total_price' => '500.00'],
            'low value skips phone check' => ['shipping_address' => ['phone' => '', 'address1' => ''], 'total_price' => '199.99'],
            'graphql total price set' => ['totalPriceSet' => ['shopMoney' => ['amount' => '300.00']], 'shipping_address' => ['phone' => '', 'address1' => '']],
            'po box' => ['shipping_address' => ['address1' => 'P.O. Box 5']],
            'normal address' => ['shipping_address' => ['address1' => '123 Main St']],
            'partially paid' => ['financial_status' => 'partially_paid'],
            'graphql financial status' => ['displayFinancialStatus' => 'PARTIALLY PAID'],
            'paid no signal' => ['financial_status' => 'paid'],
            'fraud tag string' => ['tags' => 'vip, fraud, returning'],
            'high-risk tag array' => ['tags' => ['vip', 'high-risk']],
            'innocuous tags' => ['tags' => 'wholesale, vip'],
            'null tags' => ['tags' => null],
            'missing tags key' => [],
            'shopify high risk' => ['risk_level' => 'HIGH'],
            'shopify medium risk no signal' => ['risk_level' => 'MEDIUM'],
            'explicit null shipping address' => ['shipping_address' => null],
            'missing shipping address key' => [],
            'boundary: 20 stays low' => ['shipping_address' => null],
            'boundary: 25 is medium' => ['billing_address' => ['country_code' => 'US'], 'shipping_address' => ['country_code' => 'CA']],
            'boundary: 50 stays medium' => ['email' => 'not-an-email', 'shipping_address' => null],
            'boundary: 55 is high' => ['email' => 'not-an-email', 'billing_address' => ['country_code' => 'US'], 'shipping_address' => ['country_code' => 'CA']],
            'high risk + po box + partially paid = 60' => ['risk_level' => 'HIGH', 'shipping_address' => ['address1' => 'PO Box 1'], 'financial_status' => 'partially_paid'],
            'multiple signals accumulate' => ['email' => 'x@mailinator.com', 'financial_status' => 'partially_paid'],
        ];

        $laravelScorer = new OrderRiskScorer();

        foreach ($cases as $name => $order) {
            $legacy = \RiskScorer::score($order);
            $laravel = $laravelScorer->score($order);

            $this->assertSame($legacy['score'], $laravel['score'], "score mismatch for: {$name}");
            $this->assertSame($legacy['level'], $laravel['level'], "level mismatch for: {$name}");
        }
    }
}
