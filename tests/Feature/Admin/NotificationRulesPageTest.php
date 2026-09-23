<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NotificationRulesPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_each_channel_route_opens_the_shared_page_on_its_own_tab(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach(Store::factory()->create());

        $this->assertTabState($this->actingAs($admin)->get('/admin/slack-rules'), 'slack');
        $this->assertTabState($this->actingAs($admin)->get('/admin/discord-rules'), 'discord');
        $this->assertTabState($this->actingAs($admin)->get('/admin/email-rules'), 'email');
    }

    private function assertTabState(TestResponse $response, string $activeTab): void
    {
        $response->assertOk()
            ->assertSeeText('Slack')
            ->assertSeeText('Discord')
            ->assertSeeText('Email')
            ->assertSee('data-tab-panel="'.$activeTab.'"', false);

        foreach (['slack', 'discord', 'email'] as $tab) {
            $html = $response->getContent();
            $panelStart = strpos($html, 'data-tab-panel="'.$tab.'"');
            $this->assertNotFalse($panelStart);
            $tagEnd = strpos($html, '>', $panelStart);
            $panelOpenTag = substr($html, $panelStart, $tagEnd - $panelStart);

            if ($tab === $activeTab) {
                $this->assertStringNotContainsString('hidden', $panelOpenTag);
            } else {
                $this->assertStringContainsString('hidden', $panelOpenTag);
            }
        }
    }
}
