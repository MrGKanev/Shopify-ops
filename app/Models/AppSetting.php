<?php

namespace App\Models;

use App\UserRole;
use Database\Factories\AppSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

#[Fillable(['site_name', 'logo_path', 'login_image_path', 'custom_links'])]
class AppSetting extends Model
{
    /** @use HasFactory<AppSettingFactory> */
    use HasFactory;

    public static function current(): self
    {
        if (! Schema::hasTable((new self)->getTable())) {
            return new self(['site_name' => config('app.name')]);
        }

        return self::query()->first() ?? new self(['site_name' => config('app.name')]);
    }

    public function displayName(): string
    {
        return trim((string) $this->site_name) ?: (string) config('app.name');
    }

    public function logoUrl(): ?string
    {
        return $this->assetUrl($this->logo_path);
    }

    public function loginImageUrl(): string
    {
        return $this->assetUrl($this->login_image_path) ?? asset('images/login-default.jpg');
    }

    /** @return list<array{label:string,url:string}> */
    public function linksFor(User $user): array
    {
        $allowedAudiences = match ($user->role) {
            UserRole::Admin => ['all', 'operator', 'admin'],
            UserRole::Operator => ['all', 'operator'],
            default => ['all'],
        };

        return collect($this->custom_links ?? [])
            ->filter(fn (mixed $link): bool => is_array($link)
                && filled($link['label'] ?? null)
                && in_array($link['audience'] ?? null, $allowedAudiences, true)
                && filter_var($link['url'] ?? null, FILTER_VALIDATE_URL)
                && in_array(parse_url((string) $link['url'], PHP_URL_SCHEME), ['http', 'https'], true))
            ->map(fn (array $link): array => ['label' => (string) $link['label'], 'url' => (string) $link['url']])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['custom_links' => 'array'];
    }

    private function assetUrl(?string $path): ?string
    {
        return filled($path) ? Storage::disk('public')->url($path) : null;
    }
}
