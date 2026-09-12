<?php

namespace App\Application\Health;

use App\Models\Store;
use Illuminate\Support\Str;

class CheckConfiguration
{
    private const MATCHES = ['sku_starts_with', 'sku_contains', 'sku_not_starts_with', 'title_contains', 'vendor_is'];

    /** @return list<array{name:string,ok:bool,issues:list<string>,notes:list<string>}> */
    public function handle(Store $store): array
    {
        return [
            $this->application(),
            $this->store($store),
            $this->orderTypes((array) config('order-types', [])),
            $this->tagPolicy((array) config('tag-policy', [])),
            $this->mail(),
            $this->notifications(),
        ];
    }

    /** @return array{name:string,ok:bool,issues:list<string>,notes:list<string>} */
    private function application(): array
    {
        $issues = [];
        if (trim((string) config('app.key')) === '') {
            $issues[] = 'APP_KEY is missing.';
        }
        if (config('app.env') === 'production' && config('app.debug')) {
            $issues[] = 'APP_DEBUG must be disabled in production.';
        }
        if (! in_array(config('cache.default'), array_keys((array) config('cache.stores')), true)) {
            $issues[] = 'The default cache store is not defined.';
        }
        if (! in_array(config('queue.default'), array_keys((array) config('queue.connections')), true)) {
            $issues[] = 'The default queue connection is not defined.';
        }
        if (config('app.env') === 'production' && Str::contains((string) config('app.url'), 'localhost')) {
            $issues[] = 'APP_URL must not be the localhost default in production.';
        }
        $google = array_filter([(string) config('services.google.client_id'), (string) config('services.google.client_secret'), (string) config('services.google.allowed_domains')]);
        if ($google !== [] && count($google) !== 3) {
            $issues[] = 'Google sign-in configuration is incomplete.';
        }

        return $this->result('Application', $issues, ['Environment: '.config('app.env'), 'Cache: '.config('cache.default').' · Queue: '.config('queue.default')]);
    }

    /** @return array{name:string,ok:bool,issues:list<string>,notes:list<string>} */
    private function store(Store $store): array
    {
        $issues = [];
        foreach (['shopify_store' => 'Shopify store', 'shopify_access_token' => 'Shopify access token', 'shipstation_api_key' => 'ShipStation API key', 'shipstation_api_secret' => 'ShipStation API secret'] as $field => $label) {
            if (trim((string) $store->{$field}) === '') {
                $issues[] = "{$label} is missing.";
            }
        }

        return $this->result('Active store', $issues, [$store->label]);
    }

    /** @param array<string,mixed> $config
     * @return array{name:string,ok:bool,issues:list<string>,notes:list<string>}
     */
    private function orderTypes(array $config): array
    {
        $issues = [];
        $rules = $config['rules'] ?? null;
        if (! is_array($rules)) {
            $issues[] = 'order-types.rules must be an array.';
            $rules = [];
        }
        $names = [];
        foreach ($rules as $index => $rule) {
            if (! is_array($rule)) {
                $issues[] = "Rule {$index} must be an array.";

                continue;
            }
            $name = trim((string) ($rule['name'] ?? ''));
            if ($name === '') {
                $issues[] = "Rule {$index} needs a name.";
            } elseif (isset($names[$name])) {
                $issues[] = "Duplicate order type: {$name}.";
            }
            $names[$name] = true;
            if (! in_array($rule['match'] ?? null, self::MATCHES, true) || ! array_key_exists('value', $rule)) {
                $issues[] = "Rule {$index} has an invalid match or missing value.";
            }
        }

        return $this->result('Order types', $issues, [count($rules).' rules · fallback: '.($config['fallback'] ?? 'not set')]);
    }

    /** @param array<string,mixed> $config
     * @return array{name:string,ok:bool,issues:list<string>,notes:list<string>}
     */
    private function tagPolicy(array $config): array
    {
        $issues = [];
        foreach (['required', 'forbidden'] as $group) {
            if (! is_array($config[$group] ?? null)) {
                $issues[] = "tag-policy.{$group} must be an array.";
            }
        }
        foreach (is_array($config['required'] ?? null) ? $config['required'] : [] as $index => $rule) {
            if (! is_array($rule) || empty($rule['when']) || ! is_array($rule['when']) || empty($rule['must_have']) || ! is_array($rule['must_have'])) {
                $issues[] = "Required policy {$index} needs non-empty when and must_have arrays.";
            }
        }
        foreach (is_array($config['forbidden'] ?? null) ? $config['forbidden'] : [] as $index => $rule) {
            if (! is_array($rule) || ! is_array($rule['tags'] ?? null) || count($rule['tags']) < 2) {
                $issues[] = "Forbidden policy {$index} needs at least two tags.";
            }
        }

        $count = count(is_array($config['required'] ?? null) ? $config['required'] : []) + count(is_array($config['forbidden'] ?? null) ? $config['forbidden'] : []);

        return $this->result('Tag policy', $issues, [$count === 0 ? 'No tag policies configured.' : "{$count} policies configured."]);
    }

    /** @return array{name:string,ok:bool,issues:list<string>,notes:list<string>} */
    private function mail(): array
    {
        $issues = [];
        $mailer = (string) config('mail.default');
        if (! in_array($mailer, array_keys((array) config('mail.mailers')), true)) {
            $issues[] = 'The default mailer is not defined.';
        }
        if (trim((string) config('mail.from.address')) === '') {
            $issues[] = 'MAIL_FROM_ADDRESS is missing.';
        }

        return $this->result('Mail', $issues, ["Mailer: {$mailer}"]);
    }

    /** @return array{name:string,ok:bool,issues:list<string>,notes:list<string>} */
    private function notifications(): array
    {
        $slack = trim((string) config('services.slack.notifications.webhook_url')) !== '';
        $discord = trim((string) config('services.discord.notifications.webhook_url')) !== '';

        return $this->result('Notifications', [], [
            'Slack webhook: '.($slack ? 'configured.' : 'not configured.'),
            'Discord webhook: '.($discord ? 'configured.' : 'not configured.'),
        ]);
    }

    /** @param list<string> $issues
     * @param  list<string>  $notes
     * @return array{name:string,ok:bool,issues:list<string>,notes:list<string>}
     */
    private function result(string $name, array $issues, array $notes): array
    {
        return compact('name', 'issues', 'notes') + ['ok' => $issues === []];
    }
}
