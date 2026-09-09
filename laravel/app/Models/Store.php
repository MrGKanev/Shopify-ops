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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shopify_access_token' => 'encrypted',
            'shipstation_api_key' => 'encrypted',
            'shipstation_api_secret' => 'encrypted',
        ];
    }
}
