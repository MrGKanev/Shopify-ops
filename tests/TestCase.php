<?php

namespace Tests;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    /** Every Feature test starts on this instant, so date-dependent behaviour cannot rot or flake as real time moves; a test needing another instant calls travelTo() itself. */
    protected const string FROZEN_NOW = '2026-10-28 12:00:00 UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo(self::FROZEN_NOW);
    }

    /** @return list<string> */
    protected function applicationScreenRoutes(): array
    {
        return $this->classifyApplicationScreenRoutes()['screens'];
    }

    /** Authenticated, controller-backed GET routes the screen smoke tests deliberately do not request, because they need a route parameter, return a download, or are fragments. @return list<string> */
    protected function skippedApplicationScreenRoutes(): array
    {
        return $this->classifyApplicationScreenRoutes()['skipped'];
    }

    /** @return array{screens: list<string>, skipped: list<string>} */
    private function classifyApplicationScreenRoutes(): array
    {
        $screens = [];
        $skipped = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $action = $route->getActionName();
            $authenticated = in_array('auth', $route->gatherMiddleware(), true);
            $controllerBacked = str_starts_with($action, 'App\\Http\\Controllers\\') || str_starts_with($action, 'Illuminate\\Routing\\ViewController');
            if (! $authenticated || ! in_array('GET', $route->methods(), true) || ! is_string($name) || ! $controllerBacked) {
                continue;
            }
            if (str_contains($route->uri(), '{') || $name === 'command-palette' || str_ends_with($name, '.result') || str_ends_with($name, '.export') || str_ends_with($name, '.template')) {
                $skipped[] = $name;

                continue;
            }
            $screens[] = $name;
        }
        sort($screens);
        sort($skipped);

        return ['screens' => $screens, 'skipped' => $skipped];
    }

    /** @param array<string, mixed> $attributes @return array{0: User, 1: Store} */
    protected function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
