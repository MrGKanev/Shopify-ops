<?php

namespace Tests\Feature;

use App\Models\OperationalIssue;
use App\Models\Store;
use App\Models\User;
use App\Support\UiFormat;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulgarianInterfaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_application_screens_and_all_report_forms_render_in_bulgarian(): void
    {
        app()->setLocale('bg');
        Http::preventStrayRequests();
        $store = Store::factory()->create();
        $user = User::factory()->admin()->create();
        $user->stores()->attach($store);
        $this->actingAs($user);
        $routes = $this->applicationScreenRoutes();
        $this->assertNotEmpty($routes);

        foreach (array_unique($routes) as $route) {
            $this->get(route($route))->assertOk()->assertSee('<html lang="bg">', false)
                ->assertDontSeeText('No runs yet')
                ->assertDontSeeText('Run report')
                ->assertDontSeeText('Send test notification');
        }
    }

    public function test_failed_login_translates_remaining_attempts(): void
    {
        app()->setLocale('bg');
        config(['services.google.login_only' => false]);

        $this->post(route('login.store'), ['email' => 'absent@example.com', 'password' => 'incorrect-password'])
            ->assertSessionHasErrors(['email' => 'Неправилен имейл или парола. Остават 2 опита.']);
    }

    public function test_triage_translates_dynamic_titles_filters_counts_and_accessibility_text(): void
    {
        app()->setLocale('bg');
        $store = Store::factory()->create(['label' => 'Магазин']);
        $user = User::factory()->operator()->create();
        $user->stores()->attach($store);
        OperationalIssue::factory()->for($store)->create(['title' => 'Shopify order changed after ShipStation push']);

        $this->actingAs($user)->get(route('operational-issues.index'))
            ->assertSeeText('Преглед на проблеми')
            ->assertSeeText('Shopify поръчката е променена след изпращане към ShipStation')
            ->assertSeeText('1 отворени или обработвани проблема за Магазин')
            ->assertSeeText('Моите проблеми')
            ->assertSeeText('Краен срок')
            ->assertDontSeeText('Resolution note');
    }

    public function test_numbers_dates_and_legacy_dynamic_messages_follow_the_selected_locale(): void
    {
        app()->setLocale('bg');
        $this->travelTo('2026-10-04 12:00:00');

        $this->assertSame('1 234,50', UiFormat::number(1234.5, 2));
        $this->assertSame('4.10.2026', UiFormat::date(Carbon::parse('2026-10-04')));
        $this->assertStringContainsString('час', UiFormat::relative(now()->subHour()));
        $this->assertSame('Забавено изпращане: 8 дни между поръчката и първото изпълнение', UiFormat::text('Slow to ship: 8 days between order placement and first fulfillment'));
        $this->assertSame('2 от 3 варианта нямат SKU', UiFormat::text('2 of 3 variants missing SKU'));
        $this->assertSame('SKU-ABC', UiFormat::text('SKU-ABC'));
        $this->assertSame('1 повторение', trans_choice('{1} :count occurrence|[2,*] :count occurrences', 1));
        $this->assertSame('2 повторения', trans_choice('{1} :count occurrence|[2,*] :count occurrences', 2));
        $this->assertSame('1 поръчка', UiFormat::count(1, 'orders'));
        $this->assertSame('2 поръчки', UiFormat::count(2, 'orders'));
        $this->assertSame('0 поръчки', UiFormat::count(0, 'orders'));

        app()->setLocale('en');
        $this->assertSame('1,234.50', UiFormat::number(1234.5, 2));
    }

    public function test_command_results_translate_the_visible_kind_and_preserve_its_identifier(): void
    {
        app()->setLocale('bg');
        $store = Store::factory()->create();
        $user = User::factory()->operator()->create();
        $user->stores()->attach($store);

        $this->actingAs($user)->getJson(route('command-palette', ['q' => 'still unfulfilled']))
            ->assertJsonFragment(['kind' => 'Page', 'kind_label' => 'Страница']);
    }

    public function test_order_validation_uses_bulgarian_custom_messages(): void
    {
        app()->setLocale('bg');
        $store = Store::factory()->create();
        $user = User::factory()->operator()->create();
        $user->stores()->attach($store);

        $this->actingAs($user)->post(route('orders.spot-check.store'), ['orders' => str_repeat('1 ', 51), 'mode' => 'both'])
            ->assertSessionHasErrors(['orders' => 'Максимум 50 номера на поръчки наведнъж.']);
    }
}
