<?php

namespace Tests\Unit\Notifications\Channels;

use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DiscordWebhookChannelTest extends TestCase
{
    public function test_it_posts_the_notifications_discord_payload_to_the_routed_webhook(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        $notifiable = (new AnonymousNotifiable)->route('discord', 'https://discord.com/api/webhooks/1/token');

        (new DiscordWebhookChannel)->send($notifiable, $this->notification());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.com/api/webhooks/1/token' && $request['content'] === 'hello');
    }

    public function test_it_does_nothing_when_no_webhook_is_routed(): void
    {
        Http::fake();
        $notifiable = new AnonymousNotifiable;

        (new DiscordWebhookChannel)->send($notifiable, $this->notification());

        Http::assertNothingSent();
    }

    public function test_it_throws_on_a_non_2xx_response_instead_of_failing_silently(): void
    {
        Http::fake(['discord.com/*' => Http::response('bad request', 400)]);
        $notifiable = (new AnonymousNotifiable)->route('discord', 'https://discord.com/api/webhooks/1/token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Discord webhook error 400');

        (new DiscordWebhookChannel)->send($notifiable, $this->notification());
    }

    private function notification(): Notification
    {
        return new class extends Notification
        {
            public function toDiscord(object $notifiable): array
            {
                return ['content' => 'hello'];
            }
        };
    }
}
