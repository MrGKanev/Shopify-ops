<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class UiFormat
{
    public static function count(int $value, string $label): string
    {
        $key = 'counts.'.$label;

        return __($key) !== $key
            ? trans_choice($key, $value)
            : __(':count :label', ['count' => $value, 'label' => __($label)]);
    }

    public static function number(int|float $value, int $precision = 0): string
    {
        return (string) Number::format($value, precision: $precision, locale: app()->getLocale());
    }

    public static function date(CarbonInterface|string|null $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);
        if (app()->isLocale('en')) {
            return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }

        return $date->copy()->timezone(config('app.timezone'))->locale(app()->getLocale())
            ->isoFormat($withTime ? 'L LT' : 'L');
    }

    public static function relative(CarbonInterface $value): string
    {
        return $value->copy()->locale(app()->getLocale())->diffForHumans();
    }

    /** Translate messages persisted by earlier runs without changing their technical data. */
    public static function text(string $value): string
    {
        $translated = __($value);
        if ($translated !== $value) {
            return $translated;
        }

        $patterns = [
            '/^Order (.+) has shipping address problems\. Review them, then confirm to push anyway\.$/' => ['Order :number has shipping address problems. Review them, then confirm to push anyway.', ['number']],
            '/^Order (.+) not found in Shopify\.$/' => ['Order :number not found in Shopify.', ['number']],
            '/^Shopify refund created for (.+)$/' => ['Shopify refund created for :reference', ['reference']],
            '/^Shopify dispute opened for (.+)$/' => ['Shopify dispute opened for :reference', ['reference']],
            '/^Missing order #(.+)$/' => ['Missing order #:reference', ['reference']],
            '/^Missing order (.+)$/' => ['Missing order :reference', ['reference']],
            '/^Missing: (.+)$/' => ['Missing: :tags', ['tags']],
            '/^Combination: (.+)$/' => ['Combination: :tags', ['tags']],
            '/^Slow to ship: (\d+) days between order placement and first fulfillment$/' => ['Slow to ship: :days days between order placement and first fulfillment', ['days']],
            '/^Order is refunded in Shopify but still active in ShipStation \((.+)\)$/' => ['Order is refunded in Shopify but still active in ShipStation (:status)', ['status']],
            '/^Order has (\d+) separate fulfillments \(split shipment\)$/' => ['Order has :count separate fulfillments (split shipment)', ['count']],
            '/^(\d+) of (\d+) variants? missing SKU$/' => [':missing of :total variants missing SKU', ['missing', 'total']],
            '/^Disposable \/ temporary email domain \((.+)\)$/' => ['Disposable / temporary email domain (:domain)', ['domain']],
            '/^Expiring in (\d+)d$/' => ['Expiring in :days days', ['days']],
            '/^(\d+) items?(.*)$/' => [':count items:suffix', ['count', 'suffix']],
            '/^Shopify webhook: (.+)$/' => ['Shopify webhook: :topic', ['topic']],
            '/^Status: (.+)$/' => ['Status: :status', ['status']],
            '/^Failure: (.+)$/' => ['Failure: :category', ['category']],
            '/^ShipStation order ID (.+)$/' => ['ShipStation order ID :id', ['id']],
            '/^ShipStation: (.+)$/' => ['ShipStation: :status', ['status']],
        ];
        foreach ($patterns as $pattern => [$key, $attributes]) {
            if (preg_match($pattern, $value, $matches) === 1) {
                $replacements = array_combine($attributes, array_slice($matches, 1));
                if (isset($replacements['status'])) {
                    $replacements['status'] = __(mb_strtolower(str_replace(' ', '_', $replacements['status'])));
                }

                return __($key, $replacements);
            }
        }

        return $value;
    }
}
