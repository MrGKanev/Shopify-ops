<?php

namespace App\Models\Concerns;

use App\Application\Notifications\ChatRules;
use App\Application\Notifications\EmailRules;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<ChatRules|EmailRules, ChatRules|EmailRules|array<string, mixed>|null> */
class AsNotificationRules implements CastsAttributes
{
    public bool $withoutObjectCaching = true;

    public function __construct(private readonly string $type = 'chat') {}

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ChatRules|EmailRules
    {
        $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];
        $stored = is_array($decoded) ? $decoded : [];

        return $this->type === 'email' ? EmailRules::fromArray($stored) : ChatRules::fromArray($stored, $this->type !== 'discord');
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $rules = is_array($value)
            ? ($this->type === 'email' ? EmailRules::fromArray($value) : ChatRules::fromArray($value, $this->type !== 'discord'))
            : $value;

        return json_encode($rules->toArray(), JSON_THROW_ON_ERROR);
    }
}
