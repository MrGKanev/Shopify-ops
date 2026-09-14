<?php

namespace App\Application\Health;

use App\Notifications\DiscordTestNotification;
use Illuminate\Support\Facades\Notification;
use LogicException;

class SendTestDiscord
{
    /** @return array{configured: bool, endpoint: string} */
    public function configuration(): array
    {
        $webhookUrl = trim((string) config('services.discord.notifications.webhook_url'));
        $host = strtolower((string) parse_url($webhookUrl, PHP_URL_HOST));
        $path = (string) parse_url($webhookUrl, PHP_URL_PATH);
        $configured = str_starts_with($webhookUrl, 'https://')
            && in_array($host, ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'], true)
            && str_starts_with($path, '/api/webhooks/');

        return [
            'configured' => $configured,
            'endpoint' => $configured ? $host : '',
        ];
    }

    public function handle(): void
    {
        if (! $this->configuration()['configured']) {
            throw new LogicException('Discord delivery is not configured.');
        }

        Notification::route('discord', (string) config('services.discord.notifications.webhook_url'))
            ->notifyNow(new DiscordTestNotification((string) config('app.name'), now()->toDateTimeString()));
    }
}
