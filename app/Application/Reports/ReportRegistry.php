<?php

namespace App\Application\Reports;

use App\Integration;

/**
 * The single list of report and audit tools, defined in config/reports.php.
 *
 * Report routes, the audit navigation (config/audit-hub.php), the notification tool catalog
 * (config/tool-catalog.php) and the generated section of docs/tools.md are all derived from it,
 * so adding a tool means adding one entry here.
 */
final class ReportRegistry
{
    /** The tool listed first in the notification catalog; every other tool follows by key. */
    private const string PRIMARY_TOOL = 'run_audit';

    /** @var array<string, ReportTool> */
    private array $tools = [];

    /**
     * @param  array{sections: list<string>, tools: array<string, array<string, mixed>>}  $definitions
     */
    public function __construct(private readonly array $definitions)
    {
        foreach ($definitions['tools'] as $key => $definition) {
            /** @var array{slug?: string, route?: string, label: string, catalog_label?: string, section?: string|null, description: string, controller?: class-string, requires?: list<Integration>, custom_routes?: bool} $definition */
            $this->tools[$key] = ReportTool::fromArray($key, $definition);
        }
    }

    /**
     * Builds the registry straight from the config file, for config files that are loaded before
     * the "reports" config key exists.
     */
    public static function fromConfigFile(): self
    {
        /** @var array{sections: list<string>, tools: array<string, array<string, mixed>>} $definitions */
        $definitions = require config_path('reports.php');

        return new self($definitions);
    }

    /** @return array<string, ReportTool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function find(string $key): ?ReportTool
    {
        return $this->tools[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->tools[$key]);
    }

    /**
     * Tools whose runs are recorded under their key.
     *
     * @return array<string, ReportTool>
     */
    public function recordingTools(): array
    {
        return array_filter($this->tools, fn (ReportTool $tool): bool => $tool->recordsRuns());
    }

    /**
     * Tools that get the standard create / store / result / export routes.
     *
     * @return array<string, ReportTool>
     */
    public function routedTools(): array
    {
        return array_filter($this->tools, fn (ReportTool $tool): bool => $tool->hasGeneratedRoutes());
    }

    /**
     * Section names in navigation order.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return $this->definitions['sections'];
    }

    /**
     * Tools grouped by navigation section, in navigation order. Tools without a section are left out.
     *
     * @return array<string, list<ReportTool>>
     */
    public function bySection(): array
    {
        $sections = array_fill_keys($this->sections(), []);
        foreach ($this->tools as $tool) {
            if ($tool->section !== null) {
                $sections[$tool->section][] = $tool;
            }
        }

        return array_filter($sections);
    }

    /**
     * The audit navigation, shaped like config('audit-hub').
     *
     * @return array<string, list<array{label: string, route: string}>>
     */
    public function navigation(): array
    {
        return array_map(
            fn (array $tools): array => array_map(fn (ReportTool $tool): array => ['label' => $tool->label, 'route' => $tool->route], $tools),
            $this->bySection(),
        );
    }

    /**
     * Every tool that records runs, shaped like config('tool-catalog'): the primary tool first,
     * then the rest ordered by key.
     *
     * @return array<string, array{label: string, route: string}>
     */
    public function catalog(): array
    {
        $tools = $this->recordingTools();
        uksort($tools, fn (string $a, string $b): int => [$a !== self::PRIMARY_TOOL, $a] <=> [$b !== self::PRIMARY_TOOL, $b]);

        return array_map(fn (ReportTool $tool): array => ['label' => $tool->catalogLabel(), 'route' => $tool->route], $tools);
    }
}
