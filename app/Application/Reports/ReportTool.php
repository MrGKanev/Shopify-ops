<?php

namespace App\Application\Reports;

use App\Integration;

/**
 * One entry of the tool registry (config/reports.php): a report or audit page.
 *
 * A tool with a controller records runs under its key (run_logs.tool, report_runs.tool) and can be
 * configured in the notification rules. A tool without a controller is a plain navigation link.
 */
final readonly class ReportTool
{
    /**
     * @param  class-string|null  $controller
     * @param  list<Integration>  $requires
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $route,
        public string $description,
        public ?string $slug = null,
        public ?string $section = null,
        public ?string $controller = null,
        public array $requires = [],
        public ?string $catalogLabel = null,
        public bool $customRoutes = false,
    ) {}

    /**
     * @param  array{slug?: string, route?: string, label: string, catalog_label?: string, section?: string|null, description: string, controller?: class-string, requires?: list<Integration>, custom_routes?: bool}  $definition
     */
    public static function fromArray(string $key, array $definition): self
    {
        $slug = $definition['slug'] ?? null;

        return new self(
            key: $key,
            label: $definition['label'],
            route: $definition['route'] ?? 'reports.'.$slug,
            description: $definition['description'],
            slug: $slug,
            section: $definition['section'] ?? null,
            controller: $definition['controller'] ?? null,
            requires: $definition['requires'] ?? [],
            catalogLabel: $definition['catalog_label'] ?? null,
            customRoutes: $definition['custom_routes'] ?? false,
        );
    }

    /** Whether runs of this tool are recorded under its key and it appears in the notification rules. */
    public function recordsRuns(): bool
    {
        return $this->controller !== null;
    }

    /** Whether the standard create / store / result / export routes are generated for this tool. */
    public function hasGeneratedRoutes(): bool
    {
        return $this->controller !== null && $this->slug !== null && ! $this->customRoutes;
    }

    /** Whether the controller offers a CSV export endpoint. */
    public function hasExport(): bool
    {
        return $this->controller !== null && method_exists($this->controller, 'export');
    }

    /** The name shown in the notification rules and run history. */
    public function catalogLabel(): string
    {
        return $this->catalogLabel ?? $this->label;
    }

    public function requires(Integration $integration): bool
    {
        return in_array($integration, $this->requires, true);
    }
}
