<?php

namespace App\Integrations\Shopify;

use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * An immutable Shopify search query string, built term by term in the order the terms are added.
 *
 * Date bounds are calendar days in the shop's timezone, sent to Shopify as UTC instants.
 */
final readonly class OrderSearch
{
    /**
     * @param  list<string>  $terms
     */
    private function __construct(private array $terms = []) {}

    public static function make(): self
    {
        return new self;
    }

    public static function anyStatus(): self
    {
        return new self(['status:any']);
    }

    public static function openStatus(): self
    {
        return new self(['status:open']);
    }

    public static function created(Store $store, ?string $startDate, ?string $endDate): self
    {
        return self::make()->createdBetween($store, $startDate, $endDate);
    }

    public function paid(): self
    {
        return $this->with('financial_status:paid');
    }

    public function paidOrPartiallyPaid(): self
    {
        return $this->with('(financial_status:paid OR financial_status:partially_paid)');
    }

    public function refundedOrPartiallyRefunded(): self
    {
        return $this->with('(financial_status:refunded OR financial_status:partially_refunded)');
    }

    public function notRefunded(): self
    {
        return $this->with('-financial_status:refunded');
    }

    public function unfulfilled(): self
    {
        return $this->with('fulfillment_status:unfulfilled');
    }

    public function partiallyFulfilled(): self
    {
        return $this->with('fulfillment_status:partial');
    }

    public function unfulfilledOrPartial(): self
    {
        return $this->with('(fulfillment_status:unfulfilled OR fulfillment_status:partial)');
    }

    public function fulfilledOrPartial(): self
    {
        return $this->with('(fulfillment_status:fulfilled OR fulfillment_status:partial)');
    }

    public function tagged(string $tag): self
    {
        return $this->with('tag:"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $tag).'"');
    }

    public function email(string $email): self
    {
        return $this->with('email:"'.mb_strtolower(trim($email)).'"');
    }

    /**
     * Adds a created_at lower and/or upper bound; a null date leaves that side open.
     */
    public function createdBetween(Store $store, ?string $startDate, ?string $endDate): self
    {
        $search = $this;

        if ($startDate !== null) {
            $search = $search->with('created_at:>='.self::dayStart($store, $startDate));
        }

        if ($endDate !== null) {
            $search = $search->with('created_at:<='.self::dayEnd($store, $endDate));
        }

        return $search;
    }

    public function updatedSince(Store $store, string $startDate): self
    {
        return $this->with('updated_at:>='.self::dayStart($store, $startDate));
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    public function toString(): string
    {
        return implode(' ', $this->terms);
    }

    private function with(string $term): self
    {
        return new self([...$this->terms, $term]);
    }

    /**
     * The UTC search bound for the first second of a calendar day in the shop's timezone.
     */
    private static function dayStart(Store $store, string $date): string
    {
        return CarbonImmutable::parse($date, $store->shopTimezone())->startOfDay()->utc()->format('Y-m-d\\TH:i:s\\Z');
    }

    /**
     * The UTC search bound for the last second of a calendar day in the shop's timezone.
     */
    private static function dayEnd(Store $store, string $date): string
    {
        return CarbonImmutable::parse($date, $store->shopTimezone())->endOfDay()->utc()->format('Y-m-d\\TH:i:s\\Z');
    }
}
