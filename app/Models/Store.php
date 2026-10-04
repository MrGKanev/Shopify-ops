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
    'store_number',
    'slack_rules',
    'email_rules',
    'discord_rules',
    'default_alert_email',
    'scheduled_audit_enabled',
    'scheduled_audit_time',
    'delivery_watch_days',
    'shopify_timezone',
])]
#[Hidden(['shopify_access_token', 'shopify_webhook_secret', 'shipstation_api_key', 'shipstation_api_secret'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, LogsActivity;

    protected static function booted(): void
    {
        static::saving(function (Store $store): void {
            if ($store->exists && $store->isDirty('shopify_store') && ! $store->isDirty('shopify_timezone')) {
                $store->shopify_timezone = null;
            }
        });
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

    /** @return HasMany<WebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
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
            'shopify_access_token' => 'encrypted',
            'shopify_webhook_secret' => 'encrypted',
            'shipstation_api_key' => 'encrypted',
            'shipstation_api_secret' => 'encrypted',
            'slack_rules' => AsNotificationRules::class.':chat',
            'email_rules' => AsNotificationRules::class.':email',
            'discord_rules' => AsNotificationRules::class.':discord',
            'scheduled_audit_enabled' => 'boolean',
            'delivery_watch_days' => 'integer',
            'scheduled_audit_time' => 'datetime:H:i',
        ];
    }
}
