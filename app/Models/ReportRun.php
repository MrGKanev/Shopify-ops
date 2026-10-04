<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * One queued execution of a report and, once finished, its serialized result.
 *
 * The result holds customer data, so it is stored compressed and encrypted.
 */
#[Fillable(['store_id', 'user_id', 'tool', 'report', 'arguments', 'arguments_hash', 'start_date', 'end_date', 'scanned_metric', 'rows_metric', 'status', 'started_at', 'finished_at'])]
#[Hidden(['result'])]
class ReportRun extends Model
{
    use BelongsToStore;

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function storeResult(mixed $result): void
    {
        $this->forceFill([
            'result' => Crypt::encryptString(base64_encode((string) gzcompress(serialize($result)))),
            'status' => 'completed',
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The report's result object, or null while pending or after a failure.
     */
    public function result(): mixed
    {
        if ($this->status !== 'completed' || ! is_string($this->result)) {
            return null;
        }

        $serialized = gzuncompress((string) base64_decode(Crypt::decryptString($this->result), true));

        return $serialized === false ? null : unserialize($serialized);
    }

    /**
     * A count taken from the result: a property name (e.g. "scanned") or "count:<property>" for a list.
     */
    public function metric(mixed $result, string $metric): int
    {
        if (! is_object($result)) {
            return 0;
        }

        if (str_starts_with($metric, 'count:')) {
            $values = $result->{substr($metric, 6)} ?? [];

            return is_countable($values) ? count($values) : 0;
        }

        $value = $result->{$metric} ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
