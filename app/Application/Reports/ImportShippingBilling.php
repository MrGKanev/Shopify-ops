<?php

namespace App\Application\Reports;

use App\Domain\Reports\ExactDecimalAmount;
use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader;

class ImportShippingBilling
{
    public const array HEADERS = ['shipstation_store_id', 'transaction_id', 'shipment_id', 'order_id', 'order_number', 'tracking_number', 'type', 'amount', 'currency', 'posted_at', 'carrier', 'service', 'package', 'skus', 'reason'];

    public function __construct(private readonly ExactDecimalAmount $amounts) {}

    /** @return list<array<string, string>> */
    public function parse(UploadedFile $file, Store $store): array
    {
        if ($store->missingShipStationCredentials() || ! ctype_digit((string) $store->store_number) || (int) $store->store_number < 1) {
            throw ValidationException::withMessages(['file' => __('Configure the ShipStation account and store number before importing billing.')]);
        }
        $content = $file->getContent();
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('Use a UTF-8 CSV file.')]);
        }
        $reader = Reader::fromString(str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content);
        $reader->setEscape('');
        $header = null;
        $rows = [];
        foreach ($reader->getRecords() as $offset => $values) {
            if ($header === null) {
                $expected = self::HEADERS;
                $actual = $values;
                sort($expected);
                sort($actual);
                if ($actual !== $expected) {
                    throw ValidationException::withMessages(['file' => __('CSV columns must match the billing template exactly.')]);
                }
                $header = $values;

                continue;
            }
            if (count($values) !== count($header) || count($rows) >= 5000) {
                throw ValidationException::withMessages(['file' => __('Use at most 5000 complete rows with exactly the template columns.')]);
            }
            $row = array_combine($header, array_map(fn (?string $value): string => trim($value ?? ''), $values));
            $row['currency'] = strtoupper($row['currency']);
            $validator = Validator::make($row, [
                'shipstation_store_id' => ['required', 'in:'.$store->store_number],
                'transaction_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
                'shipment_id' => ['required', 'regex:/^[1-9][0-9]{0,19}$/D'], 'order_id' => ['required', 'regex:/^[1-9][0-9]{0,19}$/D'],
                'order_number' => ['required', 'string', 'max:100'], 'tracking_number' => ['nullable', 'string', 'max:100'],
                'type' => ['required', 'in:label_charge,insurance_charge,adjustment_debit,adjustment_credit,label_refund,label_refund_reversal'],
                'amount' => ['required', 'regex:/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
                'posted_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now($store->shopTimezone())->toDateString()],
                'carrier' => ['nullable', 'string', 'max:80'], 'service' => ['nullable', 'string', 'max:80'], 'package' => ['nullable', 'string', 'max:80'], 'skus' => ['nullable', 'string', 'max:500'], 'reason' => ['nullable', 'string', 'max:500'],
            ]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['file' => __('CSV row :row: :message', ['row' => $offset + 1, 'message' => $validator->errors()->first()])]);
            }
            unset($row['shipstation_store_id']);
            $row['amount'] = (string) ($this->amounts->parse($row['amount']) ?? throw new \UnexpectedValueException('Invalid billing amount.'))->toScale(6);
            $row['order_number'] = ltrim($row['order_number'], '#');
            if ($row['order_number'] === '') {
                throw ValidationException::withMessages(['file' => __('The order reference must not be empty.')]);
            }
            $skus = array_values(array_unique(array_filter(array_map('trim', explode(';', $row['skus'])), fn (string $sku): bool => $sku !== '')));
            sort($skus);
            $row['skus'] = implode(';', $skus);
            ksort($row);
            $rows[] = $row;
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The billing file contains no transactions.')]);
        }

        return $rows;
    }

    /** @param list<array<string, string>> $rows
     * @return array{created: int, duplicates: int} */
    public function handle(Store $store, array $rows, string $sourceAccount, string $statement, string $fileHash): array
    {
        return DB::transaction(function () use ($store, $rows, $sourceAccount, $statement, $fileHash): array {
            $created = $duplicates = 0;
            foreach ($rows as $row) {
                $hash = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                $identity = ['store_id' => $store->id, 'account_fingerprint' => ShippingBillingEntry::accountFingerprint($store), 'source_account' => $sourceAccount, 'transaction_id' => $row['transaction_id']];
                $entry = ShippingBillingEntry::query()->firstOrCreate($identity, [...$row, 'statement_reference' => $statement, 'file_hash' => $fileHash, 'payload_hash' => $hash]);
                if ($entry->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['import' => __('A transaction ID already exists with different data. No rows were imported.')]);
                }
                $entry->wasRecentlyCreated ? $created++ : $duplicates++;
            }

            return compact('created', 'duplicates');
        });
    }
}
