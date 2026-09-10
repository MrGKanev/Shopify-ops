<?php

namespace App\Models;

use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'slug',
    'label',
    'shopify_store',
    'shopify_access_token',
    'shipstation_api_key',
    'shipstation_api_secret',
    'store_number',
    'slack_rules',
])]
#[Hidden(['shopify_access_token', 'shipstation_api_key', 'shipstation_api_secret'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('administration')->logOnly(['slug', 'label', 'shopify_store', 'store_number'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return HasMany<IgnoredOrder, $this> */
    public function ignoredOrders(): HasMany
    {
        return $this->hasMany(IgnoredOrder::class);
    }

    /** @return HasMany<PushLog, $this> */
    public function pushLogs(): HasMany
    {
        return $this->hasMany(PushLog::class);
    }

    /** @return HasMany<RunLog, $this> */
    public function runLogs(): HasMany
    {
        return $this->hasMany(RunLog::class);
    }

    /** @return HasMany<AuditSnapshot, $this> */
    public function auditSnapshots(): HasMany
    {
        return $this->hasMany(AuditSnapshot::class);
    }

    /** @return HasMany<PrintQueueItem, $this> */
    public function printQueueItems(): HasMany
    {
        return $this->hasMany(PrintQueueItem::class);
    }

    /** @return array{audit_enabled:bool,audit_min_missing:int,include_zero_audit:bool,scan_enabled:bool,scan_min_rows:int,mentions:string} */
    public function resolvedSlackRules(): array
    {
        $rules = array_replace(['audit_enabled' => true, 'audit_min_missing' => 0, 'include_zero_audit' => true, 'scan_enabled' => false, 'scan_min_rows' => 1, 'mentions' => ''], $this->slack_rules ?? []);

        return ['audit_enabled' => (bool) $rules['audit_enabled'], 'audit_min_missing' => max(0, (int) $rules['audit_min_missing']), 'include_zero_audit' => (bool) $rules['include_zero_audit'], 'scan_enabled' => (bool) $rules['scan_enabled'], 'scan_min_rows' => max(1, (int) $rules['scan_min_rows']), 'mentions' => (string) $rules['mentions']];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shopify_access_token' => 'encrypted',
            'shipstation_api_key' => 'encrypted',
            'shipstation_api_secret' => 'encrypted',
            'slack_rules' => 'array',
        ];
    }
}
