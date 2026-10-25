<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property list<mixed> $arguments
 * @property array<string, mixed> $parameters
 */
#[Fillable(['store_id', 'user_id', 'name', 'tool', 'arguments', 'parameters', 'uses_date_range', 'enabled', 'frequency', 'time', 'timezone', 'weekday', 'month_day', 'lookback_days', 'end_offset_days', 'notification_enabled', 'notification_threshold', 'notification_email', 'next_run_at'])]
#[Hidden(['arguments', 'parameters'])]
class ReportSchedule extends Model
{
    /** @use HasFactory<ReportScheduleFactory> */
    use BelongsToStore, HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ReportRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }

    /** @return HasOne<ReportRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ReportRun::class)->latestOfMany();
    }

    public function recordOutcome(ReportRun $run): void
    {
        $this->forceFill(['last_run_status' => $run->status, 'last_finished_at' => $run->finished_at ?? now(), 'last_run_error' => $run->failure_reason])->save();
    }

    public function cancelQueuedAutomaticRuns(): void
    {
        if ($this->runs()->where('status', 'queued')->whereNotNull('scheduled_for')->update(['status' => 'cancelled', 'finished_at' => now()]) > 0) {
            $latest = $this->latestRun()->first();
            if ($latest?->status === 'cancelled') {
                $this->recordOutcome($latest);
            }
        }
    }

    protected function casts(): array
    {
        return ['arguments' => 'encrypted:array', 'parameters' => 'encrypted:array', 'uses_date_range' => 'boolean', 'enabled' => 'boolean', 'notification_enabled' => 'boolean', 'weekday' => 'integer', 'month_day' => 'integer', 'lookback_days' => 'integer', 'end_offset_days' => 'integer', 'notification_threshold' => 'integer', 'next_run_at' => 'immutable_datetime', 'last_finished_at' => 'immutable_datetime'];
    }
}
