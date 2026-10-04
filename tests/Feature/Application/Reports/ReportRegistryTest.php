<?php

namespace Tests\Feature\Application\Reports;

use App\Application\Reports\ReportRegistry;
use App\Application\Reports\ReportTool;
use App\Integration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportRegistryTest extends TestCase
{
    public function test_every_tool_key_recorded_in_run_logs_or_report_runs_is_registered(): void
    {
        $registry = app(ReportRegistry::class);
        $usedKeys = [];
        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all('/(?:->run\(\$request, \$\w+, |->completedResult\(\$\w+, |\'tool\' => )\'([a-z_]+)\'/', $file->getContents(), $matches);
            foreach ($matches[1] as $key) {
                $usedKeys[$key][] = $file->getRelativePathname();
            }
        }

        $this->assertGreaterThan(40, count($usedKeys), 'The scan should find the tool key of every report.');
        foreach ($usedKeys as $key => $files) {
            $this->assertTrue($registry->has($key), "Tool \"{$key}\" (used in ".implode(', ', array_unique($files)).') is missing from config/reports.php.');
            $this->assertTrue($registry->find($key)?->recordsRuns(), "Tool \"{$key}\" records runs, so it needs a controller in config/reports.php.");
        }
    }

    public function test_every_registered_tool_has_a_resolvable_route_and_a_complete_definition(): void
    {
        $registry = app(ReportRegistry::class);
        $slugs = [];

        foreach ($registry->all() as $key => $tool) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $key);
            $this->assertTrue(Route::has($tool->route), "Missing route {$tool->route} for tool {$key}.");
            $this->assertNotSame('', trim($tool->label));
            $this->assertNotSame('', trim($tool->description));
            if ($tool->section !== null) {
                $this->assertContains($tool->section, $registry->sections(), "Tool {$key} uses an unknown section.");
            }
            if ($tool->recordsRuns()) {
                $this->assertTrue(class_exists((string) $tool->controller), "Controller for {$key} does not exist.");
                $this->assertNotSame([], $tool->requires, "Tool {$key} must list the integrations it needs.");
                $this->assertContainsOnlyInstancesOf(Integration::class, $tool->requires);
            }
            if ($tool->slug !== null) {
                $this->assertNotContains($tool->slug, $slugs, "Slug {$tool->slug} is used twice.");
                $slugs[] = $tool->slug;
            }
        }
    }

    public function test_generated_report_routes_keep_their_names_and_actions(): void
    {
        $routed = app(ReportRegistry::class)->routedTools();

        $this->assertArrayNotHasKey('run_audit', $routed, 'Run Audit declares its own routes.');
        foreach ($routed as $tool) {
            $this->assertSame('reports/'.$tool->slug, Route::getRoutes()->getByName("reports.{$tool->slug}")?->uri());
            $this->assertSame($tool->controller.'@store', Route::getRoutes()->getByName("reports.{$tool->slug}.store")?->getActionName());
            $this->assertSame($tool->controller.'@store', Route::getRoutes()->getByName("reports.{$tool->slug}.result")?->getActionName());
            $this->assertSame($tool->hasExport(), Route::has("reports.{$tool->slug}.export"));
        }
        $this->assertTrue(Route::has('reports.shipment-aging.export'));
    }

    public function test_navigation_and_catalog_configs_are_views_over_the_registry(): void
    {
        $registry = app(ReportRegistry::class);

        $this->assertSame($registry->navigation(), config('audit-hub'));
        $this->assertSame($registry->catalog(), config('tool-catalog'));
        $this->assertSame(array_keys($registry->recordingTools()), array_values(array_intersect(array_keys($registry->all()), array_keys(config('tool-catalog')))));
    }

    public function test_navigation_groups_tools_by_section_in_registry_order(): void
    {
        $registry = new ReportRegistry([
            'sections' => ['First', 'Empty', 'Second'],
            'tools' => [
                'beta' => ['slug' => 'beta', 'label' => 'Beta', 'section' => 'Second', 'description' => 'B', 'controller' => self::class, 'requires' => [Integration::Shopify]],
                'saved' => ['route' => 'saved-reports.index', 'label' => 'Saved', 'section' => 'First', 'description' => 'S'],
                'alpha' => ['slug' => 'alpha', 'label' => 'Alpha', 'catalog_label' => 'Alpha Report', 'section' => 'First', 'description' => 'A', 'controller' => self::class, 'requires' => [Integration::ShipStation]],
                'hidden' => ['slug' => 'hidden', 'label' => 'Hidden', 'section' => null, 'description' => 'H', 'controller' => self::class, 'requires' => [Integration::Shopify]],
            ],
        ]);

        $this->assertSame([
            'First' => [['label' => 'Saved', 'route' => 'saved-reports.index'], ['label' => 'Alpha', 'route' => 'reports.alpha']],
            'Second' => [['label' => 'Beta', 'route' => 'reports.beta']],
        ], $registry->navigation());
        $this->assertSame(['alpha', 'beta', 'hidden'], array_keys($registry->catalog()));
        $this->assertSame('Alpha Report', $registry->catalog()['alpha']['label']);
        $this->assertFalse($registry->find('saved')?->recordsRuns());
        $this->assertTrue($registry->find('alpha')?->requires(Integration::ShipStation));
        $this->assertNull($registry->find('missing'));
    }

    public function test_catalog_lists_run_audit_first_then_tools_by_key(): void
    {
        $keys = array_keys(app(ReportRegistry::class)->catalog());
        $rest = array_slice($keys, 1);
        $sorted = $rest;
        sort($sorted);

        $this->assertSame('run_audit', $keys[0]);
        $this->assertSame($sorted, $rest);
        $this->assertContainsOnlyInstancesOf(ReportTool::class, app(ReportRegistry::class)->all());
    }
}
