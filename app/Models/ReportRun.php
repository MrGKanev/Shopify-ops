<?php

namespace App\Models;

use App\Application\Reports\ReportResult;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * One queued execution of a report and, once finished, its JSON result.
 *
 * The result holds customer data, so it is stored compressed and encrypted.
 *
 * @return ReportResult<array<string, mixed>>|null
 */
#[Fillable(['store_id', 'user_id', 'tool', 'report', 'arguments', 'arguments_hash', 'start_date', 'end_date', 'status', 'started_at', 'finished_at'])]
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

    /** @param ReportResult<array<string, mixed>> $result */
    public function storeResult(ReportResult $result): void
    {
        $this->forceFill([
            'result' => Crypt::encryptString(base64_encode((string) gzcompress(json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)))),
            'status' => 'completed',
            'failure_reason' => null,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The report's result object, or null while pending or after a failure.
     *
     * @return ReportResult<array<string, mixed>>|null
     */
    public function result(): ?ReportResult
    {
        if ($this->status !== 'completed' || ! is_string($this->result)) {
            return null;
        }

        $json = gzuncompress((string) base64_decode(Crypt::decryptString($this->result), true));

        return $json === false ? null : ReportResult::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
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
