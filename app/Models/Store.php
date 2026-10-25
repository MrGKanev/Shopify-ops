<?php

namespace App\Models;

use App\Application\Notifications\ChatRules;
use App\Application\Notifications\EmailRules;
use App\Models\Concerns\AsNotificationRules;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property list<array{alias: string, master: string}>|null $sku_aliases
 * @property ChatRules $slack_rules
 * @property ChatRules $discord_rules
 * @property EmailRules $email_rules
 */
#[Fillable([
    'slug',
    'label',
    'shopify_store',
    'shopify_access_token',
    'shopify_webhook_secret',
    'shipstation_api_key',
    'shipstation_api_secret',
    'shipstation_v2_api_key',
    'store_number',
    'slack_rules',
    'email_rules',
    'discord_rules',
    'default_alert_email',
    'scheduled_audit_enabled',
    'scheduled_audit_time',
    'delivery_watch_days',
    'shopify_timezone',
    'return_exception_policy',
    'operational_digest_policy',
    'sku_aliases',
    'sku_aliases_enabled',
    'movement_policy',
])]
#[Hidden(['shipstation_v2_api_key', 'shopify_access_token', 'shopify_webhook_secret', 'shipstation_api_key', 'shipstation_api_secret', 'shipstation_monitoring_token', 'shipstation_monitoring_subscriptions'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, LogsActivity;

    protected static function booted(): void
    {
        static::saving(function (Store $store): void {
            if ($store->exists && $store->isDirty(['shipstation_api_key', 'shipstation_api_secret', 'shipstation_v2_api_key', 'store_number'])) {
                $policy = $store->movementPolicy();
                $policy['access_verified'] = false;
                $store->movement_policy = $policy;
            }
            if ($store->exists && $store->isDirty('shopify_store')) {
                $store->sku_aliases_enabled = false;
            }
            if ($store->exists && $store->isDirty('shopify_store') && ! $store->isDirty('shopify_timezone')) {
                $store->shopify_timezone = null;
            }
        });
    }

    /** @return list<array{alias: string, master: string}> */
    public function activeSkuAliases(): array
    {
        return $this->sku_aliases_enabled ? ($this->sku_aliases ?? []) : [];
    }

    /** @return array{access_verified: bool, lookback_days: int, acceptance_hours: int, stale_hours: int, max_labels: int, daily_limit: int, carriers: list<string>} */
    public function movementPolicy(): array
    {
        $policy = is_array($this->movement_policy) ? $this->movement_policy : [];

        return ['access_verified' => ($policy['access_verified'] ?? false) === true || ($policy['access_verified'] ?? null) === '1' || ($policy['access_verified'] ?? null) === 1,
            'lookback_days' => max(1, min(90, (int) ($policy['lookback_days'] ?? 30))), 'acceptance_hours' => max(1, min(720, (int) ($policy['acceptance_hours'] ?? 48))), 'stale_hours' => max(1, min(2160, (int) ($policy['stale_hours'] ?? 72))), 'max_labels' => max(1, min(10, (int) ($policy['max_labels'] ?? 5))), 'daily_limit' => max(0, min(1000, (int) ($policy['daily_limit'] ?? 100))), 'carriers' => array_values($policy['carriers'] ?? ['ups', 'fedex', 'usps', 'stamps_com'])];
    }

    /** @return HasMany<ShipmentMovementObservation, $this> */
    public function shipmentMovementObservations(): HasMany
    {
        return $this->hasMany(ShipmentMovementObservation::class);
    }

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

    /** @return HasMany<ReportRun, $this> */
    public function reportRuns(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }

    /** @return HasMany<RunLog, $this> */
    public function runLogs(): HasMany
    {
        return $this->hasMany(RunLog::class);
    }

    /** @return HasMany<AuditJob, $this> */
    public function auditJobs(): HasMany
    {
        return $this->hasMany(AuditJob::class);
    }

    /** @return HasMany<AuditSnapshot, $this> */
    public function auditSnapshots(): HasMany
    {
        return $this->hasMany(AuditSnapshot::class);
    }

    /** @return HasMany<ShippingBillingEntry, $this> */
    public function shippingBillingEntries(): HasMany
    {
        return $this->hasMany(ShippingBillingEntry::class);
    }

    /** @return HasMany<ReportSchedule, $this> */
    public function reportSchedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    /** @return HasMany<PrintQueueItem, $this> */
    public function printQueueItems(): HasMany
    {
        return $this->hasMany(PrintQueueItem::class);
    }

    /** @return HasMany<OperationalIssue, $this> */
    public function operationalIssues(): HasMany
    {
        return $this->hasMany(OperationalIssue::class);
    }

    /** @return HasMany<RateQuoteSnapshot, $this> */
    public function rateQuoteSnapshots(): HasMany
    {
        return $this->hasMany(RateQuoteSnapshot::class);
    }

    /** @return HasMany<RemediationRun, $this> */
    public function remediationRuns(): HasMany
    {
        return $this->hasMany(RemediationRun::class);
    }

    /** @return HasMany<WebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    /** @return HasMany<ShipStationEvent, $this> */
    public function shipStationEvents(): HasMany
    {
        return $this->hasMany(ShipStationEvent::class);
    }

    public function shipStationMonitoringCanQueue(): bool
    {
        return in_array(config('queue.connections.'.config('queue.default').'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }

    /** @return array{approval_days: int, processing_days: int, exchange_days: int} */
    public function returnExceptionPolicy(): array
    {
        $policy = is_array($this->return_exception_policy) ? $this->return_exception_policy : [];
        $defaults = ['approval_days' => 2, 'processing_days' => 3, 'exchange_days' => 5];
        foreach ($defaults as $key => $default) {
            $value = $policy[$key] ?? $default;
            $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
            $defaults[$key] = $validated === false ? $default : $validated;
        }

        return $defaults;
    }

    /** @return array{sla_days: int, lookback_days: int} */
    public function operationalDigestPolicy(): array
    {
        $policy = is_array($this->operational_digest_policy) ? $this->operational_digest_policy : [];
        $defaults = ['sla_days' => 3, 'lookback_days' => 30];
        foreach ($defaults as $key => $default) {
            $value = filter_var($policy[$key] ?? $default, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
            $defaults[$key] = $value === false ? $default : $value;
        }

        return $defaults;
    }

    public function missingShopifyCredentials(): bool
    {
        return trim((string) $this->shopify_store) === '' || trim((string) $this->shopify_access_token) === '';
    }

    /**
     * The shop's IANA timezone as reported by Shopify, or UTC until the API health check has recorded it.
     */
    public function shopTimezone(): string
    {
        $timezone = trim((string) $this->shopify_timezone);

        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    public function missingShipStationCredentials(): bool
    {
        return trim((string) $this->shipstation_api_key) === '' || trim((string) $this->shipstation_api_secret) === '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipstation_monitoring_enabled' => 'boolean',
            'shipstation_monitoring_token' => 'encrypted',
            'shipstation_monitoring_subscriptions' => 'encrypted:array',
            'shipstation_monitoring_started_at' => 'datetime',
            'shipstation_monitoring_checked_at' => 'datetime',
            'return_exception_policy' => 'array',
            'operational_digest_policy' => 'array',
            'sku_aliases' => 'array',
            'sku_aliases_enabled' => 'boolean',
            'movement_policy' => 'array',
            'shopify_access_token' => 'encrypted',
            'shopify_webhook_secret' => 'encrypted',
            'shipstation_api_key' => 'encrypted',
            'shipstation_api_secret' => 'encrypted',
            'shipstation_v2_api_key' => 'encrypted',
            'slack_rules' => AsNotificationRules::class.':chat',
            'email_rules' => AsNotificationRules::class.':email',
            'discord_rules' => AsNotificationRules::class.':discord',
            'scheduled_audit_enabled' => 'boolean',
            'delivery_watch_days' => 'integer',
            'scheduled_audit_time' => 'datetime:H:i',
        ];
    }
}
