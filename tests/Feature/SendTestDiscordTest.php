<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Notifications\DiscordTestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendTestDiscordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_send_a_test_notification_to_a_configured_webhook(): void
    {
        [$admin] = $this->adminWithStore();
        $this->configureDiscord();
        Notification::fake();

        $this->actingAs($admin)
            ->post(route('admin.api-health.test-discord'))
            ->assertOk()
            ->assertSeeText('Test Discord notification sent successfully')
            ->assertSeeText('discord.com')
            ->assertDontSee('test-secret', false);

        Notification::assertSentOnDemand(
            DiscordTestNotification::class,
            fn (DiscordTestNotification $notification, array $channels, object $notifiable): bool => in_array('discord', array_keys($notifiable->routes), true)
                && $notification->applicationName === config('app.name'),
        );
    }

    public function test_unconfigured_or_untrusted_webhook_fails_without_sending(): void
    {
        [$admin] = $this->adminWithStore();
        config()->set('services.discord.notifications.webhook_url', 'https://example.test/api/webhooks/1/test-secret');
        Notification::fake();

        $this->actingAs($admin)
            ->post(route('admin.api-health.test-discord'))
            ->assertOk()
            ->assertSeeText('Test Discord notification could not be sent')
            ->assertDontSeeText('Discord delivery is not configured');

        Notification::assertNothingSent();
    }

    public function test_only_administrators_can_send_test_notifications(): void
    {
        $this->post(route('admin.api-health.test-discord'))->assertRedirect(route('login'));

        $viewer = User::factory()->create();
        $store = Store::factory()->create();
        $viewer->stores()->attach($store);

        $this->actingAs($viewer)->post(route('admin.api-health.test-discord'))->assertForbidden();
    }

    public function test_notification_is_queueable_and_contains_no_operational_data(): void
    {
        $notification = new DiscordTestNotification('Shopify Ops', '2026-09-08 15:00:00');
        $payload = $notification->toDiscord((object) []);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertStringContainsString('successfully connected', $payload['content']);
        $this->assertStringContainsString('No store credentials or order data', $payload['content']);
    }

    private function configureDiscord(): void
    {
        config()->set('services.discord.notifications.webhook_url', 'https://discord.com/api/webhooks/1/test-secret');
    }

    /** @return array{User, Store} */
    private function adminWithStore(): array
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);

        return [$admin, $store];
    }
}
