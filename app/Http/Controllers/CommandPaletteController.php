<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommandPaletteRequest;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CommandPaletteController extends Controller
{
    public function __invoke(CommandPaletteRequest $request): JsonResponse
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $query = Str::of((string) ($request->validated('q') ?? ''))->squish()->toString();
        $commands = collect($this->navigationCommands())
            ->when($query !== '', fn ($items) => $items->filter(fn (array $item): bool => Str::contains(Str::lower($item['label'].' '.$item['keywords']), Str::lower($query))))
            ->take(8)
            ->map(fn (array $item): array => [...$item, 'kind' => 'Page'])
            ->values();

        if (mb_strlen($query) >= 2) {
            $issues = $store->operationalIssues()
                ->where(fn ($builder) => $builder->whereLike('title', "%{$query}%")->orWhereLike('reference', "%{$query}%")->orWhereLike('source_tool', "%{$query}%"))
                ->latest('last_seen_at')
                ->limit(5)
                ->get(['id', 'title', 'reference', 'priority'])
                ->map(fn ($issue): array => [
                    'label' => $issue->title,
                    'description' => trim($issue->priority.' · '.($issue->reference ?: 'Operational issue')),
                    'url' => route('operational-issues.index').'#issue-'.$issue->getKey(),
                    'kind' => 'Issue',
                ]);
            $runs = $store->runLogs()
                ->where(fn ($builder) => $builder->whereLike('tool', "%{$query}%")->orWhereLike('status', "%{$query}%"))
                ->latest()
                ->limit(5)
                ->get(['id', 'tool', 'status', 'created_at'])
                ->map(fn ($run): array => [
                    'label' => Str::headline($run->tool),
                    'description' => $run->status.' · '.$run->created_at->diffForHumans(),
                    'url' => route('run-logs.index', ['q' => $run->tool]),
                    'kind' => 'Run',
                ]);
            $commands = $commands->concat($issues)->concat($runs)->take(15)->values();
        }

        return response()->json(['results' => $commands]);
    }

    /** @return array<int, array{label:string,description:string,url:string,keywords:string}> */
    private function navigationCommands(): array
    {
        $commands = [
            ['label' => 'Dashboard', 'description' => 'Store overview', 'url' => route('dashboard'), 'keywords' => 'home overview начало табло'],
            ['label' => 'Order lookup', 'description' => 'Find an order in Shopify', 'url' => route('orders.lookup'), 'keywords' => 'search order поръчка търсене'],
            ['label' => 'Run audit', 'description' => 'Start a new operational audit', 'url' => route('reports.run-audit'), 'keywords' => 'audit check проверка одит'],
            ['label' => 'Issue triage', 'description' => 'Review operational issues', 'url' => route('operational-issues.index'), 'keywords' => 'issue problem проблеми triage'],
            ['label' => 'Run history', 'description' => 'See recent audit runs', 'url' => route('run-logs.index'), 'keywords' => 'history log runs история'],
            ['label' => 'Job queue', 'description' => 'Inspect queued and failed jobs', 'url' => route('jobs.index'), 'keywords' => 'horizon queue jobs опашка'],
            ['label' => 'Saved reports', 'description' => 'Open stored audit results', 'url' => route('saved-reports.index'), 'keywords' => 'report export reports справки'],
            ['label' => 'Global order search', 'description' => 'Search local order history', 'url' => route('global-search'), 'keywords' => 'global search orders търсене'],
        ];

        if (Gate::allows('manage-administration')) {
            $commands = [...$commands,
                ['label' => 'System health', 'description' => 'Check Laravel and infrastructure', 'url' => route('admin.health'), 'keywords' => 'health status system здраве'],
                ['label' => 'Health incidents', 'description' => 'Review outages and recoveries', 'url' => route('admin.health-incidents'), 'keywords' => 'health incidents downtime outage история инциденти'],
                ['label' => 'Backups', 'description' => 'Create and inspect backups', 'url' => route('admin.backups.index'), 'keywords' => 'backup restore архив'],
                ['label' => 'Webhook events', 'description' => 'Inspect incoming Shopify events', 'url' => route('admin.webhook-events'), 'keywords' => 'webhook shopify events събития'],
                ['label' => 'Stores', 'description' => 'Manage connected stores', 'url' => route('admin.stores.index'), 'keywords' => 'stores settings магазини'],
                ['label' => 'Settings', 'description' => 'Application configuration', 'url' => route('admin.settings'), 'keywords' => 'settings config настройки'],
            ];
        }

        return $commands;
    }
}
