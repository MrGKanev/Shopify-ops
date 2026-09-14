<?php

namespace Tests\Feature;

use Tests\TestCase;

class RegistryConsistencyTest extends TestCase
{
    public function test_named_routes_are_unique_and_every_report_screen_has_a_submit_route(): void
    {
        $routes = app('router')->getRoutes()->getRoutes();
        $names = array_values(array_filter(array_map(fn ($route): ?string => $route->getName(), $routes)));
        $reportScreens = array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'reports.') && ! str_ends_with($name, '.store') && ! str_ends_with($name, '.export') && ! str_ends_with($name, '.queue')));
        $missing = array_values(array_filter($reportScreens, fn (string $name): bool => ! in_array($name.'.store', $names, true)));

        $this->assertSame(count($names), count(array_unique($names)), 'Named routes must be unique.');
        $this->assertSame([], $missing, 'Every report screen must have a submit route.');
    }
}
