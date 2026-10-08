<?php

namespace Tests;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    /** @return list<string> */
    protected function applicationScreenRoutes(): array
    {
        $screens = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $action = $route->getActionName();
            $authenticated = in_array('auth', $route->gatherMiddleware(), true);
            if (! $authenticated || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{') || ! is_string($name)) {
                continue;
            }
            if ($name === 'command-palette' || str_ends_with($name, '.result')) {
                continue;
            }
            if (str_starts_with($action, 'App\\Http\\Controllers\\') || str_starts_with($action, 'Illuminate\\Routing\\ViewController')) {
                $screens[] = $name;
            }
        }
        sort($screens);

        return $screens;
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
